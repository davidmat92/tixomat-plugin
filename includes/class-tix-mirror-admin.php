<?php
/**
 * Tixomat – Datenspiegel: Status-Seite (Tixomat → Datenspiegel)
 *
 * Spiegel an/aus, Zugang, Rückstand, letzter Abgleich, Fehler, Erstbefüllung.
 * Der Schlüssel wird nie angezeigt; ein leeres Feld beim Speichern behält ihn.
 *
 * @since Datenspiegel Paket 1 (Version vergibt die Zentrale)
 */

if (!defined('ABSPATH')) exit;

class TIX_Mirror_Admin {

    const SLUG = 'tix-mirror';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 60);
        add_action('admin_post_tix_mirror_save', [__CLASS__, 'save']);
        add_action('admin_post_tix_mirror_action', [__CLASS__, 'action']);
    }

    public static function menu() {
        add_submenu_page('tixomat', 'Datenspiegel', 'Datenspiegel', 'manage_options', self::SLUG, [__CLASS__, 'render']);
    }

    private static function back($msg) {
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_mirror_save');
        $new = [
            'enabled'     => !empty($_POST['enabled']) ? 1 : 0,
            'alert_email' => sanitize_email(wp_unslash($_POST['alert_email'] ?? '')),
            'site'        => sanitize_text_field(wp_unslash($_POST['site'] ?? '')),
        ];
        if (!defined('TIX_MIRROR_URL')) {
            $url = esc_url_raw(trim(wp_unslash($_POST['url'] ?? '')), ['https', 'http']);
            $new['url'] = $url;
        }
        if (!defined('TIX_MIRROR_KEY')) {
            $key = trim((string) wp_unslash($_POST['key'] ?? ''));
            if (!empty($_POST['key_clear'])) $new['key'] = '';
            elseif ($key !== '') $new['key'] = preg_replace('/\s+/', '', $key);
        }
        TIX_Mirror::save_settings($new);
        TIX_Mirror::maybe_schedule();
        self::back('Gespeichert.');
    }

    public static function action() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_mirror_action');
        $do = sanitize_key($_POST['do'] ?? '');
        switch ($do) {
            case 'test':
                $r = TIX_Mirror_Client::test();
                self::back(($r['success'] ? '✓ ' : '✗ ') . $r['message']);
            case 'run':
                if (!TIX_Mirror::active()) self::back('Spiegel ist aus oder nicht eingerichtet.');
                TIX_Mirror::cron_run();
                $st = TIX_Mirror::state();
                self::back('Abgleich gelaufen. Rückstand: ' . TIX_Mirror::pending_count() . ($st['last_error_at'] >= time() - 5 ? ' · Fehler: ' . $st['last_error'] : ''));
            case 'backfill':
                TIX_Mirror_Backfill::start();
                self::back('Erstbefüllung gestartet. Sie läuft im Hintergrund und kann jederzeit weiterlaufen.');
            case 'backfill_stop':
                TIX_Mirror_Backfill::stop();
                self::back('Erstbefüllung angehalten. „Fortsetzen“ macht an derselben Stelle weiter.');
            case 'backfill_resume':
                TIX_Mirror_Backfill::resume();
                self::back('Erstbefüllung läuft weiter.');
            case 'retry':
                global $wpdb;
                $wpdb->query($wpdb->prepare("UPDATE " . TIX_Mirror::table() . " SET next_try = %s", gmdate('Y-m-d H:i:s')));
                self::back('Alle Einträge sind wieder fällig.');
        }
        self::back('Unbekannte Aktion.');
    }

    private static function ago($ts) {
        if (!$ts) return 'noch nie';
        $d = time() - (int) $ts;
        if ($d < 90) return 'vor ' . max(1, $d) . ' s';
        if ($d < 5400) return 'vor ' . round($d / 60) . ' min';
        return wp_date('d.m.Y H:i', (int) $ts);
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        TIX_Mirror::maybe_install();
        $s   = TIX_Mirror::settings();
        $st  = TIX_Mirror::state();
        $cfg = TIX_Mirror_Client::configured();
        $on  = TIX_Mirror::active();
        $pending = TIX_Mirror::pending_count();
        $stats = TIX_Mirror::stats();
        $bf  = TIX_Mirror_Backfill::status();
        $msg = isset($_GET['msg']) ? sanitize_text_field(wp_unslash($_GET['msg'])) : '';
        $healthy = $on && (int) $st['fail_runs'] === 0 && (time() - (int) $st['last_run']) < 10 * MINUTE_IN_SECONDS;
        $chip = !$cfg ? ['Nicht eingerichtet', '#6b7280'] : (!$on ? ['Aus', '#6b7280'] : ($healthy ? ['Aktuell', '#15803d'] : ['Rückstand / Fehler', '#b45309']));
        ?>
        <div class="wrap">
            <h1>Datenspiegel <span style="font-size:13px;vertical-align:middle;background:<?php echo esc_attr($chip[1]); ?>;color:#fff;border-radius:10px;padding:2px 10px;margin-left:8px;"><?php echo esc_html($chip[0]); ?></span></h1>
            <?php if ($msg !== '') : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($msg); ?></p></div><?php endif; ?>
            <p style="max-width:780px">Zweite Speicherung aller Events, Kategorien, Kunden, Bestellungen, Tickets, Check-ins und Stornos in Supabase (EU). WordPress bleibt führend; der Spiegel liest nur mit. Jede Änderung geht über den Postausgang und wird im Hintergrund gebündelt gesendet. Ein Fehler hier hält nie eine Bestellung oder einen Check-in auf. Nicht gespiegelt: Passwörter, Login- und Push-Tokens, Awareness-Meldungen, IBAN im Klartext.</p>

            <?php if (!empty($s['legacy_url'])) : ?>
                <div class="notice notice-warning"><p>Die alte Ticket-Anbindung (<code><?php echo esc_html(wp_parse_url($s['legacy_url'], PHP_URL_HOST)); ?></code>, nur Tickets) ist abgelöst und schreibt nichts mehr. Das alte Projekt bleibt unberührt; der Spiegel braucht das neue Projekt.</p></div>
            <?php endif; ?>

            <h2>Stand</h2>
            <table class="widefat striped" style="max-width:780px">
                <tbody>
                    <tr><td style="width:240px">Seite im Spiegel</td><td><code><?php echo esc_html(TIX_Mirror::site()); ?></code></td></tr>
                    <tr><td>Rückstand im Postausgang</td><td><strong><?php echo (int) $pending; ?></strong>
                        <?php foreach ($stats['by_entity'] as $b) echo ' · ' . esc_html($b['entity']) . ' ' . (int) $b['n'] . ((int) $b['failing'] ? ' (' . (int) $b['failing'] . ' mit Fehler)' : ''); ?></td></tr>
                    <tr><td>Letzter Lauf</td><td><?php echo esc_html(self::ago($st['last_run'])); ?></td></tr>
                    <tr><td>Letzter fehlerfreier Abgleich</td><td><?php echo esc_html(self::ago($st['last_ok'])); ?></td></tr>
                    <tr><td>Gesendet seit Einrichtung</td><td><?php echo (int) $st['sent']; ?></td></tr>
                    <tr><td>Letzter Fehler</td><td><?php echo $st['last_error'] !== '' ? esc_html($st['last_error']) . ' <span style="color:#6b7280">(' . esc_html(self::ago($st['last_error_at'])) . ((int) $st['last_ok'] > (int) $st['last_error_at'] ? ', seitdem fehlerfrei' : '') . ')</span>' : '–'; ?></td></tr>
                    <tr><td>Erstbefüllung</td><td><?php echo esc_html($bf['label']); ?></td></tr>
                    <tr><td>Nächster Hintergrund-Lauf</td><td><?php $n = wp_next_scheduled(TIX_Mirror::CRON); echo $n ? esc_html(wp_date('H:i:s', $n)) : '–'; ?></td></tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap">
                <?php wp_nonce_field('tix_mirror_action'); ?><input type="hidden" name="action" value="tix_mirror_action">
                <button class="button" name="do" value="test">Verbindung testen</button>
                <button class="button" name="do" value="run" <?php disabled(!$on); ?>>Jetzt abgleichen</button>
                <?php if ($bf['state'] === 'running') : ?>
                    <button class="button" name="do" value="backfill_stop">Erstbefüllung anhalten</button>
                <?php elseif ($bf['state'] === 'paused') : ?>
                    <button class="button button-primary" name="do" value="backfill_resume" <?php disabled(!$on); ?>>Erstbefüllung fortsetzen</button>
                <?php endif; ?>
                <button class="button" name="do" value="backfill" <?php disabled(!$on); ?> onclick="return confirm('Kompletten Bestand dieser Seite neu in den Spiegel schicken?');"><?php echo $bf['state'] === 'done' ? 'Erstbefüllung erneut' : 'Erstbefüllung starten'; ?></button>
                <?php if ($stats['errors']) : ?><button class="button" name="do" value="retry">Fehlgeschlagene sofort wiederholen</button><?php endif; ?>
            </form>

            <?php if ($stats['errors']) : ?>
                <h2>Fehler im Postausgang</h2>
                <table class="widefat striped" style="max-width:1000px">
                    <thead><tr><th>Typ</th><th>ID</th><th>Versuche</th><th>Fehler</th><th>Zuletzt</th></tr></thead>
                    <tbody><?php foreach ($stats['errors'] as $e) : ?>
                        <tr><td><?php echo esc_html($e['entity']); ?></td><td><?php echo esc_html($e['object_id']); ?></td><td><?php echo (int) $e['attempts']; ?></td><td><?php echo esc_html($e['last_error']); ?></td><td><?php echo esc_html(get_date_from_gmt($e['updated_at'], 'd.m.Y H:i')); ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table>
            <?php endif; ?>

            <h2>Einstellungen</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tix_mirror_save'); ?><input type="hidden" name="action" value="tix_mirror_save">
                <table class="form-table" role="presentation" style="max-width:780px">
                    <tr><th>Spiegel</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> Diese Seite spiegeln</label>
                        <p class="description">Standard ist an. Aus heißt: nichts erfassen, nichts senden. Lizenzkunden nur mit AVV.</p></td></tr>
                    <tr><th>Projekt-URL</th><td>
                        <?php if (defined('TIX_MIRROR_URL')) : ?><code><?php echo esc_html(TIX_Mirror_Client::url()); ?></code> <span class="description">(aus wp-config)</span>
                        <?php else : ?><input type="url" name="url" value="<?php echo esc_attr($s['url']); ?>" class="regular-text" placeholder="https://xxxx.supabase.co"><?php endif; ?></td></tr>
                    <tr><th>Service-Schlüssel</th><td>
                        <?php if (defined('TIX_MIRROR_KEY')) : ?><span class="description">aus wp-config (TIX_MIRROR_KEY)</span>
                        <?php else : ?>
                            <input type="password" name="key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['key'] !== '' ? '•••••••• gespeichert – leer lassen zum Behalten' : 'service_role / sb_secret_…'; ?>">
                            <?php if ($s['key'] !== '') : ?><label style="margin-left:8px"><input type="checkbox" name="key_clear" value="1"> entfernen</label><?php endif; ?>
                            <p class="description">Nur der Server kennt ihn. Besser in wp-config: <code>define('TIX_MIRROR_URL', …); define('TIX_MIRROR_KEY', …);</code></p>
                        <?php endif; ?></td></tr>
                    <tr><th>Kennung der Seite</th><td><input type="text" name="site" value="<?php echo esc_attr($s['site']); ?>" class="regular-text" placeholder="<?php echo esc_attr(TIX_Mirror::site()); ?>">
                        <p class="description">Leer = Domain. Nur ändern, wenn die Seite umzieht und ihre Daten unter der alten Kennung bleiben sollen.</p></td></tr>
                    <tr><th>Alarm an</th><td><input type="email" name="alert_email" value="<?php echo esc_attr($s['alert_email']); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                        <p class="description">Mail, wenn Einträge <?php echo (int) TIX_Mirror::ALERT_TRIES; ?>-mal scheitern (höchstens alle 6 Stunden).</p></td></tr>
                </table>
                <p><button class="button button-primary">Speichern</button></p>
            </form>
        </div>
        <?php
    }
}
