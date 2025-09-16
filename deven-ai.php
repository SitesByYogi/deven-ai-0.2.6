<?php
/**
 * Plugin Name: DevEN AI
 * Description: AI-powered content creation, on-site chatbot, and developer help—right inside WordPress.
 * Version: 0.2.6
 * Author: SitesByYogi
 * Text Domain: deven-ai
 */

if ( ! defined('ABSPATH') ) exit;

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------
define('DEVEN_AI_VER',  '0.2.6');
define('DEVEN_AI_PATH', plugin_dir_path(__FILE__));
define('DEVEN_AI_URL',  plugin_dir_url(__FILE__));

// -----------------------------------------------------------------------------
// Includes (helpers + REST proxy only) — we are NOT using class-deven-settings.php
// -----------------------------------------------------------------------------
require_once DEVEN_AI_PATH . 'includes/helpers.php';

// -----------------------------------------------------------------------------
// Back-compat shim: DevENAISettings → reads the new fail-safe options
// -----------------------------------------------------------------------------
if ( ! class_exists('DevENAISettings') ) {
    class DevENAISettings {
        private static $inst = null;

        public static function instance() {
            if ( self::$inst === null ) self::$inst = new self();
            return self::$inst;
        }

        /** Generic getter (supports old keys if rest class passes them) */
        public static function get( $key, $default = '' ) {
            // Map legacy keys to our option names
            $map = [
                'api_key'     => 'deven_ai_api_key',
                'provider'    => 'deven_ai_provider',
                'model'       => 'deven_ai_model',
                'temperature' => 'deven_ai_temperature',
            ];
            $opt = isset($map[$key]) ? $map[$key] : $key;
            $val = get_option($opt, $default);
            return is_string($val) ? trim($val) : $val;
        }

        // Convenience accessors (cover common static usages)
        public static function api_key()     { return self::get('api_key', ''); }
        public static function provider()    { return self::get('provider', 'openai'); }
        public static function model()       { return self::get('model', 'gpt-4o-mini'); }
        public static function temperature() { return (float) self::get('temperature', '0.2'); }
    }
}

// Back-compat shim so REST can read settings in either format
if ( ! class_exists('DevEN_AI_Settings') ) {
    class DevEN_AI_Settings {
        private static $inst = null;
        public static function instance(){ return self::$inst ?? (self::$inst = new self()); }

        // get() with no args returns the full array (what REST expects)
        public function get( $key = null, $default = '' ) {
            // New flat options
            $cur = [
                'provider'    => get_option('deven_ai_provider', 'openai'),
                'api_key'     => get_option('deven_ai_api_key', ''),
                'model'       => get_option('deven_ai_model', 'gpt-4o-mini'),
                'temperature' => get_option('deven_ai_temperature', '0.2'),
                'system'      => get_option('deven_ai_system', "You are DevEN AI, a helpful WordPress & web dev assistant."),
            ];

            // Fallback to legacy array if flats are empty
            $legacy = get_option('deven_ai_settings', []);
            if ( is_array($legacy) ) {
                foreach (['provider','api_key','model','temperature','system'] as $k) {
                    if ( $cur[$k] === '' && isset($legacy[$k]) ) {
                        $cur[$k] = $legacy[$k];
                    }
                }
            }

            // Type-fix
            $cur['temperature'] = (string) (float) $cur['temperature'];

            if ($key === null) return $cur;
            return array_key_exists($key, $cur) ? $cur[$key] : $default;
        }
    }
}

// (Optional older name some code used; forward to the class above)
if ( ! class_exists('DevENAISettings') ) {
    class DevENAISettings {
        public static function instance(){ return DevEN_AI_Settings::instance(); }
        public static function get($key, $default = ''){ return DevEN_AI_Settings::instance()->get($key, $default); }
        public static function api_key(){ return self::get('api_key', ''); }
        public static function provider(){ return self::get('provider', 'openai'); }
        public static function model(){ return self::get('model', 'gpt-4o-mini'); }
        public static function temperature(){ return (float) self::get('temperature', '0.2'); }
    }
}

require_once DEVEN_AI_PATH . 'includes/class-deven-rest.php';

// Boot REST
add_action('plugins_loaded', function () {
    if ( class_exists('DevEN_AI_REST') ) {
        DevEN_AI_REST::instance();
    }
});

