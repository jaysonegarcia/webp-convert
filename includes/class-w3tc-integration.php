<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * W3 Total Cache CDN integration (engine `cf` = CloudFront + S3 push).
 *
 * Two integration modes:
 *  1. Filter hooks append .webp file descriptors to W3TC's upload/delete lists
 *     so siblings travel alongside originals during the normal metadata flow.
 *  2. Explicit upload/delete calls from the conversion pipeline for the manual
 *     path (sibling mode only) where metadata doesn't change.
 *
 * All calls no-op cleanly when W3TC is absent.
 */
class WebP_Convert_W3TC_Integration
{
    public function register_hooks(): void
    {
        add_filter('w3tc_cdn_update_attachment_metadata', [$this, 'append_webp_files']);
        add_filter('w3tc_cdn_update_attachment', [$this, 'append_webp_files']);
        add_filter('w3tc_cdn_delete_attachment', [$this, 'append_webp_files']);
    }

    /**
     * W3TC filter callback: appends .webp file descriptors so W3TC uploads/deletes
     * them alongside the originals during the standard CDN flow.
     */
    public function append_webp_files($files)
    {
        if (!is_array($files) || empty($files)) {
            return $files;
        }

        $cdn_core = $this->cdn_core();
        if (!$cdn_core) {
            return $files;
        }

        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? trailingslashit($uploads['basedir']) : '';
        if (!$basedir) {
            return $files;
        }

        $seen = [];
        foreach ($files as $file) {
            if (!empty($file['local_path'])) {
                $seen[$file['local_path']] = true;
            }
        }

        $extra = [];
        foreach ($files as $file) {
            if (empty($file['local_path'])) {
                continue;
            }
            $local = $file['local_path'];
            if (!preg_match('/\.(jpe?g|png)$/i', $local)) {
                continue;
            }
            $webp_local = WebP_Convert_Paths::webp_path_for($local);
            if (!file_exists($webp_local) || isset($seen[$webp_local])) {
                continue;
            }
            if (strpos($webp_local, $basedir) !== 0) {
                continue;
            }
            $relative = substr($webp_local, strlen($basedir));
            foreach ($cdn_core->get_files_for_upload($relative) as $descriptor) {
                $extra[] = $descriptor;
                $seen[$webp_local] = true;
            }
        }

        return array_merge($files, $extra);
    }

    public function delete_from_cdn(array $local_paths): void
    {
        $cdn_core = $this->cdn_core();
        if (!$cdn_core || !method_exists($cdn_core, 'delete')) {
            return;
        }

        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? trailingslashit($uploads['basedir']) : '';
        if (!$basedir) {
            return;
        }

        $files = [];
        foreach ($local_paths as $path) {
            if (strpos($path, $basedir) !== 0) {
                continue;
            }
            $relative = substr($path, strlen($basedir));
            foreach ($cdn_core->get_files_for_upload($relative) as $descriptor) {
                $files[] = $descriptor;
            }
        }

        if (empty($files)) {
            return;
        }

        $results = [];
        $cdn_core->delete($files, false, $results);
    }

    /**
     * Push generated .webp siblings to the W3TC CDN explicitly. Needed for the
     * manual/bulk conversion path in sibling mode, which doesn't trigger
     * wp_update_attachment_metadata and therefore doesn't fire W3TC's own upload flow.
     */
    public function push_attachment_to_cdn(int $attachment_id): void
    {
        if (!class_exists('\W3TC\Dispatcher')) {
            return;
        }

        $cdn_core = $this->cdn_core();
        if (!$cdn_core) {
            return;
        }

        $webp_relatives = $this->webp_relative_paths_for_attachment($attachment_id);
        if (empty($webp_relatives)) {
            return;
        }

        $files = [];
        foreach ($webp_relatives as $relative) {
            foreach ($cdn_core->get_files_for_upload($relative) as $descriptor) {
                $files[] = $descriptor;
            }
        }

        if (empty($files)) {
            return;
        }

        $results = [];
        $cdn_core->upload($files, true, $results);
    }

    private function cdn_core()
    {
        if (!class_exists('\W3TC\Dispatcher')) {
            return null;
        }
        try {
            return \W3TC\Dispatcher::component('Cdn_Core');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function webp_relative_paths_for_attachment(int $attachment_id): array
    {
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return [];
        }

        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? trailingslashit($uploads['basedir']) : '';
        if (!$basedir || strpos($file, $basedir) !== 0) {
            return [];
        }

        $paths = [WebP_Convert_Paths::webp_path_for($file)];

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dir = trailingslashit(dirname($file));
            foreach ($metadata['sizes'] as $info) {
                if (empty($info['file'])) {
                    continue;
                }
                $paths[] = WebP_Convert_Paths::webp_path_for($dir . $info['file']);
            }
        }

        $relatives = [];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }
            if (strpos($path, $basedir) !== 0) {
                continue;
            }
            $relatives[] = substr($path, strlen($basedir));
        }

        return array_unique($relatives);
    }
}
