<?php
namespace HD_Telemetry\Rest;

if (!defined('ABSPATH')) exit;

class TokenController {
    public function register() {
        add_action('rest_api_init', function () {
            register_rest_route('hd/v1', '/token', [
                'methods'  => 'GET',
                'permission_callback' => '__return_true',
                'callback' => [$this, 'handle'],
            ]);
        });
    }

    public function handle($req) {
        if (!\hd_telemetry_auth_ok($req)) {
            return new \WP_REST_Response(['error' => 'Unauthorized'], 401);
        }
        return new \WP_REST_Response(['token' => \hd_telemetry_get_token()], 200);
    }
}
