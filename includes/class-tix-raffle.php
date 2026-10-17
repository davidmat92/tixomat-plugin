<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Raffle – Gewinnspiel pro Event
 *
 * Separate Teilnahme (Name + E-Mail), Freitext-Preise oder Freikarten,
 * manuelle oder automatische Auslosung.
 */
class TIX_Raffle {

    const TABLE = 'tix_raffle_entries';

    /* ════════════════════════════════════
       INIT
       ════════════════════════════════════ */

    public static function init() {
        add_shortcode('tix_raffle', [__CLASS__, 'render']);
    }

    /* ════════════════════════════════════
       DB TABLE
       ════════════════════════════════════ */

    /**
     * Tabelle anlegen (bei Plugin-Aktivierung aufrufen).
     */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id    BIGINT UNSIGNED NOT NULL,
            name        VARCHAR(255) NOT NULL,
            email       VARCHAR(255) NOT NULL,
            ip          VARCHAR(45) DEFAULT '',
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_winner   TINYINT(1) DEFAULT 0,
            prize_index INT DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_entry (event_id, email),
            KEY idx_event (event_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Tabelle existiert?
     */
    private static function table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    }

    /**
     * Sicherstellen, dass Tabelle existiert (lazy create).
     */
    private static function ensure_table() {
        if (!self::table_exists()) {
            self::create_table();
        }
    }

    /* ════════════════════════════════════
       ASSETS
       ════════════════════════════════════ */

