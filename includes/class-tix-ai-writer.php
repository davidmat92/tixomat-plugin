<?php
/**
 * TIX AI Writer – KI-generierte Textinhalte für Events
 *
 * Nutzt den gleichen Anthropic API-Key wie der Content Guard.
 * Features:
 * 1. Automatische Textauszug-Generierung aus Event-Daten
 * 2. KI-Fill für alle Info-Felder aus Bild oder URL
 */
if (!defined('ABSPATH')) exit;

class TIX_AI_Writer {

    const API_URL       = 'https://api.anthropic.com/v1/messages';
    const DEFAULT_MODEL = 'claude-sonnet-4-20250514';

    private static function get_model() {
        return tix_get_settings('ai_model') ?: self::DEFAULT_MODEL;
    }

    /**
     * Hooks registrieren
     */
    public static function init() {
        add_action('wp_ajax_tix_ai_generate_excerpt', [__CLASS__, 'ajax_generate_excerpt']);
        add_action('wp_ajax_tix_ai_fill_fields',      [__CLASS__, 'ajax_fill_fields']);
    }

    /**
     * API-Key aus Settings holen
     */
    private static function get_api_key() {
        return trim(tix_get_settings('anthropic_api_key') ?? '');
    }

    /**
     * Anthropic API aufrufen (Text-only)
     */
    private static function call_api($system, $user_content, $max_tokens = 512) {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return ['error' => 'Kein API-Key hinterlegt. Bitte unter Einstellungen → Erweitert → KI-Schutz den Anthropic API Key eintragen.'];
        }

