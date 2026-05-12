<?php

namespace VibePresto;

use WP_Error;
use WP_Post;
use ZipArchive;

if (! defined('ABSPATH')) {
    exit;
}

class Bundle_Repository
{
    private const ENTRY_META_KEY = '_vibepresto_entry_html';
    private const MODE_META_KEY = '_vibepresto_mode';
    private const STORAGE_PATH_META_KEY = '_vibepresto_storage_path';
    private const STORAGE_URL_META_KEY = '_vibepresto_storage_url';
    private const FILES_META_KEY = '_vibepresto_files';
    private const LINEAGE_ID_META_KEY = '_vibepresto_lineage_id';
    private const LINEAGE_NAME_META_KEY = '_vibepresto_lineage_name';
    private const VERSION_NUMBER_META_KEY = '_vibepresto_version_number';
    private const VERSION_LABEL_META_KEY = '_vibepresto_version_label';
    private const CURRENT_META_KEY = '_vibepresto_is_current';
    private const SOURCE_PAGE_ID_META_KEY = '_vibepresto_source_page_id';
    private const BUNDLE_KIND_META_KEY = '_vibepresto_bundle_kind';
    private const ROUTE_MANIFEST_META_KEY = '_vibepresto_route_manifest';
    private const BUILD_METADATA_META_KEY = '_vibepresto_build_metadata';

    private const DEPLOYMENT_LINEAGE_ID_META_KEY = '_vibepresto_deployment_lineage_id';
    private const DEPLOYMENT_CURRENT_BUNDLE_ID_META_KEY = '_vibepresto_deployment_current_bundle_id';
    private const DEPLOYMENT_TARGETS_META_KEY = '_vibepresto_deployment_targets';
    private const DEPLOYMENT_HOMEPAGE_ROUTE_META_KEY = '_vibepresto_deployment_homepage_route';

    public const PAGE_META_KEY = '_vibepresto_bundle_id';
    public const PAGE_DEPLOYMENT_META_KEY = '_vibepresto_deployment_id';

    public function ensure_upload_root(): void
    {
        wp_mkdir_p($this->get_upload_root()['path']);
    }

    public function all(): array
    {
        return $this->current_versions();
    }

    public function current_versions(): array
    {
        $lineages = $this->list_lineages();

        return array_values(array_map(static function (array $lineage): array {
            return $lineage['current_version'];
        }, $lineages));
    }

    public function all_versions(): array
    {
        return array_map([$this, 'hydrate_bundle'], $this->bundle_posts());
    }

    public function list_lineages(): array
    {
        $versions = $this->all_versions();
        $grouped = [];

        foreach ($versions as $version) {
            $lineage_id = (int) $version['lineage_id'];
            if (! isset($grouped[$lineage_id])) {
                $grouped[$lineage_id] = [
                    'lineage_id' => $lineage_id,
                    'lineage_name' => $version['lineage_name'],
                    'version_count' => 0,
                    'current_version' => null,
                    'versions' => [],
                    'updated_at' => $version['updated_at'],
                ];
            }

            $grouped[$lineage_id]['versions'][] = $version;
            $grouped[$lineage_id]['version_count']++;
            if (strtotime($version['updated_at']) > strtotime($grouped[$lineage_id]['updated_at'])) {
                $grouped[$lineage_id]['updated_at'] = $version['updated_at'];
            }
        }

        foreach ($grouped as $lineage_id => $lineage) {
            usort($lineage['versions'], static function (array $left, array $right): int {
                $version_compare = ($right['version_number'] ?? 0) <=> ($left['version_number'] ?? 0);
                if ($version_compare !== 0) {
                    return $version_compare;
                }

                return strtotime($right['updated_at'] ?? '') <=> strtotime($left['updated_at'] ?? '');
            });

            $current_version = null;
            foreach ($lineage['versions'] as $version) {
                if (! empty($version['is_current'])) {
                    $current_version = $version;
                    break;
                }
            }

            if ($current_version === null) {
                $current_version = $lineage['versions'][0] ?? null;
            }

            $grouped[$lineage_id]['versions'] = $lineage['versions'];
            $grouped[$lineage_id]['current_version'] = $current_version;
        }

        usort($grouped, static function (array $left, array $right): int {
            return strtotime($right['updated_at'] ?? '') <=> strtotime($left['updated_at'] ?? '');
        });

        return array_values($grouped);
    }

    public function versions_for_lineage(int $bundle_or_lineage_id): array
    {
        $lineage_id = $this->resolve_lineage_id($bundle_or_lineage_id);
        if ($lineage_id < 1) {
            return [];
        }

        foreach ($this->list_lineages() as $lineage) {
            if ((int) $lineage['lineage_id'] === $lineage_id) {
                return $lineage['versions'];
            }
        }

        return [];
    }

    public function find(int $bundle_id): ?array
    {
        $post = get_post($bundle_id);
        if (! $post instanceof WP_Post || $post->post_type !== 'vibepresto_bundle' || $post->post_status !== 'publish') {
            return null;
        }

        return $this->hydrate_bundle($post);
    }

    public function find_version_by_number(int $lineage_or_bundle_id, int $version_number): ?array
    {
        foreach ($this->versions_for_lineage($lineage_or_bundle_id) as $version) {
            if ((int) $version['version_number'] === $version_number) {
                return $version;
            }
        }

        return null;
    }

    public function resolve_lineage_id(int $bundle_or_lineage_id): int
    {
        if ($bundle_or_lineage_id < 1) {
            return 0;
        }

        $bundle = $this->find($bundle_or_lineage_id);
        if ($bundle) {
            return (int) $bundle['lineage_id'];
        }

        return $bundle_or_lineage_id;
    }

