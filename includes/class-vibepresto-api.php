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
            'callback' => [$this, 'list_pages'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/pages', [
            'methods' => 'POST',
            'callback' => [$this, 'create_page'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/pages/(?P<id>\d+)/status', [
            'methods' => 'POST',
            'callback' => [$this, 'update_page_status'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/pages/(?P<id>\d+)/homepage', [
            'methods' => 'POST',
            'callback' => [$this, 'set_page_as_homepage'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/pages/(?P<id>\d+)/bundle-rollback', [
            'methods' => 'POST',
            'callback' => [$this, 'rollback_page_bundle'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/bundles', [
            'methods' => 'GET',
            'callback' => [$this, 'list_bundles'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/bundles/(?P<id>\d+)/versions', [
            'methods' => 'GET',
            'callback' => [$this, 'list_bundle_versions'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('vibepresto/v1', '/bundles/versions/(?P<id>\d+)/promote', [
            'methods' => 'POST',
            'callback' => [$this, 'promote_bundle_version'],
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
            $scope = ['bundles:write', 'pages:read', 'pages:write', 'pages:assign', 'site:write'];
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

    public function list_pages(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        $query = sanitize_text_field((string) $request->get_param('q'));
        $status = sanitize_key((string) $request->get_param('status'));
        $allowed_statuses = ['publish', 'draft', 'pending', 'private', 'future'];
        $post_status = in_array($status, $allowed_statuses, true)
            ? [$status]
            : ['publish', 'draft', 'pending', 'private', 'future'];
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => $post_status,
            'posts_per_page' => -1,
            's' => $query,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $homepage_id = (int) get_option('page_on_front');
        $items = array_map(static function (WP_Post $page) use ($homepage_id): array {
            $assigned_bundle_id = (int) get_post_meta($page->ID, Bundle_Repository::PAGE_META_KEY, true);
            return [
                'id' => (int) $page->ID,
                'title' => $page->post_title,
                'slug' => $page->post_name,
                'status' => $page->post_status,
                'url' => get_permalink($page->ID),
                'is_homepage' => $homepage_id > 0 && $homepage_id === (int) $page->ID,
                'assigned_bundle_id' => $assigned_bundle_id,
            ];
        }, $pages);

        return $this->success([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    public function create_page(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        if (! current_user_can('manage_options')) {
            return $this->error('forbidden', __('Only administrators can create pages.', 'vibepresto'), 403);
        }

        $title = sanitize_text_field((string) $request->get_param('title'));
        if ($title === '') {
            return $this->error('invalid_request', __('A page title is required.', 'vibepresto'), 400);
        }

        $status = sanitize_key((string) ($request->get_param('status') ?: 'draft'));
        $allowed_statuses = ['publish', 'draft', 'pending', 'private'];
        if (! in_array($status, $allowed_statuses, true)) {
            return $this->error('invalid_request', __('Choose a valid page status.', 'vibepresto'), 400);
        }

        $page_data = [
            'post_type' => 'page',
            'post_title' => $title,
            'post_status' => $status,
            'post_content' => wp_kses_post((string) $request->get_param('content')),
        ];

        $slug = sanitize_title((string) $request->get_param('slug'));
        if ($slug !== '') {
            $page_data['post_name'] = $slug;
        }

        $page_id = wp_insert_post($page_data, true);
        if (is_wp_error($page_id)) {
            return $this->from_error($page_id, 400);
        }

        $page = get_post((int) $page_id);
        if (! $page instanceof WP_Post) {
            return $this->error('server_error', __('The page was created but could not be loaded.', 'vibepresto'), 500);
        }

        return $this->success($this->page_payload($page), 201);
    }

    public function update_page_status(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        if (! current_user_can('manage_options')) {
            return $this->error('forbidden', __('Only administrators can change page status.', 'vibepresto'), 403);
        }

        $page = $this->page_from_request($request);
        if (is_wp_error($page)) {
            return $this->from_error($page, 404);
        }

        $status = sanitize_key((string) $request->get_param('status'));
        $allowed_statuses = ['publish', 'draft', 'pending', 'private'];
        if (! in_array($status, $allowed_statuses, true)) {
            return $this->error('invalid_request', __('Choose a valid page status.', 'vibepresto'), 400);
        }

        $updated = wp_update_post([
            'ID' => (int) $page->ID,
            'post_status' => $status,
        ], true);

        if (is_wp_error($updated)) {
            return $this->from_error($updated, 400);
        }

        $fresh_page = get_post((int) $page->ID);
        if (! $fresh_page instanceof WP_Post) {
            return $this->error('server_error', __('The page status was updated but could not be reloaded.', 'vibepresto'), 500);
        }

        return $this->success($this->page_payload($fresh_page));
    }

    public function set_page_as_homepage(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        if (! current_user_can('manage_options')) {
            return $this->error('forbidden', __('Only administrators can change the homepage.', 'vibepresto'), 403);
        }

        $page = $this->page_from_request($request);
        if (is_wp_error($page)) {
            return $this->from_error($page, 404);
        }

        update_option('show_on_front', 'page');
        update_option('page_on_front', (int) $page->ID);

        return $this->success([
            'page_id' => (int) $page->ID,
            'page_title' => $page->post_title,
            'page_status' => $page->post_status,
            'page_url' => get_permalink($page->ID),
            'is_homepage' => true,
        ]);
    }

    public function rollback_page_bundle(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        if (! current_user_can('manage_options')) {
            return $this->error('forbidden', __('Only administrators can roll back bundle versions.', 'vibepresto'), 403);
        }

        $page = $this->page_from_request($request);
        if (is_wp_error($page)) {
            return $this->from_error($page, 404);
        }

        $bundle_version_id = absint((string) ($request->get_param('bundle_version_id') ?: 0));
        $version_number = absint((string) ($request->get_param('version_number') ?: 0));
        $assigned_bundle_id = $this->bundles->get_assigned_bundle_id((int) $page->ID);
        if ($assigned_bundle_id < 1) {
            return $this->error('invalid_request', __('That page does not currently have an assigned bundle.', 'vibepresto'), 400);
        }
        $assigned_lineage_id = $this->bundles->resolve_lineage_id($assigned_bundle_id);

        $target = null;
        if ($bundle_version_id > 0) {
            $target = $this->bundles->find($bundle_version_id);
        } elseif ($version_number > 0) {
            $target = $this->bundles->find_version_by_number($assigned_bundle_id, $version_number);
        } else {
            return $this->error('invalid_request', __('Provide either a bundle version id or a version number.', 'vibepresto'), 400);
        }

        if (! $target) {
            return $this->error('not_found', __('The requested bundle version could not be found.', 'vibepresto'), 404);
        }

        if ((int) $target['lineage_id'] !== $assigned_lineage_id) {
            return $this->error('invalid_request', __('You can only roll back to a version in the page\'s current bundle lineage.', 'vibepresto'), 400);
        }

        $promoted = $this->bundles->promote_version((int) $target['id'], (int) $page->ID);
        if (is_wp_error($promoted)) {
            return $this->from_error($promoted);
        }

        return $this->success($this->bundle_version_payload($promoted, (int) $page->ID));
    }

    public function list_bundles(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        $bundles = array_map(static function (array $lineage): array {
            $current = $lineage['current_version'];
            return [
                'lineage_id' => $lineage['lineage_id'],
                'bundle_title' => $lineage['lineage_name'],
                'bundle_version_id' => $current['id'],
                'bundle_version_number' => $current['version_number'],
                'bundle_version_label' => $current['version_label'],
                'mode' => $current['mode'],
                'entry_html' => $current['entry_html'],
                'updated_at' => $lineage['updated_at'],
                'version_count' => $lineage['version_count'],
            ];
        }, $this->bundles->list_lineages());

        return $this->success([
            'items' => $bundles,
        ]);
    }

    public function list_bundle_versions(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        $lineage_id = absint((string) $request->get_param('id'));
        $versions = $this->bundles->versions_for_lineage($lineage_id);
        if (! $versions) {
            return $this->error('not_found', __('The requested bundle lineage could not be found.', 'vibepresto'), 404);
        }

        return $this->success([
            'lineage_id' => $this->bundles->resolve_lineage_id($lineage_id),
            'bundle_title' => $versions[0]['lineage_name'],
            'items' => array_map([$this, 'bundle_version_payload'], $versions),
        ]);
    }

    public function promote_bundle_version(WP_REST_Request $request): WP_REST_Response
    {
        $auth = $this->require_bearer_auth($request);
        if (is_wp_error($auth)) {
            return $this->from_error($auth);
        }

        if (! current_user_can('manage_options')) {
            return $this->error('forbidden', __('Only administrators can promote bundle versions.', 'vibepresto'), 403);
        }

        $bundle_id = absint((string) $request->get_param('id'));
        $page_id = absint((string) ($request->get_param('page_id') ?: 0));
        $promoted = $this->bundles->promote_version($bundle_id, $page_id);
        if (is_wp_error($promoted)) {
            return $this->from_error($promoted);
        }

        return $this->success($this->bundle_version_payload($promoted, $page_id));
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
        $lineage_id = absint((string) ($request->get_param('lineage_id') ?: 0));

        if ($assign_page_id > 0 && $lineage_id < 1) {
            $existing_bundle_id = $this->bundles->get_assigned_bundle_id($assign_page_id);
            if ($existing_bundle_id > 0) {
                $lineage_id = $this->bundles->resolve_lineage_id($existing_bundle_id);
            }
        }

        try {
            if ($mode === 'zip') {
                $bundle_id = $this->bundles->create_from_zip($this->file_param('bundle_zip'), $display_name, [
                    'lineage_id' => $lineage_id,
                    'source_page_id' => $assign_page_id,
                ]);
            } elseif ($mode === 'separate') {
                $bundle_id = $this->bundles->create_from_files(
                    $this->file_param('bundle_html'),
                    $this->optional_file_param('bundle_css'),
                    $this->optional_file_param('bundle_js'),
                    $this->optional_assets_param(),
                    $display_name,
                    [
                        'lineage_id' => $lineage_id,
                        'source_page_id' => $assign_page_id,
                    ]
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
            'bundle_title' => $bundle['lineage_name'] ?? '',
            'bundle_version_id' => $bundle['id'] ?? (int) $bundle_id,
            'bundle_version_number' => $bundle['version_number'] ?? 1,
            'bundle_version_label' => $bundle['version_label'] ?? '',
            'lineage_id' => $bundle['lineage_id'] ?? (int) $bundle_id,
            'lineage_name' => $bundle['lineage_name'] ?? '',
            'is_current' => $bundle['is_current'] ?? true,
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

    private function page_from_request(WP_REST_Request $request)
    {
        $page_id = absint((string) $request->get_param('id'));
        if ($page_id < 1) {
            return new WP_Error('not_found', __('The requested page could not be found.', 'vibepresto'));
        }

        $page = get_post($page_id);
        if (! $page instanceof WP_Post || $page->post_type !== 'page') {
            return new WP_Error('not_found', __('The requested page could not be found.', 'vibepresto'));
        }

        return $page;
    }

    private function page_payload(WP_Post $page): array
    {
        $page_id = (int) $page->ID;
        $assigned_bundle_id = $this->bundles->get_assigned_bundle_id($page_id);
        $assigned_bundle = $assigned_bundle_id > 0 ? $this->bundles->find($assigned_bundle_id) : null;

        return [
            'page_id' => $page_id,
            'page_title' => $page->post_title,
            'page_slug' => $page->post_name,
            'page_status' => $page->post_status,
            'page_url' => get_permalink($page_id),
            'is_homepage' => (int) get_option('page_on_front') === $page_id,
            'assigned_bundle_id' => $assigned_bundle_id,
            'assigned_bundle_title' => $assigned_bundle['lineage_name'] ?? '',
            'assigned_bundle_version_id' => $assigned_bundle['id'] ?? 0,
            'assigned_bundle_version_number' => $assigned_bundle['version_number'] ?? 0,
        ];
    }

    private function bundle_version_payload(array $bundle, int $page_id = 0): array
    {
        return [
            'bundle_id' => (int) $bundle['lineage_id'],
            'bundle_title' => $bundle['lineage_name'],
            'bundle_version_id' => (int) $bundle['id'],
            'bundle_version_number' => (int) $bundle['version_number'],
            'bundle_version_label' => $bundle['version_label'],
            'lineage_id' => (int) $bundle['lineage_id'],
            'lineage_name' => $bundle['lineage_name'],
            'mode' => $bundle['mode'],
            'entry_html' => $bundle['entry_html'],
            'is_current' => (bool) $bundle['is_current'],
            'source_page_id' => (int) $bundle['source_page_id'],
            'page_id' => $page_id,
        ];
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
