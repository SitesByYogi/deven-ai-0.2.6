<?php
if ( ! defined('ABSPATH') ) exit;

function deven_ai_capability() {
    return apply_filters('deven_ai_capability', 'edit_posts');
}
function deven_ai_rate_limit_key($scope='chat'){
    $user = get_current_user_id();
    $ip   = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '0.0.0.0';
    return "deven_ai_rl_{$scope}_{$user}_".md5($ip);
}
function deven_ai_check_rate_limit($scope='chat', $limit=5, $window=30){
    $k = deven_ai_rate_limit_key($scope);
    $now = time();
    $data = get_transient($k);
    if (!$data) $data = [ 'ts'=>$now, 'count'=>0 ];
    if ( ($now - $data['ts']) > $window ) $data = [ 'ts'=>$now, 'count'=>0 ];
    $data['count']++;
    set_transient($k, $data, $window);
    return $data['count'] <= $limit;
}
