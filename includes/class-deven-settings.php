<?php
if ( ! defined('ABSPATH') ) exit;

class DevEN_AI_Settings {
    private static $instance = null;
    public static function instance(){ return self::$instance ?? (self::$instance = new self()); }

    private $opt_key = 'deven_ai_settings';

    private function __construct(){
        add_action('admin_menu', [ $this, 'menu' ]);
        add_action('admin_init', [ $this, 'register' ]);
    }

    public function defaults(){
        return [
            'provider'   => 'openai',
            'api_key'    => '',
            'model'      => 'gpt-4o-mini',
            'temperature'=> '0.7',
            'log'        => '0',
            'system'     => "You are DevEN AI, a helpful assistant for WordPress content and development."
        ];
    }

    public function get(){
        return wp_parse_args( get_option($this->opt_key, []), $this->defaults() );
    }

    public function menu(){
        add_menu_page(
            __('DevEN AI','deven-ai'),
            __('DevEN AI','deven-ai'),
            deven_ai_capability(),
            'deven-ai',
            [ $this, 'screen' ],
            'dashicons-art',
            58
        );
    }

    public function register(){
        register_setting('deven_ai', $this->opt_key, [
            'type' => 'array',
            'sanitize_callback' => [ $this, 'sanitize' ],
            'default' => $this->defaults()
        ]);

        add_settings_section('deven_ai_main', __('API Settings','deven-ai'), function(){
            echo '<p>'.esc_html__('Provide your API credentials and defaults. Keys are stored in the WP options table.','deven-ai').'</p>';
        }, 'deven_ai');

        $fields = [
            'provider'    => ['label'=>'Provider','type'=>'select','choices'=>['openai'=>'OpenAI']],
            'api_key'     => ['label'=>'API Key','type'=>'password'],
            'model'       => ['label'=>'Model','type'=>'text'],
            'temperature' => ['label'=>'Temperature','type'=>'number','attrs'=>['min'=>'0','max'=>'2','step'=>'0.1']],
            'system'      => ['label'=>'System Prompt','type'=>'textarea'],
            'log'         => ['label'=>'Store request logs (debug)','type'=>'checkbox'],
        ];

        foreach($fields as $key=>$cfg){
            add_settings_field($key, esc_html($cfg['label']), function() use ($key,$cfg){
                $opt = $this->get();
                $name = $this->opt_key.'['.$key.']';
                $val  = isset($opt[$key]) ? $opt[$key] : '';
                switch($cfg['type']){
                    case 'select':
                        echo '<select name="'.esc_attr($name).'">';
                        foreach($cfg['choices'] as $k=>$label){
                            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($val,$k,false), esc_html($label));
                        }
                        echo '</select>';
                        break;
                    case 'password':
                        printf('<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off"/>', esc_attr($name), esc_attr($val));
                        break;
                    case 'number':
                        $attrs = '';
                        if (!empty($cfg['attrs'])) foreach($cfg['attrs'] as $ak=>$av) $attrs .= sprintf(' %s="%s"',$ak,esc_attr($av));
                        printf('<input type="number" name="%s" value="%s" class="small-text"%s/>', esc_attr($name), esc_attr($val), $attrs);
                        break;
                    case 'textarea':
                        printf('<textarea name="%s" rows="6" class="large-text code">%s</textarea>', esc_attr($name), esc_textarea($val));
                        break;
                    case 'checkbox':
                        printf('<label><input type="checkbox" name="%s" value="1" %s/> %s</label>',
                            esc_attr($name), checked($val,'1',false), esc_html__('Enable','deven-ai'));
                        break;
                    default:
                        printf('<input type="text" name="%s" value="%s" class="regular-text" />', esc_attr($name), esc_attr($val));
                }
            }, 'deven_ai', 'deven_ai_main');
        }
    }

    public function sanitize($input){
        $d = $this->defaults();
        $out = [];
        $out['provider']    = sanitize_text_field( $input['provider'] ?? $d['provider'] );
        $out['api_key']     = trim( (string) ($input['api_key'] ?? '') );
        $out['model']       = sanitize_text_field( $input['model'] ?? $d['model'] );
        $out['temperature'] = is_numeric($input['temperature'] ?? null) ? (string) $input['temperature'] : $d['temperature'];
        $out['system']      = wp_kses_post( $input['system'] ?? $d['system'] );
        $out['log']         = !empty($input['log']) ? '1' : '0';
        return $out;
    }

    public function screen(){
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DevEN AI','deven-ai'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('deven_ai');
                do_settings_sections('deven_ai');
                submit_button();
                ?>
            </form>
            <hr/>
            <h2><?php esc_html_e('Quick Test','deven-ai'); ?></h2>
            <p><?php esc_html_e('Use the editor sidebar or the test box below to verify your setup.','deven-ai'); ?></p>
            <textarea id="deven-test-input" class="large-text code" rows="4" placeholder="Ask DevEN AI…"></textarea><br/>
            <p><button class="button button-primary" id="deven-test-send"><?php esc_html_e('Send','deven-ai'); ?></button></p>
            <pre id="deven-test-output" class="deven-output" style="max-width:800px; white-space:pre-wrap;"></pre>
        </div>
        <?php
    }
}
