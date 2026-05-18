# WebP Convert — AI agent notes

Plugin that converts Media Library JPEG/PNG uploads to WebP, serves the `.webp` on the front end, and keeps W3TC CDN (CloudFront → S3 push) in sync.

## Files

```
webp-convert/
├── webp-convert.php           # plugin header + bootstrap (requires includes/, news up Plugin, register hooks on plugins_loaded)
├── includes/
│   ├── class-paths.php        # WebP_Convert_Paths           — static path/URL helpers (webp_path_for, webp_url_for, is_convertible_url)
│   ├── class-settings.php     # WebP_Convert_Settings        — option storage, defaults, sanitization, settings page render
│   ├── class-w3tc-integration.php # WebP_Convert_W3TC_Integration — CDN filters + explicit upload/delete; no-ops without W3TC
│   ├── class-converter.php    # WebP_Convert_Converter       — conversion engine + replace-originals + delete_attachment cleanup
│   ├── class-url-rewriter.php # WebP_Convert_URL_Rewriter    — front-end .jpg/.png → .webp swapping (sibling mode)
│   ├── class-admin-pages.php  # WebP_Convert_Admin_Pages     — menu, Manual Converter page, row/bulk actions, notices
│   └── class-plugin.php       # WebP_Convert_Plugin          — orchestrator; builds subsystems and calls register_hooks()
└── CLAUDE.md / README.md / CHANGELOG.md
```

Dependencies (constructor-injected):
- `Converter ← Settings, W3TC_Integration`
- `Admin_Pages ← Settings, Converter`
- `URL_Rewriter`, `W3TC_Integration`, `Settings` — no deps (W3TC/URL_Rewriter reach into `Paths` statics)

## Settings

Stored as a single option `webp_convert_settings` (see `WebP_Convert_Settings::OPTION_NAME`):

```php
[
    'auto_convert_enabled' => true,                // toggle auto-convert on upload
    'file_types'           => ['jpg','jpeg','png'], // which mimes are eligible
    'quality'              => 80,                   // 1–100, clamped server-side
]
```

Admin UI lives under a top-level menu **WebP** (registered in `Admin_Pages::register_menu_pages()`):
- **Settings** → `admin.php?page=webp-convert` → `Settings::render_settings_page()`
- **Manual Converter** → `admin.php?page=webp-manual` → `Admin_Pages::render_manual_converter_page()`

The Manual Converter queries `WP_Query` with `post_mime_type => Settings::get_convertible_mimes()`, so it automatically filters by the file-types setting. In replace mode, converted images become `image/webp` and drop out of the list.

## Behavior modes

**Replace mode** (default, controllable via filter `webp_convert_replace_originals`):
- On conversion, rewrites `_wp_attached_file`, `post_mime_type` → `image/webp`, and metadata `file`/`sizes[].file` → `.webp`.
- Deletes original JPEG/PNG from disk **and** from W3TC CDN.
- Front-end URL swap filters become no-ops since WP itself now serves `.webp`.

