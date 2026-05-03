<?php

namespace VibePresto;

if (! defined('ABSPATH')) {
    exit;
}

class Renderer
{
    private Bundle_Repository $bundles;

    public function __construct(Bundle_Repository $bundles)
    {
        $this->bundles = $bundles;
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_render_takeover'], 0);
    }

    public function maybe_render_takeover(): void
    {
        if (is_admin() || ! is_page() || is_feed() || is_preview()) {
            return;
        }

        $page = get_queried_object();
        if (! $page instanceof \WP_Post || $page->post_status !== 'publish') {
            return;
        }

        $bundle_id = $this->bundles->get_assigned_bundle_id((int) $page->ID);
        if ($bundle_id < 1) {
            return;
        }

        $bundle = $this->bundles->find($bundle_id);
        if (! $bundle) {
            return;
        }

        $entry_path = wp_normalize_path(trailingslashit($bundle['storage_path']) . $bundle['entry_html']);
        if (! file_exists($entry_path)) {
            return;
        }

        $html = file_get_contents($entry_path);
        if ($html === false) {
            return;
        }

        $html = $this->rewrite_relative_urls($html, $bundle);

        if ($bundle['mode'] === 'separate') {
            $html = $this->inject_optional_assets($html, $bundle);
        }

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        echo $html;
        exit;
    }

    private function rewrite_relative_urls(string $html, array $bundle): string
    {
        if (! class_exists('\DOMDocument')) {
            return $html;
        }

        $base_url = $this->bundle_base_url($bundle);

        $internal_errors = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($internal_errors);

        if (! $loaded) {
            return $html;
        }

        $attributes = ['href', 'src', 'action', 'poster'];
        $xpath = new \DOMXPath($document);
        foreach ($attributes as $attribute) {
            $nodes = $xpath->query(sprintf('//*[@%s]', $attribute));
            if (! $nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                $current = $node->getAttribute($attribute);
                if ($this->is_relative_url($current)) {
                    $node->setAttribute($attribute, $this->resolve_bundle_url($base_url, $current));
                }
            }
        }

        return $document->saveHTML() ?: $html;
    }

    private function inject_optional_assets(string $html, array $bundle): string
    {
        $files = $bundle['files'];
        $styles = $files['css'] ?? [];
        $scripts = $files['js'] ?? [];

        foreach ($styles as $style_path) {
            if (stripos($html, basename($style_path)) !== false) {
                continue;
            }

            $tag = '<link rel="stylesheet" href="' . esc_url(trailingslashit($bundle['storage_url']) . ltrim($style_path, '/')) . '">';
            $html = $this->inject_before_closing_tag($html, '</head>', $tag);
        }

        foreach ($scripts as $script_path) {
            if (stripos($html, basename($script_path)) !== false) {
                continue;
            }

            $tag = '<script src="' . esc_url(trailingslashit($bundle['storage_url']) . ltrim($script_path, '/')) . '"></script>';
            $html = $this->inject_before_closing_tag($html, '</body>', $tag);
        }

        return $html;
    }

    private function inject_before_closing_tag(string $html, string $closing_tag, string $injection): string
    {
        $position = stripos($html, $closing_tag);
        if ($position === false) {
            return $html . $injection;
        }

        return substr($html, 0, $position) . $injection . substr($html, $position);
    }

    private function is_relative_url(string $url): bool
    {
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'data:')) {
            return false;
        }

        return ! preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $url);
    }

    private function bundle_base_url(array $bundle): string
    {
        $directory = trim((string) dirname($bundle['entry_html']), '.');
        if ($directory === '' || $directory === DIRECTORY_SEPARATOR) {
            return trailingslashit($bundle['storage_url']);
        }

        return trailingslashit($bundle['storage_url']) . trim(str_replace('\\', '/', $directory), '/') . '/';
    }

    private function resolve_bundle_url(string $base_url, string $relative_url): string
    {
        $segments = explode('/', str_replace('\\', '/', $relative_url));
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }

            $resolved[] = $segment;
        }

        return $base_url . implode('/', $resolved);
    }
}
