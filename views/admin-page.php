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

    <p><?php echo esc_html__('Use the admin UI for manual uploads, or authorize the VibePresto CLI so local tools and LLM-driven agents can upload bundles through the machine API.', 'vibepresto'); ?></p>

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
            <h2><?php echo esc_html__('Bundle Lineages', 'vibepresto'); ?></h2>
            <?php if (! $lineages) : ?>
                <p><?php echo esc_html__('No bundles uploaded yet.', 'vibepresto'); ?></p>
            <?php else : ?>
                <?php foreach ($lineages as $lineage) : ?>
                    <div style="border:1px solid #dcdcde;border-radius:6px;padding:16px;margin-bottom:16px;">
                        <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;">
                            <div>
                                <h3 style="margin-top:0;"><?php echo esc_html($lineage['lineage_name']); ?></h3>
                                <p class="description" style="margin:0;">
                                    <?php
                                    echo esc_html(sprintf(
                                        __('%1$d versions. Current: %2$s', 'vibepresto'),
                                        (int) $lineage['version_count'],
                                        $lineage['current_version']['version_label']
                                    ));
                                    ?>
                                </p>
                            </div>
                            <div class="description">
                                <?php echo esc_html(get_date_from_gmt($lineage['updated_at'], 'Y-m-d H:i')); ?>
                            </div>
                        </div>

                        <table class="widefat striped" style="margin-top:12px;">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Version', 'vibepresto'); ?></th>
                                    <th><?php echo esc_html__('Mode', 'vibepresto'); ?></th>
                                    <th><?php echo esc_html__('Entry HTML', 'vibepresto'); ?></th>
                                    <th><?php echo esc_html__('Updated', 'vibepresto'); ?></th>
                                    <th><?php echo esc_html__('Status', 'vibepresto'); ?></th>
                                    <th><?php echo esc_html__('Action', 'vibepresto'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lineage['versions'] as $bundle) : ?>
                                    <tr>
                                        <td><?php echo esc_html($bundle['version_label']); ?></td>
                                        <td><?php echo esc_html($bundle['mode']); ?></td>
                                        <td><code><?php echo esc_html($bundle['entry_html']); ?></code></td>
                                        <td><?php echo esc_html(get_date_from_gmt($bundle['updated_at'], 'Y-m-d H:i')); ?></td>
                                        <td>
                                            <?php echo ! empty($bundle['is_current']) ? esc_html__('Current', 'vibepresto') : esc_html__('Archived', 'vibepresto'); ?>
                                        </td>
                                        <td>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this bundle version?', 'vibepresto')); ?>');">
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
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="max-width:none;margin-top:24px;">
        <h2><?php echo esc_html__('CLI Sessions', 'vibepresto'); ?></h2>
        <p><?php echo esc_html__('Approved VibePresto CLI sessions can upload bundles and assign them to pages through the plugin API.', 'vibepresto'); ?></p>
        <p>
            <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=vibepresto-authorize')); ?>">
                <?php echo esc_html__('Open CLI approval page', 'vibepresto'); ?>
            </a>
        </p>

        <?php if (! $sessions) : ?>
            <p><?php echo esc_html__('No CLI sessions have been approved yet.', 'vibepresto'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Client', 'vibepresto'); ?></th>
                        <th><?php echo esc_html__('WordPress User', 'vibepresto'); ?></th>
                        <th><?php echo esc_html__('Scope', 'vibepresto'); ?></th>
                        <th><?php echo esc_html__('Last Used', 'vibepresto'); ?></th>
                        <th><?php echo esc_html__('Status', 'vibepresto'); ?></th>
                        <th><?php echo esc_html__('Action', 'vibepresto'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $session) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($session['client_name']); ?></strong>
                                <?php if (! empty($session['machine_name'])) : ?>
                                    <div class="description"><?php echo esc_html($session['machine_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($session['user_display_name']); ?></td>
                            <td><code><?php echo esc_html(implode(', ', $session['scope'])); ?></code></td>
                            <td>
                                <?php
                                echo $session['last_used_at'] > 0
                                    ? esc_html(gmdate('Y-m-d H:i', (int) $session['last_used_at']))
                                    : esc_html__('Never', 'vibepresto');
                                ?>
                            </td>
                            <td>
                                <?php if (! empty($session['revoked_at'])) : ?>
                                    <?php echo esc_html__('Revoked', 'vibepresto'); ?>
                                <?php elseif ((int) $session['refresh_expires_at'] < time()) : ?>
                                    <?php echo esc_html__('Expired', 'vibepresto'); ?>
                                <?php else : ?>
                                    <?php echo esc_html__('Active', 'vibepresto'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($session['revoked_at']) && (int) $session['refresh_expires_at'] >= time()) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="vibepresto_revoke_session">
                                        <input type="hidden" name="session_id" value="<?php echo esc_attr($session['session_id']); ?>">
                                        <?php wp_nonce_field('vibepresto_revoke_session'); ?>
                                        <?php submit_button(__('Revoke', 'vibepresto'), 'delete small', 'submit', false); ?>
                                    </form>
                                <?php else : ?>
                                    <span class="description"><?php echo esc_html__('No action needed', 'vibepresto'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
