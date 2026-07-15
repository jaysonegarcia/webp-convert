<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core conversion engine. Turns Media Library JPEG/PNG attachments into WebP,
 * optionally replacing originals on disk + CDN. Also handles attachment cleanup.
 *
 * Replace mode (default, `webp_replace_originals` filter defaults true):
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
    public const META_KEY = '_webp_sizes';

    /** @var WebP_Convert_Settings */
    private $settings;

    /** @var WebP_Convert_W3TC_Integration */
    private $w3tc;

    /** @var WebP_Convert_CDN_Invalidator */
    private $cdn;

    public function __construct(
        WebP_Convert_Settings $settings,
        WebP_Convert_W3TC_Integration $w3tc,
        WebP_Convert_CDN_Invalidator $cdn,
    ) {
        $this->settings = $settings;
        $this->w3tc     = $w3tc;
        $this->cdn      = $cdn;
    }

    public function register_hooks(): void
    {
        add_filter('wp_generate_attachment_metadata', [$this, 'on_generate_metadata'], 20, 2);
        add_action('delete_attachment', [$this, 'cleanup_webp_files']);
    }

    public function should_replace_originals(): bool
    {
        return (bool) apply_filters('webp_replace_originals', true);
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

        $metadata = $this->refresh_metadata_filesizes($attachment_id, $metadata);

        return $metadata;
    }

    /**
     * Recompute the `filesize` values stored in attachment metadata from the
     * actual files on disk (full-size top-level + every thumbnail). WordPress
     * (6.0+) shows the Media Library "File size" from this metadata, not from the
     * file itself — so after we re-encode to WebP or regenerate at a new quality,
     * it stays stale (e.g. showing the original PNG size) until this refreshes it.
     */
    private function refresh_metadata_filesizes(int $attachment_id, array $metadata): array
    {
        clearstatcache();

        $file = get_attached_file($attachment_id);
        if ($file && file_exists($file)) {
            $metadata['filesize'] = (int) filesize($file);
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes']) && $file) {
            $dir = trailingslashit(dirname($file));
            foreach ($metadata['sizes'] as $size => $info) {
                if (empty($info['file'])) {
                    continue;
                }
                $path = $dir . $info['file'];
                if (file_exists($path)) {
                    $metadata['sizes'][$size]['filesize'] = (int) filesize($path);
                }
            }
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

    /**
     * Re-encode an already-converted attachment's WebP files (full size + every
     * thumbnail) in place at the current quality setting, then re-push them to
     * the W3TC CDN.
     *
     * Applies only to attachments whose attached file is already .webp (i.e.
     * converted in replace mode). The original JPEG/PNG no longer exists, so the
     * re-encode reads from the existing WebP: a lower quality shrinks the files,
     * a higher quality cannot restore detail already discarded.
     *
     * @return bool True if at least one WebP file was re-encoded.
     */
    public function regenerate_attachment(int $attachment_id): bool
    {
        $file = get_attached_file($attachment_id);
        if (!$file || !preg_match('/\.webp$/i', $file)) {
            return false;
        }

        $quality = $this->settings->quality();
        $targets = [$file];

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dir = trailingslashit(dirname($file));
            foreach ($metadata['sizes'] as $info) {
                if (empty($info['file']) || !preg_match('/\.webp$/i', $info['file'])) {
                    continue;
                }
                $targets[] = $dir . $info['file'];
            }
        }

        $changed = 0;
        foreach (array_unique($targets) as $path) {
            if (file_exists($path) && $this->reencode_webp_in_place($path, $quality)) {
                $changed++;
            }
        }

        if ($changed === 0) {
            return false;
        }

        // Persist the new on-disk sizes so the Media Library "File size" (read
        // from attachment metadata, not the file) reflects the re-encode.
        $metadata = $this->refresh_metadata_filesizes($attachment_id, is_array($metadata) ? $metadata : []);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Re-upload the refreshed .webp files to the CDN (overwrite). Resolves
        // the same webp paths the upload flow uses and no-ops without W3TC.
        $this->w3tc->push_attachment_to_cdn($attachment_id);

        // Bust CloudFront edges so the re-encoded .webp is served immediately
        // (the S3 key was overwritten but edges still hold the prior copy).
        $this->cdn->invalidate_attachment($attachment_id);

        return true;
    }

    /**
     * Re-encode a single .webp file in place at the given quality. Writes to a
     * temp sibling first, then atomically replaces the original so a failed or
     * partial encode never truncates the live file.
     */
    private function reencode_webp_in_place(string $path, int $quality): bool
    {
        $tmp   = $path . '.regen.webp';
        $saved = $this->encode_webp_lossy($path, $tmp, $quality);
        if ($saved === null || !file_exists($saved) || filesize($saved) < 1) {
            if ($saved !== null && file_exists($saved)) {
                @unlink($saved);
            }
            return false;
        }

        if (!@rename($saved, $path)) {
            @copy($saved, $path);
            @unlink($saved);
        }

        return true;
    }

    /**
     * Re-encode a source image to LOSSY WebP at the given quality, bypassing
     * WP_Image_Editor entirely.
     *
     * Required for Regenerate: WordPress's Imagick editor forces lossless output
     * (quality 100 + webp:lossless) whenever the *source* WebP is itself lossless
     * (see WP_Image_Editor_Imagick::set_quality() → wp_get_webp_info()). That
     * silently discards the requested quality, so lowering it never shrinks the
     * file. Encoding directly forces lossy so the Quality setting actually applies.
     *
     * GD is tried first (production runs GD, not Imagick), Imagick as a fallback.
     * Both encode lossy here: GD's imagewebp() is always lossy, and we set
     * webp:lossless=false explicitly on Imagick.
     *
     * @return string|null Target path on success, null on failure.
     */
    private function encode_webp_lossy(string $source, string $target, int $quality): ?string
    {
        if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
            $img = @imagecreatefromwebp($source);
            if ($img) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                $ok = @imagewebp($img, $target, $quality); // GD WebP output is always lossy
                imagedestroy($img);
                if ($ok && file_exists($target) && filesize($target) > 0) {
                    return $target;
                }
                if (file_exists($target)) {
                    @unlink($target);
                }
            }
        }

        if (class_exists('Imagick')) {
            try {
                $im = new \Imagick($source);
                $im->setImageFormat('webp');
                $im->setOption('webp:lossless', 'false');
                $im->setImageCompressionQuality($quality);
                $ok = $im->writeImage($target);
                $im->clear();
                $im->destroy();
                if ($ok && file_exists($target) && filesize($target) > 0) {
                    return $target;
                }
                if (file_exists($target)) {
                    @unlink($target);
                }
            } catch (\Throwable $e) {
                if (file_exists($target)) {
                    @unlink($target);
                }
            }
        }

        return null;
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

        return $this->save_webp($editor, $target, $this->settings->quality());
    }

    /**
     * Save an image editor's current image as WebP at the given quality.
     *
     * Quality is routed through the wp_editor_set_quality filter rather than
     * relying solely on $editor->set_quality(). When the editor was created
     * from a JPEG/PNG source, set_quality() runs against the *source* mime type
     * (for JPEG it even forces COMPRESSION_JPEG) and WordPress then encodes the
     * WebP at its own default quality, discarding the value we passed. The
     * filter supplies the quality WordPress actually uses when writing WebP, so
     * it applies regardless of the source format.
     *
     * @return string|null Saved file path on success, null on failure.
     */
    private function save_webp($editor, string $target, int $quality): ?string
    {
        $apply_quality = static function ($default_quality, $mime_type) use ($quality) {
            return 'image/webp' === $mime_type ? $quality : $default_quality;
        };
        add_filter('wp_editor_set_quality', $apply_quality, 10, 2);

        $editor->set_quality($quality);
        $saved = $editor->save($target, 'image/webp');

        remove_filter('wp_editor_set_quality', $apply_quality, 10);

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
