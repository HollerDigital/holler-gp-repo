<?php
/**
 * Admin template for HD_DB_Optimizer page.
 * Variables available from the caller:
 * - $nonce  string
 * - $fields array
 * - $ops    array
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap">
    <h1>HD DB Optimizer</h1>
    <p>Run routine database maintenance tasks with an optional <strong>Dry Run</strong> to preview effects before making changes.</p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
        <input type="hidden" name="action" value="<?php echo esc_attr( HD_DB_Optimizer::SLUG ); ?>" />
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

        <h2 class="title">Tasks</h2>
        <fieldset style="margin: 12px 0;">
            <?php foreach ( $ops as $op ) : ?>
                <label style="display:flex; gap:.5rem; align-items:center; margin:.4rem 0;">
                    <input type="checkbox" name="ops[]" value="<?php echo esc_attr( $op['id'] ); ?>" <?php checked( in_array( $op['id'], $fields['ops'], true ) ); ?> />
                    <strong><?php echo esc_html( $op['label'] ); ?></strong>
                    <span style="color:#666;">— <?php echo esc_html( $op['desc'] ); ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <h2 class="title">Options</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="dry_run">Dry Run</label></th>
                <td>
                    <label><input type="checkbox" id="dry_run" name="dry_run" value="1" <?php checked( $fields['dry_run'] ); ?> />
                        Preview changes only (no writes)</label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="revision_days">Delete revisions older than (days)</label></th>
                <td>
                    <input type="number" min="1" id="revision_days" name="revision_days" value="<?php echo esc_attr( $fields['revision_days'] ); ?>" />
                </td>
            </tr>
        </table>

        <?php submit_button( 'Run Optimizer' ); ?>
    </form>

    <?php if ( ! empty( $_GET['hd_db_optimizer_result'] ) ) : ?>
        <div class="notice notice-info" style="margin-top:1rem;">
            <p><strong>Last run:</strong></p>
            <pre style="white-space:pre-wrap; max-height:420px; overflow:auto;">
<?php echo esc_html( base64_decode( sanitize_text_field( $_GET['hd_db_optimizer_result'] ) ) ); ?>
            </pre>
        </div>
    <?php endif; ?>
</div>
