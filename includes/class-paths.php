<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure path/URL helpers for mapping between .jpg/.png and .webp.
 */
class WebP_Convert_Paths
{
    public static function webp_path_for(string $source): string
    {
        return preg_replace('/\.(jpe?g|png)$/i', '.webp', $source);
    }

    public static function webp_url_for(string $url): string
    {
        return preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $url);
    }

    public static function is_convertible_url(string $url): bool
    {
        return (bool) preg_match('/\.(jpe?g|png)(\?.*)?$/i', $url);
    }
}
