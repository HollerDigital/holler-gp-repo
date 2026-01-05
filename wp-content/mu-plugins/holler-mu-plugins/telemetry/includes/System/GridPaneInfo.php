<?php
namespace HD_Telemetry\System;

if (!defined('ABSPATH')) exit;

class GridPaneInfo {
    public function info(): array {
        $os = php_uname('s');
        $kernel = php_uname('r');
        $machine = php_uname('m');
        $sapi = PHP_SAPI;
        $server_sw = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : null;

        $is_nginx = ($server_sw && stripos($server_sw, 'nginx') !== false) || file_exists('/etc/nginx/nginx.conf');
        $is_apache = ($server_sw && stripos($server_sw, 'apache') !== false) || file_exists('/etc/apache2/apache2.conf');

        $is_docker = file_exists('/.dockerenv');
        if (!$is_docker) {
            try {
                $cgroup = @file_get_contents('/proc/1/cgroup');
                if ($cgroup !== false) {
                    $is_docker = (stripos($cgroup, 'docker') !== false) || (stripos($cgroup, 'kubepods') !== false) || (stripos($cgroup, 'containerd') !== false);
                }
            } catch (\Throwable $e) { /* ignore */ }
        }
        $is_kubernetes = getenv('KUBERNETES_SERVICE_HOST') ? true : false;

        $distro = null; $distro_ver = null;
        try {
            if (is_readable('/etc/os-release')) {
                $lines = @file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $map = [];
                foreach ($lines as $ln) {
                    $pos = strpos($ln, '=');
                    if ($pos !== false) { $k = substr($ln, 0, $pos); $v = trim(substr($ln, $pos+1), "\"' "); $map[$k] = $v; }
                }
                $distro = $map['NAME'] ?? null;
                $distro_ver = $map['VERSION_ID'] ?? ($map['VERSION'] ?? null);
            }
        } catch (\Throwable $e) { /* ignore */ }

        $reverse_proxy = (isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']));

        // Server IPs
        $server_addr = isset($_SERVER['SERVER_ADDR']) ? (string) $_SERVER['SERVER_ADDR'] : null;
        $local_addr  = isset($_SERVER['LOCAL_ADDR']) ? (string) $_SERVER['LOCAL_ADDR'] : null; // IIS/others
        $hostname    = function_exists('gethostname') ? @gethostname() : null;
        $resolved_ips = [];
        try {
            if ($hostname && function_exists('gethostbynamel')) {
                $resolved_ips = @gethostbynamel($hostname) ?: [];
            }
        } catch (\Throwable $e) { /* ignore */ }

        $opcache_enabled = (bool) (function_exists('opcache_get_status') && opcache_get_status(false) !== false);
        $opcache_jit = null;
        if (function_exists('ini_get')) {
            $jit = ini_get('opcache.jit');
            if ($jit !== false && $jit !== '') { $opcache_jit = (string) $jit; }
        }

        $object_cache = function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : false;
        $object_cache_dropin = file_exists(WP_CONTENT_DIR . '/object-cache.php');
        $redis_extension = extension_loaded('redis');
        $memcached_extension = extension_loaded('memcached');
        $memcache_extension  = extension_loaded('memcache');

        // Infer object cache backend and common config
        $oc_backend = null;
        if ($object_cache_dropin) {
            if ($redis_extension || defined('WP_REDIS_HOST') || class_exists('Redis') || class_exists('Predis\\Client')) {
                $oc_backend = 'redis';
            } elseif ($memcached_extension || $memcache_extension) {
                $oc_backend = $memcached_extension ? 'memcached' : 'memcache';
            } elseif (defined('LSCWP_OBJECT_CACHE')) {
                $oc_backend = 'litespeed';
            } else {
                $oc_backend = 'unknown';
            }
        }
        
       
        
        // Hosting provider detection (deterministic signals only) — run after $is_gridpane is known
        $provider_name = null; $provider_source = null;
        $is_gridpane = null;
        if( defined('GRIDPANE') || is_dir('/opt/gridpane') ){
            $is_gridpane = true;
            $provider_name = 'GridPane';
            $provider_source = defined('GRIDPANE') ? 'GRIDPANE' : 'opt/gridpane/'; 
        }
        
        elseif (defined('PANTHEON_ENVIRONMENT') || getenv('PANTHEON_ENVIRONMENT')) { $provider_name = 'Pantheon'; $provider_source = 'PANTHEON_ENVIRONMENT'; }
        elseif (defined('WPE_PLUGIN_DIR') || defined('IS_WPE')) { $provider_name = 'WP Engine'; $provider_source = (defined('WPE_PLUGIN_DIR') ? 'WPE_PLUGIN_DIR' : 'IS_WPE'); }
        elseif (defined('KINSTA_CACHE') || file_exists('/www/kinsta-cache')) { $provider_name = 'Kinsta'; $provider_source = (defined('KINSTA_CACHE') ? 'KINSTA_CACHE' : '/www/kinsta-cache'); }
        elseif (is_dir('/home/master/applications')) { $provider_name = 'Cloudways'; $provider_source = '/home/master/applications'; }

        // (hosting provider detection moved below after $is_gridpane is set)
        $oc_cfg = [
            //'key_salt' => defined('WP_CACHE_KEY_SALT') ? (string) WP_CACHE_KEY_SALT : null,
            'redis' => [
                'host' => defined('WP_REDIS_HOST') ? (string) WP_REDIS_HOST : null,
                'port' => defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : null,
                'db'   => defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : null,
                'prefix' => defined('WP_REDIS_PREFIX') ? (string) WP_REDIS_PREFIX : null,
            ],
        ];

        // Page cache detection (WordPress-level)
        $wp_cache_on = (defined('WP_CACHE') && WP_CACHE);
        $advanced_cache_dropin = file_exists(WP_CONTENT_DIR . '/advanced-cache.php');
        $page_cache_provider = null;
        $page_cache_plugins = [];
        try {
            if (function_exists('is_plugin_active')) {
                if (is_plugin_active('w3-total-cache/w3-total-cache.php')) $page_cache_plugins[] = 'w3-total-cache';
                if (is_plugin_active('wp-rocket/wp-rocket.php')) $page_cache_plugins[] = 'wp-rocket';
                if (is_plugin_active('litespeed-cache/litespeed-cache.php')) $page_cache_plugins[] = 'litespeed-cache';
                if (is_plugin_active('wp-super-cache/wp-cache.php')) $page_cache_plugins[] = 'wp-super-cache';
                if (is_plugin_active('sg-cachepress/sg-cachepress.php')) $page_cache_plugins[] = 'sg-optimizer';
                if (is_plugin_active('flying-press/flying-press.php')) $page_cache_plugins[] = 'flying-press';
                if (is_plugin_active('nginx-helper/nginx-helper.php')) $page_cache_plugins[] = 'Nginx Helper';
            }
        } catch (\Throwable $e) { /* ignore */ }
        if ($advanced_cache_dropin) {
            // Heuristic by scanning a small portion of the drop-in file
            try {
                $ac = @file_get_contents(WP_CONTENT_DIR . '/advanced-cache.php', false, null, 0, 4096);
                if ($ac !== false) {
                    if (stripos($ac, 'WP Rocket') !== false) $page_cache_provider = 'wp-rocket';
                    elseif (stripos($ac, 'Nginx Helper') !== false) $page_cache_provider = 'nginx-helper';   
                    elseif (stripos($ac, 'W3 Total Cache') !== false || stripos($ac, 'W3TC') !== false) $page_cache_provider = 'w3-total-cache';
                    elseif (stripos($ac, 'LiteSpeed') !== false) $page_cache_provider = 'litespeed-cache';
                    elseif (stripos($ac, 'WP Super Cache') !== false) $page_cache_provider = 'wp-super-cache';
                    elseif (stripos($ac, 'Batcache') !== false) $page_cache_provider = 'batcache';
                 
                }
            } catch (\Throwable $e) { /* ignore */ }
        }

        $db = [ 'type' => 'mysql', 'version' => null, 'driver' => null ];
        try {
            global $wpdb;
            if (isset($wpdb)) {
                if (method_exists($wpdb, 'db_version')) { $db['version'] = (string) $wpdb->db_version(); }
                $db['driver'] = defined('USE_EXT_MYSQL') && USE_EXT_MYSQL ? 'ext/mysql' : ((isset($wpdb->use_mysqli) && $wpdb->use_mysqli) ? 'mysqli' : 'unknown');
            }
        } catch (\Throwable $e) { /* ignore */ }

        $is_gridpane = null;
        if(  is_dir('/opt/gridpane') ){
               $is_gridpane = true;
        }else{
            $is_gridpane = false;
        }
        
        // (defined('GRIDPANE')) || is_dir('/opt/gridpane');
        // // $gridpane_ip = null;
        // // if ($is_gridpane) {
        // //     // Attempt to read an IP from GridPane config files
        // //     $gp_files = [ '/opt/gridpane/gridpane.conf' ];
        // //     foreach ($gp_files as $gpfile) {
        // //         if (is_readable($gpfile)) {
        // //             try {
        // //                 $txt = @file_get_contents($gpfile, false, null, 0, 8192);
        // //                 if ($txt !== false) {
        // //                     if (preg_match('/\\b(\d{1,3}\\.){3}\d{1,3}\\b/', $txt, $m)) { $gridpane_ip = $m[0]; break; }
        // //                 }
        // //             } catch (\Throwable $e) { /* ignore */ }
        // //         }
        // //     }
        // // }

        // Logs: keep concise set; infer details from site logs directory
        $logs = [
            'php' => [ 'error_log' => null ],
            'wordpress' => [ 'debug_log' => null ],
            'site' => [ 'dir' => null, 'files' => null ],
        ];
        // PHP error_log from ini
        try {
            $elog = function_exists('ini_get') ? ini_get('error_log') : '';
            if (is_string($elog) && $elog !== '') { $logs['php']['error_log'] = $elog; }
        } catch (\Throwable $e) { /* ignore */ }
        // WordPress debug.log
        try {
            $wp_debug_log = defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : null;
            if (is_string($wp_debug_log) && $wp_debug_log !== '') {
                $logs['wordpress']['debug_log'] = $wp_debug_log;
            } else {
                $default_debug = WP_CONTENT_DIR . '/debug.log';
                if (file_exists($default_debug)) { $logs['wordpress']['debug_log'] = $default_debug; }
            }
        } catch (\Throwable $e) { /* ignore */ }
        // Nginx
        if (file_exists('/var/log/nginx/access.log')) { $logs['nginx']['access'] = '/var/log/nginx/access.log'; }
        if (file_exists('/var/log/nginx/error.log'))  { $logs['nginx']['error']  = '/var/log/nginx/error.log'; }
        // Apache
        if (file_exists('/var/log/apache2/access.log')) { $logs['apache']['access'] = '/var/log/apache2/access.log'; }
        if (file_exists('/var/log/apache2/error.log'))  { $logs['apache']['error']  = '/var/log/apache2/error.log'; }
        // Database
        if (file_exists('/var/log/mysql/error.log'))    { $logs['database']['mysql']   = '/var/log/mysql/error.log'; }
        if (file_exists('/var/log/mariadb/mariadb.log')){ $logs['database']['mariadb'] = '/var/log/mariadb/mariadb.log'; }
        // Redis
        if (file_exists('/var/log/redis/redis-server.log')) { $logs['redis']['server'] = '/var/log/redis/redis-server.log'; }

        // GridPane per-site logs: derive from ABSPATH => /root/www/<Site>/htdocs => /root/www/<Site>/logs
        try {
            $wp_root = rtrim(ABSPATH, '/');
            $site_root = dirname($wp_root); // .../htdocs -> .../<Site>
            $site_logs = $site_root . '/logs';
            if (is_dir($site_logs)) {
                $logs['site']['dir'] = $site_logs;
                // Shallow list of log files (no recursion)
                $files = @scandir($site_logs) ?: [];
                if ($files) {
                    $list = [];
                    foreach ($files as $f) {
                        if ($f === '.' || $f === '..') continue;
                        $full = $site_logs . '/' . $f;
                        if (is_file($full)) { $list[] = $f; }
                    }
                    $logs['site']['files'] = $list;
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        // Monit summary (no commands; file-based detection)
        $monit = [
            'installed' => false,
            'enabled' => false,
            'config' => [ 'monitrc' => null, 'conf_dirs' => [] ],
            'services' => [],
        ];
        try {
            $monitrc = '/etc/monit/monitrc';
            $conf_dirs = [ '/etc/monit/conf-enabled', '/etc/monit/conf.d', '/opt/gridpane/monit-settings' ];
            $pidfiles = [ '/run/monit.pid', '/var/run/monit.pid' ];
            if (file_exists($monitrc) || is_executable('/usr/bin/monit') || is_executable('/bin/monit')) {
                $monit['installed'] = true;
                if (file_exists($monitrc)) { $monit['config']['monitrc'] = $monitrc; }
                foreach ($pidfiles as $pf) { if (file_exists($pf)) { $monit['enabled'] = true; break; } }
                foreach ($conf_dirs as $d) { if (is_dir($d)) { $monit['config']['conf_dirs'][] = $d; } }
                // Parse service names from conf files
                $site_name = basename(dirname(rtrim(ABSPATH, '/')));
                $seen = [];
                foreach ($monit['config']['conf_dirs'] as $d) {
                    $entries = @scandir($d) ?: [];
                    foreach ($entries as $fn) {
                        if ($fn === '.' || $fn === '..') continue;
                        $path = rtrim($d, '/') . '/' . $fn;
                        if (!is_file($path) || !is_readable($path)) continue;
                        $txt = @file_get_contents($path, false, null, 0, 8192);
                        if ($txt === false) continue;
                        if (preg_match_all('/^\s*check\s+(process|program|file|filesystem)\s+"?([^"\n]+)"?/mi', $txt, $mm)) {
                            foreach ($mm[2] as $name) {
                                $name = trim($name);
                                if ($name === '' || isset($seen[$name])) continue;
                                // Prefer site-related names
                                $is_site = (stripos($name, $site_name) !== false) || preg_match('/php(-?fpm)?|nginx|redis|mysql|mariadb/i', $name);
                                $monit['services'][] = [ 'name' => $name, 'site_related' => (bool)$is_site, 'source' => $path ];
                                $seen[$name] = true;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        // PHP extensions snapshot (selected)
        $php_extensions = [];
        try {
            $php_extensions = [
                'curl' => extension_loaded('curl'),
                'curl_version' => function_exists('curl_version') ? (string) (curl_version()['version'] ?? null) : null,
                'imagick' => class_exists('Imagick'),
                'gd' => extension_loaded('gd'),
                'intl' => extension_loaded('intl'),
                'mbstring' => extension_loaded('mbstring'),
                'zip' => extension_loaded('zip'),
                'openssl' => defined('OPENSSL_VERSION_TEXT') ? (string) OPENSSL_VERSION_TEXT : null,
                'redis' => $redis_extension,
                'memcached' => $memcached_extension,
                'memcache' => $memcache_extension,
            ];
        } catch (\Throwable $e) { /* ignore */ }

        // Time info
        $time_info = [ 'timezone' => null, 'now' => null, 'unix' => null ];
        try {
            $tz = function_exists('date_default_timezone_get') ? @date_default_timezone_get() : null;
            if (!$tz || $tz === 'UTC') {
                $ini_tz = function_exists('ini_get') ? ini_get('date.timezone') : '';
                if ($ini_tz) $tz = $ini_tz;
            }
            $time_info['timezone'] = $tz ?: 'UTC';
            $time_info['now'] = gmdate('c');
            $time_info['unix'] = time();
        } catch (\Throwable $e) { /* ignore */ }

        // SSL hints (paths only)
        $ssl = [ 'cert_path' => null, 'key_path' => null, 'managed_by' => null ];
        try {
            $domain = null;
            if (function_exists('home_url')) { $domain = parse_url(home_url(), PHP_URL_HOST); }
            if (!$domain && isset($_SERVER['SERVER_NAME'])) { $domain = $_SERVER['SERVER_NAME']; }
            if (is_string($domain) && $domain !== '') {
                $le_dir = '/etc/letsencrypt/live/' . $domain;
                $cert_candidates = [ $le_dir . '/fullchain.pem', $le_dir . '/cert.pem' ];
                $key_candidates  = [ $le_dir . '/privkey.pem', $le_dir . '/key.pem' ];
                foreach ($cert_candidates as $p) { if (file_exists($p)) { $ssl['cert_path'] = $p; break; } }
                foreach ($key_candidates as $p)  { if (file_exists($p)) { $ssl['key_path'] = $p; break; } }
                if ($ssl['cert_path'] && strpos($ssl['cert_path'], '/etc/letsencrypt/') === 0) { $ssl['managed_by'] = 'letsencrypt'; }
            }
        } catch (\Throwable $e) { /* ignore */ }

        // Disk inode snapshot
        $disk_inodes = [ 'nr_inodes' => null, 'nr_free_inodes' => null ];
        try {
            $inode_nr = '/proc/sys/fs/inode-nr';
            if (is_readable($inode_nr)) {
                $txt = @file_get_contents($inode_nr);
                if (is_string($txt)) {
                    $parts = preg_split('/\s+/', trim($txt));
                    if (count($parts) >= 2) { $disk_inodes['nr_inodes'] = (int)$parts[0]; $disk_inodes['nr_free_inodes'] = (int)$parts[1]; }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        // Object cache health snapshot (no network)
        $oc_health = [ 'ping_supported' => false ];
        try {
            if ($oc_backend === 'redis' && (class_exists('Redis') || class_exists('Predis\\Client'))) { $oc_health['ping_supported'] = true; }
            if (($oc_backend === 'memcached' || $oc_backend === 'memcache') && (class_exists('Memcached') || class_exists('Memcache'))) { $oc_health['ping_supported'] = true; }
        } catch (\Throwable $e) { /* ignore */ }

        // Calculate folder sizes
        $disk_usage = [ 'htdocs_mb' => null, 'plugins_mb' => null, 'themes_mb' => null, 'uploads_mb' => null ];
        try {
            if (function_exists('hd_telemetry_size_bytes')) {
                // htdocs folder
                $htdocs_path = rtrim(ABSPATH, '/');
                $htdocs_bytes = \hd_telemetry_size_bytes($htdocs_path);
                $disk_usage['htdocs_mb'] = round($htdocs_bytes / 1048576, 2);
                
                // plugins folder
                if (defined('WP_PLUGIN_DIR') && is_dir(WP_PLUGIN_DIR)) {
                    $plugins_bytes = \hd_telemetry_size_bytes(WP_PLUGIN_DIR);
                    $disk_usage['plugins_mb'] = round($plugins_bytes / 1048576, 2);
                }
                
                // themes folder
                if (defined('WP_CONTENT_DIR')) {
                    $themes_path = WP_CONTENT_DIR . '/themes';
                    if (is_dir($themes_path)) {
                        $themes_bytes = \hd_telemetry_size_bytes($themes_path);
                        $disk_usage['themes_mb'] = round($themes_bytes / 1048576, 2);
                    }
                }
                
                // uploads folder
                if (defined('WP_CONTENT_DIR')) {
                    $uploads_path = WP_CONTENT_DIR . '/uploads';
                    if (is_dir($uploads_path)) {
                        $uploads_bytes = \hd_telemetry_size_bytes($uploads_path);
                        $disk_usage['uploads_mb'] = round($uploads_bytes / 1048576, 2);
                    }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        return [
            'hosting' => [
                'provider' => $provider_name,
                'source' => $provider_source,
                'environment' => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : null,
                // 'ip' => $gridpane_ip,
                'network' => [
                    'server_addr' => $server_addr,
                    'local_addr' => $local_addr,
                    'hostname' => $hostname,
                    'resolved_ips' => $resolved_ips,
                ],
                'monit' => $monit,
            ],
            'os' => $os,
            'kernel' => $kernel,
            'machine' => $machine,
            'php_sapi' => $sapi,
            'server_software' => $server_sw,
            'is_nginx' => $is_nginx,
            'is_apache' => $is_apache,
            'reverse_proxy' => $reverse_proxy,
            'container' => [ 'docker' => $is_docker, 'kubernetes' => $is_kubernetes ],
            'distro' => [ 'name' => $distro, 'version' => $distro_ver ],
            'opcache' => [ 'enabled' => $opcache_enabled, 'jit' => $opcache_jit ],
            'php_extensions' => $php_extensions,
            'time' => $time_info,
            'ssl' => $ssl,
            'disk_inodes' => $disk_inodes,
            'disk_usage' => $disk_usage,
            'object_cache' => [
                'enabled' => $object_cache,
                'dropin' => $object_cache_dropin,
                'backend' => $oc_backend,
                'redis_extension' => $redis_extension,
                'memcached_extension' => $memcached_extension,
                'memcache_extension' => $memcache_extension,
                'config' => $oc_cfg,
                'health' => $oc_health,
            ],
            'page_cache' => [
                'wp_cache_constant' => $wp_cache_on,
                'advanced_cache_dropin' => $advanced_cache_dropin,
                'provider' => $page_cache_provider,
                'plugins' => $page_cache_plugins,
            ],
            'paths' => [
                'wordpress' => rtrim(ABSPATH, '/'),
                'content'   => defined('WP_CONTENT_DIR') ? rtrim(WP_CONTENT_DIR, '/') : null,
                'plugins'   => defined('WP_PLUGIN_DIR') ? rtrim(WP_PLUGIN_DIR, '/') : null,
                'mu_plugins'=> defined('WPMU_PLUGIN_DIR') ? rtrim(WPMU_PLUGIN_DIR, '/') : null,
            ],
            'logs' => $logs,
        ];
    }
}
