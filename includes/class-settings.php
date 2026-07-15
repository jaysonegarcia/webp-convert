<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores, reads, and renders plugin settings. Owns the option key, option group,
 * and the file-type → mime-type map consulted by conversion and admin code.
 */
class WebP_Convert_Settings
{
    public const OPTION_NAME    = 'webp_convert_settings';
    public const SETTINGS_GROUP = 'webp_convert';

    public const FILE_TYPE_MIME_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'svg'  => 'image/svg+xml',
    ];

    public function register_hooks(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_webp_test_cdn', [$this, 'ajax_test_cdn']);
    }

    /**
     * AJAX: run a live CloudFront connection test against the saved settings and
     * return the result for the status panel on the Settings page.
     */
    public function ajax_test_cdn(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'webp-convert')], 403);
        }
        check_ajax_referer('webp_test_cdn', 'nonce');

        $invalidator = new WebP_Convert_CDN_Invalidator($this);
        $result      = $invalidator->test_connection();

        if (!empty($result['ok'])) {
            wp_send_json_success(['message' => $result['message']]);
        }
        wp_send_json_error(['message' => $result['message']]);
    }

    public function get_default_settings(): array
    {
        return [
            'auto_convert_enabled' => true,
            'file_types'           => ['jpg', 'jpeg', 'png'],
            'quality'              => 80,
            'cdn_enabled'          => false,
            'cdn_access_key'       => '',
            'cdn_secret_key'       => '',
            'cdn_distribution_id'  => '',
            'cdn_region'           => 'us-east-1',
        ];
    }

    public function is_auto_convert_enabled(): bool
    {
        return (bool) $this->get_settings()['auto_convert_enabled'];
    }

    public function get_settings(): array
    {
        $settings = get_option(self::OPTION_NAME, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        return array_merge($this->get_default_settings(), $settings);
    }

    public function get_convertible_mimes(): array
    {
        $mimes = [];
        foreach ($this->get_settings()['file_types'] as $type) {
            if (isset(self::FILE_TYPE_MIME_MAP[$type])) {
                $mimes[] = self::FILE_TYPE_MIME_MAP[$type];
            }
        }
        return array_values(array_unique($mimes));
    }

    public function quality(): int
    {
        $quality = (int) $this->get_settings()['quality'];
        $quality = max(1, min(100, $quality));
        return (int) apply_filters('webp_quality', $quality);
    }

    public function register_settings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => $this->get_default_settings(),
            ],
        );
    }

    public function sanitize_settings($input): array
    {
        $allowed    = array_keys(self::FILE_TYPE_MIME_MAP);
        $file_types = [];
        if (is_array($input) && !empty($input['file_types']) && is_array($input['file_types'])) {
            foreach ($input['file_types'] as $type) {
                $type = strtolower((string) $type);
                if (in_array($type, $allowed, true)) {
                    $file_types[] = $type;
                }
            }
        }
        $quality = isset($input['quality']) ? (int) $input['quality'] : 80;
        $quality = max(1, min(100, $quality));

        $existing      = $this->get_settings();
        $cdn_secret_in = isset($input['cdn_secret_key']) ? trim((string) $input['cdn_secret_key']) : '';
        // Blank submit keeps the stored secret (field renders masked, not the real value).
        $cdn_secret    = $cdn_secret_in !== '' ? $cdn_secret_in : (string) ($existing['cdn_secret_key'] ?? '');

        return [
            'auto_convert_enabled' => !empty($input['auto_convert_enabled']),
            'file_types'           => array_values(array_unique($file_types)),
            'quality'              => $quality,
            'cdn_enabled'          => !empty($input['cdn_enabled']),
            'cdn_access_key'       => isset($input['cdn_access_key']) ? sanitize_text_field($input['cdn_access_key']) : '',
            'cdn_secret_key'       => $cdn_secret,
            'cdn_distribution_id'  => isset($input['cdn_distribution_id']) ? sanitize_text_field($input['cdn_distribution_id']) : '',
            'cdn_region'           => isset($input['cdn_region']) && $input['cdn_region'] !== '' ? sanitize_text_field($input['cdn_region']) : 'us-east-1',
        ];
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings     = $this->get_settings();
        $enabled      = $settings['file_types'];
        $auto_enabled = !empty($settings['auto_convert_enabled']);
        $quality      = (int) $settings['quality'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WebP Converter', 'webp-convert'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Automatic conversion', 'webp-convert'); ?></th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[auto_convert_enabled]"
                                    value="1"
                                    <?php checked($auto_enabled); ?>
                                >
                                <?php esc_html_e('Enable automatic conversion on upload', 'webp-convert'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, newly uploaded images are converted to WebP automatically. When disabled, you can still convert manually from the Manual Converter page.', 'webp-convert'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto-convert file types', 'webp-convert'); ?></th>
                        <td>
                            <fieldset>
                                <?php foreach (array_keys(self::FILE_TYPE_MIME_MAP) as $type) : ?>
                                    <label style="display:inline-block;margin-right:16px;">
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr(self::OPTION_NAME); ?>[file_types][]"
                                            value="<?php echo esc_attr($type); ?>"
                                            <?php checked(in_array($type, $enabled, true)); ?>
                                        >
                                        <?php echo esc_html(strtoupper($type)); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description">
                                    <?php esc_html_e('Select which file types are automatically converted to WebP on upload and via bulk/row actions in the Media Library.', 'webp-convert'); ?>
                                </p>
                                <p class="description" style="color:#b32d2e;">
                                    <strong><?php esc_html_e('Note:', 'webp-convert'); ?></strong>
                                    <?php esc_html_e('SVG is a vector format and cannot be converted to WebP by the standard WordPress image editor. Selecting it is kept as a placeholder but will have no effect unless the server has SVG rasterization support.', 'webp-convert'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="webp-quality"><?php esc_html_e('Quality', 'webp-convert'); ?></label>
                        </th>
                        <td>
                            <input
                                type="range"
                                id="webp-quality"
                                name="<?php echo esc_attr(self::OPTION_NAME); ?>[quality]"
                                min="1"
                                max="100"
                                step="1"
                                value="<?php echo esc_attr((string) $quality); ?>"
                                oninput="document.getElementById('webp-quality-value').textContent = this.value;"
                                style="vertical-align:middle;width:260px;"
                            >
                            <output id="webp-quality-value" style="display:inline-block;min-width:2.5em;font-weight:600;margin-left:8px;"><?php echo esc_html((string) $quality); ?></output>
                            <span style="color:#646970;">/ 100</span>
                            <p class="description">
                                <?php esc_html_e('WebP compression quality. Higher = better quality, larger file size. 80 is a good default.', 'webp-convert'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('CDN cache invalidation', 'webp-convert'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="webp-cdn-enabled"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[cdn_enabled]" value="1"
                                    <?php checked(!empty($settings['cdn_enabled'])); ?>>
                                <?php esc_html_e('Enable CloudFront cache invalidation after regenerate', 'webp-convert'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, the affected image paths are invalidated on CloudFront after a regenerate (re-encoding an existing image) so edges stop serving stale copies. The credential fields below can be set here or via their .env / wp-config.php values (which override and lock the field); this on/off switch is always controlled here.', 'webp-convert'); ?>
                            </p>
                            <details style="margin-top:8px;">
                                <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('How to configure via .env or wp-config.php', 'webp-convert'); ?></summary>
                                <div style="margin-top:8px;max-width:640px;">
                                    <p class="description"><?php esc_html_e('The credential values can be set outside the database. A value set this way overrides — and locks — the matching field. Order of precedence: .env / environment variable, then a wp-config.php constant, then this screen. (The Enable switch above is always controlled here.)', 'webp-convert'); ?></p>
                                    <p class="description" style="margin-top:8px;"><strong><?php esc_html_e('Available keys', 'webp-convert'); ?></strong></p>
                                    <ul class="ul-disc" style="list-style:disc;margin:4px 0 8px 1.5em;">
                                        <li><code>WEBP_CDN_AWS_ACCESS_KEY_ID</code></li>
                                        <li><code>WEBP_CDN_AWS_SECRET_ACCESS_KEY</code></li>
                                        <li><code>WEBP_CDN_DISTRIBUTION_ID</code></li>
                                        <li><code>WEBP_CDN_AWS_REGION</code> — <?php esc_html_e('defaults to us-east-1', 'webp-convert'); ?></li>
                                    </ul>
                                    <p class="description" style="margin-top:8px;"><strong><?php esc_html_e('In .env (Bedrock)', 'webp-convert'); ?></strong></p>
                                    <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;white-space:pre;">WEBP_CDN_AWS_ACCESS_KEY_ID=AKIA...
WEBP_CDN_AWS_SECRET_ACCESS_KEY=your-secret-key
WEBP_CDN_DISTRIBUTION_ID=E1234567890ABC
WEBP_CDN_AWS_REGION=eu-west-2</pre>
                                    <p class="description" style="margin-top:8px;"><strong><?php esc_html_e('In wp-config.php (classic WordPress)', 'webp-convert'); ?></strong></p>
                                    <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;white-space:pre;">define( 'WEBP_CDN_AWS_ACCESS_KEY_ID', 'AKIA...' );
define( 'WEBP_CDN_AWS_SECRET_ACCESS_KEY', 'your-secret-key' );
define( 'WEBP_CDN_DISTRIBUTION_ID', 'E1234567890ABC' );
define( 'WEBP_CDN_AWS_REGION', 'eu-west-2' );</pre>
                                    <p class="description"><?php esc_html_e('Add these above the "That\'s all, stop editing!" line in wp-config.php.', 'webp-convert'); ?></p>
                                </div>
                            </details>
                            <details style="margin-top:8px;">
                                <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('Required AWS permission (IAM) & finding the Distribution ID', 'webp-convert'); ?></summary>
                                <div style="margin-top:8px;max-width:640px;">
                                    <p class="description"><strong><?php esc_html_e('IAM permission', 'webp-convert'); ?></strong> — <?php esc_html_e('the credentials must be allowed to run cloudfront:CreateInvalidation. A policy with only cloudfront:Get* and cloudfront:List* is NOT enough (CreateInvalidation starts with "Create", not "Get"/"List"), which fails with 403 AccessDenied while everything else appears to work. Add this action to the IAM user/role:', 'webp-convert'); ?></p>
                                    <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;white-space:pre;">{
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
}</pre>
                                    <p class="description"><?php esc_html_e('CloudFront resource-level permissions are limited, so "Resource": "*" is the reliable choice. IAM changes take effect within seconds.', 'webp-convert'); ?></p>
                                    <p class="description" style="margin-top:8px;"><strong><?php esc_html_e('Already have a CloudFront statement?', 'webp-convert'); ?></strong> — <?php esc_html_e('If the policy already grants cloudfront:Get* and cloudfront:List* (a common setup — e.g. an existing S3 uploads user), do NOT add a new statement. Just add the one action "cloudfront:CreateInvalidation" to that statement\'s Action list:', 'webp-convert'); ?></p>
                                    <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;white-space:pre;">{
    "Effect": "Allow",
    "Action": [
        "cloudfront:Get*",
        "cloudfront:List*",
        "cloudfront:CreateInvalidation"
    ],
    "Resource": [
        "*"
    ]
}</pre>
                                    <p class="description"><?php esc_html_e('(The last line — "cloudfront:CreateInvalidation" — is the only addition. Get*/List* already cover GetInvalidation and ListInvalidations.)', 'webp-convert'); ?></p>
                                    <p class="description" style="margin-top:8px;"><strong><?php esc_html_e('Distribution ID (not the domain)', 'webp-convert'); ?></strong> — <?php esc_html_e('the CloudFront Distribution ID looks like E1A2B3C4D5E6F7 — it is NOT the xxxx.cloudfront.net domain label. Using the domain prefix also produces a 403. Find the ID in the CloudFront console (the "ID" column next to your distribution\'s domain), or run:', 'webp-convert'); ?></p>
                                    <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;white-space:pre;">aws cloudfront list-distributions \
  --query "DistributionList.Items[].{Id:Id,Domain:DomainName}" \
  --output table</pre>
                                    <p class="description"><?php esc_html_e('Match the row whose Domain is your CDN host; its Id is what goes in the CloudFront Distribution ID field above.', 'webp-convert'); ?></p>
                                </div>
                            </details>
                        </td>
                    </tr>
                    <?php
                    $cdn_fields = [
                        ['cdn_access_key', WebP_Convert_CDN_Invalidator::ENV_KEY, __('AWS Access Key ID', 'webp-convert'), 'text', $settings['cdn_access_key']],
                        ['cdn_secret_key', WebP_Convert_CDN_Invalidator::ENV_SECRET, __('AWS Secret Access Key', 'webp-convert'), 'password', ''],
                        ['cdn_distribution_id', WebP_Convert_CDN_Invalidator::ENV_DIST, __('CloudFront Distribution ID', 'webp-convert'), 'text', $settings['cdn_distribution_id']],
                        ['cdn_region', WebP_Convert_CDN_Invalidator::ENV_REGION, __('AWS Region', 'webp-convert'), 'text', $settings['cdn_region']],
                    ];
        foreach ($cdn_fields as [$key, $env_key, $label, $type, $value]) :
            $env_val   = WebP_Convert_CDN_Invalidator::env($env_key);
            $has_env   = $env_val !== null;
            $is_secret = $key === 'cdn_secret_key';
            $has_saved = $is_secret && !empty($settings['cdn_secret_key']);
            ?>
                        <tr class="webp-cdn-field">
                            <th scope="row"><label for="webp-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <input type="<?php echo esc_attr($type); ?>" class="regular-text"
                                    id="webp-<?php echo esc_attr($key); ?>"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($key); ?>]"
                                    value="<?php echo esc_attr($has_env ? '' : (string) $value); ?>"
                                    autocomplete="off"
                                    <?php echo $has_env ? 'placeholder="' . esc_attr__('Set via .env', 'webp-convert') . '" disabled' : ''; ?>
                                    <?php echo (!$has_env && $has_saved) ? 'placeholder="' . esc_attr__('•••••••• (saved — leave blank to keep)', 'webp-convert') . '"' : ''; ?>>
                                <?php if ($has_env) : ?>
                                    <p class="description"><?php printf(esc_html__('Set via .env / wp-config.php (%s).', 'webp-convert'), esc_html($env_key)); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php
                    $status      = new WebP_Convert_CDN_Invalidator($this);
        $sdk_loaded  = $status->sdk_loaded();
        $sdk_version = $status->sdk_version();
        $sdk_source  = $status->sdk_source();
        $ready       = $status->is_ready();
        $problems    = $status->readiness_problems();
        $ok_color    = '#008a20';
        $bad_color   = '#b32d2e';
        ?>
                        <tr class="webp-cdn-field">
                            <th scope="row"><?php esc_html_e('Status', 'webp-convert'); ?></th>
                            <td>
                                <p style="margin-top:0;">
                                    <strong><?php esc_html_e('AWS SDK:', 'webp-convert'); ?></strong>
                                    <?php if ($sdk_loaded) : ?>
                                        <span style="color:<?php echo esc_attr($ok_color); ?>;">&#10003; <?php esc_html_e('Loaded', 'webp-convert'); ?></span>
                                        <span style="color:#646970;">(<?php
                                echo $sdk_version ? 'v' . esc_html($sdk_version) : esc_html__('version unknown', 'webp-convert');
                                        echo $sdk_source ? ', ' . esc_html($sdk_source === 'bundled' ? __('bundled with plugin', 'webp-convert') : __('provided by site', 'webp-convert')) : '';
                                        ?>)</span>
                                    <?php else : ?>
                                        <span style="color:<?php echo esc_attr($bad_color); ?>;">&#10007; <?php esc_html_e('Not loaded — cache invalidation unavailable', 'webp-convert'); ?></span>
                                    <?php endif; ?>
                                </p>
                                <p>
                                    <strong><?php esc_html_e('Ready to invalidate:', 'webp-convert'); ?></strong>
                                    <?php if ($ready) : ?>
                                        <span style="color:<?php echo esc_attr($ok_color); ?>;">&#10003; <?php esc_html_e('Yes', 'webp-convert'); ?></span>
                                    <?php else : ?>
                                        <span style="color:<?php echo esc_attr($bad_color); ?>;">&#10007; <?php esc_html_e('No', 'webp-convert'); ?></span>
                                        &mdash; <?php echo esc_html(implode(', ', $problems)); ?>
                                    <?php endif; ?>
                                </p>
                                <?php if ($sdk_loaded) : ?>
                                    <p>
                                        <button type="button" class="button" id="webp-test-cdn"
                                            data-nonce="<?php echo esc_attr(wp_create_nonce('webp_test_cdn')); ?>">
                                            <?php esc_html_e('Test CloudFront connection', 'webp-convert'); ?>
                                        </button>
                                        <span id="webp-test-cdn-result" style="margin-left:8px;font-weight:600;"></span>
                                    </p>
                                    <p class="description">
                                        <?php esc_html_e('Sends a real (harmless) invalidation against the saved settings to verify the credentials, Distribution ID, and the cloudfront:CreateInvalidation permission all work.', 'webp-convert'); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                </table>
                <script>
                (function () {
                    var toggle = document.getElementById('webp-cdn-enabled');
                    var rows = document.querySelectorAll('tr.webp-cdn-field');
                    if (toggle && rows.length) {
                        var sync = function () {
                            for (var i = 0; i < rows.length; i++) {
                                rows[i].style.display = toggle.checked ? '' : 'none';
                            }
                        };
                        toggle.addEventListener('change', sync);
                        sync();
                    }

                    var btn = document.getElementById('webp-test-cdn');
                    var out = document.getElementById('webp-test-cdn-result');
                    if (btn && out && typeof ajaxurl !== 'undefined') {
                        btn.addEventListener('click', function () {
                            btn.disabled = true;
                            out.style.color = '#646970';
                            out.textContent = '<?php echo esc_js(__('Testing…', 'webp-convert')); ?>';
                            var body = 'action=webp_test_cdn&nonce=' + encodeURIComponent(btn.getAttribute('data-nonce'));
                            fetch(ajaxurl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: body
                            }).then(function (r) { return r.json(); }).then(function (res) {
                                var ok = res && res.success;
                                var msg = (res && res.data && res.data.message) ? res.data.message : (ok ? 'OK' : 'Failed');
                                out.style.color = ok ? '#008a20' : '#b32d2e';
                                out.textContent = (ok ? '✓ ' : '✗ ') + msg;
                            }).catch(function (e) {
                                out.style.color = '#b32d2e';
                                out.textContent = '✗ ' + e;
                            }).finally(function () { btn.disabled = false; });
                        });
                    }
                })();
                </script>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
