<?php
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php echo esc_html__('VibePresto Bundles', 'vibepresto'); ?></h1>

    <?php if ($notice) : ?>
        <div class="notice notice-<?php echo $notice['type'] === 'success' ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo esc_html($notice['message']); ?></p>
        </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:minmax(320px, 480px) 1fr;gap:24px;align-items:start;">
        <div class="card" style="max-width:none;">
            <h2><?php echo esc_html__('Upload Bundle', 'vibepresto'); ?></h2>
            <?php
            $form_variant = 'admin';
            $submit_label = __('Upload bundle', 'vibepresto');
            $show_assign_checkbox = false;
            $default_assign_checked = false;
            $current_page_id = 0;
            include VIBEPRESTO_PLUGIN_DIR . 'views/upload-form.php';
            ?>
        </div>

        <div class="card" style="max-width:none;">
            <h2><?php echo esc_html__('Existing Bundles', 'vibepresto'); ?></h2>
            <?php if (! $bundles) : ?>
                <p><?php echo esc_html__('No bundles uploaded yet.', 'vibepresto'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Name', 'vibepresto'); ?></th>
                            <th><?php echo esc_html__('Mode', 'vibepresto'); ?></th>
                            <th><?php echo esc_html__('Entry HTML', 'vibepresto'); ?></th>
                            <th><?php echo esc_html__('Updated', 'vibepresto'); ?></th>
                            <th><?php echo esc_html__('Action', 'vibepresto'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bundles as $bundle) : ?>
                            <tr>
                                <td><?php echo esc_html($bundle['title']); ?></td>
                                <td><?php echo esc_html($bundle['mode']); ?></td>
                                <td><code><?php echo esc_html($bundle['entry_html']); ?></code></td>
                                <td><?php echo esc_html(get_date_from_gmt($bundle['updated_at'], 'Y-m-d H:i')); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this bundle?', 'vibepresto')); ?>');">
                                        <input type="hidden" name="action" value="vibepresto_delete_bundle">
                                        <input type="hidden" name="bundle_id" value="<?php echo esc_attr((string) $bundle['id']); ?>">
                                        <?php wp_nonce_field('vibepresto_delete_bundle'); ?>
                                        <?php submit_button(__('Delete', 'vibepresto'), 'delete small', 'submit', false); ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