        $messages = [
            ['role' => 'user', 'content' => $user_content],
        ];

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $api_key,
                'content-type'      => 'application/json',
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => self::get_model(),
                'max_tokens' => $max_tokens,
                'system'     => $system,
                'messages'   => $messages,
            ]),
        ]);

        return self::parse_response($response);
    }

    /**
     * Anthropic API aufrufen mit Bild (Vision)
     */
    private static function call_api_with_image($system, $text_prompt, $image_data, $media_type, $max_tokens = 2048) {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return ['error' => 'Kein API-Key hinterlegt.'];
        }

        $content = [];

        // Image block
        $content[] = [
            'type'   => 'image',
            'source' => [
                'type'         => 'base64',
                'media_type'   => $media_type,
                'data'         => $image_data,
            ],
        ];

        // Text block
        $content[] = [
            'type' => 'text',
            'text' => $text_prompt,
        ];

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 45,
            'headers' => [
                'x-api-key'         => $api_key,
                'content-type'      => 'application/json',
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => self::get_model(),
                'max_tokens' => $max_tokens,
                'system'     => $system,
                'messages'   => [
                    ['role' => 'user', 'content' => $content],
                ],
            ]),
        ]);

        return self::parse_response($response);
    }

    /**
     * API Response parsen
     */
    private static function parse_response($response) {
        if (is_wp_error($response)) {
            return ['error' => 'Netzwerk-Fehler: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            $error_data = json_decode($body, true);
            $error_msg = $error_data['error']['message'] ?? ('HTTP ' . $code);
            if ($code === 401) $error_msg = 'Ungültiger API-Key.';
            if ($code === 429) $error_msg = 'Rate-Limit erreicht. Bitte kurz warten.';
            return ['error' => $error_msg];
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['content'])) {
            return ['error' => 'Ungültige API-Antwort.'];
        }

        $text = '';
        foreach ($data['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return ['text' => trim($text)];
    }

    // ──────────────────────────────────────────
    // AJAX: Textauszug generieren
    // ──────────────────────────────────────────
    public static function ajax_generate_excerpt() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error(['message' => 'Keine Post-ID.']);

        // Daten sammeln
        $post = get_post($post_id);
        $title = sanitize_text_field($_POST['title'] ?? ($post->post_title ?? ''));

        // Info-Sections aus POST oder DB
        $description = $_POST['description'] ?? get_post_meta($post_id, '_tix_info_description', true);
        $lineup      = $_POST['lineup'] ?? get_post_meta($post_id, '_tix_info_lineup', true);
        $specials    = $_POST['specials'] ?? get_post_meta($post_id, '_tix_info_specials', true);
        $extra_info  = $_POST['extra_info'] ?? get_post_meta($post_id, '_tix_info_extra_info', true);

        // Event-Details
        $date_start = $_POST['date_start'] ?? get_post_meta($post_id, '_tix_date_start', true);
        $time_start = $_POST['time_start'] ?? get_post_meta($post_id, '_tix_time_start', true);
        $location   = '';
        $loc_id = intval($_POST['location_id'] ?? get_post_meta($post_id, '_tix_location_id', true));
        if ($loc_id) {
            $location = get_the_title($loc_id);
            $loc_addr = get_post_meta($loc_id, '_tix_loc_address', true);
            if ($loc_addr) $location .= ', ' . $loc_addr;
        }

        // Ticket-Infos
        $tickets = get_post_meta($post_id, '_tix_ticket_categories', true);
        $ticket_info = '';
        if (is_array($tickets)) {
            $names = [];
            $prices = [];
            foreach ($tickets as $t) {
                if (!empty($t['name'])) $names[] = $t['name'];
                if (!empty($t['price'])) $prices[] = number_format($t['price'], 2, ',', '.') . ' €';
            }
            if ($names) $ticket_info = 'Tickets: ' . implode(', ', $names);
            if ($prices) $ticket_info .= ' (ab ' . min(array_map('floatval', array_column($tickets, 'price'))) . ' €)';
        }

        // Prompt zusammenbauen
        $parts = [];
        if ($title) $parts[] = "Event-Titel: {$title}";
        if ($date_start) {
            $date_fmt = date_i18n('l, d. F Y', strtotime($date_start));
            $parts[] = "Datum: {$date_fmt}";
        }
        if ($time_start) $parts[] = "Uhrzeit: {$time_start} Uhr";
        if ($location) $parts[] = "Location: {$location}";
        if ($description) $parts[] = "Beschreibung: " . wp_strip_all_tags($description);
        if ($lineup) $parts[] = "Line-Up: " . wp_strip_all_tags($lineup);
        if ($specials) $parts[] = "Specials: " . wp_strip_all_tags($specials);
        if ($extra_info) $parts[] = "Weitere Infos: " . wp_strip_all_tags($extra_info);
        if ($ticket_info) $parts[] = $ticket_info;

        $event_context = implode("\n", $parts);

        if (empty(trim($event_context)) || $title === 'Automatischer Entwurf') {
            wp_send_json_error(['message' => 'Zu wenig Informationen. Bitte fülle zuerst den Titel und ggf. die Beschreibung aus.']);
        }

        $system = <<<'PROMPT'
Du bist ein SEO-Experte und Marketing-Texter für eine Event-Ticketing-Plattform. Deine Aufgabe ist es, eine Google-optimierte Meta-Description (Zusammenfassung) für ein Event zu schreiben.

REGELN:
- EXAKT 140-160 Zeichen (Google schneidet bei ~160 Zeichen ab)
- Beginne mit dem wichtigsten Keyword (Event-Name/Art)
- Nenne Datum, Ort und 1-2 Highlights
- Verwende einen Call-to-Action am Ende (z.B. "Jetzt Tickets sichern!", "Tickets hier!")
- Schreibe auf Deutsch in einem einladenden, aktivierenden Ton
- Verwende keine Sonderzeichen die Google nicht darstellt
- KEIN Markdown, KEINE Emojis, KEIN HTML, KEINE Anführungszeichen
- Antworte NUR mit der Meta-Description selbst, keine Erklärungen
- Der Text muss eigenständig funktionieren und Klickanreiz in Google-Suchergebnissen bieten
PROMPT;

        $result = self::call_api($system, "Schreibe einen Textauszug für folgendes Event:\n\n" . $event_context, 256);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        wp_send_json_success(['excerpt' => $result['text']]);
    }

    // ──────────────────────────────────────────
    // AJAX: Felder aus Bild oder URL füllen
    // ──────────────────────────────────────────
    public static function ajax_fill_fields() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $source_type = sanitize_text_field($_POST['source_type'] ?? '');
        $source_content = '';
        $image_data = null;
        $media_type = null;

        if ($source_type === 'image') {
            // Bild als Attachment ID
            $attachment_id = intval($_POST['attachment_id'] ?? 0);
            if (!$attachment_id) {
                wp_send_json_error(['message' => 'Kein Bild ausgewählt.']);
            }

            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) {
                wp_send_json_error(['message' => 'Bild-Datei nicht gefunden.']);
            }

            $mime = get_post_mime_type($attachment_id);
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($mime, $allowed)) {
                wp_send_json_error(['message' => 'Bildformat nicht unterstützt. Erlaubt: JPG, PNG, GIF, WEBP.']);
            }

            // Bild-Größe prüfen (max 5MB für API)
            $size = filesize($file_path);
            if ($size > 5 * 1024 * 1024) {
                wp_send_json_error(['message' => 'Bild zu groß (max. 5 MB).']);
            }

            $image_data = base64_encode(file_get_contents($file_path));
            $media_type = $mime;

        } elseif ($source_type === 'url') {
            $url = esc_url_raw($_POST['source_url'] ?? '');
            if (empty($url)) {
                wp_send_json_error(['message' => 'Keine URL angegeben.']);
            }

            // URL-Inhalt fetchen
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'user-agent' => 'TixomatBot/1.0',
            ]);
            if (is_wp_error($response)) {
                wp_send_json_error(['message' => 'URL konnte nicht geladen werden: ' . $response->get_error_message()]);
            }

            $body = wp_remote_retrieve_body($response);
            $content_type = wp_remote_retrieve_header($response, 'content-type');

            // Prüfen ob es ein Bild ist
            if (strpos($content_type, 'image/') === 0) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $detected_mime = explode(';', $content_type)[0];
                if (in_array($detected_mime, $allowed_types)) {
                    $image_data = base64_encode($body);
                    $media_type = $detected_mime;
                } else {
                    wp_send_json_error(['message' => 'Bildformat der URL nicht unterstützt.']);
                }
            } else {
                // HTML → Text extrahieren
                $source_content = self::extract_text_from_html($body, $url);
                if (empty(trim($source_content))) {
                    wp_send_json_error(['message' => 'Kein verwertbarer Inhalt auf der Seite gefunden.']);
                }
            }
        } else {
            wp_send_json_error(['message' => 'Ungültiger Quellentyp.']);
        }

        // System-Prompt für Feld-Extraktion
        $system = <<<'PROMPT'
