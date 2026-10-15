<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Awareness_Test – Test-Modus für „Hilfe vor Ort“: unsichtbares Test-Event für Tester.
 *
 * Das Test-Event ist ein Event mit Status `private` und Meta `_tix_awareness_test` = 1. Gäste, Website,
 * /public/events, Verteilung und Sitemaps sehen es nicht, weil sie nur veröffentlichte Events kennen.
 * `/app/sos`, `/awareness/status`, `/awareness/alerts`, `/awareness/events` und `/awareness/duty`
 * behandeln es nur für Tester wie ein laufendes Event: Admins sowie Konten mit User-Meta `_tix_awareness_tester`.
 * Awareness ist am Test-Event aktiv (Team, Hilfe-Knopf); „Hilfe vor Ort“ braucht trotzdem den Schalter `alarm_live`.
 *
 * WP-CLI:
 *   wp tix awareness test-event [--minutes=60] [--testers=<login,login|ID>] [--organizer=<id>]
 *   wp tix awareness test-event --cleanup     Test-Events, ihre Hinweise, Dienste und Tester-Freigaben entfernen
 *   wp tix awareness tester <login|ID|E-Mail> [--off]
 * Admin: Tixomat → Awareness → „Test-Modus“. Abgelaufene Test-Events räumt der tägliche Cron nach 24 h selbst weg.
 */
class TIX_Awareness_Test {

    const META_TEST   = '_tix_awareness_test';
    const META_TESTER = '_tix_awareness_tester';

