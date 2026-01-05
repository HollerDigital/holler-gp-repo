<?php
namespace HD_Telemetry\Collectors;

if (!defined('ABSPATH')) exit;

class SecurityCollector {
    public static function compute(): array {
        $salts_ok = (defined('AUTH_KEY') && strlen(AUTH_KEY) > 10)
                 && (defined('SECURE_AUTH_KEY') && strlen(SECURE_AUTH_KEY) > 10)
                 && (defined('LOGGED_IN_KEY') && strlen(LOGGED_IN_KEY) > 10)
                 && (defined('NONCE_KEY') && strlen(NONCE_KEY) > 10);
        $wp_debug = defined('WP_DEBUG') ? (bool) WP_DEBUG : false;
        $disallow_file_mods = defined('DISALLOW_FILE_MODS') ? (bool) DISALLOW_FILE_MODS : false;
        return [
            'salts_ok' => $salts_ok,
            'wp_debug' => $wp_debug,
            'disallow_file_mods' => $disallow_file_mods,
        ];
    }
}
