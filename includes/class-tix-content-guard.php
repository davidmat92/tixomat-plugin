<?php
/**
 * TIX Content Guard – KI-gestützte Inhaltsprüfung für Events
 *
 * Prüft Event-Inhalte (Titel, Beschreibung, Info-Sektionen) via Anthropic Claude API
 * auf verbotene, diskriminierende oder schädliche Inhalte, bevor sie veröffentlicht werden.
 *
 * FAIL-CLOSED: Bei API-Fehlern wird das Event NICHT veröffentlicht.
 */
if (!defined('ABSPATH')) exit;

class TIX_Content_Guard {

    /** Anthropic Messages API Endpoint */
    const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Modell (schnell + günstig für Moderation) */
    const MODEL = 'claude-3-5-haiku-20241022';

    /** System-Prompt für die Inhaltsprüfung */
    const SYSTEM_PROMPT = <<<'PROMPT'
Du bist ein strenger Content-Moderator für eine Event-Ticketing-Plattform.

Prüfe den folgenden Event-Text auf:
1. Hassrede, rassistische Beleidigungen, Slurs, Diskriminierung jeder Art
2. Gewaltverherrlichung oder Aufrufe zu Gewalt
3. Illegale Inhalte oder Werbung für illegale Aktivitäten
4. Betrug, Spam, Phishing oder irreführende Inhalte
5. Sexuell explizite oder pornografische Inhalte
6. Terrorismus-Verherrlichung oder Extremismus
7. Persönlichkeitsrechtsverletzungen oder Doxxing

REGELN:
- Normale Events (Konzerte, Partys, Festivals, Messen, Sport, Workshops etc.) sind erlaubt.
- Rassistische Begriffe, Slurs und Beleidigungen sind IMMER ein Verstoß, auch wenn sie im Titel stehen.
- Bei JEDEM Verstoß: ablehnen. Sei streng.

Antworte AUSSCHLIESSLICH mit einem JSON-Objekt (kein Markdown, kein Text davor/danach):
- Genehmigt: {"approved": true}
- Abgelehnt: {"approved": false, "reason": "Kurze Begründung auf Deutsch"}
PROMPT;

    /**
     * Hooks registrieren
     */
    public static function init() {
        add_action('save_post_event', [__CLASS__, 'check'], 12, 2);
        add_action('admin_notices',   [__CLASS__, 'admin_notice']);
    }