// -----------------------------------------------------------------------------
// Admin assets (editor sidebar + console/settings screens)
// -----------------------------------------------------------------------------
add_action('admin_enqueue_scripts', function () {
    // Editor sidebar (Block Editor only)
    if ( function_exists('get_current_screen') ) {
        $screen = get_current_screen();
        if ( $screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor() ) {
            wp_enqueue_script(
                'deven-editor-sidebar',
                DEVEN_AI_URL . 'assets/editor-sidebar.js',
                ['wp-plugins','wp-edit-post','wp-element','wp-components','wp-data','wp-api-fetch','wp-i18n'],
                DEVEN_AI_VER,
                true
            );
            wp_localize_script('deven-editor-sidebar', 'DevENAI', [
                'restUrl' => esc_url_raw( rest_url('deven-ai/v1/chat') ),
                'nonce'   => wp_create_nonce('wp_rest'),
            ]);
        }
    }

    // Shared formatting assets (register once)
    wp_register_script('deven-markdown', DEVEN_AI_URL . 'assets/markdown.js', [], DEVEN_AI_VER, true);
    wp_register_script('deven-hljs',    DEVEN_AI_URL . 'assets/highlight.min.js', [], DEVEN_AI_VER, true);
    wp_register_style ('deven-hljs',    DEVEN_AI_URL . 'assets/highlight.css', [], DEVEN_AI_VER);

    // Load admin console/settings assets only on our screens
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $is_console  = ($screen && strpos($screen->id, 'deven-ai-console')  !== false);
    $is_settings = ($screen && strpos($screen->id, 'deven-ai-settings') !== false);

    if ( $is_console || $is_settings ) {
        wp_enqueue_script('deven-markdown');
        wp_enqueue_script('deven-hljs');
        wp_enqueue_style ('deven-hljs');

        wp_enqueue_script('deven-admin', DEVEN_AI_URL . 'assets/admin.js', ['jquery'], DEVEN_AI_VER, true);
        wp_enqueue_style ('deven-admin', DEVEN_AI_URL . 'assets/admin.css', [], DEVEN_AI_VER);

        wp_localize_script('deven-admin', 'DevENAI', [
            'restUrl' => esc_url_raw( rest_url('deven-ai/v1/chat') ),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);

        // Allow page toggle to affect markdown (the inline checkbox sets window.DevENMarkdownEnabled)
        wp_add_inline_script('deven-admin', 'window.DevENMarkdown = window.DevENMarkdownEnabled ? window.DevENMarkdown : null;', 'after');
    }
});

// -----------------------------------------------------------------------------
// Front-end assets (shortcode-driven)
// -----------------------------------------------------------------------------
add_action('wp_enqueue_scripts', function () {
    // Shared formatting for chatbot output
    wp_register_script('deven-markdown', DEVEN_AI_URL . 'assets/markdown.js', [], DEVEN_AI_VER, true);
    wp_register_script('deven-hljs',    DEVEN_AI_URL . 'assets/highlight.min.js', [], DEVEN_AI_VER, true);
    wp_register_style ('deven-hljs',    DEVEN_AI_URL . 'assets/highlight.css', [], DEVEN_AI_VER);

    // Chatbot UI
    wp_register_script('deven-chatbot', DEVEN_AI_URL . 'assets/chatbot.js', [], DEVEN_AI_VER, true);
    wp_localize_script('deven-chatbot', 'DevENAIFront', [
        'restUrl'     => esc_url_raw( rest_url('deven-ai/v1/chat') ),
        'nonce'       => wp_create_nonce('wp_rest'),
        'placeholder' => __('Ask me anything…', 'deven-ai'),
    ]);

    wp_register_style('deven-chatbot', DEVEN_AI_URL . 'assets/chatbot.css', [], DEVEN_AI_VER);
});

// -----------------------------------------------------------------------------
// Shortcode: [deven_chatbot title="Need help?" welcome="Hi! How can I help?" minheight="380px"]
// -----------------------------------------------------------------------------
add_shortcode('deven_chatbot', function ($atts) {
    $a = shortcode_atts([
        'title'     => 'Need help?',
        'welcome'   => 'Hi! How can I help?',
        'minheight' => '380px',
    ], $atts, 'deven_chatbot');

    // Ensure formatting + chatbot assets load
    wp_enqueue_script('deven-markdown');
    wp_enqueue_script('deven-hljs');
    wp_enqueue_style ('deven-hljs');

    wp_enqueue_script('deven-chatbot');
    wp_enqueue_style ('deven-chatbot');

    ob_start(); ?>
    <div class="deven-chatbot" style="min-height:<?php echo esc_attr($a['minheight']); ?>">
        <div class="deven-chatbot__header"><?php echo esc_html($a['title']); ?></div>
        <div class="deven-chatbot__messages" data-welcome="<?php echo esc_attr($a['welcome']); ?>"></div>
        <form class="deven-chatbot__form" action="#" method="post">
            <input class="deven-chatbot__input" type="text" placeholder="<?php esc_attr_e('Type your message…','deven-ai'); ?>" />
            <button class="deven-chatbot__send" type="submit"><?php esc_html_e('Send','deven-ai'); ?></button>
        </form>
    </div>
    <?php
    return ob_get_clean();
});

// -----------------------------------------------------------------------------
// Dev Helper Console (admin)
// -----------------------------------------------------------------------------
add_action('admin_menu', function () {
    // Top-level
    add_menu_page(
        __('DevEN AI', 'deven-ai'),
        __('DevEN AI', 'deven-ai'),
        'manage_options',
        'deven-ai-console',
        'deven_ai_render_console_page',
        'dashicons-art',
        60
    );

    // Settings subpage (rendered by fail-safe settings below)
    add_submenu_page(
        'deven-ai-console',
        __('Settings', 'deven-ai'),
        __('Settings', 'deven-ai'),
        'manage_options',
        'deven-ai-settings',
        'deven_ai_render_settings_page'
    );
});

function deven_ai_render_console_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    } ?>
    <div class="wrap">
        <h1><?php esc_html_e('Dev Helper Console', 'deven-ai'); ?></h1>
        <p><?php esc_html_e('Ask anything about WordPress, PHP, JS, CSS, hooks, errors—get instant answers and code. Your key + model settings power the responses.', 'deven-ai'); ?></p>

        <div id="deven-console" style="max-width:920px">
            <div style="display:flex; gap:10px; align-items:flex-start;">
                <textarea id="deven-test-input" rows="6" style="flex:1; width:100%;" placeholder="e.g., “Write a WP_Query that gets last 5 posts in a custom taxonomy.”"></textarea>
                <div style="display:flex; flex-direction:column; gap:8px; min-width:180px;">
                    <button id="deven-test-send" class="button button-primary button-hero"><?php esc_html_e('Ask', 'deven-ai'); ?></button>

                    <!-- Quick prompts -->
                    <button class="button deven-quick" data-q="Explain this PHP error and how to fix it: "><?php esc_html_e('Explain an error', 'deven-ai'); ?></button>
                    <button class="button deven-quick" data-q="Write a WordPress hook example for: "><?php esc_html_e('WP Hook example', 'deven-ai'); ?></button>
                    <button class="button deven-quick" data-q="Refactor this function for performance and readability: "><?php esc_html_e('Refactor code', 'deven-ai'); ?></button>
                    <button class="button deven-quick" data-q="Generate a minimal shortcode that: "><?php esc_html_e('Generate shortcode', 'deven-ai'); ?></button>
                </div>
            </div>

            <div style="margin-top:12px;">
                <label style="display:inline-flex; gap:8px; align-items:center;">
                    <input type="checkbox" id="deven-markdown-toggle" checked />
                    <span><?php esc_html_e('Render Markdown (recommended)', 'deven-ai'); ?></span>
                </label>
            </div>

            <div id="deven-test-output" style="margin-top:12px;"></div>
        </div>
    </div>

    <script>
    // Small helper for quick-prompt buttons + Markdown toggle
    (function(){
        document.querySelectorAll('.deven-quick').forEach(function(btn){
            btn.addEventListener('click', function(){
                var base = this.getAttribute('data-q') || '';
                var ta = document.getElementById('deven-test-input');
                ta.value = base + (ta.value || '');
                ta.focus();
            });
        });
        // Let admin.js know whether to render markdown
        window.DevENMarkdownEnabled = true;
        var t = document.getElementById('deven-markdown-toggle');
        if (t) t.addEventListener('change', function(){
            window.DevENMarkdownEnabled = !!this.checked;
        });
    })();
    </script>
    <?php
}