    public static function init() {
        add_action('admin_post_tix_awareness_test', [__CLASS__, 'admin_action']);
        add_action(TIX_Awareness_Live::CRON, [__CLASS__, 'cleanup_expired']);
        // Titel ohne „Privat:“-Vorsatz (App zeigt den Titel des Test-Events)
        add_filter('private_title_format', function ($format, $post) { return ($post && TIX_Awareness_Test::is_test_event($post->ID)) ? '%s' : $format; }, 10, 2);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('tix awareness', 'TIX_Awareness_Test_CLI');
            WP_CLI::add_command('tixomat awareness', 'TIX_Awareness_Test_CLI');
        }
    }

    public static function is_test_event($event_id) {
        return $event_id && get_post_type($event_id) === 'event' && get_post_meta($event_id, self::META_TEST, true) === '1';
    }

    public static function is_tester($user = null) {
        $user = $user ?: wp_get_current_user();
        if (!$user || !$user->ID) return false;
        return user_can($user, 'manage_options') || (bool) get_user_meta($user->ID, self::META_TESTER, true);
    }

    /** Test-Event für den aktuellen Nutzer sichtbar (privat, Test-Markierung, Tester)? */
    public static function visible($event_id) {
        return self::is_test_event($event_id) && get_post_status($event_id) === 'private' && self::is_tester();
    }

    /** IDs aller Test-Events. */
    public static function event_ids() {
        return array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 50, 'fields' => 'ids',
            'meta_key' => self::META_TEST, 'meta_value' => '1', 'suppress_filters' => true]));
    }

    /** Laufendes Test-Event (Fenster offen) für den aktuellen Tester, sonst 0. */
    public static function running_for_current_user() {
        if (!self::is_tester()) return 0;
        $now = current_time('timestamp');
        foreach (self::event_ids() as $id) {
            if (get_post_status($id) !== 'private') continue;
            $w = TIX_Awareness_Live::window($id);
            if ($w && $now >= $w['opens'] && $now <= $w['closes']) return $id;
        }
        return 0;
    }

    /** Test-Event anlegen: läuft ab jetzt für $minutes Minuten. Gibt die Event-ID zurück. */
    public static function create($minutes = 60, $organizer_id = 0) {
        $minutes = max(10, min(720, intval($minutes)));
        $start = current_time('timestamp');
        $end = $start + $minutes * MINUTE_IN_SECONDS;
        $id = wp_insert_post([
            'post_type'   => 'event',
            'post_status' => 'private',
            'post_title'  => 'Awareness-Test (nur für Tester)',
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($id)) return $id;
        $meta = [
            self::META_TEST                => '1',
            '_tix_date_start'              => gmdate('Y-m-d', $start),
            '_tix_time_start'              => gmdate('H:i', $start),
            '_tix_date_end'                => gmdate('Y-m-d', $end),
            '_tix_time_end'                => gmdate('H:i', $end),
            '_tix_location'                => 'Test',
            '_tix_tickets_enabled'         => '0',
            TIX_Awareness::META_MODE       => 'custom',
            TIX_Awareness::META            => TIX_Awareness::sanitize(['enabled' => 1, 'team' => 1, 'alarm_enabled' => 1, 'sign' => 'Test-Weste', 'point' => 'Test-Punkt']),
        ];
        if (intval($organizer_id)) $meta['_tix_organizer_id'] = intval($organizer_id);
        foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
        TIX_Awareness::flush();
        return intval($id);
    }

    /** Test-Events samt Hinweisen, Feedback und Diensten entfernen; optional auch die Tester-Freigaben. */
    public static function cleanup($testers = true, array $only = []) {
        global $wpdb;
        $ids = $only ?: self::event_ids();
        $n = 0;
        foreach ($ids as $id) {
            if (!self::is_test_event($id)) continue;
            foreach (['tix_awareness_alerts', 'tix_awareness_feedback'] as $t) {
                $table = $wpdb->prefix . $t;
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) $wpdb->delete($table, ['event_id' => $id]);
            }
            foreach (get_users(['meta_key' => TIX_Awareness_Live::META_DUTY, 'fields' => 'ID', 'number' => 500]) as $uid) {
                $d = get_user_meta($uid, TIX_Awareness_Live::META_DUTY, true);
                if (is_array($d) && intval($d['event_id'] ?? 0) === intval($id)) delete_user_meta($uid, TIX_Awareness_Live::META_DUTY);
            }
            wp_delete_post($id, true);
            $n++;
        }
        $t = 0;
        if ($testers) {
            foreach (get_users(['meta_key' => self::META_TESTER, 'fields' => 'ID', 'number' => 500]) as $uid) { delete_user_meta($uid, self::META_TESTER); $t++; }
        }
        TIX_Awareness::flush();
        return ['events' => $n, 'testers' => $t];
    }

    /** Cron: Test-Events, deren Fenster seit 24 h zu ist, entfernen (Tester-Freigaben bleiben). */
    public static function cleanup_expired() {
        $now = current_time('timestamp');
        $old = [];
        foreach (self::event_ids() as $id) {
            $w = TIX_Awareness_Live::window($id);
            if (!$w || $w['closes'] < $now - DAY_IN_SECONDS) $old[] = $id;
        }
        if ($old) self::cleanup(false, $old);
    }

    public static function set_tester($user, $on = true) {
        if (!$user || !$user->ID) return false;
        if ($on) update_user_meta($user->ID, self::META_TESTER, 1);
        else delete_user_meta($user->ID, self::META_TESTER);
        return true;
    }

    public static function find_user($ref) {
        $ref = trim((string) $ref);
        if ($ref === '') return null;
        if (ctype_digit($ref)) return get_user_by('id', intval($ref)) ?: null;
        return (is_email($ref) ? get_user_by('email', $ref) : get_user_by('login', $ref)) ?: null;
    }

    // ── Admin (Seite „Awareness“) ──

    public static function render_admin() {
        $ids = self::event_ids();
        $testers = get_users(['meta_key' => self::META_TESTER, 'number' => 50]);
        $now = current_time('timestamp');
        $live = class_exists('TIX_Awareness_Live') && TIX_Awareness_Live::flags()['alarm_live'];
        ?>
        <hr style="margin:28px 0 8px;">
        <h2>Test-Modus „Hilfe vor Ort“</h2>
        <p class="description">Legt ein unsichtbares Test-Event an, das ab jetzt läuft (privat: nicht in der App-Liste, nicht auf der Website). Nur Tester sehen es in der App unter SOS bzw. im Awareness-Team: Admins und die hier eingetragenen Konten. Zum Testen: Handy 1 mit Tester-Konto im Awareness-Team „im Dienst“, Handy 2 mit Tester-Konto unter SOS „Hilfe vor Ort“.<?php if (!$live) echo ' <strong>„Hilfe vor Ort“ ist auf dieser Seite noch aus – der Test braucht den Schalter oben.</strong>'; ?></p>
        <?php if (!empty($_GET['awtest'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['awtest']))); ?></p></div><?php endif; ?>
        <?php if ($ids) : ?><ul><?php foreach ($ids as $id) : $w = TIX_Awareness_Live::window($id); ?>
            <li><strong>#<?php echo intval($id); ?></strong> – <?php echo $w ? esc_html(gmdate('d.m. H:i', $w['start']) . ' bis ' . gmdate('H:i', $w['end'])) : ''; ?> <?php echo ($w && $now <= $w['closes']) ? '<span style="color:#1a7f37">läuft</span>' : '<span style="color:#888">vorbei</span>'; ?></li>
        <?php endforeach; ?></ul><?php endif; ?>
        <p>Tester: <?php echo $testers ? esc_html(implode(', ', array_map(function ($u) { return $u->user_login . ' (' . $u->user_email . ')'; }, $testers))) : 'nur Admins'; ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <?php wp_nonce_field('tix_awareness_test'); ?><input type="hidden" name="action" value="tix_awareness_test">
            <input type="text" name="tester" placeholder="Benutzername oder E-Mail" style="width:240px">
            <button class="button" name="do" value="add_tester">Als Tester eintragen</button>
            <select name="minutes"><option value="60">60 Minuten</option><option value="120">2 Stunden</option><option value="240">4 Stunden</option></select>
            <button class="button button-primary" name="do" value="start">Test-Event starten</button>
            <button class="button" name="do" value="cleanup" onclick="return confirm('Test-Events, ihre Hinweise und alle Tester-Freigaben entfernen?')">Test beenden und aufräumen</button>
        </form>
        <?php
    }

    public static function admin_action() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_awareness_test');
        $do = sanitize_key((string) ($_POST['do'] ?? ''));
        $msg = '';
        if ($do === 'add_tester') {
            $u = self::find_user(wp_unslash($_POST['tester'] ?? ''));
            $msg = $u && self::set_tester($u) ? 'Tester eingetragen: ' . $u->user_login : 'Konto nicht gefunden.';
        } elseif ($do === 'start') {
            $id = self::create(intval($_POST['minutes'] ?? 60));
            $msg = is_wp_error($id) ? $id->get_error_message() : 'Test-Event #' . $id . ' läuft.';
        } elseif ($do === 'cleanup') {
            $r = self::cleanup(true);
            $msg = 'Aufgeräumt: ' . $r['events'] . ' Test-Event(s), ' . $r['testers'] . ' Tester-Freigabe(n).';
        }
        wp_safe_redirect(add_query_arg('awtest', rawurlencode($msg), admin_url('admin.php?page=tix-awareness')));
        exit;
    }
}

