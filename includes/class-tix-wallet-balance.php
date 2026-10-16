<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Wallet_Balance – Guthaben (Phase 1: Kern), 1.38.432, alle Seiten.
 *
 * Eigener Klassenname, weil `TIX_Wallet` (class-tix-wallet.php) schon die Apple-/Google-Wallet-Pässe der Tickets ist.
 *
 * Plan: tixomat-apps/docs/plan-bezahlen-vor-ort.md, Auftrag uebergaben/auftrag-guthaben-phase1-plugin.md.
 * Geschlossener Kreis: Guthaben gilt nur dort, wo es aufgeladen wurde (Seite bzw. evendis-Veranstalter).
 *
 * Tabellen (dbDelta, Option `tix_wallet_db`):
 *   {prefix}tix_wallets         Geldbörse je Konto und Bereich (user_id, organizer_id eindeutig), Saldo in Cent getrennt
 *                               nach bezahltem Anteil und Bonus, Status active|locked|closed
 *   {prefix}tix_wallet_tx       Buchungsbuch – NUR anfügen, nie ändern oder löschen
 *   {prefix}tix_wallet_devices  Phase 2 (Geräte-Schlüssel), Tabelle schon da
 *   {prefix}tix_wallet_nonces   Phase 2 (verbrauchte QR-Codes), Tabelle schon da
 *
 * Einzige Stelle, die Salden ändert: post_tx() – Transaktion, Zeile sperren (FOR UPDATE), Idempotenz je Geldbörse,
 * Saldo per UPDATE … WHERE saldo + x >= 0, danach Buchungszeile mit Saldo danach. Abbuchen: Reihenfolge nach
 * Option `_tix_wallet_spend_order` (paid_first | bonus_first, Standard paid_first – Steuerberater bestätigt noch).
 *
 * Aufladen: verstecktes System-Event „Guthaben“ je Seite (bzw. später je Veranstalter) mit Kategorien
 * `wallet_topup` (fester Betrag bzw. Wunschbetrag), App-Kasse wie Geschenkgutscheine. Nur mit Konto.
 * Gutschrift bei tix_order_completed (topup + bonus, Idempotenz = Bestellposition), Gegenbuchung bei Storno,
 * Erstattung und Rückbuchung (Mollie-Chargeback); was nicht mehr da ist, sperrt die Geldbörse.
 *
 * Mehr-Veranstalter-Modus (evendis): Einstellungen je Veranstalter (`_tix_org_wallet`), aktiv nur mit Modul `wallet`
 * und freigegebenem Veranstalter. Aufladen bleibt dort AUS, bis das Geld direkt an den Veranstalter geht (Phase 5).
 *
 * Plattform-Guthaben (1.38.466, User-Entscheid 2026-10-16, Schalter `tix_wallet_platform`, Vorgabe AUS – live erst nach
 * Klärung mit dem Anwalt, E-Geld/ZAG): im Mehr-Veranstalter-Modus EINE Geldbörse je Kunde (organizer_id 0, scope_type
 * `platform`). Aufladen über das evendis-Zahlungskonto (Einstellungen = Bereich 0), mit Seiten-Gutscheinen aufladen,
 * Tickets JEDES Veranstalters damit bezahlen, Cashless bei Veranstaltern mit Modul `wallet` (Buchung trägt
 * `organizer_id`, Abrechnung als offener Posten `cashless` mit der nächsten Event-Abrechnung). Vorerst KEIN Bonus.
 * Aufladen an der Kasse eines Veranstalters geht für das Plattform-Guthaben nicht (das Geld wäre beim Veranstalter).
 */
class TIX_Wallet_Balance {

