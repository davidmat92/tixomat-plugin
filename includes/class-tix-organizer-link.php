<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Organizer_Link – „Dein Link“ des Veranstalters (Markteintritt, Konzept docs/konzept-veranstalter-shopseite.md).
 * Nur Mehr-Veranstalter-Modus (evendis).
 *
 * Herkunft: utm_source=veranstalter-link & utm_medium=<slug>
 *   - Weiterleitung /v/<slug> der WordPress-Seite hängt sie an (TIX_Admin_Only::customer_path); evendis.de (Next.js)
 *     macht dasselbe in seiner eigenen Weiterleitung.
 *   - Event-Links im Veranstalter-Bereich (`share_url` in GET /events der App) tragen sie ebenfalls.
 *   - Bestellung: App-/Web-Kasse übergibt `attribution {utm_source, utm_medium, utm_campaign, utm_content, referrer}`
 *     (gibt es schon) → `_tix_campaign_source` / `_tix_campaign_medium` an der Bestellung.
 *   - Besuche: POST /public/track mit source=veranstalter-link und medium=<slug> (neu: medium → campaign, wenn leer).
 * REST:
 *   GET /organizer/profile → zusätzlich `link`, `link_source`, `followers {total, account, mail}`, `completeness {percent, items[]}`
 *   GET /organizer/link-stats[?days=30] → {link, days, visits, orders, tickets, revenue, followers, followers_total, followers_new}
 * Follow-Zeitpunkt: User-Meta `_tix_app_following_since` {organizer_id: unix} – wird bei jeder Änderung von
 *   `_tix_app_following` mitgeschrieben (ab 1.38.414; ältere Konto-Follows zählen nicht als „neu“).
 */
class TIX_Organizer_Link {

    const SOURCE = 'veranstalter-link';
    const META_SINCE = '_tix_app_following_since';

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function init() {
        // /organizer/link-stats gibt es seit 1.38.454 auch auf Einzel-Veranstalter-Seiten
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        if (!self::multi()) return;
        add_action('add_user_meta', function ($uid, $key, $value) {
            if ($key === '_tix_app_following') TIX_Organizer_Link::record_since($uid, [], $value);
        }, 10, 3);
        add_action('update_user_meta', function ($mid, $uid, $key, $value) {
            if ($key === '_tix_app_following') TIX_Organizer_Link::record_since($uid, get_user_meta($uid, '_tix_app_following', true), $value);
        }, 10, 4);
    }

    /** Zeitpunkt neuer Follows merken, entfernte vergessen. */
    public static function record_since($uid, $old, $new) {
        $old = is_array($old) ? array_map('intval', $old) : [];
        $new = is_array($new) ? array_map('intval', $new) : [];
        $since = get_user_meta($uid, self::META_SINCE, true);
        $since = is_array($since) ? $since : [];
        foreach ($new as $oid) if (!in_array($oid, $old, true) && !isset($since[$oid])) $since[$oid] = time();
        foreach (array_keys($since) as $oid) if (!in_array(intval($oid), $new, true)) unset($since[$oid]);
        update_user_meta($uid, self::META_SINCE, $since);
    }

