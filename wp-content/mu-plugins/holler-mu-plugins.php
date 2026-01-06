<?php

/**
 * Plugin Name: Holler Must-use Plugins
 * Plugin URI: https://hollerdigital.com
 * Description: The plugin designed to work on Gridpane's WordPress hosting platform.
 * Version: 1.2.0
 * Author: Holler Digital
 * Author URI: https://hollerdigital.com
 * Text Domain: holler-mu-plugins
 * Domain Path: /holler-mu-plugins/shared/translations
 */

namespace Holler\HDMUP;

if (! defined('ABSPATH')) { // If this file is called directly.
    die('No script kiddies please!');
}


define('HOLLERMU_VERSION', '1.0.0');
define('HOLLERMU_DOCS_URL', 'https://hollerdigital.com');

if (! defined('HOLLERMU_WHITELABEL')) {
    define('HOLLERMU_WHITELABEL', false);
}

/**
 * Define the directory path to the plugin file.
 *
 * This constant provides a convenient reference to the plugin's directory path,
 * useful for including or requiring files relative to this directory.
 *
 * @example
 *
 * if (defined('\Kinsta\KMP\PLUGIN_DIR')) {
 * // Do something when PLUGIN_DIR is defined.
 * };
 */
const PLUGIN_DIR = __DIR__;

/**
 * Define the path to the plugin file.
 *
 * This path can be used in various contexts, such as managing the activation
 * and deactivation processes, loading the plugin text domain, adding action
 * links, and more.
 *
 * if (defined('\Kinsta\KMP\PLUGIN_FILE')) {
 * // Do something when PLUGIN_FILE is defined.
 * };
 */
const PLUGIN_FILE = __FILE__;

/**
 * Load dependencies using the Composer autoloader.
 *
 * This allows us to load third-party libraries without having to include
 * or require the files from the libraries manually.
 *
 * @see https://getcomposer.org/doc/01-basic-usage.md#autoloading
 */
require PLUGIN_DIR . '/holler-mu-plugins/vendor/autoload.php';

// Wire up Holler MU classes.
require_once PLUGIN_DIR . '/holler-mu-plugins/app/security/class-banned-plugins.php';
require_once PLUGIN_DIR . '/holler-mu-plugins/app/helpers/holler-helper.php';
require_once PLUGIN_DIR . '/holler-mu-plugins/app/shortcodes/class-holler-shortcodes.php';
require_once PLUGIN_DIR . '/holler-mu-plugins/app/performance/class-hd-db-optimizer.php';
require_once PLUGIN_DIR . '/holler-mu-plugins/app/seo/class-holler-seo.php';
require_once PLUGIN_DIR . '/holler-mu-plugins/app/myapp-sso/class-myapp-wp-sso.php';
require_once PLUGIN_DIR . '/holler-mu-plugins/telemetry/holler-telemetry.php';

// Thin loader: delegate to app/shortcodes class.

\add_action('plugins_loaded', function () {
    if ( \is_admin() ) {
        // Instantiate the not-recommended plugins handler for admin UI.
        new Banned_Plugins();
    }
    if ( class_exists( '\\Holler_MU_Shortcodes' ) ) {
        \Holler_MU_Shortcodes::init();
    }
    if ( class_exists( '\\HD_DB_Optimizer' ) ) {
        // Instantiate DB Optimizer to register admin UI and handlers.
        new \HD_DB_Optimizer();
    }
    if ( class_exists( '\\Holler_SEO' ) ) {
        \Holler_SEO::init();
    }
    if ( class_exists( '\\MyApp_WP_SSO' ) ) {
        (new \MyApp_WP_SSO())->register();
    }
}, 20);

// Register WP-CLI commands when running via WP-CLI
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once PLUGIN_DIR . '/holler-mu-plugins/wp-cli/class-hd-db-optimizer-command.php';
    if ( class_exists( '\\Holler_DB_Optimizer_Command' ) ) {
        \WP_CLI::add_command( 'holler db-optimize', '\\Holler_DB_Optimizer_Command' );
    }
}