    const NS        = 'tixomat/v1';
    const DB_VER    = '3'; // 2: Geräte-Bestätigung + Zahlungsvorgänge (Phase 2, TIX_Wallet_Pay); 3: organizer_id an Buchung/Vorgang (Plattform-Cashless)
    const OPT_PLATFORM = 'tix_wallet_platform';   // '1' = Plattform-Guthaben an (nur Mehr-Veranstalter-Modus), Vorgabe aus
    const OPT       = '_tix_wallet';
    const META_ORG  = '_tix_org_wallet';
    const OPT_SPEND = '_tix_wallet_spend_order';
    const OPT_DELETE = '_tix_wallet_delete_policy';   // anonymize (Standard) | block – Entscheidung des Users offen
    const OPT_ALERTS = 'tix_wallet_alerts';
    const CAP       = 'tix_app_wallet_charge';
    const TYPES     = ['topup', 'bonus', 'charge', 'reversal', 'refund', 'adjust', 'expire', 'payout'];
    const CHANNELS  = ['app', 'web', 'pos_cash', 'pos_card', 'sumup', 'admin', 'system', 'giftcard'];
    const PAY_CRON  = 'tix_wallet_pay_timeout';   // offene Bestellungen mit Guthaben-Anteil nach 3 h stornieren
    const PER_PAGE  = 30;

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 9);
        add_action('init', [__CLASS__, 'ensure_caps'], 30);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('tix_order_completed', [__CLASS__, 'on_order_completed'], 25);
        add_action('tix_order_status_changed', [__CLASS__, 'on_status_changed'], 25, 3);
        add_action('tix_order_chargeback', [__CLASS__, 'on_chargeback'], 10, 3);
        add_action('pre_get_posts', [__CLASS__, 'hide_system_event']);
        add_action('delete_user', [__CLASS__, 'on_delete_user']);
        add_action(self::PAY_CRON, [__CLASS__, 'cron_pay_timeout']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Wallet_Balance::PAY_CRON)) wp_schedule_event(time() + 1200, 'hourly', TIX_Wallet_Balance::PAY_CRON);
        }, 27);
        add_action('admin_menu', [__CLASS__, 'menu'], 48);
        add_action('admin_post_tix_wallet_settings', [__CLASS__, 'admin_save_settings']);
        add_action('admin_post_tix_wallet_adjust', [__CLASS__, 'admin_adjust']);
        add_action('admin_post_tix_wallet_status', [__CLASS__, 'admin_status']);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('tix wallet', 'TIX_Wallet_Balance_CLI');
            WP_CLI::add_command('tixomat wallet', 'TIX_Wallet_Balance_CLI');
        }
    }

    // ═══════════════ Tabellen ═══════════════

    public static function t($name) {
        global $wpdb;
        return $wpdb->prefix . 'tix_' . $name;
    }

    public static function ready() {
        return get_option('tix_wallet_db') === self::DB_VER;
    }

    public static function maybe_install() {
        if (self::ready()) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t('wallets') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            scope_type varchar(12) NOT NULL DEFAULT 'site',
            organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            balance_paid bigint(20) NOT NULL DEFAULT 0,
            balance_bonus bigint(20) NOT NULL DEFAULT 0,
            status varchar(10) NOT NULL DEFAULT 'active',
            created datetime NOT NULL,
            updated datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_org (user_id,organizer_id),
            KEY organizer_id (organizer_id)
        ) ENGINE=InnoDB $c;");
        dbDelta("CREATE TABLE " . self::t('wallet_tx') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wallet_id bigint(20) unsigned NOT NULL,
            type varchar(10) NOT NULL,
            amount_paid bigint(20) NOT NULL DEFAULT 0,
            amount_bonus bigint(20) NOT NULL DEFAULT 0,
            balance_after_paid bigint(20) NOT NULL DEFAULT 0,
            balance_after_bonus bigint(20) NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ref_tx_id bigint(20) unsigned NOT NULL DEFAULT 0,
            staff_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            station varchar(60) NOT NULL DEFAULT '',
            device_id bigint(20) unsigned NOT NULL DEFAULT 0,
            channel varchar(12) NOT NULL DEFAULT '',
            idempotency_key varchar(100) DEFAULT NULL,
            meta longtext NULL,
            organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY wallet_idem (wallet_id,idempotency_key),
            KEY wallet_id (wallet_id,id),
            KEY order_id (order_id),
            KEY tx_org (organizer_id),
            KEY ref_tx_id (ref_tx_id),
            KEY created (created)
        ) ENGINE=InnoDB $c;");
        dbDelta("CREATE TABLE " . self::t('wallet_devices') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            key_id varchar(64) NOT NULL,
            public_key text NOT NULL,
            name varchar(80) NOT NULL DEFAULT '',
            platform varchar(12) NOT NULL DEFAULT '',
            status varchar(10) NOT NULL DEFAULT 'pending',
            verified_at datetime NULL,
            last_used datetime NULL,
            code_hash varchar(64) NULL,
            code_expires int(10) unsigned NOT NULL DEFAULT 0,
            code_tries tinyint(3) unsigned NOT NULL DEFAULT 0,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY key_id (key_id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB $c;");
        dbDelta("CREATE TABLE " . self::t('wallet_nonces') . " (
            device_id bigint(20) unsigned NOT NULL,
            nonce varchar(32) NOT NULL,
            used_at int(10) unsigned NOT NULL DEFAULT 0,
            wallet_id bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(10) NOT NULL DEFAULT 'charge',
            state varchar(10) NOT NULL DEFAULT 'used',
            amount bigint(20) NOT NULL DEFAULT 0,
            station varchar(60) NOT NULL DEFAULT '',
            staff_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            tx_id bigint(20) unsigned NOT NULL DEFAULT 0,
            idempotency_key varchar(80) NOT NULL DEFAULT '',
            organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (device_id,nonce),
            KEY used_at (used_at),
            KEY staff_idem (staff_user_id,idempotency_key)
        ) ENGINE=InnoDB $c;");
        update_option('tix_wallet_db', self::DB_VER, false);
    }

    /** Recht „Guthaben kassieren“ für Personal und Veranstalter (einmal je Rolle). */
    public static function ensure_caps() {
        foreach (['tix_staff', 'tix_organizer', 'tix_cashier', 'administrator'] as $r) {
            $role = get_role($r);
            if ($role && !$role->has_cap(self::CAP)) $role->add_cap(self::CAP);
        }
    }

    // ═══════════════ Beträge ═══════════════

    public static function cents($euro) {
        return (int) round(floatval(str_replace(',', '.', (string) $euro)) * 100);
    }

    public static function euro($cents) {
        return round(intval($cents) / 100, 2);
    }

    public static function fmt($cents) {
        $s = number_format(abs(intval($cents)) / 100, 2, ',', '.');
        $s = preg_replace('/,00$/', '', $s);
        return (intval($cents) < 0 ? '−' : '') . $s . ' €';
    }

    // ═══════════════ Einstellungen ═══════════════

    public static function defaults() {
        return [
            'enabled'            => false,
            'amounts'            => [20, 50, 100],
            'free_amount'        => true,
            'free_min'           => 5,
            'free_max'           => 250,
            'bonus_tiers'        => [],          // [{from: 50, bonus: 5, type: fixed|percent}]
            'max_balance'        => 250,
            'max_topup'          => 250,
            'new_account_days'   => 7,
            'new_account_max'    => 50,          // Summe der Aufladungen in den ersten Tagen
            'approval_threshold' => 50,          // Face-ID-Freigabe, erst ab Phase 2 aktiv
            'stations'           => ['Bar 1'],
            'refund_policy'      => 'Bezahltes Restguthaben erstatten wir auf Wunsch, Bonus nicht.',
        ];
    }

    /** Rohwerte säubern (Admin, REST, Optionen). */
    public static function sanitize_settings(array $in, array $base = null) {
        $s = $base ?: self::defaults();
        if (array_key_exists('enabled', $in)) $s['enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('free_amount', $in)) $s['free_amount'] = filter_var($in['free_amount'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('amounts', $in)) {
            $list = is_array($in['amounts']) ? $in['amounts'] : preg_split('/[,;\s]+/', (string) $in['amounts']);
            $a = [];
            foreach ($list as $v) { $v = round(floatval(str_replace(',', '.', (string) $v)), 2); if ($v >= 1 && $v <= 1000) $a[] = $v; }
            $a = array_values(array_unique($a)); sort($a);
            $s['amounts'] = array_slice($a, 0, 8);
        }
        foreach (['free_min' => [1, 1000], 'free_max' => [1, 1000], 'max_balance' => [1, 5000], 'max_topup' => [1, 5000], 'new_account_max' => [0, 5000], 'approval_threshold' => [0, 5000]] as $k => $r) {
            if (array_key_exists($k, $in)) $s[$k] = max($r[0], min($r[1], round(floatval(str_replace(',', '.', (string) $in[$k])), 2)));
        }
        if (array_key_exists('new_account_days', $in)) $s['new_account_days'] = max(0, min(365, intval($in['new_account_days'])));
        if ($s['free_max'] < $s['free_min']) $s['free_max'] = $s['free_min'];
        if (array_key_exists('bonus_tiers', $in)) {
            $tiers = [];
            foreach ((array) $in['bonus_tiers'] as $t) {
                if (!is_array($t)) continue;
                $from = round(floatval(str_replace(',', '.', (string) ($t['from'] ?? 0))), 2);
                $bonus = round(floatval(str_replace(',', '.', (string) ($t['bonus'] ?? 0))), 2);
                $type = ($t['type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
                if ($from <= 0 || $bonus <= 0 || ($type === 'percent' && $bonus > 100)) continue;
                $tiers[] = ['from' => $from, 'bonus' => $bonus, 'type' => $type];
            }
            usort($tiers, function ($a, $b) { return $a['from'] <=> $b['from']; });
            $s['bonus_tiers'] = array_slice($tiers, 0, 6);
        }
        if (array_key_exists('stations', $in)) {
            $list = is_array($in['stations']) ? $in['stations'] : preg_split('/\r\n|\r|\n|,/', (string) $in['stations']);
            $st = [];
            foreach ($list as $v) { $v = mb_substr(trim(sanitize_text_field((string) $v)), 0, 60); if ($v !== '') $st[] = $v; }
            $s['stations'] = array_slice(array_values(array_unique($st)), 0, 30);
        }
        if (array_key_exists('refund_policy', $in)) $s['refund_policy'] = mb_substr(sanitize_textarea_field((string) $in['refund_policy']), 0, 1000);
        return $s;
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Gespeicherte Einstellungen eines Bereichs (0 = Seite). */
    public static function stored($oid = 0) {
        $raw = $oid ? get_post_meta(intval($oid), self::META_ORG, true) : get_option(self::OPT, []);
        return self::sanitize_settings(is_array($raw) ? $raw : []);
    }

    /** Plattform-Guthaben an? (Mehr-Veranstalter-Modus + Schalter tix_wallet_platform = '1') */
    public static function platform_on() {
        return self::multi() && get_option(self::OPT_PLATFORM, '0') === '1';
    }

    /** Geldbörse des Plattform-Guthabens (Mehr-Veranstalter-Modus, Bereich 0)? */
    public static function is_platform_wallet($w) {
        return is_array($w) && self::multi() && intval($w['organizer_id'] ?? -1) === 0;
    }

    /** Bonus erlaubt? Auf dem Plattform-Guthaben vorerst nicht (User-Entscheid bis Anwalt/Steuerberater). */
    public static function bonus_allowed($oid = 0) {
        return !(self::multi() && !intval($oid)) || (bool) apply_filters('tix_wallet_platform_bonus', false);
    }

    /** Ist der Bereich aktiv? Seite: Schalter (im Mehr-Veranstalter-Modus = Plattform-Guthaben); Veranstalter: Modul + Freigabe. */
    public static function scope_enabled($oid = 0) {
        $oid = intval($oid);
        if (!$oid) return self::multi() ? self::platform_on() : !empty(self::stored(0)['enabled']);
        if (!self::multi() || get_post_type($oid) !== 'tix_organizer' || get_post_status($oid) !== 'publish') return false;
        if (class_exists('TIX_Org_Approval') && !TIX_Org_Approval::is_public($oid)) return false;
        $mods = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::modules($oid) : [];
        return !empty($mods['wallet']);
    }

    /** Aufladen möglich? Im Mehr-Veranstalter-Modus bis zum Direkt-Geldfluss (Phase 5) aus. */
    public static function topup_enabled($oid = 0) {
        return self::scope_enabled($oid) && !intval($oid);
    }

    public static function settings($oid = 0) {
        return self::stored($oid);
    }

    public static function spend_order() {
        return get_option(self::OPT_SPEND, 'paid_first') === 'bonus_first' ? 'bonus_first' : 'paid_first';
    }

    // ═══════════════ Bonus ═══════════════

    /** Bonus in Cent für einen Aufladebetrag in Cent (höchste passende Stufe). */
    public static function bonus_for($amount_cents, array $s) {
        $best = 0;
        foreach ((array) $s['bonus_tiers'] as $t) {
            if ($amount_cents < self::cents($t['from'])) continue;
            $b = $t['type'] === 'percent' ? (int) round($amount_cents * floatval($t['bonus']) / 100) : self::cents($t['bonus']);
            $best = $b; // Stufen sind aufsteigend sortiert
        }
        return max(0, $best);
    }

    public static function tier_text(array $t) {
        $b = $t['type'] === 'percent' ? rtrim(rtrim(number_format($t['bonus'], 2, ',', '.'), '0'), ',') . ' %' : self::fmt(self::cents($t['bonus']));
        return '+' . $b . ' ab ' . self::fmt(self::cents($t['from']));
    }

    // ═══════════════ Geldbörsen ═══════════════

    public static function get_wallet($id) {
        global $wpdb;
        if (!self::ready()) return null;
        $w = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('wallets') . " WHERE id = %d", intval($id)), ARRAY_A);
        return $w ?: null;
    }

    public static function find_wallet($user_id, $oid = 0) {
        global $wpdb;
        if (!self::ready() || intval($user_id) <= 0) return null;
        $w = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('wallets') . " WHERE user_id = %d AND organizer_id = %d", intval($user_id), intval($oid)), ARRAY_A);
        return $w ?: null;
    }

    public static function get_or_create($user_id, $oid = 0) {
        global $wpdb;
        $user_id = intval($user_id); $oid = intval($oid);
        if ($user_id <= 0 || !self::ready()) return null;
        $w = self::find_wallet($user_id, $oid);
        if ($w) return $w;
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::t('wallets') . " (user_id, scope_type, organizer_id, balance_paid, balance_bonus, status, created, updated) VALUES (%d, %s, %d, 0, 0, 'active', %s, %s)",
            $user_id, $oid ? 'organizer' : (self::multi() ? 'platform' : 'site'), $oid, $now, $now
        ));
        return self::find_wallet($user_id, $oid);
    }

    // ═══════════════ Buchen (einzige Stelle, die Salden ändert) ═══════════════

    /**
     * Bucht auf eine Geldbörse.
     *
     * $args: type (Pflicht), amount_paid / amount_bonus (Cent mit Vorzeichen) ODER amount (Cent, negativ) für
     *        Abbuchungen nach Reihenfolge-Regel, idempotency_key, order_id, ref_tx_id, staff_user_id, station,
     *        device_id, channel, meta (Array), allow_locked (bool, z. B. Gegenbuchungen).
     *
     * @return array Buchungszeile (bei Idempotenz-Treffer die vorhandene, Schlüssel 'replay' => true) | WP_Error
     */
    public static function post_tx($wallet_id, array $args) {
        global $wpdb;
        if (!self::ready()) return new WP_Error('tix_wallet_unavailable', 'Guthaben ist nicht eingerichtet.', ['status' => 503]);
        $wallet_id = intval($wallet_id);
        $type = (string) ($args['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) return new WP_Error('tix_wallet_type', 'Unbekannte Buchungsart.', ['status' => 400]);
        $key = isset($args['idempotency_key']) && $args['idempotency_key'] !== '' ? mb_substr((string) $args['idempotency_key'], 0, 100) : null;
        $channel = in_array($args['channel'] ?? '', self::CHANNELS, true) ? $args['channel'] : 'system';
        $W = self::t('wallets'); $T = self::t('wallet_tx');

        $wpdb->query('START TRANSACTION');
        $w = $wpdb->get_row($wpdb->prepare("SELECT * FROM $W WHERE id = %d FOR UPDATE", $wallet_id), ARRAY_A);
        if (!$w) { $wpdb->query('ROLLBACK'); return new WP_Error('tix_wallet_not_found', 'Geldbörse nicht gefunden.', ['status' => 404]); }
        if ($key !== null) {
            $dup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE wallet_id = %d AND idempotency_key = %s LOCK IN SHARE MODE", $wallet_id, $key), ARRAY_A);
            if ($dup) { $wpdb->query('COMMIT'); $dup['replay'] = true; return $dup; }
        }
        if ($w['status'] !== 'active' && empty($args['allow_locked'])) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tix_wallet_locked', $w['status'] === 'closed' ? 'Diese Geldbörse ist geschlossen.' : 'Diese Geldbörse ist gesperrt. Bitte wende dich an das Team.', ['status' => 423]);
        }
        $paid = intval($w['balance_paid']); $bonus = intval($w['balance_bonus']);
        if (array_key_exists('amount', $args)) {
            // Abbuchung nach zentraler Regel
            $need = -intval($args['amount']);
            if ($need <= 0) { $wpdb->query('ROLLBACK'); return new WP_Error('tix_wallet_amount', 'Betrag ungültig.', ['status' => 400]); }
            if ($paid + $bonus < $need) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('tix_wallet_insufficient', 'Guthaben reicht nicht. Es fehlen ' . self::fmt($need - $paid - $bonus) . '.', ['status' => 409, 'missing' => self::euro($need - $paid - $bonus), 'balance' => self::euro($paid + $bonus)]);
            }
            if (self::spend_order() === 'bonus_first') {
                $db = min($bonus, $need); $dp = $need - $db;
            } else {
                $dp = min($paid, $need); $db = $need - $dp;
            }
            $dp = -$dp; $db = -$db;
        } else {
            $dp = intval($args['amount_paid'] ?? 0);
            $db = intval($args['amount_bonus'] ?? 0);
        }
        if ($dp === 0 && $db === 0) { $wpdb->query('ROLLBACK'); return new WP_Error('tix_wallet_amount', 'Betrag ungültig.', ['status' => 400]); }
        $n = $wpdb->query($wpdb->prepare(
            "UPDATE $W SET balance_paid = balance_paid + %d, balance_bonus = balance_bonus + %d, updated = %s WHERE id = %d AND balance_paid + %d >= 0 AND balance_bonus + %d >= 0",
            $dp, $db, current_time('mysql', true), $wallet_id, $dp, $db
        ));
        if ($n !== 1) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tix_wallet_insufficient', 'Guthaben reicht nicht.', ['status' => 409, 'balance' => self::euro($paid + $bonus)]);
        }
        $ok = $wpdb->insert($T, [
            'wallet_id' => $wallet_id, 'type' => $type, 'amount_paid' => $dp, 'amount_bonus' => $db,
            'balance_after_paid' => $paid + $dp, 'balance_after_bonus' => $bonus + $db,
            'order_id' => intval($args['order_id'] ?? 0), 'ref_tx_id' => intval($args['ref_tx_id'] ?? 0),
            'staff_user_id' => intval($args['staff_user_id'] ?? 0), 'station' => mb_substr(sanitize_text_field((string) ($args['station'] ?? '')), 0, 60),
            'device_id' => intval($args['device_id'] ?? 0), 'channel' => $channel, 'idempotency_key' => $key,
            'meta' => !empty($args['meta']) ? wp_json_encode($args['meta']) : null,
            'organizer_id' => intval($args['organizer_id'] ?? 0),
            'created' => current_time('mysql', true),
        ]);
        if (!$ok) {
            $wpdb->query('ROLLBACK');
            if ($key !== null) {
                $dup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE wallet_id = %d AND idempotency_key = %s", $wallet_id, $key), ARRAY_A);
                if ($dup) { $dup['replay'] = true; return $dup; }
            }
            return new WP_Error('tix_wallet_failed', 'Buchung fehlgeschlagen.', ['status' => 500]);
        }
        $id = intval($wpdb->insert_id);
        $wpdb->query('COMMIT');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE id = %d", $id), ARRAY_A);
    }

    /** Saldo aus den Buchungen nachrechnen. */
    public static function recompute($wallet_id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(amount_paid),0) AS p, COALESCE(SUM(amount_bonus),0) AS b, COUNT(*) AS n FROM " . self::t('wallet_tx') . " WHERE wallet_id = %d", intval($wallet_id)), ARRAY_A);
        return ['paid' => intval($r['p']), 'bonus' => intval($r['b']), 'count' => intval($r['n'])];
    }

    /**
     * Prüfung: Saldo = Summe der Buchungen, Saldo danach lückenlos, nie negativ.
     * @return array Liste der Abweichungen (leer = alles stimmt)
     */
    public static function verify($wallet_id = 0) {
        global $wpdb;
        if (!self::ready()) return [];
        $W = self::t('wallets'); $T = self::t('wallet_tx');
        $ids = $wallet_id ? [intval($wallet_id)] : array_map('intval', (array) $wpdb->get_col("SELECT id FROM $W ORDER BY id"));
        $out = [];
        foreach ($ids as $id) {
            $w = self::get_wallet($id);
            if (!$w) continue;
            $sum = self::recompute($id);
            if ($sum['paid'] !== intval($w['balance_paid']) || $sum['bonus'] !== intval($w['balance_bonus'])) {
                $out[] = ['wallet_id' => $id, 'problem' => 'saldo', 'stored' => [intval($w['balance_paid']), intval($w['balance_bonus'])], 'from_tx' => [$sum['paid'], $sum['bonus']]];
            }
            if (intval($w['balance_paid']) < 0 || intval($w['balance_bonus']) < 0) $out[] = ['wallet_id' => $id, 'problem' => 'negativ'];
            $p = 0; $b = 0;
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, amount_paid, amount_bonus, balance_after_paid, balance_after_bonus FROM $T WHERE wallet_id = %d ORDER BY id", $id), ARRAY_A) as $tx) {
                $p += intval($tx['amount_paid']); $b += intval($tx['amount_bonus']);
                if ($p !== intval($tx['balance_after_paid']) || $b !== intval($tx['balance_after_bonus'])) {
                    $out[] = ['wallet_id' => $id, 'problem' => 'kette', 'tx_id' => intval($tx['id'])];
                    break;
                }
                if ($p < 0 || $b < 0) { $out[] = ['wallet_id' => $id, 'problem' => 'negativ_im_verlauf', 'tx_id' => intval($tx['id'])]; break; }
            }
        }
        return $out;
    }

    public static function set_status($wallet_id, $status) {
        global $wpdb;
        if (!in_array($status, ['active', 'locked', 'closed'], true)) return false;
        return (bool) $wpdb->update(self::t('wallets'), ['status' => $status, 'updated' => current_time('mysql', true)], ['id' => intval($wallet_id)]);
    }

    private static function alert($msg, array $ctx = []) {
        $list = get_option(self::OPT_ALERTS, []);
        $list = is_array($list) ? $list : [];
        array_unshift($list, ['at' => current_time('mysql'), 'msg' => (string) $msg] + $ctx);
        update_option(self::OPT_ALERTS, array_slice($list, 0, 50), false);
    }

    // ═══════════════ Aufladen über die Kasse ═══════════════

    /** System-Event „Guthaben“ des Bereichs (0 = Seite). */
    public static function system_event_id($oid = 0) {
        $mq = [['key' => '_tix_system_wallet_event', 'value' => '1']];
        $mq[] = $oid ? ['key' => '_tix_wallet_organizer', 'value' => (string) intval($oid)] : ['key' => '_tix_wallet_organizer', 'compare' => 'NOT EXISTS'];
        $ids = get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => $mq, 'tix_include_system' => 1, 'suppress_filters' => false]);
        return $ids ? intval($ids[0]) : 0;
    }

    public static function is_wallet_event($event_id) {
        return $event_id && get_post_meta(intval($event_id), '_tix_system_wallet_event', true) === '1';
    }

    /** Event anlegen bzw. Kategorien an die Einstellungen angleichen (nur wenn Aufladen aktiv). */
    public static function ensure_event($oid = 0) {
        if (!self::topup_enabled($oid)) return 0;
        $s = self::settings($oid);
        $id = self::system_event_id($oid);
        if (!$id) {
            $meta = ['_tix_system_wallet_event' => '1', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1'];
            if ($oid) { $meta['_tix_wallet_organizer'] = (string) $oid; $meta['_tix_organizer_id'] = $oid; }
            $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Guthaben', 'post_name' => 'tix-guthaben' . ($oid ? '-' . $oid : ''), 'meta_input' => $meta]);
            if (!$id || is_wp_error($id)) return 0;
        } elseif (get_post_status($id) !== 'publish') {
            wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        }
        if (get_post_meta($id, '_tix_presale_active', true) !== '1') update_post_meta($id, '_tix_presale_active', '1'); // Vorverkauf immer offen (kein Datum)
        $cats = self::build_categories($s);
        if (get_post_meta($id, '_tix_ticket_categories', true) != $cats) update_post_meta($id, '_tix_ticket_categories', $cats);
        return intval($id);
    }

    private static function build_categories(array $s) {
        $base = ['sale_price' => '', 'qty' => 1000000, 'desc' => '', 'image_id' => 0, 'online' => '1', 'offline_ticket' => '0', 'admin_only' => 0,
            'hidden' => 1, 'no_fee' => 1, 'wallet_topup' => 1, 'bundle_buy' => 0, 'bundle_pay' => 0, 'bundle_label' => '', 'tc_event_id' => 0, 'product_id' => 0,
            'sku' => '', 'group' => '', 'seatmap_id' => 0, 'seatmap_section' => '', 'low_stock_mode' => 'off', 'phases' => []];
        $cats = [];
        foreach ($s['amounts'] as $a) $cats[] = ['name' => 'Guthaben ' . self::fmt(self::cents($a)), 'price' => $a, 'wallet_free_amount' => 0] + $base;
        if (!empty($s['free_amount'])) $cats[] = ['name' => 'Guthaben Wunschbetrag', 'price' => 0, 'wallet_free_amount' => 1] + $base;
        return $cats;
    }

    /** System-Event aus allen Listen im Frontend ausblenden (wie Geschenkgutscheine). */
    public static function hide_system_event($query) {
        if (is_admin() || !($query instanceof WP_Query) || $query->get('tix_include_system')) return;
        $pt = $query->get('post_type');
        if (!($pt === 'event' || (is_array($pt) && in_array('event', $pt, true)))) return;
        $mq = array_filter((array) $query->get('meta_query'), function ($v) { return $v !== '' && $v !== null; });
        $hide = ['key' => '_tix_system_wallet_event', 'compare' => 'NOT EXISTS'];
        $query->set('meta_query', $mq ? ['relation' => 'AND', $mq, $hide] : [$hide]);
    }

    /** Bereich (Veranstalter-ID, 0 = Seite) eines Guthaben-Events. */
    public static function event_scope($event_id) {
        return intval(get_post_meta(intval($event_id), '_tix_wallet_organizer', true));
    }

    /**
     * Warenkorb-Position für die App-Kasse (TIX_App_Checkout::build_cart).
     * @return array Position | WP_Error
     */
    public static function cart_item($event_id, $idx, array $cat, $qty, $custom) {
        $oid = self::event_scope($event_id);
        if (!self::topup_enabled($oid)) return new WP_Error('tix_wallet_off', 'Guthaben aufladen ist hier nicht aktiv.', ['status' => 404]);
        if ($qty !== 1) return new WP_Error('tix_wallet_qty', 'Bitte genau eine Aufladung je Bestellung.', ['status' => 400]);
        $s = self::settings($oid);
        if (!empty($cat['wallet_free_amount'])) {
            $amount = round(floatval($custom), 2);
            if ($amount < $s['free_min'] || $amount > $s['free_max']) {
                return new WP_Error('tix_wallet_amount', sprintf('Bitte einen Betrag zwischen %s und %s wählen.', self::fmt(self::cents($s['free_min'])), self::fmt(self::cents($s['free_max']))), ['status' => 400]);
            }
        } else {
            $amount = round(floatval($cat['price'] ?? 0), 2);
        }
        if ($amount <= 0) return new WP_Error('tix_wallet_amount', 'Betrag ungültig.', ['status' => 400]);
        $bonus = self::bonus_allowed($oid) ? self::bonus_for(self::cents($amount), $s) : 0;
        return [
            'event_id' => intval($event_id), 'cat_index' => intval($idx), 'name' => 'Guthaben ' . self::fmt(self::cents($amount)),
            'event_title' => 'Guthaben', 'price' => $amount, 'qty' => 1, 'locked_price' => 1,
            'meta' => ['wallet_topup' => 1, 'wallet_org' => $oid, 'wallet_bonus' => self::euro($bonus)],
        ];
    }

    /** Wallet-Positionen eines Warenkorbs. */
    public static function cart_topups(array $cart) {
        $out = [];
        foreach ((array) ($cart['items'] ?? []) as $it) {
            if (!empty($it['meta']['wallet_topup'])) $out[] = $it;
        }
        return $out;
    }

    /**
     * Prüfung in Quote und Bestellung: Konto Pflicht, Limits (Höchstguthaben, je Aufladung, neue Konten),
     * keine Mischung mit Tickets, kein Gutschein auf Aufladungen.
     * @return WP_Error|null
     */
    public static function cart_error(array $cart, $user) {
        $tops = self::cart_topups($cart);
        if (!$tops) return null;
        if (count($tops) !== count((array) $cart['items'])) return new WP_Error('tix_wallet_mixed', 'Guthaben bitte separat aufladen, nicht zusammen mit Tickets.', ['status' => 400]);
        if (!$user || empty($user->ID)) return new WP_Error('tix_wallet_login_required', 'Zum Aufladen brauchst du ein Konto. Bitte melde dich an.', ['status' => 401]);
        if (count($tops) > 1) return new WP_Error('tix_wallet_qty', 'Bitte genau eine Aufladung je Bestellung.', ['status' => 400]);
        $it = $tops[0];
        $oid = intval($it['meta']['wallet_org'] ?? 0);
        $s = self::settings($oid);
        $amount = self::cents($it['price']);
        $bonus = self::cents($it['meta']['wallet_bonus'] ?? 0);
        $w = self::find_wallet($user->ID, $oid);
        if ($w && $w['status'] !== 'active') return new WP_Error('tix_wallet_locked', 'Deine Geldbörse ist gesperrt. Bitte wende dich an das Team.', ['status' => 423]);
        if ($amount > self::cents($s['max_topup'])) {
            return new WP_Error('tix_wallet_limit_topup', 'Höchstens ' . self::fmt(self::cents($s['max_topup'])) . ' je Aufladung.', ['status' => 409, 'limit' => $s['max_topup']]);
        }
        $current = $w ? intval($w['balance_paid']) + intval($w['balance_bonus']) : 0;
        if ($current + $amount + $bonus > self::cents($s['max_balance'])) {
            $room = max(0, self::cents($s['max_balance']) - $current);
            return new WP_Error('tix_wallet_limit_balance', 'Höchstguthaben ' . self::fmt(self::cents($s['max_balance'])) . ' – du kannst noch ' . self::fmt($room) . ' aufladen (inklusive Bonus).', ['status' => 409, 'limit' => $s['max_balance'], 'room' => self::euro($room)]);
        }
        $days = intval($s['new_account_days']);
        if ($days > 0) {
            $reg = strtotime((string) $user->user_registered . ' UTC');
            if ($reg && $reg > time() - $days * DAY_IN_SECONDS) {
                $sum = $w ? self::topped_up_since(intval($w['id']), $reg) : 0;
                if ($sum + $amount > self::cents($s['new_account_max'])) {
                    $room = max(0, self::cents($s['new_account_max']) - $sum);
                    return new WP_Error('tix_wallet_limit_new', 'Neue Konten können in den ersten ' . $days . ' Tagen höchstens ' . self::fmt(self::cents($s['new_account_max'])) . ' aufladen – noch ' . self::fmt($room) . '.', ['status' => 409, 'limit' => $s['new_account_max'], 'room' => self::euro($room)]);
                }
            }
        }
        if (!empty($cart['coupon']['code'])) return new WP_Error('tix_wallet_coupon', 'Gutscheine gelten nicht für das Aufladen von Guthaben.', ['status' => 400]);
        return null;
    }

    private static function topped_up_since($wallet_id, $ts) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM " . self::t('wallet_tx') . " WHERE wallet_id = %d AND type = 'topup' AND created >= %s", $wallet_id, gmdate('Y-m-d H:i:s', $ts))));
    }

    /** Info für die Quote: {amount, bonus, credit, organizer_id} */
    public static function quote_info(array $cart) {
        $tops = self::cart_topups($cart);
        if (!$tops) return null;
        $it = $tops[0];
        $a = round(floatval($it['price']), 2);
        $b = round(floatval($it['meta']['wallet_bonus'] ?? 0), 2);
        return ['amount' => $a, 'bonus' => $b, 'credit' => round($a + $b, 2), 'organizer_id' => intval($it['meta']['wallet_org'] ?? 0)];
    }

    /** Positionen einer Bestellung, die Aufladungen sind. */
    private static function order_topups($order_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, total, quantity, meta FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", intval($order_id)), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) {
            $m = $r['meta'] ? json_decode($r['meta'], true) : [];
            if (!empty($m['wallet_topup'])) $out[] = ['item_id' => intval($r['id']), 'total' => floatval($r['total']), 'org' => intval($m['wallet_org'] ?? 0), 'bonus' => floatval($m['wallet_bonus'] ?? 0)];
        }
        return $out;
    }

    /** Bestellung besteht nur aus Aufladungen (keine Tickets nötig). */
    public static function order_is_wallet_only($order_id) {
        global $wpdb;
        $n = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", intval($order_id))));
        return $n > 0 && count(self::order_topups($order_id)) === $n;
    }

    /** tix_order_completed: Betrag + Bonus gutschreiben (je eigene Buchung, idempotent je Position). */
    public static function on_order_completed($order_id) {
        global $wpdb;
        if (!self::ready()) return;
        $order_id = intval($order_id);
        $tops = self::order_topups($order_id);
        if (!$tops) return;
        $uid = intval($wpdb->get_var($wpdb->prepare("SELECT customer_id FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id)));
        if ($uid <= 0) {
            self::alert('Aufladung ohne Konto – nicht gutgeschrieben, bitte erstatten.', ['order_id' => $order_id]);
            self::note($order_id, '⚠️ Guthaben-Aufladung ohne Konto – nicht gutgeschrieben. Bitte erstatten.');
            return;
        }
        foreach ($tops as $t) {
            $w = self::get_or_create($uid, $t['org']);
            if (!$w) continue;
            $amount = self::cents($t['total']);
            $tx = self::post_tx($w['id'], ['type' => 'topup', 'amount_paid' => $amount, 'order_id' => $order_id, 'channel' => 'app', 'idempotency_key' => 'order:' . $order_id . ':topup:' . $t['item_id'], 'allow_locked' => true]);
            if (is_wp_error($tx) || !empty($tx['replay'])) continue;
            $bonus = self::cents($t['bonus']);
            if ($bonus > 0) self::post_tx($w['id'], ['type' => 'bonus', 'amount_bonus' => $bonus, 'order_id' => $order_id, 'ref_tx_id' => intval($tx['id']), 'channel' => 'app', 'idempotency_key' => 'order:' . $order_id . ':bonus:' . $t['item_id'], 'allow_locked' => true]);
            self::note($order_id, '💳 Guthaben gutgeschrieben: ' . self::fmt($amount) . ($bonus ? ' + ' . self::fmt($bonus) . ' Bonus' : '') . ' (Geldbörse #' . $w['id'] . ').');
            if (class_exists('TIX_Notifications')) {
                TIX_Notifications::add_user_item($uid, 'Guthaben aufgeladen', self::fmt($amount + $bonus) . ' sind jetzt auf deinem Konto' . ($bonus ? ' (inkl. ' . self::fmt($bonus) . ' Bonus)' : '') . '.', ['type' => 'wallet', 'action' => 'wallet']);
            }
        }
    }

    public static function on_status_changed($order_id, $new, $old) {
        if (in_array($new, ['cancelled', 'refunded', 'failed'], true) && in_array($old, ['completed', 'processing'], true)) {
            self::reverse_order($order_id, $new === 'refunded' ? 'Erstattung' : 'Storno');
        }
        // Mit Guthaben bezahlt (1.38.436): Restzahlung gescheitert/verfallen, Storno oder Erstattung → zurück ins Guthaben
        if (in_array($new, ['cancelled', 'refunded', 'failed'], true)) {
            self::refund_payment($order_id, $new === 'refunded' ? 'Erstattung' : ($old === 'pending' || $old === 'on-hold' ? 'Zahlung nicht abgeschlossen' : 'Storno'));
        }
    }

    // ═══════════════ Mit Guthaben bezahlen (App-Kasse, 1.38.436) ═══════════════

    /** Bereich, aus dem ein Warenkorb bezahlt werden kann: Seite (0) bzw. gemeinsamer Veranstalter; null = nicht möglich. */
    public static function checkout_scope(array $cart) {
        $items = (array) ($cart['items'] ?? []);
        if (!$items || self::cart_topups($cart)) return null;
        if (!self::multi()) return 0;
        if (self::platform_on()) return 0; // Plattform-Guthaben: Tickets jedes Veranstalters
        return self::organizer_scope($cart);
    }

    /** Gemeinsamer Veranstalter aller Positionen (Veranstalter-Geldbörsen) oder null. */
    public static function organizer_scope(array $cart) {
        $items = (array) ($cart['items'] ?? []);
        if (!$items || !self::multi()) return null;
        $common = null;
        foreach ($items as $it) {
            $orgs = class_exists('TIX_App_Scope') ? TIX_App_Scope::event_org_ids(intval($it['event_id'] ?? 0)) : [];
            $common = $common === null ? $orgs : array_values(array_intersect($common, $orgs));
            if (!$common) return null;
        }
        return $common ? intval($common[0]) : null;
    }

    /**
     * Guthaben für eine Bestellung: {available, applied, rest, wallet_id, organizer_id} (Euro).
     * `reason` wenn nichts angerechnet wird: login_required | no_wallet | locked | topup | scope | zero.
     */
    public static function checkout_info($user, array $cart, $total) {
        $total_c = self::cents($total);
        $out = ['available' => 0.0, 'applied' => 0.0, 'rest' => self::euro($total_c), 'wallet_id' => null, 'organizer_id' => null];
        if (!$user || empty($user->ID)) return $out + ['reason' => 'login_required'];
        if (self::cart_topups($cart)) return $out + ['reason' => 'topup'];
        $oid = self::checkout_scope($cart);
        if ($oid === null) return $out + ['reason' => 'scope'];
        $w = self::find_wallet($user->ID, $oid);
        // Plattform-Guthaben leer: vorhandenes Veranstalter-Guthaben (z. B. aus einem Veranstalter-Gutschein) nutzen
        if (self::platform_on() && $oid === 0 && (!$w || intval($w['balance_paid']) + intval($w['balance_bonus']) <= 0)) {
            $alt = self::organizer_scope($cart);
            $aw = $alt ? self::find_wallet($user->ID, $alt) : null;
            if ($aw && intval($aw['balance_paid']) + intval($aw['balance_bonus']) > 0) { $oid = intval($alt); $w = $aw; }
        }
        if (!$w) return $out + ['organizer_id' => $oid, 'reason' => 'no_wallet'];
        $bal = intval($w['balance_paid']) + intval($w['balance_bonus']);
        $out['available'] = self::euro($bal); $out['wallet_id'] = intval($w['id']); $out['organizer_id'] = $oid;
        if ($w['status'] !== 'active') return ['available' => self::euro($bal), 'applied' => 0.0, 'rest' => self::euro($total_c), 'wallet_id' => intval($w['id']), 'organizer_id' => $oid, 'reason' => 'locked'];
        if ($total_c <= 0 || $bal <= 0) return $out + ['reason' => 'zero'];
        $apply = min($bal, $total_c);
        $out['applied'] = self::euro($apply);
        $out['rest'] = self::euro($total_c - $apply);
        return $out;
    }

    /** Guthaben-Anteil einer Bestellung abbuchen (Idempotenz = Bestellung). */
    public static function pay_order($order_id, $wallet_id, $cents) {
        $tx = self::post_tx(intval($wallet_id), ['type' => 'charge', 'amount' => -intval($cents), 'order_id' => intval($order_id), 'channel' => 'app',
            'idempotency_key' => 'order:' . intval($order_id) . ':wallet', 'meta' => ['reason' => 'Bestellung']]);
        if (!is_wp_error($tx)) self::note($order_id, '💳 Mit Guthaben bezahlt: ' . self::fmt(-intval($tx['amount_paid']) - intval($tx['amount_bonus'])) . ' (Geldbörse #' . intval($wallet_id) . ').');
        return $tx;
    }

    /** Guthaben-Zahlung einer Bestellung (Abbuchung) oder null. */
    public static function order_payment($order_id) {
        global $wpdb;
        if (!self::ready()) return null;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('wallet_tx') . " WHERE order_id = %d AND type = 'charge' ORDER BY id ASC LIMIT 1", intval($order_id)), ARRAY_A);
        return $r ?: null;
    }

    /** Guthaben-Anteil zurückbuchen (genau die Aufteilung bezahlt/Bonus der Abbuchung, nur einmal). */
    public static function refund_payment($order_id, $reason) {
        $tx = self::order_payment($order_id);
        if (!$tx) return null;
        $r = self::post_tx(intval($tx['wallet_id']), ['type' => 'reversal', 'amount_paid' => -intval($tx['amount_paid']), 'amount_bonus' => -intval($tx['amount_bonus']),
            'order_id' => intval($order_id), 'ref_tx_id' => intval($tx['id']), 'channel' => 'system', 'allow_locked' => true,
            'idempotency_key' => 'order:' . intval($order_id) . ':wallet-back', 'meta' => ['reason' => $reason]]);
        if (!is_wp_error($r) && empty($r['replay'])) {
            $amount = -intval($tx['amount_paid']) - intval($tx['amount_bonus']);
            self::note($order_id, '↩️ ' . self::fmt($amount) . ' zurück ins Guthaben (' . $reason . ').');
            $w = self::get_wallet($tx['wallet_id']);
            if ($w && intval($w['user_id']) > 0 && class_exists('TIX_Notifications')) {
                TIX_Notifications::add_user_item(intval($w['user_id']), 'Guthaben zurückgebucht', self::fmt($amount) . ' zurück ins Guthaben (' . $reason . ')', ['type' => 'wallet', 'action' => 'wallet']);
            }
        }
        return $r;
    }

    /** Stündlich: offene Bestellungen mit Guthaben-Anteil, deren Restzahlung nach 3 h nicht kam → stornieren (bucht zurück). */
    public static function cron_pay_timeout() {
        global $wpdb;
        if (!self::ready()) return 0;
        $T = self::t('wallet_tx');
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT o.id FROM {$wpdb->prefix}tix_orders o JOIN $T t ON t.order_id = o.id AND t.type = 'charge'
             WHERE o.status IN ('pending','on-hold') AND t.created < %s
             AND NOT EXISTS (SELECT 1 FROM $T r WHERE r.ref_tx_id = t.id AND r.type = 'reversal') LIMIT 50",
            gmdate('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS)
        ));
        foreach ((array) $rows as $oid) {
            if (class_exists('TIX_Order_Admin')) TIX_Order_Admin::add_note(intval($oid), '⏱️ Restzahlung nicht abgeschlossen (3 h) – Bestellung storniert, Guthaben zurückgebucht.', 'system');
            TIX_Native_Checkout::update_order_status(intval($oid), 'cancelled', 'wallet');
        }
        return count((array) $rows);
    }

    // ═══════════════ Geschenkgutschein ins Guthaben (1.38.436) ═══════════════

    /** Gutschein-Code aus Eingabe oder Scan: „GS-XXXX-XXXX“ bzw. Ticket-QR „GL-<event>-<ticketcode>“ des Gutschein-Tickets. */
    public static function giftcard_code_from_input($raw) {
        $raw = strtoupper(trim(preg_replace('/\s+/', '', (string) $raw)));
        if (preg_match('/^GL-(\d+)-([A-Z0-9-]{3,20})$/', $raw, $m) && class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'get_ticket_by_code')) {
            $t = TIX_Tickets::get_ticket_by_code($m[2]);
            $id = is_object($t) ? intval($t->ID ?? 0) : (is_array($t) ? intval($t['ID'] ?? ($t['id'] ?? 0)) : intval($t));
            $code = $id ? (string) get_post_meta($id, '_tix_ticket_gift_code', true) : '';
            return $code !== '' ? $code : '';
        }
        // reiner Ticket-Code des Gutschein-Tickets (1.38.463)
        if (!preg_match('/^GS-/', $raw) && preg_match('/^[A-Z0-9-]{4,20}$/', $raw) && class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'get_ticket_by_code')
            && !(class_exists('TIX_Giftcards') && TIX_Giftcards::get_card($raw))) {
            $t = TIX_Tickets::get_ticket_by_code($raw);
            $id = is_object($t) ? intval($t->ID ?? 0) : (is_array($t) ? intval($t['ID'] ?? ($t['id'] ?? 0)) : intval($t));
            $code = $id ? (string) get_post_meta($id, '_tix_ticket_gift_code', true) : '';
            if ($code !== '') return $code;
        }
        return $raw;
    }

    /** Zustand eines Gutscheins für Fehlerantworten: {code, value, balance, expires, redeemed_at, redeemed_via}. */
    private static function giftcard_state(array $card) {
        $last = null;
        foreach ((array) ($card['redemptions'] ?? []) as $r) if (is_array($r)) $last = $r;
        return [
            'code'         => (string) $card['code'],
            'value'        => round(floatval($card['value'] ?? 0), 2),
            'balance'      => round(floatval($card['balance'] ?? 0), 2),
            'expires'      => (string) ($card['expires'] ?? ''),
            'redeemed_at'  => $last ? (string) ($last['ts'] ?? '') : null,
            'redeemed_via' => $last ? (string) ($last['channel'] ?? '') : null,
        ];
    }

    /** POST /wallet/redeem-giftcard {code[, organizer]} – Restwert ins Guthaben, Gutschein danach aufgebraucht. */
    public static function rest_redeem_giftcard(WP_REST_Request $req) {
        global $wpdb;
        $uid = get_current_user_id();
        if (class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'rate_limited') && TIX_App_Account::rate_limited('wallet_gc', 10, 300, 'u' . $uid)) {
            return new WP_Error('tix_wallet_rate_limited', 'Zu viele Versuche – bitte kurz warten.', ['status' => 429]);
        }
        $code = self::giftcard_code_from_input($req->get_param('code'));
        $card = ($code !== '' && class_exists('TIX_Giftcards')) ? TIX_Giftcards::get_card($code) : null;
        if (!$card) return new WP_Error('tix_giftcard_unknown', 'Diesen Gutschein kennen wir nicht. Bitte den Code prüfen.', ['status' => 404]);
        $code = (string) $card['code'];
        if (!empty($card['expires']) && strtotime($card['expires'] . ' 23:59:59') < current_time('timestamp')) {
            return new WP_Error('tix_giftcard_expired', 'Dieser Gutschein ist abgelaufen (' . date_i18n('d.m.Y', strtotime($card['expires'])) . ').', ['status' => 410] + self::giftcard_state($card));
        }
        if (round(floatval($card['balance'] ?? 0), 2) <= 0) {
            $st = self::giftcard_state($card);
            $when = $st['redeemed_at'] ? ' am ' . date_i18n('d.m.Y', strtotime($st['redeemed_at'])) : '';
            return new WP_Error('tix_giftcard_used', 'Dieser Gutschein wurde schon' . $when . ' eingelöst (Restwert 0 €).', ['status' => 409] + $st);
        }
        // Teilbetrag (1.38.463): amount ≤ Restwert, sonst der ganze Restwert
        $want_amount = $req->get_param('amount');
        $part = ($want_amount !== null && $want_amount !== '') ? self::cents(str_replace(',', '.', (string) $want_amount)) : 0;
        if ($want_amount !== null && $want_amount !== '' && $part <= 0) return new WP_Error('tix_invalid_field', 'Bitte einen Betrag über 0 € angeben.', ['status' => 400, 'field' => 'amount']);
        $oid = intval($card['organizer_id'] ?? 0);
        $want = $req->get_param('organizer') ?? $req->get_param('organizer_id');
        // Plattform-Guthaben: Seiten-Gutscheine gehen immer in die Plattform-Geldbörse (organizer der App egal)
        if ($want !== null && $want !== '' && intval($want) !== $oid && !($oid === 0 && self::platform_on())) return new WP_Error('tix_giftcard_scope', 'Dieser Gutschein gilt bei einem anderen Veranstalter.', ['status' => 409]);
        if (!self::scope_enabled($oid)) {
            return new WP_Error('tix_giftcard_scope', $oid ? 'Bei diesem Veranstalter gibt es kein Guthaben.' : 'Dieser Gutschein lässt sich hier nicht ins Guthaben übernehmen.', ['status' => 409]);
        }
        $w = self::get_or_create($uid, $oid);
        if (!$w) return new WP_Error('tix_wallet_unavailable', 'Guthaben ist nicht eingerichtet.', ['status' => 503]);
        if ($w['status'] !== 'active') return new WP_Error('tix_wallet_locked', 'Deine Geldbörse ist gesperrt. Bitte wende dich an das Team.', ['status' => 423]);
        $lock = 'tix_gc_' . md5(strtoupper($code));
        if (!intval($wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)))) return new WP_Error('tix_wallet_busy', 'Gerade viel los – bitte nochmal.', ['status' => 409]);
        try {
            // frisch aus der Datenbank lesen (unter Sperre; der Options-Zwischenspeicher dieses Aufrufs kann veraltet sein)
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('tix_coupons', 'options');
            $card = TIX_Giftcards::get_card($code);
            $bal = $card ? self::cents($card['balance'] ?? 0) : 0;
            if ($bal <= 0) return new WP_Error('tix_giftcard_used', 'Dieser Gutschein wurde schon eingelöst (Restwert 0 €).', ['status' => 409] + ($card ? self::giftcard_state($card) : ['code' => $code, 'balance' => 0]));
            if ($part > $bal) return new WP_Error('tix_giftcard_amount', 'Auf dem Gutschein sind nur noch ' . self::fmt($bal) . '.', ['status' => 409, 'field' => 'amount'] + self::giftcard_state($card));
            if ($part > 0) $bal = $part;
            $s = self::settings($oid);
            $w = self::get_wallet($w['id']);
            $cur = intval($w['balance_paid']) + intval($w['balance_bonus']);
            if ($cur + $bal > self::cents($s['max_balance'])) {
                return new WP_Error('tix_wallet_limit_balance', 'Höchstguthaben ' . self::fmt(self::cents($s['max_balance'])) . ' – der Gutschein (' . self::fmt($bal) . ') passt nicht mehr hinein. Löse ihn an der Kasse ein.', ['status' => 409, 'limit' => $s['max_balance'], 'room' => self::euro(max(0, self::cents($s['max_balance']) - $cur))]);
            }
            $n = count((array) ($card['redemptions'] ?? []));
            $tx = self::post_tx($w['id'], ['type' => 'topup', 'amount_paid' => $bal, 'channel' => 'giftcard', 'idempotency_key' => 'giftcard:' . $code . ':' . $n, 'meta' => ['giftcard' => $code]]);
            if (is_wp_error($tx)) return $tx;
            if (!empty($tx['replay'])) return new WP_Error('tix_giftcard_used', 'Dieser Gutschein wurde schon eingelöst.', ['status' => 409]);
            $red = TIX_Giftcards::redeem($code, self::euro($bal), 'wallet', 'wallet:' . $w['id'] . ':tx' . $tx['id']);
            if (empty($red['ok'])) {
                self::post_tx($w['id'], ['type' => 'reversal', 'amount_paid' => -$bal, 'ref_tx_id' => intval($tx['id']), 'channel' => 'system', 'allow_locked' => true, 'idempotency_key' => 'giftcard:' . $code . ':' . $n . ':back', 'meta' => ['reason' => 'Gutschein nicht eingelöst']]);
                return new WP_Error('tix_giftcard_used', 'Dieser Gutschein wurde schon eingelöst.', ['status' => 409]);
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        if (class_exists('TIX_Notifications')) TIX_Notifications::add_user_item($uid, 'Gutschein eingelöst', self::fmt($bal) . ' ins Guthaben übernommen', ['type' => 'wallet', 'action' => 'wallet']);
        $w = self::get_wallet($w['id']);
        $after = TIX_Giftcards::get_card($code);
        return self::out(['ok' => true, 'amount' => self::euro($bal), 'code' => $code, 'giftcard_rest' => $after ? round(floatval($after['balance'] ?? 0), 2) : 0.0, 'wallet' => self::wallet_payload($w), 'tx' => self::tx_payload($tx)]);
    }

    public static function on_chargeback($order_id, $amount = 0, $gateway = '') {
        self::reverse_order($order_id, 'Rückbuchung' . ($gateway ? ' (' . $gateway . ')' : ''), true);
    }

    /**
     * Gegenbuchung für Aufladungen einer Bestellung, soweit Guthaben da ist; Rest → Geldbörse gesperrt + Hinweis.
     */
    public static function reverse_order($order_id, $reason, $chargeback = false) {
        global $wpdb;
        if (!self::ready()) return;
        $order_id = intval($order_id);
        $T = self::t('wallet_tx');
        $txs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $T WHERE order_id = %d AND type IN ('topup','bonus') ORDER BY id", $order_id), ARRAY_A);
        foreach ((array) $txs as $tx) {
            $w = self::get_wallet($tx['wallet_id']);
            if (!$w) continue;
            $is_bonus = $tx['type'] === 'bonus';
            $orig = $is_bonus ? intval($tx['amount_bonus']) : intval($tx['amount_paid']);
            $done = -intval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(" . ($is_bonus ? 'amount_bonus' : 'amount_paid') . "),0) FROM $T WHERE ref_tx_id = %d AND type = 'reversal'", $tx['id'])));
            $open = $orig - $done;
            if ($open <= 0) continue;
            $avail = $is_bonus ? intval($w['balance_bonus']) : intval($w['balance_paid']);
            $take = min($open, max(0, $avail));
            if ($take > 0) {
                self::post_tx($w['id'], ['type' => 'reversal', ($is_bonus ? 'amount_bonus' : 'amount_paid') => -$take, 'order_id' => $order_id, 'ref_tx_id' => intval($tx['id']),
                    'channel' => 'system', 'idempotency_key' => 'order:' . $order_id . ':reversal:' . $tx['id'] . ':' . $done, 'allow_locked' => true, 'meta' => ['reason' => $reason]]);
            }
            if ($take < $open && !$is_bonus) {
                self::set_status($w['id'], 'locked');
                $msg = 'Geldbörse #' . $w['id'] . ' gesperrt: ' . $reason . ' der Aufladung (Bestellung #' . $order_id . '), ' . self::fmt($open - $take) . ' bereits ausgegeben.';
                self::alert($msg, ['wallet_id' => intval($w['id']), 'order_id' => $order_id, 'missing' => self::euro($open - $take)]);
                self::note($order_id, '⚠️ ' . $msg);
            }
        }
        if ($chargeback) self::note($order_id, '↩️ Rückbuchung gemeldet – Guthaben-Gegenbuchung ausgeführt.');
    }

    private static function note($order_id, $msg) {
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) TIX_Order_Admin::add_note(intval($order_id), $msg, 'system');
    }

    // ═══════════════ Konto löschen ═══════════════

    /** Offenes bezahltes Guthaben eines Kontos (Cent, alle Bereiche). */
    public static function open_paid($user_id) {
        global $wpdb;
        if (!self::ready()) return 0;
        return intval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(balance_paid),0) FROM " . self::t('wallets') . " WHERE user_id = %d", intval($user_id))));
    }

    /** Für /auth/delete: nur mit Richtlinie „block“ (Entscheidung offen) Fehler bei offenem bezahltem Guthaben. */
    public static function delete_block_error($user_id) {
        if (get_option(self::OPT_DELETE, 'anonymize') !== 'block') return null;
        $open = self::open_paid($user_id);
        if ($open <= 0) return null;
        return new WP_Error('tix_wallet_open_balance', 'Du hast noch ' . self::fmt($open) . ' bezahltes Guthaben. Bitte lass es dir zuerst erstatten, dann kannst du dein Konto löschen.', ['status' => 409, 'open_paid' => self::euro($open)]);
    }

    /** Konto wird gelöscht: Geldbörsen anonymisieren und schließen, Buchungen bleiben (Aufbewahrungspflicht). */
    public static function on_delete_user($user_id) {
        global $wpdb;
        if (!self::ready()) return;
        $W = self::t('wallets');
        // Geräte-Schlüssel (Phase 2) entfernen – ohne Konto kein Bezahlen mehr
        $wpdb->delete(self::t('wallet_devices'), ['user_id' => intval($user_id)]);
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, balance_paid, balance_bonus FROM $W WHERE user_id = %d", intval($user_id)), ARRAY_A) as $w) {
            $wpdb->update($W, ['user_id' => -intval($w['id']), 'status' => 'closed', 'updated' => current_time('mysql', true)], ['id' => intval($w['id'])]);
            if (intval($w['balance_paid']) + intval($w['balance_bonus']) > 0) {
                self::alert('Konto gelöscht mit Restguthaben: Geldbörse #' . $w['id'] . ' (' . self::fmt($w['balance_paid']) . ' bezahlt, ' . self::fmt($w['balance_bonus']) . ' Bonus) – geschlossen und anonymisiert.', ['wallet_id' => intval($w['id'])]);
            }
        }
    }

    // ═══════════════ Ausgabe ═══════════════

    public static function wallet_payload(array $w) {
        $p = intval($w['balance_paid']); $b = intval($w['balance_bonus']);
        $oid = intval($w['organizer_id']);
        return [
            'id' => intval($w['id']), 'scope' => $oid ? 'organizer' : (self::multi() ? 'platform' : 'site'), 'organizer_id' => $oid,
            'organizer_name' => $oid ? html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8') : html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8'),
            'status' => (string) $w['status'], 'balance' => self::euro($p + $b), 'balance_paid' => self::euro($p), 'balance_bonus' => self::euro($b),
            'currency' => 'EUR', 'updated_at' => mysql2date('c', get_date_from_gmt($w['updated']), false),
        ];
    }

    public static function type_title($type, $amount) {
        $map = ['topup' => 'Aufgeladen', 'bonus' => 'Bonus', 'charge' => 'Bezahlt', 'reversal' => 'Storniert', 'refund' => 'Erstattet', 'adjust' => 'Korrektur', 'expire' => 'Verfallen', 'payout' => 'Ausgezahlt'];
        if ($type === 'reversal' && $amount > 0) return 'Zahlung storniert';
        return $map[$type] ?? $type;
    }

    /** Bestellnummer und erstes Event einer Bestellung (ohne Guthaben-Event) für den Verlauf. */
    private static function order_ref($order_id) {
        static $cache = [];
        $order_id = intval($order_id);
        if (!$order_id) return ['number' => null, 'event_id' => null, 'event_title' => null];
        if (isset($cache[$order_id])) return $cache[$order_id];
        global $wpdb;
        $num = (string) $wpdb->get_var($wpdb->prepare("SELECT order_number FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id));
        $eids = (array) $wpdb->get_col($wpdb->prepare("SELECT event_id FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d ORDER BY id", $order_id));
        $eid = 0;
        foreach ($eids as $e) { if (intval($e) && !self::is_wallet_event(intval($e))) { $eid = intval($e); break; } }
        return $cache[$order_id] = ['number' => $num !== '' ? $num : (string) $order_id, 'event_id' => $eid ?: null,
            'event_title' => $eid ? html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8') : null];
    }

    public static function tx_payload(array $tx, $for_staff = false) {
        $a = intval($tx['amount_paid']) + intval($tx['amount_bonus']);
        $title = ($tx['channel'] ?? '') === 'giftcard' && $tx['type'] === 'topup' ? 'Gutschein eingelöst' : self::type_title($tx['type'], $a);
        // Verlauf (1.38.476): Bezug (Bestellung/Event bzw. Station/Veranstalter) und klarere Titel
        $ref = self::order_ref($tx['order_id'] ?? 0);
        $ch_names = ['pos_cash' => 'Kasse', 'pos_card' => 'Kasse', 'sumup' => 'Kasse', 'app' => 'App', 'web' => 'Web', 'admin' => 'Verwaltung', 'system' => 'System', 'giftcard' => 'Gutschein'];
        if ($tx['type'] === 'charge') $title = $ref['number'] ? 'Ticketkauf' : 'Bezahlt';
        if ($tx['type'] === 'topup' && ($tx['channel'] ?? '') !== 'giftcard' && $ref['number']) $title = 'Aufgeladen';
        $org_name = intval($tx['organizer_id'] ?? 0) ? html_entity_decode(get_the_title(intval($tx['organizer_id'])), ENT_QUOTES, 'UTF-8') : '';
        if ($ref['number']) $sub = trim(($ref['event_title'] ? $ref['event_title'] . ' · ' : '') . 'Bestellung ' . $ref['number']);
        else {
            $m0 = !empty($tx['meta']) ? json_decode((string) $tx['meta'], true) : [];
            $sub = trim(implode(' · ', array_filter([(string) ($tx['station'] ?? ''), $org_name])));
            if ($sub === '' && !empty($m0['reason'])) $sub = (string) $m0['reason'];
            if ($sub === '') $sub = $ch_names[$tx['channel'] ?? ''] ?? '';
        }
        $out = [
            'id' => intval($tx['id']), 'type' => (string) $tx['type'], 'title' => $title,
            'amount' => self::euro($a), 'amount_paid' => self::euro($tx['amount_paid']), 'amount_bonus' => self::euro($tx['amount_bonus']),
            'balance_after' => self::euro(intval($tx['balance_after_paid']) + intval($tx['balance_after_bonus'])),
            'station' => (string) $tx['station'], 'channel' => (string) $tx['channel'], 'order_id' => intval($tx['order_id']) ?: null,
            // Plattform-Guthaben: bei welchem Veranstalter bezahlt (Cashless), sonst null
            'organizer_id' => intval($tx['organizer_id'] ?? 0) ?: null,
            'organizer_name' => intval($tx['organizer_id'] ?? 0) ? html_entity_decode(get_the_title(intval($tx['organizer_id'])), ENT_QUOTES, 'UTF-8') : null,
            'created_at' => mysql2date('c', get_date_from_gmt($tx['created']), false),
            'subtitle' => $sub !== '' ? $sub : null,
            'direction' => $a >= 0 ? 'in' : 'out',
            'order_number' => $ref['number'],
            'event_id' => $ref['event_id'],
            'event_title' => $ref['event_title'],
        ];
        if ($for_staff) {
            $out['staff_user_id'] = intval($tx['staff_user_id']);
            $m = $tx['meta'] ? json_decode($tx['meta'], true) : [];
            $out['reason'] = (string) ($m['reason'] ?? '');
        }
        return $out;
    }

    /** Öffentliche Konfiguration eines Bereichs. */
    public static function config_payload($oid = 0) {
        $oid = intval($oid);
        if (!self::scope_enabled($oid)) return ['enabled' => false] + ($oid ? ['organizer_id' => $oid] : []);
        $s = self::settings($oid);
        $topup = self::topup_enabled($oid);
        $event_id = $topup ? self::ensure_event($oid) : 0;
        $cats = $event_id ? (array) get_post_meta($event_id, '_tix_ticket_categories', true) : [];
        $amounts = []; $free_index = -1;
        foreach ($cats as $i => $c) {
            if (!is_array($c) || empty($c['wallet_topup'])) continue;
            if (!empty($c['wallet_free_amount'])) { $free_index = intval($i); continue; }
            $a = round(floatval($c['price']), 2);
            $bonus = self::bonus_allowed($oid) ? self::bonus_for(self::cents($a), $s) : 0;
            $amounts[] = ['index' => intval($i), 'amount' => $a, 'bonus' => self::euro($bonus), 'credit' => self::euro(self::cents($a) + $bonus)];
        }
        $tiers = [];
        if (self::bonus_allowed($oid)) foreach ($s['bonus_tiers'] as $t) $tiers[] = $t + ['text' => self::tier_text($t)];
        $platform = self::multi() && !$oid;
        return [
            'enabled' => true, 'organizer_id' => $oid, 'topup_enabled' => $topup && $event_id > 0,
            // 1.38.466: Bereich + Plattform-Guthaben (evendis): wofür es gilt, wo Cashless geht
            'scope' => $oid ? 'organizer' : ($platform ? 'platform' : 'site'), 'platform' => $platform,
            'usable_for' => ['tickets' => true, 'cashless' => $platform ? (bool) self::cashless_organizers() : true],
            'cashless_organizers' => $platform ? self::cashless_organizers() : null,
            'topup_disabled_reason' => $topup ? null : 'direct_payment_pending',
            'event_id' => $event_id ?: null, 'amounts' => $amounts,
            'free_amount' => !empty($s['free_amount']) && $free_index >= 0, 'free_index' => $free_index, 'min' => $s['free_min'], 'max' => $s['free_max'],
            'bonus_tiers' => $tiers, 'limits' => ['max_balance' => $s['max_balance'], 'max_topup' => $s['max_topup'], 'new_account_days' => $s['new_account_days'], 'new_account_max' => $s['new_account_max']],
            'approval_threshold' => $s['approval_threshold'], 'refund_policy' => $s['refund_policy'], 'login_required' => true, 'currency' => 'EUR',
        ];
    }

    // ═══════════════ REST ═══════════════

    private static function out($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    public static function register_routes() {
        $reg = function ($path, $method, $cb, $perm) {
            register_rest_route(self::NS, $path, ['methods' => $method, 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm]);
        };
        $login = function ($req) { return class_exists('TIX_App_Account') ? TIX_App_Account::check_customer($req) : is_user_logged_in(); };
        $reg('/wallet/config', 'GET', 'rest_config', '__return_true');
        $reg('/wallet', 'GET', 'rest_wallets', $login);
        $reg('/wallet/(?P<id>\d+)/tx', 'GET', 'rest_tx', $login);
        $reg('/wallet/me/tx', 'GET', 'rest_my_tx', $login);
        $reg('/wallet/admin/config', 'GET', 'rest_admin_config', [__CLASS__, 'check_staff']);
        $reg('/wallet/admin/config', 'POST', 'rest_admin_save', [__CLASS__, 'check_manager']);
        $reg('/wallet/admin/report', 'GET', 'rest_report', [__CLASS__, 'check_report']);
        $reg('/wallet/redeem-giftcard', 'POST', 'rest_redeem_giftcard', $login);
    }

    private static function org_param(WP_REST_Request $req) {
        $v = (string) ($req->get_param('organizer') ?? $req->get_param('organizer_id') ?? '');
        if ($v === '' || $v === '0') return 0;
        if (ctype_digit($v)) return intval($v);
        $f = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_org_landing_slug', 'meta_value' => $v]);
        if (!$f) $f = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'name' => sanitize_title($v)]);
        return $f ? intval($f[0]) : -1;
    }

    /** Veranstalter, bei denen das Plattform-Guthaben Cashless gilt (Modul „wallet“, freigegeben): [{id, name, slug}] */
    public static function cashless_organizers() {
        static $list = null;
        if ($list !== null) return $list;
        $list = [];
        if (!self::multi()) return $list;
        foreach (get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 500, 'fields' => 'ids']) as $oid) {
            if (!self::scope_enabled(intval($oid))) continue;
            $list[] = ['id' => intval($oid), 'name' => html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8'), 'slug' => (string) (get_post_meta($oid, '_tix_org_landing_slug', true) ?: get_post_field('post_name', $oid))];
        }
        return $list;
    }

    /** GET /wallet/config[?organizer=] – mit Plattform-Guthaben (1.38.466) gilt für jeden Veranstalter die Plattform-Antwort. */
    public static function rest_config(WP_REST_Request $req) {
        $oid = self::org_param($req);
        if ($oid < 0) return self::out(['ok' => true, 'enabled' => false]);
        if ($oid > 0 && self::platform_on()) {
            return self::out(array_merge(['ok' => true], self::config_payload(0), ['organizer_id' => $oid, 'cashless_here' => self::scope_enabled($oid)]));
        }
        return self::out(['ok' => true] + self::config_payload($oid));
    }

    /** GET /wallet[?organizer=] – Geldbörsen des Kontos. */
    public static function rest_wallets(WP_REST_Request $req) {
        global $wpdb;
        $uid = get_current_user_id();
        $oid = self::org_param($req);
        $rows = [];
        if (self::ready() && $oid >= 0) {
            $sql = "SELECT * FROM " . self::t('wallets') . " WHERE user_id = %d" . ($req->get_param('organizer') !== null || $req->get_param('organizer_id') !== null ? $wpdb->prepare(' AND organizer_id = %d', $oid) : '') . " ORDER BY organizer_id = 0 DESC, id";
            $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $uid), ARRAY_A);
        }
        $out = [];
        foreach ($rows as $w) {
            if ($w['status'] === 'closed') continue;
            // Bereich aus: nur zeigen, wenn noch Guthaben da ist (darf nicht verschwinden)
            if (!self::scope_enabled($w['organizer_id']) && intval($w['balance_paid']) + intval($w['balance_bonus']) <= 0) continue;
            $out[] = self::wallet_payload($w);
        }
        return self::out(['ok' => true, 'enabled' => self::scope_enabled(max(0, $oid)) || (bool) $out, 'wallets' => $out]);
    }

    /** GET /wallet/{id}/tx?page= – nur eigene Geldbörse. */
    public static function rest_tx(WP_REST_Request $req) {
        global $wpdb;
        $w = self::get_wallet($req['id']);
        if (!$w || intval($w['user_id']) !== get_current_user_id()) return new WP_Error('tix_wallet_not_found', 'Geldbörse nicht gefunden.', ['status' => 404]);
        $page = max(1, intval($req->get_param('page') ?: 1));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('wallet_tx') . " WHERE wallet_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", intval($w['id']), self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE), ARRAY_A);
        $more = count($rows) > self::PER_PAGE;
        $tx = array_map(function ($r) { return self::tx_payload($r); }, array_slice((array) $rows, 0, self::PER_PAGE));
        return self::out(['ok' => true, 'wallet' => self::wallet_payload($w), 'page' => $page, 'per_page' => self::PER_PAGE, 'has_more' => $more, 'tx' => $tx]);
    }

    /**
     * GET /wallet/me/tx?page=&organizer= (1.38.476) – Verlauf über alle eigenen Geldbörsen (bzw. einen Bereich),
     * neueste zuerst, seitenweise: {ok, page, per_page, has_more, balance, tx: [...]}.
     */
    public static function rest_my_tx(WP_REST_Request $req) {
        global $wpdb;
        $uid = get_current_user_id();
        $page = max(1, intval($req->get_param('page') ?: 1));
        if (!self::ready()) return self::out(['ok' => true, 'page' => $page, 'per_page' => self::PER_PAGE, 'has_more' => false, 'balance' => 0.0, 'tx' => []]);
        $filter = $req->get_param('organizer') !== null || $req->get_param('organizer_id') !== null;
        $oid = self::org_param($req);
        $ws = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('wallets') . " WHERE user_id = %d" . ($filter ? $wpdb->prepare(' AND organizer_id = %d', $oid) : ''), $uid), ARRAY_A);
        $ids = array_map('intval', array_column($ws, 'id'));
        $bal = 0; foreach ($ws as $w) $bal += intval($w['balance_paid']) + intval($w['balance_bonus']);
        if (!$ids) return self::out(['ok' => true, 'page' => $page, 'per_page' => self::PER_PAGE, 'has_more' => false, 'balance' => 0.0, 'tx' => []]);
        $in = implode(',', $ids);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('wallet_tx') . " WHERE wallet_id IN ($in) ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE), ARRAY_A);
        $more = count((array) $rows) > self::PER_PAGE;
        $tx = array_map(function ($r) { return self::tx_payload($r) + ['wallet_id' => intval($r['wallet_id'])]; }, array_slice((array) $rows, 0, self::PER_PAGE));
        return self::out(['ok' => true, 'page' => $page, 'per_page' => self::PER_PAGE, 'has_more' => $more, 'balance' => self::euro($bal), 'tx' => $tx]);
    }

    /** Personal: Recht tix_app_wallet_charge (Admin, Personal, Veranstalter); Mehr-Veranstalter-Modus mit Veranstalter. */
    public static function check_staff(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        $u = wp_get_current_user();
        if (!$u->has_cap('manage_options') && !$u->has_cap(self::CAP)) return new WP_Error('rest_forbidden', 'Keine Berechtigung für Guthaben.', ['status' => 403]);
        if (self::multi() && !$u->has_cap('manage_options') && !TIX_App_Scope::organizer_id_for_user()) return new WP_Error('rest_forbidden', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
        return true;
    }

    /** Bericht: Personal ohne die Rolle „Kasse & Bar“ (die bucht nur, User-Entscheid 1.38.442). */
    public static function check_report(WP_REST_Request $req) {
        $r = self::check_staff($req);
        if ($r !== true) return $r;
        $u = wp_get_current_user();
        if (!$u->has_cap('manage_options') && class_exists('TIX_REST_API') && TIX_REST_API::is_cashier_only($u)) {
            return new WP_Error('rest_forbidden', 'Der Bericht ist dem Veranstalter vorbehalten.', ['status' => 403]);
        }
        return true;
    }

    /** Einstellungen ändern: Admin; Mehr-Veranstalter-Modus Inhaber/Team-Admin; sonst Rolle Veranstalter. */
    public static function check_manager(WP_REST_Request $req) {
        $r = self::check_staff($req);
        if ($r !== true) return $r;
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options')) return true;
        if (self::multi()) return TIX_App_Scope::is_org_manager() ? true : new WP_Error('rest_forbidden', 'Nur der Veranstalter selbst kann das ändern.', ['status' => 403]);
        return in_array('tix_organizer', (array) $u->roles, true) ? true : new WP_Error('rest_forbidden', 'Nur der Veranstalter kann das ändern.', ['status' => 403]);
    }

    /** Bereich für das Personal: Mehr-Veranstalter-Modus = eigener Veranstalter (Admin: ?organizer=), sonst Seite. */
    private static function staff_scope(WP_REST_Request $req) {
        if (!self::multi()) return 0;
        if (current_user_can('manage_options')) return max(0, self::org_param($req));
        return TIX_App_Scope::organizer_id_for_user();
    }

    /** GET /wallet/admin/config */
    public static function rest_admin_config(WP_REST_Request $req) {
        $oid = self::staff_scope($req);
        return self::out(['ok' => true, 'organizer_id' => $oid, 'active' => self::scope_enabled($oid), 'topup_enabled' => self::topup_enabled($oid),
            'platform' => self::multi() && !$oid, 'platform_on' => self::platform_on(), 'bonus_allowed' => self::bonus_allowed($oid),
            'settings' => self::settings($oid), 'spend_order' => self::spend_order(), 'module_required' => $oid > 0,
            'can_edit' => self::check_manager($req) === true,
            // Ausgabe-Reihenfolge ist seitenweit: änderbar für Admin bzw. auf Einzel-Seiten für den Veranstalter (1.38.464)
            'spend_order_editable' => self::check_manager($req) === true && (!self::multi() || current_user_can('manage_options'))]);
    }

    /** POST /wallet/admin/config – Teil-Update der Einstellungen des Bereichs. */
    public static function rest_admin_save(WP_REST_Request $req) {
        $oid = self::staff_scope($req);
        $in = $req->get_json_params();
        if (!is_array($in)) $in = $req->get_body_params();
        // Ausgabe-Reihenfolge (seitenweit, 1.38.463 auch per REST): paid_first | bonus_first
        $raw = is_array($in) ? $in : [];
        if (array_key_exists('spend_order', $raw) || (isset($raw['settings']) && is_array($raw['settings']) && array_key_exists('spend_order', $raw['settings']))) {
            $so = (string) ($raw['spend_order'] ?? $raw['settings']['spend_order']);
            if (!in_array($so, ['paid_first', 'bonus_first'], true)) return new WP_Error('tix_invalid_field', 'spend_order: paid_first oder bonus_first.', ['status' => 400, 'field' => 'spend_order']);
            if (self::multi() && !current_user_can('manage_options')) return new WP_Error('rest_forbidden', 'Die Ausgabe-Reihenfolge gilt für die ganze Plattform und ist dem Admin vorbehalten.', ['status' => 403, 'field' => 'spend_order']);
            update_option(self::OPT_SPEND, $so, false);
        }
        if (isset($in['settings']) && is_array($in['settings'])) $in = $in['settings'];
        $s = self::sanitize_settings($in, self::stored($oid));
        if ($oid) {
            unset($s['enabled']); // evendis: an/aus über das Modul „wallet“
            update_post_meta($oid, self::META_ORG, $s);
        } else {
            if (self::multi()) unset($s['enabled']); // Plattform-Guthaben: an/aus nur über den Schalter tix_wallet_platform
            update_option(self::OPT, $s, false);
            if (self::multi()) { if (self::platform_on()) self::ensure_event(0); }
            elseif ($s['enabled']) self::ensure_event(0); else self::sync_event_off(0);
        }
        return self::rest_admin_config($req);
    }

    /** Bereich ausgeschaltet: Aufladen stoppen (Event auf Entwurf), Guthaben bleibt einlösbar. */
    private static function sync_event_off($oid) {
        $id = self::system_event_id($oid);
        if ($id && get_post_status($id) === 'publish') wp_update_post(['ID' => $id, 'post_status' => 'draft']);
    }

    /** GET /wallet/admin/report?date=&station= */
    public static function rest_report(WP_REST_Request $req) {
        $oid = self::staff_scope($req);
        $date = (string) $req->get_param('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = current_time('Y-m-d');
        return self::out(['ok' => true] + self::report($oid, $date, (string) $req->get_param('station')));
    }

    /** Bericht eines Tages (Ortszeit) für einen Bereich. */
    public static function report($oid, $date, $station = '') {
        global $wpdb;
        $empty = ['organizer_id' => intval($oid), 'date' => $date, 'station' => $station ?: null, 'by_type' => [], 'by_station' => [], 'by_staff' => [], 'open' => ['wallets' => 0, 'paid' => 0, 'bonus' => 0, 'total' => 0]];
        if (!self::ready()) return $empty;
        $W = self::t('wallets'); $T = self::t('wallet_tx');
        $tz = wp_timezone();
        $from = (new DateTime($date . ' 00:00:00', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to   = (new DateTime($date . ' 00:00:00', $tz))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // Veranstalter: eigene Geldbörsen + Cashless mit dem Plattform-Guthaben bei ihm (Buchung trägt organizer_id)
        $where = intval($oid)
            ? $wpdb->prepare("(w.organizer_id = %d OR (w.organizer_id = 0 AND t.organizer_id = %d)) AND t.created >= %s AND t.created < %s", intval($oid), intval($oid), $from, $to)
            : $wpdb->prepare("w.organizer_id = 0 AND t.created >= %s AND t.created < %s", $from, $to);
        if ($station !== '') $where .= $wpdb->prepare(' AND t.station = %s', $station);
        $base = "FROM $T t JOIN $W w ON w.id = t.wallet_id WHERE $where";
        $sum = function ($r) { return ['count' => intval($r['n']), 'paid' => self::euro($r['p']), 'bonus' => self::euro($r['b']), 'total' => self::euro(intval($r['p']) + intval($r['b']))]; };
        $by_type = [];
        foreach ((array) $wpdb->get_results("SELECT t.type AS k, COUNT(*) n, SUM(t.amount_paid) p, SUM(t.amount_bonus) b $base GROUP BY t.type", ARRAY_A) as $r) $by_type[$r['k']] = $sum($r);
        $by_station = [];
        foreach ((array) $wpdb->get_results("SELECT t.station AS k, COUNT(*) n, SUM(t.amount_paid) p, SUM(t.amount_bonus) b $base AND t.type IN ('charge','reversal') GROUP BY t.station", ARRAY_A) as $r) $by_station[] = ['station' => (string) $r['k']] + $sum($r);
        $by_staff = [];
        foreach ((array) $wpdb->get_results("SELECT t.staff_user_id AS k, COUNT(*) n, SUM(t.amount_paid) p, SUM(t.amount_bonus) b $base AND t.staff_user_id > 0 GROUP BY t.staff_user_id", ARRAY_A) as $r) {
            $u = get_userdata(intval($r['k']));
            $by_staff[] = ['staff_user_id' => intval($r['k']), 'name' => $u ? (string) $u->display_name : '#' . intval($r['k'])] + $sum($r);
        }
        $o = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n, COALESCE(SUM(balance_paid),0) p, COALESCE(SUM(balance_bonus),0) b FROM $W WHERE organizer_id = %d AND (balance_paid > 0 OR balance_bonus > 0)", intval($oid)), ARRAY_A);
        return ['by_type' => $by_type, 'by_station' => $by_station, 'by_staff' => $by_staff,
            'open' => ['wallets' => intval($o['n']), 'paid' => self::euro($o['p']), 'bonus' => self::euro($o['b']), 'total' => self::euro(intval($o['p']) + intval($o['b']))]] + $empty;
    }

    // ═══════════════ Admin ═══════════════

    public static function menu() {
        add_submenu_page('tixomat', 'Guthaben', 'Guthaben', 'manage_options', 'tix-guthaben', [__CLASS__, 'render_admin']);
    }

    public static function admin_save_settings() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_wallet_settings');
        $p = wp_unslash($_POST);
        $tiers = [];
        foreach ((array) ($p['tier_from'] ?? []) as $i => $from) {
            $tiers[] = ['from' => $from, 'bonus' => $p['tier_bonus'][$i] ?? 0, 'type' => $p['tier_type'][$i] ?? 'fixed'];
        }
        $s = self::sanitize_settings([
            'enabled' => !empty($p['enabled']), 'amounts' => $p['amounts'] ?? '', 'free_amount' => !empty($p['free_amount']),
            'free_min' => $p['free_min'] ?? 5, 'free_max' => $p['free_max'] ?? 250, 'bonus_tiers' => $tiers, 'max_balance' => $p['max_balance'] ?? 250,
            'max_topup' => $p['max_topup'] ?? 250, 'new_account_days' => $p['new_account_days'] ?? 7, 'new_account_max' => $p['new_account_max'] ?? 50,
            'approval_threshold' => $p['approval_threshold'] ?? 50, 'stations' => $p['stations'] ?? '', 'refund_policy' => $p['refund_policy'] ?? '',
        ], self::stored(0));
        update_option(self::OPT, $s, false);
        update_option(self::OPT_SPEND, ($p['spend_order'] ?? '') === 'bonus_first' ? 'bonus_first' : 'paid_first', false);
        if ($s['enabled'] && !self::multi()) self::ensure_event(0); else self::sync_event_off(0);
        wp_safe_redirect(admin_url('admin.php?page=tix-guthaben&msg=saved'));
        exit;
    }

    public static function admin_adjust() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_wallet_adjust');
        $id = intval($_POST['wallet_id'] ?? 0);
        $reason = trim(sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
        $paid = self::cents($_POST['paid'] ?? 0); $bonus = self::cents($_POST['bonus'] ?? 0);
        $msg = 'adjusted';
        if ($reason === '' || ($paid === 0 && $bonus === 0)) {
            $msg = 'adjust_missing';
        } else {
            $r = self::post_tx($id, ['type' => 'adjust', 'amount_paid' => $paid, 'amount_bonus' => $bonus, 'staff_user_id' => get_current_user_id(), 'channel' => 'admin',
                'idempotency_key' => 'admin:' . sanitize_key($_POST['nonce_key'] ?? wp_generate_uuid4()), 'allow_locked' => true, 'meta' => ['reason' => $reason]]);
            if (is_wp_error($r)) $msg = 'adjust_failed';
        }
        wp_safe_redirect(admin_url('admin.php?page=tix-guthaben&wallet=' . $id . '&msg=' . $msg));
        exit;
    }

    public static function admin_status() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_wallet_status');
        $id = intval($_POST['wallet_id'] ?? 0);
        $st = ($_POST['status'] ?? '') === 'locked' ? 'locked' : 'active';
        $w = self::get_wallet($id);
        if ($w && $w['status'] !== 'closed') self::set_status($id, $st);
        wp_safe_redirect(admin_url('admin.php?page=tix-guthaben&wallet=' . $id . '&msg=status'));
        exit;
    }

    public static function render_admin() {
        global $wpdb;
        if (!current_user_can('manage_options')) return;
        self::maybe_install();
        $msgs = ['saved' => 'Gespeichert.', 'adjusted' => 'Korrektur gebucht.', 'adjust_missing' => 'Bitte Betrag und Grund angeben.', 'adjust_failed' => 'Korrektur nicht möglich (Guthaben würde negativ).', 'status' => 'Status geändert.'];
        $m = sanitize_key($_GET['msg'] ?? '');
        $wid = intval($_GET['wallet'] ?? 0);
        echo '<div class="wrap"><h1>Guthaben</h1>';
        if (isset($msgs[$m])) echo '<div class="notice notice-' . (strpos($m, 'adjust_') === 0 ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($msgs[$m]) . '</p></div>';
        if ($wid && ($w = self::get_wallet($wid))) { self::render_wallet($w); echo '</div>'; return; }
        $alerts = get_option(self::OPT_ALERTS, []);
        if (is_array($alerts) && $alerts) {
            echo '<div class="notice notice-warning"><p><strong>Hinweise</strong></p><ul style="list-style:disc;margin-left:20px">';
            foreach (array_slice($alerts, 0, 8) as $a) echo '<li>' . esc_html(($a['at'] ?? '') . ' – ' . ($a['msg'] ?? '')) . '</li>';
            echo '</ul></div>';
        }
        $s = self::stored(0);
        $open = self::report(0, current_time('Y-m-d'))['open'];
        if (self::multi()) {
            echo '<p><strong>Mehr-Veranstalter-Modus:</strong> Guthaben gibt es hier nur je Veranstalter (Modul „Guthaben“). Aufladen bleibt aus, bis das Geld direkt an den Veranstalter geht. Einstellungen je Veranstalter über die App bzw. <code>POST /wallet/admin/config</code>.</p>';
        } else {
            echo '<p>Offenes Guthaben: <strong>' . esc_html(self::fmt(self::cents($open['total']))) . '</strong> (' . esc_html(self::fmt(self::cents($open['paid']))) . ' bezahlt, ' . esc_html(self::fmt(self::cents($open['bonus']))) . ' Bonus) in ' . intval($open['wallets']) . ' Geldbörsen.</p>';
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tix_wallet_settings'); ?><input type="hidden" name="action" value="tix_wallet_settings">
                <h2>Einstellungen</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Guthaben</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled']); ?>> Aufladen und Guthaben in der App anbieten</label><p class="description">Aus = Aufladen gestoppt; vorhandenes Guthaben bleibt einlösbar.</p></td></tr>
                    <tr><th>Aufladebeträge (€)</th><td><input type="text" name="amounts" value="<?php echo esc_attr(implode(', ', $s['amounts'])); ?>" class="regular-text"> <label><input type="checkbox" name="free_amount" value="1" <?php checked($s['free_amount']); ?>> Wunschbetrag</label> von <input type="number" step="0.01" name="free_min" value="<?php echo esc_attr($s['free_min']); ?>" style="width:80px"> bis <input type="number" step="0.01" name="free_max" value="<?php echo esc_attr($s['free_max']); ?>" style="width:80px"> €</td></tr>
                    <tr><th>Bonusstufen</th><td><?php $tiers = $s['bonus_tiers']; $tiers[] = ['from' => '', 'bonus' => '', 'type' => 'fixed']; $tiers[] = ['from' => '', 'bonus' => '', 'type' => 'fixed'];
                        foreach ($tiers as $t) : ?><p>ab <input type="number" step="0.01" name="tier_from[]" value="<?php echo esc_attr($t['from']); ?>" style="width:90px"> € → + <input type="number" step="0.01" name="tier_bonus[]" value="<?php echo esc_attr($t['bonus']); ?>" style="width:80px">
                        <select name="tier_type[]"><option value="fixed" <?php selected($t['type'], 'fixed'); ?>>€</option><option value="percent" <?php selected($t['type'], 'percent'); ?>>%</option></select></p><?php endforeach; ?>
                        <p class="description">Leere Zeilen werden ignoriert. Es gilt die höchste erreichte Stufe.</p></td></tr>
                    <tr><th>Limits</th><td>Höchstguthaben <input type="number" step="0.01" name="max_balance" value="<?php echo esc_attr($s['max_balance']); ?>" style="width:90px"> € · je Aufladung höchstens <input type="number" step="0.01" name="max_topup" value="<?php echo esc_attr($s['max_topup']); ?>" style="width:90px"> €<br>
                        Neue Konten: in den ersten <input type="number" name="new_account_days" value="<?php echo esc_attr($s['new_account_days']); ?>" style="width:60px"> Tagen höchstens <input type="number" step="0.01" name="new_account_max" value="<?php echo esc_attr($s['new_account_max']); ?>" style="width:90px"> € aufladen</td></tr>
                    <tr><th>Freigabe per Face ID</th><td>ab <input type="number" step="0.01" name="approval_threshold" value="<?php echo esc_attr($s['approval_threshold']); ?>" style="width:90px"> € <span class="description">(wirkt ab Phase 2, Bezahlen per QR)</span></td></tr>
                    <tr><th>Stationen</th><td><textarea name="stations" rows="3" class="regular-text"><?php echo esc_textarea(implode("\n", $s['stations'])); ?></textarea><p class="description">Eine je Zeile, z. B. „Bar 1“.</p></td></tr>
                    <tr><th>Erstattungsregel</th><td><textarea name="refund_policy" rows="2" class="large-text"><?php echo esc_textarea($s['refund_policy']); ?></textarea></td></tr>
                    <tr><th>Abbuchen zuerst</th><td><select name="spend_order"><option value="paid_first" <?php selected(self::spend_order(), 'paid_first'); ?>>bezahlter Anteil, dann Bonus</option><option value="bonus_first" <?php selected(self::spend_order(), 'bonus_first'); ?>>Bonus, dann bezahlter Anteil</option></select><p class="description">Steuerliche Bestätigung offen – nur nach Rücksprache ändern.</p></td></tr>
                </table>
                <p><button class="button button-primary">Speichern</button></p>
            </form>
            <?php
        }
        $q = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $W = self::t('wallets');
        $where = '1=1';
        if ($q !== '') {
            $uids = get_users(['search' => '*' . $q . '*', 'search_columns' => ['user_email', 'display_name', 'user_login'], 'fields' => 'ID', 'number' => 50]);
            $where = $uids ? 'user_id IN (' . implode(',', array_map('intval', $uids)) . ')' : (ctype_digit($q) ? 'id = ' . intval($q) : '0=1');
        }
        $rows = $wpdb->get_results("SELECT * FROM $W WHERE $where ORDER BY updated DESC LIMIT 100", ARRAY_A);
        echo '<h2>Geldbörsen</h2><form method="get"><input type="hidden" name="page" value="tix-wallet"><input type="search" name="s" value="' . esc_attr($q) . '" placeholder="E-Mail, Name oder Nr."> <button class="button">Suchen</button></form>';
        echo '<table class="widefat striped" style="margin-top:10px;max-width:1100px"><thead><tr><th>Nr.</th><th>Konto</th><th>Bereich</th><th>Guthaben</th><th>bezahlt</th><th>Bonus</th><th>Status</th><th>Zuletzt</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="8">Noch keine Geldbörsen.</td></tr>';
        foreach ((array) $rows as $w) {
            $u = intval($w['user_id']) > 0 ? get_userdata(intval($w['user_id'])) : null;
            echo '<tr><td><a href="' . esc_url(admin_url('admin.php?page=tix-guthaben&wallet=' . intval($w['id']))) . '">#' . intval($w['id']) . '</a></td><td>' . esc_html($u ? $u->user_email : 'gelöscht (anonym)') . '</td><td>' . esc_html(intval($w['organizer_id']) ? get_the_title(intval($w['organizer_id'])) : 'Seite') . '</td>'
                . '<td>' . esc_html(self::fmt(intval($w['balance_paid']) + intval($w['balance_bonus']))) . '</td><td>' . esc_html(self::fmt($w['balance_paid'])) . '</td><td>' . esc_html(self::fmt($w['balance_bonus'])) . '</td><td>' . esc_html($w['status']) . '</td><td>' . esc_html(get_date_from_gmt($w['updated'], 'd.m.Y H:i')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_wallet(array $w) {
        global $wpdb;
        $u = intval($w['user_id']) > 0 ? get_userdata(intval($w['user_id'])) : null;
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=tix-guthaben')) . '">← alle Geldbörsen</a></p>';
        echo '<h2>Geldbörse #' . intval($w['id']) . ' – ' . esc_html($u ? $u->user_email : 'gelöscht (anonym)') . '</h2>';
        echo '<p>Guthaben <strong>' . esc_html(self::fmt(intval($w['balance_paid']) + intval($w['balance_bonus']))) . '</strong> (' . esc_html(self::fmt($w['balance_paid'])) . ' bezahlt, ' . esc_html(self::fmt($w['balance_bonus'])) . ' Bonus) · Status <strong>' . esc_html($w['status']) . '</strong></p>';
        $v = self::verify(intval($w['id']));
        echo $v ? '<div class="notice notice-error"><p>Prüfung: Abweichung gefunden – ' . esc_html(wp_json_encode($v)) . '</p></div>' : '<p style="color:#15803d">✓ Saldo stimmt mit den Buchungen überein.</p>';
        if ($w['status'] !== 'closed') {
            $to = $w['status'] === 'active' ? 'locked' : 'active';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'; wp_nonce_field('tix_wallet_status');
            echo '<input type="hidden" name="action" value="tix_wallet_status"><input type="hidden" name="wallet_id" value="' . intval($w['id']) . '"><input type="hidden" name="status" value="' . $to . '"><button class="button">' . ($to === 'locked' ? 'Sperren' : 'Entsperren') . '</button></form>';
        }
        echo '<h3>Korrekturbuchung</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('tix_wallet_adjust');
        echo '<input type="hidden" name="action" value="tix_wallet_adjust"><input type="hidden" name="wallet_id" value="' . intval($w['id']) . '"><input type="hidden" name="nonce_key" value="' . esc_attr(wp_generate_uuid4()) . '">'
            . 'bezahlt <input type="number" step="0.01" name="paid" value="0" style="width:90px"> € · Bonus <input type="number" step="0.01" name="bonus" value="0" style="width:90px"> € (negativ = abziehen) · Grund <input type="text" name="reason" required class="regular-text"> <button class="button">Buchen</button></form>';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('wallet_tx') . " WHERE wallet_id = %d ORDER BY id DESC LIMIT 200", intval($w['id'])), ARRAY_A);
        echo '<h3>Verlauf</h3><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Zeit</th><th>Art</th><th>Betrag</th><th>bezahlt</th><th>Bonus</th><th>Saldo danach</th><th>Station</th><th>Personal</th><th>Bestellung</th><th>Grund</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="10">Keine Buchungen.</td></tr>';
        foreach ((array) $rows as $t) {
            $p = self::tx_payload($t, true);
            $su = $p['staff_user_id'] ? get_userdata($p['staff_user_id']) : null;
            echo '<tr><td>' . esc_html(get_date_from_gmt($t['created'], 'd.m.Y H:i:s')) . '</td><td>' . esc_html($p['title']) . '</td><td>' . esc_html(self::fmt(self::cents($p['amount']))) . '</td><td>' . esc_html(self::fmt($t['amount_paid'])) . '</td><td>' . esc_html(self::fmt($t['amount_bonus'])) . '</td><td>' . esc_html(self::fmt(self::cents($p['balance_after']))) . '</td><td>' . esc_html($p['station']) . '</td><td>' . esc_html($su ? $su->display_name : '') . '</td><td>' . ($p['order_id'] ? '#' . intval($p['order_id']) : '') . '</td><td>' . esc_html($p['reason']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}

/**
 * WP-CLI: wp tix wallet verify | charge (Test/Support)
 */
class TIX_Wallet_Balance_CLI {

    /**
     * Prüft alle Geldbörsen: Saldo = Summe der Buchungen, lückenlose Kette, nie negativ.
     *
     * ## OPTIONS
     * [--wallet=<id>]
     * : nur diese Geldbörse
     */
    public function verify($args, $assoc) {
        $v = TIX_Wallet_Balance::verify(intval($assoc['wallet'] ?? 0));
        global $wpdb;
        $n = TIX_Wallet_Balance::ready() ? intval($wpdb->get_var("SELECT COUNT(*) FROM " . TIX_Wallet_Balance::t('wallets'))) : 0;
        if ($v) {
            foreach ($v as $x) WP_CLI::warning(wp_json_encode($x));
            WP_CLI::error(count($v) . ' Abweichung(en) in ' . $n . ' Geldbörse(n).');
        }
        WP_CLI::success('Keine Abweichung (' . $n . ' Geldbörsen geprüft).');
    }

    /**
     * Bucht eine Abbuchung (nur Test und Support).
     *
     * <wallet_id>
     * : Geldbörse
     *
     * <amount>
     * : Betrag in Euro
     *
     * [--key=<idempotency>]
     * [--station=<name>]
     */
    public function charge($args, $assoc) {
        $r = TIX_Wallet_Balance::post_tx(intval($args[0]), ['type' => 'charge', 'amount' => -TIX_Wallet_Balance::cents($args[1]), 'idempotency_key' => (string) ($assoc['key'] ?? ''), 'station' => (string) ($assoc['station'] ?? ''), 'channel' => 'admin']);
        if (is_wp_error($r)) WP_CLI::error($r->get_error_message());
        WP_CLI::success('Buchung #' . $r['id'] . (!empty($r['replay']) ? ' (schon vorhanden)' : '') . ', Saldo ' . TIX_Wallet_Balance::fmt(intval($r['balance_after_paid']) + intval($r['balance_after_bonus'])));
    }
}
