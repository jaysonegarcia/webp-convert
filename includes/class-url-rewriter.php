<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-end URL swapper for sibling mode.
 *
 * Swaps `.jpg`/`.png` URLs → `.webp` when a sibling exists on disk. All filters
 * run at priority 9 so theme-level CDN host rewrites at the default priority 10
 * still apply after the extension swap.
 *
 * No-op in replace mode: WordPress itself serves `.webp` URLs so nothing here matches.
 */
class WebP_Convert_URL_Rewriter
{
    public function register_hooks(): void
    {
        add_filter('wp_get_attachment_image_src', [$this, 'swap_image_src'], 9, 4);
        add_filter('wp_calculate_image_srcset', [$this, 'swap_srcset'], 9, 5);
        add_filter('wp_content_img_tag', [$this, 'swap_content_img_tag'], 9, 3);
    }

    public function swap_image_src($image, $attachment_id, $size, $icon)
    {
        if (!is_array($image) || empty($image[0])) {
            return $image;
        }
        if (!WebP_Convert_Paths::is_convertible_url($image[0])) {
            return $image;
        }
        if ($this->has_webp_sibling($image[0])) {
            $image[0] = WebP_Convert_Paths::webp_url_for($image[0]);
        }
        return $image;
    }

    public function swap_srcset($sources, $size_array, $src, $image_meta, $attachment_id)
    {
        if (!is_array($sources)) {
            return $sources;
        }
        foreach ($sources as &$source) {
            if (empty($source['url']) || !WebP_Convert_Paths::is_convertible_url($source['url'])) {
                continue;
            }
            if ($this->has_webp_sibling($source['url'])) {
                $source['url'] = WebP_Convert_Paths::webp_url_for($source['url']);
            }
        }
        return $sources;
    }

    public function swap_content_img_tag($filtered_image, $context, $attachment_id)
    {
        if (!preg_match('/src=["\']([^"\']+)["\']/', $filtered_image, $m)) {
            return $filtered_image;
        }
        $src = $m[1];
        if (!WebP_Convert_Paths::is_convertible_url($src) || !$this->has_webp_sibling($src)) {
            return $filtered_image;
        }

        $filtered_image = str_replace($src, WebP_Convert_Paths::webp_url_for($src), $filtered_image);

        if (preg_match('/srcset=["\']([^"\']+)["\']/', $filtered_image, $sm)) {
            $new_srcset = preg_replace_callback('/([^\s,]+\.(?:jpe?g|png))(\s+[^,]+)?/i', function ($parts) {
                $url        = $parts[1];
                $descriptor = $parts[2] ?? '';
                return ($this->has_webp_sibling($url) ? WebP_Convert_Paths::webp_url_for($url) : $url) . $descriptor;
            }, $sm[1]);
            $filtered_image = str_replace($sm[1], $new_srcset, $filtered_image);
        }

        return $filtered_image;
    }

    private function has_webp_sibling(string $url): bool
    {
        $path = $this->url_to_path($url);
        if (!$path) {
            return false;
        }
        return file_exists(WebP_Convert_Paths::webp_path_for($path));
    }

    private function url_to_path(string $url): ?string
    {
        $uploads = wp_get_upload_dir();
        if (empty($uploads['baseurl']) || empty($uploads['basedir'])) {
            return null;
        }

        $baseurl      = $uploads['baseurl'];
        $url_no_query = strtok($url, '?');

        if (strpos($url_no_query, $baseurl) === 0) {
            return $uploads['basedir'] . substr($url_no_query, strlen($baseurl));
        }

        $path = parse_url($url_no_query, PHP_URL_PATH);
        if (!$path) {
            return null;
        }

        $base_path = parse_url($baseurl, PHP_URL_PATH);
        if ($base_path && strpos($path, $base_path) === 0) {
            return $uploads['basedir'] . substr($path, strlen($base_path));
        }

        return null;
    }
}
