<?php
if (! defined('ABSPATH')) {
    exit;
}
?>
<div>
    <p><?php echo esc_html__('Use VibePresto to replace this page with your uploaded HTML, CSS, and JS. When active, the normal WordPress theme content for this page is bypassed.', 'vibepresto'); ?></p>

    <?php if ($notice) : ?>
        <div class="<?php echo $notice['type'] === 'success' ? 'notice notice-success inline' : 'notice notice-error inline'; ?>">
            <p><?php echo esc_html($notice['message']); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($active_bundle) : ?>
        <p><strong><?php echo esc_html($active_bundle['version_label']); ?></strong></p>
        <p><?php echo esc_html__('Visitors are currently seeing the uploaded takeover bundle instead of the normal WordPress page template.', 'vibepresto'); ?></p>
        <p><strong><?php echo esc_html__('Lineage', 'vibepresto'); ?>:</strong> <?php echo esc_html($active_bundle['lineage_name']); ?></p>
        <p><strong><?php echo esc_html__('Version', 'vibepresto'); ?>:</strong> <?php echo esc_html((string) $active_bundle['version_number']); ?></p>
        <p><strong><?php echo esc_html__('Mode', 'vibepresto'); ?>:</strong> <?php echo esc_html($active_bundle['mode']); ?></p>
        <p><strong><?php echo esc_html__('Entry HTML', 'vibepresto'); ?>:</strong> <code><?php echo esc_html($active_bundle['entry_html']); ?></code></p>
        <p>
            <a class="button button-secondary" href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open page', 'vibepresto'); ?></a>
        </p>
    <?php endif; ?>

    <p>
        <label for="vibepresto_bundle_id"><strong><?php echo esc_html__('Assigned bundle', 'vibepresto'); ?></strong></label><br>
        <select name="vibepresto_bundle_id" id="vibepresto_bundle_id" class="widefat">
            <option value="0"><?php echo esc_html__('Use normal WordPress rendering', 'vibepresto'); ?></option>
            <?php foreach ($bundles as $bundle) : ?>
                <option value="<?php echo esc_attr((string) $bundle['id']); ?>" <?php selected($selected_bundle_id, $bundle['id']); ?>>
                    <?php echo esc_html($bundle['version_label'] . ' (' . $bundle['mode'] . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p>
        <a class="button button-secondary" href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Preview current page', 'vibepresto'); ?></a>
        <a class="button button-link" href="<?php echo esc_url(admin_url('admin.php?page=vibepresto')); ?>"><?php echo esc_html__('Manage all bundles', 'vibepresto'); ?></a>
    </p>

    <hr>

    <p><strong><?php echo esc_html__('Upload new bundle', 'vibepresto'); ?></strong></p>
    <p><?php echo esc_html__('Upload a new ZIP bundle or separate HTML/CSS/JS files right here. If this page already has a bundle assigned, the upload becomes the next version in that same lineage.', 'vibepresto'); ?></p>
    <?php
    $form_variant = 'page';
    $submit_label = __('Upload and keep editing', 'vibepresto');
    $show_assign_checkbox = true;
    $default_assign_checked = true;
    $current_page_id = (int) $post->ID;
    $use_standalone_form = false;
    include VIBEPRESTO_PLUGIN_DIR . 'views/upload-form.php';
    ?>
</div>
