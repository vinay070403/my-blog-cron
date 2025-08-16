<?php
/**
 * Plugin Name: Auto Blog Cron
 * Description: Auto-creates 1 post every 5 minutes from topics.json using WP-Cron + Rewrite endpoints + colorful styles + logging.
 * Version: 1.1.0
 * Author: Vinay
 */

if (!defined('ABSPATH')) exit;

/** =========================
 *  Helpers: Log + JSON load
 *  ========================= */
function abc_log($msg){
    $file = plugin_dir_path(__FILE__) . 'log.txt';
    $time = date('Y-m-d H:i:s');
    file_put_contents($file, "[$time] $msg" . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function abc_topics(){
    $file = plugin_dir_path(__FILE__) . 'topics.json';
    if (!file_exists($file)) {
        abc_log('topics.json missing');
        return [];
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        abc_log('topics.json invalid JSON');
        return [];
    }
    return $data;
}

/** ============================================
 *  Styles: enqueue + wrapper
 *  ============================================ */
add_action('wp_enqueue_scripts', function(){
    wp_enqueue_style('abc-styles', plugin_dir_url(__FILE__) . 'assets/style.css', [], '1.0');
});
function abc_wrap_content($content, $styleInline = ''){
    $styleAttr = $styleInline ? ' style="'.esc_attr($styleInline).'"' : '';
    return '<div class="abc-card"'.$styleAttr.'>'.$content.'</div>';
}

/** =========================
 *  Shortcodes (status widgets)
 *  ========================= */
add_shortcode('autoblog_next', function(){
    $ts = wp_next_scheduled('abc_every_5m_event');
    if(!$ts) return '<em>No schedule set.</em>';
    return '<strong>Next auto-post run:</strong> ' . esc_html( date_i18n('Y-m-d H:i:s', $ts) );
});
add_shortcode('autoblog_last', function(){
    $file = plugin_dir_path(__FILE__) . 'log.txt';
    if(!file_exists($file)) return '<em>No logs yet.</em>';
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if(!$lines) return '<em>No logs yet.</em>';
    $tail = array_slice($lines, -5);
    return '<pre class="abc-log">'.esc_html(implode("\n", $tail)).'</pre>';
});

/** =========================
 *  Cron schedule: every 1 day
 *  ========================= */
add_filter('cron_schedules', function($s){
    if (!isset($s['every_one_day'])) {
        $s['every_one_day'] = [
            'interval' => 86400, // 1 day = 24 hours
            'display'  => 'Every 1 Day'
        ];
    }
    return $s;
});

register_activation_hook(__FILE__, function(){
    // Rewrite for endpoints
    abc_add_rewrite_rules();
    flush_rewrite_rules();

    // Ensure counters exist
    if (get_option('abc_blog_counter') === false) update_option('abc_blog_counter', 0);
    if (get_option('abc_topic_index') === false) update_option('abc_topic_index', 0);

    // Schedule every 1 day
    if (!wp_next_scheduled('abc_every_1d_event')) {
        wp_schedule_event(time() + 10, 'every_one_day', 'abc_every_1d_event');
        abc_log('Scheduled abc_every_1d_event every 1 day.');
    }
});

register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('abc_every_1d_event');
    flush_rewrite_rules();
    abc_log('Cleared 1-day schedule & flushed rewrites.');
});


/** =========================
 *  Cron handler: create 1 POST
 *  ========================= */
add_action('abc_every_5m_event', function(){
    $topics = abc_topics();         // optional
    $counter = (int) get_option('abc_blog_counter', 0);
    $tIndex  = (int) get_option('abc_topic_index', 0);

    $title   = 'Blog ' . ($counter + 1); // guarantee unique numbering
    $content = 'Auto-generated content for ' . $title . '.';
    $style   = '';
    $cats    = [];
    $tags    = [];

    if (!empty($topics)) {
        // pick next topic in sequence (round-robin)
        if ($tIndex >= count($topics)) $tIndex = 0;
        $t = $topics[$tIndex];

        if (!empty($t['title']))   $title   = $t['title'] . ' — ' . ($counter + 1); // keep unique suffix
        if (!empty($t['content'])) $content = $t['content'];
        if (!empty($t['style']))   $style   = $t['style'];
        if (!empty($t['categories'])) $cats = (array) $t['categories'];
        if (!empty($t['tags']))       $tags = (array) $t['tags'];
    }

    // Only POST type now (no pages)
    $final = do_shortcode( abc_wrap_content($content, $style) );
    $postarr = [
        'post_title'   => $title,
        'post_content' => $final,
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ];

    $post_id = wp_insert_post($postarr, true);

    if (is_wp_error($post_id)) {
        abc_log('Insert error: ' . $post_id->get_error_message());
    } else {
        if (!empty($cats)) wp_set_post_terms($post_id, $cats, 'category');
        if (!empty($tags)) wp_set_post_terms($post_id, $tags, 'post_tag');

        $counter++;
        update_option('abc_blog_counter', $counter);

        if (!empty($topics)) {
            $tIndex++;
            update_option('abc_topic_index', $tIndex);
        }

        abc_log("Created post #$post_id: {$title}");
    }
});