Du bist ein Assistent für eine Event-Ticketing-Plattform. Deine Aufgabe ist es, aus der gegebenen Quelle (Bild eines Flyers/Plakats oder Webseiten-Text) Event-Informationen zu extrahieren.

Extrahiere folgende Felder und antworte AUSSCHLIESSLICH mit einem JSON-Objekt:

{
    "title": "Event-Titel",
    "description": "Ausführliche Beschreibung des Events (2-4 Sätze, HTML erlaubt: <strong>, <em>, <br>)",
    "lineup": "Künstler/Acts/Sprecher (HTML erlaubt, mit <br> getrennt falls mehrere)",
    "specials": "Besondere Features/Highlights (HTML erlaubt)",
    "extra_info": "Zusätzliche Informationen wie Dresscode, Hinweise etc. (HTML erlaubt)",
    "age_limit": "Mindestalter als Zahl oder leer",
    "date_start": "Startdatum im Format YYYY-MM-DD oder leer",
    "date_end": "Enddatum im Format YYYY-MM-DD oder leer (gleich wie start wenn eintägig)",
    "time_start": "Startzeit im Format HH:MM oder leer",
    "time_end": "Endzeit im Format HH:MM oder leer",
    "time_doors": "Einlass im Format HH:MM oder leer",
    "location": "Name der Location oder leer",
    "excerpt": "Kurzer Textauszug (1-2 Sätze, max 200 Zeichen, kein HTML)"
}

