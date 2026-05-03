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
    public const PAGE_META_KEY = '_vibepresto_bundle_id';

    public function ensure_upload_root(): void
    {
        wp_mkdir_p($this->get_upload_root()['path']);
    }

    public function all(): array
    {
        $posts = get_posts([
            'post_type' => 'vibepresto_bundle',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        return array_map([$this, 'hydrate_bundle'], $posts);
    }

    public function find(int $bundle_id): ?array
    {
        $post = get_post($bundle_id);
        if (! $post instanceof WP_Post || $post->post_type !== 'vibepresto_bundle' || $post->post_status !== 'publish') {
            return null;
        }

        return $this->hydrate_bundle($post);
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

    public function delete(int $bundle_id): bool
    {
        $bundle = $this->find($bundle_id);
        if (! $bundle) {
            return false;
        }

        global $wpdb;

        $wpdb->delete(
            $wpdb->postmeta,
            [
                'meta_key' => self::PAGE_META_KEY,
                'meta_value' => $bundle_id,
            ],
            ['%s', '%d']
        );
        $this->delete_directory($bundle['storage_path']);
        return (bool) wp_delete_post($bundle_id, true);
    }

    public function create_from_zip(array $zip_file, string $display_name)
    {
        $this->ensure_upload_root();
        $this->validate_uploaded_file($zip_file, ['zip'], __('A ZIP bundle is required.', 'vibepresto'));

        $bundle_name = $this->normalize_name($display_name, $zip_file['name']);
        $tmp_path = $zip_file['tmp_name'];
        $bundle_id = $this->create_bundle_post($bundle_name);

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
        $this->persist_bundle_meta($bundle_id, 'zip', $directory, $relative_entry, $files);

        return $bundle_id;
    }

    public function create_from_files(array $html_file, array $css_file, array $js_file, array $asset_files, string $display_name)
    {
        $this->ensure_upload_root();
        $this->validate_uploaded_file($html_file, ['html', 'htm'], __('An HTML file is required.', 'vibepresto'));

        if (! empty($css_file['name'])) {
            $this->validate_uploaded_file($css_file, ['css'], __('CSS files must end in .css.', 'vibepresto'));
        }

        if (! empty($js_file['name'])) {
            $this->validate_uploaded_file($js_file, ['js'], __('JS files must end in .js.', 'vibepresto'));
        }

        $bundle_name = $this->normalize_name($display_name, $html_file['name']);
        $bundle_id = $this->create_bundle_post($bundle_name);

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
            $stored_files
        );

        return $bundle_id;
    }

    private function hydrate_bundle(WP_Post $post): array
    {
        return [
            'id' => (int) $post->ID,
            'title' => $post->post_title,
            'mode' => (string) get_post_meta($post->ID, self::MODE_META_KEY, true),
            'entry_html' => (string) get_post_meta($post->ID, self::ENTRY_META_KEY, true),
            'storage_path' => (string) get_post_meta($post->ID, self::STORAGE_PATH_META_KEY, true),
            'storage_url' => (string) get_post_meta($post->ID, self::STORAGE_URL_META_KEY, true),
            'files' => get_post_meta($post->ID, self::FILES_META_KEY, true) ?: [],
            'created_at' => $post->post_date_gmt,
            'updated_at' => $post->post_modified_gmt,
        ];
    }

    private function persist_bundle_meta(int $bundle_id, string $mode, array $directory, string $entry_html, array $files): void
    {
        update_post_meta($bundle_id, self::MODE_META_KEY, $mode);
        update_post_meta($bundle_id, self::ENTRY_META_KEY, $entry_html);
        update_post_meta($bundle_id, self::STORAGE_PATH_META_KEY, $directory['path']);
        update_post_meta($bundle_id, self::STORAGE_URL_META_KEY, $directory['url']);
        update_post_meta($bundle_id, self::FILES_META_KEY, $files);
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
