<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Studio_Editor – evendis Studio Stufe 3: weitere Felder des Experten-Modus im Event-Editor und Orte (1.38.448).
 *
 * Event-Editor (additiv in GET /events/form, GET /events/{id}/edit → `event`, POST /events[/{id}]; nur mitgeschickte Schlüssel):
 *   timetable       {times_tba, stages[{name, color}], days{"JJJJ-MM-TT": [{time, end, stage, title, desc}]}} | null löscht
 *   low_stock       {mode: global|custom|manual|off, threshold, text}  (+ low_stock_global nur lesend)
 *   ticket_transfer bool  (+ ticket_transfer_available nur lesend: globaler Schalter)
 *   ticket_sponsor  {image, image_id, link} | null löscht;  POST /events/{id}/sponsor-image (multipart `image`) → {image_id, image}
 *   (1.38.449)
 *   specials        {in_selector, in_checkout, items[{special_id, enabled, price_override, qty_override}]} + specials_catalog (nur lesend)
 *   combo_deals     [{id, label, price, self_cat_index, partners[{event_id, cat_index}]}] + combo_partner_options (nur lesend)
 *   syndication     {available, active, decided, partner_sales, partner_sales_available, status, last, error}; POST `syndicate`, `partner_sales` (nur Änderungen werden geschrieben)
 *   table_reservation {enabled, payment_mode, deposit_type, deposit_value, info_text, show_in_checkout,
 *                      categories[{index, name, desc, min_spend, min_guests, max_guests, quantity}], has_floor_plan}
 *   GET/POST /specials – Extras-Katalog (Mehr-Veranstalter: eigene + vom Admin freigegebene `_tix_special_shared`)
 * Orte (CPT tix_location):
 *   GET /locations · POST /locations · POST /locations/{id} · DELETE /locations/{id} (nur ohne Events, sonst 409)
 *   Rechte: lesen Staff; ändern Admin bzw. Rolle Veranstalter. Mehr-Veranstalter: eigene Orte (selbst angelegt oder dem
 *   eigenen Veranstalter zugeordnet) änderbar, in der App gelistete Orte anderer nur lesend.
 */
class TIX_Studio_Editor {

