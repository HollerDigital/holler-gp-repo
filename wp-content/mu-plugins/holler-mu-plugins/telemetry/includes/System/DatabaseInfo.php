<?php
namespace HD_Telemetry\System;

if (!defined('ABSPATH')) exit;

class DatabaseInfo {
    public function info(): array {
        $version = '';
        $type = 'mysql';
        $driver = 'unknown';
        $host = null;
        $name = null;
        $prefix = null;
        $charset = null;
        $collate = null;

        try {
            if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
                $wpdb = $GLOBALS['wpdb'];
                if (method_exists($wpdb, 'db_version')) {
                    $version = (string) $wpdb->db_version();
                }
                // Determine engine type (MariaDB vs MySQL) from version string
                if (stripos($version, 'mariadb') !== false) { $type = 'mariadb'; }

                // Determine driver
                if (property_exists($wpdb, 'use_mysqli') && $wpdb->use_mysqli) { $driver = 'mysqli'; }
                elseif (defined('USE_EXT_MYSQL') && USE_EXT_MYSQL) { $driver = 'ext/mysql'; }
                elseif (class_exists('PDO') && in_array('mysql', \PDO::getAvailableDrivers(), true)) { $driver = 'pdo_mysql'; }

                // Basic connection/schema info (non-sensitive)
                if (property_exists($wpdb, 'dbhost'))   { $host = (string) $wpdb->dbhost; }
                if (property_exists($wpdb, 'dbname'))   { $name = (string) $wpdb->dbname; }
                if (property_exists($wpdb, 'prefix'))   { $prefix = (string) $wpdb->prefix; }
                if (property_exists($wpdb, 'charset'))  { $charset = (string) $wpdb->charset; }
                if (property_exists($wpdb, 'collate'))  { $collate = (string) $wpdb->collate; }
            }
        } catch (\Throwable $e) { /* ignore */ }

        return [
            'type' => $type,
            'version' => $version,
            'driver' => $driver,
            'host' => $host,
            'name' => $name,
            'table_prefix' => $prefix,
            'charset' => $charset,
            'collate' => $collate,
        ];
    }
}
