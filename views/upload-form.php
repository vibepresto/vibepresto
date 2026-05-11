<?php
if (! defined('ABSPATH')) {
    exit;
}

$form_variant = $form_variant ?? 'admin';
$submit_label = $submit_label ?? __('Upload bundle', 'vibepresto');
$show_assign_checkbox = $show_assign_checkbox ?? false;
$default_assign_checked = $default_assign_checked ?? false;
$current_page_id = $current_page_id ?? 0;
$use_standalone_form = $use_standalone_form ?? true;
?>
<?php if ($use_standalone_form) : ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="vibepresto-upload-form" data-vibepresto-upload-form>
<?php else : ?>
<div class="vibepresto-upload-form" data-vibepresto-upload-form>
<?php endif; ?>
    <input type="hidden" name="action" value="vibepresto_create_bundle">
    <?php if ($current_page_id > 0) : ?>
        <input type="hidden" name="page_id" value="<?php echo esc_attr((string) $current_page_id); ?>">
    <?php endif; ?>
    <?php wp_nonce_field('vibepresto_create_bundle'); ?>

    <div class="vibepresto-upload-grid vibepresto-upload-grid-<?php echo esc_attr($form_variant); ?>">
        <div class="vibepresto-field">
            <label for="display_name_<?php echo esc_attr($form_variant); ?>"><strong><?php echo esc_html__('Display name', 'vibepresto'); ?></strong></label>
            <input id="display_name_<?php echo esc_attr($form_variant); ?>" name="display_name" type="text" class="regular-text" placeholder="<?php echo esc_attr__('Landing page bundle', 'vibepresto'); ?>">
            <p class="description"><?php echo esc_html__('Used as the lineage name for new bundles. Updates to an already-assigned page keep the existing lineage name.', 'vibepresto'); ?></p>
        </div>

        <div class="vibepresto-field">
            <span><strong><?php echo esc_html__('Upload mode', 'vibepresto'); ?></strong></span>
            <fieldset class="vibepresto-radio-group">
                <label><input type="radio" name="upload_mode" value="zip" checked> <?php echo esc_html__('ZIP bundle', 'vibepresto'); ?></label>
                <label><input type="radio" name="upload_mode" value="separate"> <?php echo esc_html__('Separate files', 'vibepresto'); ?></label>
            </fieldset>
        </div>

        <div class="vibepresto-mode vibepresto-mode-zip vibepresto-field">
            <label for="bundle_zip_<?php echo esc_attr($form_variant); ?>"><strong><?php echo esc_html__('ZIP bundle', 'vibepresto'); ?></strong></label>
            <input id="bundle_zip_<?php echo esc_attr($form_variant); ?>" name="bundle_zip" type="file" accept=".zip">
            <p class="description"><?php echo esc_html__('Best for exported landing pages with nested folders and assets.', 'vibepresto'); ?></p>
        </div>

        <div class="vibepresto-mode vibepresto-mode-separate vibepresto-field" style="display:none;">
            <label for="bundle_html_<?php echo esc_attr($form_variant); ?>"><strong><?php echo esc_html__('HTML file', 'vibepresto'); ?></strong></label>
            <input id="bundle_html_<?php echo esc_attr($form_variant); ?>" name="bundle_html" type="file" accept=".html,.htm">
        </div>

        <div class="vibepresto-mode vibepresto-mode-separate vibepresto-field" style="display:none;">
            <label for="bundle_css_<?php echo esc_attr($form_variant); ?>"><strong><?php echo esc_html__('CSS file', 'vibepresto'); ?></strong></label>
            <input id="bundle_css_<?php echo esc_attr($form_variant); ?>" name="bundle_css" type="file" accept=".css">
        </div>

        <div class="vibepresto-mode vibepresto-mode-separate vibepresto-field" style="display:none;">
            <label for="bundle_js_<?php echo esc_attr($form_variant); ?>"><strong><?php echo esc_html__('JS file', 'vibepresto'); ?></strong></label>
            <input id="bundle_js_<?php echo esc_attr($form_variant); ?>" name="bundle_js" type="file" accept=".js">
        </div>

        <div class="vibepresto-mode vibepresto-mode-separate vibepresto-field" style="display:none;">
            <label for="bundle_assets_<?php echo esc_attr($form_variant); ?>"><strong><?php echo esc_html__('Extra assets', 'vibepresto'); ?></strong></label>
            <input id="bundle_assets_<?php echo esc_attr($form_variant); ?>" name="bundle_assets[]" type="file" multiple>
            <p class="description"><?php echo esc_html__('Optional images, fonts, and other files referenced by your HTML.', 'vibepresto'); ?></p>
        </div>

        <?php if ($show_assign_checkbox) : ?>
            <div class="vibepresto-field vibepresto-assign-field">
                <label class="vibepresto-checkbox">
                    <input type="checkbox" name="assign_to_page" value="1" <?php checked($default_assign_checked); ?>>
                    <span><?php echo esc_html__('Assign the uploaded bundle to this page immediately', 'vibepresto'); ?></span>
                </label>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($use_standalone_form) : ?>
        <?php submit_button($submit_label, 'primary', 'submit', false); ?>
    <?php else : ?>
        <button
            type="submit"
            class="button button-primary"
            formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            formmethod="post"
            formenctype="multipart/form-data"
            formnovalidate
        >
            <?php echo esc_html($submit_label); ?>
        </button>
    <?php endif; ?>

<?php if ($use_standalone_form) : ?>
</form>
<?php else : ?>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-vibepresto-upload-form]').forEach(function (form) {
        var radios = form.querySelectorAll('input[name="upload_mode"]');
        var zipRows = form.querySelectorAll('.vibepresto-mode-zip');
        var separateRows = form.querySelectorAll('.vibepresto-mode-separate');

        function updateMode() {
            var selected = form.querySelector('input[name="upload_mode"]:checked');
            var zipActive = selected && selected.value === 'zip';

            zipRows.forEach(function (row) {
                row.style.display = zipActive ? '' : 'none';
            });

            separateRows.forEach(function (row) {
                row.style.display = zipActive ? 'none' : '';
            });
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', updateMode);
        });

        updateMode();
    });
});
</script>
