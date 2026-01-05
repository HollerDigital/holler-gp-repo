<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Holler_MU_Shortcodes' ) ) :
	class Holler_MU_Shortcodes {
		/**
		 * Prevent duplicate initialization.
		 *
		 * @var bool
		 */
		protected static $booted = false;

		/**
		 * Bootstrap hooks for shortcode features.
		 */
		public static function init() {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			// Cleanup unwanted paragraph and line break tags around shortcodes.
			add_filter( 'the_content', array( __CLASS__, 'shortcodes_unwrap' ) );

			// Allow shortcodes in widgets and menus (to preserve previous behavior).
			add_filter( 'widget_text', 'do_shortcode' );
			add_filter( 'wp_nav_menu_items', 'do_shortcode' );

			// Register provided shortcodes.
			add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		}

		/**
		 * Register shortcodes.
		 */
		public static function register_shortcodes() {
			add_shortcode( 'menu', array( __CLASS__, 'shortcode_menu' ) );
			add_shortcode( 'credits', array( __CLASS__, 'shortcode_credits' ) );
			add_shortcode( 'this-year', array( __CLASS__, 'shortcode_this_year' ) );
		}

		/**
		 * Fix unwanted paragraph and line break tags around shortcodes.
		 *
		 * @param string $content The post content.
		 * @return string Modified content.
		 */
		public static function shortcodes_unwrap( $content ) {
			$map = array(
				'<p>['    => '[',
				']</p>'    => ']',
				"]<br />" => ']',
			);
			return strtr( $content, $map );
		}

		/**
		 * [menu] shortcode
		 * Displays a menu by theme location. Accepts `location` (preferred) and legacy `name` (treated as location).
		 *
		 * @param array $atts
		 * @return string
		 */
		public static function shortcode_menu( $atts ) {
			$atts = shortcode_atts(
				array(
					'location' => 'primary',
					// Back-compat: allow `name` to be passed, treated as location.
					'name'     => '',
					'class'    => 'shortcode-menu',
				),
				$atts,
				'menu'
			);

			$theme_location = ! empty( $atts['location'] ) ? $atts['location'] : $atts['name'];
			if ( empty( $theme_location ) ) {
				$theme_location = 'primary';
			}

			ob_start();

			wp_nav_menu( array(
				'theme_location' => $theme_location,
				'container'      => false,
				'menu_class'     => sanitize_html_class( $atts['class'] ),
				'fallback_cb'    => false,
				'items_wrap'     => '<ul id="%1$s" class="%2$s" role="menu">%3$s</ul>',
			) );

			return ob_get_clean();
		}

		/**
		 * [credits] shortcode
		 * Displays copyright with current year and site name (or provided name).
		 *
		 * @param array $atts
		 * @return string
		 */
		public static function shortcode_credits( $atts ) {
			$atts = shortcode_atts(
				array(
					'name' => '',
				),
				$atts,
				'credits'
			);

			$name = ! empty( $atts['name'] ) ? $atts['name'] : get_bloginfo( 'name' );

			$output  = '<span class="copyright">';
			$output .= '&copy; ' . date( 'Y' ) . ' ' . esc_html( $name );
			$output .= '. <span class="inline-block">' . esc_html__( 'All Rights Reserved.', 'holler-theme' ) . '</span>';
			$output .= '</span>';

			return $output;
		}

		/**
		 * [this-year] shortcode
		 * Outputs the current 4-digit year.
		 *
		 * @return string
		 */
		public static function shortcode_this_year() {
			return date( 'Y' );
		}
	}
endif;
