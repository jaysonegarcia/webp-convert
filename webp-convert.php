<?php
/**
 * Plugin Name:       WebP Convert
 * Description:       A WordPress plugin that automatically converts JPEG and PNG uploads to WebP, reducing image file sizes by up to 90% while keeping your site visually identical. Built to work seamlessly with W3 Total Cache + CloudFront (S3 push) CDN setups.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webp-convert
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-paths.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-w3tc-integration.php';
require_once __DIR__ . '/includes/class-converter.php';
require_once __DIR__ . '/includes/class-url-rewriter.php';
require_once __DIR__ . '/includes/class-admin-pages.php';
require_once __DIR__ . '/includes/class-plugin.php';

add_action('plugins_loaded', function () {
    if (!function_exists('wp_get_image_editor')) {
        return;
    }
    (new WebP_Convert_Plugin())->register_hooks();
});
