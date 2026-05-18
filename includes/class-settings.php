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
    const OPTION_NAME    = 'webp_convert_settings';
    const SETTINGS_GROUP = 'webp_convert';

    const FILE_TYPE_MIME_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'svg'  => 'image/svg+xml',
    ];

    public function register_hooks(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function get_default_settings(): array
    {
        return [
            'auto_convert_enabled' => true,
            'file_types'           => ['jpg', 'jpeg', 'png'],
            'quality'              => 80,
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
        return (int) apply_filters('webp_convert_quality', $quality);
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
            ]
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

        return [
            'auto_convert_enabled' => !empty($input['auto_convert_enabled']),
            'file_types'           => array_values(array_unique($file_types)),
            'quality'              => $quality,
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
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
