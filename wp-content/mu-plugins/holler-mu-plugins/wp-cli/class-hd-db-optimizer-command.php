<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Holler_DB_Optimizer_Command' ) ) :
/**
 * WP-CLI commands for HD_DB_Optimizer
 *
 * Usage examples:
 *   wp holler db-optimize list
 *   wp holler db-optimize run --ops=expired_transients,analyze_tables --dry-run --revision-days=30
 */
class Holler_DB_Optimizer_Command {
	/** @var HD_DB_Optimizer */
	protected $optimizer;

	public function __construct() {
		if ( ! class_exists( 'HD_DB_Optimizer' ) ) {
			require_once dirname( __DIR__ ) . '/app/performance/class-hd-db-optimizer.php';
		}
		$this->optimizer = new \HD_DB_Optimizer();
	}

	/**
	 * List available optimization operations.
	 *
	 * ## EXAMPLES
	 *   wp holler db-optimize list
	 */
	public function list( $args, $assoc_args ) {
		$ops = $this->optimizer->get_operations();
		if ( empty( $ops ) ) {
			\WP_CLI::success( 'No operations available.' );
			return;
		}
		$items = array_map( function( $op ) {
			return [ 'id' => $op['id'], 'label' => $op['label'], 'desc' => $op['desc'] ];
		}, $ops );
		\WP_CLI\Utils\format_items( 'table', $items, [ 'id', 'label', 'desc' ] );
	}

	/**
	 * Back-compat alias: list-ops
	 */
	public function list_ops( $args, $assoc_args ) {
		return $this->list( $args, $assoc_args );
	}

	/**
	 * Run selected optimization operations.
	 *
	 * ## OPTIONS
	 * [--ops=<ops>]
	 * : Comma-separated operation IDs. If omitted, runs all operations.
	 *
	 * [--dry-run]
	 * : Preview changes only (no writes).
	 * [--revision-days=<days>]
	 * : Number of days for the delete_old_revisions operation. Default: 14
	 *
	 * ## EXAMPLES
	 *   wp holler db-optimize run --dry-run
	 *   wp holler db-optimize run --ops=expired_transients,analyze_tables --revision-days=30
	 */
	public function run( $args, $assoc_args ) {
		$ops  = isset( $assoc_args['ops'] ) ? array_map( 'trim', explode( ',', $assoc_args['ops'] ) ) : [];
		$dry  = isset( $assoc_args['dry-run'] );
		$days = isset( $assoc_args['revision-days'] ) ? max( 1, intval( $assoc_args['revision-days'] ) ) : 14;

		$log = $this->optimizer->run_selected( $ops, $dry, $days );
		\WP_CLI::line( $log );
	}
}
endif;