    public function get_current_version_for_lineage(int $lineage_or_bundle_id): ?array
    {
        $lineage_id = $this->resolve_lineage_id($lineage_or_bundle_id);
        if ($lineage_id < 1) {
            return null;
        }

        foreach ($this->list_lineages() as $lineage) {
            if ((int) $lineage['lineage_id'] === $lineage_id) {
                return $lineage['current_version'];
            }
        }

        return null;
    }

    public function list_deployments(): array
    {
        return array_map([$this, 'hydrate_deployment'], $this->deployment_posts());
    }

    public function find_deployment(int $deployment_id): ?array
    {
        $post = get_post($deployment_id);
        if (! $post instanceof WP_Post || $post->post_type !== 'vibepresto_deploy' || $post->post_status !== 'publish') {
            return null;
        }

        return $this->hydrate_deployment($post);
    }

    public function find_deployment_by_lineage(int $lineage_id): ?array
    {
        foreach ($this->list_deployments() as $deployment) {
            if ((int) $deployment['lineage_id'] === $lineage_id) {
                return $deployment;
            }
        }

        return null;
    }

    public function get_assigned_deployment_id(int $page_id): int
    {
        return (int) get_post_meta($page_id, self::PAGE_DEPLOYMENT_META_KEY, true);
    }

    public function assign_to_page(int $page_id, int $bundle_id, int $deployment_id = 0): void
    {
        update_post_meta($page_id, self::PAGE_META_KEY, $bundle_id);

        if ($deployment_id > 0) {
            update_post_meta($page_id, self::PAGE_DEPLOYMENT_META_KEY, $deployment_id);
            return;
        }

        delete_post_meta($page_id, self::PAGE_DEPLOYMENT_META_KEY);
    }

    public function clear_page_assignment(int $page_id): void
    {
        delete_post_meta($page_id, self::PAGE_META_KEY);
        delete_post_meta($page_id, self::PAGE_DEPLOYMENT_META_KEY);
    }

    public function get_assigned_bundle_id(int $page_id): int
    {
        return (int) get_post_meta($page_id, self::PAGE_META_KEY, true);
    }

    public function promote_version(int $bundle_id, int $page_id = 0)
    {
        $bundle = $this->find($bundle_id);
        if (! $bundle) {
            return new WP_Error('not_found', __('The requested bundle version could not be found.', 'vibepresto'));
        }

        $this->set_current_version((int) $bundle['lineage_id'], $bundle_id);

        if ($page_id > 0) {
            $page = get_post($page_id);
            if (! $page instanceof WP_Post || $page->post_type !== 'page') {
                return new WP_Error('not_found', __('The requested page could not be found.', 'vibepresto'));
            }

            $this->assign_to_page($page_id, $bundle_id);
        }

        return $this->find($bundle_id);
    }

    public function promote_deployment_version(int $deployment_id, int $bundle_id)
    {
        $deployment = $this->find_deployment($deployment_id);
        if (! $deployment) {
            return new WP_Error('not_found', __('The requested deployment could not be found.', 'vibepresto'));
        }

        $bundle = $this->find($bundle_id);
        if (! $bundle) {
            return new WP_Error('not_found', __('The requested bundle version could not be found.', 'vibepresto'));
        }

        if ((int) $bundle['lineage_id'] !== (int) $deployment['lineage_id']) {
            return new WP_Error('invalid_request', __('You can only promote bundle versions within the deployment lineage.', 'vibepresto'));
        }

        $promoted = $this->promote_version($bundle_id);
        if (is_wp_error($promoted)) {
            return $promoted;
        }

        $targets = $deployment['targets'];
        foreach ($targets as $target) {
            $page_id = (int) ($target['page_id'] ?? 0);
            if ($page_id > 0) {
                $this->assign_to_page($page_id, $bundle_id, $deployment_id);
            }
        }

        update_post_meta($deployment_id, self::DEPLOYMENT_CURRENT_BUNDLE_ID_META_KEY, $bundle_id);

        return $this->find_deployment($deployment_id);
    }

    public function rollback_deployment(int $deployment_id, int $bundle_version_id = 0, int $version_number = 0)
    {
        $deployment = $this->find_deployment($deployment_id);
        if (! $deployment) {
            return new WP_Error('not_found', __('The requested deployment could not be found.', 'vibepresto'));
        }

        $target = null;
        if ($bundle_version_id > 0) {
            $target = $this->find($bundle_version_id);
        } elseif ($version_number > 0) {
            $target = $this->find_version_by_number((int) $deployment['lineage_id'], $version_number);
        } else {
            return new WP_Error('invalid_request', __('Provide either a bundle version id or version number.', 'vibepresto'));
        }

        if (! $target) {
            return new WP_Error('not_found', __('The requested bundle version could not be found.', 'vibepresto'));
        }

        return $this->promote_deployment_version($deployment_id, (int) $target['id']);
    }

