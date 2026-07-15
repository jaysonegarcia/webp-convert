# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-07-15

### Added
- **Regenerate WebP** admin page (**WebP Convert → Regenerate WebP**): re-encodes already-converted `image/webp` attachments — full size and every thumbnail — in place at the current Quality setting, then re-pushes them to the W3TC CDN. Includes a paginated list with current file sizes, per-row and bulk (AJAX progress) actions, plus matching Media Library row and bulk actions ("Regenerate WebP").
- `WebP_Convert_Converter::regenerate_attachment()` and the private `reencode_webp_in_place()` helper (encodes to a temp sibling, then atomically replaces the original so a failed encode never truncates the live file).
- **CDN status panel on the Settings screen.** Shows whether the AWS SDK is loaded (with version and whether it's bundled with the plugin or provided by the site) and whether cache invalidation is "Ready" (listing anything missing). A **"Test CloudFront connection"** button runs a live, harmless invalidation against the saved settings and reports success or the exact AWS error — verifying credentials, the Distribution ID, and the `cloudfront:CreateInvalidation` permission in one click.

### Fixed
- The Quality setting had no effect on WebP output. `set_quality()` was applied while the editor's mime type was still the JPEG/PNG source, so WordPress encoded the WebP at its own default quality and discarded the configured value. Quality is now routed through the `wp_editor_set_quality` filter (in the new shared `Converter::save_webp()` helper), so the setting is honored on every conversion.
- **Media Library "File size" now reflects the converted/regenerated file.** WordPress reads that value from the stored attachment metadata (`filesize`), not the file on disk, so it stayed at the original upload's size (e.g. "14 KB" for a PNG that became a 2 KB WebP). Convert and Regenerate now recompute and persist `filesize` for the full size and every thumbnail via the new `Converter::refresh_metadata_filesizes()`.
- **Regenerate now honors the Quality setting even when the source WebP is lossless.** If a converted file was stored as lossless WebP (e.g. converted at quality 100, or a flat image), `WP_Image_Editor_Imagick::set_quality()` force-encodes it lossless (quality 100) and discards the requested quality, so lowering the setting and regenerating changed nothing. `reencode_webp_in_place()` now re-encodes directly via `Converter::encode_webp_lossy()` (GD first — matching production — then Imagick, both forced lossy), so the Quality setting reduces file size as expected. Verified: a lossless 4298-byte image regenerated at quality 10 → 2436 bytes. Added `tests/test-regenerate-quality.php`.
- **Per-row Regenerate/Convert actions no longer redirect to the Media Library.** The row-action links on the Regenerate and Manual Converter pages share their handler with the Media Library, so they relied on the HTTP `Referer` to return — when the browser omitted it, users were dropped on `upload.php`. The links now carry an explicit `webp_return` hint and redirect back to the originating WebP Convert page.
- **File sizes display in decimal (SI) units.** The Regenerate list and AJAX result now format sizes as decimal KB/MB with one decimal (e.g. 2604 bytes → "2.6 KB"), matching file managers and `ls -lh`. Previously `size_format()` used binary units and rounded to whole KB, showing "3 KB" for a 2.6 KB file.

### Changed
- Extracted the Manual Converter's batch progress script into a shared `Admin_Pages::print_batch_progress_script()` reused by both the Manual Converter and Regenerate pages.

## [1.0.2] - 2026-04-17

### Changed
- Manual Converter bulk conversion now runs over AJAX, processing one attachment at a time so long batches no longer hit `max_execution_time`.
- Added a live progress bar with per-item success/failure log on the Manual Converter page.

## [1.0.1] - 2026-04-16

### Changed
- Converted from a must-use plugin to a regular activatable plugin. Install to `wp-content/plugins/` and activate from the Plugins screen.
- Standardized the plugin header (docblock style; added `License`, `License URI`, `Requires at least`, `Requires PHP`).
- Refactored the single-file codebase into focused classes under `includes/` (`Paths`, `Settings`, `Converter`, `URL_Rewriter`, `W3TC_Integration`, `Admin_Pages`, `Plugin`). All hooks, option keys, filter names, and admin slugs are preserved — no behavior change.

## [1.0.0] - 2026-04-15

### Added
- Automatic WebP conversion of JPEG/PNG uploads via `wp_generate_attachment_metadata`.
- Admin UI under **WebP Convert** with Settings and Manual Converter pages.
- Manual conversion via Media Library row action and bulk/per-row actions on the Manual Converter page.
- Replace mode (default): rewrites attachment metadata to `.webp`, deletes originals from disk and W3TC CDN.
- Sibling mode (via `webp_replace_originals` filter): keeps originals and swaps URLs at render time.
- Front-end URL swap filters at priority 9 (`wp_get_attachment_image_src`, `wp_calculate_image_srcset`, `wp_content_img_tag`) to run before the theme's CDN rewrite.
- W3TC CloudFront/S3 integration: appends `.webp` siblings to CDN upload/delete file lists and pushes converted files via `Cdn_Core::upload()` in sibling mode.
- Cleanup of `.webp` siblings on `delete_attachment`.
- Animated PNG detection (skipped to preserve animation).
- Filters: `webp_quality`, `webp_replace_originals`.

[1.1.0]: https://github.com/jaysonegarcia/webp-convert/releases/tag/v1.1.0
[1.0.2]: https://github.com/jaysonegarcia/webp-convert/releases/tag/v1.0.2
[1.0.1]: https://github.com/jaysonegarcia/webp-convert/releases/tag/v1.0.1
[1.0.0]: https://github.com/jaysonegarcia/webp-convert/releases/tag/v1.0.0
