<?php
/**
 * Zentraler Migrations-Lauf (Phase 0, 1.38.767).
 *
 * Bisher liefen die Versions-Migrationen nur auf `admin_init`, also erst wenn jemand das WP-Backend öffnete.
 * Seiten, die nur per REST, App, Cron oder WP-CLI genutzt werden (Weg 1), blieben auf altem Stand.
 * Jetzt läuft alles beim ersten Aufruf nach einem Update, egal welcher Art:
 * - Hook `init` (spät, damit alle Module geladen sind)
 * - unter Sperre: ein Lauf zugleich, eine hängende Sperre gilt nach 10 Minuten als frei
 * - Ergebnis je Schritt in `tix_migrate_log`; nach einem Fehler höchstens alle 15 Minuten ein neuer Versuch
 *
 * WP-CLI (Zeile `ERGEBNIS <json>` wie bei golive, für den Kunden-Dienst):
 *   wp tixomat migrate status
 *   wp tixomat migrate run [--force]
 */
if (!defined('ABSPATH')) exit;

class TIX_Migrations {

    const OPT_LOCK  = 'tix_migrate_lock';
    const OPT_LOG   = 'tix_migrate_log';
    const LOCK_TTL  = 600;
    const RETRY_TTL = 900;

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_run'], 999);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat migrate', [__CLASS__, 'cli']);
    }

    public static function pending() {
        return version_compare((string) get_option('tix_db_version', '0'), TIXOMAT_VERSION, '<');
    }

    public static function maybe_run() {
        if (!self::pending()) return;
        $last = self::last();
        if ($last && empty($last['ok']) && ($last['version'] ?? '') === TIXOMAT_VERSION && (time() - intval($last['ts'] ?? 0)) < self::RETRY_TTL) return;
        self::run('auto');
    }

    /** Schritte in fester Reihenfolge; jeder ist einzeln wiederholbar (idempotent). */
    private static function steps($stored) {
        return [
            'versionen'          => function () use ($stored) { if (function_exists('tix_version_migrations')) tix_version_migrations($stored); },
            'ki_schluessel'      => function () {
                $s = get_option('tix_settings', []);
                if (is_array($s) && !empty($s['ai_guard_api_key']) && empty($s['anthropic_api_key'])) { $s['anthropic_api_key'] = $s['ai_guard_api_key']; update_option('tix_settings', $s); }
            },
            'lizenz_server'      => function () { if (class_exists('TIX_License_Server') && TIX_License_Server::enabled()) TIX_License_Server::maybe_install(); },
            'rechnungen_alt'     => function () { if (class_exists('TIX_Invoicing')) TIX_Invoicing::maybe_migrate_legacy_settings(); },
            'support_nachrichten'=> function () { if (class_exists('TIX_Support')) TIX_Support::migrate_messages_storage(true); },
            'support_mail_zeit'  => function () { if (class_exists('TIX_Support_Mail')) TIX_Support_Mail::ensure_schedule(); },
            'bestand'            => function () { if (class_exists('TIX_Stock')) { TIX_Stock::install(); TIX_Stock::backfill(); } },
        ];
    }

    /** Sperre nehmen (atomar über add_option). */
    private static function lock() {
        if (add_option(self::OPT_LOCK, time(), '', 'no')) return true;
        $since = intval(get_option(self::OPT_LOCK));
        if ($since && time() - $since < self::LOCK_TTL) return false;
        delete_option(self::OPT_LOCK);
        return add_option(self::OPT_LOCK, time(), '', 'no');
    }

    /**
     * Lauf ausführen. $force: auch wenn die Version schon passt (alle Schritte, Versions-Schritte ab der gespeicherten Version).
     * Rückgabe: Protokoll-Eintrag oder ['ok' => false, 'locked' => true].
     */
    public static function run($source = 'auto', $force = false) {
        if (!$force && !self::pending()) return ['ok' => true, 'skipped' => true, 'version' => TIXOMAT_VERSION];
        if (!self::lock()) return ['ok' => false, 'locked' => true, 'since' => intval(get_option(self::OPT_LOCK))];
        $stored = (string) get_option('tix_db_version', '0');
        $t0 = microtime(true);
        $res = [];
        $ok = true;
        try {
            foreach (self::steps($stored) as $key => $fn) {
                try {
                    $r = $fn();
                    if (is_wp_error($r)) { $ok = false; $res[$key] = 'fehler: ' . $r->get_error_message(); }
                    else $res[$key] = 'ok';
                } catch (Throwable $e) {
                    $ok = false;
                    $res[$key] = 'fehler: ' . mb_substr($e->getMessage(), 0, 300);
                }
            }
            if ($ok) update_option('tix_db_version', TIXOMAT_VERSION);
        } finally {
            delete_option(self::OPT_LOCK);
        }
        $entry = ['version' => TIXOMAT_VERSION, 'from' => $stored, 'ok' => $ok, 'source' => sanitize_key($source), 'ts' => time(),
                  'at' => gmdate('c'), 'ms' => intval(round((microtime(true) - $t0) * 1000)), 'steps' => $res];
        $log = get_option(self::OPT_LOG, []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, $entry);
        update_option(self::OPT_LOG, array_slice($log, 0, 10), false);
        if (!$ok && function_exists('error_log')) error_log('[tixomat] Migration ' . TIXOMAT_VERSION . ' unvollständig: ' . wp_json_encode($res));
        return $entry;
    }

    public static function last() {
        $log = get_option(self::OPT_LOG, []);
        return is_array($log) && $log ? $log[0] : null;
    }

    public static function status() {
        $lock = intval(get_option(self::OPT_LOCK));
        return [
            'plugin_version' => TIXOMAT_VERSION,
            'db_version'     => (string) get_option('tix_db_version', '0'),
            'pending'        => self::pending(),
            'locked'         => $lock > 0 && time() - $lock < self::LOCK_TTL,
            'last'           => self::last(),
        ];
    }

    /**
     * Zentraler Migrations-Lauf.
     *
     * ## OPTIONS
     *
     * <aktion>
     * : status | run
     *
     * [--force]
     * : Bei run: auch ausführen, wenn die Version schon passt.
     */
    public static function cli($args, $assoc) {
        $a = $args[0] ?? 'status';
        if ($a === 'status') { WP_CLI::line('ERGEBNIS ' . wp_json_encode(self::status(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); return; }
        if ($a === 'run') {
            $r = self::run('cli', !empty($assoc['force']));
            WP_CLI::line('ERGEBNIS ' . wp_json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (empty($r['ok'])) WP_CLI::halt(1);
            return;
        }
        WP_CLI::error('tix_invalid_field Aktion: status | run');
    }
}
