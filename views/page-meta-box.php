<?php
if (! defined('ABSPATH')) {
    exit;
}
?>
<p><?php echo esc_html__('Assign a VibePresto bundle to let uploaded HTML fully replace this page on the front end.', 'vibepresto'); ?></p>
<p>
    <label for="vibepresto_bundle_id" class="screen-reader-text"><?php echo esc_html__('Takeover bundle', 'vibepresto'); ?></label>
    <select name="vibepresto_bundle_id" id="vibepresto_bundle_id" class="widefat">
        <option value="0"><?php echo esc_html__('Use normal WordPress rendering', 'vibepresto'); ?></option>
        <?php foreach ($bundles as $bundle) : ?>
            <option value="<?php echo esc_attr((string) $bundle['id']); ?>" <?php selected($selected_bundle_id, $bundle['id']); ?>>
                <?php echo esc_html($bundle['title'] . ' (' . $bundle['mode'] . ')'); ?>
            </option>
        <?php endforeach; ?>
    </select>
</p>
<?php if (! $bundles) : ?>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=vibepresto')); ?>"><?php echo esc_html__('Upload a bundle first.', 'vibepresto'); ?></a></p>
<?php endif; ?>
