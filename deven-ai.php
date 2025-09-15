<?php
/**
 * Plugin Name: DevEN AI
 * Description: AI-powered content creation, on-site chatbot, and developer help—right inside WordPress.
 * Version: 0.2.6
 * Author: SitesByYogi
 * Text Domain: deven-ai
 */
if ( ! defined('ABSPATH') ) exit;

define('DEVEN_AI_VER', '0.2.6');
define('DEVEN_AI_PATH', plugin_dir_path(__FILE__));
define('DEVEN_AI_URL',  plugin_dir_url(__FILE__));

require_once DEVEN_AI_PATH . 'includes/helpers.php';
require_once DEVEN_AI_PATH . 'includes/class-deven-settings.php';
require_once DEVEN_AI_PATH . 'includes/class-deven-rest.php';

add_action('plugins_loaded', function () {
    DevEN_AI_Settings::instance();
    DevEN_AI_REST::instance();
});

/** Admin assets */
add_action('admin_enqueue_scripts', function($hook){
    if ( function_exists('get_current_screen') ) {
        $screen = get_current_screen();
        if ( $screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor() ) {
            wp_enqueue_script(
                'deven-editor-sidebar',
                DEVEN_AI_URL.'assets/editor-sidebar.js',
                ['wp-plugins','wp-edit-post','wp-element','wp-components','wp-data','wp-api-fetch','wp-i18n'],
                DEVEN_AI_VER,
                true
            );
            wp_localize_script('deven-editor-sidebar','DevENAI',[
                'restUrl' => esc_url_raw( rest_url('deven-ai/v1/chat') ),
                'nonce'   => wp_create_nonce('wp_rest'),
            ]);
        }
    }

    // shared formatting assets
    wp_register_script('deven-markdown', DEVEN_AI_URL.'assets/markdown.js', [], DEVEN_AI_VER, true);
    wp_register_script('deven-hljs', DEVEN_AI_URL.'assets/highlight.min.js', [], DEVEN_AI_VER, true);
    wp_register_style('deven-hljs', DEVEN_AI_URL.'assets/highlight.css', [], DEVEN_AI_VER);

    if ( isset($_GET['page']) && $_GET['page'] === 'deven-ai' ) {
        wp_enqueue_script('deven-markdown');
        wp_enqueue_script('deven-hljs');
        wp_enqueue_style('deven-hljs');
        wp_enqueue_script('deven-admin', DEVEN_AI_URL.'assets/admin.js', ['jquery'], DEVEN_AI_VER, true);
        wp_enqueue_style('deven-admin', DEVEN_AI_URL.'assets/admin.css', [], DEVEN_AI_VER);
        wp_localize_script('deven-admin','DevENAI',[
            'restUrl' => esc_url_raw( rest_url('deven-ai/v1/chat') ),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }
});

/** Front-end chatbot assets */
add_action('wp_enqueue_scripts', function(){
    wp_register_script('deven-markdown', DEVEN_AI_URL.'assets/markdown.js', [], DEVEN_AI_VER, true);
    wp_register_script('deven-hljs', DEVEN_AI_URL.'assets/highlight.min.js', [], DEVEN_AI_VER, true);
    wp_register_style('deven-hljs', DEVEN_AI_URL.'assets/highlight.css', [], DEVEN_AI_VER);

    wp_register_script('deven-chatbot', DEVEN_AI_URL.'assets/chatbot.js', [], DEVEN_AI_VER, true);
    wp_localize_script('deven-chatbot','DevENAIFront',[
        'restUrl' => esc_url_raw( rest_url('deven-ai/v1/chat') ),
        'nonce'   => wp_create_nonce('wp_rest'),
        'placeholder' => __('Ask me anything…','deven-ai')
    ]);
    wp_register_style('deven-chatbot', DEVEN_AI_URL.'assets/chatbot.css', [], DEVEN_AI_VER);
});

/** Shortcode: [deven_chatbot title="Need help?" welcome="Hi! How can I help?"] */
add_shortcode('deven_chatbot', function($atts){
    $a = shortcode_atts([
        'title'   => 'Need help?',
        'welcome' => 'Hi! How can I help?',
        'minheight' => '380px',
    ], $atts, 'deven_chatbot');
    // ensure formatting + chatbot assets load
    wp_enqueue_script('deven-markdown');
    wp_enqueue_script('deven-hljs');
    wp_enqueue_style('deven-hljs');
    wp_enqueue_script('deven-chatbot');
    wp_enqueue_style('deven-chatbot');
    ob_start(); ?>
    <div class="deven-chatbot" style="min-height:<?php echo esc_attr($a['minheight']); ?>">
        <div class="deven-chatbot__header"><?php echo esc_html($a['title']); ?></div>
        <div class="deven-chatbot__messages" data-welcome="<?php echo esc_attr($a['welcome']); ?>"></div>
        <form class="deven-chatbot__form" action="#" method="post">
            <input class="deven-chatbot__input" type="text" placeholder="<?php esc_attr_e('Type your message…','deven-ai'); ?>" />
            <button class="deven-chatbot__send" type="submit"><?php esc_html_e('Send','deven-ai'); ?></button>
        </form>
    </div>
    <?php return ob_get_clean();
});
