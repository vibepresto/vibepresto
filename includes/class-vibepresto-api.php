<?php

namespace VibePresto;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

class API
{
    private Bundle_Repository $bundles;

    private Auth_Store $auth;

    public function __construct(Bundle_Repository $bundles, Auth_Store $auth)
    {
        $this->bundles = $bundles;
        $this->auth = $auth;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('vibepresto/v1', '/auth/device', [
            'methods' => 'POST',
            'callback' => [$this, 'create_device_authorization'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/auth/token', [
            'methods' => 'POST',
            'callback' => [$this, 'exchange_token'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/auth/refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'refresh_token'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/auth/me', [
            'methods' => 'GET',
            'callback' => [$this, 'whoami'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/auth/revoke', [
            'methods' => 'POST',
            'callback' => [$this, 'revoke_session'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'search_pages'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/bundles', [
            'methods' => 'GET',
            'callback' => [$this, 'list_bundles'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/bundles', [
            'methods' => 'POST',
            'callback' => [$this, 'upload_bundle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function create_device_authorization(WP_REST_Request $request): WP_REST_Response
    {
        $this->auth->cleanup_expired();

        $client_name = sanitize_text_field((string) ($request->get_param('client_name') ?: 'VibePresto CLI'));
        $machine_name = sanitize_text_field((string) ($request->get_param('machine_name') ?: ''));
        $scope = $request->get_param('scope');
        if (! is_array($scope)) {
            $scope = ['bundles:write', 'pages:read', 'pages:assign'];
        }

        $device = $this->auth->create_device_authorization($client_name, $machine_name, $scope);

        $verification_url = admin_url('admin.php?page=vibepresto-authorize&device_code=' . rawurlencode($device['device_code']) . '&user_code=' . rawurlencode($device['user_code']));

        return $this->success([
            'device_code' => $device['device_code'],
            'user_code' => $device['user_code'],
            'verification_url' => $verification_url,
            'verification_url_complete' => $verification_url,
            'expires_in' => max(0, (int) $device['expires_at'] - time()),
            'interval' => (int) $device['interval'],
        ], 201);
    }

    public function exchange_token(WP_REST_Request $request): WP_REST_Response
    {
        $this->auth->cleanup_expired();

        $device_code = $request->get_param('device_code');
        $completion_code = $request->get_param('completion_code');
        $result = $this->auth->exchange_device_authorization(
            is_string($device_code) ? sanitize_text_field($device_code) : null,
            is_string($completion_code) ? strtoupper(sanitize_text_field($completion_code)) : null
        );

        if (is_wp_error($result)) {
            return $this->from_error($result);
        }

        return $this->success($result);
    }

    public function refresh_token(WP_REST_Request $request): WP_REST_Response
    {
        $refresh_token = $request->get_param('refresh_token');
        if (! is_string($refresh_token) || $refresh_token === '') {
            return $this->error('invalid_request', __('A refresh token is required.', 'vibepresto'), 400);
        }

        $result = $this->auth->refresh_access_token(sanitize_text_field($refresh_token));
        if (is_wp_error($result)) {
            return $this->from_error($result);
        }

        return $this->success($result);
    }

    public function whoami(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        return $this->success([
            'site_url' => home_url('/'),
            'session_id' => $auth['session_id'],
            'user_id' => $auth['user']->ID,
            'user_display_name' => $auth['user']->display_name,
            'client_name' => $auth['session']['client_name'],
            'machine_name' => $auth['session']['machine_name'],
            'scope' => $auth['session']['scope'],
            'access_expires_at' => (int) $auth['session']['access_expires_at'],
            'refresh_expires_at' => (int) $auth['session']['refresh_expires_at'],
        ]);
    }

    public function revoke_session(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        $session_id = sanitize_text_field((string) ($request->get_param('session_id') ?: $auth['session_id']));
        if ($session_id !== $auth['session_id'] && ! current_user_can('manage_options')) {
            return $this->error('forbidden', __('You do not have permission to revoke that session.', 'vibepresto'), 403);
        }

        if (! $this->auth->revoke_session($session_id)) {
            return $this->error('not_found', __('That session could not be found.', 'vibepresto'), 404);
        }

        return $this->success([
            'session_id' => $session_id,
            'revoked' => true,
        ]);
    }

    public function search_pages(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        $query = sanitize_text_field((string) $request->get_param('q'));
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 20,
            's' => $query,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $items = array_map(static function (WP_Post $page): array {
            return [
                'id' => (int) $page->ID,
                'title' => $page->post_title,
                'slug' => $page->post_name,
                'status' => $page->post_status,
                'url' => get_permalink($page->ID),
            ];
        }, $pages);

        return $this->success([
            'items' => $items,
        ]);
    }

    public function list_bundles(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        $bundles = array_map(static function (array $bundle): array {
            return [
                'bundle_id' => $bundle['id'],
                'bundle_title' => $bundle['title'],
                'mode' => $bundle['mode'],
                'entry_html' => $bundle['entry_html'],
                'updated_at' => $bundle['updated_at'],
            ];
        }, $this->bundles->all());

        return $this->success([
            'items' => $bundles,
        ]);
    }

    public function upload_bundle(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        if (! current_user_can('manage_options')) {
            return $this->error('forbidden', __('Only administrators can upload bundles.', 'vibepresto'), 403);
        }

        $mode = sanitize_key((string) $request->get_param('mode'));
        $display_name = sanitize_text_field((string) $request->get_param('display_name'));
        $assign_page_id = absint((string) ($request->get_param('assign_page_id') ?: 0));

        try {
            if ($mode === 'zip') {
                $bundle_id = $this->bundles->create_from_zip($this->file_param('bundle_zip'), $display_name);
            } elseif ($mode === 'separate') {
                $bundle_id = $this->bundles->create_from_files(
                    $this->file_param('bundle_html'),
                    $this->optional_file_param('bundle_css'),
                    $this->optional_file_param('bundle_js'),
                    $this->optional_assets_param(),
                    $display_name
                );
            } else {
                return $this->error('invalid_request', __('Choose a valid upload mode.', 'vibepresto'), 400);
            }
        } catch (\RuntimeException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 400);
        }

        if (is_wp_error($bundle_id)) {
            return $this->from_error($bundle_id, 400);
        }

        $assigned_page_id = null;
        $assigned_page_url = null;
        if ($assign_page_id > 0) {
            $page = get_post($assign_page_id);
            if (! $page instanceof WP_Post || $page->post_type !== 'page') {
                return $this->error('invalid_request', __('The requested page could not be found.', 'vibepresto'), 400);
            }

            $this->bundles->assign_to_page($assign_page_id, (int) $bundle_id);
            $assigned_page_id = $assign_page_id;
            $assigned_page_url = get_permalink($assign_page_id);
        }

        $bundle = $this->bundles->find((int) $bundle_id);

        return $this->success([
            'bundle_id' => (int) $bundle_id,
            'bundle_title' => $bundle['title'] ?? '',
            'mode' => $bundle['mode'] ?? $mode,
            'entry_html' => $bundle['entry_html'] ?? '',
            'assigned_page_id' => $assigned_page_id,
            'assigned_page_url' => $assigned_page_url,
        ], 201);
    }

    private function require_bearer_auth(WP_REST_Request $request)
    {
        $header = $request->get_header('authorization');
        if (! is_string($header) || ! preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return new WP_Error('invalid_token', __('A bearer token is required.', 'vibepresto'));
        }

        $auth = $this->auth->authenticate_access_token(trim($matches[1]));
        if (is_wp_error($auth)) {
            return $auth;
        }

        wp_set_current_user($auth['user']->ID);

        return $auth;
    }

    private function file_param(string $key): array
    {
        $files = $this->rest_files();
        return is_array($files[$key] ?? null) ? $files[$key] : [];
    }

    private function optional_file_param(string $key): array
    {
        $files = $this->rest_files();
        return is_array($files[$key] ?? null) ? $files[$key] : [
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ];
    }

    private function optional_assets_param(): array
    {
        $files = $this->rest_files();
        if (isset($files['bundle_assets'])) {
            return $files['bundle_assets'];
        }

        if (isset($files['bundle_assets[]'])) {
            return $files['bundle_assets[]'];
        }

        return [
            'name' => [],
            'type' => [],
            'tmp_name' => [],
            'error' => [],
            'size' => [],
        ];
    }

    private function rest_files(): array
    {
        return is_array($_FILES ?? null) ? $_FILES : [];
    }

    private function success(array $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'data' => $data,
        ], $status);
    }

    private function error(string $code, string $message, int $status, array $details = []): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }

    private function from_error(WP_Error $error, ?int $status = null): WP_REST_Response
    {
        $code = $error->get_error_code();
        $message = $error->get_error_message();
        $status = $status ?? $this->status_from_error_code($code);

        return $this->error($code, $message, $status);
    }

    private function status_from_error_code(string $code): int
    {
        if (in_array($code, ['authorization_pending', 'slow_down', 'invalid_request', 'validation_error'], true)) {
            return 400;
        }

        if (in_array($code, ['invalid_token', 'invalid_grant', 'expired_token', 'access_denied'], true)) {
            return 401;
        }

        if ($code === 'forbidden') {
            return 403;
        }

        if ($code === 'not_found') {
            return 404;
        }

        return 500;
    }
}