    const NS = 'tixomat/v1';
    const TIME_RE = '/^([01]?\d|2[0-3]):[0-5]\d$/';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    private static function bad($field, $msg, $status = 400) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => $status, 'field' => $field]);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Event-Editor ═══════════════

    /** Zusätzliche Felder für edit_payload ($id = 0: Vorlage). */
    public static function payload($id) {
        $id = intval($id);
        $m = function ($k) use ($id) { return $id ? (string) get_post_meta($id, $k, true) : ''; };
        // Programm
        $stages = $id ? get_post_meta($id, '_tix_stages', true) : [];
        $stages = is_array($stages) ? array_values(array_map(function ($s) { return ['name' => (string) ($s['name'] ?? ''), 'color' => (string) ($s['color'] ?? '')]; }, $stages)) : [];
        $tt = $id ? get_post_meta($id, '_tix_timetable', true) : [];
        $days = [];
        if (is_array($tt)) {
            ksort($tt);
            foreach ($tt as $date => $slots) {
                if (!is_array($slots)) continue;
                $days[(string) $date] = array_values(array_map(function ($s) {
                    return ['time' => (string) ($s['time'] ?? ''), 'end' => (string) ($s['end'] ?? ''), 'stage' => intval($s['stage'] ?? 0), 'title' => (string) ($s['title'] ?? ''), 'desc' => (string) ($s['desc'] ?? '')];
                }, $slots));
            }
        }
        // Sponsor
        $sid = $id ? intval(get_post_meta($id, '_tix_ticket_sponsor_image_id', true)) : 0;
        $simg = $sid ? (string) (wp_get_attachment_image_url($sid, 'large') ?: '') : $m('_tix_ticket_sponsor_image_url');
        $mode = $m('_tix_low_stock_mode') ?: 'global';
        return [
            'timetable'       => ['times_tba' => $m('_tix_timetable_times_tba') === '1', 'stages' => $stages, 'days' => (object) $days],
            'low_stock'       => ['mode' => $mode, 'threshold' => $m('_tix_low_stock_threshold') !== '' ? intval($m('_tix_low_stock_threshold')) : null, 'text' => $m('_tix_low_stock_text')],
            'low_stock_global' => function_exists('tix_get_settings') ? intval(tix_get_settings('low_stock_threshold') ?? 10) : 10,
            'ticket_transfer' => $m('_tix_ticket_transfer') === '1',
            'ticket_transfer_available' => function_exists('tix_get_settings') && (bool) tix_get_settings('ticket_transfer_enabled'),
            'ticket_sponsor'  => ['image' => $simg, 'image_id' => $sid, 'link' => $m('_tix_ticket_sponsor_link')],
        ] + self::payload2($id);
    }

    // ── Teil 2 (1.38.449): Extras, Kombis, Verteilung, Tische ──

    /** Extras, die das Konto nutzen darf (Admin/Ein-Veranstalter: alle aktiven; Mehr-Veranstalter: eigene + freigegebene). */
    public static function specials_allowed() {
        if (!class_exists('TIX_Specials')) return [];
        $out = [];
        foreach (TIX_Specials::get_all_active() as $sp) {
            if (self::special_visible($sp->ID)) $out[] = intval($sp->ID);
        }
        return $out;
    }

    private static function special_visible($sid) {
        if (!self::multi() || current_user_can('manage_options')) return true;
        return self::special_own($sid) || get_post_meta($sid, '_tix_special_shared', true) === '1';
    }

    private static function special_own($sid) {
        if (current_user_can('manage_options') || !self::multi()) return true;
        $oid = TIX_App_Scope::organizer_id_for_user();
        if ($oid && intval(get_post_meta($sid, '_tix_special_organizer_id', true)) === $oid) return true;
        return intval(get_post_field('post_author', $sid)) === get_current_user_id();
    }

    public static function special_payload($sid) {
        $img = get_post_thumbnail_id($sid);
        return [
            'id'          => intval($sid),
            'name'        => html_entity_decode(get_the_title($sid), ENT_QUOTES, 'UTF-8'),
            'description' => wp_strip_all_tags((string) get_post_field('post_content', $sid)),
            'price'       => round(floatval(get_post_meta($sid, '_tix_special_price', true)), 2),
            'value'       => round(floatval(get_post_meta($sid, '_tix_special_value', true)), 2),
            'qty'         => intval(get_post_meta($sid, '_tix_special_qty', true)),
            'image'       => $img ? (string) (wp_get_attachment_image_url($img, 'medium') ?: '') : '',
            'editable'    => self::special_own($sid),
        ];
    }

    private static function cat_list($eid) {
        $cats = get_post_meta($eid, '_tix_ticket_categories', true);
        $out = [];
        foreach (is_array($cats) ? $cats : [] as $i => $c) {
            if (is_array($c)) $out[] = ['index' => intval($i), 'name' => (string) ($c['name'] ?? ''), 'price' => round(floatval($c['price'] ?? 0), 2)];
        }
        return $out;
    }

    /** Kommende Events, die Partner einer Kombi sein dürfen (Mehr-Veranstalter: eigene). */
    private static function combo_partner_ids($exclude) {
        $args = ['post_type' => 'event', 'post_status' => ['publish', 'future', 'draft'], 'posts_per_page' => 100, 'fields' => 'ids',
            'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC', 'suppress_filters' => true, 'post__not_in' => $exclude ? [intval($exclude)] : [],
            'meta_query' => [['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']]];
        if (self::multi() && !current_user_can('manage_options')) {
            $oid = TIX_App_Scope::organizer_id_for_user();
            if (!$oid) return [];
            $args['meta_query'][] = TIX_App_Scope::event_meta_clause($oid);
        }
        return array_map('intval', get_posts($args));
    }

    /** Verteilung an? Wie die Metabox: noch nie entschieden = an, wenn die Seite zum Verteilen eingerichtet ist. */
    private static function syndicate_effective($id) {
        if ($id && metadata_exists('post', $id, '_tix_syndicate')) return get_post_meta($id, '_tix_syndicate', true) === '1';
        // Vorgabe „alle Events teilen“ (1.38.465); nicht eingestellt = wie bisher an
        if (class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::default_setting() === false) return false;
        return self::syndication_available();
    }

    private static function syndication_available() {
        return !self::multi() && class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::is_configured();
    }

    private static function payload2($id) {
        $m = function ($k) use ($id) { return $id ? (string) get_post_meta($id, $k, true) : ''; };
        // Extras
        $items = [];
        $raw = $id ? get_post_meta($id, '_tix_specials', true) : [];
        if (is_array($raw) && isset($raw['special_id'])) $raw = [$raw];
        foreach (is_array($raw) ? $raw : [] as $es) {
            if (!is_array($es) || !intval($es['special_id'] ?? 0)) continue;
            $items[] = ['special_id' => intval($es['special_id']), 'enabled' => !empty($es['enabled']),
                'price_override' => ($es['price_override'] ?? '') === '' ? null : round(floatval($es['price_override']), 2),
                'qty_override' => ($es['qty_override'] ?? '') === '' ? null : intval($es['qty_override'])];
        }
        // Kombis
        $combos = [];
        foreach ((array) ($id ? get_post_meta($id, '_tix_combo_deals', true) : []) as $c) {
            if (!is_array($c)) continue;
            $combos[] = ['id' => (string) ($c['id'] ?? ''), 'label' => (string) ($c['label'] ?? ''), 'price' => round(floatval($c['price'] ?? 0), 2), 'self_cat_index' => intval($c['self_cat_index'] ?? 0),
                'partners' => array_values(array_map(function ($p) { return ['event_id' => intval($p['event_id'] ?? 0), 'cat_index' => intval($p['cat_index'] ?? 0)]; }, (array) ($c['partners'] ?? [])))];
        }
        $partner_opts = [];
        foreach (self::combo_partner_ids($id) as $eid) {
            $partner_opts[] = ['event_id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date_start' => (string) get_post_meta($eid, '_tix_date_start', true), 'categories' => self::cat_list($eid)];
        }
        // Tische
        $tr = $id ? get_post_meta($id, '_tix_table_reservation', true) : [];
        $tr = is_array($tr) ? $tr : [];
        $tcats = [];
        foreach ((array) ($tr['categories'] ?? []) as $i => $c) {
            if (!is_array($c)) continue;
            $tcats[] = ['index' => intval($i), 'name' => (string) ($c['name'] ?? ''), 'desc' => (string) ($c['desc'] ?? ''), 'min_spend' => round(floatval($c['min_spend'] ?? 0), 2),
                'min_guests' => intval($c['min_guests'] ?? 1), 'max_guests' => intval($c['max_guests'] ?? 10), 'quantity' => intval($c['quantity'] ?? 0), 'tables' => count((array) ($c['tables'] ?? []))];
        }
        $syn_avail = self::syndication_available();
        return [
            'specials'         => ['available' => class_exists('TIX_Specials'), 'in_selector' => $m('_tix_specials_in_selector') === '1', 'in_checkout' => $m('_tix_specials_in_checkout') === '1', 'items' => $items],
            'specials_catalog' => array_map([__CLASS__, 'special_payload'], self::specials_allowed()),
            'combo_deals'      => $combos,
            'combo_partner_options' => $partner_opts,
            'syndication'      => [
                'available' => $syn_avail,
                'active' => self::syndicate_effective($id),
                'decided' => $id && metadata_exists('post', $id, '_tix_syndicate'),
                'partner_sales' => $id && class_exists('TIX_Syndication_Push') ? TIX_Syndication_Push::partner_sales_enabled($id) : false,
                'partner_sales_available' => class_exists('TIX_Partners') && TIX_Partners::source_api_enabled(),
                'status' => $m('_tix_syndicate_status') ?: null, 'last' => $m('_tix_syndicate_last') ?: null, 'error' => $m('_tix_syndicate_error') ?: null,
            ],
            'table_reservation' => [
                'available' => class_exists('TIX_Table_Reservation'),
                'enabled' => !empty($tr['enabled']), 'payment_mode' => (string) ($tr['payment_mode'] ?? 'on_site'), 'deposit_type' => (string) ($tr['deposit_type'] ?? 'percent'),
                'deposit_value' => round(floatval($tr['deposit_value'] ?? 50), 2), 'info_text' => (string) ($tr['info_text'] ?? ''), 'show_in_checkout' => !empty($tr['show_in_checkout']),
                'categories' => $tcats, 'has_floor_plan' => intval($tr['floor_plan_id'] ?? 0) > 0,
            ],
        ];
    }

    /** Aktive Tischreservierungen je Kategorie-Index. */
    private static function table_bookings($post_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_table_reservations';
        if (!$post_id || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return [];
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT category_index, COUNT(*) n FROM $t WHERE event_id = %d AND status NOT IN ('cancelled','expired','failed','refunded') GROUP BY category_index", $post_id)) as $r) {
            $out[intval($r->category_index)] = intval($r->n);
        }
        return $out;
    }

    private static function check2(array $body, $post_id) {
        $out = [];
        // ── Extras ──
        if (array_key_exists('specials', $body)) {
            $sp = $body['specials'];
            if (!class_exists('TIX_Specials')) return new WP_Error('tix_specials_unavailable', 'Extras sind auf dieser Seite nicht eingeschaltet.', ['status' => 409, 'field' => 'specials']);
            if (!is_array($sp)) return self::bad('specials', 'Ungültige Extras.');
            $c = [];
            foreach (['in_selector', 'in_checkout'] as $k) if (array_key_exists($k, $sp)) $c[$k] = !empty($sp[$k]);
            if (array_key_exists('items', $sp)) {
                if (!is_array($sp['items'])) return self::bad('specials.items', 'Ungültige Extras.');
                $allowed = array_flip(self::specials_allowed());
                $items = []; $seen = [];
                foreach (array_values($sp['items']) as $i => $it) {
                    $sid = intval($it['special_id'] ?? 0);
                    if (!isset($allowed[$sid])) return self::bad("specials.items[$i].special_id", 'Dieses Extra gibt es nicht oder es ist nicht freigegeben.');
                    if (isset($seen[$sid])) continue;
                    $seen[$sid] = true;
                    $po = $it['price_override'] ?? null; $qo = $it['qty_override'] ?? null;
                    if ($po !== null && $po !== '' && (!is_numeric($po) || floatval($po) < 0)) return self::bad("specials.items[$i].price_override", 'Ungültiger Preis.');
                    if ($qo !== null && $qo !== '' && (!is_numeric($qo) || intval($qo) < 0)) return self::bad("specials.items[$i].qty_override", 'Ungültige Anzahl.');
                    $items[] = ['special_id' => $sid, 'enabled' => !empty($it['enabled']) ? '1' : '',
                        'price_override' => ($po === null || $po === '') ? '' : round(floatval($po), 2), 'qty_override' => ($qo === null || $qo === '') ? '' : intval($qo)];
                }
                $c['items'] = $items;
            }
            $out['specials'] = $c;
        }
        // ── Kombis ──
        if (array_key_exists('combo_deals', $body)) {
            $cd = $body['combo_deals'];
            if ($cd === null) $cd = [];
            if (!is_array($cd)) return self::bad('combo_deals', 'Ungültige Kombis.');
            $own_cats = isset($body['categories']) && is_array($body['categories']) ? count(array_filter($body['categories'], function ($x) { return is_array($x) && trim((string) ($x['name'] ?? '')) !== ''; }))
                : count(self::cat_list($post_id));
            $partners_ok = array_flip(self::combo_partner_ids($post_id));
            $list = [];
            foreach (array_values($cd) as $i => $c) {
                if (!is_array($c)) return self::bad("combo_deals[$i]", 'Ungültige Kombi.');
                $label = mb_substr(sanitize_text_field((string) ($c['label'] ?? '')), 0, 80);
                if ($label === '') return self::bad("combo_deals[$i].label", 'Bitte einen Namen für die Kombi angeben.');
                if (!is_numeric($c['price'] ?? null) || floatval($c['price']) <= 0) return self::bad("combo_deals[$i].price", 'Bitte einen Preis über 0 angeben.');
                $self = intval($c['self_cat_index'] ?? 0);
                if ($self < 0 || $self >= max(1, $own_cats)) return self::bad("combo_deals[$i].self_cat_index", 'Diese Ticketart gibt es bei diesem Event nicht.');
                $parts = [];
                foreach (array_values((array) ($c['partners'] ?? [])) as $j => $pt) {
                    $eid = intval($pt['event_id'] ?? 0); $ci = intval($pt['cat_index'] ?? 0);
                    if (!$eid || $eid === intval($post_id) || !isset($partners_ok[$eid])) return self::bad("combo_deals[$i].partners[$j].event_id", 'Dieses Event kann nicht Partner der Kombi sein.');
                    if ($ci < 0 || $ci >= count(self::cat_list($eid))) return self::bad("combo_deals[$i].partners[$j].cat_index", 'Diese Ticketart gibt es beim Partner-Event nicht.');
                    $parts[] = ['event_id' => $eid, 'cat_index' => $ci];
                }
                if (!$parts) return self::bad("combo_deals[$i].partners", 'Eine Kombi braucht mindestens ein Partner-Event.');
                $cid = sanitize_key((string) ($c['id'] ?? ''));
                $list[] = ['id' => $cid !== '' ? $cid : 'combo_' . wp_generate_password(8, false, false), 'label' => $label, 'price' => round(floatval($c['price']), 2), 'self_cat_index' => $self, 'partners' => $parts];
            }
            if (count($list) > 20) return self::bad('combo_deals', 'Höchstens 20 Kombis.');
            $out['combo_deals'] = $list;
        }
        // ── Verteilung ──
        $syn = $body['syndication'] ?? null;
        $has_syn = array_key_exists('syndicate', $body) || array_key_exists('partner_sales', $body) || is_array($syn);
        if ($has_syn) {
            $c = [];
            $a = array_key_exists('syndicate', $body) ? $body['syndicate'] : (is_array($syn) && array_key_exists('active', $syn) ? $syn['active'] : null);
            // Nur Änderungen schreiben (bzw. die erste Entscheidung, wie beim Speichern der Metabox): unverändert mitgeschickt passiert nichts
            $decided = $post_id && metadata_exists('post', $post_id, '_tix_syndicate');
            if ($a !== null && (!$decided || !empty($a) !== self::syndicate_effective($post_id))) $c['syndicate'] = !empty($a);
            $ps = array_key_exists('partner_sales', $body) ? $body['partner_sales'] : (is_array($syn) && array_key_exists('partner_sales', $syn) ? $syn['partner_sales'] : null);
            $ps_now = $post_id && class_exists('TIX_Syndication_Push') ? TIX_Syndication_Push::partner_sales_enabled($post_id) : false;
            if ($ps !== null && (!($post_id && metadata_exists('post', $post_id, '_tix_partner_sales')) || !empty($ps) !== $ps_now)) $c['partner_sales'] = !empty($ps);
            if (!self::syndication_available()) {
                // Hier nicht eingerichtet: „aus“ ist nichts zu tun, nur Einschalten ist ein Fehler
                if (!empty($c['syndicate']) || !empty($c['partner_sales'])) return new WP_Error('tix_syndication_unavailable', 'Die Verteilung auf die Plattform ist hier nicht eingerichtet.', ['status' => 409, 'field' => 'syndicate']);
                $c = [];
            }
            if ($c) $out['syndication'] = $c;
        }
        // ── Tische ──
        if (array_key_exists('table_reservation', $body)) {
            $tr = $body['table_reservation'];
            if (!class_exists('TIX_Table_Reservation')) return new WP_Error('tix_table_reservation_unavailable', 'Die Tischreservierung ist auf dieser Seite nicht eingeschaltet.', ['status' => 409, 'field' => 'table_reservation']);
            if (!is_array($tr)) return self::bad('table_reservation', 'Ungültige Tischreservierung.');
            $old = $post_id ? get_post_meta($post_id, '_tix_table_reservation', true) : [];
            $old = is_array($old) ? $old : [];
            $new = $old + ['enabled' => '', 'payment_mode' => 'on_site', 'deposit_type' => 'percent', 'deposit_value' => 50, 'floor_plan_id' => 0, 'info_text' => '', 'show_in_checkout' => '', 'categories' => []];
            if (array_key_exists('enabled', $tr)) $new['enabled'] = !empty($tr['enabled']) ? '1' : '';
            if (array_key_exists('show_in_checkout', $tr)) $new['show_in_checkout'] = !empty($tr['show_in_checkout']) ? '1' : '';
            if (array_key_exists('payment_mode', $tr)) {
                if (!in_array($tr['payment_mode'], ['deposit', 'full', 'on_site'], true)) return self::bad('table_reservation.payment_mode', 'Zahlung: deposit, full oder on_site.');
                $new['payment_mode'] = $tr['payment_mode'];
            }
            if (array_key_exists('deposit_type', $tr)) {
                if (!in_array($tr['deposit_type'], ['percent', 'fixed'], true)) return self::bad('table_reservation.deposit_type', 'Anzahlung: percent oder fixed.');
                $new['deposit_type'] = $tr['deposit_type'];
            }
            if (array_key_exists('deposit_value', $tr)) {
                $dv = $tr['deposit_value'];
                if (!is_numeric($dv) || floatval($dv) <= 0 || ($new['deposit_type'] === 'percent' && floatval($dv) > 100)) return self::bad('table_reservation.deposit_value', 'Ungültige Anzahlung.');
                $new['deposit_value'] = round(floatval($dv), 2);
            }
            if (array_key_exists('info_text', $tr)) $new['info_text'] = mb_substr(sanitize_textarea_field((string) $tr['info_text']), 0, 2000);
            if (array_key_exists('categories', $tr)) {
                if (!is_array($tr['categories'])) return self::bad('table_reservation.categories', 'Ungültige Tisch-Kategorien.');
                $old_cats = array_values((array) ($old['categories'] ?? []));
                $book = self::table_bookings($post_id);
                $kept = []; $last = -1; $cats = [];
                foreach (array_values($tr['categories']) as $i => $c) {
                    if (!is_array($c)) continue;
                    $name = mb_substr(sanitize_text_field((string) ($c['name'] ?? '')), 0, 100);
                    if ($name === '') return self::bad("table_reservation.categories[$i].name", 'Bitte einen Namen für die Tisch-Kategorie angeben.');
                    $has_idx = isset($c['index']) && $c['index'] !== '' && $c['index'] !== null && isset($old_cats[intval($c['index'])]);
                    if ($has_idx) {
                        $idx = intval($c['index']);
                        if ($idx < $last) return new WP_Error('table_category_order', 'Die Reihenfolge bestehender Tisch-Kategorien kann nicht geändert werden.', ['status' => 409]);
                        $last = $idx; $kept[$idx] = true;
                    }
                    $min = max(1, intval($c['min_guests'] ?? 1)); $max = max(1, intval($c['max_guests'] ?? 10));
                    if ($max < $min) return self::bad("table_reservation.categories[$i].max_guests", 'Höchstzahl muss mindestens die Mindestzahl sein.');
                    if (isset($c['min_spend']) && (!is_numeric($c['min_spend']) || floatval($c['min_spend']) < 0)) return self::bad("table_reservation.categories[$i].min_spend", 'Ungültiger Mindestumsatz.');
                    $base = $has_idx ? $old_cats[intval($c['index'])] : ['tables' => []];
                    $cats[] = array_merge($base, ['name' => $name, 'desc' => mb_substr(sanitize_textarea_field((string) ($c['desc'] ?? ($base['desc'] ?? ''))), 0, 500),
                        'min_spend' => round(floatval($c['min_spend'] ?? ($base['min_spend'] ?? 0)), 2), 'min_guests' => $min, 'max_guests' => $max,
                        'quantity' => max(0, intval($c['quantity'] ?? ($base['quantity'] ?? 0))), 'tables' => (array) ($base['tables'] ?? [])]);
                }
                // Gelöschte Kategorie bzw. Indizes dahinter mit Reservierungen → 409 (Reservierungen hängen am Index)
                for ($k = 0; $k < count($old_cats); $k++) {
                    if (isset($kept[$k])) continue;
                    for ($j = $k; $j < count($old_cats); $j++) {
                        if (!empty($book[$j])) return new WP_Error('table_category_in_use', 'Die Tisch-Kategorie „' . (string) ($old_cats[$k]['name'] ?? '') . '“ kann nicht gelöscht werden, weil dahinter Reservierungen bestehen.', ['status' => 409]);
                    }
                }
                $new['categories'] = $cats;
            }
            $out['table_reservation'] = $new;
        }
        return $out;
    }

    private static function apply2($post_id, array $c) {
        if (array_key_exists('specials', $c)) {
            $sp = $c['specials'];
            if (array_key_exists('in_selector', $sp)) update_post_meta($post_id, '_tix_specials_in_selector', $sp['in_selector'] ? '1' : '');
            if (array_key_exists('in_checkout', $sp)) update_post_meta($post_id, '_tix_specials_in_checkout', $sp['in_checkout'] ? '1' : '');
            if (array_key_exists('items', $sp)) update_post_meta($post_id, '_tix_specials', $sp['items']);
        }
        if (array_key_exists('combo_deals', $c)) update_post_meta($post_id, '_tix_combo_deals', $c['combo_deals']);
        if (array_key_exists('syndication', $c)) {
            if (array_key_exists('syndicate', $c['syndication'])) update_post_meta($post_id, '_tix_syndicate', $c['syndication']['syndicate'] ? '1' : '0');
            if (array_key_exists('partner_sales', $c['syndication'])) update_post_meta($post_id, '_tix_partner_sales', $c['syndication']['partner_sales'] ? '1' : '0');
        }
        if (array_key_exists('table_reservation', $c)) update_post_meta($post_id, '_tix_table_reservation', $c['table_reservation']);
    }

    /** Nach dem Speichern: Verteilung anstoßen, wenn das Speichern selbst kein save_post ausgelöst hat (sonst macht es on_save). */
    public static function after_save($post_id, array $c, $post_updated) {
        if (!array_key_exists('syndication', $c) || $post_updated || !class_exists('TIX_Syndication_Push')) return;
        TIX_Syndication_Push::on_save($post_id, get_post($post_id));
    }

    /** Vorab prüfen (vor allen Änderungen). Liefert bereinigte Werte je mitgeschicktem Schlüssel oder WP_Error. */
    public static function check(array $body, $post_id = 0) {
        $out = [];
        // ── Programm ──
        if (array_key_exists('timetable', $body)) {
            $t = $body['timetable'];
            if ($t === null) { $out['timetable'] = null; }
            elseif (!is_array($t)) return self::bad('timetable', 'Ungültiges Programm.');
            else {
                $c = [];
                if (array_key_exists('times_tba', $t)) $c['times_tba'] = !empty($t['times_tba']);
                $stage_count = null;
                if (array_key_exists('stages', $t)) {
                    if (!is_array($t['stages'])) return self::bad('timetable.stages', 'Ungültige Bühnen.');
                    $st = [];
                    foreach (array_values($t['stages']) as $i => $s) {
                        $name = mb_substr(sanitize_text_field((string) ($s['name'] ?? '')), 0, 60);
                        if ($name === '') continue;
                        $color = sanitize_hex_color((string) ($s['color'] ?? '')) ?: (function_exists('tix_primary') ? tix_primary() : '#FF5500');
                        $st[] = ['name' => $name, 'color' => $color];
                    }
                    if (count($st) > 20) return self::bad('timetable.stages', 'Höchstens 20 Bühnen.');
                    $c['stages'] = $st;
                    $stage_count = count($st);
                }
                if (array_key_exists('days', $t)) {
                    if (!is_array($t['days'])) return self::bad('timetable.days', 'Ungültige Programm-Tage.');
                    if ($stage_count === null) { $cur = $post_id ? get_post_meta($post_id, '_tix_stages', true) : []; $stage_count = is_array($cur) ? count($cur) : 0; }
                    $days = [];
                    foreach ($t['days'] as $date => $slots) {
                        $date = (string) $date;
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return self::bad('timetable.days', 'Programm-Tag bitte als JJJJ-MM-TT.');
                        if (!is_array($slots)) return self::bad("timetable.days.$date", 'Ungültige Programmpunkte.');
                        $list = [];
                        foreach (array_values($slots) as $i => $s) {
                            if (!is_array($s)) continue;
                            $time = trim((string) ($s['time'] ?? '')); $end = trim((string) ($s['end'] ?? ''));
                            $title = mb_substr(sanitize_text_field((string) ($s['title'] ?? '')), 0, 200);
                            if ($time === '' && $title === '') continue;
                            if ($time !== '' && !preg_match(self::TIME_RE, $time)) return self::bad("timetable.days.$date.$i.time", 'Uhrzeit bitte als HH:MM.');
                            if ($end !== '' && !preg_match(self::TIME_RE, $end)) return self::bad("timetable.days.$date.$i.end", 'Uhrzeit bitte als HH:MM.');
                            $stage = max(0, intval($s['stage'] ?? 0));
                            if ($stage_count > 0 && $stage >= $stage_count) return self::bad("timetable.days.$date.$i.stage", 'Diese Bühne gibt es nicht.');
                            $list[] = ['time' => $time !== '' ? sprintf('%05s', $time) : '', 'end' => $end !== '' ? sprintf('%05s', $end) : '', 'stage' => $stage, 'title' => $title, 'desc' => mb_substr(sanitize_text_field((string) ($s['desc'] ?? '')), 0, 500)];
                            if (count($list) > 200) return self::bad("timetable.days.$date", 'Höchstens 200 Programmpunkte je Tag.');
                        }
                        usort($list, function ($a, $b) { return strcmp($a['time'], $b['time']); });
                        if ($list) $days[$date] = $list;
                    }
                    ksort($days);
                    $c['days'] = $days;
                }
                $out['timetable'] = $c;
            }
        }
        // ── Restbestand-Hinweis ──
        if (array_key_exists('low_stock', $body)) {
            $l = $body['low_stock'];
            if (!is_array($l)) return self::bad('low_stock', 'Ungültiger Restbestand-Hinweis.');
            $mode = sanitize_key((string) ($l['mode'] ?? 'global'));
            if (!in_array($mode, ['global', 'custom', 'manual', 'off'], true)) return self::bad('low_stock.mode', 'Modus: global, custom, manual oder off.');
            $c = ['mode' => $mode];
            if ($mode === 'custom') {
                $th = $l['threshold'] ?? 50;
                if (!is_numeric($th) || intval($th) < 1 || intval($th) > 9999) return self::bad('low_stock.threshold', 'Schwelle zwischen 1 und 9999.');
                $c['threshold'] = intval($th);
            }
            if ($mode === 'manual') {
                $text = sanitize_text_field((string) ($l['text'] ?? ''));
                if ($text === '' || mb_strlen($text) > 80) return self::bad('low_stock.text', 'Bitte einen Text mit höchstens 80 Zeichen angeben.');
                $c['text'] = $text;
            }
            $out['low_stock'] = $c;
        }
        if (array_key_exists('ticket_transfer', $body)) $out['ticket_transfer'] = !empty($body['ticket_transfer']);
        // ── Ticket-Sponsor ──
        if (array_key_exists('ticket_sponsor', $body)) {
            $s = $body['ticket_sponsor'];
            if ($s === null) { $out['ticket_sponsor'] = null; }
            elseif (!is_array($s)) return self::bad('ticket_sponsor', 'Ungültiger Sponsor.');
            else {
                $c = [];
                if (array_key_exists('image_id', $s)) {
                    $iid = intval($s['image_id']);
                    if ($iid && !self::image_allowed($iid, $post_id)) return self::bad('ticket_sponsor.image_id', 'Dieses Bild ist nicht verfügbar.');
                    $c['image_id'] = $iid;
                } elseif (array_key_exists('image', $s)) {
                    $u = trim((string) $s['image']);
                    if ($u !== '' && !preg_match('#^https://#i', $u)) return self::bad('ticket_sponsor.image', 'Bild-Adresse bitte mit https://.');
                    $c['image'] = esc_url_raw($u);
                }
                if (array_key_exists('link', $s)) {
                    $u = trim((string) $s['link']);
                    if ($u !== '' && !preg_match('#^https?://[^\s/]+\.[^\s]+#i', $u)) return self::bad('ticket_sponsor.link', 'Link bitte mit https://.');
                    $c['link'] = esc_url_raw($u);
                }
                $out['ticket_sponsor'] = $c;
            }
        }
        $more = self::check2($body, $post_id);
        if (is_wp_error($more)) return $more;
        return $out + $more;
    }

    /** Bild aus der Mediathek: Bild-Anhang, im Mehr-Veranstalter-Modus nur eigene bzw. am Event hängende. */
    private static function image_allowed($iid, $post_id) {
        if (get_post_type($iid) !== 'attachment' || strpos((string) get_post_mime_type($iid), 'image/') !== 0) return false;
        if (!self::multi() || current_user_can('manage_options')) return true;
        if (intval(get_post_field('post_parent', $iid)) === intval($post_id) && $post_id) return true;
        return intval(get_post_field('post_author', $iid)) === get_current_user_id();
    }

    /** Geprüfte Werte speichern. */
    public static function apply($post_id, array $c) {
        self::apply2($post_id, $c);
        if (array_key_exists('timetable', $c)) {
            $t = $c['timetable'];
            if ($t === null) {
                foreach (['_tix_timetable', '_tix_stages', '_tix_timetable_times_tba'] as $k) delete_post_meta($post_id, $k);
            } else {
                if (array_key_exists('times_tba', $t)) update_post_meta($post_id, '_tix_timetable_times_tba', $t['times_tba'] ? '1' : '');
                if (array_key_exists('stages', $t)) update_post_meta($post_id, '_tix_stages', $t['stages']);
                if (array_key_exists('days', $t)) update_post_meta($post_id, '_tix_timetable', $t['days']);
            }
        }
        if (array_key_exists('low_stock', $c)) {
            $l = $c['low_stock'];
            update_post_meta($post_id, '_tix_low_stock_mode', $l['mode']);
            if (isset($l['threshold'])) update_post_meta($post_id, '_tix_low_stock_threshold', $l['threshold']);
            if (isset($l['text'])) update_post_meta($post_id, '_tix_low_stock_text', $l['text']);
        }
        if (array_key_exists('ticket_transfer', $c)) update_post_meta($post_id, '_tix_ticket_transfer', $c['ticket_transfer'] ? '1' : '0');
        if (array_key_exists('ticket_sponsor', $c)) {
            $s = $c['ticket_sponsor'];
            if ($s === null) {
                foreach (['_tix_ticket_sponsor_image_id', '_tix_ticket_sponsor_image_url', '_tix_ticket_sponsor_link'] as $k) delete_post_meta($post_id, $k);
            } else {
                if (array_key_exists('image_id', $s)) {
                    if ($s['image_id']) { update_post_meta($post_id, '_tix_ticket_sponsor_image_id', $s['image_id']); delete_post_meta($post_id, '_tix_ticket_sponsor_image_url'); }
                    else { delete_post_meta($post_id, '_tix_ticket_sponsor_image_id'); delete_post_meta($post_id, '_tix_ticket_sponsor_image_url'); }
                } elseif (array_key_exists('image', $s)) {
                    if ($s['image'] !== '') { update_post_meta($post_id, '_tix_ticket_sponsor_image_url', $s['image']); delete_post_meta($post_id, '_tix_ticket_sponsor_image_id'); }
                    else delete_post_meta($post_id, '_tix_ticket_sponsor_image_url');
                }
                if (array_key_exists('link', $s)) {
                    if ($s['link'] !== '') update_post_meta($post_id, '_tix_ticket_sponsor_link', $s['link']); else delete_post_meta($post_id, '_tix_ticket_sponsor_link');
                }
            }
        }
    }

    // ═══════════════ Routen ═══════════════

    public static function register_routes() {
        $staff = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/sponsor-image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_sponsor_image'], 'permission_callback' => $staff]);
        register_rest_route(self::NS, '/specials', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_specials'], 'permission_callback' => $staff],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_special_save'], 'permission_callback' => [__CLASS__, 'perm_location_write']],
        ]);
        register_rest_route(self::NS, '/specials/(?P<id>\d+)', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_special_save'], 'permission_callback' => [__CLASS__, 'perm_location_write']]);
        register_rest_route(self::NS, '/locations', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_locations'], 'permission_callback' => $staff],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_location_save'], 'permission_callback' => [__CLASS__, 'perm_location_write']],
        ]);
        register_rest_route(self::NS, '/locations/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_location_save'], 'permission_callback' => [__CLASS__, 'perm_location_write']],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_location_delete'], 'permission_callback' => [__CLASS__, 'perm_location_write']],
        ]);
    }

    /** POST /events/{id}/sponsor-image (multipart `image`) → {image_id, image} */
    public static function rest_sponsor_image(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $files = $req->get_file_params();
        $file = $files['image'] ?? ($files['file'] ?? null);
        if (empty($file['tmp_name'])) return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400, 'field' => 'image']);
        if (!empty($file['error'])) return new WP_Error('upload_failed', 'Upload fehlgeschlagen (höchstens 5 MB).', ['status' => in_array(intval($file['error']), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400, 'field' => 'image']);
        if (intval($file['size'] ?? 0) > 5 * MB_IN_BYTES) return new WP_Error('too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => 'image']);
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return new WP_Error('bad_type', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.', ['status' => 400, 'field' => 'image']);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $aid = media_handle_sideload(['name' => 'sponsor-' . $id . '-' . time() . '.' . $ext, 'tmp_name' => $file['tmp_name'], 'type' => $mime, 'size' => $file['size'] ?? 0], $id, 'Ticket-Sponsor');
        if (is_wp_error($aid)) return new WP_Error('upload_failed', $aid->get_error_message(), ['status' => 500]);
        update_post_meta($id, '_tix_ticket_sponsor_image_id', $aid);
        delete_post_meta($id, '_tix_ticket_sponsor_image_url');
        return rest_ensure_response(['ok' => true, 'image_id' => intval($aid), 'image' => (string) (wp_get_attachment_image_url($aid, 'large') ?: '')]);
    }

    // ═══════════════ Extras-Katalog ═══════════════

    /** GET /specials */
    public static function rest_specials(WP_REST_Request $req) {
        return rest_ensure_response(['ok' => true, 'specials' => array_map([__CLASS__, 'special_payload'], self::specials_allowed())]);
    }

    /** POST /specials[/{id}] {name, description, price, value, qty} – eigene Extras (Mehr-Veranstalter: dem Veranstalter zugeordnet) */
    public static function rest_special_save(WP_REST_Request $req) {
        if (!class_exists('TIX_Specials')) return new WP_Error('tix_unavailable', 'Extras sind hier nicht verfügbar.', ['status' => 404]);
        $sid = absint($req['id'] ?? 0);
        if ($sid) {
            if (get_post_type($sid) !== TIX_Specials::CPT) return new WP_Error('not_found', 'Extra nicht gefunden.', ['status' => 404]);
            if (!self::special_own($sid)) return new WP_Error('rest_forbidden', 'Dieses Extra kannst du nicht ändern.', ['status' => 403]);
        }
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : $req->get_params();
        if (!$sid || array_key_exists('name', $p)) {
            $name = mb_substr(sanitize_text_field((string) ($p['name'] ?? '')), 0, 120);
            if ($name === '') return self::bad('name', 'Bitte einen Namen für das Extra angeben.');
        }
        foreach (['price', 'value'] as $k) if (array_key_exists($k, $p) && (!is_numeric($p[$k]) || floatval($p[$k]) < 0)) return self::bad($k, 'Ungültiger Betrag.');
        if (array_key_exists('qty', $p) && (!is_numeric($p['qty']) || intval($p['qty']) < 0)) return self::bad('qty', 'Ungültige Anzahl.');
        if (!$sid) {
            $sid = wp_insert_post(['post_type' => TIX_Specials::CPT, 'post_status' => 'publish', 'post_title' => $name, 'post_content' => sanitize_textarea_field((string) ($p['description'] ?? '')), 'post_author' => get_current_user_id()], true);
            if (is_wp_error($sid)) return new WP_Error('tix_save_failed', 'Extra konnte nicht angelegt werden.', ['status' => 500]);
            if (self::multi() && ($oid = TIX_App_Scope::organizer_id_for_user())) update_post_meta($sid, '_tix_special_organizer_id', $oid);
            $created = true;
        } else {
            $upd = ['ID' => $sid];
            if (isset($name)) $upd['post_title'] = $name;
            if (array_key_exists('description', $p)) $upd['post_content'] = sanitize_textarea_field((string) $p['description']);
            if (count($upd) > 1) wp_update_post($upd);
        }
        if (array_key_exists('price', $p) || !empty($created)) update_post_meta($sid, '_tix_special_price', round(floatval($p['price'] ?? 0), 2));
        if (array_key_exists('value', $p) || !empty($created)) update_post_meta($sid, '_tix_special_value', round(floatval($p['value'] ?? 0), 2));
        if (array_key_exists('qty', $p) || !empty($created)) update_post_meta($sid, '_tix_special_qty', intval($p['qty'] ?? 0));
        return new WP_REST_Response(['ok' => true, 'special' => self::special_payload($sid)], !empty($created) ? 201 : 200);
    }

    // ═══════════════ Orte ═══════════════

    /** Eigener Ort? (Admin bzw. Ein-Veranstalter: alle; Mehr-Veranstalter: selbst angelegt oder dem eigenen Veranstalter zugeordnet) */
    private static function location_own($lid) {
        if (class_exists('TIX_Locations')) return TIX_Locations::is_own($lid);
        if (current_user_can('manage_options') || !self::multi()) return true;
        if (intval(get_post_field('post_author', $lid)) === get_current_user_id()) return true;
        $oid = TIX_App_Scope::organizer_id_for_user();
        return $oid && intval(get_post_meta($lid, '_tix_loc_organizer_id', true)) === $oid;
    }

    private static function location_events($lid) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_tix_location_id' AND pm.meta_value = %s AND p.post_type = 'event' AND p.post_status NOT IN ('trash', 'auto-draft')",
            (string) intval($lid)
        )));
    }

    public static function location_payload($lid) {
        $m = function ($k) use ($lid) { return (string) get_post_meta($lid, $k, true); };
        $lat = $m('_tix_loc_lat'); $lng = $m('_tix_loc_lng');
        $lat = is_numeric($lat) && floatval($lat) != 0 ? floatval($lat) : null;
        $lng = is_numeric($lng) && $lat !== null ? floatval($lng) : null;
        $addr = $m('_tix_loc_address');
        $zip = $m('_tix_loc_zip') ?: $m('_tix_loc_postcode');
        $q = $lat !== null ? $lat . ',' . $lng : implode(', ', array_filter([get_the_title($lid), $addr, trim($zip . ' ' . $m('_tix_loc_city'))]));
        return [
            'id'       => intval($lid),
            'title'    => html_entity_decode(get_the_title($lid), ENT_QUOTES, 'UTF-8'),
            'address'  => $addr,
            'zip'      => $zip,
            'city'     => $m('_tix_loc_city'),
            'country'  => $m('_tix_loc_country'),
            'lat'      => $lat,
            'lng'      => $lng,
            'maps_url' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q),
            // Barrierefreiheit am Ort (1.38.456, Vorgabe für Events mit „vom Ort übernehmen“): {items, note}
            'accessibility' => class_exists('TIX_Event_Facts') ? TIX_Event_Facts::sanitize_acc(get_post_meta($lid, TIX_Event_Facts::META_ACC, true)) : null,
            'events'   => self::location_events($lid),
            'editable' => self::location_own($lid),
            // 1.38.475: Google-Ort, zuletzt genutzt, Veranstalter, öffentlicher (kanonischer) Ort
            'place_id' => $m('_tix_loc_place_id') ?: null,
            'last_used' => class_exists('TIX_Locations') ? TIX_Locations::last_used($lid) : null,
            'organizer_id' => intval($m('_tix_loc_organizer_id')) ?: null,
            'canonical_id' => class_exists('TIX_Locations') ? TIX_Locations::canonical_id($lid) : intval($lid),
        ];
    }

    /** GET /locations */
    public static function rest_locations(WP_REST_Request $req) {
        // Mehr-Veranstalter-Modus: nur eigene Orte (1.38.475; vorher auch fremde, in der App gelistete), Admins alle
        $ids = class_exists('TIX_Locations') ? TIX_Locations::visible_ids(300) : get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => 300, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids']);
        $out = [];
        foreach ($ids as $lid) {
            if (self::multi() && !current_user_can('manage_options') && !self::location_own($lid)) continue;
            $out[] = self::location_payload($lid);
        }
        return rest_ensure_response(['ok' => true, 'locations' => $out]);
    }

    public static function perm_location_write(WP_REST_Request $req) {
        $r = TIX_REST_API::check_organizer($req);
        if ($r !== true) return $r;
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options') || in_array('tix_organizer', (array) $u->roles, true)) return true;
        return new WP_Error('rest_forbidden', 'Nur Admins und Veranstalter können Orte ändern.', ['status' => 403]);
    }

    /** POST /locations[/{id}] {title, address, zip, city, country, lat?, lng?, accessibility? {items, note} | null} */
    public static function rest_location_save(WP_REST_Request $req) {
        $lid = absint($req['id'] ?? 0);
        if ($lid) {
            if (get_post_type($lid) !== 'tix_location') return new WP_Error('not_found', 'Ort nicht gefunden.', ['status' => 404]);
            if (!self::location_own($lid)) return new WP_Error('rest_forbidden', 'Diesen Ort kannst du nicht ändern.', ['status' => 403]);
        }
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : $req->get_params();
        $f = ['address' => '_tix_loc_address', 'zip' => '_tix_loc_zip', 'city' => '_tix_loc_city', 'country' => '_tix_loc_country'];
        // Google-Ort (1.38.475): gibt es ihn beim Veranstalter schon, den vorhandenen zurückgeben statt doppelt anlegen
        $place_id = isset($p['place_id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $p['place_id']) : null;
        if (!$lid && $place_id && class_exists('TIX_Locations')) {
            $own = TIX_Locations::find_own_by_place($place_id, self::multi() ? intval(TIX_App_Scope::organizer_id_for_user()) : 0);
            if ($own) return rest_ensure_response(['ok' => true, 'existing' => true, 'location' => self::location_payload($own)]);
        }
        if (!$lid || array_key_exists('title', $p)) {
            $title = mb_substr(sanitize_text_field((string) ($p['title'] ?? '')), 0, 120);
            if ($title === '') return self::bad('title', 'Bitte einen Namen für den Ort angeben.');
        }
        if (array_key_exists('accessibility', $p) && class_exists('TIX_Event_Facts')) {
            $acc = $p['accessibility'];
            if ($acc !== null && !is_array($acc)) return self::bad('accessibility', 'Ungültige Angaben zur Barrierefreiheit.');
            $unknown = is_array($acc) ? array_diff(array_map('strval', (array) ($acc['items'] ?? [])), array_keys(TIX_Event_Facts::KEYS)) : [];
            if ($unknown) return self::bad('accessibility.items', 'Unbekannte Angabe: ' . reset($unknown) . '.');
            if (is_array($acc) && mb_strlen((string) ($acc['note'] ?? '')) > 500) return self::bad('accessibility.note', 'Höchstens 500 Zeichen.');
        }
        foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $k => $rng) {
            if (isset($p[$k]) && $p[$k] !== '' && $p[$k] !== null && (!is_numeric($p[$k]) || floatval($p[$k]) < $rng[0] || floatval($p[$k]) > $rng[1])) return self::bad($k, 'Ungültige Koordinate.');
        }
        if (!$lid) {
            $lid = wp_insert_post(['post_type' => 'tix_location', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => get_current_user_id()], true);
            if (is_wp_error($lid)) return new WP_Error('tix_save_failed', 'Ort konnte nicht angelegt werden.', ['status' => 500]);
            if (self::multi() && ($oid = TIX_App_Scope::organizer_id_for_user())) update_post_meta($lid, '_tix_loc_organizer_id', $oid);
            $created = true;
        } elseif (isset($title)) {
            wp_update_post(['ID' => $lid, 'post_title' => $title]);
        }
        foreach ($f as $k => $meta) {
            if (array_key_exists($k, $p)) update_post_meta($lid, $meta, mb_substr(sanitize_text_field((string) $p[$k]), 0, $k === 'address' ? 200 : 80));
        }
        if (array_key_exists('accessibility', $p) && class_exists('TIX_Event_Facts')) {
            update_post_meta($lid, TIX_Event_Facts::META_ACC, TIX_Event_Facts::sanitize_acc(is_array($p['accessibility']) ? $p['accessibility'] : []));
        }
        if (isset($p['lat'], $p['lng']) && is_numeric($p['lat']) && is_numeric($p['lng'])) {
            update_post_meta($lid, '_tix_loc_lat', (string) floatval($p['lat']));
            update_post_meta($lid, '_tix_loc_lng', (string) floatval($p['lng']));
            update_post_meta($lid, '_tix_loc_geo_auto', '0');
        } elseif (array_intersect(array_keys($p), array_keys($f)) && class_exists('TIX_Venues')) {
            // Adresse geändert → Koordinaten wie bisher (Nominatim, nur automatische überschreiben)
            TIX_Venues::maybe_geocode($lid);
        }
        if ($place_id !== null) {
            if ($place_id === '') delete_post_meta($lid, '_tix_loc_place_id'); else update_post_meta($lid, '_tix_loc_place_id', mb_substr($place_id, 0, 300));
        }
        // Öffentlich zusammenführen (gleiche place_id bzw. nah + ähnlicher Name) – nach dem Speichern aller Angaben
        if (self::multi() && class_exists('TIX_Locations')) TIX_Locations::check($lid);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return new WP_REST_Response(['ok' => true, 'location' => self::location_payload($lid)], !empty($created) ? 201 : 200);
    }

    /** DELETE /locations/{id} – nur ohne Events */
    public static function rest_location_delete(WP_REST_Request $req) {
        $lid = absint($req['id']);
        if (get_post_type($lid) !== 'tix_location') return new WP_Error('not_found', 'Ort nicht gefunden.', ['status' => 404]);
        if (!self::location_own($lid)) return new WP_Error('rest_forbidden', 'Diesen Ort kannst du nicht löschen.', ['status' => 403]);
        $n = self::location_events($lid);
        if ($n > 0) return new WP_Error('tix_location_in_use', 'An diesem Ort hängen noch ' . $n . ' Event(s).', ['status' => 409, 'events' => $n]);
        wp_delete_post($lid, true);
        return rest_ensure_response(['ok' => true, 'deleted' => $lid]);
    }
}
