<?php

if (!defined('ABSPATH')) exit;

class MyApp_WP_SSO {
    const OPTION_KEY = 'holler_sso_settings';
    const NONCE_KEY = 'holler_sso_settings_nonce';

    public function register() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_post']);
    }

    private function ct_equals($a, $b) {
        $a = (string) $a;
        $b = (string) $b;
        return hash_equals($a, $b);
    }

    private function is_eligible_login_request($req) {
        // Keep the request surface area small.
        if (defined('WP_CLI') && WP_CLI) return false;
        if (defined('DOING_AJAX') && DOING_AJAX) return false;
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (defined('WP_INSTALLING') && WP_INSTALLING) return false;
        if (is_admin()) return false;
        if (!is_ssl()) return false;

        $method = strtoupper((string) $req->get_method());
        if ($method !== 'GET') return false;

        // Only allow a single query param: token
        $qp = $req->get_query_params();
        if (is_array($qp)) {
            foreach ($qp as $k => $_v) {
                if ($k !== 'token') return false;
            }
        }

        // No body params for this endpoint.
        $bp = $req->get_body_params();
        if (is_array($bp) && !empty($bp)) return false;

        return true;
    }

    public function register_routes() {
        register_rest_route('myapp-sso/v1', '/login', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_login'],
        ]);
    }

    private function get_settings() {
        $defaults = [
            'enabled' => false,
            'app_base_url' => '',
            'site_id' => '',
            'issuer' => '',
            'audience' => '',
            'secret_active' => '',
            'secret_previous' => '',
            'allowed_redirect_paths' => "/wp-admin/\n/wp-admin",
            'require_manage_options' => false,
            'require_redeem' => true,
            'rate_limit_max' => 10,
            'rate_limit_window' => 300,
        ];
        $raw = get_option(self::OPTION_KEY);
        if (!is_array($raw)) $raw = [];

        $merged = array_merge($defaults, $raw);

        // Allow defining sensitive settings as constants (e.g. in a user-configs.php outside webroot).
        // Constants override option values and prevent secrets from being stored in the WP DB.
        // Prefer HOLLER_* constants to match existing convention.
        if (defined('HOLLER_API_URL')) $merged['app_base_url'] = (string) HOLLER_API_URL;
        if (defined('HOLLER_API_SITE_ID')) $merged['site_id'] = (string) HOLLER_API_SITE_ID;
        if (defined('HOLLER_API_SSO_SECRET_ACTIVE')) $merged['secret_active'] = (string) HOLLER_API_SSO_SECRET_ACTIVE;
        if (defined('HOLLER_API_SSO_SECRET_PREVIOUS')) $merged['secret_previous'] = (string) HOLLER_API_SSO_SECRET_PREVIOUS;

        // Backwards-compatible MYAPP_* constants (override HOLLER_* when present).
        if (defined('MYAPP_WP_SSO_ENABLED')) $merged['enabled'] = (bool) MYAPP_WP_SSO_ENABLED;
        if (defined('MYAPP_WP_SSO_APP_BASE_URL')) $merged['app_base_url'] = (string) MYAPP_WP_SSO_APP_BASE_URL;
        if (defined('MYAPP_WP_SSO_SITE_ID')) $merged['site_id'] = (string) MYAPP_WP_SSO_SITE_ID;
        if (defined('MYAPP_WP_SSO_ISSUER')) $merged['issuer'] = (string) MYAPP_WP_SSO_ISSUER;
        if (defined('MYAPP_WP_SSO_AUDIENCE')) $merged['audience'] = (string) MYAPP_WP_SSO_AUDIENCE;
        if (defined('MYAPP_WP_SSO_SECRET_ACTIVE')) $merged['secret_active'] = (string) MYAPP_WP_SSO_SECRET_ACTIVE;
        if (defined('MYAPP_WP_SSO_SECRET_PREVIOUS')) $merged['secret_previous'] = (string) MYAPP_WP_SSO_SECRET_PREVIOUS;
        if (defined('MYAPP_WP_SSO_ALLOWED_REDIRECT_PATHS')) $merged['allowed_redirect_paths'] = (string) MYAPP_WP_SSO_ALLOWED_REDIRECT_PATHS;
        if (defined('MYAPP_WP_SSO_REQUIRE_MANAGE_OPTIONS')) $merged['require_manage_options'] = (bool) MYAPP_WP_SSO_REQUIRE_MANAGE_OPTIONS;
        if (defined('MYAPP_WP_SSO_REQUIRE_REDEEM')) $merged['require_redeem'] = (bool) MYAPP_WP_SSO_REQUIRE_REDEEM;

        return $merged;
    }

    private function log_event($level, $message, $context = []) {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) return;
        $safe = [];
        if (is_array($context)) {
            foreach ($context as $k => $v) {
                if ($k === 'token' || $k === 'secret' || $k === 'jwt') continue;
                $safe[$k] = $v;
            }
        }
        error_log('[Holler SSO] ' . strtoupper($level) . ' ' . $message . (empty($safe) ? '' : ' ' . wp_json_encode($safe)));
    }

    private function base64url_decode($input) {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        $input = strtr($input, '-_', '+/');
        return base64_decode($input);
    }

    private function verify_jwt_hs256($token, $secret) {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3) return [false, null];
        list($h, $p, $s) = $parts;

        $header_raw = $this->base64url_decode($h);
        $payload_raw = $this->base64url_decode($p);
        $sig_raw = $this->base64url_decode($s);
        if ($header_raw === false || $payload_raw === false || $sig_raw === false) return [false, null];

        $header = json_decode($header_raw, true);
        $payload = json_decode($payload_raw, true);
        if (!is_array($header) || !is_array($payload)) return [false, null];
        if (($header['alg'] ?? '') !== 'HS256') return [false, null];

        $expected = hash_hmac('sha256', $h . '.' . $p, $secret, true);
        if (!hash_equals($expected, $sig_raw)) return [false, null];

        return [true, $payload];
    }

    private function get_client_ip() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return preg_replace('/[^0-9a-fA-F:\\.]/', '', (string) $ip);
    }

    private function rate_limit_fail() {
        $settings = $this->get_settings();
        $ip = $this->get_client_ip();
        $key = 'holler_sso_rl_' . md5($ip ?: 'unknown');
        $count = (int) get_transient($key);
        $count++;
        set_transient($key, $count, max(1, (int) $settings['rate_limit_window']));
        return $count;
    }

    private function is_rate_limited() {
        $settings = $this->get_settings();
        $ip = $this->get_client_ip();
        $key = 'holler_sso_rl_' . md5($ip ?: 'unknown');
        $count = (int) get_transient($key);
        return $count >= (int) $settings['rate_limit_max'];
    }

    private function parse_allowed_paths($raw) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $out = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if ($line[0] !== '/') continue;
                if (strpos($line, '//') === 0) continue;
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    private function sanitize_return_path($requested, $allowed) {
        $requested = (string) $requested;
        if ($requested === '' || $requested[0] !== '/' || strpos($requested, '//') === 0) {
            return '/wp-admin/';
        }
        if (empty($allowed)) return $requested;
        foreach ($allowed as $p) {
            if ($requested === $p || strpos($requested, rtrim($p, '/') . '/') === 0) {
                return $requested;
            }
        }
        return '/wp-admin/';
    }

    private function reject($code, $message, $status = 400, $ctx = []) {
        $this->log_event('warn', $message, $ctx);
        $this->rate_limit_fail();
        return new WP_REST_Response(['error' => $code, 'message' => $message], $status);
    }

    private function redeem_with_app($token) {
        $settings = $this->get_settings();
        $base = trim((string) $settings['app_base_url']);
        if ($base === '') return [false, 'app_base_url_not_configured'];
        $base = rtrim($base, '/');

        $url = $base . '/api/wp-sso/redeem';
        $headers = [ 'Content-Type' => 'application/json' ];
        if (defined('HOLLER_API_KEY') && HOLLER_API_KEY) {
            $headers['X-API-Key'] = (string) HOLLER_API_KEY;
        }
        $args = [
            'timeout' => 3,
            'headers' => $headers,
            'body' => wp_json_encode([ 'token' => (string) $token ]),
        ];

        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)) {
            return [false, 'request_failed'];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            return [false, 'redeem_rejected'];
        }
        return [true, null];
    }

    public function handle_login($req) {
        $settings = $this->get_settings();

        if (!$this->is_eligible_login_request($req)) {
            return $this->reject('ineligible_request', 'Ineligible request', 400);
        }

        if (!$settings['enabled']) {
            return $this->reject('disabled', 'SSO is disabled', 403);
        }

        if ($this->is_rate_limited()) {
            return $this->reject('rate_limited', 'Too many failed attempts', 429);
        }

        $token = (string) $req->get_param('token');
        if ($token === '') {
            return $this->reject('missing_token', 'Missing token', 400);
        }

        $secrets = [];
        if (!empty($settings['secret_active'])) $secrets[] = (string) $settings['secret_active'];
        if (!empty($settings['secret_previous'])) $secrets[] = (string) $settings['secret_previous'];
        if (empty($secrets)) {
            return $this->reject('not_configured', 'SSO secret not configured', 500);
        }

        $payload = null;
        $verified = false;
        foreach ($secrets as $sec) {
            list($ok, $pl) = $this->verify_jwt_hs256($token, $sec);
            if ($ok) {
                $verified = true;
                $payload = $pl;
                break;
            }
        }

        if (!$verified || !is_array($payload)) {
            return $this->reject('invalid_token', 'Invalid token', 401);
        }

        // Burn JTI immediately after signature verification (even if later checks fail),
        // but only if we have a JTI (prevents DoS with random junk tokens).
        $jti = (string) ($payload['jti'] ?? '');
        if ($jti !== '') {
            $jti_key = 'holler_sso_jti_' . md5((string) $settings['site_id'] . '|' . $jti);
            if (get_transient($jti_key)) {
                return $this->reject('replay', 'Token replay detected', 401);
            }

            $now = time();
            $skew = 60;
            $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
            $ttl = 600;
            if ($exp > 0) {
                $ttl = max(1, $exp - $now + $skew);
            }
            set_transient($jti_key, 1, $ttl);
        } else {
            return $this->reject('invalid_jti', 'Missing jti', 401);
        }

        $now = time();
        $iat = isset($payload['iat']) ? (int) $payload['iat'] : 0;
        $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        $skew = 60;
        if ($iat <= 0 || $exp <= 0) {
            return $this->reject('invalid_claims', 'Missing iat/exp', 401);
        }
        if ($iat > ($now + $skew)) {
            return $this->reject('invalid_claims', 'iat is in the future', 401);
        }
        if ($exp < ($now - $skew)) {
            return $this->reject('expired', 'Token expired', 401);
        }

        if (!$this->ct_equals(($payload['iss'] ?? ''), (string) $settings['issuer'])) {
            return $this->reject('invalid_iss', 'Issuer mismatch', 401);
        }
        if (!$this->ct_equals(($payload['aud'] ?? ''), (string) $settings['audience'])) {
            return $this->reject('invalid_aud', 'Audience mismatch', 401);
        }
        if (!$this->ct_equals(($payload['sid'] ?? ''), (string) $settings['site_id'])) {
            return $this->reject('invalid_sid', 'Site ID mismatch', 401);
        }

        // Host binding: if your configured audience looks like a hostname, require it matches this WP site.
        $aud_cfg = strtolower(trim((string) $settings['audience']));
        $site_host = strtolower(trim((string) parse_url(home_url(), PHP_URL_HOST)));
        if ($aud_cfg !== '' && $site_host !== '' && strpos($aud_cfg, '.') !== false) {
            if (!$this->ct_equals($aud_cfg, $site_host)) {
                return $this->reject('host_mismatch', 'Site host mismatch', 401, ['host' => $site_host]);
            }
        }

        // Server-to-server redemption callback (strong replay protection)
        if (!empty($settings['require_redeem'])) {
            list($redeemed_ok, $redeem_err) = $this->redeem_with_app($token);
            if (!$redeemed_ok) {
                return $this->reject('redeem_failed', 'Failed to redeem token', 401, ['reason' => $redeem_err]);
            }
        }

        $sub = strtolower(trim((string) ($payload['sub'] ?? '')));
        if ($sub === '') {
            return $this->reject('invalid_sub', 'Missing sub', 401);
        }

        $user = get_user_by('email', $sub);
        if (!$user || !($user instanceof WP_User)) {
            return $this->reject('no_user', 'No matching WP user', 403, ['sub' => $sub]);
        }

        if (!user_can($user, 'read')) {
            return $this->reject('forbidden', 'User lacks read capability', 403, ['user_id' => $user->ID]);
        }
        if (!empty($settings['require_manage_options']) && !user_can($user, 'manage_options')) {
            return $this->reject('forbidden', 'User lacks manage_options capability', 403, ['user_id' => $user->ID]);
        }

        $allowed = $this->parse_allowed_paths($settings['allowed_redirect_paths']);
        $return_path = $this->sanitize_return_path($payload['rp'] ?? '/wp-admin/', $allowed);

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true, true);
        do_action('wp_login', $user->user_login, $user);

        $this->log_event('info', 'SSO login success', ['user_id' => $user->ID, 'rp' => $return_path]);
        wp_safe_redirect($return_path);
        exit;
    }

    public function register_admin_menu() {
        add_options_page(
            'Holler SSO',
            'Holler SSO',
            'manage_options',
            'holler-sso',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->get_settings();
        $nonce = wp_create_nonce(self::NONCE_KEY);
        $locked = [
            'app_base_url' => defined('MYAPP_WP_SSO_APP_BASE_URL') ? 'MYAPP_WP_SSO_APP_BASE_URL' : (defined('HOLLER_API_URL') ? 'HOLLER_API_URL' : null),
            'site_id' => defined('MYAPP_WP_SSO_SITE_ID') ? 'MYAPP_WP_SSO_SITE_ID' : (defined('HOLLER_API_SITE_ID') ? 'HOLLER_API_SITE_ID' : null),
            'secret_active' => defined('MYAPP_WP_SSO_SECRET_ACTIVE') ? 'MYAPP_WP_SSO_SECRET_ACTIVE' : (defined('HOLLER_API_SSO_SECRET_ACTIVE') ? 'HOLLER_API_SSO_SECRET_ACTIVE' : null),
            'secret_previous' => defined('MYAPP_WP_SSO_SECRET_PREVIOUS') ? 'MYAPP_WP_SSO_SECRET_PREVIOUS' : (defined('HOLLER_API_SSO_SECRET_PREVIOUS') ? 'HOLLER_API_SSO_SECRET_PREVIOUS' : null),
            'issuer' => defined('MYAPP_WP_SSO_ISSUER') ? 'MYAPP_WP_SSO_ISSUER' : null,
            'audience' => defined('MYAPP_WP_SSO_AUDIENCE') ? 'MYAPP_WP_SSO_AUDIENCE' : null,
            'allowed_redirect_paths' => defined('MYAPP_WP_SSO_ALLOWED_REDIRECT_PATHS') ? 'MYAPP_WP_SSO_ALLOWED_REDIRECT_PATHS' : null,
            'require_manage_options' => defined('MYAPP_WP_SSO_REQUIRE_MANAGE_OPTIONS') ? 'MYAPP_WP_SSO_REQUIRE_MANAGE_OPTIONS' : null,
            'require_redeem' => defined('MYAPP_WP_SSO_REQUIRE_REDEEM') ? 'MYAPP_WP_SSO_REQUIRE_REDEEM' : null,
            'enabled' => defined('MYAPP_WP_SSO_ENABLED') ? 'MYAPP_WP_SSO_ENABLED' : null,
        ];
        $ro = function ($key) use ($locked) {
            return !empty($locked[$key]) ? ' readonly aria-readonly="true"' : '';
        };
        $dis = function ($key) use ($locked) {
            return !empty($locked[$key]) ? ' disabled aria-disabled="true"' : '';
        };
        $note = function ($key) use ($locked) {
            if (empty($locked[$key])) return '';
            return '<p class="description">Managed via <code>' . esc_html((string) $locked[$key]) . '</code></p>';
        };
        echo '<div class="wrap">';
        echo '<h1>Holler SSO</h1>';
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="holler_sso_action" value="save" />';
        echo '<input type="hidden" name="holler_sso_nonce" value="' . esc_attr($nonce) . '" />';

        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">Enable SSO</th><td>';
        echo '<label><input type="checkbox" name="enabled" value="1" ' . checked((bool) $s['enabled'], true, false) . $dis('enabled') . ' /> Enabled</label>';
        echo $note('enabled');
        echo '</td></tr>';

        echo '<tr><th scope="row">Site ID</th><td>';
        echo '<input type="text" class="regular-text" name="site_id" value="' . esc_attr($s['site_id']) . '"' . $ro('site_id') . ' />';
        echo $note('site_id');
        echo '</td></tr>';

        echo '<tr><th scope="row">App Base URL</th><td>';
        echo '<input type="url" class="regular-text" name="app_base_url" value="' . esc_attr($s['app_base_url']) . '" placeholder="https://app.example.com"' . $ro('app_base_url') . ' />';
        echo '<p class="description">Used for server-to-server redemption callback: <code>/api/wp-sso/redeem</code></p>';
        echo $note('app_base_url');
        echo '</td></tr>';

        echo '<tr><th scope="row">Issuer</th><td>';
        echo '<input type="text" class="regular-text" name="issuer" value="' . esc_attr($s['issuer']) . '"' . $ro('issuer') . ' />';
        echo $note('issuer');
        echo '</td></tr>';

        echo '<tr><th scope="row">Audience</th><td>';
        echo '<input type="text" class="regular-text" name="audience" value="' . esc_attr($s['audience']) . '"' . $ro('audience') . ' />';
        echo $note('audience');
        echo '</td></tr>';

        echo '<tr><th scope="row">Shared Secret (Active)</th><td>';
        echo '<input type="password" class="regular-text" name="secret_active" value="' . esc_attr($s['secret_active']) . '" autocomplete="new-password"' . $ro('secret_active') . ' />';
        echo $note('secret_active');
        echo '</td></tr>';

        echo '<tr><th scope="row">Shared Secret (Previous)</th><td>';
        echo '<input type="password" class="regular-text" name="secret_previous" value="' . esc_attr($s['secret_previous']) . '" autocomplete="new-password"' . $ro('secret_previous') . ' />';
        echo $note('secret_previous');
        echo '</td></tr>';

        echo '<tr><th scope="row">Allowed Redirect Paths</th><td>';
        echo '<textarea class="large-text" rows="5" name="allowed_redirect_paths"' . (!empty($locked['allowed_redirect_paths']) ? ' readonly aria-readonly="true"' : '') . '>' . esc_textarea($s['allowed_redirect_paths']) . '</textarea>';
        echo $note('allowed_redirect_paths');
        echo '</td></tr>';

        echo '<tr><th scope="row">Require manage_options</th><td>';
        echo '<label><input type="checkbox" name="require_manage_options" value="1" ' . checked((bool) $s['require_manage_options'], true, false) . $dis('require_manage_options') . ' /> Require manage_options</label>';
        echo $note('require_manage_options');
        echo '</td></tr>';

        echo '<tr><th scope="row">Require redemption callback</th><td>';
        echo '<label><input type="checkbox" name="require_redeem" value="1" ' . checked((bool) $s['require_redeem'], true, false) . $dis('require_redeem') . ' /> Require successful redemption with the app</label>';
        echo $note('require_redeem');
        echo '</td></tr>';

        echo '<tr><th scope="row">Rate Limit</th><td>';
        echo '<input type="number" name="rate_limit_max" value="' . esc_attr((int) $s['rate_limit_max']) . '" min="1" /> failed attempts / ';
        echo '<input type="number" name="rate_limit_window" value="' . esc_attr((int) $s['rate_limit_window']) . '" min="60" /> seconds';
        echo '</td></tr>';

        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">Save Changes</button>';
        echo '</p>';

        echo '</form>';
        echo '</div>';
    }

    public function handle_admin_post() {
        if (!is_admin()) return;
        if (!current_user_can('manage_options')) return;
        if (empty($_POST['holler_sso_action']) || $_POST['holler_sso_action'] !== 'save') return;
        if (empty($_POST['holler_sso_nonce']) || !wp_verify_nonce($_POST['holler_sso_nonce'], self::NONCE_KEY)) return;

        $settings = $this->get_settings();

        // Only persist values that are NOT constant-backed.
        if (!defined('MYAPP_WP_SSO_ENABLED')) $settings['enabled'] = !empty($_POST['enabled']);
        if (!defined('MYAPP_WP_SSO_APP_BASE_URL') && !defined('HOLLER_API_URL')) $settings['app_base_url'] = esc_url_raw($_POST['app_base_url'] ?? '');
        if (!defined('MYAPP_WP_SSO_SITE_ID') && !defined('HOLLER_API_SITE_ID')) $settings['site_id'] = sanitize_text_field($_POST['site_id'] ?? '');
        if (!defined('MYAPP_WP_SSO_ISSUER')) $settings['issuer'] = sanitize_text_field($_POST['issuer'] ?? '');
        if (!defined('MYAPP_WP_SSO_AUDIENCE')) $settings['audience'] = sanitize_text_field($_POST['audience'] ?? '');
        if (!defined('MYAPP_WP_SSO_SECRET_ACTIVE') && !defined('HOLLER_API_SSO_SECRET_ACTIVE')) $settings['secret_active'] = sanitize_text_field($_POST['secret_active'] ?? '');
        if (!defined('MYAPP_WP_SSO_SECRET_PREVIOUS') && !defined('HOLLER_API_SSO_SECRET_PREVIOUS')) $settings['secret_previous'] = sanitize_text_field($_POST['secret_previous'] ?? '');
        if (!defined('MYAPP_WP_SSO_ALLOWED_REDIRECT_PATHS')) $settings['allowed_redirect_paths'] = (string) ($_POST['allowed_redirect_paths'] ?? '');
        if (!defined('MYAPP_WP_SSO_REQUIRE_MANAGE_OPTIONS')) $settings['require_manage_options'] = !empty($_POST['require_manage_options']);
        if (!defined('MYAPP_WP_SSO_REQUIRE_REDEEM')) $settings['require_redeem'] = !empty($_POST['require_redeem']);
        $settings['rate_limit_max'] = max(1, (int) ($_POST['rate_limit_max'] ?? 10));
        $settings['rate_limit_window'] = max(60, (int) ($_POST['rate_limit_window'] ?? 300));

        update_option(self::OPTION_KEY, $settings, false);
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>Holler SSO settings saved.</p></div>';
        });
    }
}
