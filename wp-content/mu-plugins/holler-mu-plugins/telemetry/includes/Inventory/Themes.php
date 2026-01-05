<?php
namespace HD_Telemetry\Inventory;

if (!defined('ABSPATH')) exit;

class Themes {
    public function activeAndParent(): array {
        $theme = wp_get_theme();
        $parent = $theme ? $theme->parent() : null;

        // Theme updates transient: keys are stylesheets
        $updates = get_site_transient('update_themes');
        $updates_map = (is_object($updates) && isset($updates->response) && is_array($updates->response)) ? $updates->response : [];

        $active_stylesheet = $theme ? $theme->get_stylesheet() : null;
        $parent_stylesheet = $parent ? $parent->get_stylesheet() : null;

        // Auto-update settings (array of theme stylesheets)
        $auto_update_list = (array) get_site_option('auto_update_themes', []);
        $active_auto = $active_stylesheet ? in_array($active_stylesheet, $auto_update_list, true) : false;
        $parent_auto = $parent_stylesheet ? in_array($parent_stylesheet, $auto_update_list, true) : false;

        $active_new = ($active_stylesheet && isset($updates_map[$active_stylesheet])) ? $updates_map[$active_stylesheet]['new_version'] ?? null : null;
        $parent_new = ($parent_stylesheet && isset($updates_map[$parent_stylesheet])) ? $updates_map[$parent_stylesheet]['new_version'] ?? null : null;

        return [
            'active' => $theme ? [
                'stylesheet'       => $active_stylesheet,
                'name'             => $theme->get('Name'),
                'version'          => $theme->get('Version'),
                'update_available' => $active_new !== null,
                'new_version'      => $active_new,
                'auto_update'      => $active_auto,
            ] : null,
            'child'  => $parent ? [
                'stylesheet'       => $parent_stylesheet,
                'name'             => $parent->get('Name'),
                'version'          => $parent->get('Version'),
                'update_available' => $parent_new !== null,
                'new_version'      => $parent_new,
                'auto_update'      => $parent_auto,
            ] : null,
        ];
    }
}
