<?php
namespace HD_Telemetry\System;

if (!defined('ABSPATH')) exit;

class PhpInfo {
    public function info(): array {
        // Raw memory limit
        $mem_raw = ini_get('memory_limit');
        $mem_mb  = null;
        if ($mem_raw !== false && $mem_raw !== '' && $mem_raw !== '-1') {
            if (function_exists('wp_convert_hr_to_bytes')) {
                $mem_mb = round((float) wp_convert_hr_to_bytes($mem_raw) / 1048576, 2);
            } else {
                $v = trim($mem_raw);
                $unit = strtolower(substr($v, -1));
                $num = (float) $v;
                if ($unit === 'g') $num *= 1024; elseif ($unit === 'k') $num /= 1024; $mem_mb = round($num, 2);
            }
        }

        // WP memory constants
        $wp_mem_raw = defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : null;
        $wp_mem_mb  = null;
        if ($wp_mem_raw) {
            if (function_exists('wp_convert_hr_to_bytes')) {
                $wp_mem_mb = round((float) wp_convert_hr_to_bytes($wp_mem_raw) / 1048576, 2);
            } else {
                $v = trim($wp_mem_raw); $unit = strtolower(substr($v, -1)); $num = (float)$v; if ($unit==='g') $num*=1024; elseif ($unit==='k') $num/=1024; $wp_mem_mb=round($num,2);
            }
        }
        $wp_max_mem_raw = defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : null;
        $wp_max_mem_mb  = null;
        if ($wp_max_mem_raw) {
            if (function_exists('wp_convert_hr_to_bytes')) {
                $wp_max_mem_mb = round((float) wp_convert_hr_to_bytes($wp_max_mem_raw) / 1048576, 2);
            } else {
                $v = trim($wp_max_mem_raw); $unit = strtolower(substr($v, -1)); $num = (float)$v; if ($unit==='g') $num*=1024; elseif ($unit==='k') $num/=1024; $wp_max_mem_mb=round($num,2);
            }
        }

        return [
            'version' => PHP_VERSION,
            'memory_limit' => $mem_raw,
            'memory_limit_mb' => $mem_mb,
            'wp_memory_limit' => $wp_mem_raw,
            'wp_memory_limit_mb' => $wp_mem_mb,
            'wp_max_memory_limit' => $wp_max_mem_raw,
            'wp_max_memory_limit_mb' => $wp_max_mem_mb,
        ];
    }
}
