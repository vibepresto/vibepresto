<?php

namespace VibePresto;

if (! defined('ABSPATH')) {
    exit;
}

require_once VIBEPRESTO_PLUGIN_DIR . 'includes/class-vibepresto-bundle-repository.php';
require_once VIBEPRESTO_PLUGIN_DIR . 'includes/class-vibepresto-auth-store.php';
require_once VIBEPRESTO_PLUGIN_DIR . 'includes/class-vibepresto-admin.php';
require_once VIBEPRESTO_PLUGIN_DIR . 'includes/class-vibepresto-api.php';
require_once VIBEPRESTO_PLUGIN_DIR . 'includes/class-vibepresto-renderer.php';

class Plugin
{
    private static ?Plugin $instance = null;

    private Bundle_Repository $bundles;

    private Admin $admin;

    private Auth_Store $auth;

    private API $api;

    private Renderer $renderer;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->bundles = new Bundle_Repository();
        $this->auth = new Auth_Store();
        $this->admin = new Admin($this->bundles, $this->auth);
        $this->api = new API($this->bundles, $this->auth);
        $this->renderer = new Renderer($this->bundles);

        register_activation_hook(VIBEPRESTO_PLUGIN_FILE, [$this, 'activate']);

        add_action('init', [$this, 'register_bundle_post_type']);
        add_action('init', [$this, 'register_deployment_post_type']);
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function activate(): void
    {
        $this->register_bundle_post_type();
        $this->register_deployment_post_type();
        flush_rewrite_rules();
    }

    public function register_bundle_post_type(): void
    {
        register_post_type('vibepresto_bundle', [
            'labels' => [
                'name' => __('VibePresto Bundles', 'vibepresto'),
                'singular_name' => __('VibePresto Bundle', 'vibepresto'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function register_deployment_post_type(): void
    {
        register_post_type('vibepresto_deploy', [
            'labels' => [
                'name' => __('VibePresto Deployments', 'vibepresto'),
                'singular_name' => __('VibePresto Deployment', 'vibepresto'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function boot(): void
    {
        $this->bundles->ensure_upload_root();
        $this->auth->cleanup_expired();
        $this->admin->register();
        $this->api->register();
        $this->renderer->register();

        do_action('vibepresto_services_ready', $this->services(), $this);
    }

    public function get_auth_store(): Auth_Store
    {
        return $this->auth;
    }

    public function get_bundle_repository(): Bundle_Repository
    {
        return $this->bundles;
    }

    public function services(): array
    {
        return [
            'auth' => $this->auth,
            'bundles' => $this->bundles,
            'site' => [
                'site_url' => home_url('/'),
                'plugin_version' => VIBEPRESTO_VERSION,
                'can_manage_options' => current_user_can('manage_options'),
            ],
        ];
    }
}
