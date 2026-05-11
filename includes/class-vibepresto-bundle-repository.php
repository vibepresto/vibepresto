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
    public const PAGE_META_KEY = '_vibepresto_bundle_id';

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

    public function assign_to_page(int $page_id, int $bundle_id): void
    {
        update_post_meta($page_id, self::PAGE_META_KEY, $bundle_id);
    }

    public function clear_page_assignment(int $page_id): void
    {
        delete_post_meta($page_id, self::PAGE_META_KEY);
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
        } else {
            $this->clear_page_assignments_for_bundle($bundle_id);
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
        $this->persist_bundle_meta($bundle_id, 'zip', $directory, $relative_entry, $files, $draft);

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

        $this->persist_bundle_meta(
            $bundle_id,
            'separate',
            $directory,
            $this->relative_path($directory['path'], $entry_file),
            $stored_files,
            $draft
        );

        return $bundle_id;
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
                : $lineage_name;
        }

        $is_current = get_post_meta($post->ID, self::CURRENT_META_KEY, true);
        $is_current = $is_current === '' ? true : ((int) $is_current === 1);

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
            'entry_html' => (string) get_post_meta($post->ID, self::ENTRY_META_KEY, true),
            'storage_path' => (string) get_post_meta($post->ID, self::STORAGE_PATH_META_KEY, true),
            'storage_url' => (string) get_post_meta($post->ID, self::STORAGE_URL_META_KEY, true),
            'files' => get_post_meta($post->ID, self::FILES_META_KEY, true) ?: [],
            'created_at' => $post->post_date_gmt,
            'updated_at' => $post->post_modified_gmt,
        ];
    }

    private function prepare_version_draft(string $display_name, string $fallback_file_name, array $options)
    {
        $requested_lineage_id = isset($options['lineage_id']) ? (int) $options['lineage_id'] : 0;
        $source_page_id = isset($options['source_page_id']) ? (int) $options['source_page_id'] : 0;

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
        ];
    }

    private function persist_bundle_meta(int $bundle_id, string $mode, array $directory, string $entry_html, array $files, array $draft): void
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
        return $version_number > 1
            ? sprintf('%s v%d', $lineage_name, $version_number)
            : sprintf('%s v1', $lineage_name);
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
}
