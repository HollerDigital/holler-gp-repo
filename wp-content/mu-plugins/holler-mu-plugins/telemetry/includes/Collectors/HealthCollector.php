<?php
namespace HD_Telemetry\Collectors;

if (!defined('ABSPATH')) exit;

class HealthCollector {
    public static function compute(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $updates_core    = 0;
        $updates_plugins = 0;
        $updates_themes  = 0;
        try {
            $core_updates = get_core_updates();
            if (is_array($core_updates)) {
                foreach ($core_updates as $cu) {
                    if (!empty($cu->response) && $cu->response === 'upgrade') { $updates_core++; }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $plugin_updates = get_plugin_updates();
            if (is_array($plugin_updates)) { $updates_plugins = count($plugin_updates); }
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $theme_updates = get_theme_updates();
            if (is_array($theme_updates)) { $updates_themes = count($theme_updates); }
        } catch (\Throwable $e) { /* ignore */ }

        global $wpdb;
        $autoload_bytes = null;
        try {
            $autoload_bytes = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload='yes'");
        } catch (\Throwable $e) { /* ignore */ }

        $uploads_dir = wp_upload_dir();
        $uploads_path = rtrim($uploads_dir['basedir'] ?? (WP_CONTENT_DIR . '/uploads'), '/');
        $uploads_writable = function_exists('wp_is_writable') ? wp_is_writable($uploads_path) : is_writable($uploads_path);

        $disk_free_mb = null; $disk_total_mb = null;
        try {
            $df = @disk_free_space(WP_CONTENT_DIR);
            $dt = @disk_total_space(WP_CONTENT_DIR);
            if ($df !== false && $dt !== false) {
                $disk_free_mb = round($df / 1048576, 2);
                $disk_total_mb = round($dt / 1048576, 2);
            }
        } catch (\Throwable $e) { /* ignore */ }

        return [
            'updates' => [ 'core' => $updates_core, 'plugins' => $updates_plugins, 'themes' => $updates_themes ],
            'autoload_bytes' => $autoload_bytes,
            'uploads_writable' => (bool)$uploads_writable,
            'disk' => [ 'free_mb' => $disk_free_mb, 'total_mb' => $disk_total_mb ],
        ];
    }
}
