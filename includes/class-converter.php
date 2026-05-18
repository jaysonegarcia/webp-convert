<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core conversion engine. Turns Media Library JPEG/PNG attachments into WebP,
 * optionally replacing originals on disk + CDN. Also handles attachment cleanup.
 *
 * Replace mode (default, `webp_convert_replace_originals` filter defaults true):
 *  - Rewrites `_wp_attached_file`, `post_mime_type` → `image/webp`,
 *    and metadata `file`/`sizes[].file` → `.webp`.
 *  - Deletes original JPEG/PNG from disk and W3TC CDN.
 *
 * Sibling mode (filter returns false):
 *  - Originals kept on disk and CDN; `.webp` siblings sit alongside and are
 *    served via {@see WebP_Convert_URL_Rewriter}.
 */
class WebP_Convert_Converter
{
    const META_KEY = '_webp_convert_sizes';

    /** @var WebP_Convert_Settings */
    private $settings;

    /** @var WebP_Convert_W3TC_Integration */
    private $w3tc;

    public function __construct(
        WebP_Convert_Settings $settings,
        WebP_Convert_W3TC_Integration $w3tc
    ) {
        $this->settings = $settings;
        $this->w3tc     = $w3tc;
    }

    public function register_hooks(): void
    {
        add_filter('wp_generate_attachment_metadata', [$this, 'on_generate_metadata'], 20, 2);
        add_action('delete_attachment', [$this, 'cleanup_webp_files']);
    }

    public function should_replace_originals(): bool
    {
        return (bool) apply_filters('webp_convert_replace_originals', true);
    }

    public function on_generate_metadata(array $metadata, int $attachment_id): array
    {
        if (!$this->settings->is_auto_convert_enabled()) {
            return $metadata;
        }
        $result = $this->convert_attachment($attachment_id, $metadata);
        return is_array($result) ? $result : $metadata;
    }

    /**
     * Converts an attachment to WebP. If replace mode is on, also rewrites
     * metadata/post mime type and removes the originals (disk + CDN).
     *
     * @return array|null The (possibly modified) metadata on success, null on failure.
     */
    public function convert_attachment(int $attachment_id, ?array $metadata = null): ?array
    {
        $mime = get_post_mime_type($attachment_id);
        if (!in_array($mime, $this->settings->get_convertible_mimes(), true)) {
            return null;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return null;
        }

        if ($mime === 'image/png' && $this->is_animated_png($file)) {
            return null;
        }

        $metadata = $metadata ?: wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $uploads_dir = dirname($file);
        $converted   = [];

        $full_webp = $this->convert_file($file);
        if ($full_webp) {
            $converted['full'] = wp_basename($full_webp);
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $info) {
                if (empty($info['file'])) {
                    continue;
                }
                $size_file = trailingslashit($uploads_dir) . $info['file'];
                if (!file_exists($size_file)) {
                    continue;
                }
                $size_webp = $this->convert_file($size_file);
                if ($size_webp) {
                    $converted[$size] = wp_basename($size_webp);
                }
            }
        }

        if (empty($converted)) {
            return null;
        }

        update_post_meta($attachment_id, self::META_KEY, $converted);

        if ($this->should_replace_originals()) {
            $metadata = $this->replace_originals_with_webp($attachment_id, $metadata, $file);
        }

