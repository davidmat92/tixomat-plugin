<?php
/**
 * Partner-Verzeichnis für geteilte Events (Plattform-Seite, z. B. evendis.de)
 * und gemeinsame Hilfen für beide Seiten der Partner-Anbindung.
 *
 * Plattform (Empfänger der Event-Verteilung):
 *   Option `tix_partners` = [ id => [
 *       id, name, api_base (REST-Basis der Quelle, …/wp-json/tixomat/v1),
 *       key_in  (Quelle → Plattform: Event-Verteilung + Webhooks),
 *       key_out (Plattform → Quelle: Partner-API),
 *       organizer_id (zugeordneter tix_organizer auf der Plattform),
 *       sales_enabled (Verkauf über die Plattform erlaubt), terms_url, created
 *   ] ]
 *   Verwaltung: Tixomat → Partner (nur Admins).
 *
 * Quelle (Sender, z. B. kitchenklub.de):
 *   Einstellungen `syndication_api_url` (Plattform), `syndication_api_key` (= key_in),
 *   `partner_api_enabled` + `partner_api_key` (= key_out). Die Plattform meldet sich mit
 *   `X-Tix-Partner-Id` = Hostname der Plattform und `X-Tix-Partner-Key`.
 *
 * Alles ist aus, bis es konfiguriert ist: leeres Verzeichnis = keine Vermittlung,
 * Partner-API ohne Schlüssel = gesperrt.
 */
if (!defined('ABSPATH')) exit;

class TIX_Partners {

