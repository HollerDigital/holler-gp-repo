<?php
namespace HD_Telemetry\Collectors;

if (!defined('ABSPATH')) exit;

class PerformanceCollector {
    public static function compute(): array {
        // Object cache info
        $object_cache = [
            'enabled' => function_exists('wp_using_ext_object_cache') ? wp_using_ext_object_cache() : false,
            'backend' => null,
        ];
        if (file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
            if (class_exists('Redis') || defined('WP_REDIS_HOST')) $object_cache['backend'] = 'redis';
            elseif (class_exists('Memcached') || class_exists('Memcache')) $object_cache['backend'] = 'memcached';
            else $object_cache['backend'] = 'dropin';
        }

        // Opcache summary (non-heavy)
        $opcache = [ 'enabled' => false, 'used_mb' => null, 'free_mb' => null, 'hit_rate' => null ];
        if (function_exists('opcache_get_status')) {
            $st = @opcache_get_status(false);
            if (is_array($st)) {
                $opcache['enabled'] = !empty($st['opcache_enabled']);
                if (isset($st['memory_usage'])) {
                    $opcache['used_mb'] = round(($st['memory_usage']['used_memory'] ?? 0) / 1048576, 2);
                    $opcache['free_mb'] = round(($st['memory_usage']['free_memory'] ?? 0) / 1048576, 2);
                }
                if (isset($st['opcache_statistics']) && ($st['opcache_statistics']['opcache_hit_rate'] ?? null) !== null) {
                    $opcache['hit_rate'] = round((float)$st['opcache_statistics']['opcache_hit_rate'], 2);
                }
            }
        }

        return [
            'object_cache' => $object_cache,
            'opcache' => $opcache,
        ];
    }
}
