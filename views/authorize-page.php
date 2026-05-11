<?php
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php echo esc_html__('Authorize VibePresto CLI', 'vibepresto'); ?></h1>

    <?php if ($notice) : ?>
        <div class="notice notice-<?php echo $notice['type'] === 'success' ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo esc_html($notice['message']); ?></p>
        </div>
    <?php endif; ?>

    <?php if (! $device) : ?>
        <div class="notice notice-info">
            <p><?php echo esc_html__('Open this screen from a VibePresto CLI authorization link, or start a new login request from the CLI first.', 'vibepresto'); ?></p>
        </div>
    <?php else : ?>
        <div class="card" style="max-width:720px;">
            <h2><?php echo esc_html__('Authorization Request', 'vibepresto'); ?></h2>
            <p><?php echo esc_html__('A local tool wants permission to upload bundles and assign pages through the VibePresto API.', 'vibepresto'); ?></p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Client', 'vibepresto'); ?></th>
                        <td><?php echo esc_html($device['client_name']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Machine', 'vibepresto'); ?></th>
                        <td><?php echo esc_html($device['machine_name'] ?: __('Unknown machine', 'vibepresto')); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('User Code', 'vibepresto'); ?></th>
                        <td><code><?php echo esc_html($device['user_code']); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Scope', 'vibepresto'); ?></th>
                        <td><code><?php echo esc_html(implode(', ', $device['scope'])); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Status', 'vibepresto'); ?></th>
                        <td><?php echo esc_html(ucfirst((string) $device['status'])); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Expires', 'vibepresto'); ?></th>
                        <td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $device['expires_at'])); ?> UTC</td>
                    </tr>
                </tbody>
            </table>

            <?php if ($device['status'] === 'pending' && (int) $device['expires_at'] >= time()) : ?>
                <p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:12px;">
                        <input type="hidden" name="action" value="vibepresto_authorize_device">
                        <input type="hidden" name="device_code" value="<?php echo esc_attr($device['device_code']); ?>">
                        <?php wp_nonce_field('vibepresto_authorize_device'); ?>
                        <?php submit_button(__('Authorize CLI', 'vibepresto'), 'primary', 'submit', false); ?>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                        <input type="hidden" name="action" value="vibepresto_deny_device">
                        <input type="hidden" name="device_code" value="<?php echo esc_attr($device['device_code']); ?>">
                        <?php wp_nonce_field('vibepresto_deny_device'); ?>
                        <?php submit_button(__('Deny', 'vibepresto'), 'secondary', 'submit', false); ?>
                    </form>
                </p>
            <?php elseif ($device['status'] === 'approved') : ?>
                <div class="notice notice-success inline">
                    <p><?php echo esc_html__('Authorization approved. The terminal can continue polling, or you can copy this completion code back into the CLI if needed.', 'vibepresto'); ?></p>
                </div>
                <p>
                    <strong><?php echo esc_html__('Completion Code', 'vibepresto'); ?></strong><br>
                    <code style="font-size:16px;"><?php echo esc_html($device['completion_code']); ?></code>
                </p>
            <?php elseif ($device['status'] === 'completed') : ?>
                <div class="notice notice-success inline">
                    <p><?php echo esc_html__('This authorization request has already been completed.', 'vibepresto'); ?></p>
                </div>
            <?php elseif ($device['status'] === 'denied') : ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html__('This authorization request was denied.', 'vibepresto'); ?></p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html__('This authorization request has expired.', 'vibepresto'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
