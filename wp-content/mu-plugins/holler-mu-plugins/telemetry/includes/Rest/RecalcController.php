<?php
namespace HD_Telemetry\Rest;

if (!defined('ABSPATH')) exit;

class RecalcController {
    public function register() {
        add_action('rest_api_init', function () {
            register_rest_route('hd/v1', '/recalc', [
                'methods'  => 'POST',
                'permission_callback' => '__return_true',
                'callback' => [$this, 'handle'],
            ]);
        });
    }

    public function handle($req) {
        if (!\hd_telemetry_auth_ok($req)) {
            return new \WP_REST_Response(['error' => 'Unauthorized'], 401);
        }
        $payload = \hd_telemetry_collect_and_cache();
        return new \WP_REST_Response($payload ?: ['status' => 'busy'], 200);
    }
}
