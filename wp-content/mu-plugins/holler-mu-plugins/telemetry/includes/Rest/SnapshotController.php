<?php
namespace HD_Telemetry\Rest;
use HD_Telemetry\Inventory\Plugins as PluginsInventory;
use HD_Telemetry\Inventory\Themes as ThemesInventory;
use HD_Telemetry\Features\Comments as CommentsFeature;
use HD_Telemetry\Features\Editor as EditorFeature;
use HD_Telemetry\System\PhpInfo as PhpInfo;
use HD_Telemetry\System\DatabaseInfo as DatabaseInfo;
use HD_Telemetry\System\GridPaneInfo as GridPaneInfo;
use HD_Telemetry\System\CacheControlInfo as CacheControlInfo;

if (!defined('ABSPATH')) exit;

class SnapshotController {
    public function register() {
        add_action('rest_api_init', function () {
            register_rest_route('hd/v1', '/snapshot', [
                'methods'  => 'GET',
                'permission_callback' => function ($req) { return \hd_telemetry_auth_ok($req); },
                'callback' => [$this, 'handle'],
            ]);
        });
    }

    public function handle($req) {
        // Authorization is enforced via permission_callback

        // read cached; refresh in background if stale (>24h)
        $data = get_option(HD_TELEMETRY_OPTION_KEY);
        $stale = true;
        if (is_array($data) && !empty($data['calculated_at'])) {
            $age = time() - strtotime($data['calculated_at']);
            $stale = ($age > HD_TELEMETRY_CACHE_TTL_S);
        }
        if (!$data) {
            $data = \hd_telemetry_collect_and_cache() ?: null;
            if (is_array($data) && !empty($data['calculated_at'])) $stale = false;
        }
        if (is_array($data)) {
            $needs_enrich = (!isset($data['health']) || !isset($data['performance']) || !isset($data['security']) || !isset($data['backups']));
            if ($needs_enrich) {
                $fresh = \hd_telemetry_collect_and_cache();
                if (is_array($fresh)) { $data = $fresh; $stale = false; }
            }
        }
        if ($stale && !wp_next_scheduled(HD_TELEMETRY_CRON_HOOK)) {
            wp_schedule_single_event(time() + 10, HD_TELEMETRY_CRON_HOOK);
        }

        $pluginsInv = new PluginsInventory();
        $plugins = $pluginsInv->all();
        $this_plugin_version = $pluginsInv->thisPluginVersion();
        $themesInv = new ThemesInventory();
        $themes = $themesInv->activeAndParent();

        // Feature statuses
        $commentsFeature = new CommentsFeature();
        $editorFeature   = new EditorFeature();
        $features = [
            'comments' => $commentsFeature->status(),
            'editor'   => $editorFeature->status(),
            'search_engines' => [
                'disabled' => (function_exists('get_option') ? (get_option('blog_public') === '0') : null),
            ],
        ];

        // System info
        $phpInfo = (new PhpInfo())->info();
        $dbInfo  = (new DatabaseInfo())->info();

        // Determine installed WordPress version: prefer core's $wp_version, then Updates API, then bloginfo
        $installed_ver = null;
        $last_checked_iso = null;
        $available_ver = null;
        try {
            if (file_exists(ABSPATH . 'wp-admin/includes/update.php')) {
                require_once ABSPATH . 'wp-admin/includes/update.php';
            }
            $uc = get_site_transient('update_core');
            if (is_object($uc) && isset($uc->last_checked)) {
                $last_checked_iso = gmdate('c', (int) $uc->last_checked);
            }
            if (is_object($uc) && isset($uc->version_checked)) {
                $installed_ver = (string) $uc->version_checked;
            }
            if (function_exists('get_core_updates')) {
                $updates = get_core_updates();
                if (is_array($updates) && isset($updates[0]) && isset($updates[0]->current)) {
                    $available_ver = (string) $updates[0]->current;
                }
            }
            // If missing, try to populate updates transient (non-blocking best-effort)
            if ((!$installed_ver || !$last_checked_iso) && function_exists('wp_version_check')) {
                @wp_version_check();
                $uc2 = get_site_transient('update_core');
                if (is_object($uc2)) {
                    if (!$installed_ver && isset($uc2->version_checked)) {
                        $installed_ver = (string) $uc2->version_checked;
                    }
                    if (!$last_checked_iso && isset($uc2->last_checked)) {
                        $last_checked_iso = gmdate('c', (int) $uc2->last_checked);
                    }
                }
                if (!$available_ver && function_exists('get_core_updates')) {
                    $updates = get_core_updates();
                    if (is_array($updates) && isset($updates[0]) && isset($updates[0]->current)) {
                        $available_ver = (string) $updates[0]->current;
                    }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        // Prefer the canonical $wp_version defined by core
        $ver =  get_bloginfo('version');
        // if (defined('HD_TELEMETRY_WP_VERSION')) {
        //     $ver = HD_TELEMETRY_WP_VERSION;
        // } else {
        //     global $wp_version;
        //     if (empty($wp_version)) {
        //         // Load version file directly if global not set
        //         $ver_file = ABSPATH . WPINC . '/version.php';
        //         if (file_exists($ver_file)) {
        //             require $ver_file; // populates $wp_version
        //         }
        //     }
        //     if (!empty($wp_version)) {
        //         $ver = (string) $wp_version;
        //     } elseif (!empty($installed_ver)) {
        //         $ver = (string) $installed_ver;
        //     } else {
        //         $ver = (string) get_bloginfo('version');
        //     }
        // }

        $vp  = array_map('intval', explode('.', (string)$ver));
        $wordpress = [
            'version' => $ver,
            'version_label' => 'WordPress ' . $ver . ' Release',
            'version_major' => $vp[0] ?? null,
            'version_minor' => $vp[1] ?? null,
            'version_patch' => $vp[2] ?? null,
            'release_date' => defined('HD_TELEMETRY_WP_RELEASE_DATE') ? HD_TELEMETRY_WP_RELEASE_DATE : null,
            'updates_last_checked' => $last_checked_iso,
            'available_version' => $available_ver,
        ];

        // WordPress cron summary
        try {
            if (file_exists(ABSPATH . 'wp-includes/cron.php')) { require_once ABSPATH . 'wp-includes/cron.php'; }
        } catch (\Throwable $e) { /* ignore */ }
        $cron_summary = [ 'enabled' => (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON), 'scheduled_events' => null, 'overdue_events' => null ];
        try {
            if (function_exists('_get_cron_array')) {
                $events = _get_cron_array();
                if (is_array($events)) {
                    $total = 0; $overdue = 0; $now = time();
                    foreach ($events as $ts => $hooks) {
                        $count = 0;
                        if (is_array($hooks)) {
                            foreach ($hooks as $hook => $instances) { if (is_array($instances)) { $count += count($instances); } }
                        }
                        $total += $count; if ((int)$ts < $now) { $overdue += $count; }
                    }
                    $cron_summary['scheduled_events'] = $total;
                    $cron_summary['overdue_events'] = $overdue;
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        // WordPress important constants (non-secret)
        $wp_constants = [
            'WP_DEBUG' => defined('WP_DEBUG') ? (bool) WP_DEBUG : null,
            'WP_DISABLE_FATAL_ERROR_HANDLER' => defined('WP_DISABLE_FATAL_ERROR_HANDLER') ? (bool) WP_DISABLE_FATAL_ERROR_HANDLER : null,
            'AUTOSAVE_INTERVAL' => defined('AUTOSAVE_INTERVAL') ? (int) AUTOSAVE_INTERVAL : null,
            'EMPTY_TRASH_DAYS' => defined('EMPTY_TRASH_DAYS') ? (int) EMPTY_TRASH_DAYS : null,
        ];
        $wordpress['cron'] = $cron_summary;
        $wordpress['constants'] = $wp_constants;

        // Build sizes subset only (avoid duplicating other cached sections)
        $sizesSubset = null;
        if (is_array($data)) {
            $sizesSubset = [
                'calculated_at' => $data['calculated_at'] ?? null,
                'disk'          => $data['disk'] ?? null,
                'db'            => $data['db'] ?? null,
            ];
        }

        // Compute server info and move site paths under 'site'
        $serverInfo = (new GridPaneInfo())->info();
        $sitePaths = is_array($serverInfo) && array_key_exists('paths', $serverInfo) ? $serverInfo['paths'] : null;
        if (is_array($serverInfo) && array_key_exists('paths', $serverInfo)) { unset($serverInfo['paths']); }

        // Summaries for themes/plugins auto-updates
        $themes_summary = null;
        try {
            $t_total = 0; $t_auto = 0;
            if (is_array($themes)) {
                foreach (['active','child','parent'] as $k) {
                    if (!empty($themes[$k]) && is_array($themes[$k])) {
                        $t_total += 1; if (!empty($themes[$k]['auto_update'])) { $t_auto += 1; }
                    }
                }
            }
            $themes_summary = [ 'total' => $t_total, 'auto_update_on' => $t_auto ];
        } catch (\Throwable $e) { /* ignore */ }

        $plugins_summary = null;
        try {
            $p_total = is_array($plugins) ? count($plugins) : 0;
            $p_auto = 0; if ($p_total > 0) { foreach ($plugins as $pl) { if (!empty($pl['auto_update'])) { $p_auto += 1; } } }
            $plugins_summary = [ 'total' => $p_total, 'auto_update_on' => $p_auto ];
        } catch (\Throwable $e) { /* ignore */ }

        $resp = [
            'schema_version' => '0.2',
            'plugin' => [ 'name' => 'HD Telemetry Lite', 'version' => $this_plugin_version ],
            'site' => [ 'url' => get_site_url(), 'name' => get_bloginfo('name'), 'paths' => $sitePaths ],
            'wordpress' => $wordpress,
            'php' => $phpInfo,
            'database' => $dbInfo,
            'server' => $serverInfo,
            'themes' => $themes,
            'plugins' => $plugins,
            'themes_summary' => $themes_summary,
            'plugins_summary' => $plugins_summary,
            'sizes'   => $sizesSubset,
            'health' => isset($data['health']) ? $data['health'] : null,
            'performance' => isset($data['performance']) ? $data['performance'] : null,
            'security' => isset($data['security']) ? $data['security'] : null,
            'backups' => isset($data['backups']) ? $data['backups'] : null,
            'features' => $features,
            'now' => gmdate('c'),
        ];

        // Integrate definitive data from Holler Cache Control plugin via its own info class
        $resp['cache_control'] = (new CacheControlInfo())->info();
        return new \WP_REST_Response($resp, 200);
    }
}
