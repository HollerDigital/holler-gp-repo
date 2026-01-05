<?php
/**
 * Plugin Name: HD Telemetry Lite
 * Description: Single-file telemetry you can pull from your dashboard. Reports disk usage (uploads/plugins/themes), DB size, stack info. No 3rd-party addons.
 * Version:     0.2.0
 * Author:      Holler Digital
 */

if (!defined('ABSPATH')) exit;

// ====== CONFIG (edit if you want) ======
// Option A (recommended): let the plugin generate a token on activation (stored in option).
// Option B: hardcode your own token here and it will override the stored one.
// define('HD_TELEMETRY_TOKEN', 'paste-a-strong-token-here');

// Recalc cadence: daily via WP-Cron. Snapshot pulls are instant (use cached values).
const HD_TELEMETRY_CRON_HOOK   = 'hd_telemetry_recalc_daily';
const HD_TELEMETRY_OPTION_KEY  = 'hd_telemetry_sizes';
const HD_TELEMETRY_TOKEN_KEY   = 'hd_telemetry_token';
const HD_TELEMETRY_LOCK_KEY    = 'hd_telemetry_calc_lock';
const HD_TELEMETRY_CACHE_TTL_S = 24 * 3600; // 24h before we trigger a background refresh

// Webhook configuration
const HD_TELEMETRY_WEBHOOK_CRON_HOOK = 'hd_telemetry_webhook_daily';
const HD_TELEMETRY_WEBHOOK_TOKEN_KEY = 'hd_telemetry_webhook_token';
if (!defined('HOLLER_API_URL')) {
    define('HOLLER_API_URL', 'https://hub.hollerdigital.dev/');
}
if (!defined('HD_TELEMETRY_WEBHOOK_ENDPOINT')) {
    define('HD_TELEMETRY_WEBHOOK_ENDPOINT', rtrim(HOLLER_API_URL, '/') . '/api/webhooks/hdsnapshot');
}
// Webhook interval: 'hourly', 'twicedaily', or 'daily'
if (!defined('HOLLER_API_INTERVAL')) {
    define('HOLLER_API_INTERVAL', 'hourly');
}

// Error logging configuration
if (!defined('HOLLER_ERROR_LOGGING_ENABLED')) {
    define('HOLLER_ERROR_LOGGING_ENABLED', true); // Set to false to disable error logging
}
if (!defined('HOLLER_ERROR_LOG_ENDPOINT')) {
    define('HOLLER_ERROR_LOG_ENDPOINT', rtrim(HOLLER_API_URL, '/') . '/api/webhooks/log-error');
}

// ====== ACTIVATION / CRON ======
function hd_telemetry_bootstrap() {
    // Ensure token exists (unless overridden by constant)
    if (!defined('HOLLER_API_KEY') && !defined('HD_TELEMETRY_TOKEN')) {
        $t = get_option(HD_TELEMETRY_TOKEN_KEY);
        if (!$t) {
            if (function_exists('wp_generate_password')) {
                $t = wp_generate_password(32, true, true);
            } else {
                $t = bin2hex(random_bytes(16));
            }
            update_option(HD_TELEMETRY_TOKEN_KEY, $t, true);
        }
    }
    // Ensure cron is scheduled (safe to call repeatedly)
    if (!wp_next_scheduled(HD_TELEMETRY_CRON_HOOK)) {
        // jitter start within next hour to avoid thundering herd
        wp_schedule_event(time() + rand(60, 3600), 'daily', HD_TELEMETRY_CRON_HOOK);
    }
    // Ensure webhook cron is scheduled
    if (!wp_next_scheduled(HD_TELEMETRY_WEBHOOK_CRON_HOOK)) {
        // Schedule webhook with configured interval
        wp_schedule_event(time() + rand(300, 900), HOLLER_API_INTERVAL, HD_TELEMETRY_WEBHOOK_CRON_HOOK);
    }
}
// Run bootstrap for both plugin and MU-plugin contexts
add_action('muplugins_loaded', 'hd_telemetry_bootstrap', 1);
add_action('plugins_loaded', 'hd_telemetry_bootstrap', 1);