// -----------------------------------------------------------------------------
// Fail-safe Settings Screen (kept)
// -----------------------------------------------------------------------------

// Ensure options exist (first-run safety)
add_action('admin_init', function () {
    add_option('deven_ai_api_key', '');
    add_option('deven_ai_provider', 'openai');   // openai | anthropic | local
    add_option('deven_ai_model', 'gpt-4o-mini');
    add_option('deven_ai_temperature', '0.2');
});

// One-time migration from legacy array option -> new flat options
add_action('admin_init', function () {
    $legacy = get_option('deven_ai_settings'); // from the old class-based settings
    if ( ! is_array($legacy) ) return;

    // Only migrate values that are missing in the new options
    $map = [
        'api_key'     => 'deven_ai_api_key',
        'provider'    => 'deven_ai_provider',
        'model'       => 'deven_ai_model',
        'temperature' => 'deven_ai_temperature',
        // optional: if you later add a system prompt field
        // 'system'      => 'deven_ai_system',
    ];

    foreach ( $map as $k => $opt ) {
        $new = get_option($opt, '');
        if ( $new === '' && isset($legacy[$k]) && $legacy[$k] !== '' ) {
            update_option($opt, $legacy[$k]);
        }
    }
});

// Register settings + fields
add_action('admin_init', function () {
    register_setting('deven_ai', 'deven_ai_api_key', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => ''
    ]);
    register_setting('deven_ai', 'deven_ai_provider', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'openai'
    ]);
    register_setting('deven_ai', 'deven_ai_model', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'gpt-4o-mini'
    ]);
    register_setting('deven_ai', 'deven_ai_temperature', [
        'type'              => 'string',
        'sanitize_callback' => function($v){ $f = floatval($v); if ($f < 0) $f = 0; if ($f > 1) $f = 1; return (string)$f; },
        'default'           => '0.2'
    ]);

    add_settings_section('deven_ai_main', __('Core Settings','deven-ai'), function () {
        echo '<p>' . esc_html__('Enter your API details. These are used by the REST proxy for the editor sidebar, chatbot, and Dev Helper Console.', 'deven-ai') . '</p>';
    }, 'deven-ai');

    add_settings_field('deven_ai_api_key', __('API Key','deven-ai'), function () {
        $val = get_option('deven_ai_api_key', '');
        echo '<input type="password" id="deven_ai_api_key" name="deven_ai_api_key" value="' . esc_attr($val) . '" class="regular-text" autocomplete="off" />';
        echo ' <label><input type="checkbox" onclick="const i=document.getElementById(\'deven_ai_api_key\'); i.type = this.checked?\'text\':\'password\';"> ' . esc_html__('Show', 'deven-ai') . '</label>';
    }, 'deven-ai', 'deven_ai_main');

    add_settings_field('deven_ai_provider', __('Provider','deven-ai'), function () {
        $cur = get_option('deven_ai_provider','openai'); ?>
        <select name="deven_ai_provider" id="deven_ai_provider">
            <option value="openai"     <?php selected($cur,'openai'); ?>>OpenAI</option>
            <option value="anthropic"  <?php selected($cur,'anthropic'); ?>>Anthropic</option>
            <option value="local"      <?php selected($cur,'local'); ?>>Local / Other</option>
        </select>
    <?php }, 'deven-ai', 'deven_ai_main');

    add_settings_field('deven_ai_model', __('Model','deven-ai'), function () {
        $val = get_option('deven_ai_model','gpt-4o-mini');
        echo '<input type="text" name="deven_ai_model" id="deven_ai_model" value="' . esc_attr($val) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Example: gpt-4o-mini, gpt-4.1, claude-3-haiku, etc.', 'deven-ai') . '</p>';
    }, 'deven-ai', 'deven_ai_main');

    add_settings_field('deven_ai_temperature', __('Temperature','deven-ai'), function () {
        $val = get_option('deven_ai_temperature','0.2');
        echo '<input type="number" step="0.01" min="0" max="1" name="deven_ai_temperature" id="deven_ai_temperature" value="' . esc_attr($val) . '" />';
        echo '<p class="description">' . esc_html__('0 = deterministic, 1 = very creative.', 'deven-ai') . '</p>';
    }, 'deven-ai', 'deven_ai_main');
});

// Render settings page
function deven_ai_render_settings_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    } ?>
    <div class="wrap">
        <h1><?php esc_html_e('DevEN AI Settings','deven-ai'); ?></h1>
        <form method="post" action="options.php">
            <?php
                settings_fields('deven_ai');
                do_settings_sections('deven-ai');
                submit_button(__('Save Settings','deven-ai'));
            ?>
        </form>
    </div>
    <?php
}

// Settings link on Plugins list row
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=deven-ai-settings');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings','deven-ai') . '</a>');
    return $links;
});
