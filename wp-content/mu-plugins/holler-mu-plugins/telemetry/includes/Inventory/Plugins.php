<?php
namespace HD_Telemetry\Inventory;

if (!defined('ABSPATH')) exit;

class Plugins {
    public function all(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        // Read the current plugin update transient (do not force remote checks on each request)
        $updates = get_site_transient('update_plugins');
        $updates_map = is_object($updates) && isset($updates->response) && is_array($updates->response) ? $updates->response : [];
        // Read per-plugin auto-update settings
        $auto_update_list = (array) get_site_option('auto_update_plugins', []);
        $plugins = [];
        foreach (get_plugins() as $slug => $info) {
            $updObj = $updates_map[$slug] ?? null;
            $newVer = is_object($updObj) && isset($updObj->new_version) ? (string)$updObj->new_version : null;
            $auto   = in_array($slug, $auto_update_list, true);
            $plugins[] = [
               'name'    => $info['Name'] ?? '',
                'slug'    => $slug,
                'version' => $info['Version'] ?? '',
                'active'  => is_plugin_active($slug),
                'update_available' => $newVer !== null,
                'new_version' => $newVer,
                'auto_update' => $auto,
            ];
        }
        return $plugins;
    }

    public function thisPluginVersion(): string {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach (get_plugins() as $slug => $info) {
            if (strpos($slug, 'holler-telemetry/holler-telemetry.php') === 0) {
                return $info['Version'] ?? '';
            }
        }
        return '';
    }
}
