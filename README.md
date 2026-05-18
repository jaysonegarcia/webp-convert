# WebP Convert

A WordPress plugin that automatically converts JPEG and PNG uploads to WebP, reducing image file sizes by up to 90% while keeping your site visually identical. Built to work seamlessly with W3 Total Cache + CloudFront (S3 push) CDN setups.

## Features

- **Automatic conversion on upload** — JPEG/PNG files (original + every thumbnail size) are converted to WebP during the upload flow.
- **Replace mode** — originals are removed from disk and CDN; only `.webp` files remain. Attachment URLs point to `.webp` directly.
- **Manual Converter** — a dedicated admin page to convert existing images one-by-one or in bulk.
- **Media Library integration** — "Convert to WebP" row action and bulk action in the standard Media Library.
- **W3 Total Cache CDN-aware** — pushes `.webp` files to S3 alongside (or in place of) originals; cleans up orphaned files on deletion.
- **Front-end URL swapping** (when replace mode is off) — uses `<img>` tag filters to serve `.webp` via `src` and `srcset` without requiring `<picture>` markup.
- **Configurable quality** — 1–100 slider (default 80).

## Requirements

- WordPress 6.0+
- PHP 8.0+
- GD or Imagick with WebP support (PHP 7.0+ includes GD WebP by default)
- Optional: W3 Total Cache with CDN enabled, for S3/CloudFront push sync

## Installation

Drop the `webp-convert/` folder into `wp-content/plugins/` (or `web/app/plugins/` on Bedrock), then activate **WebP Convert** from the WordPress Plugins screen.

## Usage

### Admin menu

A top-level **WebP** menu appears in the WordPress admin with:

1. **Settings** — toggle auto-convert, pick file types, adjust quality.
2. **Manual Converter** — browse eligible images and convert them with per-row or bulk actions.

### Settings

| Setting | Description | Default |
|---|---|---|
| Automatic conversion | Convert new uploads automatically | Enabled |
| Auto-convert file types | Which mime types are eligible (JPG, JPEG, PNG, SVG) | JPG + JPEG + PNG |
| Quality | WebP compression quality (1–100) | 80 |

> **SVG note:** the WordPress image editor (GD/Imagick without librsvg) cannot convert SVG to WebP. The option is exposed for completeness but is a no-op on standard hosting.

### Converting existing images

Go to **WebP → Manual Converter**:

- The list shows every image whose mime type matches the Settings checkboxes.
- Tick individual rows and click **Convert Selected**, or click **Convert** on a single row.
- In replace mode, each converted image becomes `image/webp` and disappears from the list — so the page always shows the "still to do" pile.

Alternatively, use the **Media Library → List view → Bulk actions → Convert to WebP**.

## Developer notes

### Filters

```php
// Override the WebP quality (takes precedence over the settings UI).
add_filter('webp_convert_quality', fn() => 85);

// Disable replace mode — keep originals on disk and serve .webp via URL swap instead.
add_filter('webp_convert_replace_originals', '__return_false');
```

### How it works with W3 Total Cache

In replace mode the plugin:

1. Creates `.webp` versions on disk using WP's native image editor.
2. Rewrites `_wp_attached_file`, `post_mime_type`, and attachment metadata to point to `.webp`.
3. Calls `update_attached_file()` — this triggers W3TC's CDN hook to push the full-size `.webp` to S3.
4. WordPress then saves the metadata, triggering W3TC to push the thumbnail `.webp` sizes.
5. The original `.jpg`/`.png` files are deleted from disk **and** from the CDN.

If you're not using W3TC, the plugin simply skips the CDN integration — no configuration needed.

### Caveats

- **Direct links to original `.jpg` / `.png` URLs will 404** in replace mode (external backlinks, emails, social posts). If that's a concern for your site, disable replace mode via the filter above.
- Converting a large existing library synchronously may approach PHP timeouts. Convert in batches of ~50–100 at a time.
- CloudFront may serve cached 403/404 responses for `.webp` URLs requested before the file was pushed. Flush the CDN cache after initial bulk conversions.

## Support

See `CLAUDE.md` for technical implementation notes.
