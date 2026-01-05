<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! defined( 'ABSPATH' ) ) { exit; }

class HD_DB_Optimizer {
    const SLUG  = 'hd-db-optimizer';
    const NONCE = 'hd_db_optimizer_nonce';

    /** @var wpdb */
    private $db;

    public function __construct() {
        global $wpdb; $this->db = $wpdb;
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_' . self::SLUG, [$this, 'handle_submit']);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('hd-db-optimize', [$this, 'wpcli']);
        }
    }

    /**
     * Admin UI
     */
    public function register_menu() {
        add_management_page(
            'DB Optimizer', 'DB Optimizer', 'manage_options', self::SLUG, [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) { return; }
        $nonce  = wp_create_nonce(self::NONCE);
        $fields = $this->default_fields();
        $ops    = $this->operations();
        // Load admin view template.
        $template = dirname(__DIR__, 1) . '/../admin/db-optimizer-page.php';
        if ( file_exists( $template ) ) {
            include $template; // uses $nonce, $fields, $ops
        } else {
            echo '<div class="notice notice-error"><p>Admin template missing: ' . esc_html( $template ) . '</p></div>';
        }
    }

    private function default_fields() {
        return [
            'ops' => [],
            'dry_run' => true,
            'revision_days' => 14,
        ];
    }

    /**
     * Handle form submit
     */
    public function handle_submit() {
        if (!current_user_can('manage_options')) { wp_die('Insufficient permissions'); }
        check_admin_referer(self::NONCE);

        $ops  = isset($_POST['ops']) && is_array($_POST['ops']) ? array_map('sanitize_text_field', $_POST['ops']) : [];
        $dry  = !empty($_POST['dry_run']);
        $days = isset($_POST['revision_days']) ? max(1, intval($_POST['revision_days'])) : 90;

        $log = $this->run($ops, $dry, $days);
        $b64 = base64_encode($log);
        wp_safe_redirect(add_query_arg('hd_db_optimizer_result', rawurlencode($b64), wp_get_referer() ?: admin_url('tools.php?page=' . self::SLUG)));
        exit;
    }

    /**
     * WP‑CLI
     */
    public function wpcli($args, $assoc) {
        $ops  = isset($assoc['ops']) ? array_map('trim', explode(',', $assoc['ops'])) : wp_list_pluck($this->operations(), 'id');
        $dry  = isset($assoc['dry-run']);
        $days = isset($assoc['revision-days']) ? max(1, intval($assoc['revision-days'])) : 90;
        $log  = $this->run($ops, $dry, $days);
        if (defined('WP_CLI') && WP_CLI) { WP_CLI::line($log); }
    }

    /**
     * Expose operations for external (CLI) use.
     *
     * @return array { id, label, desc }
     */
    public function get_operations() {
        $ops = $this->operations();
        $out = [];
        foreach ( $ops as $op ) {
            $out[] = [
                'id'    => $op['id'],
                'label' => $op['label'],
                'desc'  => $op['desc'],
            ];
        }
        return $out;
    }

    /**
     * Public runner for selected ops (for CLI).
     *
     * @param array $selected IDs
     * @param bool  $dry
     * @param int   $revision_days
     * @return string Log output
     */
    public function run_selected(array $selected, $dry, $revision_days) {
        return $this->run($selected, (bool) $dry, (int) $revision_days);
    }

    /**
     * Define all supported operations
     */
    private function operations() {
        $pfx = $this->db->prefix;
        return [
            [
                'id' => 'expired_transients',
                'label' => 'Delete expired transients (site & single)',
                'desc' => 'Removes only expired transients from options/sitemeta.',
                'run' => function($dry) use ($pfx) { return $this->op_expired_transients($dry); }
            ],
            [
                'id' => 'orphan_postmeta',
                'label' => 'Cleanup orphaned postmeta',
                'desc' => 'Deletes postmeta rows whose posts no longer exist.',
                'run' => function($dry) use ($pfx) { return $this->op_orphan_postmeta($dry); }
            ],
            [
                'id' => 'orphan_term_rel',
                'label' => 'Cleanup orphaned term relationships',
                'desc' => 'Removes relationships without matching taxonomy rows.',
                'run' => function($dry) use ($pfx) { return $this->op_orphan_term_rel($dry); }
            ],
            [
                'id' => 'delete_old_revisions',
                'label' => 'Delete old revisions',
                'desc' => 'Deletes post revisions older than a configured age.',
                'run' => function($dry, $opts) use ($pfx) { return $this->op_delete_old_revisions($dry, $opts['revision_days']); }
            ],
            [
                'id' => 'analyze_tables',
                'label' => 'ANALYZE all tables',
                'desc' => 'Updates index statistics for better query plans.',
                'run' => function($dry) { return $this->op_analyze_all($dry); }
            ],
            [
                'id' => 'optimize_tables',
                'label' => 'OPTIMIZE all tables',
                'desc' => 'Rebuilds tables/defragments storage (may lock tables).',
                'run' => function($dry) { return $this->op_optimize_all($dry); }
            ],
            [
                'id' => 'convert_myisam',
                'label' => 'Convert MyISAM → InnoDB (if any)',
                'desc' => 'Alters legacy MyISAM tables to InnoDB.',
                'run' => function($dry) { return $this->op_convert_myisam($dry); }
            ],
            [
                'id' => 'report_autoload_bloat',
                'label' => 'Report: top autoloaded options',
                'desc' => 'Lists the largest autoloaded options (no changes).',
                'run' => function($dry) { return $this->op_report_autoload_bloat(); }
            ],
        ];
    }

    /**
     * Run selected ops
     */
    private function run(array $selected, $dry, $revision_days) {
        $ops = $this->operations();
        $ops_by_id = [];
        foreach ($ops as $op) { $ops_by_id[$op['id']] = $op; }
        if (empty($selected)) { $selected = array_keys($ops_by_id); }

        $log = [];
        $log[] = 'HD DB Optimizer ' . date('Y-m-d H:i:s');
        $log[] = 'Dry Run: ' . ($dry ? 'YES' : 'NO');
        $log[] = 'Selected operations: ' . implode(', ', $selected);
        $log[] = str_repeat('-', 60);

        foreach ($selected as $id) {
            if (!isset($ops_by_id[$id])) { $log[] = "[skip] Unknown op: $id"; continue; }
            $log[] = "[start] {$ops_by_id[$id]['label']}";
            $fn = $ops_by_id[$id]['run'];
            $out = is_callable($fn) ? call_user_func($fn, $dry, ['revision_days' => $revision_days]) : 'No handler';
            $log[] = rtrim($out);
            $log[] = "[done]  {$ops_by_id[$id]['label']}";
            $log[] = str_repeat('-', 60);
        }

        return implode("\n", $log) . "\n";
    }

    /** Helpers */
    private function dbname() { return $this->db->get_var('SELECT DATABASE()'); }
    private function all_tables() { return $this->db->get_col('SHOW TABLES'); }

    /** Operation: delete expired transients */
    private function op_expired_transients($dry) {
        $pfx = $this->db->prefix;
        $lines = [];
        // Single-site options
        $opt = $this->db->options;
        $cnt = (int) $this->db->get_var("SELECT COUNT(*) FROM {$opt} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");
        $lines[] = "Expired single-site transients: $cnt";
        // Multisite (network) transients live in sitemeta
        $ismu = is_multisite();
        if ($ismu) {
            $sitemeta = $this->db->sitemeta;
            $cnt_mu = (int) $this->db->get_var("SELECT COUNT(*) FROM {$sitemeta} WHERE meta_key LIKE '_site_transient_timeout_%' AND meta_value < UNIX_TIMESTAMP()");
            $lines[] = "Expired network transients: $cnt_mu";
        }

        if ($dry) {
            $lines[] = '[Dry] Would execute JOIN deletes to remove expired transients and their values.';
            return implode("\n", $lines);
        }

        // Delete expired transients (single-site)
        $sql1 = "DELETE o FROM {$opt} o JOIN {$opt} t ON o.option_name = CONCAT('_transient_', SUBSTRING(t.option_name, 20)) WHERE t.option_name LIKE '_transient_timeout_%' AND t.option_value < UNIX_TIMESTAMP()";
        $sql2 = "DELETE FROM {$opt} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()";
        $d1 = (int) $this->db->query($sql1);
        $d2 = (int) $this->db->query($sql2);
        $lines[] = "Deleted single-site transient values: {$d1}";
        $lines[] = "Deleted single-site transient timeouts: {$d2}";

        if ($ismu) {
            $sql3 = "DELETE o FROM {$sitemeta} o JOIN {$sitemeta} t ON o.meta_key = CONCAT('_site_transient_', SUBSTRING(t.meta_key, 26)) WHERE t.meta_key LIKE '_site_transient_timeout_%' AND t.meta_value < UNIX_TIMESTAMP()";
            $sql4 = "DELETE FROM {$sitemeta} WHERE meta_key LIKE '_site_transient_timeout_%' AND meta_value < UNIX_TIMESTAMP()";
            $d3 = (int) $this->db->query($sql3);
            $d4 = (int) $this->db->query($sql4);
            $lines[] = "Deleted network transient values: {$d3}";
            $lines[] = "Deleted network transient timeouts: {$d4}";
        }

        return implode("\n", $lines);
    }

    /** Operation: cleanup orphaned postmeta */
    private function op_orphan_postmeta($dry) {
        $pm = $this->db->postmeta; $p = $this->db->posts;
        $cnt = (int) $this->db->get_var("SELECT COUNT(*) FROM {$pm} pm LEFT JOIN {$p} p ON p.ID = pm.post_id WHERE p.ID IS NULL");
        if ($dry) {
            return sprintf("Found %d orphaned postmeta rows. [Dry] Would delete with LEFT JOIN.", $cnt);
        }
        $sql = "DELETE pm FROM {$pm} pm LEFT JOIN {$p} p ON p.ID = pm.post_id WHERE p.ID IS NULL";
        $deleted = (int) $this->db->query($sql);
        return sprintf("Deleted %d orphaned postmeta rows.", $deleted);
    }

    /** Operation: cleanup orphaned term relationships */
    private function op_orphan_term_rel($dry) {
        $tr = $this->db->term_relationships; $tt = $this->db->term_taxonomy;
        $cnt = (int) $this->db->get_var("SELECT COUNT(*) FROM {$tr} tr LEFT JOIN {$tt} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.term_taxonomy_id IS NULL");
        if ($dry) { return sprintf("Found %d orphaned term relationships. [Dry] Would delete.", $cnt); }
        $sql = "DELETE tr FROM {$tr} tr LEFT JOIN {$tt} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.term_taxonomy_id IS NULL";
        $deleted = (int) $this->db->query($sql);
        return sprintf("Deleted %d orphaned term relationships.", $deleted);
    }

    /** Operation: delete old revisions */
    private function op_delete_old_revisions($dry, $days) {
        $p = $this->db->posts; $days = max(1, (int)$days);
        $cnt = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$p} WHERE post_type='revision' AND post_modified < (NOW() - INTERVAL %d DAY)", $days));
        if ($dry) { return sprintf("Revisions older than %d days: %d. [Dry] Would delete.", $days, $cnt); }
        $sql = $this->db->prepare("DELETE FROM {$p} WHERE post_type='revision' AND post_modified < (NOW() - INTERVAL %d DAY)", $days);
        $deleted = (int) $this->db->query($sql);
        return sprintf("Deleted %d old revisions (>%d days).", $deleted, $days);
    }

    /** Operation: ANALYZE all tables */
    private function op_analyze_all($dry) {
        $tables = $this->all_tables();
        if ($dry) {
            return "[Dry] Would ANALYZE these tables (" . count($tables) . "):\n- `" . implode("`\n- `", $tables) . "`";
        }
        $ok = 0; $err = [];
        foreach ($tables as $t) {
            $res = $this->db->get_results("ANALYZE TABLE `{$t}`");
            if ($this->db->last_error) { $err[] = "ANALYZE {$t}: " . $this->db->last_error; } else { $ok++; }
        }
        $out = "Analyzed {$ok} tables."; if ($err) { $out .= "\nErrors:\n- " . implode("\n- ", $err); }
        return $out;
    }

    /** Operation: OPTIMIZE all tables */
    private function op_optimize_all($dry) {
        $tables = $this->all_tables();
        if ($dry) { return "[Dry] Would OPTIMIZE these tables (" . count($tables) . "):\n- `" . implode("`\n- `", $tables) . "`"; }
        $ok = 0; $err = [];
        foreach ($tables as $t) {
            $this->db->get_results("OPTIMIZE TABLE `{$t}`");
            if ($this->db->last_error) { $err[] = "OPTIMIZE {$t}: " . $this->db->last_error; } else { $ok++; }
        }
        $out = "Optimized {$ok} tables."; if ($err) { $out .= "\nErrors:\n- " . implode("\n- ", $err); }
        return $out;
    }

    /** Operation: Convert MyISAM → InnoDB */
    private function op_convert_myisam($dry) {
        $db = esc_sql($this->dbname());
        $rows = $this->db->get_col($this->db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=%s AND engine='MyISAM'", $db));
        if (empty($rows)) { return 'No MyISAM tables found.'; }
        if ($dry) { return "[Dry] Would convert to InnoDB:\n- `" . implode("`\n- `", $rows) . "`"; }
        $ok=0; $err=[];
        foreach ($rows as $t) {
            $this->db->query("ALTER TABLE `{$t}` ENGINE=InnoDB");
            if ($this->db->last_error) { $err[] = "ALTER {$t}: " . $this->db->last_error; } else { $ok++; }
        }
        $out = "Converted {$ok} tables to InnoDB."; if ($err) { $out .= "\nErrors:\n- " . implode("\n- ", $err); }
        return $out;
    }

    /** Report: list top autoloaded options by size */
    private function op_report_autoload_bloat() {
        $opt = $this->db->options;
        $rows = $this->db->get_results("SELECT option_name, LENGTH(option_value) AS bytes FROM {$opt} WHERE autoload='yes' ORDER BY bytes DESC LIMIT 20", ARRAY_A);
        if (!$rows) { return 'No autoloaded options found or table empty.'; }
        $out = ["Top autoloaded options (by size):"]; $i=1;
        foreach ($rows as $r) {
            $out[] = sprintf('%2d. %-60s %8s bytes', $i++, $r['option_name'], number_format($r['bytes']));
        }
        $out[] = 'Note: This is informational only. Review and de‑autoload via code/plugins if appropriate.';
        return implode("\n", $out);
    }
}
