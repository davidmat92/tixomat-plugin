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
        if (get_post_meta($post_id, '_tix_raffle_enabled', true) !== '1') return '';

        self::ensure_table();
        self::enqueue();

        $title    = get_post_meta($post_id, '_tix_raffle_title', true) ?: 'Gewinnspiel';
        $desc     = get_post_meta($post_id, '_tix_raffle_description', true);
        $end_date = get_post_meta($post_id, '_tix_raffle_end_date', true);
        $max      = intval(get_post_meta($post_id, '_tix_raffle_max_entries', true));
        $db_status  = get_post_meta($post_id, '_tix_raffle_status', true) ?: 'open';
        $prizes     = get_post_meta($post_id, '_tix_raffle_prizes', true);
        $winners    = get_post_meta($post_id, '_tix_raffle_winners', true);
        $hide_count = get_post_meta($post_id, '_tix_raffle_hide_count', true) === '1';

        if (!is_array($prizes) || empty($prizes)) return '';

        // Aktuelle Einträge zählen
        $entry_count = self::count_entries($post_id);

        // Status komplett dynamisch bestimmen — nur 'drawn' wird aus DB respektiert
        if ($db_status === 'drawn') {
            $status = 'drawn';
            $close_reason = 'drawn';
        } else {
            $end_passed  = $end_date && strtotime($end_date) <= current_time('timestamp');
            $max_reached = $max > 0 && $entry_count >= $max;
            if ($end_passed) {
                $status = 'closed';
                $close_reason = 'end_date_passed:' . $end_date . '|now:' . current_time('Y-m-d H:i:s');
            } elseif ($max_reached) {
                $status = 'closed';
                $close_reason = 'max_reached:' . $entry_count . '/' . $max;
            } else {
                $status = 'open';
                $close_reason = '';
            }
        }

        // Nonce
        $nonce = wp_create_nonce('tix_raffle_' . $post_id);

        ob_start();
        ?>
        <?php $tix_v = intval($atts['variant']) === 2 ? 2 : 1; ?>
        <!-- tix-raffle-debug: db_status=<?php echo $db_status; ?> | computed=<?php echo $status; ?> | end_date=<?php echo $end_date ?: 'NONE'; ?> | max=<?php echo $max; ?> | entries=<?php echo $entry_count; ?> | reason=<?php echo $close_reason; ?> -->
        <div class="tix-raffle<?php echo $atts['fullwidth'] === '1' ? ' tix-fullwidth' : ''; ?>" data-event="<?php echo $post_id; ?>"<?php if ($tix_v === 2): ?> style="--tix-btn1-bg:var(--tix-btn2-bg,transparent);--tix-btn1-color:var(--tix-btn2-color,inherit);--tix-btn1-hover-bg:var(--tix-btn2-hover-bg,transparent);--tix-btn1-hover-color:var(--tix-btn2-hover-color,inherit);--tix-btn1-radius:var(--tix-btn2-radius,8px);--tix-btn1-border:var(--tix-btn2-border,1px solid currentColor);--tix-btn1-font-size:var(--tix-btn2-font-size,0.9rem)"<?php endif; ?>>

            <!-- Header -->
            <div class="tix-raffle-header">
                <h2 class="tix-raffle-title"><?php echo esc_html($title); ?></h2>
                <?php if ($desc): ?>
                    <div class="tix-raffle-desc"><?php echo wp_kses_post(wpautop($desc)); ?></div>
                <?php endif; ?>
            </div>

            <!-- Preise -->
            <div class="tix-raffle-prizes">
                <h3 class="tix-raffle-prizes-title">Preise</h3>
                <ul class="tix-raffle-prizes-list">
                    <?php foreach ($prizes as $p): ?>
                        <li>
                            <span class="tix-raffle-prize-qty"><?php echo intval($p['qty']); ?>&times;</span>
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
                <form class="tix-raffle-form" data-event="<?php echo $post_id; ?>" data-nonce="<?php echo $nonce; ?>">
                    <div class="tix-raffle-form-fields">
                        <input type="text" name="name" placeholder="Dein Name" required autocomplete="name">
                        <input type="email" name="email" placeholder="Deine E-Mail" required autocomplete="email">
                    </div>
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
            <h3 class="tix-raffle-winners-title">Gewinner</h3>
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
                                <?php echo esc_html($prizes[$w['prize_index']]['name']); ?>
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
        $name     = sanitize_text_field($_POST['name'] ?? '');
        $email    = sanitize_email($_POST['email'] ?? '');
        $nonce    = $_POST['nonce'] ?? '';

        // Validierung
        if (!wp_verify_nonce($nonce, 'tix_raffle_' . $event_id)) {
            wp_send_json_error(['message' => 'Sicherheits-Check fehlgeschlagen. Bitte Seite neu laden.']);
        }

        if (!$event_id || !$name || !$email || !is_email($email)) {
            wp_send_json_error(['message' => 'Bitte Name und gültige E-Mail angeben.']);
        }

        // Gewinnspiel aktiv?
        if (get_post_meta($event_id, '_tix_raffle_enabled', true) !== '1') {
            wp_send_json_error(['message' => 'Kein aktives Gewinnspiel.']);
        }

        $status   = get_post_meta($event_id, '_tix_raffle_status', true) ?: 'open';
        $end_date = get_post_meta($event_id, '_tix_raffle_end_date', true);
        $max      = intval(get_post_meta($event_id, '_tix_raffle_max_entries', true));

        // Status dynamisch prüfen
        $end_passed  = $end_date && strtotime($end_date) <= current_time('timestamp');
        $max_reached = $max > 0 && self::count_entries($event_id) >= $max;

        if ($status === 'drawn') {
            wp_send_json_error(['message' => 'Die Auslosung hat bereits stattgefunden.']);
        }

        if ($end_passed) {
            wp_send_json_error(['message' => 'Die Teilnahme ist leider beendet.']);
        }

        if ($max_reached) {
            wp_send_json_error(['message' => 'Die maximale Teilnehmerzahl ist erreicht.']);
        }

        // Rate-Limiting: Max 5 Teilnahmen pro IP/Minute
        $ip = self::get_ip();
        $rate_key = 'tix_raffle_rate_' . md5($ip);
        $attempts = intval(get_transient($rate_key));
        if ($attempts >= 5) {
            wp_send_json_error(['message' => 'Zu viele Versuche. Bitte warte kurz.']);
        }
        set_transient($rate_key, $attempts + 1, 60);

        // Eintrag speichern
        self::ensure_table();
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
            if (strpos($wpdb->last_error, 'Duplicate') !== false) {
                wp_send_json_error(['message' => 'Du nimmst bereits an diesem Gewinnspiel teil.']);
            }
            wp_send_json_error(['message' => 'Fehler bei der Teilnahme. Bitte versuche es erneut.']);
        }

        wp_send_json_success([
            'message' => 'Du nimmst jetzt teil! Viel Glück!',
            'count'   => self::count_entries($event_id),
        ]);
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
       AUSLOSUNG
       ════════════════════════════════════ */

    /**
     * Gewinner ziehen.
     *
     * @param int $event_id
     * @return array|WP_Error Gewinner-Array oder Fehler
     */
    public static function draw_winners($event_id) {
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

                $winner_data = [
                    'entry_id'    => $entry->id,
                    'name'        => $entry->name,
                    'email'       => $entry->email,
                    'prize_index' => $prize_index,
                    'prize_name'  => $prize['name'],
                ];

                // Freikarte erstellen wenn Typ = ticket
                if (($prize['type'] ?? 'text') === 'ticket') {
                    $ticket_id = self::create_prize_ticket($event_id, $entry, $prize);
                    if ($ticket_id) {
                        $winner_data['ticket_id'] = $ticket_id;
                    }
                }

                $winners[] = $winner_data;
            }
        }

        // Status updaten
        update_post_meta($event_id, '_tix_raffle_status', 'drawn');
        update_post_meta($event_id, '_tix_raffle_winners', $winners);
        update_post_meta($event_id, '_tix_raffle_drawn_at', current_time('mysql'));

        // Gewinner benachrichtigen
        foreach ($winners as $w) {
            self::send_winner_email($event_id, $w);
        }

        return $winners;
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
     * Gewinner-E-Mail senden.
     */
    private static function send_winner_email($event_id, $winner) {
        $event = get_post($event_id);
        if (!$event) return;

        $s          = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $brand_name = $s['email_brand_name'] ?? get_bloginfo('name');
        $first_name = explode(' ', $winner['name'])[0];

        $subject = 'Herzlichen Glückwunsch! Du hast gewonnen!';
        $body    = "Hallo {$first_name},\n\n";
        $body   .= "du hast beim Gewinnspiel für \"{$event->post_title}\" gewonnen!\n\n";
        $body   .= "Dein Preis: {$winner['prize_name']}\n\n";

        // Wenn ein Ticket erstellt wurde, Download-Link einfügen
        if (!empty($winner['ticket_id'])) {
            $dl_token = get_post_meta($winner['ticket_id'], '_tix_ticket_download_token', true);
            if ($dl_token) {
                $dl_url = add_query_arg('tix_dl', $dl_token, home_url('/'));
                $body .= "Dein Ticket kannst du hier herunterladen:\n{$dl_url}\n\n";
            }
        }

        $body .= "Viel Spaß bei der Veranstaltung!\n\n";
        $body .= "Dein {$brand_name}-Team";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if (!empty($s['email_from_address'])) {
            $from_name = $brand_name;
            $headers[] = "From: {$from_name} <{$s['email_from_address']}>";
        }

        wp_mail($winner['email'], $subject, $body, $headers);
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
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '';
    }
}