    const OPTION = 'tix_partners';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 40);
        add_action('admin_post_tix_partner_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_tix_partner_delete', [__CLASS__, 'handle_delete']);
    }

    // ──────────────────────────────────────────
    //  Verzeichnis (Plattform)
    // ──────────────────────────────────────────

    private static function defaults() {
        return [
            'id'            => '',
            'name'          => '',
            'api_base'      => '',
            'key_in'        => '',
            'key_out'       => '',
            'organizer_id'  => 0,
            'sales_enabled' => 0,
            'terms_url'     => '',
            'created'       => '',
        ];
    }

    public static function all() {
        $all = get_option(self::OPTION, []);
        if (!is_array($all)) return [];
        $out = [];
        foreach ($all as $id => $p) {
            if (is_array($p)) $out[$id] = wp_parse_args($p, self::defaults());
        }
        return $out;
    }

    public static function get($id) {
        $id = sanitize_key((string) $id);
        if ($id === '') return null;
        $all = self::all();
        return $all[$id] ?? null;
    }

    public static function generate_key($prefix) {
        return $prefix . wp_generate_password(32, false);
    }

    /** Kurzer, nicht umkehrbarer Fingerabdruck eines Schlüssels (Zuordnung von Webhooks). */
    public static function kid($key) {
        return substr(hash('sha256', (string) $key), 0, 16);
    }

    /** Partner zu einem eingehenden Schlüssel (Event-Verteilung). */
    public static function find_by_key($key) {
        $key = (string) $key;
        if (strlen($key) < 20) return null;
        foreach (self::all() as $p) {
            if ($p['key_in'] !== '' && hash_equals($p['key_in'], $key)) return $p;
        }
        return null;
    }

    /** Partner zum Fingerabdruck seines eingehenden Schlüssels (Webhooks). */
    public static function find_by_kid($kid) {
        $kid = (string) $kid;
        if (strlen($kid) !== 16) return null;
        foreach (self::all() as $p) {
            if ($p['key_in'] !== '' && hash_equals(self::kid($p['key_in']), $kid)) return $p;
        }
        return null;
    }

    /** Darf die Plattform für diesen Partner verkaufen (Schalter + Zugangsdaten)? */
    public static function sales_ready($p) {
        return is_array($p) && !empty($p['sales_enabled']) && $p['api_base'] !== '' && strlen($p['key_out']) >= 20;
    }

    /** Hostname dieser Seite – Kennung der Plattform gegenüber der Quelle. */
    public static function platform_id() {
        return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    // ──────────────────────────────────────────
    //  Signatur (Webhooks Quelle → Plattform)
    // ──────────────────────────────────────────

    public static function sign($body, $key, $ts = null) {
        $ts = $ts ?: time();
        return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, (string) $key);
    }

    public static function verify($body, $header, $key, $tolerance = 300) {
        if (!preg_match('/t=(\d+),v1=([a-f0-9]{64})/', (string) $header, $m)) return false;
        if (abs(time() - intval($m[1])) > $tolerance) return false;
        return hash_equals(hash_hmac('sha256', $m[1] . '.' . $body, (string) $key), $m[2]);
    }

    // ──────────────────────────────────────────
    //  Quelle: Einstellungen der Partner-API
    // ──────────────────────────────────────────

    /** Partner-API auf dieser Seite freigeschaltet (Schalter + Schlüssel + Plattform)? */
    public static function source_api_enabled() {
        return !empty(tix_get_settings('partner_api_enabled'))
            && strlen((string) tix_get_settings('partner_api_key')) >= 20
            && self::source_platform_id() !== '';
    }

    /** Erwartete Kennung der Plattform = Hostname aus `syndication_api_url`. */
    public static function source_platform_id() {
        return strtolower((string) wp_parse_url((string) tix_get_settings('syndication_api_url'), PHP_URL_HOST));
    }

    // ──────────────────────────────────────────
    //  Admin: Tixomat → Partner
    // ──────────────────────────────────────────

    public static function admin_menu() {
        add_submenu_page('tixomat', 'Partner (geteilte Events)', 'Partner', 'manage_options', 'tix-partners', [__CLASS__, 'render_page']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        $all  = self::all();
        $edit = isset($_GET['edit']) ? self::get(sanitize_key($_GET['edit'])) : null;
        $new  = isset($_GET['new']);
        $orgs = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']);
        $msg  = sanitize_key($_GET['msg'] ?? '');
        $receive_on = !empty(tix_get_settings('syndication_receive_enabled'));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Partner (geteilte Events)</h1>
            <?php if (!$edit && !$new): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=tix-partners&new=1')); ?>" class="page-title-action">Partner anlegen</a>
            <?php endif; ?>
            <hr class="wp-header-end">
            <?php if ($msg === 'saved'): ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <?php if ($msg === 'deleted'): ?><div class="notice notice-success is-dismissible"><p>Partner entfernt.</p></div><?php endif; ?>
            <?php if ($msg === 'invalid'): ?><div class="notice notice-error"><p>Bitte Kennung, Namen und API-Basis angeben.</p></div><?php endif; ?>
            <?php if (!$receive_on): ?>
                <div class="notice notice-warning"><p>Der Empfang verteilter Events ist in den Einstellungen (Event-Verteilung → Empfang) ausgeschaltet.</p></div>
            <?php endif; ?>

            <p style="max-width:860px;">Quellseiten (andere Tixomat-Installationen) teilen Events mit dieser Seite. Jede Quelle meldet sich mit ihrem eigenen Schlüssel.
            Ist „Verkauf über diese Seite“ an, verkauft die App Tickets für geteilte Events dieser Quelle: Bestellung und Zahlung laufen beim Veranstalter (Quelle),
            die Tickets werden hier gespiegelt und erscheinen unter „Meine Tickets“. Der Einlass erfolgt bei der Quelle.</p>

            <?php if ($edit || $new):
                $p = $edit ?: wp_parse_args(['key_in' => self::generate_key('tix_pin_'), 'key_out' => self::generate_key('tix_pout_')], self::defaults());
                ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:860px;">
                    <input type="hidden" name="action" value="tix_partner_save">
                    <input type="hidden" name="is_new" value="<?php echo $edit ? '0' : '1'; ?>">
                    <?php wp_nonce_field('tix_partner_save'); ?>
                    <table class="form-table" role="presentation">
                        <tr><th><label for="tpid">Kennung</label></th><td>
                            <input id="tpid" name="id" type="text" class="regular-text" value="<?php echo esc_attr($p['id']); ?>" <?php echo $edit ? 'readonly' : 'required'; ?> pattern="[a-z0-9_\-]+" placeholder="kitchenklub">
                            <p class="description">Kleinbuchstaben, Ziffern, - und _. Nicht änderbar.</p></td></tr>
                        <tr><th><label for="tpname">Anzeigename</label></th><td>
                            <input id="tpname" name="name" type="text" class="regular-text" value="<?php echo esc_attr($p['name']); ?>" required placeholder="KitchenKlub">
                            <p class="description">Wird in der App angezeigt („Tickets über …“).</p></td></tr>
                        <tr><th><label for="tpapi">API-Basis der Quelle</label></th><td>
                            <input id="tpapi" name="api_base" type="url" class="regular-text" style="width:100%;" value="<?php echo esc_attr($p['api_base']); ?>" required placeholder="https://kitchenklub.de/wp-json/tixomat/v1"></td></tr>
                        <tr><th><label for="tporg">Veranstalter hier</label></th><td>
                            <select id="tporg" name="organizer_id">
                                <option value="0">– keiner –</option>
                                <?php foreach ($orgs as $o): ?>
                                    <option value="<?php echo intval($o->ID); ?>" <?php selected(intval($p['organizer_id']), intval($o->ID)); ?>><?php echo esc_html($o->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Geteilte Events dieser Quelle werden diesem Veranstalter zugeordnet.</p></td></tr>
                        <tr><th>Verkauf über diese Seite</th><td>
                            <label><input type="checkbox" name="sales_enabled" value="1" <?php checked(!empty($p['sales_enabled'])); ?>> Tickets für geteilte Events dieser Quelle in der App verkaufen (Zahlung beim Veranstalter)</label></td></tr>
                        <tr><th><label for="tpterms">AGB des Veranstalters</label></th><td>
                            <input id="tpterms" name="terms_url" type="url" class="regular-text" style="width:100%;" value="<?php echo esc_attr($p['terms_url']); ?>" placeholder="https://kitchenklub.de/agb">
                            <p class="description">Optional; sonst wird der AGB-Link aus dem Angebot der Quelle genutzt.</p></td></tr>
                        <tr><th>Schlüssel für die Quelle</th><td>
                            <p><strong>API Key</strong> (Quelle → hier, bei der Quelle unter Event-Verteilung → Senden eintragen):</p>
                            <input type="text" name="key_in" class="regular-text" style="width:100%;font-family:monospace;" value="<?php echo esc_attr($p['key_in']); ?>" readonly onclick="this.select();">
                            <p style="margin-top:12px;"><strong>Partner-Schlüssel</strong> (hier → Quelle, bei der Quelle unter „Verkauf über die Plattform“ eintragen):</p>
                            <input type="text" name="key_out" class="regular-text" style="width:100%;font-family:monospace;" value="<?php echo esc_attr($p['key_out']); ?>" readonly onclick="this.select();">
                            <?php if ($edit): ?>
                                <p><label><input type="checkbox" name="rotate" value="1"> Beide Schlüssel neu erzeugen (Quelle muss sie danach neu eintragen)</label></p>
                            <?php endif; ?>
                            <p class="description">Plattform-URL für die Quelle: <code><?php echo esc_html(rest_url('tixomat/v1')); ?></code></p>
                        </td></tr>
                    </table>
                    <?php submit_button($edit ? 'Speichern' : 'Partner anlegen'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tix-partners')); ?>">Zurück</a>
                </form>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1100px;">
                    <thead><tr><th>Kennung</th><th>Name</th><th>API-Basis</th><th>Veranstalter</th><th>Verkauf</th><th>Geteilte Events</th><th></th></tr></thead>
                    <tbody>
                    <?php if (empty($all)): ?>
                        <tr><td colspan="7">Noch keine Partner. Geteilte Events werden bis dahin nur angezeigt; Tickets gibt es bei der Quelle.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($all as $p):
                        global $wpdb;
                        $count = intval($wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tix_source_partner' AND meta_value = %s", $p['id']
                        )));
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($p['id']); ?></code></td>
                            <td><?php echo esc_html($p['name']); ?></td>
                            <td><?php echo esc_html($p['api_base']); ?></td>
                            <td><?php echo $p['organizer_id'] ? esc_html(get_the_title($p['organizer_id'])) : '–'; ?></td>
                            <td><?php echo self::sales_ready($p) ? '✓ an' : 'aus'; ?></td>
                            <td><?php echo $count; ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=tix-partners&edit=' . rawurlencode($p['id']))); ?>">Bearbeiten</a> ·
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_partner_delete&id=' . rawurlencode($p['id'])), 'tix_partner_delete')); ?>" onclick="return confirm('Partner entfernen? Geteilte Events bleiben, die Quelle kann aber nichts mehr senden.');">Entfernen</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_partner_save');
        $id   = sanitize_key(wp_unslash($_POST['id'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $api  = esc_url_raw(trim(wp_unslash($_POST['api_base'] ?? '')));
        if ($id === '' || $name === '' || $api === '' || stripos($api, 'https://') !== 0) {
            wp_safe_redirect(admin_url('admin.php?page=tix-partners&msg=invalid' . ($id !== '' && empty($_POST['is_new']) ? '&edit=' . $id : '&new=1')));
            exit;
        }
        $all = self::all();
        $old = $all[$id] ?? null;
        $is_new = !empty($_POST['is_new']);
        if ($is_new && $old) {
            wp_safe_redirect(admin_url('admin.php?page=tix-partners&msg=invalid&new=1'));
            exit;
        }
        $key_in  = sanitize_text_field(wp_unslash($_POST['key_in'] ?? ''));
        $key_out = sanitize_text_field(wp_unslash($_POST['key_out'] ?? ''));
        if ($old) {
            // Schlüssel nur über „neu erzeugen“ ändern, nie aus dem Formular übernehmen
            $key_in  = $old['key_in'];
            $key_out = $old['key_out'];
        }
        if (!empty($_POST['rotate']) || strlen($key_in) < 20)  $key_in  = self::generate_key('tix_pin_');
        if (!empty($_POST['rotate']) || strlen($key_out) < 20) $key_out = self::generate_key('tix_pout_');
        $all[$id] = [
            'id'            => $id,
            'name'          => $name,
            'api_base'      => untrailingslashit($api),
            'key_in'        => $key_in,
            'key_out'       => $key_out,
            'organizer_id'  => intval($_POST['organizer_id'] ?? 0),
            'sales_enabled' => !empty($_POST['sales_enabled']) ? 1 : 0,
            'terms_url'     => esc_url_raw(trim(wp_unslash($_POST['terms_url'] ?? ''))),
            'created'       => $old['created'] ?? current_time('mysql'),
        ];
        update_option(self::OPTION, $all, false);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        wp_safe_redirect(admin_url('admin.php?page=tix-partners&msg=saved&edit=' . $id));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_partner_delete');
        $id  = sanitize_key($_GET['id'] ?? '');
        $all = self::all();
        unset($all[$id]);
        update_option(self::OPTION, $all, false);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        wp_safe_redirect(admin_url('admin.php?page=tix-partners&msg=deleted'));
        exit;
    }
}