        return $metadata;
    }

    /**
     * Wrapper for the bulk/row-action code path. Persists the new metadata (which
     * triggers W3TC to upload the .webp files) when in replace mode, otherwise
     * pushes the .webp siblings explicitly since metadata hasn't changed.
     */
    public function manual_convert(int $attachment_id): bool
    {
        $result = $this->convert_attachment($attachment_id);
        if ($result === null) {
            return false;
        }

        if ($this->should_replace_originals()) {
            wp_update_attachment_metadata($attachment_id, $result);
        } else {
            $this->w3tc->push_attachment_to_cdn($attachment_id);
        }

        return true;
    }

    public function cleanup_webp_files(int $attachment_id): void
    {
        $file = get_attached_file($attachment_id);
        if (!$file || !preg_match('/\.(jpe?g|png)$/i', $file)) {
            return;
        }

        $webp = WebP_Convert_Paths::webp_path_for($file);
        if (file_exists($webp)) {
            @unlink($webp);
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dir = trailingslashit(dirname($file));
            foreach ($metadata['sizes'] as $info) {
                if (empty($info['file'])) {
                    continue;
                }
                $size_webp = WebP_Convert_Paths::webp_path_for($dir . $info['file']);
                if (file_exists($size_webp)) {
                    @unlink($size_webp);
                }
            }
        }
    }

    /**
     * Swap the attachment to use the .webp files: rewrite metadata, update the
     * post mime type + _wp_attached_file, and delete originals from disk and CDN.
     */
    private function replace_originals_with_webp(int $attachment_id, array $metadata, string $original_file): array
    {
        $dir = trailingslashit(dirname($original_file));

        $originals = [$original_file];
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $info) {
                if (!empty($info['file'])) {
                    $originals[] = $dir . $info['file'];
                }
            }
        }

        $new_metadata = $metadata;
        if (!empty($metadata['file'])) {
            $new_metadata['file'] = preg_replace('/\.(jpe?g|png)$/i', '.webp', $metadata['file']);
        }
        if (!empty($new_metadata['sizes']) && is_array($new_metadata['sizes'])) {
            foreach ($new_metadata['sizes'] as $size => &$info) {
                if (!empty($info['file'])) {
                    $info['file'] = preg_replace('/\.(jpe?g|png)$/i', '.webp', $info['file']);
                }
                $info['mime-type'] = 'image/webp';
            }
            unset($info);
        }

        wp_update_post([
            'ID'             => $attachment_id,
            'post_mime_type' => 'image/webp',
        ]);

        // Use update_attached_file() (not raw update_post_meta) so the
        // "update_attached_file" filter fires — that's how W3TC gets notified
        // to push the full-size file to the CDN.
        $new_attached = WebP_Convert_Paths::webp_path_for($original_file);
        update_attached_file($attachment_id, $new_attached);

        $this->w3tc->delete_from_cdn($originals);

        foreach ($originals as $path) {
            if (file_exists($path) && preg_match('/\.(jpe?g|png)$/i', $path)) {
                @unlink($path);
            }
        }

        return $new_metadata;
    }

    private function convert_file(string $source): ?string
    {
        $target = WebP_Convert_Paths::webp_path_for($source);
        if (file_exists($target) && filesize($target) > 0) {
            return $target;
        }

        // GD's imagewebp() throws "Palette image not supported by webp" on
        // indexed-color PNGs, which fatals the request. Detect via the IHDR
        // color-type byte and promote to truecolor through raw GD instead of
        // going through WP_Image_Editor_GD.
        if ($this->is_palette_png($source)) {
            return $this->convert_palette_png($source, $target);
        }

        $editor = wp_get_image_editor($source);
        if (is_wp_error($editor)) {
            return null;
        }

        $editor->set_quality($this->settings->quality());
        $saved = $editor->save($target, 'image/webp');
        if (is_wp_error($saved) || empty($saved['path'])) {
            return null;
        }

        return $saved['path'];
    }

    private function is_palette_png(string $file): bool
    {
        if (!preg_match('/\.png$/i', $file)) {
            return false;
        }
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return false;
        }
        $head = fread($fh, 26);
        fclose($fh);
        // PNG signature (8) + IHDR length/type (8) + width (4) + height (4)
        // + bit depth (1) + color type (1) — color type lives at offset 25.
        // Type 3 = indexed/palette.
        return is_string($head) && strlen($head) >= 26 && ord($head[25]) === 3;
    }

    private function convert_palette_png(string $source, string $target): ?string
    {
        if (!function_exists('imagecreatefrompng') || !function_exists('imagewebp')) {
            return null;
        }
        $image = @imagecreatefrompng($source);
        if (!$image) {
            return null;
        }
        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $ok = @imagewebp($image, $target, $this->settings->quality());
        imagedestroy($image);
        if (!$ok || !file_exists($target) || filesize($target) === 0) {
            if (file_exists($target)) {
                @unlink($target);
            }
            return null;
        }
        return $target;
    }

    private function is_animated_png(string $file): bool
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return false;
        }
        $bytes = fread($fh, 1024 * 64);
        fclose($fh);
        return $bytes !== false && strpos($bytes, 'acTL') !== false;
    }
}