/** =========================
 *  Rewrite API: /autoblog/status & /autoblog/run
 *  ========================= */
function abc_add_rewrite_rules(){
    add_rewrite_rule('^autoblog/status/?$', 'index.php?abc_endpoint=status', 'top');
    add_rewrite_rule('^autoblog/run/?$',    'index.php?abc_endpoint=run',    'top');
}
add_action('init', 'abc_add_rewrite_rules');

add_filter('query_vars', function($vars){
    $vars[] = 'abc_endpoint';
    return $vars;
});

add_action('template_redirect', function(){
    $ep = get_query_var('abc_endpoint');
    if (!$ep) return;

    if ($ep === 'status'){
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        $next = wp_next_scheduled('abc_every_1d_event');
        echo '<style>body{font:14px/1.6 system-ui;padding:24px} .abc-log{white-space:pre-wrap;background:#f6f8fa;border:1px solid #e5e7eb;padding:12px;border-radius:10px} .pill{display:inline-block;padding:4px 10px;border-radius:999px;background:#e0f2fe} a{color:#0ea5e9}</style>';
        echo '<h1>Auto Blog Status (5 min)</h1>';
        echo '<p><span class="pill">Next run:</span> ' . ($next ? esc_html(date_i18n('Y-m-d H:i:s', $next)) : 'not scheduled') . '</p>';
        $file = plugin_dir_path(__FILE__) . 'log.txt';
        if(file_exists($file)){
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            $tail = $lines ? array_slice($lines, -30) : [];
            echo '<h3>Recent Logs</h3><div class="abc-log">'.esc_html(implode("\n", $tail)).'</div>';
        } else {
            echo '<p>No logs yet.</p>';
        }
        echo '<p><a href="'.esc_url(home_url('/autoblog/run')).'">Run now (DEV)</a></p>';
        exit;
    }

    if ($ep === 'run'){
        // Manual trigger (dev). Protect in production.
        do_action('abc_every_1d_event');
        wp_safe_redirect( home_url('/autoblog/status') );
        exit;
    }
});

/** =========================
 *  Example shortcode used in content
 *  ========================= */
add_shortcode('my_shortcode', function($atts){
    $atts = shortcode_atts(['txt' => 'This is my shortcode!'], $atts);
    return '<div class="abc-chip">'.$atts['txt'].'</div>';
});


/** 
 * =============================
 * Admin UI for ABC Auto Blogger
 * =============================
 */
add_action('admin_menu', function(){
    add_menu_page(
        'ABC Auto Blogger',      // Page title
        'ABC Blogger',           // Menu title
        'manage_options',        // Capability
        'abc-auto-blogger',      // Slug
        'abc_admin_page_render', // Callback
        'dashicons-admin-site',  // Icon
        20                       // Position
    );
});

function abc_admin_page_render(){
    // Counter values
    $blog_count  = get_option('abc_blog_counter', 0);
    $topic_index = get_option('abc_topic_index', 0);

    // Schedule info
    $timestamp = wp_next_scheduled('abc_every_5m_event');
    $next_run  = $timestamp ? date('Y-m-d H:i:s', $timestamp) : 'Not scheduled';

    ?>
    <div class="wrap">
        <h1>ABC Auto Blogger Settings</h1>

        <table class="widefat fixed" style="max-width:600px;">
            <tr>
                <th>Blog Counter</th>
                <td><?php echo esc_html($blog_count); ?></td>
            </tr>
            <tr>
                <th>Topic Index</th>
                <td><?php echo esc_html($topic_index); ?></td>
            </tr>
            <tr>
                <th>Next Cron Run</th>
                <td><?php echo esc_html($next_run); ?></td>
            </tr>
        </table>

        <h2>Actions</h2>
        <form method="post">
            <?php wp_nonce_field('abc_admin_actions', 'abc_nonce'); ?>
            <input type="submit" class="button button-primary" name="abc_run_now" value="Run Now (Test)">
            <input type="submit" class="button" name="abc_reset" value="Reset Counters">
        </form>
    </div>
    <?php
}

// Handle actions
add_action('admin_init', function(){
    if (!isset($_POST['abc_nonce']) || !wp_verify_nonce($_POST['abc_nonce'], 'abc_admin_actions')) return;

    if (isset($_POST['abc_run_now'])) {
        do_action('abc_every_5m_event'); // force run
        add_action('admin_notices', function(){
            echo '<div class="notice notice-success"><p>Task executed manually.</p></div>';
        });
    }

    if (isset($_POST['abc_reset'])) {
        update_option('abc_blog_counter', 0);
        update_option('abc_topic_index', 0);
        add_action('admin_notices', function(){
            echo '<div class="notice notice-warning"><p>Counters have been reset.</p></div>';
        });
    }
});
