<?php
/**
 * TIX Content Guard – KI-gestützte Inhaltsprüfung für Events
 *
 * Prüft Event-Inhalte (Titel, Beschreibung, Info-Sektionen) via Anthropic Claude API
 * auf verbotene, diskriminierende oder schädliche Inhalte, bevor sie veröffentlicht werden.
 */
if (!defined('ABSPATH')) exit;

class TIX_Content_Guard {

    /** Anthropic Messages API Endpoint */
    const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Modell (schnell + günstig für Moderation) */
    const MODEL = 'claude-3-5-haiku-20241022';

    /** System-Prompt für die Inhaltsprüfung */
    const SYSTEM_PROMPT = <<<'PROMPT'
Du bist ein Content-Moderator für eine Event-Ticketing-Plattform (Konzerte, Partys, Messen, Workshops, Festivals etc.).

Prüfe den folgenden Event-Text auf:
1. Hassrede, Rassismus, Diskriminierung jeder Art
2. Gewaltverherrlichung oder Aufrufe zu Gewalt
3. Illegale Inhalte oder Werbung für illegale Aktivitäten
4. Betrug, Spam, Phishing oder irreführende Inhalte
5. Sexuell explizite oder pornografische Inhalte
6. Terrorismus-Verherrlichung oder Extremismus
7. Persönlichkeitsrechtsverletzungen oder Doxxing

WICHTIG:
- Normale Events (Konzerte, Partys, Festivals, Messen, Sportveranstaltungen, Workshops etc.) sind IMMER erlaubt.
- Satire und Humor sind grundsätzlich erlaubt, solange keine der oben genannten Grenzen überschritten wird.
- Im Zweifel: genehmige den Inhalt. Nur bei klaren Verstößen ablehnen.

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
        if (empty($api_key)) return;

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
            // Wenn flagged → erneut blocken
            if (get_post_meta($post_id, '_tix_ai_flagged', true)) {
                self::revert_to_draft($post_id);
                $reason = get_post_meta($post_id, '_tix_ai_flag_reason', true);
                set_transient('tix_ai_flag_' . $post_id, $reason ?: 'Inhalt wurde zuvor als problematisch eingestuft.', 120);
            }
            return;
        }

        // ── API-Aufruf ──
        $result = self::call_api($content, $api_key);

        // Timestamp + Hash speichern
        update_post_meta($post_id, '_tix_ai_content_hash', $hash);
        update_post_meta($post_id, '_tix_ai_checked_at', time());

        if ($result === null) {
            // API-Fehler → Fail-open (veröffentlichen erlauben)
            error_log('[TIX Content Guard] API-Fehler für Event #' . $post_id . ' – Fail-open');
            delete_post_meta($post_id, '_tix_ai_flagged');
            delete_post_meta($post_id, '_tix_ai_flag_reason');
            update_post_meta($post_id, '_tix_ai_approved', 1);
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

            // Transient für Admin-Notice
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

        $reason = get_transient('tix_ai_flag_' . $post->ID);
        if (!$reason) return;

        delete_transient('tix_ai_flag_' . $post->ID);

        echo '<div class="notice notice-error tix-ai-flag-notice is-dismissible">';
        echo '<p><strong>🛡️ KI-Schutz: Event kann nicht veröffentlicht werden.</strong></p>';
        echo '<p>' . esc_html($reason) . '</p>';
        echo '<p class="description">Passe den Inhalt an und versuche es erneut. '
           . 'Wenn du glaubst, dass dies ein Fehler ist, kann ein Administrator das Flag manuell entfernen.</p>';
        echo '</div>';
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
            'timeout' => 15,
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

        // HTTP-Fehler
        if (is_wp_error($response)) {
            error_log('[TIX Content Guard] wp_remote_post Fehler: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log('[TIX Content Guard] API HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
            return null;
        }

        // Response parsen
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || empty($data['content'])) {
            error_log('[TIX Content Guard] Ungültige API-Response: ' . $body);
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
            error_log('[TIX Content Guard] JSON-Parse-Fehler: ' . $ai_text);
            return null;  // Fail-open
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