    private static function enqueue() {
        wp_enqueue_style(
            'tix-raffle',
            TIXOMAT_URL . 'assets/css/raffle.css',
            [],
            TIXOMAT_VERSION
        );
        wp_enqueue_script(
            'tix-raffle',
            TIXOMAT_URL . 'assets/js/raffle.js',
            [],
            TIXOMAT_VERSION,
            true
        );
        wp_localize_script('tix-raffle', 'tixRaffle', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    /* ════════════════════════════════════
       SHORTCODE [tix_raffle]
       ════════════════════════════════════ */

    public static function render($atts = []) {
        $atts    = shortcode_atts(['id' => 0, 'fullwidth' => '0', 'variant' => '1'], $atts, 'tix_raffle');
        $post_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

        if (!$post_id || get_post_type($post_id) !== 'event') return '';
        if (get_post_meta($post_id, '_tix_raffle_enabled', true) !== '1' || !self::globally_on()) return '';

        self::ensure_table();
        self::enqueue();

        $title    = get_post_meta($post_id, '_tix_raffle_title', true) ?: 'Gewinnspiel';
        $desc     = get_post_meta($post_id, '_tix_raffle_description', true);
        $end_date = get_post_meta($post_id, '_tix_raffle_end_date', true);
        $max      = intval(get_post_meta($post_id, '_tix_raffle_max_entries', true));
        $db_status    = get_post_meta($post_id, '_tix_raffle_status', true) ?: 'open';
        $prizes       = get_post_meta($post_id, '_tix_raffle_prizes', true);
        $winners      = get_post_meta($post_id, '_tix_raffle_winners', true);
        $hide_count   = get_post_meta($post_id, '_tix_raffle_hide_count', true) === '1';
        $consent_text  = get_post_meta($post_id, '_tix_raffle_consent_text', true);
        $header_bg     = get_post_meta($post_id, '_tix_raffle_header_bg', true);
        $header_color  = get_post_meta($post_id, '_tix_raffle_header_color', true);

        if (!is_array($prizes) || empty($prizes)) return '';

        // Aktuelle Einträge zählen
        $entry_count = self::count_entries($post_id);

        // Status komplett dynamisch bestimmen — nur 'drawn' wird aus DB respektiert
        if ($db_status === 'drawn') {
            $status = 'drawn';
        } else {
            $end_passed  = $end_date && strtotime($end_date) <= current_time('timestamp');
            $max_reached = $max > 0 && $entry_count >= $max;
            $status = ($end_passed || $max_reached) ? 'closed' : 'open';
        }

        // Nonce
        $nonce = wp_create_nonce('tix_raffle_' . $post_id);

        ob_start();
        ?>
        <?php $tix_v = intval($atts['variant']) === 2 ? 2 : 1; ?>
        <div class="tix-raffle<?php echo $atts['fullwidth'] === '1' ? ' tix-fullwidth' : ''; ?>" data-event="<?php echo $post_id; ?>"<?php if ($tix_v === 2): ?> style="--tix-btn1-bg:var(--tix-btn2-bg,transparent);--tix-btn1-color:var(--tix-btn2-color,inherit);--tix-btn1-hover-bg:var(--tix-btn2-hover-bg,transparent);--tix-btn1-hover-color:var(--tix-btn2-hover-color,inherit);--tix-btn1-radius:var(--tix-btn2-radius,8px);--tix-btn1-border:var(--tix-btn2-border,1px solid currentColor);--tix-btn1-font-size:var(--tix-btn2-font-size,0.9rem)"<?php endif; ?>>

            <!-- Header -->
            <?php
            $hdr_style = '';
            if ($header_bg)    $hdr_style .= '--tix-raffle-header-bg:' . esc_attr($header_bg) . ';';
            if ($header_color) $hdr_style .= '--tix-raffle-header-color:' . esc_attr($header_color) . ';';
            ?>
            <div class="tix-raffle-header"<?php if ($hdr_style): ?> style="<?php echo $hdr_style; ?>"<?php endif; ?>>
                <h4 class="tix-raffle-title"><?php echo esc_html($title); ?></h4>
                <?php if ($desc): ?>
                    <div class="tix-raffle-desc"><?php echo wp_kses_post(wpautop($desc)); ?></div>
                <?php endif; ?>
            </div>

            <!-- Preise -->
            <div class="tix-raffle-prizes">
                <h4 class="tix-raffle-prizes-title">Preise</h4>
                <ul class="tix-raffle-prizes-list">
                    <?php foreach ($prizes as $p):
                        $pw = intval($p['per_winner'] ?? 1);
                        $qty_label = intval($p['qty']);
                        if ($pw > 1) {
                            $qty_label .= ' &times; ' . $pw;
                        } else {
                            $qty_label .= '&times;';
                        }
                    ?>
                        <li>
                            <span class="tix-raffle-prize-qty"><?php echo $qty_label; ?></span>
                            <span class="tix-raffle-prize-name"><?php echo esc_html($p['name']); ?></span>
                            <?php if (($p['type'] ?? 'text') === 'ticket'): ?>
                                <span class="tix-raffle-prize-badge">Freikarte</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($status === 'drawn' && !empty($winners) && is_array($winners)): ?>
                <!-- Gewinner -->
                <?php self::render_winners($winners, $prizes); ?>

            <?php elseif ($status === 'open'): ?>
                <!-- Countdown -->
                <?php if ($end_date): ?>
                    <div class="tix-raffle-countdown" data-end="<?php echo esc_attr($end_date); ?>">
                        Teilnahmeschluss in <span class="tix-raffle-timer"></span>
                    </div>
                <?php endif; ?>

                <!-- Formular -->
                <?php
                $prefill_name  = '';
                $prefill_email = '';
                if (is_user_logged_in()) {
                    $cu = wp_get_current_user();
                    $prefill_name  = trim($cu->first_name . ' ' . $cu->last_name) ?: $cu->display_name;
                    $prefill_email = $cu->user_email;
                }
                ?>
                <form class="tix-raffle-form" data-event="<?php echo $post_id; ?>" data-nonce="<?php echo $nonce; ?>">
                    <div class="tix-raffle-form-fields">
                        <input type="text" name="name" placeholder="Dein Name" required autocomplete="name" value="<?php echo esc_attr($prefill_name); ?>">
                        <input type="email" name="email" placeholder="Deine E-Mail" required autocomplete="email" value="<?php echo esc_attr($prefill_email); ?>">
                    </div>
                    <?php if (!empty($consent_text)): ?>
                    <label class="tix-raffle-consent">
                        <input type="checkbox" name="consent" required>
                        <span><?php echo wp_kses_post($consent_text); ?></span>
                    </label>
                    <?php endif; ?>
                    <button type="submit" class="tix-raffle-submit">Jetzt teilnehmen</button>
                    <div class="tix-raffle-msg" hidden></div>
                </form>

                <!-- Teilnehmer-Zähler -->
                <?php if (!$hide_count): ?>
                <p class="tix-raffle-count">
                    <?php echo $entry_count; ?> Teilnehmer
                    <?php if ($max > 0): ?>
                        <span class="tix-raffle-max"> / max. <?php echo $max; ?></span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>

            <?php elseif ($status === 'closed'): ?>
                <div class="tix-raffle-closed">
                    <p>Die Teilnahme ist beendet. Die Auslosung steht noch aus.</p>
                    <?php if (!$hide_count): ?>
                        <p class="tix-raffle-count"><?php echo $entry_count; ?> Teilnehmer</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Gewinnerliste rendern (nur Vornamen für Datenschutz).
     */
    private static function render_winners($winners, $prizes) {
        ?>
        <div class="tix-raffle-winners">
            <h4 class="tix-raffle-winners-title">Gewinner</h4>
            <div class="tix-raffle-winners-list">
                <?php foreach ($winners as $w): ?>
                    <div class="tix-raffle-winner">
                        <span class="tix-raffle-winner-name">
                            <?php
                            // Nur Vorname + erster Buchstabe des Nachnamens
                            $parts = explode(' ', $w['name']);
                            $display = $parts[0];
                            if (count($parts) > 1) $display .= ' ' . mb_substr(end($parts), 0, 1) . '.';
                            echo esc_html($display);
                            ?>
                        </span>
                        <?php if (isset($w['prize_index']) && isset($prizes[$w['prize_index']])): ?>
                            <span class="tix-raffle-winner-prize">
                                <?php
                                $pw = intval($w['per_winner'] ?? $prizes[$w['prize_index']]['per_winner'] ?? 1);
                                $pname = esc_html($prizes[$w['prize_index']]['name']);
                                echo $pw > 1 ? "{$pw}× {$pname}" : $pname;
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ════════════════════════════════════
       AJAX: TEILNAHME
       ════════════════════════════════════ */

    public static function ajax_enter() {
        $event_id = intval($_POST['event_id'] ?? 0);
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tix_raffle_' . $event_id)) {
            wp_send_json_error(['message' => 'Sicherheits-Check fehlgeschlagen. Bitte Seite neu laden.']);
        }
        $r = self::enter($event_id, $_POST['name'] ?? '', $_POST['email'] ?? '', !empty($_POST['consent']));
        if (!$r['ok']) wp_send_json_error(['message' => $r['message']]);
        wp_send_json_success(['message' => $r['message'], 'count' => $r['count']]);
    }

    /**
     * Status des Gewinnspiels: open | closed | drawn (nur 'drawn' kommt aus der DB, Rest dynamisch).
     */
    public static function status($event_id) {
        if ((get_post_meta($event_id, '_tix_raffle_status', true) ?: 'open') === 'drawn') return 'drawn';
        $end_date    = get_post_meta($event_id, '_tix_raffle_end_date', true);
        $max         = intval(get_post_meta($event_id, '_tix_raffle_max_entries', true));
        $end_passed  = $end_date && strtotime($end_date) <= current_time('timestamp');
        $max_reached = $max > 0 && self::count_entries($event_id) >= $max;
        return ($end_passed || $max_reached) ? 'closed' : 'open';
    }

    /**
     * Teilnahme eintragen (Web per admin-ajax, Apps per REST). Rate-Limit 5/Minute je IP.
     * @return array{ok:bool,message:string,count:int}
     */
    public static function enter($event_id, $name, $email, $consent) {
        $event_id = intval($event_id);
        $name     = sanitize_text_field((string) $name);
        $email    = sanitize_email((string) $email);
        $fail = function ($m) { return ['ok' => false, 'message' => $m, 'count' => 0]; };

        if (!$event_id || !$name || !$email || !is_email($email)) return $fail('Bitte Name und gültige E-Mail angeben.');
        $consent_text = get_post_meta($event_id, '_tix_raffle_consent_text', true);
        if (!empty($consent_text) && !$consent) return $fail('Bitte stimme den Teilnahmebedingungen zu.');
        if (get_post_meta($event_id, '_tix_raffle_enabled', true) !== '1' || !self::globally_on()) return $fail('Kein aktives Gewinnspiel.');

        self::ensure_table();
        $status = self::status($event_id);
        if ($status === 'drawn') return $fail('Die Auslosung hat bereits stattgefunden.');
        if ($status === 'closed') {
            $max = intval(get_post_meta($event_id, '_tix_raffle_max_entries', true));
            return $fail($max > 0 && self::count_entries($event_id) >= $max
                ? 'Die maximale Teilnehmerzahl ist erreicht.'
                : 'Die Teilnahme ist leider beendet.');
        }

        // Rate-Limiting: Max 5 Teilnahmen pro IP/Minute
        $ip = self::get_ip();
        $rate_key = 'tix_raffle_rate_' . md5($ip);
        $attempts = intval(get_transient($rate_key));
        if ($attempts >= 5) return $fail('Zu viele Versuche. Bitte warte kurz.');
        set_transient($rate_key, $attempts + 1, 60);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $result = $wpdb->insert($table, [
            'event_id'   => $event_id,
            'name'       => $name,
            'email'      => $email,
            'ip'         => $ip,
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s']);

        if ($result === false) {
            // Duplicate entry (UNIQUE constraint)
            if (strpos($wpdb->last_error, 'Duplicate') !== false) return $fail('Du nimmst bereits an diesem Gewinnspiel teil.');
            return $fail('Fehler bei der Teilnahme. Bitte versuche es erneut.');
        }
        return ['ok' => true, 'message' => 'Du nimmst jetzt teil! Viel Glück!', 'count' => self::count_entries($event_id)];
    }

    /**
     * Öffentliche Gewinnspiel-Daten für Apps/Vorlagen (ohne E-Mails); null, wenn kein Gewinnspiel.
     */
    /** Schalter „Gewinnspiele“ für die ganze Seite (TIX_Features, 1.38.498); Event-Einstellungen bleiben erhalten. */
    public static function globally_on() {
        return !class_exists('TIX_Features') || TIX_Features::on('raffles');
    }

    /** Kurzform für Event-Listen: {title, status open|closed|drawn, end_date} oder null (ohne Teilnehmerliste). */
    public static function list_info($event_id) {
        if (get_post_meta($event_id, '_tix_raffle_enabled', true) !== '1' || !self::globally_on()) return null;
        $prizes = get_post_meta($event_id, '_tix_raffle_prizes', true);
        if (!is_array($prizes) || empty($prizes)) return null;
        self::ensure_table();
        $end = (string) get_post_meta($event_id, '_tix_raffle_end_date', true);
        return [
            'title'    => (string) (get_post_meta($event_id, '_tix_raffle_title', true) ?: 'Gewinnspiel'),
            'status'   => self::status($event_id),
            'end_date' => $end !== '' ? wp_date('c', strtotime(get_gmt_from_date(str_replace('T', ' ', $end)) . ' UTC')) : '',
        ];
    }

    public static function public_info($event_id) {
        if (get_post_meta($event_id, '_tix_raffle_enabled', true) !== '1' || !self::globally_on()) return null;
        $prizes = get_post_meta($event_id, '_tix_raffle_prizes', true);
        if (!is_array($prizes) || empty($prizes)) return null;
        self::ensure_table();
        $status = self::status($event_id);
        $hide   = get_post_meta($event_id, '_tix_raffle_hide_count', true) === '1';
        $winners = [];
        if ($status === 'drawn') {
            foreach ((array) get_post_meta($event_id, '_tix_raffle_winners', true) as $w) {
                // nur Vorname + Initiale, keine E-Mail
                $parts = preg_split('/\s+/u', trim((string) ($w['name'] ?? '')));
                $short = ($parts[0] ?? '') . (isset($parts[1]) ? ' ' . mb_substr($parts[1], 0, 1) . '.' : '');
                $winners[] = ['name' => $short, 'prize' => (string) ($w['prize_name'] ?? '')];
            }
        }
        $end = (string) get_post_meta($event_id, '_tix_raffle_end_date', true);
        return [
            'title'        => (string) (get_post_meta($event_id, '_tix_raffle_title', true) ?: 'Gewinnspiel'),
            'description'  => wp_kses_post(wpautop((string) get_post_meta($event_id, '_tix_raffle_description', true))),
            'status'       => $status,
            'end_date'     => $end !== '' ? wp_date('c', strtotime(get_gmt_from_date(str_replace('T', ' ', $end)) . ' UTC')) : '',
            'entries'      => $hide ? null : self::count_entries($event_id),
            'max_entries'  => intval(get_post_meta($event_id, '_tix_raffle_max_entries', true)),
            'consent_text' => (string) get_post_meta($event_id, '_tix_raffle_consent_text', true),
            'prizes'       => array_values(array_map(function ($p) {
                return [
                    'name'       => (string) ($p['name'] ?? ''),
                    'qty'        => intval($p['qty'] ?? 1),
                    'per_winner' => max(1, intval($p['per_winner'] ?? 1)),
                    'free_ticket'=> ($p['type'] ?? 'text') === 'ticket',
                ];
            }, $prizes)),
            'winners'      => $winners,
        ];
    }

    /* ════════════════════════════════════
       AJAX: MANUELL AUSLOSEN (Admin)
       ════════════════════════════════════ */

    public static function ajax_draw() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        check_ajax_referer('tix_raffle_admin', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) {
            wp_send_json_error(['message' => 'Ungültige Event-ID.']);
        }

        $result = self::draw_winners($event_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => count($result) . ' Gewinner ausgelost!',
            'winners' => $result,
        ]);
    }

    /* ════════════════════════════════════
       AJAX: TEILNEHMER FUER ANIMATION
       ════════════════════════════════════ */

    public static function ajax_get_participants() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        check_ajax_referer('tix_raffle_admin', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) {
            wp_send_json_error(['message' => 'Ungültige Event-ID.']);
        }

        self::ensure_table();
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Nur Vornamen – keine E-Mails fuer Animation
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT name FROM {$table} WHERE event_id = %d ORDER BY RAND()",
            $event_id
        ));

        $total = count($names);

        // Max 200 fuer den Scroll-Pool (Performance)
        if ($total > 200) {
            $names = array_slice($names, 0, 200);
        }

        $prizes = get_post_meta($event_id, '_tix_raffle_prizes', true);
        if (!is_array($prizes)) $prizes = [];

        $event = get_post($event_id);

        wp_send_json_success([
            'names'      => $names,
            'total'      => $total,
            'prizes'     => array_map(function($p) {
                return [
                    'name'       => $p['name'] ?? '',
                    'qty'        => intval($p['qty'] ?? 1),
                    'per_winner' => intval($p['per_winner'] ?? 1),
                    'type'       => $p['type'] ?? 'text',
                ];
            }, $prizes),
            'eventTitle' => $event ? $event->post_title : '',
        ]);
    }