    public function save_deployment(int $bundle_id, array $targets, array $options = [])
    {
        $bundle = $this->find($bundle_id);
        if (! $bundle) {
            return new WP_Error('not_found', __('The requested bundle version could not be found.', 'vibepresto'));
        }

        $lineage_id = (int) $bundle['lineage_id'];
        $deployment_id = isset($options['deployment_id']) ? (int) $options['deployment_id'] : 0;
        $title = sanitize_text_field((string) ($options['title'] ?? ($bundle['lineage_name'] . ' deployment')));
        $homepage_route = sanitize_text_field((string) ($options['homepage_route'] ?? ''));

        if ($deployment_id < 1) {
            $existing = $this->find_deployment_by_lineage($lineage_id);
            $deployment_id = $existing ? (int) $existing['id'] : 0;
        }

        $normalized_targets = $this->normalize_deployment_targets($targets, $bundle);
        if (is_wp_error($normalized_targets)) {
            return $normalized_targets;
        }

        if ($deployment_id > 0) {
            $previous = $this->find_deployment($deployment_id);
            if (! $previous) {
                return new WP_Error('not_found', __('The requested deployment could not be found.', 'vibepresto'));
            }

            wp_update_post([
                'ID' => $deployment_id,
                'post_title' => $title,
            ]);

            $this->clear_page_deployment_assignments($previous);
        } else {
            $deployment_id = wp_insert_post([
                'post_type' => 'vibepresto_deploy',
                'post_status' => 'publish',
                'post_title' => $title,
            ], true);

            if (is_wp_error($deployment_id)) {
                return $deployment_id;
            }

            $deployment_id = (int) $deployment_id;
        }

        update_post_meta($deployment_id, self::DEPLOYMENT_LINEAGE_ID_META_KEY, $lineage_id);
        update_post_meta($deployment_id, self::DEPLOYMENT_CURRENT_BUNDLE_ID_META_KEY, $bundle_id);
        update_post_meta($deployment_id, self::DEPLOYMENT_TARGETS_META_KEY, $normalized_targets);
        update_post_meta($deployment_id, self::DEPLOYMENT_HOMEPAGE_ROUTE_META_KEY, $homepage_route);

        foreach ($normalized_targets as $target) {
            $page_id = (int) $target['page_id'];
            $this->assign_to_page($page_id, $bundle_id, $deployment_id);
        }

        foreach ($normalized_targets as $target) {
            if (! empty($target['is_homepage']) && (int) $target['page_id'] > 0) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', (int) $target['page_id']);
                break;
            }
        }

