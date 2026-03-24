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

    const DEFAULT_MODEL = 'claude-sonnet-4-20250514';

    /** OpenAI-Modelle erkennen */
    private static function is_openai() {
        $model = self::get_model();
        return strpos($model, 'gpt') === 0 || strpos($model, 'o3') === 0 || strpos($model, 'o1') === 0;
    }

    private static function get_model() {
        return tix_get_settings('ai_model') ?: self::DEFAULT_MODEL;
    }

    public static function init() {
        add_action('wp_ajax_tix_ai_generate_excerpt', [__CLASS__, 'ajax_generate_excerpt']);
        add_action('wp_ajax_tix_ai_fill_fields',      [__CLASS__, 'ajax_fill_fields']);
        add_action('wp_ajax_tix_ai_upload_image',     [__CLASS__, 'ajax_upload_image']);
        add_action('wp_ajax_tix_ai_check_duplicates',  [__CLASS__, 'ajax_check_duplicates']);
    }

    private static function get_api_key() {
        if (self::is_openai()) {
            $key = trim(tix_get_settings('openai_api_key') ?? '');
            if (empty($key)) return '';
            return $key;
        }
        return trim(tix_get_settings('anthropic_api_key') ?? '');
    }

    /**
     * API aufrufen (Text-only) — routet automatisch zu Anthropic oder OpenAI
     */
    private static function call_api($system, $user_content, $max_tokens = 512) {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            $provider = self::is_openai() ? 'OpenAI' : 'Anthropic';
            return ['error' => "Kein {$provider} API-Key hinterlegt. Bitte unter Einstellungen → Erweitert → KI eintragen."];
        }

        if (self::is_openai()) {
            return self::call_openai($system, [['role' => 'user', 'content' => $user_content]], $max_tokens);
        }

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
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
                'messages'   => [['role' => 'user', 'content' => $user_content]],
            ]),
        ]);

        return self::parse_anthropic_response($response);
    }

    /**
     * API aufrufen mit Bild (Vision)
     */
    private static function call_api_with_image($system, $text_prompt, $image_data, $media_type, $max_tokens = 2048) {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return ['error' => 'Kein API-Key hinterlegt.'];
        }

        if (self::is_openai()) {
            $data_url = "data:{$media_type};base64,{$image_data}";
            $messages = [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => $data_url]],
                    ['type' => 'text', 'text' => $text_prompt],
                ],
            ]];
            return self::call_openai($system, $messages, $max_tokens);
        }

        // Anthropic Vision
        $content = [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $media_type, 'data' => $image_data]],
            ['type' => 'text', 'text' => $text_prompt],
        ];

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
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
                'messages'   => [['role' => 'user', 'content' => $content]],
            ]),
        ]);

        return self::parse_anthropic_response($response);
    }

    /**
     * OpenAI Chat Completions API
     */
    private static function call_openai($system, $messages, $max_tokens = 2048) {
        $api_key = self::get_api_key();

        array_unshift($messages, ['role' => 'system', 'content' => $system]);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'      => self::get_model(),
                'max_tokens' => $max_tokens,
                'messages'   => $messages,
            ]),
        ]);

        return self::parse_openai_response($response);
    }

    /**
     * Anthropic Response parsen
     */
    private static function parse_anthropic_response($response) {
        if (is_wp_error($response)) {
            return ['error' => 'Netzwerk-Fehler: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            $error_data = json_decode($body, true);
            $error_msg = $error_data['error']['message'] ?? ('HTTP ' . $code);
            if ($code === 401) $error_msg = 'Ungültiger Anthropic API-Key.';
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

    /**
     * OpenAI Response parsen
     */
    private static function parse_openai_response($response) {
        if (is_wp_error($response)) {
            return ['error' => 'Netzwerk-Fehler: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            $error_data = json_decode($body, true);
            $error_msg = $error_data['error']['message'] ?? ('HTTP ' . $code);
            if ($code === 401) $error_msg = 'Ungültiger OpenAI API-Key.';
            if ($code === 429) $error_msg = 'Rate-Limit erreicht. Bitte kurz warten.';
            return ['error' => $error_msg];
        }

        $data = json_decode($body, true);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if (empty($text)) {
            return ['error' => 'Leere OpenAI-Antwort.'];
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
Du bist ein Assistent für eine Event-Ticketing-Plattform. Deine Aufgabe ist es, aus der gegebenen Quelle (Bild eines Flyers/Plakats oder Webseiten-Text) ALLE Event-Informationen vollständig zu extrahieren.

Extrahiere folgende Felder und antworte AUSSCHLIESSLICH mit einem JSON-Objekt:

{
    "title": "Event-Titel (IMMER extrahieren, auch wenn nur ein Name erkennbar ist)",
    "description": "Ausführliche Beschreibung des Events (3-5 Sätze, HTML: <strong>, <em>, <br>). Schreibe einladend und professionell. Wenn wenig Info: ergänze sinnvoll basierend auf dem Event-Typ.",
    "lineup": "Künstler/Acts/DJs/Sprecher (HTML, mit <br> getrennt falls mehrere)",
    "specials": "Besondere Features/Highlights/Attraktionen (HTML)",
    "extra_info": "Dresscode, Hinweise, Anfahrt, Parken, Barrierefreiheit etc. (HTML)",
    "age_limit": "Mindestalter als Zahl oder leer",
    "date_start": "Startdatum YYYY-MM-DD oder leer",
    "date_end": "Enddatum YYYY-MM-DD oder leer (gleich wie start wenn eintägig)",
    "time_start": "Startzeit HH:MM oder leer",
    "time_end": "Endzeit HH:MM oder leer",
    "time_doors": "Einlass HH:MM oder leer",
    "location": "Name der Location oder leer",
    "location_address": "Adresse der Location oder leer",
    "excerpt": "SEO-optimierte Zusammenfassung (140-160 Zeichen, kein HTML, mit Call-to-Action)",
    "event_type": "Art des Events: konzert, party, festival, workshop, messe, sport, theater, comedy, networking, sonstiges",
    "tickets": [
        {"name": "Ticket-Name", "price": 0.00, "description": "Kurze Beschreibung"}
    ],
    "faq": [
        {"question": "Häufige Frage", "answer": "Antwort"}
    ]
}

REGELN:
- IMMER einen Titel extrahieren. Wenn unklar, nutze den prominentesten Text.
- Felder die nicht erkennbar sind: leerer String "" (oder leeres Array [] für tickets/faq)
- Schreibe auf Deutsch
- KEIN Markdown-Codeblock, NUR das JSON-Objekt
- Beschreibungen einladend und professionell
- Bei Bildern: lies ALLE sichtbaren Texte, Daten, Logos und Informationen
- tickets: Wenn Preise erkennbar sind, als Array zurückgeben. Sonst leeres Array.
- faq: Generiere 2-3 sinnvolle FAQ basierend auf dem Event-Typ (Anfahrt, Einlass, Dresscode etc.)
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
            'title'            => sanitize_text_field($fields['title'] ?? ''),
            'description'      => wp_kses_post($fields['description'] ?? ''),
            'lineup'           => wp_kses_post($fields['lineup'] ?? ''),
            'specials'         => wp_kses_post($fields['specials'] ?? ''),
            'extra_info'       => wp_kses_post($fields['extra_info'] ?? ''),
            'age_limit'        => ($fields['age_limit'] ?? '') !== '' ? intval($fields['age_limit']) : '',
            'date_start'       => sanitize_text_field($fields['date_start'] ?? ''),
            'date_end'         => sanitize_text_field($fields['date_end'] ?? ''),
            'time_start'       => sanitize_text_field($fields['time_start'] ?? ''),
            'time_end'         => sanitize_text_field($fields['time_end'] ?? ''),
            'time_doors'       => sanitize_text_field($fields['time_doors'] ?? ''),
            'location'         => sanitize_text_field($fields['location'] ?? ''),
            'location_address' => sanitize_text_field($fields['location_address'] ?? ''),
            'excerpt'          => sanitize_text_field($fields['excerpt'] ?? ''),
            'event_type'       => sanitize_text_field($fields['event_type'] ?? ''),
            'tickets'          => [],
            'faq'              => [],
        ];

        // Tickets sanitizen
        if (!empty($fields['tickets']) && is_array($fields['tickets'])) {
            foreach ($fields['tickets'] as $t) {
                if (empty($t['name'])) continue;
                $clean['tickets'][] = [
                    'name'        => sanitize_text_field($t['name']),
                    'price'       => floatval($t['price'] ?? 0),
                    'description' => sanitize_text_field($t['description'] ?? ''),
                ];
            }
        }

        // FAQ sanitizen
        if (!empty($fields['faq']) && is_array($fields['faq'])) {
            foreach ($fields['faq'] as $f) {
                if (empty($f['question'])) continue;
                $clean['faq'][] = [
                    'question' => sanitize_text_field($f['question']),
                    'answer'   => wp_kses_post($f['answer'] ?? ''),
                ];
            }
        }

        // ── Location Matching: Fuzzy-Match gegen bestehende Locations ──
        $clean['location_id'] = 0;
        if ($clean['location']) {
            $locations = get_posts([
                'post_type'      => 'tix_location',
                'post_status'    => 'any',
                'posts_per_page' => -1,
            ]);
            $best_match = 0;
            $best_score = 0;
            $search = mb_strtolower($clean['location']);
            foreach ($locations as $loc) {
                $name = mb_strtolower($loc->post_title);
                // Exakter Match
                if ($name === $search) {
                    $best_match = $loc->ID;
                    $best_score = 100;
                    break;
                }
                // Enthält den Namen
                if (strpos($name, $search) !== false || strpos($search, $name) !== false) {
                    $score = 80;
                    if ($score > $best_score) {
                        $best_match = $loc->ID;
                        $best_score = $score;
                    }
                }
                // similar_text Score
                similar_text($name, $search, $pct);
                if ($pct > 60 && $pct > $best_score) {
                    $best_match = $loc->ID;
                    $best_score = $pct;
                }
            }
            if ($best_match && $best_score >= 60) {
                $clean['location_id'] = $best_match;
            }
        }

        // ── Event-Kategorie Matching ──
        $clean['category_ids'] = [];
        if ($clean['event_type']) {
            $type_map = [
                'konzert'    => ['konzert', 'concert', 'live', 'musik'],
                'party'      => ['party', 'club', 'nachtleben', 'nightlife'],
                'festival'   => ['festival', 'open air', 'openair'],
                'workshop'   => ['workshop', 'seminar', 'kurs', 'schulung'],
                'messe'      => ['messe', 'expo', 'ausstellung', 'exhibition'],
                'sport'      => ['sport', 'turnier', 'marathon', 'lauf'],
                'theater'    => ['theater', 'schauspiel', 'aufführung', 'musical'],
                'comedy'     => ['comedy', 'stand-up', 'standup', 'kabarett'],
                'networking' => ['networking', 'meetup', 'business', 'konferenz'],
            ];
            $terms = get_terms(['taxonomy' => 'event_category', 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                $event_type_lower = mb_strtolower($clean['event_type']);
                foreach ($terms as $term) {
                    $term_lower = mb_strtolower($term->name);
                    // Direkter Match
                    if ($term_lower === $event_type_lower || strpos($term_lower, $event_type_lower) !== false) {
                        $clean['category_ids'][] = $term->term_id;
                        break;
                    }
                    // Synonym-Match
                    foreach ($type_map as $type => $synonyms) {
                        if ($event_type_lower === $type || in_array($event_type_lower, $synonyms)) {
                            foreach ($synonyms as $syn) {
                                if (strpos($term_lower, $syn) !== false) {
                                    $clean['category_ids'][] = $term->term_id;
                                    break 3;
                                }
                            }
                        }
                    }
                }
            }
        }

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

    // ──────────────────────────────────────────
    // AJAX: Bild direkt hochladen (ohne wp.media)
    // ──────────────────────────────────────────
    public static function ajax_upload_image() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('upload_files')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'Keine Datei empfangen.']);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('file', intval($_POST['post_id'] ?? 0));
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }

        $url = wp_get_attachment_image_url($attachment_id, 'medium');

        wp_send_json_success([
            'attachment_id' => $attachment_id,
            'url'           => $url,
            'filename'      => basename(get_attached_file($attachment_id)),
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Ähnliche eigene Events prüfen
    // ──────────────────────────────────────────
    public static function ajax_check_duplicates() {
        check_ajax_referer('tix_admin_action', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $title      = sanitize_text_field($_POST['title'] ?? '');
        $date_start = sanitize_text_field($_POST['date_start'] ?? '');
        $location   = sanitize_text_field($_POST['location'] ?? '');
        $post_id    = intval($_POST['post_id'] ?? 0);

        if (!$title) wp_send_json_success(['duplicates' => []]);

        // Nur eigene Events prüfen
        $args = [
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'pending'],
            'posts_per_page' => 5,
            'author'         => get_current_user_id(),
            'post__not_in'   => $post_id ? [$post_id] : [],
        ];

        $events = get_posts($args);
        $duplicates = [];

        $title_lower = mb_strtolower($title);

        foreach ($events as $ev) {
            $ev_title = mb_strtolower($ev->post_title);
            $ev_date  = get_post_meta($ev->ID, '_tix_date_start', true);
            $ev_loc   = get_post_meta($ev->ID, '_tix_location', true);

            $match = false;
            $reason = '';

            // Starke Übereinstimmung: fast identischer Titel
            similar_text($title_lower, $ev_title, $pct);
            if ($pct > 85) {
                $match = true;
                $reason = 'Ähnlicher Titel';
            }

            // Gleiche Location + gleiches Datum
            if (!$match && $date_start && $ev_date === $date_start && $location && $ev_loc) {
                if (mb_strtolower($location) === mb_strtolower($ev_loc)) {
                    $match = true;
                    $reason = 'Gleiche Location & Datum';
                }
            }

            if ($match) {
                $duplicates[] = [
                    'id'     => $ev->ID,
                    'title'  => $ev->post_title,
                    'date'   => $ev_date ? date_i18n('d.m.Y', strtotime($ev_date)) : '',
                    'reason' => $reason,
                    'url'    => get_edit_post_link($ev->ID, 'raw'),
                ];
            }
        }

        wp_send_json_success(['duplicates' => $duplicates]);
    }
}