REGELN:
- Felder die nicht erkennbar sind: leerer String ""
- Schreibe auf Deutsch
- KEIN Markdown-Codeblock, NUR das JSON-Objekt
- Beschreibungen sollen einladend und professionell klingen
- Bei Bildern: lies ALLE sichtbaren Texte, Daten und Informationen
PROMPT;

        $text_prompt = 'Extrahiere die Event-Informationen aus dieser Quelle.';
        if ($source_content) {
            $text_prompt .= "\n\nWebseiten-Inhalt:\n" . mb_substr($source_content, 0, 4000);
        }

        if ($image_data) {
            $result = self::call_api_with_image($system, $text_prompt, $image_data, $media_type, 2048);
        } else {
            $result = self::call_api($system, $text_prompt, 2048);
        }

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        // JSON parsen
        $text = $result['text'];
        // Markdown-Codeblock entfernen falls vorhanden
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $text = $m[1];
        }

        $fields = json_decode($text, true);
        if (!is_array($fields)) {
            wp_send_json_error(['message' => 'KI-Antwort konnte nicht verarbeitet werden. Bitte versuche es erneut.']);
        }

        // Felder sanitizen
        $clean = [
            'title'       => sanitize_text_field($fields['title'] ?? ''),
            'description' => wp_kses_post($fields['description'] ?? ''),
            'lineup'      => wp_kses_post($fields['lineup'] ?? ''),
            'specials'    => wp_kses_post($fields['specials'] ?? ''),
            'extra_info'  => wp_kses_post($fields['extra_info'] ?? ''),
            'age_limit'   => $fields['age_limit'] !== '' ? intval($fields['age_limit']) : '',
            'date_start'  => sanitize_text_field($fields['date_start'] ?? ''),
            'date_end'    => sanitize_text_field($fields['date_end'] ?? ''),
            'time_start'  => sanitize_text_field($fields['time_start'] ?? ''),
            'time_end'    => sanitize_text_field($fields['time_end'] ?? ''),
            'time_doors'  => sanitize_text_field($fields['time_doors'] ?? ''),
            'location'    => sanitize_text_field($fields['location'] ?? ''),
            'excerpt'     => sanitize_text_field($fields['excerpt'] ?? ''),
        ];

        wp_send_json_success(['fields' => $clean]);
    }

    /**
     * HTML → lesbaren Text extrahieren
     */
    private static function extract_text_from_html($html, $url = '') {
        // Title
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // Meta description
        $desc = '';
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) {
            $desc = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // OG tags
        $og = [];
        preg_match_all('/<meta[^>]+property=["\']og:([^"\']+)["\'][^>]+content=["\'](.*?)["\']/si', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $og[$m[1]] = html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8');
        }

        // Body text (strip scripts, styles, nav, footer)
        $body = $html;
        $body = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $body);
        $body = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $body);
        $body = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $body);
        $body = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $body);
        $body = preg_replace('/<header[^>]*>.*?<\/header>/si', '', $body);
        $body = wp_strip_all_tags($body);
        $body = preg_replace('/\s+/', ' ', $body);
        $body = trim($body);

        $parts = [];
        if ($url) $parts[] = "URL: {$url}";
        if ($title) $parts[] = "Seitentitel: {$title}";
        if ($desc) $parts[] = "Meta-Description: {$desc}";
        if (!empty($og['title'])) $parts[] = "OG-Title: {$og['title']}";
        if (!empty($og['description'])) $parts[] = "OG-Description: {$og['description']}";
        if ($body) $parts[] = "Seitentext:\n" . mb_substr($body, 0, 3000);

        return implode("\n\n", $parts);
    }
}
