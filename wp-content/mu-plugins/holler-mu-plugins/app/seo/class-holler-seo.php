<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
// Simple, self-contained SEO tweaks for MU plugin.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Holler_SEO' ) ) :
	class Holler_SEO {
		/**
		 * Bootstrap hooks.
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'maybe_setup_rank_math_tweaks' ) );
		}

		/**
		 * Attach Rank Math related tweaks only if Rank Math is active.
		 */
		public static function maybe_setup_rank_math_tweaks() {
			if ( class_exists( 'RankMath' ) ) {
				add_filter( 'rank_math/can_edit_file', '__return_true' );
				add_filter( 'rank_math/sitemap/enable_caching', '__return_false' );
			}
		}
	}
endif;
