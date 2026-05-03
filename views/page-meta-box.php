<?php
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="vibepresto-editor-panel">
    <style>
        .vibepresto-editor-panel {
            margin: 8px 0;
        }
        .vibepresto-editor-hero {
            padding: 20px 22px;
            border: 1px solid #d9d4c7;
            border-radius: 16px;
            background: linear-gradient(135deg, #fff8ee 0%, #f4ece1 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.65);
        }
        .vibepresto-editor-hero h3 {
            margin: 0 0 10px;
            font-size: 20px;
        }
        .vibepresto-editor-hero p {
            margin: 0;
            max-width: 70ch;
        }
        .vibepresto-editor-notice,
        .vibepresto-active-state,
        .vibepresto-editor-section {
            margin-top: 18px;
            padding: 18px;
            border-radius: 14px;
            border: 1px solid #dcdcde;
            background: #fff;
        }
        .vibepresto-editor-notice.is-success,
        .vibepresto-active-state {
            border-color: #9fd0ad;
            background: #f3fbf5;
        }
        .vibepresto-editor-notice.is-error {
            border-color: #e5a4a4;
            background: #fff5f5;
        }
        .vibepresto-active-badge {
            display: inline-block;
            margin-bottom: 10px;
            padding: 5px 10px;
            border-radius: 999px;
            background: #1f7a3f;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .vibepresto-active-state h4,
        .vibepresto-editor-section h4 {
            margin: 0 0 10px;
            font-size: 16px;
        }
        .vibepresto-active-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }
        .vibepresto-active-meta div {
            padding: 12px;
            border-radius: 12px;
            background: rgba(31, 122, 63, 0.06);
        }
        .vibepresto-active-meta strong,
        .vibepresto-field strong {
            display: block;
            margin-bottom: 6px;
        }
        .vibepresto-assignment-row {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) auto;
            gap: 12px;
            align-items: end;
        }
        .vibepresto-field {
            margin-bottom: 14px;
        }
        .vibepresto-field input[type="text"],
        .vibepresto-field input[type="file"],
        .vibepresto-field select {
            width: 100%;
            max-width: 100%;
        }
        .vibepresto-radio-group {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin: 8px 0 0;
        }
        .vibepresto-upload-grid-page .vibepresto-assign-field {
            margin-top: 4px;
        }
        .vibepresto-checkbox {
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .vibepresto-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }
        @media (max-width: 782px) {
            .vibepresto-assignment-row {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="vibepresto-editor-hero">
        <h3><?php echo esc_html__('Page Takeover Control', 'vibepresto'); ?></h3>
        <p><?php echo esc_html__('Use VibePresto to replace this page with your uploaded HTML, CSS, and JS. When active, the normal WordPress theme content for this page is bypassed.', 'vibepresto'); ?></p>
    </div>

    <?php if ($notice) : ?>
        <div class="vibepresto-editor-notice <?php echo $notice['type'] === 'success' ? 'is-success' : 'is-error'; ?>">
            <?php echo esc_html($notice['message']); ?>
        </div>
    <?php endif; ?>

    <?php if ($active_bundle) : ?>
        <div class="vibepresto-active-state">
            <span class="vibepresto-active-badge"><?php echo esc_html__('Bundle Active on This Page', 'vibepresto'); ?></span>
            <h4><?php echo esc_html($active_bundle['title']); ?></h4>
            <p><?php echo esc_html__('Visitors are currently seeing the uploaded takeover bundle instead of the normal WordPress page template.', 'vibepresto'); ?></p>
            <div class="vibepresto-active-meta">
                <div>
                    <strong><?php echo esc_html__('Mode', 'vibepresto'); ?></strong>
                    <span><?php echo esc_html($active_bundle['mode']); ?></span>
                </div>
                <div>
                    <strong><?php echo esc_html__('Entry HTML', 'vibepresto'); ?></strong>
                    <code><?php echo esc_html($active_bundle['entry_html']); ?></code>
                </div>
                <div>
                    <strong><?php echo esc_html__('Frontend URL', 'vibepresto'); ?></strong>
                    <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open takeover page', 'vibepresto'); ?></a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="vibepresto-editor-section">
        <h4><?php echo esc_html__('Assign Existing Bundle', 'vibepresto'); ?></h4>
        <p><?php echo esc_html__('Choose which uploaded bundle should control this page. Save or update the page after changing this selection.', 'vibepresto'); ?></p>
        <div class="vibepresto-assignment-row">
            <div class="vibepresto-field">
                <label for="vibepresto_bundle_id" class="screen-reader-text"><?php echo esc_html__('Takeover bundle', 'vibepresto'); ?></label>
                <select name="vibepresto_bundle_id" id="vibepresto_bundle_id" class="widefat">
                    <option value="0"><?php echo esc_html__('Use normal WordPress rendering', 'vibepresto'); ?></option>
                    <?php foreach ($bundles as $bundle) : ?>
                        <option value="<?php echo esc_attr((string) $bundle['id']); ?>" <?php selected($selected_bundle_id, $bundle['id']); ?>>
                            <?php echo esc_html($bundle['title'] . ' (' . $bundle['mode'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="vibepresto-actions">
                <a class="button button-secondary" href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Preview current page', 'vibepresto'); ?></a>
                <a class="button button-link" href="<?php echo esc_url(admin_url('admin.php?page=vibepresto')); ?>"><?php echo esc_html__('Manage all bundles', 'vibepresto'); ?></a>
            </div>
        </div>
    </div>

    <div class="vibepresto-editor-section">
        <h4><?php echo esc_html__('Upload New Bundle From This Page', 'vibepresto'); ?></h4>
        <p><?php echo esc_html__('Upload a new ZIP bundle or separate HTML/CSS/JS files right here. You can assign the new bundle to this page immediately after upload.', 'vibepresto'); ?></p>
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
</div>
