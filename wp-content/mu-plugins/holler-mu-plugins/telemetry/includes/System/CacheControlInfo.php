<?php
namespace HD_Telemetry\System;

if (!defined('ABSPATH')) exit;

class CacheControlInfo {
    public function info(): array {
        $out = [ 'installed' => false ];
        try {
            if (class_exists('Holler\\CacheControl\\Admin\\Tools')) {
                $statuses = \Holler\CacheControl\Admin\Tools::get_cache_systems_status();
                $opts_settings = function_exists('get_option') ? (array) get_option('holler_cache_control_settings', []) : [];
                $opts_auto     = function_exists('get_option') ? (array) get_option('holler_cache_control_auto_purge', []) : [];
                $opts_cf       = function_exists('get_option') ? (array) get_option('holler_cache_control_cloudflare', []) : [];
                $paths         = null;
                if (class_exists('Holler\\CacheControl\\Core\\CachePathDetector')) {
                    $paths = \Holler\CacheControl\Core\CachePathDetector::get_comprehensive_report();
                    // Remove duplicate system info already available at top-level server/php/wordpress blocks
                    if (is_array($paths) && array_key_exists('system_info', $paths)) {
                        unset($paths['system_info']);
                    }
                }
                $out = [
                    'installed' => true,
                    'version' => (defined('HOLLER_CACHE_CONTROL_VERSION') ? HOLLER_CACHE_CONTROL_VERSION : null),
                    'statuses' => $statuses,
                    'options' => [
                        'settings'   => $opts_settings,
                        'auto_purge' => $opts_auto,
                        'cloudflare' => $opts_cf,
                    ],
                    'paths' => $paths,
                ];
            }
        } catch (\Throwable $e) {
            // keep minimal safe output
        }
        return $out;
    }
}
