<?php
// Holler MU Helper module (loaded by the main MU plugin). No plugin header here.

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function add_holler_ascii(){
    ?>
<!-- 

 ___  ___  ________  ___       ___       _______   ________          ________  ___  ________  ___  _________  ________  ___          
|\  \|\  \|\   __  \|\  \     |\  \     |\  ___ \ |\   __  \        |\   ___ \|\  \|\   ____\|\  \|\___   ___\\   __  \|\  \         
\ \  \\\  \ \  \|\  \ \  \    \ \  \    \ \   __/|\ \  \|\  \       \ \  \_|\ \ \  \ \  \___|\ \  \|___ \  \_\ \  \|\  \ \  \        
 \ \   __  \ \  \\\  \ \  \    \ \  \    \ \  \_|/_\ \   _  _\       \ \  \ \\ \ \  \ \  \  __\ \  \   \ \  \ \ \   __  \ \  \       
  \ \  \ \  \ \  \\\  \ \  \____\ \  \____\ \  \_|\ \ \  \\  \|       \ \  \_\\ \ \  \ \  \|\  \ \  \   \ \  \ \ \  \ \  \ \  \____  
   \ \__\ \__\ \_______\ \_______\ \_______\ \_______\ \__\\ _\        \ \_______\ \__\ \_______\ \__\   \ \__\ \ \__\ \__\ \_______\
    \|__|\|__|\|_______|\|_______|\|_______|\|_______|\|__|\|__|        \|_______|\|__|\|_______|\|__|    \|__|  \|__|\|__|\|_______|
                                                                                                                                     
                                                                                                                                     
                                                                                                                                   
Design and Developed By: Holler Digital - https://hollerdigital.com - info@hollerdigital.com -->

<?php
 
}
//add_action('wp_head', 'add_holler_ascii', 40);
add_action('astra_html_before', 'add_holler_ascii', 40);

 

// Admin notice: warn if search engines are discouraged.
function hdmup_indexing_warning() {
	if ( get_option( 'blog_public' ) == '0' ) {
		echo '<div class="notice notice-warning"><p><strong>Warning:</strong> Search engines are discouraged from indexing this site. '
			. '<a href="' . esc_url( admin_url( 'options-reading.php' ) ) . '">Update this setting</a></p></div>';
	}
}
add_action( 'admin_notices', __NAMESPACE__ . '\\hdmup_indexing_warning' );

// Admin footer branding.
function hdmup_footer_text() {
	echo 'Website built and maintained by <a href="https://hollerdigital.com/" target="_blank">Holler Digital</a>';
}
add_filter( 'admin_footer_text', __NAMESPACE__ . '\\hdmup_footer_text' );

// Restrict select admin menus for non-admins.
function hdmup_remove_menus() {
	if ( ! current_user_can( 'manage_options' ) ) {
		remove_menu_page( 'tools.php' );
		remove_menu_page( 'plugins.php' );
		remove_menu_page( 'options-general.php' );
	}
}
add_action( 'admin_menu', __NAMESPACE__ . '\\hdmup_remove_menus', 999 );

// Hardening: disable Plugin & Theme editors and file modifications from wp-admin.
// Wrap in guards so wp-config.php can override if needed.
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
    define( 'DISALLOW_FILE_EDIT', true );
}
// if ( ! defined( 'DISALLOW_FILE_MODS' ) ) {
//     define( 'DISALLOW_FILE_MODS', true );
// }

// Upload restrictions and SVG support.
// Consolidate into a single filter so behavior is predictable and easy to override.
function hdmup_upload_mimes( $mimes ) {
    // Allowed types (restrict to a safe subset) and add SVG.
    $allowed = array(
        'jpg|jpeg' => 'image/jpeg',
        'png'      => 'image/png',
        'gif'      => 'image/gif',
        'webp'     => 'image/webp',
        'pdf'      => 'application/pdf',
        'doc'      => 'application/msword',
        'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );

    // Optionally allow SVG uploads (SVGs can contain scripts; limit to admins by default).
    // Change capability as needed, or always allow by removing the condition.
    if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
        $allowed['svg'] = 'image/svg+xml';
    }

    return $allowed;
}
add_filter( 'upload_mimes', __NAMESPACE__ . '\\hdmup_upload_mimes' );

function hdmup_is_displayable_image( $result, $path ) {
    if ( false === $result ) {
        $info = @getimagesize( $path );
        if ( ! empty( $info ) && isset( $info[2] ) && IMAGETYPE_WEBP === $info[2] ) {
            return true;
        }
    }
    return $result;
}
add_filter( 'file_is_displayable_image', __NAMESPACE__ . '\\hdmup_is_displayable_image', 10, 2 );

// Hide WordPress login errors to avoid user enumeration leaks.
function hdmup_hide_login_errors( $error ) {
    return 'Login failed. Try again.';
}
add_filter( 'login_errors', __NAMESPACE__ . '\\hdmup_hide_login_errors' );

// // Auto-logout idle users after 15 minutes of inactivity (front-end only).
// function hdmup_auto_logout_idle_users() {
//     if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && ! is_admin() ) {
//         $logout_url = esc_url( wp_logout_url() );
//         echo '<script>(function(){\n'
//             . 'var timeoutMs=900000, timer;\n'
//             . 'function reset(){ clearTimeout(timer); timer=setTimeout(function(){ window.location.href=' . json_encode( $logout_url ) . '; }, timeoutMs);}\n'
//             . "['load','mousemove','keydown','scroll','touchstart','visibilitychange'].forEach(function(evt){ window.addEventListener(evt, reset, {passive:true}); });\n"
//             . 'reset();\n'
//             . '})();</script>';
//     }
// }
// add_action( 'wp_footer', __NAMESPACE__ . '\\hdmup_auto_logout_idle_users' );

// Limit Login Attempts (brute-force mitigation) using transients (no PHP sessions required).
// Locks out a username+IP pair for 10 minutes after 5 failed attempts.
function hdmup_login_attempt_key( $username ) {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
    return 'hdmup_login_' . md5( strtolower( (string) $username ) . '|' . $ip );
}

function hdmup_login_is_locked( $username ) {
    $key = hdmup_login_attempt_key( $username ) . '_lock';
    return (bool) get_transient( $key );
}

function hdmup_login_register_failure( $username ) {
    $max_attempts    = 5;
    $lockout_seconds = 600; // 10 minutes.
    $base_key        = hdmup_login_attempt_key( $username );
    $count           = (int) get_transient( $base_key );
    $count++;
    set_transient( $base_key, $count, $lockout_seconds );

    if ( $count >= $max_attempts ) {
        set_transient( $base_key . '_lock', 1, $lockout_seconds );
    }
}

function hdmup_login_reset_attempts( $username ) {
    $base_key = hdmup_login_attempt_key( $username );
    delete_transient( $base_key );
    delete_transient( $base_key . '_lock' );
}

// Block authentication when currently locked.
function hdmup_block_when_locked( $user, $username, $password ) {
    if ( empty( $username ) ) {
        return $user; // Let core handle empty username.
    }
    if ( hdmup_login_is_locked( $username ) ) {
        return new \WP_Error( 'too_many_failed_logins', __( 'Too many failed login attempts. Try again later.', 'holler-mu-plugins' ) );
    }
    return $user;
}
add_filter( 'authenticate', __NAMESPACE__ . '\\hdmup_block_when_locked', 30, 3 );

// Track failures and successes.
add_action( 'wp_login_failed', __NAMESPACE__ . '\\hdmup_login_register_failure' );
add_action( 'wp_login', __NAMESPACE__ . '\\hdmup_login_reset_attempts', 10, 1 );
