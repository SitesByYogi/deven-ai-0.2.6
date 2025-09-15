<?php
if ( ! defined('ABSPATH') ) exit;

class DevEN_AI_REST {
    private static $instance = null;
    public static function instance(){ return self::$instance ?? (self::$instance = new self()); }

    private function __construct(){
        add_action('rest_api_init', [ $this, 'routes' ]);
    }

    public function routes(){
        register_rest_route('deven-ai/v1','/chat',[
            'methods'  => 'POST',
            'callback' => [ $this, 'chat' ],
            'permission_callback' => '__return_true',
            'args' => [
                'messages'    => [ 'required'=>true,  'type'=>'array' ],
                'temperature' => [ 'required'=>false, 'type'=>'number' ],
                'model'       => [ 'required'=>false, 'type'=>'string' ],
                'scope'       => [ 'required'=>false, 'type'=>'string' ],
            ]
        ]);
    }

    public function chat( WP_REST_Request $req ){
        if ( ! wp_verify_nonce( $req->get_header('X-WP-Nonce'), 'wp_rest' ) ) {
            return new WP_REST_Response([ 'error'=>'bad_nonce' ], 403);
        }
        if ( ! deven_ai_check_rate_limit('chat', 8, 30) ) {
            return new WP_REST_Response([ 'error'=>'rate_limited' ], 429);
        }

        $settings = DevEN_AI_Settings::instance()->get();
        if ( empty($settings['api_key']) ) {
            return new WP_REST_Response([ 'error'=>'no_api_key' ], 400);
        }

        $messages    = $req->get_param('messages');
        $temperature = floatval( $req->get_param('temperature') ?? $settings['temperature'] );
        $model       = (string) ( $req->get_param('model') ?? $settings['model'] );

        array_unshift($messages, [
            'role'    => 'system',
            'content' => $settings['system']
        ]);

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ];

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer '.$settings['api_key'],
                ],
                'body' => wp_json_encode($body),
            ]
        );

        if ( is_wp_error($response) ) {
            return new WP_REST_Response([ 'error'=>'http_error', 'detail'=>$response->get_error_message() ], 500);
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode( wp_remote_retrieve_body($response), true );

        if ( $code >= 400 ) {
            return new WP_REST_Response([ 'error'=>'provider_error', 'detail'=>$data ], $code );
        }

        $text = $data['choices'][0]['message']['content'] ?? '';
        return new WP_REST_Response([ 'text'=>$text, 'raw'=>$data ], 200);
    }
}
