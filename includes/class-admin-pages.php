<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI surface: top-level menu, Manual Converter page (with pagination and
 * bulk submit), Media Library row + bulk actions, and admin notices.
 *
 * Delegates settings rendering to {@see WebP_Convert_Settings::render_settings_page()}
 * and conversion work to {@see WebP_Convert_Converter::manual_convert()}.
 */
class WebP_Convert_Admin_Pages
{
    const PARENT_SLUG = 'webp-convert';
    const MANUAL_SLUG = 'webp-manual';

    /** @var WebP_Convert_Settings */
    private $settings;

    /** @var WebP_Convert_Converter */
    private $converter;

    public function __construct(
        WebP_Convert_Settings $settings,
        WebP_Convert_Converter $converter
    ) {
        $this->settings  = $settings;
        $this->converter = $converter;
    }

    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'register_menu_pages']);
        add_filter('media_row_actions', [$this, 'row_action'], 10, 2);
        add_action('admin_action_convert_webp', [$this, 'handle_row_action']);
        add_filter('bulk_actions-upload', [$this, 'register_bulk_action']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_notice']);
        add_action('wp_ajax_webp_convert_one', [$this, 'ajax_convert_one']);
    }

    /**
     * AJAX endpoint: convert a single attachment. Called once per selected ID
     * from the Manual Converter progress UI so long batches never hit PHP
     * max_execution_time and the user sees per-item progress.
     */
    public function ajax_convert_one(): void
    {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'webp-convert')], 403);
        }
        check_ajax_referer('webp_ajax', 'nonce');

        $id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid attachment ID.', 'webp-convert')], 400);
        }

        $file     = get_attached_file($id);
        $filename = $file ? wp_basename($file) : (string) $id;

        if ($this->converter->manual_convert($id)) {
            wp_send_json_success([
                'id'       => $id,
                'filename' => $filename,
            ]);
        }

        wp_send_json_error([
            'id'       => $id,
            'filename' => $filename,
            'message'  => __('Conversion failed.', 'webp-convert'),
        ]);
    }

    public function register_menu_pages(): void
    {
        add_menu_page(
            __('WebP Converter', 'webp-convert'),
            __('WebP', 'webp-convert'),
            'manage_options',
            self::PARENT_SLUG,
            [$this->settings, 'render_settings_page'],
            'dashicons-format-image',
            81
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __('Settings', 'webp-convert'),
            __('Settings', 'webp-convert'),
            'manage_options',
            self::PARENT_SLUG,
            [$this->settings, 'render_settings_page']
        );

        $manual_hook = add_submenu_page(
            self::PARENT_SLUG,
            __('Manual Converter', 'webp-convert'),
            __('Manual Converter', 'webp-convert'),
            'upload_files',
            self::MANUAL_SLUG,
            [$this, 'render_manual_converter_page']
        );

        if ($manual_hook) {
            add_action('load-' . $manual_hook, [$this, 'maybe_handle_manual_convert']);
        }
    }

    /**
     * Handle POST submissions from the Manual Converter page. Runs on the page's
     * `load-{hook}` action, before any output, so redirects work.
     */
    public function maybe_handle_manual_convert(): void
    {
        if (empty($_POST['webp_action']) || $_POST['webp_action'] !== 'bulk_convert') {
            return;
        }
        if (!current_user_can('upload_files')) {
            return;
        }
        check_admin_referer('webp_manual_convert', 'webp_nonce');

        $ids = [];
        if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
            $ids = array_map('intval', $_POST['ids']);
        }

        $count = 0;
        foreach ($ids as $id) {
            if ($id > 0 && $this->converter->manual_convert($id)) {
                $count++;
            }
        }

        $paged = isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1;

        wp_safe_redirect(add_query_arg(
            [
                'page'                 => self::MANUAL_SLUG,
                'paged'                => $paged,
                'webp_converted' => 1,
                'webp_count'     => $count,
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    public function render_manual_converter_page(): void
    {
        if (!current_user_can('upload_files')) {
            return;
        }

        $mimes    = $this->settings->get_convertible_mimes();
        $enabled  = $this->settings->get_settings()['file_types'];
        $paged    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = 20;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('WebP — Manual Converter', 'webp-convert') . '</h1>';

        if (empty($mimes)) {
            echo '<div class="notice notice-warning"><p>';
            printf(
                /* translators: %s: settings page link */
                esc_html__('No file types are enabled. Enable at least one in %s.', 'webp-convert'),
                '<a href="' . esc_url(admin_url('admin.php?page=' . self::PARENT_SLUG)) . '">' . esc_html__('Settings', 'webp-convert') . '</a>'
            );
            echo '</p></div></div>';
            return;
        }

        echo '<p>' . sprintf(
            /* translators: %s: comma-separated list of file type labels (e.g. "JPG, PNG") */
            esc_html__('Showing images with file types: %s. Convert one at a time with the row action, or tick multiple and use "Convert Selected".', 'webp-convert'),
            '<strong>' . esc_html(strtoupper(implode(', ', $enabled))) . '</strong>'
        ) . '</p>';

        $query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => $mimes,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if (!$query->have_posts()) {
            echo '<p>' . esc_html__('No images found for the selected file types. All eligible images may have already been converted.', 'webp-convert') . '</p>';
            echo '</div>';
            return;
        }

        ?>

        <form id="webp-manual-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MANUAL_SLUG . '&paged=' . $paged)); ?>">
            <?php wp_nonce_field('webp_manual_convert', 'webp_nonce'); ?>
            <input type="hidden" name="webp_action" value="bulk_convert">
            <input type="hidden" name="paged" value="<?php echo esc_attr((string) $paged); ?>">
            <input type="hidden" id="webp-ajax-nonce" value="<?php echo esc_attr(wp_create_nonce('webp_ajax')); ?>">

            <div id="webp-progress" style="display:none;margin:12px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;border-left-width:4px;border-left-color:#2271b1;">
                <p style="margin:0 0 8px 0;"><strong id="webp-progress-label"><?php esc_html_e('Converting…', 'webp-convert'); ?></strong></p>
                <progress id="webp-progress-bar" value="0" max="100" style="width:100%;height:20px;"></progress>
                <p id="webp-progress-current" style="margin:8px 0 0 0;color:#646970;font-style:italic;"></p>
                <ul id="webp-progress-log" style="margin:8px 0 0 0;max-height:160px;overflow:auto;font-family:monospace;font-size:12px;"></ul>
            </div>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <?php submit_button(__('Convert Selected', 'webp-convert'), 'primary', 'submit_bulk_top', false); ?>
                </div>
                <?php $this->render_tablenav_pages($query, $paged); ?>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" id="webp-select-all">
                        </td>
                        <th scope="col" style="width:80px;"><?php esc_html_e('Preview', 'webp-convert'); ?></th>
                        <th scope="col"><?php esc_html_e('File', 'webp-convert'); ?></th>
                        <th scope="col"><?php esc_html_e('Type', 'webp-convert'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'webp-convert'); ?></th>
                        <th scope="col"><?php esc_html_e('Date', 'webp-convert'); ?></th>
                        <th scope="col" style="width:120px;"><?php esc_html_e('Action', 'webp-convert'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    while ($query->have_posts()) :
                        $query->the_post();
                        $id          = get_the_ID();
                        $converted   = (bool) get_post_meta($id, WebP_Convert_Converter::META_KEY, true);
                        $file_path   = get_attached_file($id);
                        $file_name   = $file_path ? wp_basename($file_path) : get_the_title();
                        $mime        = get_post_mime_type($id);
                        $convert_url = wp_nonce_url(
                            admin_url('admin.php?action=convert_webp&attachment_id=' . $id),
                            'convert_webp_' . $id
                        );
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="ids[]" value="<?php echo esc_attr((string) $id); ?>">
                            </th>
                            <td><?php echo wp_get_attachment_image($id, [60, 60], true); ?></td>
                            <td>
                                <strong><?php echo esc_html($file_name); ?></strong><br>
                                <span class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php esc_html_e('Edit', 'webp-convert'); ?></a> |
                                    </span>
                                    <span class="view">
                                        <a href="<?php echo esc_url(wp_get_attachment_url($id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'webp-convert'); ?></a>
                                    </span>
                                </span>
                            </td>
                            <td><?php echo esc_html($mime); ?></td>
                            <td>
                                <?php if ($converted) : ?>
                                    <span style="color:#00a32a;">✓ <?php esc_html_e('Converted', 'webp-convert'); ?></span>
                                <?php else : ?>
                                    <span style="color:#646970;">— <?php esc_html_e('Not converted', 'webp-convert'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(get_the_date()); ?></td>
                            <td>
                                <a href="<?php echo esc_url($convert_url); ?>" class="button button-small">
                                    <?php echo $converted ? esc_html__('Re-convert', 'webp-convert') : esc_html__('Convert', 'webp-convert'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; wp_reset_postdata(); ?>
                </tbody>
            </table>

            <div class="tablenav bottom">
                <div class="alignleft actions">
                    <?php submit_button(__('Convert Selected', 'webp-convert'), 'primary', 'submit_bulk_bottom', false); ?>
                </div>
                <?php $this->render_tablenav_pages($query, $paged); ?>
            </div>
        </form>

        <script>
        (function () {
            var master = document.getElementById('webp-select-all');
            if (master) {
                master.addEventListener('change', function () {
                    document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) {
                        cb.checked = master.checked;
                    });
                });
            }

            var form  = document.getElementById('webp-manual-form');
            var nonce = document.getElementById('webp-ajax-nonce');
            if (!form || !nonce || typeof window.fetch !== 'function') {
                return;
            }

            var box     = document.getElementById('webp-progress');
            var label   = document.getElementById('webp-progress-label');
            var bar     = document.getElementById('webp-progress-bar');
            var current = document.getElementById('webp-progress-current');
            var log     = document.getElementById('webp-progress-log');
            var paged   = form.querySelector('input[name="paged"]').value || '1';

            function appendLog(text, color) {
                var li = document.createElement('li');
                li.textContent = text;
                if (color) { li.style.color = color; }
                log.appendChild(li);
                log.scrollTop = log.scrollHeight;
            }

            function convertOne(id) {
                var body = new URLSearchParams();
                body.append('action', 'webp_convert_one');
                body.append('nonce', nonce.value);
                body.append('attachment_id', String(id));
                return fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'Invalid response' } }; }); });
            }

            form.addEventListener('submit', function (ev) {
                var checked = Array.prototype.slice.call(form.querySelectorAll('input[name="ids[]"]:checked'));
                if (checked.length === 0) {
                    return;
                }
                ev.preventDefault();

                var ids       = checked.map(function (cb) { return parseInt(cb.value, 10); }).filter(Boolean);
                var total     = ids.length;
                var done      = 0;
                var succeeded = 0;
                var failed    = 0;

                form.querySelectorAll('button, input[type="submit"]').forEach(function (b) { b.disabled = true; });
                log.innerHTML = '';
                current.textContent = '';
                bar.value = 0;
                bar.max = total;
                label.textContent = '<?php echo esc_js(__('Converting…', 'webp-convert')); ?> 0 / ' + total;
                box.style.display = 'block';
                box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

                var chain = Promise.resolve();
                ids.forEach(function (id) {
                    chain = chain.then(function () {
                        current.textContent = '#' + id;
                        return convertOne(id).then(function (res) {
                            done++;
                            var data = (res && res.data) || {};
                            var name = data.filename || ('#' + id);
                            if (res && res.success) {
                                succeeded++;
                                appendLog('✓ ' + name, '#00a32a');
                            } else {
                                failed++;
                                appendLog('✗ ' + name + (data.message ? ' — ' + data.message : ''), '#d63638');
                            }
                            bar.value = done;
                            label.textContent = '<?php echo esc_js(__('Converting…', 'webp-convert')); ?> ' + done + ' / ' + total;
                        });
                    });
                });

                chain.then(function () {
                    current.textContent = '';
                    label.textContent = '<?php echo esc_js(__('Done.', 'webp-convert')); ?> ' + succeeded + ' / ' + total + (failed ? ' (' + failed + ' failed)' : '');
                    var url = new URL(window.location.href);
                    url.searchParams.set('page', '<?php echo esc_js(self::MANUAL_SLUG); ?>');
                    url.searchParams.set('paged', paged);
                    url.searchParams.set('webp_converted', '1');
                    url.searchParams.set('webp_count', String(succeeded));
                    setTimeout(function () { window.location.href = url.toString(); }, 1200);
                });
            });
        })();
        </script>
        <?php

        echo '</div>';
    }

    private function render_tablenav_pages(WP_Query $query, int $paged): void
    {
        $total_items = (int) $query->found_posts;
        $total_pages = (int) $query->max_num_pages;

        if ($total_items < 1) {
            return;
        }

        $base_url  = admin_url('admin.php?page=' . self::MANUAL_SLUG);
        $first_url = add_query_arg('paged', 1, $base_url);
        $prev_url  = add_query_arg('paged', max(1, $paged - 1), $base_url);
        $next_url  = add_query_arg('paged', min($total_pages, $paged + 1), $base_url);
        $last_url  = add_query_arg('paged', $total_pages, $base_url);

        $classes = ['tablenav-pages'];
        if ($total_pages <= 1) {
            $classes[] = 'one-page';
        }
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
            <span class="displaying-num">
                <?php echo esc_html(sprintf(
                    /* translators: %s: formatted item count */
                    _n('%s item', '%s items', $total_items, 'webp-convert'),
                    number_format_i18n($total_items)
                )); ?>
            </span>
            <?php if ($total_pages > 1) : ?>
                <span class="pagination-links">
                    <?php $this->render_pagination_button($first_url, '&laquo;', __('First page', 'webp-convert'), $paged <= 1, 'first-page'); ?>
                    <?php $this->render_pagination_button($prev_url, '&lsaquo;', __('Previous page', 'webp-convert'), $paged <= 1, 'prev-page'); ?>
                    <span class="paging-input">
                        <span class="tablenav-paging-text">
                            <?php echo esc_html((string) $paged); ?>
                            <span class="tablenav-paging-text">
                                <?php esc_html_e('of', 'webp-convert'); ?>
                                <span class="total-pages"><?php echo esc_html((string) $total_pages); ?></span>
                            </span>
                        </span>
                    </span>
                    <?php $this->render_pagination_button($next_url, '&rsaquo;', __('Next page', 'webp-convert'), $paged >= $total_pages, 'next-page'); ?>
                    <?php $this->render_pagination_button($last_url, '&raquo;', __('Last page', 'webp-convert'), $paged >= $total_pages, 'last-page'); ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_pagination_button(string $url, string $symbol, string $label, bool $disabled, string $class): void
    {
        if ($disabled) {
            printf(
                '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">%s</span>',
                $symbol // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hard-coded entities.
            );
            return;
        }
        printf(
            '<a class="%1$s button" href="%2$s"><span class="screen-reader-text">%3$s</span><span aria-hidden="true">%4$s</span></a>',
            esc_attr($class),
            esc_url($url),
            esc_html($label),
            $symbol // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hard-coded entities.
        );
    }

    public function row_action(array $actions, $post): array
    {
        if (!current_user_can('upload_files') || !in_array(get_post_mime_type($post), $this->settings->get_convertible_mimes(), true)) {
            return $actions;
        }
        $url = wp_nonce_url(
            admin_url('admin.php?action=convert_webp&attachment_id=' . $post->ID),
            'convert_webp_' . $post->ID
        );
        $actions['webp_convert'] = '<a href="' . esc_url($url) . '">' . esc_html__('Convert to WebP', 'webp-convert') . '</a>';
        return $actions;
    }

    public function handle_row_action(): void
    {
        $id = isset($_GET['attachment_id']) ? (int) $_GET['attachment_id'] : 0;
        if (!$id || !current_user_can('upload_files')) {
            wp_die(esc_html__('Insufficient permissions.', 'webp-convert'));
        }
        check_admin_referer('convert_webp_' . $id);

        $ok       = $this->converter->manual_convert($id);
        $redirect = add_query_arg(
            ['webp_converted' => $ok ? 1 : 0, 'webp_count' => 1],
            wp_get_referer() ?: admin_url('upload.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public function register_bulk_action(array $actions): array
    {
        $actions['convert_webp'] = __('Convert to WebP', 'webp-convert');
        return $actions;
    }

    public function handle_bulk_action(string $redirect, string $action, array $ids): string
    {
        if ($action !== 'convert_webp') {
            return $redirect;
        }
        if (!current_user_can('upload_files')) {
            return $redirect;
        }

        $count = 0;
        foreach ($ids as $id) {
            if ($this->converter->manual_convert((int) $id)) {
                $count++;
            }
        }
        return add_query_arg(
            ['webp_converted' => 1, 'webp_count' => $count],
            $redirect
        );
    }

    public function bulk_notice(): void
    {
        if (!isset($_GET['webp_converted'])) {
            return;
        }
        $count = isset($_GET['webp_count']) ? (int) $_GET['webp_count'] : 0;
        $ok    = (int) $_GET['webp_converted'] === 1;

        $class   = $ok && $count ? 'notice-success' : 'notice-warning';
        $message = $ok && $count
            ? sprintf(_n('%d image converted to WebP.', '%d images converted to WebP.', $count, 'webp-convert'), $count)
            : __('No images were converted. Check that the file is a JPEG or PNG and that the server supports WebP.', 'webp-convert');

        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($message));
    }
}
