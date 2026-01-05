<?php
/**
 * Error Logger - Captures and pushes PHP/WordPress errors to agency hub
 */

namespace HD_Telemetry;

if (!defined('ABSPATH')) exit;

class ErrorLogger {
    private static $instance = null;
    private $endpoint;
    private $api_key;
    private $domain;
    private $batch = [];
    private $batch_size = 10; // Send after 10 errors or on shutdown
    
    // Error level mapping
    private $severity_map = [
        E_ERROR             => 'error',
        E_WARNING           => 'warning',
        E_PARSE             => 'error',
        E_NOTICE            => 'notice',
        E_CORE_ERROR        => 'error',
        E_CORE_WARNING      => 'warning',
        E_COMPILE_ERROR     => 'error',
        E_COMPILE_WARNING   => 'warning',
        E_USER_ERROR        => 'error',
        E_USER_WARNING      => 'warning',
        E_USER_NOTICE       => 'notice',
        E_STRICT            => 'notice',
        E_RECOVERABLE_ERROR => 'error',
        E_DEPRECATED        => 'notice',
        E_USER_DEPRECATED   => 'notice',
    ];
    
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Configuration
        $base_url = defined('HOLLER_API_URL') ? rtrim(HOLLER_API_URL, '/') : 'https://hub.hollerdigital.dev';
        
        $this->endpoint = defined('HOLLER_ERROR_LOG_ENDPOINT') 
            ? HOLLER_ERROR_LOG_ENDPOINT 
            : $base_url . '/api/webhooks/log-error';
            
        $this->api_key = defined('HOLLER_API_KEY') ? HOLLER_API_KEY : '';
        $this->domain = parse_url(get_site_url(), PHP_URL_HOST);
        
        // Only initialize if API key is configured
        if (empty($this->api_key)) {
            return;
        }
        
        // Check if error logging is enabled
        if (defined('HOLLER_ERROR_LOGGING_ENABLED') && !HOLLER_ERROR_LOGGING_ENABLED) {
            return;
        }
        
        // Set up error handlers
        set_error_handler([$this, 'handle_error'], E_ALL);
        register_shutdown_function([$this, 'handle_shutdown']);
        
        // Hook into WordPress errors
        add_action('wp_error_added', [$this, 'handle_wp_error'], 10, 4);
    }
    
    /**
     * Handle PHP errors
     */
    public function handle_error($errno, $errstr, $errfile, $errline) {
        // Skip if error reporting is disabled for this error level
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        // Skip suppressed errors (@)
        if (error_reporting() === 0) {
            return false;
        }
        
        $level = $this->severity_map[$errno] ?? 'error';
        
        // Skip notices in production unless explicitly enabled
        if ($level === 'notice' && !defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }
        
        $this->log_error([
            'message' => $errstr,
            'level' => $level,
            'context' => [
                'type' => 'php_error',
                'errno' => $errno,
                'file' => $this->sanitize_path($errfile),
                'line' => $errline,
                'url' => $_SERVER['REQUEST_URI'] ?? null,
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]
        ]);
        
        // Don't block PHP's internal error handler
        return false;
    }
    
    /**
     * Handle fatal errors on shutdown
     */
    public function handle_shutdown() {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $this->log_error([
                'message' => $error['message'],
                'level' => 'error',
                'context' => [
                    'type' => 'fatal_error',
                    'errno' => $error['type'],
                    'file' => $this->sanitize_path($error['file']),
                    'line' => $error['line'],
                    'url' => $_SERVER['REQUEST_URI'] ?? null,
                    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                ]
            ]);
        }
        
        // Send any remaining batched errors
        $this->flush_batch();
    }
    
    /**
     * Handle WordPress WP_Error objects
     */
    public function handle_wp_error($code, $message, $data, $wp_error) {
        // Skip if not a critical error
        if (!$this->is_critical_wp_error($code)) {
            return;
        }
        
        $this->log_error([
            'message' => is_string($message) ? $message : $code,
            'level' => 'error',
            'context' => [
                'type' => 'wp_error',
                'code' => $code,
                'data' => $data,
                'url' => $_SERVER['REQUEST_URI'] ?? null,
            ]
        ]);
    }
    
    /**
     * Add error to batch
     */
    private function log_error($error_data) {
        $error_data['domain'] = $this->domain;
        $error_data['timestamp'] = gmdate('c');
        
        $this->batch[] = $error_data;
        
        // Send batch if it reaches the size limit
        if (count($this->batch) >= $this->batch_size) {
            $this->flush_batch();
        }
    }
    
    /**
     * Send batched errors to hub
     */
    private function flush_batch() {
        if (empty($this->batch)) {
            return;
        }
        
        $errors = $this->batch;
        $this->batch = [];
        
        // Send each error (or could batch them if API supports it)
        foreach ($errors as $error) {
            $this->send_error($error);
        }
    }
    
    /**
     * Send error to agency hub
     */
    private function send_error($error_data) {
        // Use non-blocking request to avoid slowing down the site
        wp_remote_post($this->endpoint, [
            'blocking' => false,
            'timeout' => 0.01,
            'headers' => [
                'X-API-Key' => $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($error_data),
        ]);
    }
    
    /**
     * Sanitize file paths to remove sensitive server info
     */
    private function sanitize_path($path) {
        $replacements = [
            ABSPATH => '/',
            WP_CONTENT_DIR => '/wp-content',
            get_home_path() => '/',
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $path);
    }
    
    /**
     * Determine if a WP_Error code is critical
     */
    private function is_critical_wp_error($code) {
        $critical_codes = [
            'db_connect_fail',
            'db_query_error',
            'maintenance_mode',
            'installation_error',
        ];
        
        return in_array($code, $critical_codes);
    }
}
