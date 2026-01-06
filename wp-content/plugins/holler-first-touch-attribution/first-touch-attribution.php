<?php
/**
 * Plugin Name: First Touch Attribution
 * Description: Captures first-touch channel/source/landing page and injects into Gravity Forms hidden fields.
 * Plugin URI: https://hollerdigital.com    
 * Version: 1.0.0
 * Author: Holler Digital
 * Author URI: https://hollerdigital.com
 * Text Domain: first-touch-attribution
 * Domain Path: /languages
 */


 

if (!defined('ABSPATH')) exit;

final class GF_First_Touch_Attribution {
  const VERSION = '1.0.0';
  const HANDLE  = 'gf-first-touch-attribution';
  const OPTION_COOKIE_DAYS = 'gffta_cookie_days';

  public function __construct() {
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_init', [$this, 'register_settings']);
  }

  public function register_settings() {
    register_setting('general', self::OPTION_COOKIE_DAYS, [
      'type' => 'integer',
      'sanitize_callback' => function($v) {
        $v = (int)$v;
        if ($v < 1) $v = 1;
        if ($v > 365) $v = 365;
        return $v;
      },
      'default' => 90,
    ]);

    add_settings_field(
      self::OPTION_COOKIE_DAYS,
      'GF Attribution Cookie Days',
      function() {
        $val = (int) get_option(self::OPTION_COOKIE_DAYS, 90);
        echo '<input type="number" min="1" max="365" name="'.esc_attr(self::OPTION_COOKIE_DAYS).'" value="'.esc_attr($val).'" class="small-text" />';
        echo '<p class="description">How long to persist first-touch attribution (cookies + localStorage). Default 90.</p>';
      },
      'general'
    );
  }

  public function enqueue_assets() {
    // If Gravity Forms is not installed, script is harmless; no hard dependency.
    $cookie_days = (int) get_option(self::OPTION_COOKIE_DAYS, 90);

    wp_register_script(
      self::HANDLE,
      plugins_url('assets/gf-first-touch-attribution.js', __FILE__),
      [],
      self::VERSION,
      true
    );

    wp_localize_script(self::HANDLE, 'GFFTA_CONFIG', [
      'cookieDays' => $cookie_days,
      // The three fields you asked for as "first touch":
      'keys' => [
        'channel'            => 'channel',
        'source'             => 'source',
        'landing_page_first' => 'landing_page_first',
      ],
      // Optional extras you may want later (still captured by the JS)
      'extraKeys' => [
        'referrer_first',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'msclkid', 'fbclid'
      ],
    ]);

    wp_enqueue_script(self::HANDLE);
  }
}

new GF_First_Touch_Attribution();