// ====== UTILITIES ======
function hd_telemetry_get_token() {
    if (defined('HOLLER_API_KEY') && HOLLER_API_KEY) return HOLLER_API_KEY;
    if (defined('HD_TELEMETRY_TOKEN') && HD_TELEMETRY_TOKEN) return HD_TELEMETRY_TOKEN;
    return (string) get_option(HD_TELEMETRY_TOKEN_KEY);
}
function hd_telemetry_auth_ok($req) {
    $auth = $req->get_header('authorization');
    $want = 'Bearer ' . hd_telemetry_get_token();
    return ($auth && hash_equals($want, $auth));
}
function hd_telemetry_size_bytes($path) {
    $total = 0;
    try {
        if (!is_dir($path)) return 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $total += (int) $file->getSize();
            }
        }
    } catch (Throwable $e) {
        // ignore and return what we have
    }
    return $total;
}
function hd_telemetry_disk_snapshot() {
    // Measure uploads, plugins, themes; total is sum of these (safe and fast enough).
    $uploads_dir = wp_upload_dir();
    $uploads     = rtrim($uploads_dir['basedir'] ?? (WP_CONTENT_DIR . '/uploads'), '/');

    $plugins_dir = WP_PLUGIN_DIR;
    $themes_dir  = get_theme_root();

    $uploads_b = hd_telemetry_size_bytes($uploads);
    $plugins_b = hd_telemetry_size_bytes($plugins_dir);
    $themes_b  = hd_telemetry_size_bytes($themes_dir);

    return [
        'uploads_bytes' => $uploads_b,
        'plugins_bytes' => $plugins_b,
        'themes_bytes'  => $themes_b,
        'total_bytes'   => $uploads_b + $plugins_b + $themes_b,
    ];
}
function hd_telemetry_db_size_mb() {
    global $wpdb;
    try {
        // Works on MySQL/MariaDB; returns NULL if perms blocked.
        $rows = $wpdb->get_results("
            SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()
        ");
        if (is_array($rows)) {
            $sum = 0.0;
            foreach ($rows as $r) $sum += (float) $r->size_mb;
            return round($sum, 2);
        }
    } catch (Throwable $e) { /* ignore */ }
    return null;
}
function hd_telemetry_collect_and_cache() {
    // simple lock to avoid concurrent heavy scans
    if (get_transient(HD_TELEMETRY_LOCK_KEY)) return false;
    set_transient(HD_TELEMETRY_LOCK_KEY, 1, 10 * 60); // 10 min lock

    $disk = hd_telemetry_disk_snapshot();
    $dbmb = hd_telemetry_db_size_mb();

    // Delegate to collectors
    $health      = \HD_Telemetry\Collectors\HealthCollector::compute();
    $performance = \HD_Telemetry\Collectors\PerformanceCollector::compute();
    $security    = \HD_Telemetry\Collectors\SecurityCollector::compute();
    $backups     = \HD_Telemetry\Collectors\BackupsCollector::compute();

    $payload = [
        'calculated_at' => gmdate('c'),
        'disk' => [
            'uploads_mb' => round($disk['uploads_bytes'] / 1048576, 2),
            'plugins_mb' => round($disk['plugins_bytes'] / 1048576, 2),
            'themes_mb'  => round($disk['themes_bytes'] / 1048576, 2),
            'total_mb'   => round($disk['total_bytes']  / 1048576, 2),
        ],
        'db' => [
            'size_mb' => $dbmb, // may be null if not permitted
        ],
        'health' => $health,
        'performance' => $performance,
        'security' => $security,
        'backups' => $backups,
    ];

    update_option(HD_TELEMETRY_OPTION_KEY, $payload, false);
    delete_transient(HD_TELEMETRY_LOCK_KEY);
    return $payload;
}

function hd_telemetry_get_webhook_token() {
    // Check for dedicated webhook token first
    if (defined('HD_TELEMETRY_WEBHOOK_TOKEN') && HD_TELEMETRY_WEBHOOK_TOKEN) {
        return HD_TELEMETRY_WEBHOOK_TOKEN;
    }
    // Fall back to stored webhook token
    $stored = get_option(HD_TELEMETRY_WEBHOOK_TOKEN_KEY, '');
    if (!empty($stored)) {
        return (string) $stored;
    }
    // Fall back to existing telemetry tokens
    if (defined('HOLLER_API_KEY') && HOLLER_API_KEY) {
        return HOLLER_API_KEY;
    }
    if (defined('HD_TELEMETRY_TOKEN') && HD_TELEMETRY_TOKEN) {
        return HD_TELEMETRY_TOKEN;
    }
    // Finally fall back to stored telemetry token
    return (string) get_option(HD_TELEMETRY_TOKEN_KEY, '');
}

function hd_telemetry_get_webhook_domain() {
    if (defined('HD_TELEMETRY_WEBHOOK_DOMAIN') && HD_TELEMETRY_WEBHOOK_DOMAIN) {
        return HD_TELEMETRY_WEBHOOK_DOMAIN;
    }
    return parse_url(get_site_url(), PHP_URL_HOST);
}

function hd_telemetry_send_webhook() {
    // Get configuration
    $endpoint = HD_TELEMETRY_WEBHOOK_ENDPOINT;
    $domain = hd_telemetry_get_webhook_domain();
    $token = hd_telemetry_get_webhook_token();
    
    // Skip if no token configured
    if (empty($token)) {
        error_log('HD Telemetry: Webhook token not configured');
        return false;
    }
    
    // Collect data
    $cached = get_option(HD_TELEMETRY_OPTION_KEY);
    
    // WordPress version
    $ver = get_bloginfo('version');
    $vp = array_map('intval', explode('.', (string)$ver));
    
    $wordpress = [
        'version' => $ver,
        'version_major' => $vp[0] ?? null,
        'version_minor' => $vp[1] ?? null,
        'version_patch' => $vp[2] ?? null,
    ];
    
    // Build data structure
    $data = [
        'wordpress' => $wordpress,
    ];
    
    // Add cached metrics if available
    if (is_array($cached)) {
        if (isset($cached['disk'])) {
            $data['disk'] = $cached['disk'];
        }
        if (isset($cached['db'])) {
            $data['db_size'] = $cached['db'];
        }
        if (isset($cached['health'])) {
            $data['health'] = $cached['health'];
        }
        if (isset($cached['performance'])) {
            $data['performance'] = $cached['performance'];
        }
        if (isset($cached['security'])) {
            $data['security'] = $cached['security'];
        }
        if (isset($cached['backups'])) {
            $data['backups'] = $cached['backups'];
        }
    }
    
    // Prepare payload (without token - it goes in header)
    $payload = [
        'domain' => $domain,
        'data' => $data,
    ];
    
    // Send to webhook
    $response = wp_remote_post($endpoint, [
        'timeout' => 30,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-API-Key' => $token,
        ],
        'body' => json_encode($payload),
    ]);
    
    // Log result
    if (is_wp_error($response)) {
        error_log('HD Telemetry: Webhook send failed - ' . $response->get_error_message());
        return false;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        error_log('HD Telemetry: Webhook sent successfully to ' . $endpoint);
        return true;
    } else {
        $body = wp_remote_retrieve_body($response);
        error_log('HD Telemetry: Webhook send failed with status ' . $code . ' - ' . $body);
        return false;
    }
}

