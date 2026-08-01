<?php
/**
 * Plugin Name: Imaedge Gallery Importer
 * Description: Importiert Bilder aus einem Imaedge-Export-Link oder HTML-Source in kurzen AJAX-Schritten und erstellt optional einen Galerie-Beitrag.
 * Version: 1.1.1
 * Author: Klaus Breyer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Imaedge_Gallery_Importer {
    private const MENU_SLUG = 'imaedge-gallery-importer';
    private const NONCE_ACTION = 'imaedge_gallery_import';
    private const JOB_TTL = HOUR_IN_SECONDS;

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('wp_ajax_imaedge_gallery_start', [$this, 'handle_start']);
        add_action('wp_ajax_imaedge_gallery_next', [$this, 'handle_next']);
    }

    public function register_menu(): void {
        add_menu_page(
            'Imaedge Gallery Importer',
            'Imaedge Gallery Importer',
            'upload_files',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-format-gallery',
            56
        );
    }

    public function render_page(): void {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('Du hast keine Berechtigung, Dateien hochzuladen.', 'imaedge-gallery-importer'));
        }

        $nonce = wp_create_nonce(self::NONCE_ACTION);
        ?>
        <div class="wrap">
            <h1>Imaedge Gallery Importer</h1>
            <p>Füge einen Imaedge-Export-Link oder HTML-Source ein. Die Bilder werden einzeln importiert, damit lange Cloudflare-Requests vermieden werden.</p>

            <form id="imaedge-import-form">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="imaedge-title">Titel</label></th>
                        <td><input id="imaedge-title" name="title" type="text" class="regular-text" value="Imported Gallery"></td>
                    </tr>
                    <tr>
                        <th scope="row">Beitrag erstellen</th>
                        <td>
                            <label>
                                <input name="create_page" type="checkbox" value="1">
                                Neuen Entwurfs-Beitrag mit Galerie erstellen
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="imaedge-source">Quelle</label></th>
                        <td>
                            <textarea id="imaedge-source" name="source" rows="12" class="large-text code" required></textarea>
                            <p class="description">Imaedge-Export-URL, direkte Bild-URL oder HTML-Source.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button id="imaedge-submit" type="submit" class="button button-primary">Import starten</button>
                </p>
            </form>

            <div id="imaedge-progress" hidden>
                <h2>Import läuft</h2>
                <p id="imaedge-progress-text">Quelle wird geprüft …</p>
                <progress id="imaedge-progress-bar" value="0" max="1" style="width:100%;max-width:700px"></progress>
                <pre id="imaedge-log" style="max-width:900px;max-height:320px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;white-space:pre-wrap"></pre>
            </div>

            <div id="imaedge-result" hidden></div>
        </div>

        <script>
        (() => {
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            const form = document.getElementById('imaedge-import-form');
            const submit = document.getElementById('imaedge-submit');
            const progress = document.getElementById('imaedge-progress');
            const progressText = document.getElementById('imaedge-progress-text');
            const progressBar = document.getElementById('imaedge-progress-bar');
            const log = document.getElementById('imaedge-log');
            const result = document.getElementById('imaedge-result');

            const post = async (data) => {
                const body = new URLSearchParams(data);
                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                });

                let json;
                try {
                    json = await response.json();
                } catch (error) {
                    throw new Error('Ungültige Serverantwort (HTTP ' + response.status + ').');
                }

                if (!response.ok || !json.success) {
                    const message = json && json.data && json.data.message
                        ? json.data.message
                        : 'Import fehlgeschlagen (HTTP ' + response.status + ').';
                    throw new Error(message);
                }

                return json.data;
            };

            const renderLog = (lines) => {
                log.textContent = Array.isArray(lines) ? lines.join('\n') : '';
                log.scrollTop = log.scrollHeight;
            };

            const escapeHtml = (value) => String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');

            const renderResult = (data) => {
                const errors = Array.isArray(data.errors) ? data.errors : [];
                let html = '<div class="notice notice-success inline"><p><strong>Fertig.</strong> '
                    + escapeHtml(data.imported) + ' Bilder importiert.</p></div>';

                if (data.shortcode) {
                    html += '<h2>Gallery Shortcode</h2><textarea class="large-text code" rows="2" readonly>'
                        + escapeHtml(data.shortcode) + '</textarea>';
                }

                if (data.block) {
                    html += '<h2>Gutenberg Gallery Block</h2><textarea class="large-text code" rows="8" readonly>'
                        + escapeHtml(data.block) + '</textarea>';
                }

                if (data.post_edit_url) {
                    html += '<p><a class="button" href="' + escapeHtml(data.post_edit_url) + '">Erstellten Galerie-Beitrag bearbeiten</a></p>';
                }

                if (errors.length) {
                    html += '<h2>Fehler / übersprungene URLs</h2><ul>'
                        + errors.map((error) => '<li><code>' + escapeHtml(error) + '</code></li>').join('')
                        + '</ul>';
                }

                result.innerHTML = html;
                result.hidden = false;
            };

            const runNext = async (runId) => {
                const data = await post({
                    action: 'imaedge_gallery_next',
                    _wpnonce: nonce,
                    run_id: runId
                });

                renderLog(data.log);
                progressBar.max = Math.max(1, data.total || 1);
                progressBar.value = Math.min(data.processed || 0, progressBar.max);
                progressText.textContent = data.finished
                    ? 'Import abgeschlossen.'
                    : 'Bild ' + ((data.processed || 0) + 1) + ' von ' + (data.total || 0) + ' wird importiert …';

                if (data.finished) {
                    renderResult(data.result);
                    return;
                }

                await runNext(runId);
            };

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                submit.disabled = true;
                result.hidden = true;
                result.innerHTML = '';
                progress.hidden = false;
                progressBar.value = 0;
                progressBar.max = 1;
                progressText.textContent = 'Quelle wird geprüft …';
                log.textContent = '';

                const formData = new FormData(form);

                try {
                    const start = await post({
                        action: 'imaedge_gallery_start',
                        _wpnonce: nonce,
                        source: formData.get('source') || '',
                        title: formData.get('title') || 'Imported Gallery',
                        create_page: formData.get('create_page') ? '1' : ''
                    });

                    renderLog(start.log);
                    progressBar.max = Math.max(1, start.total || 1);
                    progressText.textContent = start.total + ' Bilder gefunden.';
                    await runNext(start.run_id);
                } catch (error) {
                    result.innerHTML = '<div class="notice notice-error inline"><p><strong>Fehler:</strong> '
                        + escapeHtml(error.message) + '</p></div>';
                    result.hidden = false;
                    progressText.textContent = 'Import abgebrochen.';
                } finally {
                    submit.disabled = false;
                }
            });
        })();
        </script>
        <?php
    }

    public function handle_start(): void {
        $this->authorize_ajax();

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }

        $source = isset($_POST['source']) ? trim(wp_unslash($_POST['source'])) : '';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'Imported Gallery';
        $create_page = !empty($_POST['create_page']);
        $run_id = sanitize_key(wp_generate_uuid4());

        $log = [];
        $this->log($log, 'Import gestartet.');
        $this->log($log, 'Quelle wird geprüft.');

        $source_data = $this->load_source($source, $log);
        if (is_wp_error($source_data)) {
            wp_send_json_error(['message' => $source_data->get_error_message()], 400);
        }

        $items = $this->extract_items($source_data['html'], $source_data['base_url']);
        if (!$items) {
            wp_send_json_error(['message' => 'Keine passenden Bild-URLs in der Quelle gefunden.'], 400);
        }

        $this->log($log, count($items) . ' Bild-URLs gefunden.');

        $job = [
            'items' => array_values($items),
            'position' => 0,
            'ids' => [],
            'errors' => [],
            'title' => $title ?: 'Imported Gallery',
            'create_page' => $create_page,
            'log' => $log,
            'created_at' => time(),
        ];

        set_transient($this->job_key($run_id), $job, self::JOB_TTL);

        wp_send_json_success([
            'run_id' => $run_id,
            'total' => count($items),
            'log' => $log,
        ]);
    }

    public function handle_next(): void {
        $this->authorize_ajax();

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(90);
        }

        $run_id = isset($_POST['run_id']) ? sanitize_key(wp_unslash($_POST['run_id'])) : '';
        if (!$run_id) {
            wp_send_json_error(['message' => 'Import-ID fehlt.'], 400);
        }

        $key = $this->job_key($run_id);
        $job = get_transient($key);
        if (!is_array($job)) {
            wp_send_json_error(['message' => 'Der Import ist abgelaufen oder wurde nicht gefunden. Bitte neu starten.'], 404);
        }

        $total = count($job['items']);
        $position = (int) $job['position'];

        if ($position < $total) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $item = $job['items'][$position];
            $this->log($job['log'], 'Importiere Bild ' . ($position + 1) . ' von ' . $total . ': ' . $item['url']);

            $attachment_id = $this->import_remote_file($item['url'], $item['filename'], $job['title']);
            if (is_wp_error($attachment_id)) {
                $message = $item['url'] . ' - ' . $attachment_id->get_error_message();
                $job['errors'][] = $message;
                $this->log($job['log'], 'Übersprungen: ' . $attachment_id->get_error_message());
            } else {
                $job['ids'][] = (int) $attachment_id;
                $this->log($job['log'], 'Importiert als Attachment #' . (int) $attachment_id . '.');
            }

            $job['position'] = $position + 1;
            set_transient($key, $job, self::JOB_TTL);
        }

        if ((int) $job['position'] < $total) {
            wp_send_json_success([
                'finished' => false,
                'processed' => (int) $job['position'],
                'total' => $total,
                'log' => $job['log'],
            ]);
        }

        $result = $this->finish_job($job);
        delete_transient($key);

        wp_send_json_success([
            'finished' => true,
            'processed' => $total,
            'total' => $total,
            'log' => $job['log'],
            'result' => $result,
        ]);
    }

    private function authorize_ajax(): void {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION);
    }

    private function finish_job(array &$job): array {
        $ids = array_values(array_unique(array_map('intval', $job['ids'])));
        $errors = array_values($job['errors']);
        $shortcode = '';
        $block = '';
        $post_edit_url = '';

        if ($ids) {
            $this->log($job['log'], 'Galerie wird vorbereitet.');
            $shortcode = '[gallery ids="' . implode(',', $ids) . '"]';
            $block = $this->build_gallery_block($ids);

            if (!empty($job['create_page'])) {
                $this->log($job['log'], 'Entwurfs-Beitrag wird erstellt.');
                $post_id = wp_insert_post([
                    'post_title' => $job['title'],
                    'post_status' => 'draft',
                    'post_type' => 'post',
                    'post_content' => $block,
                ], true);

                if (is_wp_error($post_id)) {
                    $errors[] = 'Beitrag konnte nicht erstellt werden: ' . $post_id->get_error_message();
                    $this->log($job['log'], 'Beitrag konnte nicht erstellt werden: ' . $post_id->get_error_message());
                } else {
                    $post_edit_url = (string) get_edit_post_link($post_id, 'raw');
                    $this->log($job['log'], 'Entwurfs-Beitrag erstellt.');
                }
            }
        }

        $this->log($job['log'], 'Fertig. ' . count($ids) . ' Bilder importiert.');

        return [
            'imported' => count($ids),
            'shortcode' => $shortcode,
            'block' => $block,
            'post_edit_url' => $post_edit_url,
            'errors' => $errors,
        ];
    }

    private function build_gallery_block(array $ids): string {
        $images = [];

        foreach ($ids as $id) {
            $src = wp_get_attachment_image_url($id, 'large');
            if (!$src) {
                continue;
            }

            $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
            $images[] = [
                'id' => (int) $id,
                'url' => $src,
                'alt' => $alt,
            ];
        }

        if (!$images) {
            return '';
        }

        $attrs = [
            'images' => array_map(static function (array $image): array {
                return [
                    'id' => $image['id'],
                    'url' => $image['url'],
                    'alt' => $image['alt'],
                    'caption' => '',
                ];
            }, $images),
            'linkTo' => 'none',
        ];

        $html = '<!-- wp:gallery ' . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' -->' . "\n";
        $html .= '<figure class="wp-block-gallery has-nested-images columns-default is-cropped">' . "\n";

        foreach ($images as $image) {
            $html .= '<!-- wp:image {"id":' . (int) $image['id'] . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n";
            $html .= '<figure class="wp-block-image size-large"><img src="' . esc_url($image['url']) . '" alt="' . esc_attr($image['alt']) . '" class="wp-image-' . (int) $image['id'] . '"/></figure>' . "\n";
            $html .= '<!-- /wp:image -->' . "\n";
        }

        $html .= '</figure>' . "\n";
        $html .= '<!-- /wp:gallery -->';

        return $html;
    }

    private function load_source(string $source, array &$log) {
        if ($source === '') {
            return new WP_Error('empty_source', 'Bitte einen Imaedge-Export-Link oder HTML-Source einfügen.');
        }

        if ($this->is_probably_url($source)) {
            if ($this->looks_like_image_url($source)) {
                $this->log($log, 'Direkte Bild-URL erkannt.');
                return ['html' => $source, 'base_url' => $source];
            }

            if (!wp_http_validate_url($source)) {
                return new WP_Error('invalid_source_url', 'Ungültige oder nicht erlaubte Quell-URL.');
            }

            $this->log($log, 'Quell-URL wird geladen: ' . $source);
            $response = wp_remote_get($source, [
                'timeout' => 30,
                'redirection' => 5,
                'user-agent' => 'Imaedge Gallery Importer/' . get_bloginfo('version') . '; ' . home_url('/'),
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('source_fetch_failed', 'Quell-URL konnte nicht geladen werden: ' . $response->get_error_message());
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $this->log($log, 'Quell-URL antwortet mit HTTP-Status ' . $status_code . '.');

            if ($status_code < 200 || $status_code >= 300) {
                return new WP_Error('source_bad_status', 'Quell-URL konnte nicht geladen werden. HTTP-Status: ' . $status_code);
            }

            return [
                'html' => wp_remote_retrieve_body($response),
                'base_url' => $source,
            ];
        }

        $this->log($log, 'HTML-Source erkannt.');
        return ['html' => $source, 'base_url' => ''];
    }

    private function extract_items(string $html, string $base_url = ''): array {
        $items = [];

        if (preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tag = $match[0];
                $url = $this->absolute_url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), $base_url);
                if (!$this->looks_like_image_url($url)) {
                    continue;
                }

                $filename = $this->attribute_from_tag($tag, 'download');
                if (!$filename || !$this->looks_like_image_url($filename)) {
                    $path = wp_parse_url($url, PHP_URL_PATH);
                    $filename = is_string($path) ? basename($path) : '';
                }

                $items[] = [
                    'url' => $url,
                    'filename' => sanitize_file_name($filename),
                ];
            }
        }

        if (!$items && $this->looks_like_image_url(trim($html))) {
            $url = $this->absolute_url(trim($html), $base_url);
            $path = wp_parse_url($url, PHP_URL_PATH);
            $items[] = [
                'url' => $url,
                'filename' => is_string($path) ? sanitize_file_name(basename($path)) : '',
            ];
        }

        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            if (!$item['url'] || isset($seen[$item['url']])) {
                continue;
            }
            $seen[$item['url']] = true;
            $unique[] = $item;
        }

        return $unique;
    }

    private function attribute_from_tag(string $tag, string $attribute): string {
        if (preg_match('/\s' . preg_quote($attribute, '/') . '=["\']([^"\']+)["\']/i', $tag, $match)) {
            return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        }
        return '';
    }

    private function looks_like_image_url(string $url): bool {
        $path = wp_parse_url($url, PHP_URL_PATH);
        return is_string($path) && (bool) preg_match('/\.(jpe?g|png|webp|gif|avif)$/i', $path);
    }

    private function is_probably_url(string $value): bool {
        return (bool) preg_match('/^https?:\/\/[^\s<>"\']+$/i', trim($value));
    }

    private function absolute_url(string $url, string $base_url = ''): string {
        $url = trim($url);
        if ($url === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            $scheme = wp_parse_url($base_url, PHP_URL_SCHEME);
            return ($scheme ?: 'https') . ':' . $url;
        }

        if (!$base_url || !preg_match('/^https?:\/\//i', $base_url)) {
            return $url;
        }

        $base = wp_parse_url($base_url);
        if (empty($base['scheme']) || empty($base['host'])) {
            return $url;
        }

        $host = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (strpos($url, '/') === 0) {
            return $host . $this->normalize_path($url);
        }

        $base_path = isset($base['path']) ? $base['path'] : '/';
        $directory = preg_replace('/\/[^\/]*$/', '/', $base_path);
        return $host . $this->normalize_path($directory . $url);
    }

    private function normalize_path(string $path): string {
        $segments = explode('/', $path);
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }

        return '/' . implode('/', $normalized);
    }

    private function import_remote_file(string $url, string $filename, string $title) {
        if (!wp_http_validate_url($url)) {
            return new WP_Error('invalid_url', 'Ungültige oder nicht erlaubte URL.');
        }

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        if (!$filename) {
            $path = wp_parse_url($url, PHP_URL_PATH);
            $filename = is_string($path) ? basename($path) : 'image.jpg';
        }

        $file_array = [
            'name' => sanitize_file_name($filename),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, 0, $title);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        return (int) $attachment_id;
    }

    private function job_key(string $run_id): string {
        return 'imaedge_gallery_job_' . get_current_user_id() . '_' . sanitize_key($run_id);
    }

    private function log(array &$lines, string $message): void {
        $lines[] = '[' . current_time('H:i:s') . '] ' . $message;
        $lines = array_slice($lines, -200);
    }
}

new Imaedge_Gallery_Importer();