    /** Neue Follower seit $ts (Konto + bestätigte Mail-Follower). */
    public static function followers_new($oid, $ts) {
        global $wpdb;
        $n = 0;
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META_SINCE)) as $raw) {
            $map = maybe_unserialize($raw);
            if (is_array($map) && isset($map[intval($oid)]) && intval($map[intval($oid)]) >= $ts) $n++;
        }
        if (class_exists('TIX_Mail_Follow')) $n += TIX_Mail_Follow::count($oid, wp_date('Y-m-d H:i:s', $ts));
        return $n;
    }

    public static function register_routes() {
        register_rest_route('tixomat/v1', '/organizer/link-stats', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_stats'],
            'permission_callback' => self::multi() ? ['TIX_App_Scope', 'check_manager'] : ['TIX_Studio_Stats', 'perm_manager'],
        ]);
    }

    public static function slug($oid) {
        return class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($oid) : (string) get_post_field('post_name', $oid);
    }

    private static function base() {
        $b = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::base() : '';
        return $b !== '' ? $b : untrailingslashit(home_url());
    }

    public static function link($oid) {
        return self::base() . '/v/' . self::slug($oid) . '/';
    }

    public static function source_args($oid) {
        return ['utm_source' => self::SOURCE, 'utm_medium' => self::slug($oid)];
    }

    /** Event-Link mit Herkunft (für Teilen aus dem Veranstalter-Bereich). */
    public static function share_url($event_id, $oid = 0) {
        $oid = $oid ?: intval(get_post_meta($event_id, '_tix_organizer_id', true));
        $url = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to((string) get_permalink($event_id)) : (string) get_permalink($event_id);
        return $oid ? add_query_arg(self::source_args($oid), $url) : $url;
    }

    /** Follower: Konto/App (`_tix_app_following`) + bestätigte Mail-Follower (TIX_Mail_Follow, falls vorhanden). */
    public static function followers($oid) {
        global $wpdb;
        $account = 0;
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", '_tix_app_following')) as $raw) {
            $list = maybe_unserialize($raw);
            if (is_array($list) && in_array(intval($oid), array_map('intval', $list), true)) $account++;
        }
        $mail = class_exists('TIX_Mail_Follow') ? TIX_Mail_Follow::count($oid) : 0;
        return ['total' => $account + $mail, 'account' => $account, 'mail' => $mail];
    }

    /** Checkliste „Deine Seite ist zu N % fertig“. */
    public static function completeness($oid) {
        $m = function ($k) use ($oid) { return get_post_meta($oid, $k, true); };
        $social = $m('_tix_org_landing_social');
        $social = is_array($social) ? $social : [];
        $desc = wp_strip_all_tags((string) ($m('_tix_org_landing_description') ?: $m('_tix_org_description')));
        $next = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) intval($oid)], ['key' => '_thumbnail_id', 'compare' => 'EXISTS']]]);
        $items = [
            ['key' => 'logo',        'label' => 'Logo',                 'done' => (bool) ($m('_tix_org_landing_logo_id') ?: $m('_tix_org_image_id'))],
            ['key' => 'hero',        'label' => 'Titelbild',            'done' => (bool) $m('_tix_org_landing_hero_id')],
            ['key' => 'description', 'label' => 'Beschreibung',         'done' => mb_strlen(trim($desc)) >= 40],
            ['key' => 'social',      'label' => 'Instagram oder Website', 'done' => !empty($social['instagram']) || !empty($social['website']) || (string) $m('_tix_org_website') !== ''],
            ['key' => 'contact',     'label' => 'Kontakt (E-Mail)',     'done' => is_email((string) $m('_tix_org_email'))],
            ['key' => 'event',       'label' => 'Erstes Event mit Bild', 'done' => (bool) $next],
        ];
        $done = count(array_filter(array_column($items, 'done')));
        return ['percent' => (int) round($done / count($items) * 100), 'items' => $items];
    }

    /** Zusatzfelder für /organizer/profile. */
    public static function profile_extra($oid) {
        return [
            'link'         => self::link($oid),
            'link_source'  => self::source_args($oid),
            'followers'    => self::followers($oid),
            'completeness' => self::completeness($oid),
        ];
    }

    /**
     * $oid = 0: Einzel-Veranstalter-Seite – alle Events, Herkunft nur über utm_source (ohne utm_medium).
     * $event_id: nur dieses Event; Besuche = Aufrufe der Event-Seite über den Link (Kampagnen-Zähler).
     */
    public static function stats($oid, $days = 30, $event_id = 0) {
        global $wpdb;
        $days = max(1, min(365, intval($days)));
        $since = gmdate('Y-m-d', current_time('timestamp') - ($days - 1) * DAY_IN_SECONDS);
        $slug = $oid ? self::slug($oid) : '';
        // Besuche: Sitzungen mit Herkunft „veranstalter-link“ und Kampagne = Slug (eigene Statistik)
        $visits = 0;
        $vt = $wpdb->prefix . 'tixomat_campaign_views';
        if ($event_id) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vt)) === $vt) {
                $visits = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(views),0) FROM $vt WHERE event_id = %d AND source = %s AND view_date >= %s" . ($slug !== '' ? ' AND campaign = %s' : ''),
                    ...array_merge([intval($event_id), sanitize_key(self::SOURCE), $since], $slug !== '' ? [$slug] : [])
                )));
            }
        } elseif (class_exists('TIX_Site_Analytics')) {
            $t = TIX_Site_Analytics::table_name();
            if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
                $visits = intval($wpdb->get_var($slug !== '' ? $wpdb->prepare(
                    "SELECT COUNT(DISTINCT session_id) FROM $t WHERE source = %s AND campaign = %s AND view_date >= %s",
                    sanitize_key(self::SOURCE), $slug, $since
                ) : $wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $t WHERE source = %s AND view_date >= %s", sanitize_key(self::SOURCE), $since)));
            }
        }
        // Käufe: bezahlte Bestellungen mit dieser Herkunft; Umsatz = eigene Positionen
        $orders = 0; $tickets = 0; $revenue = 0.0;
        if (class_exists('TIX_Order')) {
            $o = TIX_Order::table_name(); $i = TIX_Order::items_table_name(); $pm = $wpdb->postmeta;
            $rows = $wpdb->get_results($slug !== '' ? $wpdb->prepare(
                "SELECT o.id FROM $o o
                 JOIN $pm s ON s.post_id = o.id AND s.meta_key = '_tix_campaign_source' AND s.meta_value = %s
                 JOIN $pm m ON m.post_id = o.id AND m.meta_key = '_tix_campaign_medium' AND m.meta_value = %s
                 WHERE o.status IN ('completed','processing') AND o.date_created >= %s",
                sanitize_key(self::SOURCE), $slug, $since . ' 00:00:00'
            ) : $wpdb->prepare(
                "SELECT o.id FROM $o o
                 JOIN $pm s ON s.post_id = o.id AND s.meta_key = '_tix_campaign_source' AND s.meta_value = %s
                 WHERE o.status IN ('completed','processing') AND o.date_created >= %s",
                sanitize_key(self::SOURCE), $since . ' 00:00:00'
            ));
            $ids = array_map('intval', wp_list_pluck((array) $rows, 'id'));
            if ($ids) {
                $events = $event_id ? [intval($event_id)] : ($oid ? array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
                    'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) intval($oid)]]])) : array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids'])));
                if ($events) {
                    $r = $wpdb->get_row($wpdb->prepare(
                        "SELECT COUNT(DISTINCT order_id) AS n, COALESCE(SUM(quantity),0) AS q, COALESCE(SUM(total),0) AS t FROM $i
                         WHERE order_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ") AND event_id IN (" . implode(',', array_fill(0, count($events), '%d')) . ")",
                        ...array_merge($ids, $events)
                    ));
                    $orders = intval($r->n); $tickets = intval($r->q); $revenue = round(floatval($r->t), 2);
                }
            }
        }
        $followers = $oid ? self::followers($oid) : ['total' => 0, 'account' => 0, 'mail' => 0];
        $link = $oid ? self::link($oid) : add_query_arg(['utm_source' => self::SOURCE], self::base() . '/');
        if ($event_id) $link = $oid ? self::share_url($event_id, $oid) : add_query_arg(['utm_source' => self::SOURCE], class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to((string) get_permalink($event_id)) : (string) get_permalink($event_id));
        return ['link' => $link, 'event_id' => $event_id ? intval($event_id) : null, 'days' => $days, 'visits' => $visits, 'orders' => $orders, 'tickets' => $tickets, 'revenue' => $revenue,
            'followers' => $followers, 'followers_total' => $followers['total'], 'followers_new' => $oid ? self::followers_new($oid, strtotime($since . ' 00:00:00 ' . wp_timezone_string()) ?: 0) : 0];
    }

    public static function rest_stats(WP_REST_Request $req) {
        $oid = self::multi() ? TIX_App_Scope::organizer_id_for_user() : 0;
        if (self::multi() && !$oid) return new WP_Error('tix_no_organizer', 'Kein Veranstalter-Profil gefunden.', ['status' => 404]);
        $eid = absint($req->get_param('event_id'));
        if ($eid && (get_post_type($eid) !== 'event' || !TIX_REST_API::can_access_event($eid))) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        return rest_ensure_response(['ok' => true] + self::stats($oid, $req->get_param('days') ?: 30, $eid));
    }
}