// daily cron
add_action(HD_TELEMETRY_CRON_HOOK, 'hd_telemetry_collect_and_cache');
add_action(HD_TELEMETRY_WEBHOOK_CRON_HOOK, 'hd_telemetry_send_webhook');

// ====== LIGHT PERFORMANCE HEADER (per-request) ======
add_action('init', function () {
    $GLOBALS['__hd_t0'] = microtime(true);
    // sample DB timings 1/10 requests to limit overhead
    if (!defined('SAVEQUERIES')) define('SAVEQUERIES', mt_rand(1,10) === 1);
});
add_action('shutdown', function () {
    $t0    = $GLOBALS['__hd_t0'] ?? microtime(true);
    $phpMs = (microtime(true) - $t0) * 1000.0;
    $dbMs  = 0.0;
    if (defined('SAVEQUERIES') && SAVEQUERIES && isset($GLOBALS['wpdb']->queries)) {
        foreach ($GLOBALS['wpdb']->queries as $q) { $dbMs += ((float)$q[1] * 1000.0); }
    }
    $parts = ['app=' . number_format($phpMs, 1) . ';desc="PHP(ms)"'];
    if ($dbMs > 0) $parts[] = 'db=' . number_format($dbMs, 1) . ';desc="MySQL(ms)"';
    @header('Server-Timing: ' . implode(', ', $parts));
}, 999);

// ====== ERROR LOGGING ======
// Initialize error logger early
require_once __DIR__ . '/includes/ErrorLogger.php';
\HD_Telemetry\ErrorLogger::init();

// ====== REST API (class-based) ======
// Load controllers and register routes
require_once __DIR__ . '/includes/Inventory/Plugins.php';
require_once __DIR__ . '/includes/Inventory/Themes.php';
require_once __DIR__ . '/includes/Features/Comments.php';
require_once __DIR__ . '/includes/Features/Editor.php';
require_once __DIR__ . '/includes/Collectors/HealthCollector.php';
require_once __DIR__ . '/includes/Collectors/PerformanceCollector.php';
require_once __DIR__ . '/includes/Collectors/SecurityCollector.php';
require_once __DIR__ . '/includes/Collectors/BackupsCollector.php';
require_once __DIR__ . '/includes/System/PhpInfo.php';
require_once __DIR__ . '/includes/System/DatabaseInfo.php';
require_once __DIR__ . '/includes/System/CacheControlInfo.php';
require_once __DIR__ . '/includes/System/GridPaneInfo.php';
require_once __DIR__ . '/includes/Rest/SnapshotController.php';
require_once __DIR__ . '/includes/Rest/RecalcController.php';
require_once __DIR__ . '/includes/Rest/TokenController.php';

add_action('plugins_loaded', function () {
    $snap = new \HD_Telemetry\Rest\SnapshotController();
    $snap->register();
    $recalc = new \HD_Telemetry\Rest\RecalcController();
    $recalc->register();
    $token = new \HD_Telemetry\Rest\TokenController();
    $token->register();
});