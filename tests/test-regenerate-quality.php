<?php
// Standalone test: proves Regenerate honors the quality setting even when the
// SOURCE webp is lossless (the WP_Image_Editor_Imagick lossless-forcing bug).
// Run: docker compose exec -T php php web/app/plugins/webp-convert/tests/test-regenerate-quality.php
error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

require __DIR__ . '/../includes/class-converter.php';

function ok($cond, $msg)
{
    echo ($cond ? 'PASS' : 'FAIL') . " - $msg\n";
    if (!$cond) {
        $GLOBALS['fail'] = true;
    }
}

// WebP chunk fourcc lives at bytes 12-15: 'VP8 ' = lossy, 'VP8L' = lossless.
function webp_kind(string $path): string
{
    $head = file_get_contents($path, false, null, 0, 16);
    return $head !== false && strlen($head) >= 16 ? substr($head, 12, 4) : '????';
}

if (!function_exists('imagecreatefromwebp')) {
    echo "SKIP - GD WebP not available\n";
    exit(0);
}
if (!class_exists('Imagick')) {
    echo "SKIP - Imagick needed only to build the lossless fixture\n";
    exit(0);
}

// Build a smooth gradient (quality strongly affects lossy size for gradients).
$w   = 400;
$h   = 400;
$img = new Imagick();
$img->newImage($w, $h, new ImagickPixel('white'), 'png');
$draw = new ImagickDraw();
for ($x = 0; $x < $w; $x++) {
    $draw->setStrokeColor(new ImagickPixel(sprintf('rgb(%d,%d,%d)', ($x * 255) / $w, (($w - $x) * 255) / $w, ($x * 127) / $w)));
    $draw->line($x, 0, $x, $h);
}
$img->drawImage($draw);

// Write it as a LOSSLESS webp — reproduces the user's uploaded file condition.
$src = sys_get_temp_dir() . '/regen-src-' . getmypid() . '.webp';
$img->setImageFormat('webp');
$img->setOption('webp:lossless', 'true');
$img->setImageCompressionQuality(100);
$img->writeImage($src);
$img->clear();

ok(webp_kind($src) === 'VP8L', 'source webp is lossless (VP8L) — reproduces the bug condition');

$conv = (new ReflectionClass('WebP_Convert_Converter'))->newInstanceWithoutConstructor();

// Re-encode the lossless source at two different qualities via encode_webp_lossy.
$encode = new ReflectionMethod('WebP_Convert_Converter', 'encode_webp_lossy');
$encode->setAccessible(true);
$out10 = sys_get_temp_dir() . '/regen-q10-' . getmypid() . '.webp';
$out90 = sys_get_temp_dir() . '/regen-q90-' . getmypid() . '.webp';
$encode->invoke($conv, $src, $out10, 10);
$encode->invoke($conv, $src, $out90, 90);

ok(webp_kind($out10) === 'VP8 ', 'q10 output is LOSSY (VP8 ) — lossless override defeated');
ok(webp_kind($out90) === 'VP8 ', 'q90 output is LOSSY (VP8 )');
$s10 = filesize($out10);
$s90 = filesize($out90);
ok($s10 < $s90, "lower quality yields a smaller file — quality is honored (q10=$s10 < q90=$s90 bytes)");

// End-to-end in-place re-encode returns true and leaves a lossy file.
$work = sys_get_temp_dir() . '/regen-work-' . getmypid() . '.webp';
copy($src, $work);
$inplace = new ReflectionMethod('WebP_Convert_Converter', 'reencode_webp_in_place');
$inplace->setAccessible(true);
$result = $inplace->invoke($conv, $work, 10);
ok($result === true, 'reencode_webp_in_place returns true');
ok(webp_kind($work) === 'VP8 ', 'in-place regenerated file is now lossy (VP8 )');

@unlink($src);
@unlink($out10);
@unlink($out90);
@unlink($work);

echo empty($GLOBALS['fail']) ? "\nALL PASS\n" : "\nFAILURES\n";
exit(empty($GLOBALS['fail']) ? 0 : 1);
