<?php
/**
 * TIX_Email_Tracking — Pixel-Open-Tracking + Customer-Feedback-Endpoints
 *
 * Stellt zwei REST-Endpoints bereit:
 *   GET /tix/v1/mail-pixel/{token}.gif           → 1×1 GIF, markiert die Mail als geöffnet
 *   GET/POST /tix/v1/mail-feedback/{token}/{value} → Speichert Feedback und zeigt Bestätigungsseite
 *
 * @since 1.38.124
 */
if (!defined('ABSPATH')) exit;

class TIX_Email_Tracking {

    const VALID_FEEDBACK = ['helpful', 'not_helpful', 'need_more'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('tix/v1', '/mail-pixel/(?P<token>[a-zA-Z0-9]+)(?:\.gif)?', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'serve_pixel'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route('tix/v1', '/mail-feedback/(?P<token>[a-zA-Z0-9]+)/(?P<value>[a-z_]+)', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [__CLASS__, 'handle_feedback'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => ['type' => 'string', 'required' => true],
                'value' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Pixel-Endpoint
    // ══════════════════════════════════════════════════════════════════════

    public static function serve_pixel($request) {
        $token = sanitize_text_field($request['token'] ?? '');
        // .gif-Suffix entfernen (manche Clients packen das in den Pfad)
        $token = preg_replace('/\.gif$/i', '', $token);

        if ($token && strlen($token) === 32) {
            self::record_open($token);
        }

        // 1×1 transparentes GIF
        $gif = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');

        // Verhindert Caching durch Mail-Clients/Proxies — sonst würden Re-Opens nicht getrackt
        nocache_headers();
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($gif));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $gif;
        exit;
    }

    private static function record_open($token) {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_email_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, opened_at, open_count FROM $t WHERE tracking_token = %s LIMIT 1",
            $token
        ));
        if (!$row) return;

        $now = current_time('mysql');
        $update = [
            'open_count'      => intval($row->open_count) + 1,
            'last_opened_at'  => $now,
        ];
        if (empty($row->opened_at) || $row->opened_at === '0000-00-00 00:00:00') {
            $update['opened_at'] = $now;
        }
        $wpdb->update($t, $update, ['id' => intval($row->id)]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Feedback-Endpoint
    // ══════════════════════════════════════════════════════════════════════

    public static function handle_feedback($request) {
        $token = sanitize_text_field($request['token'] ?? '');
        $value = sanitize_text_field($request['value'] ?? '');
        $note  = sanitize_textarea_field($request->get_param('note') ?? '');

        if (!$token || strlen($token) !== 32 || !in_array($value, self::VALID_FEEDBACK, true)) {
            status_header(400);
            return self::render_feedback_page('error', 'Ungültiger Link.', '', '');
        }

        global $wpdb;
        $t = $wpdb->prefix . 'tix_email_log';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, to_email, subject, source, feedback_value FROM $t WHERE tracking_token = %s LIMIT 1",
            $token
        ));
        if (!$row) {
            status_header(404);
            return self::render_feedback_page('error', 'Feedback-Link nicht gefunden.', '', '');
        }

        $is_update = !empty($row->feedback_value) && $row->feedback_value !== $value;

        $wpdb->update($t, [
            'feedback_value' => $value,
            'feedback_at'    => current_time('mysql'),
            'feedback_text'  => $note ?: null,
        ], ['id' => intval($row->id)]);

        // Bei "need_more" → optional einen Support-Folgereply triggern (Hook)
        do_action('tix_email_feedback_received', $value, $row, $note);

        // Wenn die Mail aus Support kam → interne Notiz im Ticket anlegen
        self::log_feedback_to_support($row, $value, $note);

        return self::render_feedback_page('ok', $value, $row->subject, $token, $is_update);
    }

    private static function log_feedback_to_support($row, $value, $note) {
        if (stripos($row->source, 'tix_support') === false) return;
        if (!class_exists('TIX_Support')) return;

        // Ticket-ID aus Subject extrahieren — Format: "Neue Antwort zu deiner Anfrage #123"
        if (!preg_match('/#(\d+)/', $row->subject, $m)) return;
        $ticket_id = intval($m[1]);
        if (!$ticket_id) return;

        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_support_ticket') return;

        $labels = [
            'helpful'     => '👍 Hilfreich',
            'not_helpful' => '👎 Nicht hilfreich',
            'need_more'   => '💬 Brauche mehr Hilfe',
        ];
        $label = $labels[$value] ?? $value;

        // Reflection-freie interne Notiz via add_message (private, daher direkt Meta updaten)
        $meta_key = '_tix_sp_messages';
        $raw = get_post_meta($ticket_id, $meta_key, true);
        $messages = $raw ? json_decode($raw, true) : [];
        if (!is_array($messages)) $messages = [];

        $content = 'Kunden-Feedback per E-Mail: ' . $label;
        if ($note) $content .= "\n\n„" . $note . '"';

        $messages[] = [
            'id'      => uniqid('msg_', true),
            'type'    => 'note',
            'author'  => 'System (Mail-Feedback)',
            'user_id' => 0,
            'content' => $content,
            'date'    => current_time('c'),
        ];
        update_post_meta($ticket_id, $meta_key, wp_json_encode($messages));

        // Bei "need_more" Status auf "in Bearbeitung" zurücksetzen, falls schon "gelöst"
        if ($value === 'need_more' && $post->post_status === 'tix_resolved') {
            wp_update_post(['ID' => $ticket_id, 'post_status' => 'tix_progress']);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Feedback-Bestätigungsseite (HTML)
    // ══════════════════════════════════════════════════════════════════════

    private static function render_feedback_page($state, $value_or_msg, $subject = '', $token = '', $is_update = false) {
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        $brand_name = get_bloginfo('name');
        $accent     = '#FF5500';

        if ($state === 'error') {
            $title   = 'Fehler';
            $heading = 'Hoppla — etwas ist schiefgelaufen';
            $sub     = esc_html($value_or_msg);
            $body    = '<p style="margin:0;color:#6b7280;font-size:14px;line-height:1.6;">Bitte schreibe direkt eine Antwort auf die letzte E-Mail oder stelle eine neue Support-Anfrage.</p>';
            $color   = '#dc2626';
            $emoji   = '⚠️';
        } else {
            $messages = [
                'helpful' => [
                    'emoji' => '🎉',
                    'heading' => 'Danke für dein Feedback!',
                    'sub' => 'Wir freuen uns, dass wir dir helfen konnten.',
                    'body' => 'Falls du noch weitere Fragen hast, kannst du jederzeit direkt auf die E-Mail antworten oder eine neue Support-Anfrage stellen.',
                    'color' => '#16a34a',
                ],
                'not_helpful' => [
                    'emoji' => '🙏',
                    'heading' => 'Danke — wir machen es besser',
                    'sub' => 'Wir schauen uns deinen Fall noch einmal an.',
                    'body' => 'Wenn du möchtest, antworte bitte einfach auf die E-Mail mit weiteren Details. Unser Team meldet sich zeitnah erneut bei dir.',
                    'color' => '#dc2626',
                ],
                'need_more' => [
                    'emoji' => '💬',
                    'heading' => 'Verstanden — wir kümmern uns',
                    'sub' => 'Dein Anliegen wurde erneut für unser Team markiert.',
                    'body' => 'Bitte antworte auf die letzte E-Mail mit weiteren Details, damit wir gezielt helfen können.',
                    'color' => '#d97706',
                ],
            ];
            $m = $messages[$value_or_msg] ?? $messages['helpful'];
            $emoji = $m['emoji'];
            $heading = $m['heading'];
            $sub = $m['sub'];
            $body = '<p style="margin:0;color:#6b7280;font-size:14px;line-height:1.6;">' . esc_html($m['body']) . '</p>';
            $color = $m['color'];

            if ($subject) {
                $body .= '<p style="margin:18px 0 0;font-size:12px;color:#9ca3af;">Bezug: ' . esc_html($subject) . '</p>';
            }
            if ($is_update) {
                $body = '<p style="margin:0 0 14px;font-size:13px;color:#92400e;background:#fef3c7;padding:10px 14px;border-radius:8px;">Dein vorheriges Feedback wurde aktualisiert.</p>' . $body;
            }
        }
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html($heading ?? 'Feedback'); ?> — <?php echo esc_html($brand_name); ?></title>
<style>
  *,*::before,*::after{box-sizing:border-box}
  body{margin:0;padding:24px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#f3f4f6;min-height:100vh;display:flex;align-items:center;justify-content:center}
  .card{max-width:480px;width:100%;background:#fff;border-radius:14px;padding:36px 28px;box-shadow:0 4px 20px rgba(0,0,0,0.06);text-align:center}
  .emoji{font-size:48px;line-height:1;margin-bottom:12px}
  h1{margin:0 0 6px;font-size:22px;font-weight:700;color:<?php echo esc_attr($color); ?>}
  .sub{margin:0 0 18px;font-size:14px;color:#475569;line-height:1.5}
  .brand{margin-top:24px;padding-top:18px;border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af;letter-spacing:0.04em;text-transform:uppercase}
</style>
</head>
<body>
<div class="card">
    <div class="emoji"><?php echo esc_html($emoji); ?></div>
    <h1><?php echo esc_html($heading); ?></h1>
    <p class="sub"><?php echo esc_html($sub); ?></p>
    <?php echo $body; ?>
    <div class="brand"><?php echo esc_html($brand_name); ?></div>
</div>
</body>
</html>
        <?php
        exit;
    }
}