    /**
     * Hauptprüfung – läuft nach TIX_Metabox::save() (Prio 10)
     */
    public static function check($post_id, $post) {

        // ── Guards ──
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Feature aktiviert?
        $enabled = tix_get_settings('ai_guard_enabled');
        if (!$enabled) return;

        // API-Key vorhanden?
        $api_key = trim(tix_get_settings('ai_guard_api_key') ?? '');
        if (empty($api_key)) {
            // Kein API-Key → blockieren + Hinweis
            if (get_post_status($post_id) === 'publish') {
                self::revert_to_draft($post_id);
                set_transient('tix_ai_flag_' . $post_id,
                    'KI-Schutz ist aktiviert, aber kein API-Key hinterlegt. '
                    . 'Bitte unter Einstellungen → Erweitert → KI-Schutz den Anthropic API Key eintragen.',
                    120);
            }
            return;
        }

        // Nur bei Status 'publish' prüfen
        $current_status = get_post_status($post_id);
        if ($current_status !== 'publish') return;

        // ── Content sammeln ──
        $content = self::collect_content($post_id, $post);
        if (empty(trim($content))) return;

        // ── Hash-Cache: bereits geprüfter Content? ──
        $hash = md5($content);
        $stored_hash = get_post_meta($post_id, '_tix_ai_content_hash', true);

        if ($hash === $stored_hash) {
            // Content unverändert seit letzter Prüfung
            if (get_post_meta($post_id, '_tix_ai_flagged', true)) {
                // Weiterhin geblockt
                self::revert_to_draft($post_id);
                $reason = get_post_meta($post_id, '_tix_ai_flag_reason', true);
                set_transient('tix_ai_flag_' . $post_id,
                    $reason ?: 'Inhalt wurde zuvor als problematisch eingestuft. Bitte Inhalt ändern.', 120);
            }
            // Wenn approved → durchlassen
            return;
        }

        // ── Vorherige Flags löschen (neuer Content wird geprüft) ──
        delete_post_meta($post_id, '_tix_ai_flagged');
        delete_post_meta($post_id, '_tix_ai_flag_reason');
        delete_post_meta($post_id, '_tix_ai_approved');

        // ── API-Aufruf ──
        $result = self::call_api($content, $api_key);

        // Timestamp + Hash speichern
        update_post_meta($post_id, '_tix_ai_content_hash', $hash);
        update_post_meta($post_id, '_tix_ai_checked_at', time());

        if ($result === null) {
            // ═══ FAIL-CLOSED: API-Fehler → Event NICHT veröffentlichen ═══
            $error_msg = get_transient('_tix_ai_last_error');
            delete_transient('_tix_ai_last_error');

            self::revert_to_draft($post_id);

            $notice = 'KI-Schutz: API-Fehler – Event wurde nicht veröffentlicht.';
            if ($error_msg) {
                $notice .= ' Fehler: ' . $error_msg;
            }
            $notice .= ' Prüfe den API-Key in den Einstellungen.';

            set_transient('tix_ai_flag_' . $post_id, $notice, 120);
            error_log('[TIX Content Guard] API-Fehler für Event #' . $post_id . ': ' . ($error_msg ?: 'unbekannt'));
            return;
        }

        if ($result['approved']) {
            // ── Genehmigt ──
            update_post_meta($post_id, '_tix_ai_approved', 1);
            delete_post_meta($post_id, '_tix_ai_flagged');
            delete_post_meta($post_id, '_tix_ai_flag_reason');
        } else {
            // ── Abgelehnt → Zurück auf Entwurf ──
            $reason = $result['reason'] ?? 'Der Inhalt wurde von der KI-Prüfung abgelehnt.';

            update_post_meta($post_id, '_tix_ai_flagged', 1);
            update_post_meta($post_id, '_tix_ai_flag_reason', sanitize_text_field($reason));
            delete_post_meta($post_id, '_tix_ai_approved');

            self::revert_to_draft($post_id);

            set_transient('tix_ai_flag_' . $post_id, $reason, 120);
        }
    }

