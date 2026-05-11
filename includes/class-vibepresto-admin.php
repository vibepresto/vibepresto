<?php

namespace VibePresto;

use RuntimeException;

if (! defined('ABSPATH')) {
    exit;
}

class Admin
{
    private Bundle_Repository $bundles;

    public function __construct(Bundle_Repository $bundles)
    {
        $this->bundles = $bundles;
    }

    public function register(): void
    {
        add_action('init', [$this, 'register_page_editor_support']);
        add_action('init', [$this, 'register_page_assignment_meta']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_vibepresto_create_bundle', [$this, 'handle_create_bundle']);
        add_action('admin_post_vibepresto_delete_bundle', [$this, 'handle_delete_bundle']);
        add_action('add_meta_boxes_page', [$this, 'register_page_meta_box']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        add_action('save_post_page', [$this, 'save_page_assignment']);
    }

    public function register_page_editor_support(): void
    {
        add_post_type_support('page', 'custom-fields');
    }

    public function register_page_assignment_meta(): void
    {
        register_post_meta('page', Bundle_Repository::PAGE_META_KEY, [
            'type' => 'integer',
            'single' => true,
            'default' => 0,
            'show_in_rest' => true,
            'sanitize_callback' => 'absint',
            'auth_callback' => static function (): bool {
                return current_user_can('manage_options');
            },
        ]);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('VibePresto', 'vibepresto'),
            __('VibePresto', 'vibepresto'),
            'manage_options',
            'vibepresto',
            [$this, 'render_admin_page'],
            'dashicons-layout'
        );
    }

    public function render_admin_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'vibepresto'));
        }

        $bundles = $this->bundles->all();
        $notice = $this->read_notice();
        include VIBEPRESTO_PLUGIN_DIR . 'views/admin-page.php';
    }

    public function handle_create_bundle(): void
    {
        $this->assert_admin_request('vibepresto_create_bundle');

        $mode = sanitize_key($_POST['upload_mode'] ?? '');
        $display_name = sanitize_text_field(wp_unslash($_POST['display_name'] ?? ''));
        $page_id = isset($_POST['page_id']) ? (int) $_POST['page_id'] : 0;
        $assign_to_page = ! empty($_POST['assign_to_page']) && $page_id > 0;

        try {
            if ($mode === 'zip') {
                $bundle_id = $this->bundles->create_from_zip($_FILES['bundle_zip'] ?? [], $display_name);
            } elseif ($mode === 'separate') {
                $bundle_id = $this->bundles->create_from_files(
                    $_FILES['bundle_html'] ?? [],
                    $_FILES['bundle_css'] ?? [],
                    $_FILES['bundle_js'] ?? [],
                    $_FILES['bundle_assets'] ?? [],
                    $display_name
                );
            } else {
                throw new RuntimeException(__('Choose a valid upload mode.', 'vibepresto'));
            }
        } catch (RuntimeException $exception) {
            $this->redirect_with_notice('error', $exception->getMessage(), $page_id);
        }

        if (is_wp_error($bundle_id)) {
            $this->redirect_with_notice('error', $bundle_id->get_error_message(), $page_id);
        }

        if ($assign_to_page && $this->bundles->find((int) $bundle_id)) {
            $this->bundles->assign_to_page($page_id, (int) $bundle_id);
        }

        $message = $assign_to_page
            ? __('Bundle uploaded and assigned to this page.', 'vibepresto')
            : __('Bundle uploaded successfully.', 'vibepresto');

        $this->redirect_with_notice('success', $message, $page_id);
    }

    public function handle_delete_bundle(): void
    {
        $this->assert_admin_request('vibepresto_delete_bundle');

        $bundle_id = isset($_POST['bundle_id']) ? (int) $_POST['bundle_id'] : 0;
        if ($bundle_id < 1 || ! $this->bundles->delete($bundle_id)) {
            $this->redirect_with_notice('error', __('Unable to delete that bundle.', 'vibepresto'));
        }

        $this->redirect_with_notice('success', __('Bundle deleted.', 'vibepresto'));
    }

    public function register_page_meta_box(): void
    {
        if (use_block_editor_for_post_type('page')) {
            return;
        }

        add_meta_box(
            'vibepresto-page-assignment',
            __('VibePresto Takeover', 'vibepresto'),
            [$this, 'render_page_meta_box'],
            'page',
            'side',
            'default'
        );
    }

    public function enqueue_block_editor_assets(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (! $screen || $screen->base !== 'post' || $screen->post_type !== 'page' || ! method_exists($screen, 'is_block_editor') || ! $screen->is_block_editor()) {
            return;
        }

        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $post = $post_id > 0 ? get_post($post_id) : null;
        if ($post && (! $post instanceof \WP_Post || $post->post_type !== 'page')) {
            return;
        }

        wp_register_script(
            'vibepresto-editor-sidebar',
            VIBEPRESTO_PLUGIN_URL . 'assets/editor-sidebar.js',
            ['wp-components', 'wp-compose', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-plugins'],
            VIBEPRESTO_VERSION,
            true
        );

        wp_add_inline_script(
            'vibepresto-editor-sidebar',
            'window.VibePrestoEditorSidebar = ' . wp_json_encode($this->get_editor_sidebar_data($post)) . ';',
            'before'
        );

        wp_enqueue_script('vibepresto-editor-sidebar');
    }

    public function render_page_meta_box(\WP_Post $post): void
    {
        if (! current_user_can('manage_options')) {
            echo '<p>' . esc_html__('Only administrators can assign takeovers.', 'vibepresto') . '</p>';
            return;
        }

        wp_nonce_field('vibepresto_save_assignment', 'vibepresto_assignment_nonce');

        $selected_bundle_id = $this->bundles->get_assigned_bundle_id((int) $post->ID);
        $bundles = $this->bundles->all();
        $active_bundle = $selected_bundle_id > 0 ? $this->bundles->find($selected_bundle_id) : null;
        $notice = $this->read_notice();

        include VIBEPRESTO_PLUGIN_DIR . 'views/page-meta-box.php';
    }

    public function save_page_assignment(int $post_id): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = $_POST['vibepresto_assignment_nonce'] ?? '';
        if (! wp_verify_nonce($nonce, 'vibepresto_save_assignment')) {
            return;
        }

        $bundle_id = isset($_POST['vibepresto_bundle_id']) ? (int) $_POST['vibepresto_bundle_id'] : 0;
        if ($bundle_id > 0 && $this->bundles->find($bundle_id)) {
            $this->bundles->assign_to_page($post_id, $bundle_id);
            return;
        }

        $this->bundles->clear_page_assignment($post_id);
    }

    private function assert_admin_request(string $nonce_action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'vibepresto'));
        }

        check_admin_referer($nonce_action);
    }

    private function redirect_with_notice(string $type, string $message, int $page_id = 0): void
    {
        $args = [
            'vibepresto_notice' => rawurlencode($message),
            'vibepresto_notice_type' => rawurlencode($type),
        ];

        if ($page_id > 0) {
            $url = add_query_arg($args, get_edit_post_link($page_id, 'url'));
        } else {
            $url = add_query_arg([
            'page' => 'vibepresto',
            ] + $args, admin_url('admin.php'));
        }

        wp_safe_redirect($url);
        exit;
    }

    private function read_notice(): ?array
    {
        if (empty($_GET['vibepresto_notice']) || empty($_GET['vibepresto_notice_type'])) {
            return null;
        }

        return [
            'message' => sanitize_text_field(rawurldecode(wp_unslash($_GET['vibepresto_notice']))),
            'type' => sanitize_key($_GET['vibepresto_notice_type']),
        ];
    }

    private function get_editor_sidebar_data(?\WP_Post $post): array
    {
        $post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
        $selected_bundle_id = $post_id > 0 ? $this->bundles->get_assigned_bundle_id($post_id) : 0;

        return [
            'postId' => $post_id,
            'postType' => 'page',
            'metaKey' => Bundle_Repository::PAGE_META_KEY,
            'selectedBundleId' => $selected_bundle_id,
            'bundles' => $this->bundles->all(),
            'notice' => $this->read_notice(),
            'links' => [
                'preview' => $post_id > 0 ? get_permalink($post_id) : '',
                'manageBundles' => admin_url('admin.php?page=vibepresto'),
                'adminPost' => admin_url('admin-post.php'),
            ],
            'nonces' => [
                'createBundle' => wp_create_nonce('vibepresto_create_bundle'),
            ],
            'strings' => [
                'panelTitle' => __('VibePresto Takeover', 'vibepresto'),
                'panelHelp' => __('Use VibePresto to replace this page with an uploaded HTML/CSS/JS bundle.', 'vibepresto'),
                'assignmentTab' => __('Assignment', 'vibepresto'),
                'uploadTab' => __('Upload', 'vibepresto'),
                'activeTitle' => __('Active bundle', 'vibepresto'),
                'activeDescription' => __('Visitors see this takeover bundle instead of the normal WordPress page template.', 'vibepresto'),
                'mode' => __('Mode', 'vibepresto'),
                'entryHtml' => __('Entry HTML', 'vibepresto'),
                'frontendUrl' => __('Frontend URL', 'vibepresto'),
                'openPage' => __('Open page', 'vibepresto'),
                'assignTitle' => __('Assigned bundle', 'vibepresto'),
                'assignHelp' => __('Choose which uploaded bundle should control this page. Save or update the page to apply changes.', 'vibepresto'),
                'normalRendering' => __('Use normal WordPress rendering', 'vibepresto'),
                'previewPage' => __('Preview current page', 'vibepresto'),
                'manageBundles' => __('Manage all bundles', 'vibepresto'),
                'uploadTitle' => __('Upload new bundle', 'vibepresto'),
                'uploadHelp' => __('Upload a ZIP bundle or separate HTML/CSS/JS files from the editor sidebar.', 'vibepresto'),
                'displayName' => __('Display name', 'vibepresto'),
                'displayNamePlaceholder' => __('Landing page bundle', 'vibepresto'),
                'uploadMode' => __('Upload mode', 'vibepresto'),
                'zipBundle' => __('ZIP bundle', 'vibepresto'),
                'separateFiles' => __('Separate files', 'vibepresto'),
                'htmlFile' => __('HTML file', 'vibepresto'),
                'cssFile' => __('CSS file', 'vibepresto'),
                'jsFile' => __('JS file', 'vibepresto'),
                'extraAssets' => __('Extra assets', 'vibepresto'),
                'zipDescription' => __('Best for exported landing pages with nested folders and assets.', 'vibepresto'),
                'assetsDescription' => __('Optional images, fonts, and other files referenced by your HTML.', 'vibepresto'),
                'assignOnUpload' => __('Assign the uploaded bundle to this page immediately', 'vibepresto'),
                'uploadButton' => __('Upload and keep editing', 'vibepresto'),
                'noBundleActive' => __('No bundle is currently assigned to this page.', 'vibepresto'),
                'saveFirstToUpload' => __('Save the page first if you want to upload and assign a bundle from the editor sidebar.', 'vibepresto'),
            ],
        ];
    }
}
