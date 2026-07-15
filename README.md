# WebP Convert

A WordPress plugin that automatically converts JPEG and PNG uploads to WebP, reducing image file sizes by up to 90% while keeping your site visually identical. Built to work seamlessly with W3 Total Cache + CloudFront (S3 push) CDN setups.

## Features

- **Automatic conversion on upload** — JPEG/PNG files (original + every thumbnail size) are converted to WebP during the upload flow.
- **Replace mode** — originals are removed from disk and CDN; only `.webp` files remain. Attachment URLs point to `.webp` directly.
- **Manual Converter** — a dedicated admin page to convert existing images one-by-one or in bulk.
- **Regenerate WebP** — a dedicated admin page to re-encode already-converted WebP images (full size + every thumbnail) in place at the current quality setting, with CDN re-push.
- **Media Library integration** — "Convert to WebP" and "Regenerate WebP" row actions and bulk actions in the standard Media Library.
- **W3 Total Cache CDN-aware** — pushes `.webp` files to S3 alongside (or in place of) originals; cleans up orphaned files on deletion.
- **CloudFront cache invalidation** — optionally invalidates changed image paths on CloudFront after convert/regenerate so edges stop serving stale copies. AWS credentials + distribution are configurable in-screen or via `.env`.
- **Front-end URL swapping** (when replace mode is off) — uses `<img>` tag filters to serve `.webp` via `src` and `srcset` without requiring `<picture>` markup.
- **Configurable quality** — 1–100 slider (default 80).

## Requirements

- WordPress 6.0+
- PHP 7.4+
- GD or Imagick with WebP support (PHP 7.0+ includes GD WebP by default)
- Optional: W3 Total Cache with CDN enabled, for S3/CloudFront push sync

## Installation

Drop the `webp-convert/` folder into `wp-content/plugins/` (or `web/app/plugins/` on Bedrock), then activate **WebP Convert** from the WordPress Plugins screen.

## Usage

### Admin menu

A top-level **WebP Convert** menu appears in the WordPress admin with:

1. **Settings** — toggle auto-convert, pick file types, adjust quality.
2. **Manual Converter** — browse eligible images and convert them with per-row or bulk actions.
3. **Regenerate WebP** — re-encode already-converted WebP images at the current quality setting.

### Settings

| Setting | Description | Default |
|---|---|---|
| Automatic conversion | Convert new uploads automatically | Enabled |
| Auto-convert file types | Which mime types are eligible (JPG, JPEG, PNG, SVG) | JPG + JPEG + PNG |
| Quality | WebP compression quality (1–100) | 80 |
| Enable CDN cache invalidation | Invalidate CloudFront after convert/regenerate | Disabled |
| AWS Access Key ID / Secret Access Key | Credentials for the CloudFront invalidation call | — |
| CloudFront Distribution ID | Distribution to invalidate (e.g. `E123ABC…`) | — |
| AWS Region | Region passed to the AWS client | `us-east-1` |

> **SVG note:** the WordPress image editor (GD/Imagick without librsvg) cannot convert SVG to WebP. The option is exposed for completeness but is a no-op on standard hosting.

### CloudFront cache invalidation

W3 Total Cache overwrites the `.webp` object in S3, but CloudFront edges keep serving the previously cached copy until its TTL expires. This is only a problem when an **existing** object is re-encoded at the same key — i.e. **Regenerate**. A fresh convert or upload isn't edge-cached yet, so invalidation runs on regenerate only.

#### Configure it on the Settings screen

Go to **WebP Convert → Settings** and scroll to the **CDN cache invalidation** row:

1. Tick **Enable CloudFront cache invalidation after regenerate**. The credential fields appear below.
2. **AWS Access Key ID** — the access key of an IAM user/role that has the `cloudfront:CreateInvalidation` permission (see [Required IAM permission](#required-iam-permission)). Example: `AKIA…`.
3. **AWS Secret Access Key** — the matching secret. It is stored masked; once saved it shows `•••••••• (saved — leave blank to keep)`, so leave it blank on future saves to keep the existing value.
4. **CloudFront Distribution ID** — the distribution's ID such as `E1A2B3C4D5E6F7`, **not** the `xxxx.cloudfront.net` domain (see [Finding the Distribution ID](#finding-the-distribution-id-it-is-not-the-domain)).
5. **AWS Region** — e.g. `us-east-1` (defaults to `us-east-1` if left blank; CloudFront is global so any valid region works).
6. Click **Save Changes**.

To confirm it works, open **WebP Convert → Regenerate WebP** and click **Regenerate** on one image, then reload that image's CloudFront URL — it should reflect the re-encoded file. If it stays stale, check `web/app/debug.log` for a `[webp-convert] CloudFront invalidation failed: …` line (almost always the IAM action or the Distribution ID).

> **Note:** if a value is also set in `.env` or `wp-config.php`, that value wins and its field on this screen is shown read-only with a "Set via .env / wp-config.php" note. The **Enable** toggle is always controlled here and is never locked.

#### Or configure it outside the database

- Tick **Enable CDN cache invalidation** in Settings to reveal the AWS fields. This on/off toggle is always controlled from this screen (it is not env-locked, so you can turn it off anytime). The Secret Access Key is masked; leave it blank on save to keep the stored value.
- The four **credential** values can instead be provided via `.env` (`WEBP_CDN_AWS_ACCESS_KEY_ID`, `WEBP_CDN_AWS_SECRET_ACCESS_KEY`, `WEBP_CDN_DISTRIBUTION_ID`, `WEBP_CDN_AWS_REGION`) or a matching `define()` constant in `wp-config.php`. Precedence is env var → `wp-config.php` constant → this screen; an overriding value renders the field read-only. The Settings page has an expandable "How to configure via .env or wp-config.php" panel with copy-paste snippets.

  ```
  # .env (Bedrock)
  WEBP_CDN_AWS_ACCESS_KEY_ID=AKIA...
  WEBP_CDN_AWS_SECRET_ACCESS_KEY=your-secret-key
  WEBP_CDN_DISTRIBUTION_ID=E1234567890ABC
  WEBP_CDN_AWS_REGION=eu-west-2
  ```

  ```php
  // wp-config.php (classic WordPress) — above "That's all, stop editing!"
  define( 'WEBP_CDN_AWS_ACCESS_KEY_ID', 'AKIA...' );
  define( 'WEBP_CDN_AWS_SECRET_ACCESS_KEY', 'your-secret-key' );
  define( 'WEBP_CDN_DISTRIBUTION_ID', 'E1234567890ABC' );
  define( 'WEBP_CDN_AWS_REGION', 'eu-west-2' );
  ```
- The plugin **bundles a CloudFront-only AWS SDK** in its own `vendor/` (slimmed to ~9 MB via the SDK's `removeUnusedServices` script). The bootstrap loads it only if no AWS SDK is already present on the site and PHP ≥ 8.1. If the SDK or configuration is missing, invalidation silently no-ops — regeneration is never blocked. To update the SDK: `composer --working-dir=<plugin> update`.
- Each regenerated attachment's files are batched into one invalidation. A bulk Regenerate of N images therefore issues N invalidations; CloudFront's free tier is 1000 paths/month. API errors are logged and never fail the regeneration.

#### Required IAM permission

The AWS credentials need the **`cloudfront:CreateInvalidation`** action. This is the most common setup mistake: a policy that grants `cloudfront:Get*` and `cloudfront:List*` looks complete but does **not** cover `CreateInvalidation` (it starts with `Create`, not `Get`/`List`), so invalidation fails with `403 AccessDenied` while everything else appears to work.

Attach (or extend) an IAM policy on the user/role whose keys you configured:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "WebPCloudFrontInvalidation",
            "Effect": "Allow",
            "Action": [
                "cloudfront:CreateInvalidation",
                "cloudfront:GetInvalidation",
                "cloudfront:ListInvalidations"
            ],
            "Resource": "*"
        }
    ]
}
```

CloudFront's resource-level permissions are limited, so `"Resource": "*"` is the reliable choice (you can try scoping to `arn:aws:cloudfront::<account-id>:distribution/<DISTRIBUTION_ID>` if your policy supports it). IAM changes take effect within seconds.

**Already have a CloudFront statement?** If the policy already grants `cloudfront:Get*` and `cloudfront:List*` (a common setup — e.g. an existing S3 uploads user), don't add a new statement — just add the single action `"cloudfront:CreateInvalidation"` to that statement's `Action` list:

```json
{
    "Effect": "Allow",
    "Action": [
        "cloudfront:Get*",
        "cloudfront:List*",
        "cloudfront:CreateInvalidation"
    ],
    "Resource": [
        "*"
    ]
}
```

That last line is the only addition — `Get*` / `List*` already cover `GetInvalidation` and `ListInvalidations`.

#### Finding the Distribution ID (it is not the domain)

The **Distribution ID** looks like `E1A2B3C4D5E6F7` — it is **not** the `xxxxxxxx.cloudfront.net` domain label. Using the domain prefix (e.g. `d38bgkww5p5rrg`) as the ID also produces a `403`. Find the real ID in the CloudFront console (the "ID" column next to your distribution's domain), or with:

```bash
aws cloudfront list-distributions \
  --query "DistributionList.Items[].{Id:Id,Domain:DomainName}" --output table
```

Match the row whose `Domain` is your CDN host; its `Id` is what goes in `WEBP_CDN_DISTRIBUTION_ID`.

### Converting existing images

Go to **WebP Convert → Manual Converter**:

- The list shows every image whose mime type matches the Settings checkboxes.
- Tick individual rows and click **Convert Selected**, or click **Convert** on a single row.
- In replace mode, each converted image becomes `image/webp` and disappears from the list — so the page always shows the "still to do" pile.

Alternatively, use the **Media Library → List view → Bulk actions → Convert to WebP**.

### Regenerating existing WebP at a new quality

Go to **WebP Convert → Regenerate WebP**:

- The list shows every `image/webp` attachment with its current file size.
- Tick rows and click **Regenerate Selected**, or click **Regenerate** on a single row. Each file (full size + every thumbnail) is re-encoded in place at the current Quality setting and re-pushed to the W3TC CDN.
- Also available as a **Regenerate WebP** row/bulk action in the Media Library.

> **Quality is one-directional for already-converted images.** In replace mode the original JPEG/PNG was deleted, so regeneration re-encodes from the existing WebP. Lowering the quality reduces file size; raising it cannot restore detail already discarded. To get a higher-quality result you must re-upload the original source image.

## Developer notes

### Filters

```php
// Override the WebP quality (takes precedence over the settings UI).
add_filter('webp_quality', fn() => 85);

// Disable replace mode — keep originals on disk and serve .webp via URL swap instead.
add_filter('webp_replace_originals', '__return_false');
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

Internal plugin maintained by Jayson Garcia. See `CLAUDE.md` for technical implementation notes.