    /* ════════════════════════════════════
       AJAX: STATUS ZURÜCKSETZEN (Admin)
       ════════════════════════════════════ */

    public static function ajax_reset_draw() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        check_ajax_referer('tix_raffle_admin', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) {
            wp_send_json_error(['message' => 'Ungültige Event-ID.']);
        }

        $r = self::reset_draw($event_id); // Freikarten werden storniert, eingecheckte verhindern das Zurücksetzen (1.38.489)
        if (is_wp_error($r)) wp_send_json_error(['message' => $r->get_error_message()]);

        wp_send_json_success(['message' => 'Auslosung zurückgesetzt. Kann erneut ausgelost werden.']);
    }

    /* ════════════════════════════════════
       AUSLOSUNG
       ════════════════════════════════════ */

    /**
     * Gewinner ziehen.
     *
     * @param int $event_id
     * @return array|WP_Error Gewinner-Array oder Fehler
     */
    public static function draw_winners($event_id, $notify = true) {
        self::ensure_table();
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $status = get_post_meta($event_id, '_tix_raffle_status', true) ?: 'open';
        if ($status === 'drawn') {
            return new \WP_Error('already_drawn', 'Gewinner wurden bereits ausgelost.');
        }

        $prizes = get_post_meta($event_id, '_tix_raffle_prizes', true);
        if (!is_array($prizes) || empty($prizes)) {
            return new \WP_Error('no_prizes', 'Keine Preise konfiguriert.');
        }

        // Alle Einträge in zufälliger Reihenfolge laden
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, email FROM {$table} WHERE event_id = %d ORDER BY RAND()",
            $event_id
        ));

        if (empty($entries)) {
            return new \WP_Error('no_entries', 'Keine Teilnehmer vorhanden.');
        }

        $winners       = [];
        $used_entry_ids = [];

        foreach ($prizes as $prize_index => $prize) {
            $qty = intval($prize['qty'] ?? 1);

            for ($i = 0; $i < $qty; $i++) {
                // Nächsten ungenutzten Eintrag nehmen
                $entry = null;
                foreach ($entries as $e) {
                    if (!in_array($e->id, $used_entry_ids)) {
                        $entry = $e;
                        break;
                    }
                }
                if (!$entry) break; // Nicht genug Teilnehmer

                $used_entry_ids[] = $entry->id;

                // DB markieren
                $wpdb->update($table, [
                    'is_winner'   => 1,
                    'prize_index' => $prize_index,
                ], ['id' => $entry->id], ['%d', '%d'], ['%d']);

                $per_winner = max(1, intval($prize['per_winner'] ?? 1));

                $winner_data = [
                    'entry_id'    => $entry->id,
                    'name'        => $entry->name,
                    'email'       => $entry->email,
                    'prize_index' => $prize_index,
                    'prize_name'  => $prize['name'],
                    'per_winner'  => $per_winner,
                ];

                // Freikarte(n) erstellen wenn Typ = ticket
                if (($prize['type'] ?? 'text') === 'ticket') {
                    $ticket_ids = [];
                    for ($t = 0; $t < $per_winner; $t++) {
                        $ticket_id = self::create_prize_ticket($event_id, $entry, $prize);
                        if ($ticket_id) $ticket_ids[] = $ticket_id;
                    }
                    if (!empty($ticket_ids)) {
                        $winner_data['ticket_id']  = $ticket_ids[0]; // Abwärtskompatibel
                        $winner_data['ticket_ids'] = $ticket_ids;
                    }
                }

                $winners[] = $winner_data;
            }
        }

        // Status updaten
        update_post_meta($event_id, '_tix_raffle_status', 'drawn');
        update_post_meta($event_id, '_tix_raffle_winners', $winners);
        update_post_meta($event_id, '_tix_raffle_drawn_at', current_time('mysql'));

        // Gewinner benachrichtigen – abschaltbar (Studio: auslosen ohne Mail, später über notify(), 1.38.489)
        if ($notify) self::notify($event_id);

        return (array) get_post_meta($event_id, '_tix_raffle_winners', true);
    }

    /**
     * Freikarte für Gewinner erstellen.
     */
    private static function create_prize_ticket($event_id, $entry, $prize) {
        $cat_index = intval($prize['cat_index'] ?? 0);

        // Ticket-Code generieren
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code  = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $chars[wp_rand(0, strlen($chars) - 1)];
        }

        $ticket_id = wp_insert_post([
            'post_type'   => 'tix_ticket',
            'post_title'  => $code,
            'post_status' => 'publish',
        ]);

        if (is_wp_error($ticket_id) || !$ticket_id) return false;

        update_post_meta($ticket_id, '_tix_ticket_code',           $code);
        update_post_meta($ticket_id, '_tix_ticket_event_id',       $event_id);
        update_post_meta($ticket_id, '_tix_ticket_order_id',       0);
        update_post_meta($ticket_id, '_tix_ticket_cat_index',      $cat_index);
        update_post_meta($ticket_id, '_tix_ticket_status',         'valid');
        update_post_meta($ticket_id, '_tix_ticket_owner_email',    $entry->email);
        update_post_meta($ticket_id, '_tix_ticket_owner_name',     $entry->name);
        update_post_meta($ticket_id, '_tix_ticket_price',          0);
        update_post_meta($ticket_id, '_tix_ticket_source',         'raffle');

        // Download-Token
        $dl_token = bin2hex(random_bytes(32));
        update_post_meta($ticket_id, '_tix_ticket_download_token', $dl_token);

        return $ticket_id;
    }

    /**
     * Gewinner-E-Mail senden (seit 1.38.489 im Mail-Layout der Seite). @return bool
     */
    private static function send_winner_email($event_id, $winner) {
        $event = get_post($event_id);
        if (!$event || !is_email((string) ($winner['email'] ?? ''))) return false;
        $first_name = explode(' ', trim((string) $winner['name']))[0];
        $per_winner = intval($winner['per_winner'] ?? 1);
        $prize_label = (string) $winner['prize_name'] . ($per_winner > 1 ? " ({$per_winner}×)" : '');
        $title = html_entity_decode(get_the_title($event_id), ENT_QUOTES, 'UTF-8');
        $subject = 'Herzlichen Glückwunsch! Du hast gewonnen!';
        $ticket_ids = !empty($winner['ticket_ids']) ? $winner['ticket_ids'] : (!empty($winner['ticket_id']) ? [$winner['ticket_id']] : []);
        $links = [];
        foreach ($ticket_ids as $tid) {
            $dl_token = get_post_meta($tid, '_tix_ticket_download_token', true);
            if ($dl_token) $links[] = add_query_arg('tix_dl', $dl_token, home_url('/'));
        }
        if (class_exists('TIX_Mail_Layout')) {
            $blocks = [
                ['type' => 'text', 'html' => 'Hallo ' . esc_html($first_name) . ',<br>du hast beim Gewinnspiel für <strong>' . esc_html($title) . '</strong> gewonnen!'],
                ['type' => 'info', 'rows' => [['Dein Preis', esc_html($prize_label)]]],
            ];
            foreach ($links as $i => $u) {
                $blocks[] = ['type' => 'button', 'label' => count($links) > 1 ? 'Ticket ' . ($i + 1) . ' herunterladen' : 'Ticket herunterladen', 'url' => class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to($u) : $u];
            }
            $blocks[] = ['type' => 'text', 'html' => 'Viel Spaß bei der Veranstaltung!'];
            return (bool) TIX_Mail_Layout::send($winner['email'], $subject, ['title' => 'Du hast gewonnen!', 'kind' => 'customer', 'blocks' => $blocks], ['customer' => true]);
        }
        $body = "Hallo {$first_name},\n\ndu hast beim Gewinnspiel für \"{$title}\" gewonnen!\n\nDein Preis: {$prize_label}\n\n";
        if ($links) $body .= (count($links) > 1 ? "Deine Tickets:\n" : "Dein Ticket:\n") . implode("\n", $links) . "\n\n";
        $body .= "Viel Spaß bei der Veranstaltung!";
        return (bool) TIX_Customer_URL::mail($winner['email'], $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
    }

    /**
     * Gewinner benachrichtigen (alle oder einen, $entry_id) und notified_at setzen (1.38.489). @return int gesendet
     */
    public static function notify($event_id, $entry_id = 0) {
        $winners = get_post_meta($event_id, '_tix_raffle_winners', true);
        if (!is_array($winners)) return 0;
        $sent = 0;
        foreach ($winners as $i => $w) {
            if ($entry_id && intval($w['entry_id'] ?? 0) !== intval($entry_id)) continue;
            if (self::send_winner_email($event_id, $w)) {
                $winners[$i]['notified_at'] = current_time('mysql');
                $sent++;
            }
        }
        update_post_meta($event_id, '_tix_raffle_winners', $winners);
        return $sent;
    }

    /** Freikarten-IDs aller Gewinner. */
    public static function prize_ticket_ids($event_id) {
        $ids = [];
        foreach ((array) get_post_meta($event_id, '_tix_raffle_winners', true) as $w) {
            foreach ((array) ($w['ticket_ids'] ?? (!empty($w['ticket_id']) ? [$w['ticket_id']] : [])) as $tid) $ids[] = intval($tid);
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Auslosung zurücksetzen (1.38.489): nicht mehr, sobald eine gewonnene Freikarte eingecheckt ist; sonst Freikarten
     * stornieren, Gewinner-Markierung und Status zurück. @return true|WP_Error
     */
    public static function reset_draw($event_id) {
        $tickets = self::prize_ticket_ids($event_id);
        foreach ($tickets as $tid) {
            if (get_post_meta($tid, '_tix_ticket_checked_in', true) || get_post_meta($tid, '_tix_ticket_status', true) === 'used') {
                return new \WP_Error('tix_raffle_checked_in', 'Zurücksetzen geht nicht mehr: Eine gewonnene Freikarte wurde schon eingecheckt.');
            }
        }
        foreach ($tickets as $tid) update_post_meta($tid, '_tix_ticket_status', 'cancelled');
        self::ensure_table();
        global $wpdb;
        $wpdb->update($wpdb->prefix . self::TABLE, ['is_winner' => 0, 'prize_index' => null], ['event_id' => intval($event_id)], ['%d', '%s'], ['%d']);
        update_post_meta($event_id, '_tix_raffle_status', 'open');
        delete_post_meta($event_id, '_tix_raffle_winners');
        delete_post_meta($event_id, '_tix_raffle_drawn_at');
        return true;
    }

    /** Automatische Auslosung am Teilnahmeschluss an? (Vorgabe an, `_tix_raffle_auto_draw` = '0' schaltet ab, 1.38.489) */
    public static function auto_draw_on($event_id) {
        return get_post_meta($event_id, '_tix_raffle_auto_draw', true) !== '0';
    }

    /* ════════════════════════════════════
       CRON: AUTOMATISCHE AUSLOSUNG
       ════════════════════════════════════ */

    public static function cron_auto_draw() {
        $now = current_time('Y-m-d H:i');

        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_raffle_enabled', 'value' => '1'],
                ['key' => '_tix_raffle_status',  'value' => 'open'],
                [
                    'key'     => '_tix_raffle_end_date',
                    'value'   => $now,
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ],
            ],
        ]);

        foreach ($events as $event) {
            if (!self::auto_draw_on($event->ID)) continue; // je Gewinnspiel abschaltbar (1.38.489)
            // Status schliessen
            update_post_meta($event->ID, '_tix_raffle_status', 'closed');
            // Auslosen
            self::draw_winners($event->ID);
        }
    }

    /* ════════════════════════════════════
       HILFSFUNKTIONEN
       ════════════════════════════════════ */

    /**
     * Einträge für ein Event zählen.
     */
    public static function count_entries($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %d",
            $event_id
        ));
    }

    /**
     * Alle Einträge für ein Event laden.
     */
    public static function get_entries($event_id, $limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at DESC LIMIT %d",
            $event_id,
            $limit
        ));
    }

    /**
     * IP-Adresse ermitteln.
     */
    private static function get_ip() {
        return TIX_Client_IP::get();
    }
}
