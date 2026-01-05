<?php
namespace HD_Telemetry\Collectors;

if (!defined('ABSPATH')) exit;

class BackupsCollector {
    public static function compute(): array {
        $blogvault = false;
        $updraft   = false;
        if (function_exists('is_plugin_active')) {
            $blogvault = is_plugin_active('blogvault-real-time-backup/blogvault-real-time-backup.php')
                      || is_plugin_active('blogvault-real-time-backup/init.php')
                      || is_plugin_active('wpremote/plugin.php')
                      || is_plugin_active('blogvault-real-time-backup/blogvault.php');
            $updraft = is_plugin_active('updraftplus/updraftplus.php');
        }
        return [
            'blogvault' => [ 'active' => (bool)$blogvault ],
            'updraftplus' => [ 'active' => (bool)$updraft ],
        ];
    }
}