**Sibling mode** (`webp_convert_replace_originals` returns false):
- Originals kept on disk and CDN; `.webp` siblings sit alongside.
- URL swap at render time via `wp_get_attachment_image_src`, `wp_calculate_image_srcset`, `wp_content_img_tag` (all at priority 9 so the theme's CDN rewrite at priority 10 runs after).

## Entry points

- `Converter::on_generate_metadata` — filter `wp_generate_attachment_metadata` priority 20. Respects `auto_convert_enabled`.
- `Converter::manual_convert($id)` — used by both the Media Library row action and the Manual Converter page (bulk + per-row). Always runs regardless of the auto-convert toggle.
- `Admin_Pages::handle_row_action` — hooked on `admin_action_convert_webp` (GET + nonce flow).
- `Admin_Pages::maybe_handle_manual_convert` — hooked on `load-{manual_hook}` so redirects fire before any output.

## W3TC CDN integration (engine `cf` = CloudFront + S3 push)

All W3TC code lives in `WebP_Convert_W3TC_Integration`. W3TC only knows about files listed in WP attachment metadata, so the class integrates in two ways:

1. **Upload-time append** — filters `w3tc_cdn_update_attachment_metadata`, `w3tc_cdn_update_attachment`, `w3tc_cdn_delete_attachment` routed through `append_webp_files()`. Scans the file list for `.jpg/.png` descriptors and appends their `.webp` siblings so W3TC uploads/deletes them alongside.

2. **Explicit push for manual path** — after bulk/row conversion in sibling mode, `Converter::manual_convert()` calls `W3TC_Integration::push_attachment_to_cdn()` which invokes `Cdn_Core::upload()` directly. Not needed in replace mode because metadata changes naturally trigger W3TC.

All W3TC calls are guarded by `class_exists('\W3TC\Dispatcher')` and no-op if W3TC is absent (see `cdn_core()`).

## Critical lesson: use `update_attached_file()` not raw `update_post_meta`

In replace mode we rewrite `_wp_attached_file` from `.jpg` to `.webp` (inside `Converter::replace_originals_with_webp()`). **Must** use WP's `update_attached_file($id, $path)` helper — it fires the `update_attached_file` filter, which is how W3TC detects the change and pushes the new full-size file to S3.

Raw `update_post_meta($id, '_wp_attached_file', ...)` bypasses the filter → W3TC uploads only the thumbnail sizes (via `wp_update_attachment_metadata`) and the main `.webp` never reaches S3 → CloudFront returns S3 `AccessDenied`.

The sized thumbnails are pushed by W3TC via `wp_update_attachment_metadata` → `get_metadata_files()` — that path uses the modified metadata returned from `Converter::on_generate_metadata`, so sizes just work.

## Front-end URL swapping (sibling mode)

All three filters live in `WebP_Convert_URL_Rewriter` and swap `.jpg/.png` → `.webp` when a sibling exists on disk:

- `wp_get_attachment_image_src` (priority 9)
- `wp_calculate_image_srcset` (priority 9)
- `wp_content_img_tag` (priority 9)

Priority 9 is deliberate — the theme's CDN rewrite (`web/app/themes/impax-am/app/Callbacks/Cdn.php`) runs at default priority 10, so the CDN host swap happens after our extension swap. Don't change these priorities without accounting for that.

No `<picture>` element is used — direct URL swap only. User explicitly asked for this; modern browser WebP support is universal.

## File-type → MIME map

```php
// WebP_Convert_Settings::FILE_TYPE_MIME_MAP
[
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'svg'  => 'image/svg+xml',
]
```

SVG is exposed in the UI but `wp_get_image_editor` can't open SVGs without librsvg/Imagick SVG support, so SVG conversion will silently fail on typical setups. Kept in the UI at user's request.

## Cleanup

- `Converter::cleanup_webp_files` on `delete_attachment` — removes `.webp` siblings when a JPEG/PNG attachment is deleted in sibling mode. Guards against webp-only attachments (replace mode) so it doesn't double-delete the file WP is already handling.
- Animated PNG (`acTL` chunk in first 64KB) is detected and skipped in `Converter::is_animated_png()` — `wp_get_image_editor` would silently lose the animation.
- Settings option is intentionally **not** removed on uninstall — users keep their config across reinstalls.

## Filters for consumers

- `webp_convert_quality` (int) — override UI quality (applied last in `Settings::quality()`, wins over settings).
- `webp_convert_replace_originals` (bool) — return false to opt out of replace mode (read in `Converter::should_replace_originals()`).

## When working on this plugin

- Each subsystem owns its own hooks via `register_hooks()`, called from `WebP_Convert_Plugin::register_hooks()` on `plugins_loaded`. Don't register hooks from constructors — keeps instantiation side-effect-free.
- Shared path/URL manipulation lives in `WebP_Convert_Paths` (static). Don't re-implement `.jpg → .webp` regex inline — call the helper.
- `Paths` is used by `Converter`, `W3TC_Integration`, and `URL_Rewriter` — it's the one bit of cross-class coupling that's intentional.
- Defaults are merged in `Settings::get_settings()` at read time, so no activation hook is needed to seed options.
- Preserve the W3TC integration path ordering described above when refactoring conversion flow.
- New settings: add to `Settings::get_default_settings()`, `Settings::sanitize_settings()`, and `Settings::render_settings_page()`. Stored as one option, not individual options.
- New admin pages: add to `Admin_Pages::register_menu_pages()`. Keep pagination helpers (`render_tablenav_pages`, `render_pagination_button`) reusable if you add another list page.
