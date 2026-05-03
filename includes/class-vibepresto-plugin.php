<?php

namespace VibePresto;

if (! defined('ABSPATH')) {
    exit;
}

require_once VIBEPRESTO_PLUGIN_DIR . 'includes/class-vibepresto-bundle-repository.php';
require_once VIBEPRESTO_PLUGIN_DIR . 'includes/class-vibepresto-admin.php';
require_once VIBEPRESTO_PLUGIN_DIR . 'includes/class-vibepresto-renderer.php';

class Plugin
{
    private static ?Plugin $instance = null;

    private Bundle_Repository $bundles;

    private Admin $admin;

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
        $this->admin = new Admin($this->bundles);
        $this->renderer = new Renderer($this->bundles);

        register_activation_hook(VIBEPRESTO_PLUGIN_FILE, [$this, 'activate']);

        add_action('init', [$this, 'register_bundle_post_type']);
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function activate(): void
    {
        $this->register_bundle_post_type();
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

    public function boot(): void
    {
        $this->bundles->ensure_upload_root();
        $this->admin->register();
        $this->renderer->register();
    }
}
