<?php
// Standalone test: stubs the WP + AWS surface the invalidator touches, then asserts.
// Run: php web/app/plugins/webp-convert/tests/test-cdn-invalidator.php
error_reporting(E_ALL);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

// --- WP stubs ---
$GLOBALS['__options'] = [];
function get_option($name, $default = false)
{
    return $GLOBALS['__options'][$name] ?? $default;
}

// Minimal Settings stub matching the real class's OPTION_NAME + get_settings().
class WebP_Convert_Settings
{
    const OPTION_NAME = 'webp_convert_settings';
    public function get_settings(): array
    {
        return array_merge([
            'cdn_enabled'         => false,
            'cdn_access_key'      => '',
            'cdn_secret_key'      => '',
            'cdn_distribution_id' => '',
            'cdn_region'          => 'us-east-1',
        ], get_option(self::OPTION_NAME, []) ?: []);
    }
}

require __DIR__ . '/../includes/class-cdn-invalidator.php';

function ok($cond, $msg)
{
    echo ($cond ? 'PASS' : 'FAIL') . " - $msg\n";
    if (!$cond) {
        $GLOBALS['__fail'] = true;
    }
}

// env() overrides option
putenv('WEBP_CDN_AWS_REGION=eu-west-2');
$GLOBALS['__options']['webp_convert_settings'] = ['cdn_region' => 'us-east-1'];
$inv = new WebP_Convert_CDN_Invalidator(new WebP_Convert_Settings());
ok($inv->region() === 'eu-west-2', 'env region overrides option');
putenv('WEBP_CDN_AWS_REGION');

// option used when env unset; default region
$GLOBALS['__options']['webp_convert_settings'] = [];
$inv = new WebP_Convert_CDN_Invalidator(new WebP_Convert_Settings());
ok($inv->region() === 'us-east-1', 'region defaults to us-east-1');

// enable is DB-only (admin-controlled), not env-overridable
putenv('WEBP_CDN_ENABLED=1');
$GLOBALS['__options']['webp_convert_settings'] = ['cdn_enabled' => false];
$inv = new WebP_Convert_CDN_Invalidator(new WebP_Convert_Settings());
ok($inv->is_enabled() === false, 'enable ignores env (admin-controlled), stays off when option off');
putenv('WEBP_CDN_ENABLED');
$GLOBALS['__options']['webp_convert_settings'] = ['cdn_enabled' => true];
$inv = new WebP_Convert_CDN_Invalidator(new WebP_Convert_Settings());
ok($inv->is_enabled() === true, 'enable reads DB option');

// wp-config.php constant fallback when no env var set
$GLOBALS['__options']['webp_convert_settings'] = [];
define('WEBP_CDN_DISTRIBUTION_ID', 'ECONST123');
$inv = new WebP_Convert_CDN_Invalidator(new WebP_Convert_Settings());
ok($inv->distribution_id() === 'ECONST123', 'wp-config.php constant used when env unset');
// env still wins over the constant
putenv('WEBP_CDN_DISTRIBUTION_ID=EENV456');
ok($inv->distribution_id() === 'EENV456', 'env var overrides wp-config.php constant');
putenv('WEBP_CDN_DISTRIBUTION_ID');

// is_ready false when disabled
$GLOBALS['__options']['webp_convert_settings'] = [
    'cdn_enabled' => false, 'cdn_access_key' => 'AK', 'cdn_secret_key' => 'SK', 'cdn_distribution_id' => 'E1',
];
$inv = new WebP_Convert_CDN_Invalidator(new WebP_Convert_Settings());
ok($inv->is_ready() === false, 'is_ready false when disabled');

// is_ready false when missing distribution id even if enabled+creds
$GLOBALS['__options']['webp_convert_settings'] = [
    'cdn_enabled' => true, 'cdn_access_key' => 'AK', 'cdn_secret_key' => 'SK', 'cdn_distribution_id' => '',
];
$inv = new WebP_Convert_CDN_Invalidator(new WebP_Convert_Settings());
ok($inv->is_ready() === false, 'is_ready false without distribution id');

// --- path derivation + invalidation dispatch ---
function wp_get_upload_dir()
{
    return ['basedir' => '/var/www/uploads', 'baseurl' => 'http://example.com/app/uploads'];
}
function get_attached_file($id)
{
    return '/var/www/uploads/2024/06/img.webp';
}
function wp_get_attachment_metadata($id)
{
    return ['sizes' => ['thumb' => ['file' => 'img-150x150.webp']]];
}

// Paths stub: identity for .webp inputs (real class returns .webp sibling of .jpg/.png; here inputs are already .webp)
class WebP_Convert_Paths
{
    public static function webp_path_for($p)
    {
        return preg_replace('/\.(jpe?g|png)$/i', '.webp', $p);
    }
}

// Test subclass: force ready, capture create_invalidation args, pretend all files exist.
class Test_Invalidator extends WebP_Convert_CDN_Invalidator
{
    public $captured = null;
    public function is_ready(): bool
    {
        return true;
    }
    protected function file_exists_path(string $p): bool
    {
        return true;
    }
    protected function create_invalidation(array $paths): void
    {
        $this->captured = $paths;
    }
}

$t     = new Test_Invalidator(new WebP_Convert_Settings());
$paths = $t->webp_url_paths_for_attachment(1);
ok(in_array('/app/uploads/2024/06/img.webp', $paths, true), 'full-size url path derived');
ok(in_array('/app/uploads/2024/06/img-150x150.webp', $paths, true), 'size url path derived');

$t->invalidate_attachment(1);
ok(is_array($t->captured) && in_array('/app/uploads/2024/06/img.webp', $t->captured, true), 'invalidate_attachment dispatches derived paths');

// gating: not ready => no dispatch
$g = new WebP_Convert_CDN_Invalidator(new WebP_Convert_Settings());
ok($g->invalidate_paths(['/x']) === false, 'invalidate_paths no-ops when not ready');

echo empty($GLOBALS['__fail']) ? "\nALL PASS\n" : "\nFAILURES\n";
exit(empty($GLOBALS['__fail']) ? 0 : 1);