    /**
     * Admin-Notice anzeigen wenn KI ein Event blockiert hat
     */
    public static function admin_notice() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'event' || $screen->base !== 'post') return;

        global $post;
        if (!$post) return;

        // ── KI-Flag / Fehler Notice ──
        $reason = get_transient('tix_ai_flag_' . $post->ID);
        if ($reason) {
            delete_transient('tix_ai_flag_' . $post->ID);

            $is_api_error = (strpos($reason, 'API-Fehler') !== false || strpos($reason, 'API-Key') !== false);

            echo '<div class="notice notice-error tix-ai-flag-notice is-dismissible">';
            echo '<p><strong>🛡️ KI-Schutz: Event kann nicht veröffentlicht werden.</strong></p>';
            echo '<p>' . esc_html($reason) . '</p>';
            if (!$is_api_error) {
                echo '<p class="description">Passe den Inhalt an und versuche es erneut. '
                   . 'Wenn du glaubst, dass dies ein Fehler ist, kann ein Administrator das Flag unter '
                   . '<em>Erweitert → KI-Schutz</em> kurz deaktivieren und das Event manuell veröffentlichen.</p>';
            }
            echo '</div>';
        }

        // ── Persistente Flag-Anzeige im Editor ──
        if (get_post_meta($post->ID, '_tix_ai_flagged', true)) {
            $stored_reason = get_post_meta($post->ID, '_tix_ai_flag_reason', true);
            if ($stored_reason && !$reason) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>🛡️ Dieses Event wurde vom KI-Schutz markiert:</strong> ' . esc_html($stored_reason) . '</p>';
                echo '</div>';
            }
        }
    }

    /**
     * Event-Content sammeln (Titel + Excerpt + Info-Sektionen)
     */
    private static function collect_content($post_id, $post) {
        $parts = [];

        // Titel
        if (!empty($post->post_title) && $post->post_title !== 'Automatischer Entwurf') {
            $parts[] = 'Titel: ' . $post->post_title;
        }

        // Excerpt (Kurzbeschreibung)
        if (!empty($post->post_excerpt)) {
            $parts[] = 'Kurzbeschreibung: ' . $post->post_excerpt;
        }

        // Info-Sektionen (Beschreibung, Kontakt, etc.)
        $sections = get_post_meta($post_id, '_tix_info_sections', true);
        if (is_array($sections)) {
            foreach ($sections as $section) {
                $heading = trim($section['heading'] ?? '');
                $body    = trim($section['body'] ?? '');
                if ($heading || $body) {
                    $text = '';
                    if ($heading) $text .= $heading . ': ';
                    if ($body)    $text .= wp_strip_all_tags($body);
                    $parts[] = $text;
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Anthropic API aufrufen
     *
     * @param string $text    Der zu prüfende Text
     * @param string $api_key Anthropic API Key
     * @return array|null     ['approved' => bool, 'reason' => string] oder null bei Fehler
     */
    private static function call_api($text, $api_key) {

        // Text kürzen (max ~3000 Zeichen für Kosteneffizienz)
        if (mb_strlen($text) > 3000) {
            $text = mb_substr($text, 0, 3000) . "\n[…gekürzt]";
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 20,
            'headers' => [
                'x-api-key'         => $api_key,
                'content-type'      => 'application/json',
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => self::MODEL,
                'max_tokens' => 256,
                'system'     => self::SYSTEM_PROMPT,
                'messages'   => [
                    ['role' => 'user', 'content' => $text],
                ],
            ]),
        ]);

        // HTTP-Fehler (Netzwerk, Timeout, DNS)
        if (is_wp_error($response)) {
            $msg = $response->get_error_message();
            error_log('[TIX Content Guard] wp_remote_post Fehler: ' . $msg);
            set_transient('_tix_ai_last_error', 'Netzwerk-Fehler: ' . $msg, 60);
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // API-Fehler (401, 403, 429, 500, etc.)
        if ($code < 200 || $code >= 300) {
            $error_data = json_decode($body, true);
            $error_msg  = $error_data['error']['message'] ?? ('HTTP ' . $code);

            if ($code === 401) {
                $error_msg = 'Ungültiger API-Key (HTTP 401). Bitte prüfen.';
            } elseif ($code === 429) {
                $error_msg = 'Rate-Limit erreicht (HTTP 429). Bitte kurz warten.';
            } elseif ($code === 529) {
                $error_msg = 'Anthropic API überlastet (HTTP 529). Bitte kurz warten.';
            }

            error_log('[TIX Content Guard] API HTTP ' . $code . ': ' . $body);
            set_transient('_tix_ai_last_error', $error_msg, 60);
            return null;
        }

        // Response parsen
        $data = json_decode($body, true);

        if (!$data || empty($data['content'])) {
            error_log('[TIX Content Guard] Ungültige API-Response: ' . $body);
            set_transient('_tix_ai_last_error', 'Ungültige API-Antwort', 60);
            return null;
        }

        // Text aus Content-Blöcken extrahieren
        $ai_text = '';
        foreach ($data['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $ai_text .= $block['text'];
            }
        }

        // JSON aus der AI-Antwort extrahieren
        $ai_text = trim($ai_text);

        // Falls in Markdown-Codeblock gewrapped
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $ai_text, $m)) {
            $ai_text = $m[1];
        }

        $result = json_decode($ai_text, true);
        if (!is_array($result) || !isset($result['approved'])) {
            error_log('[TIX Content Guard] JSON-Parse-Fehler, AI antwortete: ' . $ai_text);
            set_transient('_tix_ai_last_error', 'KI-Antwort konnte nicht geparst werden: ' . mb_substr($ai_text, 0, 100), 60);
            return null;
        }

        return [
            'approved' => (bool) $result['approved'],
            'reason'   => $result['reason'] ?? '',
        ];
    }

    /**
     * Post auf Entwurf zurücksetzen (identisches Muster wie Pflichtfeld-Validierung)
     */
    private static function revert_to_draft($post_id) {
        remove_action('save_post_event', [__CLASS__, 'check'], 12);
        remove_action('save_post_event', ['TIX_Metabox', 'save'], 10);
        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
        add_action('save_post_event', ['TIX_Metabox', 'save'], 10, 2);
        add_action('save_post_event', [__CLASS__, 'check'], 12, 2);
    }
}