        return $this->find_deployment($deployment_id);
    }

    public function create_compat_deployment_for_page(int $bundle_id, int $page_id)
    {
        $bundle = $this->find($bundle_id);
        $page = get_post($page_id);
        if (! $bundle || ! $page instanceof WP_Post || $page->post_type !== 'page') {
            return new WP_Error('not_found', __('The requested page or bundle could not be found.', 'vibepresto'));
        }

        $target = $this->default_target_for_page($page_id, $bundle);
        return $this->save_deployment($bundle_id, [$target], [
            'title' => $bundle['lineage_name'],
        ]);
    }

    public function deployment_target_for_page(int $page_id, array $bundle): ?array
    {
        $deployment_id = $this->get_assigned_deployment_id($page_id);
        if ($deployment_id > 0) {
            $deployment = $this->find_deployment($deployment_id);
            if ($deployment && (int) $deployment['lineage_id'] === (int) $bundle['lineage_id']) {
                foreach ($deployment['targets'] as $target) {
                    if ((int) ($target['page_id'] ?? 0) === $page_id) {
                        return $target;
                    }
                }
            }
        }

        $deployment = $this->find_deployment_by_lineage((int) $bundle['lineage_id']);
        if ($deployment) {
            foreach ($deployment['targets'] as $target) {
                if ((int) ($target['page_id'] ?? 0) === $page_id) {
                    return $target;
                }
            }
        }

        return null;
    }

    public function delete(int $bundle_id)
    {
        $bundle = $this->find($bundle_id);
        if (! $bundle) {
            return new WP_Error('not_found', __('The requested bundle version could not be found.', 'vibepresto'));
        }

        $versions = $this->versions_for_lineage((int) $bundle['lineage_id']);
        $replacement = null;

        if (! empty($bundle['is_current'])) {
            foreach ($versions as $candidate) {
                if ((int) $candidate['id'] !== $bundle_id) {
                    $replacement = $candidate;
                    break;
                }
            }
        }

        if ($replacement) {
            $this->set_current_version((int) $bundle['lineage_id'], (int) $replacement['id']);
            $this->reassign_pages_from_version($bundle_id, (int) $replacement['id']);
            $deployment = $this->find_deployment_by_lineage((int) $bundle['lineage_id']);
            if ($deployment) {
                update_post_meta((int) $deployment['id'], self::DEPLOYMENT_CURRENT_BUNDLE_ID_META_KEY, (int) $replacement['id']);
            }
        } else {
            $this->clear_page_assignments_for_bundle($bundle_id);
            $deployment = $this->find_deployment_by_lineage((int) $bundle['lineage_id']);
            if ($deployment) {
                wp_delete_post((int) $deployment['id'], true);
            }
        }

        $this->delete_directory($bundle['storage_path']);

        if (! wp_delete_post($bundle_id, true)) {
            return new WP_Error('delete_failed', __('The bundle version could not be deleted.', 'vibepresto'));
        }

        return true;
    }

    public function create_from_zip(array $zip_file, string $display_name, array $options = [])
    {
        $this->ensure_upload_root();
        $this->validate_uploaded_file($zip_file, ['zip'], __('A ZIP bundle is required.', 'vibepresto'));

        $draft = $this->prepare_version_draft($display_name, $zip_file['name'], $options);
        if (is_wp_error($draft)) {
            return $draft;
        }

        $tmp_path = $zip_file['tmp_name'];
        $bundle_id = $this->create_bundle_post($draft['version_label']);
        if (is_wp_error($bundle_id)) {
            return $bundle_id;
        }

        $directory = $this->create_bundle_directory($bundle_id);
        if (is_wp_error($directory)) {
            wp_delete_post($bundle_id, true);
            return $directory;
        }

        $archive = new ZipArchive();
        if ($archive->open($tmp_path) !== true) {
            wp_delete_post($bundle_id, true);
            $this->delete_directory($directory['path']);
            return new WP_Error('vibepresto_zip_open_failed', __('Unable to open the uploaded ZIP archive.', 'vibepresto'));
        }

        $zip_validation = $this->validate_zip_archive($archive);
        if (is_wp_error($zip_validation)) {
            $archive->close();
            wp_delete_post($bundle_id, true);
            $this->delete_directory($directory['path']);
            return $zip_validation;
        }

        $archive->extractTo($directory['path']);
        $archive->close();

        $entry_file = $this->locate_index_file($directory['path']);
        if (! $entry_file) {
            wp_delete_post($bundle_id, true);
            $this->delete_directory($directory['path']);
            return new WP_Error('vibepresto_missing_index', __('ZIP bundles must contain an index.html file.', 'vibepresto'));
        }

        $files = $this->scan_bundle_files($directory['path']);
        $relative_entry = $this->relative_path($directory['path'], $entry_file);
        $manifest = $this->normalize_route_manifest($draft['route_manifest'], $relative_entry, $draft['bundle_kind']);
        if (is_wp_error($manifest)) {
            wp_delete_post($bundle_id, true);
            $this->delete_directory($directory['path']);
            return $manifest;
        }

        $manifest_validation = $this->validate_route_manifest($manifest, $files['html']);
        if (is_wp_error($manifest_validation)) {
            wp_delete_post($bundle_id, true);
            $this->delete_directory($directory['path']);
            return $manifest_validation;
        }

        $bundle_kind = $this->determine_bundle_kind($draft['bundle_kind'], $manifest);
        $this->persist_bundle_meta($bundle_id, 'zip', $directory, $relative_entry, $files, $draft, $bundle_kind, $manifest);

        return $bundle_id;
    }

    public function create_from_files(array $html_file, array $css_file, array $js_file, array $asset_files, string $display_name, array $options = [])
    {
        $this->ensure_upload_root();
        $this->validate_uploaded_file($html_file, ['html', 'htm'], __('An HTML file is required.', 'vibepresto'));

        if (! empty($css_file['name'])) {
            $this->validate_uploaded_file($css_file, ['css'], __('CSS files must end in .css.', 'vibepresto'));
        }

        if (! empty($js_file['name'])) {
            $this->validate_uploaded_file($js_file, ['js'], __('JS files must end in .js.', 'vibepresto'));
        }

        $draft = $this->prepare_version_draft($display_name, $html_file['name'], $options);
        if (is_wp_error($draft)) {
            return $draft;
        }

        $bundle_id = $this->create_bundle_post($draft['version_label']);
        if (is_wp_error($bundle_id)) {
            return $bundle_id;
        }

        $directory = $this->create_bundle_directory($bundle_id);
        if (is_wp_error($directory)) {
            wp_delete_post($bundle_id, true);
            return $directory;
        }

        $stored_files = [];

        $entry_file = $this->move_uploaded_file($html_file, $directory['path']);
        if (is_wp_error($entry_file)) {
            wp_delete_post($bundle_id, true);
            $this->delete_directory($directory['path']);
            return $entry_file;
        }
        $stored_files['html'] = [$this->relative_path($directory['path'], $entry_file)];

        if (! empty($css_file['name'])) {
            $css_path = $this->move_uploaded_file($css_file, $directory['path']);
            if (is_wp_error($css_path)) {
                wp_delete_post($bundle_id, true);
                $this->delete_directory($directory['path']);
                return $css_path;
            }
            $stored_files['css'] = [$this->relative_path($directory['path'], $css_path)];
        }

        if (! empty($js_file['name'])) {
            $js_path = $this->move_uploaded_file($js_file, $directory['path']);
            if (is_wp_error($js_path)) {
                wp_delete_post($bundle_id, true);
                $this->delete_directory($directory['path']);
                return $js_path;
            }
            $stored_files['js'] = [$this->relative_path($directory['path'], $js_path)];
        }

        $asset_paths = [];
        if (! empty($asset_files['name']) && is_array($asset_files['name'])) {
            $asset_count = count($asset_files['name']);
            for ($index = 0; $index < $asset_count; $index++) {
                if (empty($asset_files['name'][$index])) {
                    continue;
                }

                $file = [
                    'name' => $asset_files['name'][$index],
                    'type' => $asset_files['type'][$index] ?? '',
                    'tmp_name' => $asset_files['tmp_name'][$index] ?? '',
                    'error' => $asset_files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $asset_files['size'][$index] ?? 0,
                ];
                $asset_path = $this->move_uploaded_file($file, $directory['path']);
                if (is_wp_error($asset_path)) {
                    wp_delete_post($bundle_id, true);
                    $this->delete_directory($directory['path']);
                    return $asset_path;
                }
                $asset_paths[] = $this->relative_path($directory['path'], $asset_path);
            }
        }

        if ($asset_paths) {
            $stored_files['assets'] = $asset_paths;
        }

        $entry_html = $this->relative_path($directory['path'], $entry_file);
        $manifest = $this->normalize_route_manifest($draft['route_manifest'], $entry_html, $draft['bundle_kind']);
        if (is_wp_error($manifest)) {
            wp_delete_post($bundle_id, true);
            $this->delete_directory($directory['path']);
            return $manifest;
        }

        $manifest_validation = $this->validate_route_manifest($manifest, $stored_files['html']);
        if (is_wp_error($manifest_validation)) {
            wp_delete_post($bundle_id, true);
            $this->delete_directory($directory['path']);
            return $manifest_validation;
        }

        $bundle_kind = $this->determine_bundle_kind($draft['bundle_kind'], $manifest);
        $this->persist_bundle_meta(
            $bundle_id,
            'separate',
            $directory,
            $entry_html,
            $stored_files,
            $draft,
            $bundle_kind,
            $manifest
        );

        return $bundle_id;
    }

    public function default_target_for_page(int $page_id, array $bundle): array
    {
        $page = get_post($page_id);
        $route = $this->route_for_page($page);
        $manifest_entry = $this->find_manifest_entry_for_route($bundle['route_manifest'], $route)
            ?: ($bundle['route_manifest'][0] ?? null);

        return [
            'page_id' => $page_id,
            'route_path' => $route,
            'target_slug' => $page instanceof WP_Post ? $page->post_name : '',
            'entry_html' => $manifest_entry['entry_html'] ?? $bundle['entry_html'],
            'route_type' => $manifest_entry['route_type'] ?? ($bundle['bundle_kind'] === 'spa' ? 'spa-fallback' : 'entry'),
            'is_homepage' => (int) get_option('page_on_front') === $page_id,
        ];
    }

    private function bundle_posts(): array
    {
        return get_posts([
            'post_type' => 'vibepresto_bundle',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    private function deployment_posts(): array
    {
        return get_posts([
            'post_type' => 'vibepresto_deploy',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
    }

    private function hydrate_bundle(WP_Post $post): array
    {
        $lineage_id = (int) get_post_meta($post->ID, self::LINEAGE_ID_META_KEY, true);
        if ($lineage_id < 1) {
            $lineage_id = (int) $post->ID;
        }

        $lineage_name = (string) get_post_meta($post->ID, self::LINEAGE_NAME_META_KEY, true);
        if ($lineage_name === '') {
            $lineage_name = $post->post_title;
        }

        $version_number = (int) get_post_meta($post->ID, self::VERSION_NUMBER_META_KEY, true);
        if ($version_number < 1) {
            $version_number = 1;
        }

        $version_label = (string) get_post_meta($post->ID, self::VERSION_LABEL_META_KEY, true);
        if ($version_label === '') {
            $version_label = $version_number > 1
                ? sprintf('%s v%d', $lineage_name, $version_number)
                : sprintf('%s v1', $lineage_name);
        }

        $is_current = get_post_meta($post->ID, self::CURRENT_META_KEY, true);
        $is_current = $is_current === '' ? true : ((int) $is_current === 1);
        $entry_html = (string) get_post_meta($post->ID, self::ENTRY_META_KEY, true);
        $bundle_kind = sanitize_key((string) get_post_meta($post->ID, self::BUNDLE_KIND_META_KEY, true));
        $route_manifest = get_post_meta($post->ID, self::ROUTE_MANIFEST_META_KEY, true);
        $route_manifest = is_array($route_manifest) ? $route_manifest : [];
        $bundle_kind = $this->determine_bundle_kind($bundle_kind, $route_manifest, $entry_html);

        if (! $route_manifest) {
            $route_manifest = $this->normalize_route_manifest([], $entry_html, $bundle_kind);
        }

        return [
            'id' => (int) $post->ID,
            'title' => $lineage_name,
            'lineage_name' => $lineage_name,
            'lineage_id' => $lineage_id,
            'version_number' => $version_number,
            'version_label' => $version_label,
            'is_current' => $is_current,
            'source_page_id' => (int) get_post_meta($post->ID, self::SOURCE_PAGE_ID_META_KEY, true),
            'mode' => (string) get_post_meta($post->ID, self::MODE_META_KEY, true),
            'entry_html' => $entry_html,
            'storage_path' => (string) get_post_meta($post->ID, self::STORAGE_PATH_META_KEY, true),
            'storage_url' => (string) get_post_meta($post->ID, self::STORAGE_URL_META_KEY, true),
            'files' => get_post_meta($post->ID, self::FILES_META_KEY, true) ?: [],
            'bundle_kind' => $bundle_kind,
            'route_manifest' => $route_manifest,
            'build_metadata' => get_post_meta($post->ID, self::BUILD_METADATA_META_KEY, true) ?: [],
            'created_at' => $post->post_date_gmt,
            'updated_at' => $post->post_modified_gmt,
            'deployment_id' => $this->deployment_id_for_lineage($lineage_id),
        ];
    }

    private function hydrate_deployment(WP_Post $post): array
    {
        $lineage_id = (int) get_post_meta($post->ID, self::DEPLOYMENT_LINEAGE_ID_META_KEY, true);
        $current_bundle_id = (int) get_post_meta($post->ID, self::DEPLOYMENT_CURRENT_BUNDLE_ID_META_KEY, true);
        $targets = get_post_meta($post->ID, self::DEPLOYMENT_TARGETS_META_KEY, true);
        $targets = is_array($targets) ? $targets : [];
        $bundle = $current_bundle_id > 0 ? $this->find($current_bundle_id) : null;

        return [
            'id' => (int) $post->ID,
            'title' => $post->post_title,
            'lineage_id' => $lineage_id,
            'bundle_id' => $current_bundle_id,
            'bundle_title' => $bundle['lineage_name'] ?? $post->post_title,
            'bundle_version_id' => $bundle['id'] ?? $current_bundle_id,
            'bundle_version_number' => $bundle['version_number'] ?? 0,
            'bundle_version_label' => $bundle['version_label'] ?? '',
            'bundle_kind' => $bundle['bundle_kind'] ?? '',
            'route_manifest' => $bundle['route_manifest'] ?? [],
            'build_metadata' => $bundle['build_metadata'] ?? [],
            'targets' => $targets,
            'homepage_route' => (string) get_post_meta($post->ID, self::DEPLOYMENT_HOMEPAGE_ROUTE_META_KEY, true),
            'created_at' => $post->post_date_gmt,
            'updated_at' => $post->post_modified_gmt,
        ];
    }

    private function deployment_id_for_lineage(int $lineage_id): int
    {
        if ($lineage_id < 1) {
            return 0;
        }

        $deployment_ids = get_posts([
            'post_type' => 'vibepresto_deploy',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => self::DEPLOYMENT_LINEAGE_ID_META_KEY,
            'meta_value' => $lineage_id,
        ]);

        return isset($deployment_ids[0]) ? (int) $deployment_ids[0] : 0;
    }

    private function prepare_version_draft(string $display_name, string $fallback_file_name, array $options)
    {
        $requested_lineage_id = isset($options['lineage_id']) ? (int) $options['lineage_id'] : 0;
        $source_page_id = isset($options['source_page_id']) ? (int) $options['source_page_id'] : 0;
        $route_manifest = isset($options['route_manifest']) && is_array($options['route_manifest']) ? $options['route_manifest'] : [];
        $build_metadata = isset($options['build_metadata']) && is_array($options['build_metadata']) ? $options['build_metadata'] : [];
        $bundle_kind = sanitize_key((string) ($options['bundle_kind'] ?? ''));

        $lineage_id = $requested_lineage_id > 0 ? $this->resolve_lineage_id($requested_lineage_id) : 0;
        $lineage_name = $this->normalize_name($display_name, $fallback_file_name);
        $version_number = 1;

        if ($lineage_id > 0) {
            $current = $this->get_current_version_for_lineage($lineage_id);
            if (! $current) {
                return new WP_Error('not_found', __('The requested bundle lineage could not be found.', 'vibepresto'));
            }

            $lineage_name = $current['lineage_name'];
            $version_number = $this->next_version_number($lineage_id);
        }

        return [
            'lineage_id' => $lineage_id,
            'lineage_name' => $lineage_name,
            'version_number' => $version_number,
            'version_label' => $this->build_version_label($lineage_name, $version_number),
            'source_page_id' => $source_page_id,
            'route_manifest' => $route_manifest,
            'build_metadata' => $build_metadata,
            'bundle_kind' => $bundle_kind,
        ];
    }

    private function persist_bundle_meta(int $bundle_id, string $mode, array $directory, string $entry_html, array $files, array $draft, string $bundle_kind, array $route_manifest): void
    {
        $lineage_id = (int) ($draft['lineage_id'] ?: $bundle_id);

        update_post_meta($bundle_id, self::MODE_META_KEY, $mode);
        update_post_meta($bundle_id, self::ENTRY_META_KEY, $entry_html);
        update_post_meta($bundle_id, self::STORAGE_PATH_META_KEY, $directory['path']);
        update_post_meta($bundle_id, self::STORAGE_URL_META_KEY, $directory['url']);
        update_post_meta($bundle_id, self::FILES_META_KEY, $files);
        update_post_meta($bundle_id, self::LINEAGE_ID_META_KEY, $lineage_id);
        update_post_meta($bundle_id, self::LINEAGE_NAME_META_KEY, $draft['lineage_name']);
        update_post_meta($bundle_id, self::VERSION_NUMBER_META_KEY, (int) $draft['version_number']);
        update_post_meta($bundle_id, self::VERSION_LABEL_META_KEY, $draft['version_label']);
        update_post_meta($bundle_id, self::CURRENT_META_KEY, 1);
        update_post_meta($bundle_id, self::SOURCE_PAGE_ID_META_KEY, (int) ($draft['source_page_id'] ?? 0));
        update_post_meta($bundle_id, self::BUNDLE_KIND_META_KEY, $bundle_kind);
        update_post_meta($bundle_id, self::ROUTE_MANIFEST_META_KEY, $route_manifest);
        update_post_meta($bundle_id, self::BUILD_METADATA_META_KEY, $draft['build_metadata'] ?? []);

        $this->set_current_version($lineage_id, $bundle_id);
    }

    private function get_upload_root(): array
    {
        $uploads = wp_upload_dir();
        return [
            'path' => trailingslashit($uploads['basedir']) . 'vibepresto/bundles',
            'url' => trailingslashit($uploads['baseurl']) . 'vibepresto/bundles',
        ];
    }

    private function create_bundle_post(string $display_name)
    {
        $bundle_id = wp_insert_post([
            'post_type' => 'vibepresto_bundle',
            'post_status' => 'publish',
            'post_title' => $display_name,
        ], true);

        if (is_wp_error($bundle_id)) {
            return $bundle_id;
        }

        return (int) $bundle_id;
    }

    private function create_bundle_directory(int $bundle_id)
    {
        $root = $this->get_upload_root();
        $path = trailingslashit($root['path']) . $bundle_id;
        $url = trailingslashit($root['url']) . $bundle_id;

        if (! wp_mkdir_p($path)) {
            return new WP_Error('vibepresto_upload_dir_failed', __('Unable to create bundle storage directory.', 'vibepresto'));
        }

        return ['path' => $path, 'url' => $url];
    }

    private function set_current_version(int $lineage_id, int $bundle_id): void
    {
        foreach ($this->versions_for_lineage($lineage_id) as $version) {
            update_post_meta((int) $version['id'], self::CURRENT_META_KEY, (int) ((int) $version['id'] === $bundle_id));
        }

        update_post_meta($bundle_id, self::CURRENT_META_KEY, 1);
    }

    private function next_version_number(int $lineage_id): int
    {
        $versions = $this->versions_for_lineage($lineage_id);
        if (! $versions) {
            return 1;
        }

        return max(array_map(static function (array $version): int {
            return (int) $version['version_number'];
        }, $versions)) + 1;
    }

    private function build_version_label(string $lineage_name, int $version_number): string
    {
        return sprintf('%s v%d', $lineage_name, $version_number);
    }

    private function clear_page_assignments_for_bundle(int $bundle_id): void
    {
        global $wpdb;

        $wpdb->delete(
            $wpdb->postmeta,
            [
                'meta_key' => self::PAGE_META_KEY,
                'meta_value' => $bundle_id,
            ],
            ['%s', '%d']
        );
    }

    private function clear_page_deployment_assignments(array $deployment): void
    {
        foreach ($deployment['targets'] as $target) {
            $page_id = (int) ($target['page_id'] ?? 0);
            if ($page_id < 1) {
                continue;
            }

            $assigned_deployment_id = $this->get_assigned_deployment_id($page_id);
            if ($assigned_deployment_id === (int) $deployment['id']) {
                delete_post_meta($page_id, self::PAGE_DEPLOYMENT_META_KEY);
            }
        }
    }

    private function reassign_pages_from_version(int $old_bundle_id, int $new_bundle_id): void
    {
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'meta_key' => self::PAGE_META_KEY,
            'meta_value' => $old_bundle_id,
            'fields' => 'ids',
        ]);

        foreach ($pages as $page_id) {
            update_post_meta((int) $page_id, self::PAGE_META_KEY, $new_bundle_id);
        }
    }

    private function validate_zip_archive(ZipArchive $archive)
    {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if (! is_string($name)) {
                continue;
            }

            $normalized = str_replace('\\', '/', $name);
            if (str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
                return new WP_Error('vibepresto_zip_unsafe_path', __('ZIP bundles cannot contain unsafe file paths.', 'vibepresto'));
            }
        }

        return true;
    }

    private function normalize_name(string $display_name, string $fallback_file_name): string
    {
        $display_name = trim($display_name);
        if ($display_name !== '') {
            return sanitize_text_field($display_name);
        }

        return sanitize_text_field(pathinfo($fallback_file_name, PATHINFO_FILENAME));
    }

    private function validate_uploaded_file(array $file, array $extensions, string $required_message): void
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException($required_message);
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(__('The uploaded file could not be processed.', 'vibepresto'));
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (! in_array($extension, $extensions, true)) {
            throw new \RuntimeException(__('The uploaded file type is not allowed.', 'vibepresto'));
        }
    }

    private function move_uploaded_file(array $file, string $destination_directory)
    {
        $this->ensure_safe_file($file);

        $file_name = sanitize_file_name(wp_unslash($file['name']));
        $target_path = wp_normalize_path(trailingslashit($destination_directory) . $file_name);

        if (! @move_uploaded_file($file['tmp_name'], $target_path)) {
            return new WP_Error('vibepresto_move_failed', __('Unable to move an uploaded file into bundle storage.', 'vibepresto'));
        }

        return $target_path;
    }

    private function ensure_safe_file(array $file): void
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException(__('A required file is missing.', 'vibepresto'));
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(__('A file upload failed before it reached the plugin.', 'vibepresto'));
        }
    }

    private function locate_index_file(string $directory): ?string
    {
        $index_path = trailingslashit($directory) . 'index.html';
        if (file_exists($index_path)) {
            return wp_normalize_path($index_path);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (strtolower($file->getFilename()) === 'index.html') {
                return wp_normalize_path($file->getPathname());
            }
        }

        return null;
    }

    private function scan_bundle_files(string $directory): array
    {
        $files = [
            'html' => [],
            'css' => [],
            'js' => [],
            'assets' => [],
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative_path = $this->relative_path($directory, $file->getPathname());
            $extension = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));

            if (in_array($extension, ['html', 'htm'], true)) {
                $files['html'][] = $relative_path;
            } elseif ($extension === 'css') {
                $files['css'][] = $relative_path;
            } elseif ($extension === 'js') {
                $files['js'][] = $relative_path;
            } else {
                $files['assets'][] = $relative_path;
            }
        }

        return $files;
    }

    private function relative_path(string $root, string $path): string
    {
        $root = trailingslashit(wp_normalize_path($root));
        $path = wp_normalize_path($path);
        return ltrim(str_replace($root, '', $path), '/');
    }

    private function delete_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function determine_bundle_kind(string $requested_kind, array $manifest, string $entry_html = ''): string
    {
        if (in_array($requested_kind, ['single-entry', 'multi-entry', 'spa'], true)) {
            return $requested_kind;
        }

        foreach ($manifest as $route) {
            if (($route['route_type'] ?? '') === 'spa-fallback') {
                return 'spa';
            }
        }

        if (count($manifest) > 1) {
            return 'multi-entry';
        }

        return $entry_html !== '' ? 'single-entry' : 'single-entry';
    }

    private function normalize_route_manifest(array $manifest, string $entry_html, string $requested_kind): array
    {
        $normalized = [];
        foreach ($manifest as $item) {
            if (! is_array($item)) {
                continue;
            }

            $route_path = $this->normalize_route_path((string) ($item['route_path'] ?? '/'));
            $target_slug = sanitize_title((string) ($item['target_slug'] ?? $this->route_path_to_slug($route_path)));
            $target_path = trim((string) ($item['target_path'] ?? $target_slug), '/');
            $item_entry_html = ltrim(str_replace('\\', '/', (string) ($item['entry_html'] ?? $entry_html)), '/');
            $route_type = sanitize_key((string) ($item['route_type'] ?? 'entry'));
            if (! in_array($route_type, ['entry', 'spa-fallback'], true)) {
                $route_type = 'entry';
            }

            $normalized[] = [
                'route_path' => $route_path,
                'target_slug' => $target_slug,
                'target_path' => $target_path,
                'entry_html' => $item_entry_html,
                'route_type' => $route_type,
                'is_homepage' => ! empty($item['is_homepage']),
            ];
        }

        if (! $normalized) {
            $route_path = '/';
            $route_type = $requested_kind === 'spa' ? 'spa-fallback' : 'entry';
            $normalized[] = [
                'route_path' => $route_path,
                'target_slug' => $this->route_path_to_slug($route_path),
                'target_path' => '',
                'entry_html' => ltrim(str_replace('\\', '/', $entry_html), '/'),
                'route_type' => $route_type,
                'is_homepage' => true,
            ];
        }

        return array_values($normalized);
    }

    private function validate_route_manifest(array $manifest, array $html_files)
    {
        $html_files = array_map(static function (string $path): string {
            return ltrim(str_replace('\\', '/', $path), '/');
        }, $html_files);

        foreach ($manifest as $item) {
            $entry_html = ltrim(str_replace('\\', '/', (string) ($item['entry_html'] ?? '')), '/');
            if ($entry_html === '') {
                return new WP_Error('invalid_route_manifest', __('Each route manifest entry must include an entry HTML file.', 'vibepresto'));
            }

            if (! in_array($entry_html, $html_files, true)) {
                return new WP_Error('invalid_route_manifest', __('A route manifest entry points to an HTML file that is missing from the bundle.', 'vibepresto'));
            }
        }

        return true;
    }

    private function normalize_deployment_targets(array $targets, array $bundle)
    {
        $manifest = $bundle['route_manifest'];
        $normalized = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }

            $page_id = (int) ($target['page_id'] ?? 0);
            $page = $page_id > 0 ? get_post($page_id) : null;
            if (! $page instanceof WP_Post || $page->post_type !== 'page') {
                return new WP_Error('invalid_request', __('A deployment target page could not be found.', 'vibepresto'));
            }

            $route_path = $this->normalize_route_path((string) ($target['route_path'] ?? $this->route_for_page($page)));
            $manifest_entry = $this->find_manifest_entry_for_route($manifest, $route_path)
                ?: $this->find_manifest_entry_by_entry($manifest, (string) ($target['entry_html'] ?? ''))
                ?: ($manifest[0] ?? null);

            if (! $manifest_entry) {
                return new WP_Error('invalid_request', __('A deployment target could not be matched to the bundle route manifest.', 'vibepresto'));
            }

            $normalized[] = [
                'page_id' => $page_id,
                'page_title' => $page->post_title,
                'page_slug' => $page->post_name,
                'page_url' => get_permalink($page_id),
                'route_path' => $route_path,
                'target_slug' => sanitize_title((string) ($target['target_slug'] ?? $manifest_entry['target_slug'] ?? $page->post_name)),
                'target_path' => trim((string) ($target['target_path'] ?? $manifest_entry['target_path'] ?? $page->post_name), '/'),
                'entry_html' => (string) $manifest_entry['entry_html'],
                'route_type' => (string) ($manifest_entry['route_type'] ?? 'entry'),
                'is_homepage' => ! empty($target['is_homepage']) || ! empty($manifest_entry['is_homepage']),
            ];
        }

        return array_values($normalized);
    }

    private function find_manifest_entry_for_route(array $manifest, string $route_path): ?array
    {
        $route_path = $this->normalize_route_path($route_path);
        foreach ($manifest as $item) {
            if ($this->normalize_route_path((string) ($item['route_path'] ?? '/')) === $route_path) {
                return $item;
            }
        }

        foreach ($manifest as $item) {
            if (($item['route_type'] ?? '') === 'spa-fallback') {
                return $item;
            }
        }

        return null;
    }

    private function find_manifest_entry_by_entry(array $manifest, string $entry_html): ?array
    {
        $entry_html = ltrim(str_replace('\\', '/', $entry_html), '/');
        foreach ($manifest as $item) {
            if (ltrim(str_replace('\\', '/', (string) ($item['entry_html'] ?? '')), '/') === $entry_html) {
                return $item;
            }
        }

        return null;
    }

    private function route_for_page(?WP_Post $page): string
    {
        if (! $page instanceof WP_Post || $page->post_type !== 'page') {
            return '/';
        }

        $path = trim((string) get_page_uri($page), '/');
        return $path === '' ? '/' : '/' . $path;
    }

    private function route_path_to_slug(string $route_path): string
    {
        $trimmed = trim($route_path, '/');
        if ($trimmed === '') {
            return 'home';
        }

        $segments = explode('/', $trimmed);
        return sanitize_title((string) end($segments)) ?: 'page';
    }

    private function normalize_route_path(string $route_path): string
    {
        $trimmed = trim($route_path);
        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        return '/' . trim($trimmed, '/');
    }
}
