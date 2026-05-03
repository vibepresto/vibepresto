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
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="vibepresto_create_bundle">
                <?php wp_nonce_field('vibepresto_create_bundle'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="display_name"><?php echo esc_html__('Display name', 'vibepresto'); ?></label></th>
                        <td><input id="display_name" name="display_name" type="text" class="regular-text" placeholder="<?php echo esc_attr__('Landing page bundle', 'vibepresto'); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Upload mode', 'vibepresto'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="upload_mode" value="zip" checked> <?php echo esc_html__('ZIP bundle', 'vibepresto'); ?></label><br>
                                <label><input type="radio" name="upload_mode" value="separate"> <?php echo esc_html__('Separate files', 'vibepresto'); ?></label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr class="vibepresto-mode vibepresto-mode-zip">
                        <th scope="row"><label for="bundle_zip"><?php echo esc_html__('ZIP bundle', 'vibepresto'); ?></label></th>
                        <td><input id="bundle_zip" name="bundle_zip" type="file" accept=".zip"></td>
                    </tr>
                    <tr class="vibepresto-mode vibepresto-mode-separate" style="display:none;">
                        <th scope="row"><label for="bundle_html"><?php echo esc_html__('HTML file', 'vibepresto'); ?></label></th>
                        <td><input id="bundle_html" name="bundle_html" type="file" accept=".html,.htm"></td>
                    </tr>
                    <tr class="vibepresto-mode vibepresto-mode-separate" style="display:none;">
                        <th scope="row"><label for="bundle_css"><?php echo esc_html__('CSS file', 'vibepresto'); ?></label></th>
                        <td><input id="bundle_css" name="bundle_css" type="file" accept=".css"></td>
                    </tr>
                    <tr class="vibepresto-mode vibepresto-mode-separate" style="display:none;">
                        <th scope="row"><label for="bundle_js"><?php echo esc_html__('JS file', 'vibepresto'); ?></label></th>
                        <td><input id="bundle_js" name="bundle_js" type="file" accept=".js"></td>
                    </tr>
                    <tr class="vibepresto-mode vibepresto-mode-separate" style="display:none;">
                        <th scope="row"><label for="bundle_assets"><?php echo esc_html__('Extra assets', 'vibepresto'); ?></label></th>
                        <td>
                            <input id="bundle_assets" name="bundle_assets[]" type="file" multiple>
                            <p class="description"><?php echo esc_html__('Optional images, fonts, and other files referenced by your HTML.', 'vibepresto'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Upload bundle', 'vibepresto')); ?>
            </form>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    const radios = document.querySelectorAll('input[name="upload_mode"]');
    const zipRows = document.querySelectorAll('.vibepresto-mode-zip');
    const separateRows = document.querySelectorAll('.vibepresto-mode-separate');

    function updateMode() {
        const selected = document.querySelector('input[name="upload_mode"]:checked');
        const zipActive = selected && selected.value === 'zip';

        zipRows.forEach((row) => {
            row.style.display = zipActive ? '' : 'none';
        });
        separateRows.forEach((row) => {
            row.style.display = zipActive ? 'none' : '';
        });
    }

    radios.forEach((radio) => radio.addEventListener('change', updateMode));
    updateMode();
});
</script>
