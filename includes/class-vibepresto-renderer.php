<?php

namespace VibePresto;

if (! defined('ABSPATH')) {
    exit;
}

class Renderer
{
    private const SUPPORTED_PLACEHOLDER_FIELDS = [
        'post_title',
        'post_name',
        'post_excerpt',
        'post_content',
        'post_date',
        'post_modified',
        'post_author',
        'permalink',
        'featured_image_url',
        'author_display_name',
    ];

    private const TEXT_UNSUPPORTED_PLACEHOLDER_TAGS = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];

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
        if (is_admin() || is_feed() || is_preview()) {
            return;
        }

        $page = $this->resolve_render_target();
        if (! $page instanceof \WP_Post || $page->post_status !== 'publish') {
            return;
        }

        $bundle = $this->resolve_render_bundle($page);
        if (! is_array($bundle)) {
            return;
        }

        $target = $this->bundles->deployment_target_for_page((int) $page->ID, $bundle);
        $entry_html = is_array($target) && ! empty($target['entry_html'])
            ? (string) $target['entry_html']
            : (string) $bundle['entry_html'];
        $entry_path = wp_normalize_path(trailingslashit($bundle['storage_path']) . $entry_html);
        if (! file_exists($entry_path)) {
            return;
        }

        $html = file_get_contents($entry_path);
        if ($html === false) {
            return;
        }

        $document = $this->load_html_document($html);
        if ($document instanceof \DOMDocument) {
            $this->rewrite_relative_urls($document, $bundle, $entry_html);
            $this->replace_wordpress_placeholders($document, $page);
            $html = $document->saveHTML() ?: $html;
        }

        if ($bundle['mode'] === 'separate') {
            $html = $this->inject_optional_assets($html, $bundle);
        }

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        echo $html;
        exit;
    }

    private function resolve_render_target(): ?\WP_Post
    {
        if (is_page()) {
            $page = get_queried_object();
            return $page instanceof \WP_Post ? $page : null;
        }

        if (is_home()) {
            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id > 0) {
                $page = get_post($posts_page_id);
                return $page instanceof \WP_Post ? $page : null;
            }
        }

        if (is_single()) {
            $post = get_queried_object();
            if ($post instanceof \WP_Post && $post->post_type === 'post') {
                return $post;
            }
        }

        return null;
    }

    private function resolve_render_bundle(\WP_Post $target): ?array
    {
        $bundle_id = $this->bundles->get_assigned_bundle_id((int) $target->ID);
        if ($bundle_id > 0) {
            $bundle = $this->bundles->find($bundle_id);
            if ($bundle) {
                return $bundle;
            }
        }

        if ($target->post_type === 'post' && is_single()) {
            return $this->bundles->get_default_single_post_template_bundle();
        }

        return null;
    }

    private function load_html_document(string $html): ?\DOMDocument
    {
        if (! class_exists('\DOMDocument')) {
            return null;
        }

        $internal_errors = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($internal_errors);

        if (! $loaded) {
            return null;
        }

        return $document;
    }

    private function rewrite_relative_urls(\DOMDocument $document, array $bundle, string $entry_html): void
    {
        $base_url = $this->bundle_base_url($bundle, $entry_html);

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
    }

    private function replace_wordpress_placeholders(\DOMDocument $document, \WP_Post $post): void
    {
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[@data-vp-source or @data-vp-field]');
        if (! $nodes) {
            return;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            if (in_array(strtolower($node->tagName), self::TEXT_UNSUPPORTED_PLACEHOLDER_TAGS, true)) {
                continue;
            }

            $source = sanitize_key($node->getAttribute('data-vp-source'));
            $field = sanitize_key($node->getAttribute('data-vp-field'));
            if ($source === '' || $field === '') {
                continue;
            }

            $value = $this->resolve_placeholder_value($post, $source, $field);
            if ($value === null || $value === '') {
                continue;
            }

            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }

            $node->appendChild($document->createTextNode($value));
        }
    }

    private function resolve_placeholder_value(\WP_Post $post, string $source, string $field): ?string
    {
        if ($source !== 'post' || ! in_array($field, self::SUPPORTED_PLACEHOLDER_FIELDS, true)) {
            return null;
        }

        switch ($field) {
            case 'post_title':
                return (string) $post->post_title;

            case 'post_name':
                return (string) $post->post_name;

            case 'post_excerpt':
                return wp_strip_all_tags((string) $post->post_excerpt, true);

            case 'post_content':
                return wp_strip_all_tags((string) $post->post_content, true);

            case 'post_date':
                return get_the_date('', $post) ?: null;

            case 'post_modified':
                return get_the_modified_date('', $post) ?: null;

            case 'post_author':
                return (string) $post->post_author;

            case 'permalink':
                return get_permalink($post) ?: null;

            case 'featured_image_url':
                return get_the_post_thumbnail_url($post, 'full') ?: null;

            case 'author_display_name':
                $author = get_user_by('id', (int) $post->post_author);
                return $author ? (string) $author->display_name : null;

            default:
                return null;
        }
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

    private function bundle_base_url(array $bundle, string $entry_html): string
    {
        $directory = trim((string) dirname($entry_html), '.');
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