if (defined('WP_CLI') && WP_CLI) {
    /** Test-Modus „Hilfe vor Ort“. */
    class TIX_Awareness_Test_CLI {

        /**
         * Legt ein unsichtbares Test-Event an (läuft ab jetzt) bzw. räumt auf.
         *
         * ## OPTIONS
         * [--minutes=<n>]
         * : Laufzeit in Minuten (10–720, Vorgabe 60)
         * [--testers=<liste>]
         * : Konten (Login, E-Mail oder ID, mit Komma), die das Test-Event sehen (Admins immer)
         * [--organizer=<id>]
         * : Veranstalter des Test-Events (Mehr-Veranstalter-Modus)
         * [--cleanup]
         * : Test-Events, Hinweise, Dienste und Tester-Freigaben entfernen
         *
         * @subcommand test-event
         */
        public function test_event($args, $assoc) {
            if (!empty($assoc['cleanup'])) {
                $r = TIX_Awareness_Test::cleanup(true);
                WP_CLI::success("Aufgeräumt: {$r['events']} Test-Event(s), {$r['testers']} Tester-Freigabe(n).");
                return;
            }
            foreach (array_filter(array_map('trim', explode(',', (string) ($assoc['testers'] ?? '')))) as $ref) {
                $u = TIX_Awareness_Test::find_user($ref);
                if (!$u) WP_CLI::error("Konto nicht gefunden: $ref");
                TIX_Awareness_Test::set_tester($u);
                WP_CLI::log("Tester: {$u->user_login}");
            }
            $id = TIX_Awareness_Test::create(intval($assoc['minutes'] ?? 60), intval($assoc['organizer'] ?? 0));
            if (is_wp_error($id)) WP_CLI::error($id->get_error_message());
            $w = TIX_Awareness_Live::window($id);
            WP_CLI::success("Test-Event #$id läuft bis " . gmdate('H:i', $w['end']) . (TIX_Awareness_Live::flags()['alarm_live'] ? '.' : ' – Achtung: „Hilfe vor Ort“ (alarm_live) ist auf dieser Seite aus.'));
        }

        /**
         * Test-Freigabe für ein Konto setzen bzw. entfernen.
         *
         * ## OPTIONS
         * <konto>
         * : Login, E-Mail oder ID
         * [--off]
         * : Freigabe entfernen
         */
        public function tester($args, $assoc) {
            $u = TIX_Awareness_Test::find_user($args[0] ?? '');
            if (!$u) WP_CLI::error('Konto nicht gefunden.');
            TIX_Awareness_Test::set_tester($u, empty($assoc['off']));
            WP_CLI::success(empty($assoc['off']) ? "Tester: {$u->user_login}" : "Keine Test-Freigabe mehr: {$u->user_login}");
        }
    }
}
