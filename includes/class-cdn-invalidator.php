<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Issues CloudFront CreateInvalidation calls so edge caches drop stale WebP
 * objects after convert/regenerate. Config resolves env-over-option; every
 * public action no-ops unless enabled + fully configured + AWS SDK present.
 */
class WebP_Convert_CDN_Invalidator
{
    public const ENV_KEY     = 'WEBP_CDN_AWS_ACCESS_KEY_ID';
    public const ENV_SECRET  = 'WEBP_CDN_AWS_SECRET_ACCESS_KEY';
    public const ENV_DIST    = 'WEBP_CDN_DISTRIBUTION_ID';
    public const ENV_REGION  = 'WEBP_CDN_AWS_REGION';

    /** @var WebP_Convert_Settings */
    private $settings;

    /** @var \Aws\CloudFront\CloudFrontClient|null */
    private $client = null;

    public function __construct(WebP_Convert_Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Resolve an override value, or null when unset/empty. Reads an environment
     * variable first (Bedrock .env via getenv()), then falls back to a PHP
     * constant of the same name (a define() in wp-config.php on classic WP).
     */
    public static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false && defined($key)) {
            $value = constant($key);
        }
        if ($value === false || $value === null) {
            return null;
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '';
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function option(string $key, string $default = ''): string
    {
        $settings = $this->settings->get_settings();
        return isset($settings[$key]) ? (string) $settings[$key] : $default;
    }

    /**
     * On/off master switch. Admin-controlled only (stored option) so it can
     * always be toggled from the settings screen — unlike the credential fields,
     * it is intentionally not lockable via .env / wp-config.php.
     */
    public function is_enabled(): bool
    {
        $settings = $this->settings->get_settings();
        return !empty($settings['cdn_enabled']);
    }

    public function access_key(): string
    {
        return self::env(self::ENV_KEY) ?? $this->option('cdn_access_key');
    }

    public function secret_key(): string
    {
        return self::env(self::ENV_SECRET) ?? $this->option('cdn_secret_key');
    }

    public function distribution_id(): string
    {
        return self::env(self::ENV_DIST) ?? $this->option('cdn_distribution_id');
    }

    public function region(): string
    {
        $region = self::env(self::ENV_REGION) ?? $this->option('cdn_region', 'us-east-1');
        return $region !== '' ? $region : 'us-east-1';
    }

    public function is_ready(): bool
    {
        return $this->is_enabled()
            && $this->access_key() !== ''
            && $this->secret_key() !== ''
            && $this->distribution_id() !== ''
            && $this->sdk_loaded();
    }

    /** Whether the AWS CloudFront client class is available (bundled or site-provided). */
    public function sdk_loaded(): bool
    {
        return class_exists('\Aws\CloudFront\CloudFrontClient');
    }

    /** Installed AWS SDK version, or null if the SDK isn't loaded. */
    public function sdk_version(): ?string
    {
        return class_exists('\Aws\Sdk') ? \Aws\Sdk::VERSION : null;
    }

    /**
     * Where the loaded SDK came from: 'bundled' (this plugin's vendor/), 'site'
     * (a root/other autoloader), or null when not loaded.
     */
    public function sdk_source(): ?string
    {
        if (!$this->sdk_loaded()) {
            return null;
        }
        try {
            $path = (new \ReflectionClass('\Aws\CloudFront\CloudFrontClient'))->getFileName();
        } catch (\Throwable $e) {
            return null;
        }
        $plugin_dir = dirname(__DIR__); // .../webp-convert
        return ($path && strpos($path, $plugin_dir) === 0) ? 'bundled' : 'site';
    }

    /**
     * Human-readable list of what's missing for is_ready(), for the status panel.
     *
     * @return string[]
     */
    public function readiness_problems(): array
    {
        $problems = [];
        if (!$this->sdk_loaded()) {
            $problems[] = 'AWS SDK not available';
        }
        if (!$this->is_enabled()) {
            $problems[] = 'not enabled';
        }
        if ($this->access_key() === '') {
            $problems[] = 'missing Access Key ID';
        }
        if ($this->secret_key() === '') {
            $problems[] = 'missing Secret Access Key';
        }
        if ($this->distribution_id() === '') {
            $problems[] = 'missing Distribution ID';
        }
        return $problems;
    }

    /**
     * Live end-to-end check: issue a real CloudFront invalidation for a harmless
     * throwaway path. This exercises the exact operation the plugin performs, so
     * it validates credentials, the Distribution ID, AND the
     * cloudfront:CreateInvalidation permission in one go. Returns
     * ['ok' => bool, 'message' => string].
     */
    public function test_connection(): array
    {
        if (!$this->is_ready()) {
            return [
                'ok'      => false,
                'message' => 'Not ready: ' . implode(', ', $this->readiness_problems()) . '.',
            ];
        }
        try {
            $client = $this->client();
            if (!$client) {
                return ['ok' => false, 'message' => 'Could not build the CloudFront client.'];
            }
            $result = $client->createInvalidation([
                'DistributionId'    => $this->distribution_id(),
                'InvalidationBatch' => [
                    'CallerReference' => uniqid('webp-healthcheck-', true),
                    'Paths'           => [
                        'Quantity' => 1,
                        'Items'    => ['/webp-convert-healthcheck-' . time()],
                    ],
                ],
            ]);
            $inv_id = isset($result['Invalidation']['Id']) ? (string) $result['Invalidation']['Id'] : '';

            return [
                'ok'      => true,
                'message' => 'CloudFront reachable — test invalidation accepted' . ($inv_id !== '' ? " (ID {$inv_id})" : '') . '.',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Submit an invalidation for the given origin-relative paths. No-ops (returns
     * false) unless ready. Never throws — API failures are logged, not fatal, so
     * a conversion never fails because of a CDN hiccup.
     */
    public function invalidate_paths(array $paths): bool
    {
        if (!$this->is_ready()) {
            return false;
        }
        $paths = array_values(array_unique(array_filter($paths)));
        if (empty($paths)) {
            return false;
        }
        try {
            $this->create_invalidation($paths);
            return true;
        } catch (\Throwable $e) {
            error_log('[webp-convert] CloudFront invalidation failed: ' . $e->getMessage());
            return false;
        }
    }

    public function invalidate_attachment(int $attachment_id): bool
    {
        $paths = $this->webp_url_paths_for_attachment($attachment_id);
        if (empty($paths)) {
            return false;
        }
        return $this->invalidate_paths($paths);
    }

    /**
     * Origin-relative URL paths for the attachment's WebP full-size + sizes.
     * CloudFront invalidation paths are host-agnostic — we take the path
     * component of the uploads baseurl, matching how the theme Cdn swaps host only.
     */
    public function webp_url_paths_for_attachment(int $attachment_id): array
    {
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return [];
        }
        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? rtrim($uploads['basedir'], '/') . '/' : '';
        $baseurl = isset($uploads['baseurl']) ? $uploads['baseurl'] : '';
        if ($basedir === '' || $baseurl === '' || strpos($file, $basedir) !== 0) {
            return [];
        }
        $base_path = rtrim((string) parse_url($baseurl, PHP_URL_PATH), '/'); // e.g. /app/uploads

        $abs      = [WebP_Convert_Paths::webp_path_for($file)];
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dir = rtrim(dirname($file), '/') . '/';
            foreach ($metadata['sizes'] as $info) {
                if (empty($info['file'])) {
                    continue;
                }
                $abs[] = WebP_Convert_Paths::webp_path_for($dir . $info['file']);
            }
        }

        $paths = [];
        foreach ($abs as $path) {
            if (strpos($path, $basedir) !== 0 || !$this->file_exists_path($path)) {
                continue;
            }
            $relative = ltrim(substr($path, strlen($basedir)), '/');
            $paths[]  = $base_path . '/' . $relative;
        }
        return array_values(array_unique($paths));
    }

    protected function file_exists_path(string $path): bool
    {
        return file_exists($path);
    }

    protected function create_invalidation(array $paths): void
    {
        $client = $this->client();
        if (!$client) {
            return;
        }
        $client->createInvalidation([
            'DistributionId'    => $this->distribution_id(),
            'InvalidationBatch' => [
                'CallerReference' => uniqid('webp-', true),
                'Paths'           => [
                    'Quantity' => count($paths),
                    'Items'    => array_values($paths),
                ],
            ],
        ]);
    }

    private function client()
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if (!class_exists('\Aws\CloudFront\CloudFrontClient')) {
            return null;
        }
        $this->client = new \Aws\CloudFront\CloudFrontClient([
            'version'     => '2020-05-31',
            'region'      => $this->region(),
            'credentials' => [
                'key'    => $this->access_key(),
                'secret' => $this->secret_key(),
            ],
        ]);
        return $this->client;
    }
}
