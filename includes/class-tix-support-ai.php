<?php
/**
 * TIX_Support_AI — KI-gestützte Support-Features (Anthropic/OpenAI)
 *
 * - summarize_thread(): 1-Satz-Zusammenfassung einer Anfrage für die Liste
 * - suggest_reply():    Antwort-Entwurf basierend auf vollem Kontext
 *
 * Nutzt denselben API-Key wie TIX_AI_Writer (anthropic_api_key / openai_api_key
 * aus tix_settings). Fällt sauber zurück, wenn kein Key konfiguriert ist.
 *
 * @since 1.38.126
 */
if (!defined('ABSPATH')) exit;

class TIX_Support_AI {

    public static function init() {
        // Auto-Summary bei neuer Nachricht (asynchron via wp-cron)
        add_action('tix_sp_summary_async', [__CLASS__, 'cron_summarize'], 10, 1);
        // 1.38.506: einmalig vorhandene KI-Zusammenfassungen von Awareness-Anfragen löschen
        add_action('init', [__CLASS__, 'purge_excluded_once'], 40);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DATENSCHUTZ (1.38.506): Awareness nie an die KI, sonst nur das Nötige
    // ══════════════════════════════════════════════════════════════════════

    const EXCLUDED_META = '_tix_sp_ai_excluded';

    /**
     * Anfrage von der KI ausgenommen? Awareness-Meldungen (Kategorie „awareness“, Melden/Hilfe vor Ort) können Daten nach
     * Art. 9 DSGVO enthalten. Einmal erkannt, bleibt die Anfrage dauerhaft ausgenommen (auch nach Kategorie-Wechsel).
     */
    public static function excluded($ticket_id) {
        $ticket_id = intval($ticket_id);
        if (!$ticket_id) return false;
        if (get_post_meta($ticket_id, self::EXCLUDED_META, true)) return true;
        $ex = (string) get_post_meta($ticket_id, '_tix_sp_category', true) === 'awareness';
        $ex = (bool) apply_filters('tix_ai_excluded', $ex, $ticket_id);
        if ($ex) {
            update_post_meta($ticket_id, self::EXCLUDED_META, 1);
            delete_post_meta($ticket_id, '_tix_sp_ai_summary');
        }
        return $ex;
    }

    public static function excluded_error() {
        return new WP_Error('tix_ai_excluded', 'Diese Anfrage ist aus Datenschutzgründen von der KI ausgenommen (Awareness).', ['status' => 403]);
    }

    /** Einmalig: gespeicherte Zusammenfassungen bei Awareness-Anfragen löschen. */
    public static function purge_excluded_once() {
        if (get_option('tix_ai_awareness_purged_506')) return;
        update_option('tix_ai_awareness_purged_506', current_time('mysql'), false);
        $ids = get_posts(['post_type' => 'tix_support_ticket', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_sp_category', 'value' => 'awareness']]]);
        foreach ($ids as $id) { delete_post_meta($id, '_tix_sp_ai_summary'); update_post_meta($id, self::EXCLUDED_META, 1); }
    }

    /** Datenminimierung: E-Mail-Adressen, Telefonnummern und IBAN im Text schwärzen. */
    public static function redact($text) {
        $t = (string) $text;
        $t = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[E-Mail]', $t);
        $t = preg_replace('/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){3,7}(?:[ ]?[A-Z0-9]{1,4})?\b/', '[IBAN]', $t);
        // Telefon: + bzw. Ziffern mit Leerzeichen, /, -, Klammern, mindestens 8 Zeichen (keine Punkte → Datumsangaben bleiben)
        $t = preg_replace('/(?<![\w.,])(?:\+|00)?\d[\d \/()-]{6,}\d(?!\w)(?![.,]\d)/', '[Telefon]', $t);
        return $t;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUBLIC: SUMMARY (1 Satz für die Liste)
    // ══════════════════════════════════════════════════════════════════════

    public static function summarize_thread($post, $messages) {
        if ($post && self::excluded($post->ID)) return self::excluded_error();
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return new WP_Error('no_key', 'Kein KI-API-Key hinterlegt (Einstellungen → Erweitert → KI).');
        }

        // Thread auf max. 6000 Chars kürzen (Token-Schutz)
        $thread = '';
        $count = 0;
        foreach ($messages as $m) {
            if (($m['type'] ?? '') === 'note') continue;
            $who = ($m['type'] === 'admin') ? 'Support' : 'Kunde';
            $date = $m['date'] ?? '';
            $thread .= "[$who – $date]\n" . self::redact(trim($m['content'] ?? '')) . "\n\n";
            $count++;
            if (strlen($thread) > 6000) break;
        }
        if (!$thread) $thread = 'Keine Nachrichten.';

        $system = 'Du bist ein Support-Assistent für ein Event-Ticketing-System. ' .
                  'Fasse die folgende Support-Anfrage in EINEM SATZ auf Deutsch zusammen ' .
                  '(max. 18 Wörter). Schreibe sachlich, ohne Anrede, ohne Emojis. ' .
                  'Wenn der Kunde wartet/Folge-Anfragen hat, erwähne das. ' .
                  'Beispiel: "Kunde hat Tickets nicht erhalten, hat 2× nachgefasst, wartet seit 12h auf Bestätigung."';

        $user = "BETREFF: " . self::redact($post->post_title) . "\n\nVERLAUF:\n" . $thread;

        $result = self::call_api($system, $user, 120);
        if (isset($result['error'])) return new WP_Error('api', $result['error']);

        $text = trim($result['text'] ?? '');
        // Erste Zeile + Punkt am Ende
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/^["„""]|["„""]$/u', '', $text);
        return $text;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUBLIC: ANTWORT-VORSCHLAG
    // ══════════════════════════════════════════════════════════════════════

    public static function suggest_reply($ctx) {
        if (!empty($ctx['ticket_id']) && self::excluded($ctx['ticket_id'])) return self::excluded_error();
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return new WP_Error('no_key', 'Kein KI-API-Key hinterlegt (Einstellungen → Erweitert → KI).');
        }

        // Thread aufbauen (nur Kunden+Admin, keine Notes)
        $thread = '';
        foreach (($ctx['messages'] ?? []) as $m) {
            if (($m['type'] ?? '') === 'note') continue;
            $who = ($m['type'] === 'admin') ? 'Support' : 'Kunde';
            $thread .= "[$who]\n" . self::redact(trim($m['content'] ?? '')) . "\n\n";
            if (strlen($thread) > 8000) break;
        }

        // Templates als Inspiration
        $tpl_block = '';
        if (!empty($ctx['templates'])) {
            $tpl_block = "\n\nVERFÜGBARE STANDARD-ANTWORTEN (als Inspiration):\n";
            foreach (array_slice($ctx['templates'], 0, 6) as $t) {
                $tpl_block .= "## " . ($t['title'] ?? '') . "\n" . trim($t['body'] ?? '') . "\n\n";
            }
        }

        $name        = $ctx['customer']['name']  ?? '';
        $email       = $ctx['customer']['email'] ?? '';
        $first_name  = trim(explode(' ', $name)[0] ?? '') ?: 'zusammen';
        $order_info  = $ctx['order_info']   ?? '';
        $tickets_info = $ctx['tickets_info'] ?? '';
        $ticket_code = $ctx['ticket_code']  ?? '';
        $category    = $ctx['category']     ?? '';
        $subject     = $ctx['subject']      ?? '';

        $system = "Du bist ein freundlicher, lösungsorientierter Support-Mitarbeiter " .
                  "eines Event-Ticketing-Systems. Sprache: Deutsch, du-Form. " .
                  "Schreibe eine **konkrete Antwort-Mail** (kein Brainstorming, kein „Hier ein Vorschlag\"). " .
                  "Beginne mit \"Hallo {$first_name},\". Sei freundlich, aber nicht übertrieben. " .
                  "Antworte direkt auf das Anliegen. " .
                  "Wenn die Standard-Antworten passen, nutze deren Wording. " .
                  "Schließe mit „Viele Grüße\" (kein Name dahinter — wird vom Admin ergänzt). " .
                  "WICHTIG: Wenn du nicht sicher bist (z.B. AGB-Detail, Veranstaltungsdatum), " .
                  "schreibe stattdessen [PRÜFEN: …] in eckigen Klammern, damit der Admin nachprüft. " .
                  "Erfinde keine Bestelldaten oder Tickets, die nicht im Kontext stehen.";

        // Datenminimierung (1.38.506): nur der Vorname für die Anrede, keine E-Mail; Texte geschwärzt
        $context = "## ANFRAGE\n";
        $context .= "Betreff: " . self::redact($subject) . "\n";
        $context .= "Kategorie: $category\n";
        $context .= "Kunde (Vorname): $first_name\n";
        if ($ticket_code) $context .= "Ticket-Code: $ticket_code\n";
        if ($order_info) $context .= "\n## BESTELLUNG\n" . self::redact($order_info) . "\n";
        if ($tickets_info) $context .= "\n" . self::redact($tickets_info) . "\n";
        $context .= "\n## VERLAUF\n$thread";
        $context .= $tpl_block;
        $context .= "\n\nAUFGABE: Schreibe nur die Antwort-Mail (Plain Text). Keine Meta-Kommentare.";

        $result = self::call_api($system, $context, 800);
        if (isset($result['error'])) return new WP_Error('api', $result['error']);

        return trim($result['text'] ?? '');
    }

    // ══════════════════════════════════════════════════════════════════════
    // CRON: Async-Summary (für neue Tickets)
    // ══════════════════════════════════════════════════════════════════════

    public static function cron_summarize($ticket_id) {
        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_support_ticket') return;
        if (self::excluded($ticket_id)) return; // Awareness nie an die KI
        if (!class_exists('TIX_Support')) return;

        $messages = TIX_Support::get_messages_public($ticket_id);
        $hash = md5(wp_json_encode($messages) . $post->post_title);
        $existing = get_post_meta($ticket_id, '_tix_sp_ai_summary', true);
        if (is_array($existing) && ($existing['hash'] ?? '') === $hash) return;

        $summary = self::summarize_thread($post, $messages);
        if (is_wp_error($summary)) return;

        update_post_meta($ticket_id, '_tix_sp_ai_summary', [
            'hash' => $hash,
            'text' => $summary,
            'date' => current_time('c'),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // INTERNAL: API-Calls
    // ══════════════════════════════════════════════════════════════════════

    private static function get_settings() {
        return function_exists('tix_get_settings') ? (function() {
            $s = tix_get_settings();
            return is_array($s) ? $s : [];
        })() : [];
    }

    private static function get_api_key() {
        $s = self::get_settings();
        if (!empty($s['ai_provider']) && $s['ai_provider'] === 'openai') {
            return trim($s['openai_api_key'] ?? '');
        }
        return trim($s['anthropic_api_key'] ?? '');
    }

    /** Modell der Support-KI (für Antworten mit `model`). */
    public static function model() {
        return self::get_model();
    }

    private static function get_model() {
        $s = self::get_settings();
        if (!empty($s['ai_provider']) && $s['ai_provider'] === 'openai') {
            return $s['openai_model'] ?? 'gpt-4o-mini';
        }
        // Schnelles + günstiges Modell für Support — Haiku reicht völlig (1.38.503: nur aktuelle Modelle, sonst Haiku 5.5)
        $m = (string) ($s['ai_support_model'] ?? '');
        return (class_exists('TIX_AI_Writer') && isset(TIX_AI_Writer::MODELS[$m])) ? $m : 'claude-haiku-5-5';
    }

    private static function is_openai() {
        $s = self::get_settings();
        return !empty($s['ai_provider']) && $s['ai_provider'] === 'openai';
    }

    private static function call_api($system, $user, $max_tokens = 512) {
        $api_key = self::get_api_key();
        if (empty($api_key)) return ['error' => 'Kein KI-API-Key hinterlegt.'];

        if (self::is_openai()) {
            return self::call_openai($system, $user, $max_tokens);
        }
        return self::call_anthropic($system, $user, $max_tokens);
    }

    private static function call_anthropic($system, $user, $max_tokens) {
        $payload = [
            'model'      => self::get_model(),
            'max_tokens' => $max_tokens,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ];
        $response = class_exists('TIX_AI_Writer') ? TIX_AI_Writer::anthropic_post($payload, self::get_api_key(), 30) : wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30, 'headers' => ['x-api-key' => self::get_api_key(), 'content-type' => 'application/json', 'anthropic-version' => '2023-06-01'], 'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) return ['error' => $response->get_error_message()];

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? "API-Fehler ($code)";
            return ['error' => $msg];
        }

        $text = $body['content'][0]['text'] ?? '';
        return ['text' => $text];
    }

    private static function call_openai($system, $user, $max_tokens) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . self::get_api_key(),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'    => self::get_model(),
                'max_completion_tokens' => $max_tokens,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
            ]),
        ]);

        if (is_wp_error($response)) return ['error' => $response->get_error_message()];

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $body['error']['message'] ?? "API-Fehler ($code)";
            return ['error' => $msg];
        }

        $text = $body['choices'][0]['message']['content'] ?? '';
        return ['text' => $text];
    }
}
