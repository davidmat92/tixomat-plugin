<?php
if (!defined('ABSPATH')) exit;

/**
 * Co-Veranstalter: wer nimmt das Geld ein + Anteil (1.38.711, Vertrag `uebergaben/vertrag-co-geldempfaenger.md`).
 *
 * Paket 1: Datenmodell je Event (`_tix_co_money` creator|co, `_tix_co_share` 0–100), Sperre nach dem ersten bezahlten
 * Verkauf, Abrechnung auf evendis.de an den Geldempfänger mit Anteil (Position `co_share`), Ausgleichs-Abrechnung
 * `GET /events/{id}/co-balance` (+ PDF) an der Verkaufsinstanz.
 */
class TIX_Co_Money {
    const NS          = 'tixomat/v1';
    const META_OWNER  = '_tix_co_money';
    const META_SHARE  = '_tix_co_share';
    const META_BY     = '_tix_co_money_by';
    const OWNERS      = ['creator', 'co']; // Alt; seit 1.38.716 auch die ID eines Co (owner_id)
    const FINAL_DAYS  = 14;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function dec($s) {
        return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8');
    }

    // ═══════════════ Grundlagen ═══════════════
    //
    // Mehrere Co (1.38.716, Vertrag `uebergaben/vertrag-co-mehrere.md`):
    //   _tix_co_money   'creator' | 'o:{Veranstalter-ID}' (Alt: 'co' = erster Co)
    //   _tix_co_shares  {'creator' => %, 'o:{ID}' => %} – Anteile am Netto je Beteiligtem, Summe 100 (Rest beim Geldempfänger)
    //   _tix_co_share   abgeleitet: Summe der Anteile, die nicht beim Geldempfänger bleiben (alte Leser)
    // Veranstalter-IDs sind immer die der Plattform (auch auf der Lizenzseite: die von evendis.de gemeldete ID).

    const META_SHARES = '_tix_co_shares';
    const META_PARTS  = '_tix_co_parts'; // Kunden-Seite: [{name, percent}] der Beteiligten, die einen Anteil bekommen

    /** IDs der Co am Event (je nach Art). */
    public static function co_ids($eid) {
        switch (self::co_kind($eid)) {
            case 'local':  return TIX_Co_Organizer::ids($eid);
            case 'mirror': return TIX_Co_Remote::mirror_ids($eid);
            case 'remote': return TIX_Co_Remote::ids($eid);
        }
        return [];
    }

    private static function co_access($eid, $oid) {
        switch (self::co_kind($eid)) {
            case 'local':  return TIX_Co_Organizer::has_access($eid, $oid);
            case 'mirror': return TIX_Co_Remote::mirror_has_access($eid, $oid);
            case 'remote': return !empty(TIX_Co_Remote::payload($eid, $oid)['access']);
        }
        return false;
    }

    private static function co_name($eid, $oid) {
        if (self::co_kind($eid) === 'remote') return (string) (TIX_Co_Remote::payload($eid, $oid)['name'] ?? '');
        return self::dec(get_the_title(intval($oid)));
    }

    /** Geldempfänger: 0 = Ersteller, sonst Veranstalter-ID des Co. */
    public static function owner_id($eid) {
        $v = (string) get_post_meta(intval($eid), self::META_OWNER, true);
        $ids = self::co_ids($eid);
        if ($v === 'co') return $ids[0] ?? 0;
        if (strpos($v, 'o:') === 0) { $o = intval(substr($v, 2)); return in_array($o, $ids, true) ? $o : 0; }
        return 0;
    }

    /** creator | co (Alt-Schnittstelle). Kunden-Seite: 'co' = diese Seite verkauft. */
    public static function owner($eid) {
        if (self::co_kind($eid) === 'platform_co' || !self::co_kind($eid)) return get_post_meta(intval($eid), self::META_OWNER, true) === 'co' ? 'co' : 'creator';
        return self::owner_id($eid) ? 'co' : 'creator';
    }

    private static function party($oid) {
        return intval($oid) ? 'o:' . intval($oid) : 'creator';
    }

    private static function pid($party) {
        return $party === 'creator' ? 0 : intval(substr((string) $party, 2));
    }

    /** Anteile je Beteiligtem (nur aktuelle Beteiligte, Summe 100, Rest beim Geldempfänger). */
    public static function shares($eid) {
        $eid = intval($eid);
        $parties = array_merge(['creator'], array_map([__CLASS__, 'party'], self::co_ids($eid)));
        $own = self::party(self::owner_id($eid));
        $raw = get_post_meta($eid, self::META_SHARES, true);
        $map = [];
        if (is_array($raw) && $raw) {
            foreach ($raw as $k => $v) if (in_array((string) $k, $parties, true) && $k !== $own) $map[(string) $k] = max(0.0, round(floatval($v), 2));
        } else { // Alt: ein Anteil an „die andere Partei“
            $s = get_post_meta($eid, self::META_SHARE, true);
            $s = $s === '' ? 0.0 : max(0.0, min(100.0, round(floatval($s) * 2) / 2));
            if ($s > 0) {
                $other = $own === 'creator' ? ($parties[1] ?? null) : 'creator';
                if ($other) $map[$other] = $s;
            }
        }
        $rest = round(100 - array_sum($map), 2);
        if ($rest < 0) { $map = []; $rest = 100.0; } // unstimmig → alles beim Geldempfänger
        return [$own => $rest] + $map;
    }

    /** Summe der Anteile, die nicht beim Geldempfänger bleiben (Alt-Schnittstelle „share“). */
    public static function share($eid) {
        if (self::co_kind($eid) === 'platform_co' || !self::co_kind($eid)) {
            $v = get_post_meta(intval($eid), self::META_SHARE, true);
            return $v === '' ? 0.0 : max(0.0, min(100.0, round(floatval($v) * 100) / 100));
        }
        $m = self::shares($eid);
        $own = self::party(self::owner_id($eid));
        return round(array_sum(array_diff_key($m, [$own => 0])), 2);
    }

    /** Art des Co am Event: local (Teil A) | mirror (Teil-B-Spiegel auf der Plattform) | remote (Lizenzseite) | platform_co (Anzeige-Spiegel einer Kunden-Seite) | null */
    public static function co_kind($eid) {
        $eid = intval($eid);
        if (self::multi()) {
            if (get_post_meta($eid, '_tix_syndicated', true) === '1') return class_exists('TIX_Co_Remote') && TIX_Co_Remote::mirror_ids($eid) ? 'mirror' : null;
            return class_exists('TIX_Co_Organizer') && TIX_Co_Organizer::ids($eid) ? 'local' : null;
        }
        if (class_exists('TIX_Site_Move') && TIX_Site_Move::platform_co_id($eid)) return 'platform_co';
        return class_exists('TIX_Co_Remote') && TIX_Co_Remote::ids($eid) ? 'remote' : null;
    }

    private static function creator_name($eid) {
        $eid = intval($eid);
        $site = self::dec(get_bloginfo('name'));
        $creator = self::dec(get_the_title(intval(get_post_meta($eid, '_tix_organizer_id', true)))) ?: $site;
        if (self::co_kind($eid) === 'platform_co') $creator = (string) get_post_meta($eid, '_tix_platform_co_by', true) ?: $creator;
        if (get_post_meta($eid, '_tix_syndicated', true) === '1') $creator = (string) (get_post_meta($eid, '_tix_source_site', true) ?: $creator);
        return $creator;
    }

    private static function party_name($eid, $party) {
        return $party === 'creator' ? self::creator_name($eid) : self::co_name($eid, self::pid($party));
    }

    /** Namen: [Ersteller, Geldempfänger-Seite des Co bzw. erster Co] (Alt-Schnittstelle). */
    private static function names($eid) {
        if (self::co_kind($eid) === 'platform_co') return [self::creator_name($eid), self::dec(get_bloginfo('name'))];
        $o = self::owner_id($eid);
        $ids = self::co_ids($eid);
        return [self::creator_name($eid), self::co_name($eid, $o ?: ($ids[0] ?? 0))];
    }

    private static function host() {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(class_exists('TIX_Customer_URL') && TIX_Customer_URL::host() !== '' ? 'https://' . TIX_Customer_URL::host() : home_url(), PHP_URL_HOST)));
    }

    /** Gibt es eine bezahlte Bestellung (auch vermittelt, auch Kasse)? */
    public static function sold($eid) {
        global $wpdb;
        $eid = intval($eid);
        $o = $wpdb->prefix . 'tix_orders'; $oi = $wpdb->prefix . 'tix_order_items';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $oi)) === $oi) {
            $n = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$o} o JOIN {$oi} i ON i.order_id = o.id WHERE i.event_id = %d AND o.status IN ('completed','processing','refunded') AND o.total > 0", $eid)));
            if ($n) return true;
        }
        $pt = $wpdb->prefix . 'tix_partner_orders';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pt)) === $pt) {
            if (intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$pt} WHERE event_id = %d AND paid_at IS NOT NULL", $eid)))) return true;
        }
        return (bool) apply_filters('tix_co_money_sold', false, $eid);
    }

    /**
     * Kann Co $oid hier das Geld einnehmen? Nur mit Zugriff; eine Kunden-Seite nur, wenn sie mit der Plattform gekoppelt ist.
     * $access = Zugriff vorgeben (neuer Co, noch nicht gespeichert).
     */
    public static function owner_ok($eid, $oid, $access = null) {
        $oid = intval($oid);
        $k = self::co_kind($eid);
        if ($access === null) $access = $oid > 0 && self::co_access($eid, $oid);
        if (!$access) return false;
        if (in_array($k, ['local', 'mirror', null], true) && self::multi() && $oid > 0 && class_exists('TIX_Co_Seller')) {
            if (class_exists('TIX_Co_Sites') && TIX_Co_Sites::is_site_profile($oid) && !TIX_Co_Seller::site_partner($oid)) return false;
        }
        return true;
    }

    /** Wählbare Geldempfänger: [0 (Ersteller), Co-IDs …]. */
    public static function choosable_ids($eid) {
        $out = [0];
        foreach (self::co_ids($eid) as $o) if (self::owner_ok($eid, $o)) $out[] = $o;
        return $out;
    }

    /** creator | co (Alt-Schnittstelle). */
    public static function choosable($eid) {
        return count(self::choosable_ids($eid)) > 1 ? ['creator', 'co'] : ['creator'];
    }

    /** Alt (bis 1.38.715). */
    public static function choosable_for_new($eid, $access) {
        return $access ? ['creator', 'co'] : ['creator'];
    }

    private static function can_edit($eid) {
        if (self::multi()) return class_exists('TIX_Co_Organizer') && in_array(TIX_Co_Organizer::role($eid), ['owner', 'admin'], true);
        $u = wp_get_current_user();
        return $u && $u->ID && ($u->has_cap('manage_options') || in_array('tix_organizer', (array) $u->roles, true));
    }

    /** Editor/Plattform-Zeile: co_money oder null (kein Co). */
    public static function payload($eid) {
        $eid = intval($eid);
        $k = self::co_kind($eid);
        if (!$k) return null;
        if ($k === 'platform_co') return self::payload_site($eid);
        $oid = self::owner_id($eid);
        $owner = $oid ? 'co' : 'creator';
        $map = self::shares($eid);
        $share = self::share($eid);
        $ch = self::choosable_ids($eid);
        $sold = self::sold($eid);
        $readonly = $k === 'mirror'; // Werte kommen vom Ersteller
        $seller_name = self::party_name($eid, self::party($oid));
        $seller_host = !$oid && in_array($k, ['local', 'remote'], true) ? self::host() : null;
        $note = 'Verkauf, Zahlung, Tickets und Erstattungen laufen über ' . ($seller_host ?: $seller_name) . '.';
        $parts = [];
        foreach ($map as $party => $pct) {
            if ($party === self::party($oid)) continue;
            if ($pct > 0) $parts[] = self::pct($pct) . ' % an ' . self::party_name($eid, $party);
        }
        if ($parts) $note .= ' Vom Netto gehen ' . implode(', ', $parts) . (self::settles_here($eid) ? ' (mit der Abrechnung).' : ' (Ausgleich zwischen euch).');
        $list = [];
        foreach (array_merge(['creator'], array_map([__CLASS__, 'party'], self::co_ids($eid))) as $party) {
            $id = self::pid($party);
            $list[] = ['party' => $party === 'creator' ? 'creator' : 'co', 'organizer_id' => $id, 'name' => self::party_name($eid, $party),
                'percent' => floatval($map[$party] ?? 0), 'owner' => $id === $oid, 'can_own' => in_array($id, $ch, true)];
        }
        $first_other = null;
        foreach ($map as $party => $pct) if ($party !== self::party($oid) && $pct > 0) { $first_other = $party; break; }
        return ['owner' => $owner, 'owner_id' => $oid, 'share' => $share, 'share_to' => $owner === 'co' ? 'creator' : 'co',
            'shares' => $list, 'choosable' => $readonly ? [$owner] : self::choosable($eid), 'choosable_ids' => $readonly ? [$oid] : $ch,
            'locked' => $sold || $readonly, 'locked_reason' => $sold ? 'sold' : ($readonly ? 'not_supported' : (count($ch) < 2 && count($list) < 2 ? 'not_supported' : null)),
            'editable' => !$sold && !$readonly && self::can_edit($eid),
            'seller' => ['name' => $seller_name, 'host' => $seller_host, 'side' => $owner, 'organizer_id' => $oid], 'note' => $note,
            'share_to_name' => $first_other ? self::party_name($eid, $first_other) : null,
            'status' => self::pending($eid) ? 'waiting_consent' : 'active', 'pending' => self::pending_payload($eid), 'consent_version' => self::CONSENT_VERSION];
    }

    /** Kunden-Seite (Anzeige-Spiegel): Werte vom Ersteller, nur lesen. */
    private static function payload_site($eid) {
        $owner = self::owner($eid);
        $share = self::share($eid);
        [$creator, $co] = self::names($eid);
        $parts = self::site_parts($eid);
        $note = 'Verkauf, Zahlung, Tickets und Erstattungen laufen über ' . ($owner === 'co' ? $co : $creator) . '.';
        if ($parts) $note .= ' Vom Netto gehen ' . implode(', ', array_map(function ($p) { return self::pct($p['percent']) . ' % an ' . $p['name']; }, $parts)) . ' (Ausgleich zwischen euch).';
        return ['owner' => $owner, 'owner_id' => 0, 'share' => $share, 'share_to' => $owner === 'co' ? 'creator' : 'co', 'shares' => [], 'choosable' => [$owner], 'choosable_ids' => [],
            'locked' => true, 'locked_reason' => self::sold($eid) ? 'sold' : 'not_supported', 'editable' => false,
            'seller' => ['name' => $owner === 'co' ? $co : $creator, 'host' => null, 'side' => $owner, 'organizer_id' => 0], 'note' => $note, 'share_to_name' => $parts[0]['name'] ?? null,
            'status' => ($sp = get_post_meta(intval($eid), self::META_SITE_PENDING, true)) ? 'waiting_consent' : 'active', 'pending' => $sp ?: null, 'consent_version' => self::CONSENT_VERSION];
    }

    /** Kunden-Seite: Beteiligte mit Anteil (vom Abgleich) – Alt: der Ersteller mit `share`. */
    private static function site_parts($eid) {
        $p = get_post_meta(intval($eid), self::META_PARTS, true);
        if (is_array($p) && $p) return array_values(array_filter(array_map(function ($x) { return is_array($x) ? ['name' => (string) ($x['name'] ?? ''), 'percent' => floatval($x['percent'] ?? 0)] : null; }, $p), function ($x) { return $x && $x['percent'] > 0; }));
        $s = self::share($eid);
        return $s > 0 ? [['name' => self::creator_name($eid), 'percent' => $s]] : [];
    }

    private static function pct($v) {
        return rtrim(rtrim(number_format(floatval($v), 2, ',', ''), '0'), ',');
    }

    /** Wird das Event auf dieser Seite über die Plattform-Abrechnung abgerechnet? */
    private static function settles_here($eid) {
        return self::multi() && class_exists('TIX_Settlement') && get_post_meta(intval($eid), '_tix_syndicated', true) !== '1';
    }

    // ═══════════════ Editor ═══════════════

    /** Angabe für den Geldempfänger → 0 (Ersteller) | ID | -1 (der neue Co) | null (ungültig). */
    private static function owner_in($v, $ids, $new) {
        if ($v === null || $v === '' || $v === 'creator' || $v === 0 || $v === '0') return 0;
        if ($v === 'co') {
            if ($new) return $new;
            return count($ids) === 1 ? $ids[0] : null;
        }
        if (is_string($v) && strpos($v, 'o:') === 0) $v = substr($v, 2);
        if (is_numeric($v) && intval($v) > 0) return intval($v);
        return null;
    }

    /**
     * Feld `co_money` {owner?, owner_id?, share?, shares?} bzw. Angaben beim Verbinden prüfen → null (unverändert) | ['owner' => party, 'shares' => map] | WP_Error.
     * $ctx: ['new' => ID des Co, der gerade hinzukommt (-1 = noch unbekannt, Lizenzseite), 'new_access' => bool] (beim Verbinden).
     * Alt-Aufruf (bis 1.38.715): check_values($eid, $in, $co_after, $choosable) – Parameter 3/4 werden ignoriert.
     */
    public static function check_values($eid, array $in, $ctx = [], $unused = null) {
        $eid = intval($eid);
        $ctx = is_array($ctx) ? $ctx : [];
        if (array_key_exists('owner_id', $in) && !array_key_exists('owner', $in)) $in['owner'] = $in['owner_id'];
        if (!array_key_exists('owner', $in) && !array_key_exists('share', $in) && !array_key_exists('shares', $in)) return null;
        $new = intval($ctx['new'] ?? 0);
        $ids = $eid ? self::co_ids($eid) : [];
        $all = $ids;
        if ($new && !in_array($new, $ids, true)) $all[] = $new; // -1 = Platzhalter
        $cur_owner = $eid ? self::owner_id($eid) : 0;
        // Geldempfänger
        $owner = $cur_owner;
        if (array_key_exists('owner', $in) && $in['owner'] !== null) {
            $owner = self::owner_in($in['owner'], $ids, $new);
            if ($owner === null) return new WP_Error('tix_invalid_field', count($ids) > 1 ? 'Geldempfänger: creator oder die ID eines Co-Veranstalters.' : 'Geldempfänger: creator oder co.', ['status' => 400, 'field' => 'co_money.owner']);
            if ($owner && !in_array($owner, $all, true)) return new WP_Error('tix_invalid_field', 'Dieser Veranstalter ist kein Co-Veranstalter des Events.', ['status' => 400, 'field' => 'co_money.owner']);
        }
        $party = function ($o) { return $o === -1 ? 'o:new' : self::party($o); };
        $own = $party($owner);
        // Anteile
        $map = $eid ? self::shares($eid) : ['creator' => 100.0];
        $cur_map = $map;
        if (array_key_exists('shares', $in) && $in['shares'] !== null) {
            if (!is_array($in['shares'])) return new WP_Error('tix_invalid_field', 'shares: Liste [{organizer_id, percent}].', ['status' => 400, 'field' => 'co_money.shares']);
            $map = [];
            foreach ($in['shares'] as $k => $row) {
                if (is_array($row)) { $who = $row['party'] ?? ($row['organizer_id'] ?? null); $pc = $row['percent'] ?? null; }
                else { $who = $k; $pc = $row; }
                if ($who === 'co' && $new) $o = $new;
                elseif ($who === 'creator' || $who === 0 || $who === '0') $o = 0;
                else $o = self::owner_in($who, [], 0);
                if ($o === null || ($o && !in_array($o, $all, true))) return new WP_Error('tix_invalid_field', 'shares: unbekannter Beteiligter.', ['status' => 400, 'field' => 'co_money.shares']);
                if (!is_numeric($pc) || floatval($pc) < 0 || floatval($pc) > 100) return new WP_Error('tix_invalid_field', 'Anteil: 0 bis 100 Prozent.', ['status' => 400, 'field' => 'co_money.shares']);
                $key = $party($o);
                if (isset($map[$key])) return new WP_Error('tix_invalid_field', 'shares: jeder Beteiligte nur einmal.', ['status' => 400, 'field' => 'co_money.shares']);
                $map[$key] = round(floatval($pc), 2);
            }
            if (abs(array_sum($map) - 100) > 0.01) return new WP_Error('tix_co_shares_sum', 'Die Anteile müssen zusammen 100 % ergeben.', ['status' => 400, 'field' => 'co_money.shares']);
        } elseif (array_key_exists('share', $in) && $in['share'] !== null && $in['share'] !== '') {
            if (!is_numeric($in['share']) || floatval($in['share']) < 0 || floatval($in['share']) > 100) return new WP_Error('tix_invalid_field', 'Anteil: 0 bis 100 Prozent.', ['status' => 400, 'field' => 'co_money.share']);
            $s = round(floatval($in['share']) * 2) / 2;
            // Alt-Feld: Anteil „der anderen Seite“. Beim Verbinden = Anteil des neuen Co (bzw. des Erstellers, wenn der neue Co einnimmt).
            if ($new) $other = $own === $party($new) ? 'creator' : $party($new);
            else {
                $parties = array_merge(['creator'], array_map($party, $ids));
                $others = array_values(array_diff($parties, [$own]));
                if (count($others) > 1 && $s > 0) return new WP_Error('tix_invalid_field', 'Bei mehreren Co-Veranstaltern bitte die Anteile je Beteiligtem angeben (shares).', ['status' => 400, 'field' => 'co_money.shares']);
                $other = $others[0] ?? null;
            }
            $keep = [];
            foreach ($map as $k => $v) if ($k !== $own && $k !== $other && $v > 0) $keep[$k] = $v; // andere Anteile bleiben
            if ($other !== null && $s > 0) $keep[$other] = $s;
            if (array_sum($keep) > 100.001) return new WP_Error('tix_co_shares_sum', 'Die Anteile ergeben zusammen mehr als 100 %.', ['status' => 400, 'field' => 'co_money.share']);
            $map = [$own => round(100 - array_sum($keep), 2)] + $keep;
        } else {
            // nur Geldempfänger geändert
            $old_own = self::party($cur_owner);
            if ($own !== $old_own) {
                $parties = array_merge(['creator'], array_map($party, $all));
                if (count($parties) === 2) { // wie bisher: der Anteil der anderen Seite bleibt, die Seiten tauschen
                    $s = round(100 - floatval($map[$old_own] ?? 100), 2);
                    $map = [$own => round(100 - $s, 2)] + ($s > 0 ? [$old_own => $s] : []);
                } else { // der neue Geldempfänger übernimmt den Rest, der bisherige bekommt nichts
                    unset($map[$old_own], $map[$own]);
                    $map = [$own => round(100 - array_sum($map), 2)] + $map;
                }
            }
        }
        foreach ($map as $k => $v) if ($v <= 0 && $k !== $own) unset($map[$k]);
        if (!isset($map[$own])) $map = [$own => round(100 - array_sum($map), 2)] + $map;
        $changed = $own !== self::party($cur_owner) || self::map_diff($map, $cur_map);
        if (!$changed) return null;
        if ($eid && self::sold($eid)) return new WP_Error('tix_co_money_locked', 'Nach dem ersten bezahlten Verkauf lassen sich Geldempfänger und Anteile nicht mehr ändern.', ['status' => 409]);
        if ($owner) {
            if ($owner === -1) $ok = !empty($ctx['new_access']); // Lizenzseite, neuer Co: die Plattform prüft beim Umstellen
            elseif ($owner === $new && array_key_exists('new_access', $ctx)) $ok = self::owner_ok($eid, $owner, !empty($ctx['new_access']));
            else $ok = self::owner_ok($eid, $owner);
            if (!$ok) return new WP_Error('tix_co_money_unsupported', 'Dieser Co-Veranstalter kann bei diesem Event (noch) nicht das Geld einnehmen.', ['status' => 409, 'field' => 'co_money.owner']);
        }
        return ['owner' => $own, 'shares' => $map];
    }

    private static function map_diff(array $a, array $b) {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) if (abs(floatval($a[$k] ?? 0) - floatval($b[$k] ?? 0)) > 0.001) return true;
        return false;
    }

    /** Platzhalter des neuen Co durch seine ID ersetzen. */
    public static function resolve_new($c, $oid) {
        if (!is_array($c)) return $c;
        $p = 'o:' . intval($oid);
        if (($c['owner'] ?? '') === 'o:new') $c['owner'] = $p;
        if (isset($c['shares']['o:new'])) { $c['shares'][$p] = $c['shares']['o:new']; unset($c['shares']['o:new']); }
        return $c;
    }

    /** POST /events/{id} mit `co_money` (Ersteller/Admin). */
    public static function check(array $body, $post_id, $is_new) {
        if (!array_key_exists('co_money', $body)) return null;
        if ($is_new) return new WP_Error('tix_invalid_field', 'Geldempfänger erst nach dem Verbinden des Co-Veranstalters wählen.', ['status' => 400, 'field' => 'co_money']);
        if (!self::can_edit($post_id)) return new WP_Error('tix_co_forbidden', 'Den Geldempfänger kann nur der Ersteller des Events ändern.', ['status' => 403]);
        $k = self::co_kind($post_id);
        if (in_array($k, ['mirror', 'platform_co'], true)) return new WP_Error('tix_co_forbidden', 'Den Geldempfänger legt die Seite des Erstellers fest.', ['status' => 403]);
        $v = $body['co_money'];
        if (!is_array($v)) return new WP_Error('tix_invalid_field', 'co_money: {owner, share} oder {owner_id, shares}.', ['status' => 400, 'field' => 'co_money']);
        if (!$k) return new WP_Error('tix_invalid_field', 'Ohne Co-Veranstalter gibt es keinen Geldempfänger zu wählen.', ['status' => 400, 'field' => 'co_money']);
        $c = self::check_values($post_id, $v);
        // Lizenzseite: die Plattform prüft, holt die Zustimmungen und übernimmt (1.38.713/717); hier nichts mehr anzuwenden
        if (is_array($c) && $k === 'remote' && class_exists('TIX_Co_Remote')) {
            $r = self::remote_set($post_id, $c, 'creator');
            return is_wp_error($r) ? $r : null;
        }
        return $c;
    }

    public static function apply($eid, $c, $via = 'creator') {
        if (!is_array($c)) return;
        if (!isset($c['shares']) && isset($c['share'])) { // Alt-Form {owner: creator|co, share}
            $c = self::check_values($eid, ['owner' => $c['owner'], 'share' => $c['share']]) ?: ['owner' => self::party(self::owner_id($eid)), 'shares' => self::shares($eid)];
            if (is_wp_error($c)) return;
        }
        $own = (string) $c['owner'];
        $map = (array) $c['shares'];
        update_post_meta($eid, self::META_OWNER, $own);
        update_post_meta($eid, self::META_SHARES, $map);
        $other = round(array_sum(array_diff_key($map, [$own => 0])), 2);
        update_post_meta($eid, self::META_SHARE, (string) $other);
        update_post_meta($eid, self::META_BY, ['at' => gmdate('c'), 'by' => get_current_user_id(), 'via' => $via]);
        do_action('tix_co_money_changed', intval($eid), $own === 'creator' ? 'creator' : 'co', $other);
    }

    /**
     * Alt-Werte (bis 1.38.715: `co` = der eine Co, `_tix_co_share`) auf IDs umschreiben. Wird vor jeder Änderung einer Co-Liste
     * aufgerufen (mit der alten Liste), damit „co“ beim Hinzufügen/Lösen weiterer Co eindeutig bleibt.
     */
    public static function normalize($eid) {
        $eid = intval($eid);
        $k = self::co_kind($eid);
        if (!in_array($k, ['local', 'mirror', 'remote'], true)) return;
        $raw = (string) get_post_meta($eid, self::META_OWNER, true);
        $has_map = is_array(get_post_meta($eid, self::META_SHARES, true));
        if ($raw !== 'co' && $has_map) return;
        if ($raw === '' && get_post_meta($eid, self::META_SHARE, true) === '') return; // nie gesetzt
        $own = self::party(self::owner_id($eid));
        $map = self::shares($eid);
        update_post_meta($eid, self::META_OWNER, $own);
        update_post_meta($eid, self::META_SHARES, $map);
    }

    /** Alles gelöst: zurück auf Ersteller ohne Anteil. */
    public static function reset($eid) {
        foreach ([self::META_OWNER, self::META_SHARE, self::META_SHARES, self::META_BY, self::META_PENDING] as $k) delete_post_meta($eid, $k);
    }

    /**
     * Co $oid gelöst ($access_only = nur Zugriff weg): nimmt er das Geld ein, geht das zurück an den Ersteller.
     * Beim Lösen fällt sein Anteil an den Geldempfänger. (Gesperrt nach Verkauf: vorher über can_clear bzw. die Zugriffs-Prüfung.)
     */
    public static function on_co_lost($eid, $oid, $access_only) {
        $eid = intval($eid);
        $pp = self::pending($eid);
        if ($pp && (in_array(self::party($oid), (array) $pp['needs'], true) || isset($pp['shares'][self::party($oid)]))) delete_post_meta($eid, self::META_PENDING);
        $raw_owner = (string) get_post_meta($eid, self::META_OWNER, true);
        $p = self::party($oid);
        $is_owner = $raw_owner === $p || ($raw_owner === 'co' && $access_only && (self::co_ids($eid)[0] ?? 0) === intval($oid)); // 'co' nur noch ohne Listenänderung (normalize)
        $map = get_post_meta($eid, self::META_SHARES, true);
        $map = is_array($map) ? $map : [];
        $changed = false;
        if ($is_owner) {
            $mine = floatval($map[$p] ?? 0);
            unset($map[$p]);
            $raw_owner = 'creator';
            update_post_meta($eid, self::META_OWNER, 'creator');
            if ($access_only && $mine > 0) $map[$p] = $mine; // bleibt beteiligt
            $changed = true;
        }
        if (!$access_only && isset($map[$p])) { unset($map[$p]); $changed = true; }
        if (!$changed) return;
        if (!$map && !$raw_owner) { self::reset($eid); return; }
        update_post_meta($eid, self::META_SHARES, $map);
        $own = $raw_owner ?: 'creator';
        update_post_meta($eid, self::META_SHARE, (string) round(array_sum(array_diff_key($map, [$own => 0])), 2));
        do_action('tix_co_money_changed', $eid, $own === 'creator' ? 'creator' : 'co', self::share($eid));
    }

    /**
     * Lösen erlaubt? $oid = ein Co (0 = alle). Nimmt er Geld ein bzw. bekommt er einen Anteil, nur ohne bezahlte Bestellung.
     */
    public static function can_clear($eid, $oid = 0) {
        if ($oid) {
            $in = self::owner_id($eid) === intval($oid) || floatval(self::shares($eid)[self::party($oid)] ?? 0) > 0;
            if (!$in || !self::sold($eid)) return true;
        } else {
            if (self::owner($eid) === 'creator' && self::share($eid) <= 0) return true;
            if (!self::sold($eid)) return true;
        }
        return new WP_Error('tix_co_money_locked', 'Der Co-Veranstalter nimmt Geld ein bzw. bekommt einen Anteil, und es gibt schon Verkäufe. Die Verbindung kann erst nach der Abrechnung gelöst werden.', ['status' => 409]);
    }

    // ═══════════════ Zustimmung (1.38.717, Rechtstexte `pruefmappe/co-geldempfaenger.md` §2) ═══════════════
    //
    // Ein Co wird Geldempfänger nur mit eigenem Pflicht-Haken; Anteile gelten erst, wenn alle mit Anteil zugestimmt haben
    // (mindestens der Geldempfänger, der abgibt). Bis dahin bleibt die bisherige Einstellung wirksam (`status: waiting_consent`).
    //   _tix_co_money_pending   {owner, shares, needs [party], consents {party => {at, by, by_name, version, text}}, at, by, via}
    //   _tix_co_money_consents  Protokoll aller Zustimmungen/Ablehnungen (Nachweis, bleibt nach dem Wirksamwerden)

    const META_PENDING     = '_tix_co_money_pending';
    const META_CONSENT_LOG = '_tix_co_money_consents';
    const META_SITE_PENDING = '_tix_co_money_pending_site'; // Kunden-Seite: Stand vom Abgleich
    const CONSENT_VERSION  = 'co-geld-2026-11-10';

    public static function pending($eid) {
        $p = get_post_meta(intval($eid), self::META_PENDING, true);
        return is_array($p) && !empty($p['owner']) && is_array($p['shares'] ?? null) ? $p : null;
    }

    /** Wer muss zustimmen? Geldempfänger + alle mit Anteil > 0. Nur der Ersteller mit 100 % → niemand. */
    private static function needs_of(array $c) {
        $out = [(string) $c['owner']];
        foreach ((array) $c['shares'] as $k => $v) if (floatval($v) > 0) $out[] = (string) $k;
        $out = array_values(array_unique($out));
        return $out === ['creator'] ? [] : $out;
    }

    /** Pflicht-Texte (Fassung CONSENT_VERSION) für eine Partei. */
    public static function consent_texts($eid, array $c, $party) {
        $title = self::dec(get_the_title(intval($eid)));
        $list = [];
        foreach ((array) $c['shares'] as $k => $v) if (floatval($v) > 0) $list[] = self::party_name($eid, $k) . ' – ' . self::pct($v) . ' %';
        $out = [];
        if ((string) $c['owner'] === $party && $party !== 'creator') {
            $out['owner'] = 'Ich übernehme für ' . $title . ' den Ticketverkauf. Ich bin Verkäufer und Vertragspartner der Käufer, auch für Erstattungen bei Absage oder Verlegung. Bestätigung, Tickets und Rechnung gehen in meinem Namen raus. Es gelten meine AGB und meine Datenschutzerklärung.';
        }
        if (count($list) > 1 || ($list && (string) $c['owner'] !== $party)) {
            $out['shares'] = 'Ich bin mit der Aufteilung für ' . $title . ' einverstanden: ' . implode(', ', $list) . '. Nach dem ersten bezahlten Verkauf kann sie nicht mehr geändert werden.';
        }
        if (!$out) $out['shares'] = 'Ich bin mit der Aufteilung für ' . $title . ' einverstanden: ' . implode(', ', $list) . '. Nach dem ersten bezahlten Verkauf kann sie nicht mehr geändert werden.';
        return $out;
    }

    /** Wer ändert: creator (Ersteller/Lizenzseite) | platform (Plattform-Admin an einem fremden Event). */
    public static function via_for($eid) {
        if (!self::multi() || !class_exists('TIX_Co_Organizer')) return 'creator';
        return TIX_Co_Organizer::role($eid) === 'admin' && intval(get_post_meta(intval($eid), '_tix_organizer_id', true)) !== intval(TIX_App_Scope::organizer_id_for_user()) ? 'platform' : 'creator';
    }

    /** Partei des angemeldeten Nutzers am Event (creator | o:{ID}) oder null. */
    public static function my_party($eid) {
        $eid = intval($eid);
        if (!self::multi()) {
            if (self::co_kind($eid) === 'platform_co') return null; // Kunden-Seite: über die eigene Zustimmungs-Route
            return self::can_edit($eid) ? 'creator' : null;
        }
        $org = class_exists('TIX_App_Scope') ? TIX_App_Scope::organizer_id_for_user() : 0;
        if (!$org) return null;
        if (get_post_meta($eid, '_tix_syndicated', true) !== '1' && intval(get_post_meta($eid, '_tix_organizer_id', true)) === $org) return 'creator';
        return in_array($org, self::co_ids($eid), true) ? self::party($org) : null;
    }

    /**
     * Einstellung vorschlagen: sofort wirksam, wenn niemand (mehr) zustimmen muss, sonst „wartet auf Zustimmung“.
     * $via creator = der Ersteller ändert (seine Zustimmung gilt damit), platform = Plattform-Admin (alle müssen zustimmen).
     * → 'active' | 'pending' | null
     */
    public static function propose($eid, $c, $via = 'creator') {
        if (!is_array($c)) return null;
        $eid = intval($eid);
        $needs = self::needs_of($c);
        $consents = [];
        if ($via === 'creator' && in_array('creator', $needs, true)) $consents['creator'] = ['at' => gmdate('c'), 'by' => get_current_user_id(), 'by_name' => self::user_name(), 'version' => self::CONSENT_VERSION, 'text' => self::consent_texts($eid, $c, 'creator'), 'via' => 'change'];
        delete_post_meta($eid, self::META_PENDING);
        if (!array_diff($needs, array_keys($consents))) {
            self::apply($eid, $c, $via);
            if ($consents) self::log_consent($eid, 'creator', true, $consents['creator']);
            return 'active';
        }
        update_post_meta($eid, self::META_PENDING, ['owner' => (string) $c['owner'], 'shares' => (array) $c['shares'], 'needs' => $needs, 'consents' => $consents,
            'at' => gmdate('c'), 'by' => get_current_user_id(), 'via' => $via]);
        foreach ($consents as $party => $x) self::log_consent($eid, $party, true, $x);
        foreach (array_diff($needs, array_keys($consents)) as $party) self::notify_consent($eid, $party);
        return 'pending';
    }

    private static function user_name() {
        $u = wp_get_current_user();
        return $u && $u->ID ? (string) ($u->display_name ?: $u->user_login) : '';
    }

    private static function log_consent($eid, $party, $accept, array $x) {
        $log = (array) get_post_meta($eid, self::META_CONSENT_LOG, true);
        $log[] = ['party' => $party, 'accept' => (bool) $accept] + $x;
        update_post_meta($eid, self::META_CONSENT_LOG, array_slice(array_values(array_filter($log, 'is_array')), -100));
    }

    /**
     * Zustimmung bzw. Ablehnung einer Partei. → 'active' | 'pending' | 'declined' | WP_Error
     * $by_name = Anzeigename (z. B. Konto auf der Kunden-Seite).
     */
    public static function consent($eid, $party, $accept, $version = '', $by_name = '') {
        $eid = intval($eid);
        $p = self::pending($eid);
        if (!$p) return new WP_Error('tix_co_consent_none', 'Für dieses Event wartet keine Zustimmung.', ['status' => 409]);
        if (!empty($p['remote'])) return new WP_Error('tix_co_consent_remote', 'Die Zustimmungen sammelt die Plattform.', ['status' => 409]); // Lizenzseite: nur Kopie
        if (!in_array($party, (array) $p['needs'], true)) return new WP_Error('tix_co_forbidden', 'Deine Zustimmung ist hier nicht nötig.', ['status' => 403]);
        if (self::sold($eid)) { delete_post_meta($eid, self::META_PENDING); return new WP_Error('tix_co_money_locked', 'Für dieses Event gibt es schon bezahlte Bestellungen. Geldempfänger und Aufteilung sind deshalb fest.', ['status' => 409]); }
        if ($accept && $version !== '' && $version !== self::CONSENT_VERSION) return new WP_Error('tix_co_consent_version', 'Der Text der Zustimmung hat sich geändert. Bitte neu laden.', ['status' => 409, 'version' => self::CONSENT_VERSION]);
        $x = ['at' => gmdate('c'), 'by' => get_current_user_id(), 'by_name' => $by_name !== '' ? $by_name : self::user_name(), 'version' => self::CONSENT_VERSION, 'text' => self::consent_texts($eid, $p, $party)];
        self::log_consent($eid, $party, $accept, $x);
        if (!$accept) {
            delete_post_meta($eid, self::META_PENDING);
            self::notify_declined($eid, $party);
            self::source_callback($eid, $p, 'declined');
            return 'declined';
        }
        $p['consents'][$party] = $x;
        if (array_diff((array) $p['needs'], array_keys((array) $p['consents']))) { update_post_meta($eid, self::META_PENDING, $p); return 'pending'; }
        delete_post_meta($eid, self::META_PENDING);
        self::apply($eid, ['owner' => $p['owner'], 'shares' => $p['shares']], 'consent');
        self::source_callback($eid, $p, 'active');
        return 'active';
    }

    /** Spiegel: Ergebnis an die Lizenzseite melden (dort gilt die Einstellung dann auch). */
    private static function source_callback($eid, array $p, $state) {
        if (!self::multi() || get_post_meta($eid, '_tix_syndicated', true) !== '1' || !class_exists('TIX_Partners')) return;
        $pp = TIX_Partners::get((string) get_post_meta($eid, '_tix_source_partner', true));
        $sid = intval(get_post_meta($eid, '_tix_source_id', true));
        if (!$pp || empty($pp['api_base']) || strlen((string) ($pp['key_out'] ?? '')) < 20 || !$sid) return;
        wp_remote_post(untrailingslashit($pp['api_base']) . '/partner/co/' . $sid . '/money', ['timeout' => 15,
            'body' => wp_json_encode(['state' => $state, 'owner' => $p['owner'], 'shares' => (object) $p['shares'], 'consents' => (object) ($p['consents'] ?? [])]),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(), 'X-Tix-Partner-Key' => (string) $pp['key_out']]]);
    }

    /** Lizenzseite: Ergebnis der Zustimmung von der Plattform. */
    public static function rest_source_money(WP_REST_Request $req) {
        $sid = absint($req['id']);
        $b = (array) ($req->get_json_params() ?: []);
        $state = (string) ($b['state'] ?? '');
        if ($state === 'active') {
            $owner = (string) ($b['owner'] ?? 'creator');
            if ($owner !== 'creator' && !in_array(intval(substr($owner, 2)), self::co_ids($sid), true)) return new WP_Error('tix_invalid_field', 'Unbekannter Geldempfänger.', ['status' => 400]);
            self::apply($sid, ['owner' => $owner, 'shares' => array_map('floatval', (array) ($b['shares'] ?? []))], 'consent');
            foreach ((array) ($b['consents'] ?? []) as $party => $x) if (is_array($x)) self::log_consent($sid, (string) $party, true, $x + ['via' => 'platform']);
        }
        delete_post_meta($sid, self::META_PENDING);
        return rest_ensure_response(['ok' => true, 'co_money' => self::payload($sid)]);
    }

    /** Lizenzseite: Einstellung über die Plattform setzen (die prüft und holt die Zustimmungen). → 'active' | 'pending' | WP_Error */
    public static function remote_set($eid, array $c, $via = 'creator') {
        $r = TIX_Co_Remote::send_money($eid, $c['owner'], $c['shares'], $via);
        if (is_wp_error($r)) return $r;
        $st = (string) ($r['co_money']['status'] ?? 'active');
        if ($st === 'waiting_consent') {
            update_post_meta($eid, self::META_PENDING, ['owner' => (string) $c['owner'], 'shares' => (array) $c['shares'], 'needs' => array_map(function ($n) { return (string) $n['party_key']; }, (array) ($r['co_money']['pending']['needs'] ?? [])),
                'consents' => [], 'remote' => (array) ($r['co_money']['pending'] ?? []), 'at' => gmdate('c'), 'by' => get_current_user_id(), 'via' => $via]);
            return 'pending';
        }
        delete_post_meta($eid, self::META_PENDING);
        self::apply($eid, $c, $via);
        return 'active';
    }

    /** Anzeige der wartenden Einstellung. */
    private static function pending_payload($eid) {
        $p = self::pending($eid);
        if (!$p) return null;
        if (!empty($p['remote']) && self::co_kind($eid) === 'remote') return $p['remote'] + ['version' => self::CONSENT_VERSION]; // Lizenzseite: Stand der Plattform
        $needs = [];
        foreach ((array) $p['needs'] as $party) {
            $done = $p['consents'][$party] ?? null;
            $needs[] = ['party_key' => $party, 'party' => $party === 'creator' ? 'creator' : 'co', 'organizer_id' => self::pid($party), 'name' => self::party_name($eid, $party),
                'done' => (bool) $done, 'at' => $done['at'] ?? null, 'by_name' => $done['by_name'] ?? null];
        }
        $shares = [];
        foreach ((array) $p['shares'] as $k => $v) $shares[] = ['party_key' => $k, 'organizer_id' => self::pid($k), 'name' => self::party_name($eid, $k), 'percent' => floatval($v), 'owner' => $k === $p['owner']];
        $mine = self::my_party($eid);
        return ['owner_id' => self::pid($p['owner']), 'owner_name' => self::party_name($eid, $p['owner']), 'shares' => $shares, 'needs' => $needs,
            'since' => (string) ($p['at'] ?? ''), 'version' => self::CONSENT_VERSION,
            'my_consent' => ($mine && in_array($mine, (array) $p['needs'], true) && empty($p['consents'][$mine])) ? ['party_key' => $mine, 'texts' => self::consent_texts($eid, $p, $mine)] : null];
    }

    private static function party_user($eid, $party) {
        $oid = $party === 'creator' ? intval(get_post_meta($eid, '_tix_organizer_id', true)) : self::pid($party);
        return ($oid && class_exists('TIX_Co_Organizer')) ? TIX_Co_Organizer::owner_user($oid) : null;
    }

    private static function notify_consent($eid, $party) {
        if (!self::multi()) return;
        $u = self::party_user($eid, $party);
        if (!$u) { if ($party !== 'creator' && class_exists('TIX_Co_Sites') && TIX_Co_Sites::is_site_profile(self::pid($party))) TIX_Co_Sites::trigger_sync(self::pid($party)); return; }
        $title = self::dec(get_the_title($eid));
        if (class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item')) {
            TIX_Notifications::add_user_item($u->ID, 'Zustimmung nötig', $title . ' · Geldempfänger und Aufteilung', ['type' => 'message', 'action' => 'staff:event:' . intval($eid)]);
        }
        $html = '<p>Hallo,</p><p>Für <strong>' . esc_html($title) . '</strong> wurde festgelegt, wer das Geld einnimmt und wie ihr das Netto aufteilt. Das gilt erst, wenn du zustimmst.</p><p>Im Studio findest du das beim Event unter „Co-Veranstalter“.</p>';
        $subject = 'Zustimmung nötig: ' . $title;
        if (class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::send($u->user_email, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        else wp_mail($u->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    private static function notify_declined($eid, $party) {
        if (!self::multi()) return;
        $u = self::party_user($eid, 'creator');
        if ($u && class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item')) {
            TIX_Notifications::add_user_item($u->ID, self::party_name($eid, $party) . ' hat nicht zugestimmt', self::dec(get_the_title($eid)) . ' · Geldempfänger und Aufteilung bleiben wie bisher', ['type' => 'message', 'action' => 'staff:event:' . intval($eid)]);
        }
    }

    /** POST /events/{id}/co-money/consent {accept, version} */
    public static function rest_consent(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $b = (array) ($req->get_json_params() ?: $req->get_params());
        $accept = filter_var($b['accept'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $version = (string) ($b['version'] ?? '');
        if (!self::multi() && self::co_kind($eid) === 'platform_co') return self::site_forward_consent($eid, $accept, $version); // Kunden-Seite → Plattform
        if (!self::multi() && self::co_kind($eid) === 'remote') { // Lizenzseite: die Zustimmungen liegen auf der Plattform
            if (!self::can_edit($eid)) return new WP_Error('tix_co_forbidden', 'Deine Zustimmung ist hier nicht nötig.', ['status' => 403]);
            $u = wp_get_current_user();
            $r = TIX_Co_Remote::send_consent($eid, $accept, $version, (string) ($u->display_name ?: $u->user_login));
            if (is_wp_error($r)) return $r;
            $st = (string) ($r['state'] ?? '');
            if ($st === 'active' && is_array($r['co_money'] ?? null)) { // gleich wirksam (Rückmeldung kommt zusätzlich)
                $own = intval($r['co_money']['owner_id'] ?? 0);
                $map = [];
                foreach ((array) ($r['co_money']['shares'] ?? []) as $x) if (floatval($x['percent'] ?? 0) > 0 || !empty($x['owner'])) $map[intval($x['organizer_id'] ?? 0) ? 'o:' . intval($x['organizer_id']) : 'creator'] = floatval($x['percent']);
                self::apply($eid, ['owner' => self::party($own), 'shares' => $map], 'consent');
            }
            if ($st !== 'pending') delete_post_meta($eid, self::META_PENDING);
            return rest_ensure_response(['ok' => true, 'state' => $st, 'co_money' => self::payload($eid)]);
        }
        $party = self::my_party($eid);
        if (!$party) return new WP_Error('tix_co_forbidden', 'Deine Zustimmung ist hier nicht nötig.', ['status' => 403]);
        $r = self::consent($eid, $party, $accept, $version);
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(['ok' => true, 'state' => $r, 'co_money' => self::payload($eid)]);
    }

    /** GET /organizer/co-consents: offene Zustimmungen des eigenen Veranstalters. */
    public static function rest_my_consents(WP_REST_Request $req) {
        $org = TIX_App_Scope::organizer_id_for_user();
        $items = [];
        if ($org) {
            foreach (get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => 200, 'fields' => 'ids', 'meta_key' => self::META_PENDING]) as $eid) {
                $p = self::pending($eid);
                if (!$p) continue;
                foreach ([self::party($org), 'creator'] as $party) {
                    if ($party === 'creator' && (get_post_meta($eid, '_tix_syndicated', true) === '1' || intval(get_post_meta($eid, '_tix_organizer_id', true)) !== $org)) continue;
                    if ($party !== 'creator' && !in_array($org, self::co_ids($eid), true)) continue;
                    if (in_array($party, (array) $p['needs'], true) && empty($p['consents'][$party])) {
                        $items[] = ['event_id' => intval($eid), 'title' => self::dec(get_the_title($eid)), 'date' => (string) get_post_meta($eid, '_tix_date_start', true),
                            'party_key' => $party, 'texts' => self::consent_texts($eid, $p, $party), 'version' => self::CONSENT_VERSION, 'pending' => self::pending_payload($eid)];
                    }
                }
            }
        }
        return rest_ensure_response(['ok' => true, 'items' => $items]);
    }

    /** Plattform: Zustimmung einer Kunden-Seite (Partner-Schlüssel der Seite). */
    public static function perm_site_consent(WP_REST_Request $req) {
        if (!class_exists('TIX_Site_Move')) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $ok = TIX_Site_Move::perm_partner($req);
        if ($ok !== true) return $ok;
        if (!TIX_Site_Move::partner_event_ok(intval($req->get_param('_partner_oid')), absint($req['id']))) return new WP_Error('tix_co_forbidden', 'Kein gemeinsames Event.', ['status' => 403]);
        return true;
    }

    public static function rest_site_consent(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $b = (array) ($req->get_json_params() ?: []);
        $r = self::consent($eid, self::party(intval($req->get_param('_partner_oid'))), filter_var($b['accept'] ?? false, FILTER_VALIDATE_BOOLEAN), (string) ($b['version'] ?? ''), mb_substr(sanitize_text_field((string) ($b['by_name'] ?? '')), 0, 120));
        if (is_wp_error($r)) return $r;
        $oid = intval($req->get_param('_partner_oid'));
        if (class_exists('TIX_Co_Sites')) TIX_Co_Sites::trigger_sync($oid);
        return rest_ensure_response(['ok' => true, 'state' => $r]);
    }

    /** Kunden-Seite: Zustimmung an die Plattform weiterreichen. */
    private static function site_forward_consent($eid, $accept, $version) {
        $pid = class_exists('TIX_Site_Move') ? TIX_Site_Move::platform_co_id($eid) : 0;
        if (!$pid) return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        $u = wp_get_current_user();
        $r = wp_remote_post(rtrim((string) tix_get_settings('syndication_api_url'), '/') . '/partner/co-events/' . $pid . '/money-consent', ['timeout' => 20,
            'headers' => ['X-Tix-Syndication-Key' => (string) tix_get_settings('syndication_api_key'), 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode(['accept' => (bool) $accept, 'version' => $version, 'by_name' => (string) ($u->display_name ?: $u->user_login)])]);
        if (is_wp_error($r)) return new WP_Error('tix_partner_unreachable', 'Die Plattform ist gerade nicht erreichbar. Bitte gleich noch einmal versuchen.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($r));
        $d = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code >= 300 || !is_array($d)) return new WP_Error(is_array($d) && !empty($d['code']) ? (string) $d['code'] : 'tix_co_consent_error', is_array($d) && !empty($d['message']) ? (string) $d['message'] : 'Die Plattform hat die Zustimmung nicht angenommen.', ['status' => $code >= 400 && $code < 500 ? $code : 502]);
        if (($d['state'] ?? '') !== 'pending') delete_post_meta($eid, self::META_SITE_PENDING);
        return rest_ensure_response(['ok' => true, 'state' => (string) ($d['state'] ?? ''), 'note' => 'Der neue Stand kommt mit dem nächsten Abgleich.']);
    }

    /** Kunden-Seite: Stand vom Abgleich ({…pending_payload, my_party}) speichern. */
    public static function site_pending_sync($id, $pending) {
        if (is_array($pending) && $pending) update_post_meta($id, self::META_SITE_PENDING, $pending); else delete_post_meta($id, self::META_SITE_PENDING);
    }

    /** Plattform: wartende Einstellung für eine Kunden-Seite (Abgleich-Eintrag) oder null. */
    public static function pending_for_site($eid, $oid) {
        $p = self::pending($eid);
        if (!$p) return null;
        $party = self::party($oid);
        $pp = self::pending_payload($eid);
        $pp['my_consent'] = (in_array($party, (array) $p['needs'], true) && empty($p['consents'][$party])) ? ['party_key' => $party, 'texts' => self::consent_texts($eid, $p, $party)] : null;
        return $pp;
    }

    // ═══════════════ Abrechnung (evendis.de) ═══════════════

    /** Veranstalter, an den die Event-Abrechnung geht. */
    public static function settlement_org($eid) {
        $eid = intval($eid);
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        $co = self::owner_id($eid);
        if ($co && self::co_kind($eid) === 'local' && get_post_type($co) === 'tix_organizer') return $co;
        // Geteiltes Event, das die Plattform für den Co verkauft (Fall B, 1.38.713)
        if ($co && self::co_kind($eid) === 'mirror' && class_exists('TIX_Co_Seller') && TIX_Co_Seller::sells_here($eid) && get_post_type($co) === 'tix_organizer') return $co;
        return $main;
    }

    /** Beteiligte mit Anteil, die die Plattform-Abrechnung bedient: [[Veranstalter-ID, Prozent, Name]]. */
    public static function share_parties($eid) {
        $eid = intval($eid);
        if (self::co_kind($eid) !== 'local') return [];
        $own = self::party(self::owner_id($eid));
        $main = intval(get_post_meta($eid, '_tix_organizer_id', true));
        $out = [];
        foreach (self::shares($eid) as $party => $pct) {
            if ($party === $own || $pct <= 0) continue;
            $o = $party === 'creator' ? $main : self::pid($party);
            if ($o && get_post_type($o) === 'tix_organizer') $out[] = [$o, floatval($pct), self::dec(get_the_title($o))];
        }
        return $out;
    }

    /** Die andere Partei (Alt-Schnittstelle: erste mit Anteil) oder 0. */
    public static function share_org($eid) {
        $p = self::share_parties($eid);
        return $p ? $p[0][0] : 0;
    }

    /**
     * Anteile in einer Event-Abrechnung: je Beteiligtem eine Position `co_share` (−) beim Geldempfänger und ein offener Posten (+) für ihn.
     * Wiederholbar (Neu berechnen): ersetzt eigene Positionen und noch offene Gegenposten, verrechnet schon übernommene.
     */
    public static function apply_share($sid) {
        global $wpdb;
        $s = TIX_Settlement::get($sid);
        if (!$s || $s->type !== 'event' || !$s->event_id) return;
        $eid = intval($s->event_id);
        $si = TIX_Settlement::items_table();
        $wpdb->query($wpdb->prepare("DELETE FROM {$si} WHERE settlement_id = %d AND type = 'co_share'", $sid));
        $wpdb->query($wpdb->prepare("DELETE FROM {$si} WHERE settlement_id = 0 AND type = 'co_share' AND ref_id = %d", $sid));
        $parties = self::share_parties($eid);
        if (!$parties) return;
        $net = round(floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM {$si} WHERE settlement_id = %d AND type IN ('order','late')", $sid))), 2);
        $title = self::dec($s->event_title);
        foreach ($parties as [$other, $pct, $name]) {
            $amt = round($net * $pct / 100, 2);
            $done = round(floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM {$si} WHERE settlement_id > 0 AND type = 'co_share' AND ref_id = %d AND organizer_id = %d", $sid, $other))), 2);
            if (abs($amt) >= 0.01) {
                TIX_Settlement::insert_item(['settlement_id' => $sid, 'organizer_id' => intval($s->organizer_id), 'event_id' => $eid, 'type' => 'co_share', 'amount' => -$amt, 'ref_id' => $sid,
                    'note' => 'Anteil an ' . $name . ' (' . self::pct($pct) . ' %)']);
            }
            if (abs($amt - $done) >= 0.01) {
                TIX_Settlement::insert_item(['settlement_id' => 0, 'organizer_id' => $other, 'event_id' => $eid, 'type' => 'co_share', 'amount' => round($amt - $done, 2), 'ref_id' => $sid,
                    'note' => 'Anteil aus ' . $title . ' (' . self::pct($pct) . ' %)']);
            }
        }
    }

    /** Nachverkauf nach der Abrechnung: Anteile als offene Posten (−/+). */
    public static function late_share($eid, $owner_org, array $line, $order_id) {
        $title = self::dec(get_the_title($eid));
        foreach (self::share_parties($eid) as [$other, $pct, $name]) {
            $amt = round(floatval($line['amount'] ?? 0) * $pct / 100, 2);
            if (abs($amt) < 0.01) continue;
            TIX_Settlement::insert_item(['settlement_id' => 0, 'organizer_id' => intval($owner_org), 'event_id' => intval($eid), 'order_id' => intval($order_id), 'type' => 'co_share', 'amount' => -$amt,
                'note' => 'Anteil an ' . $name . ' (Nachverkauf)']);
            TIX_Settlement::insert_item(['settlement_id' => 0, 'organizer_id' => $other, 'event_id' => intval($eid), 'order_id' => intval($order_id), 'type' => 'co_share', 'amount' => $amt,
                'note' => 'Anteil aus ' . $title . ' (Nachverkauf)']);
        }
    }

    // ═══════════════ Ausgleichs-Abrechnung ═══════════════

    public static function balance($eid) {
        $eid = intval($eid);
        [$creator, $co] = self::names($eid);
        $owner = self::owner($eid);
        $share = self::share($eid);
        $orders = 0; $tickets = 0; $gross = 0.0; $refunds = 0.0; $pf = 0.0; $gf = 0.0; $net = 0.0;
        if (class_exists('TIX_Settlement')) {
            foreach (TIX_Settlement::event_orders($eid, false) as $row) {
                $l = TIX_Settlement::order_line($row, $eid, false);
                $orders++; $tickets += intval($l['tickets']); $gross += $l['gross']; $refunds += $l['refund']; $pf += $l['platform_fee']; $gf += $l['gateway_fee']; $net += $l['amount'];
            }
        }
        $end = class_exists('TIX_Settlement') ? intval(TIX_Settlement::event_end_ts($eid)) : 0;
        // Beteiligte mit Anteil (mehrere Co, 1.38.716)
        if (self::co_kind($eid) === 'platform_co') {
            $payer = $owner === 'co' ? $co : $creator;
            $parts = self::site_parts($eid);
        } else {
            $own = self::party(self::owner_id($eid));
            $payer = self::party_name($eid, $own);
            $parts = [];
            foreach (self::shares($eid) as $party => $pct) if ($party !== $own && $pct > 0) $parts[] = ['name' => self::party_name($eid, $party), 'percent' => floatval($pct), 'organizer_id' => self::pid($party)];
        }
        foreach ($parts as $i => $pp) $parts[$i]['amount'] = round($net * $pp['percent'] / 100, 2);
        $amt = round(array_sum(array_column($parts, 'amount')), 2);
        $payee = $parts ? implode(', ', array_column($parts, 'name')) : ($owner === 'co' ? $creator : $co);
        return ['ok' => true,
            'event' => ['id' => $eid, 'title' => self::dec(get_the_title($eid)), 'date' => (string) get_post_meta($eid, '_tix_date_start', true)],
            'seller' => ['name' => $payer, 'host' => self::host()], 'owner' => $owner, 'share' => $share,
            'payer' => ['name' => $payer], 'payee' => ['name' => $payee], 'parts' => $parts,
            'orders' => $orders, 'tickets' => $tickets, 'gross' => round($gross, 2), 'refunds' => round($refunds, 2),
            'fees' => ['platform' => round($pf, 2), 'gateway' => round($gf, 2)], 'net' => round($net, 2), 'share_amount' => $amt, 'currency' => 'EUR',
            'as_of' => gmdate('c'), 'final' => $end > 0 && time() > $end + self::FINAL_DAYS * DAY_IN_SECONDS,
            'settled_by_platform' => self::settles_here($eid),
            'note' => self::settles_here($eid) ? 'Die Anteile werden mit der Abrechnung von ' . self::dec(get_bloginfo('name')) . ' verrechnet.'
                : ($parts ? 'Den Ausgleich überweist ' . $payer . ' an ' . $payee . ' selbst.' : 'Kein Anteil vereinbart.')];
    }

    public static function perm_balance(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $eid = absint($req['id']);
        if (get_post_type($eid) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::co_kind($eid)) return new WP_Error('tix_co_none', 'Dieses Event hat keinen Co-Veranstalter.', ['status' => 409]);
        if (self::multi() && !in_array(TIX_Co_Organizer::role($eid), ['owner', 'admin', 'co'], true)) return new WP_Error('tix_co_forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    public static function rest_balance(WP_REST_Request $req) {
        $eid = absint($req['id']);
        // Lizenzseite, Co verkauft: Stand der Verkaufsinstanz über die Plattform (1.38.713)
        if (class_exists('TIX_Co_Seller') && ($rb = TIX_Co_Seller::remote_balance($eid)) !== null) return rest_ensure_response($rb);
        return rest_ensure_response(self::balance($eid));
    }

    public static function rest_balance_pdf(WP_REST_Request $req) {
        $b = self::balance(absint($req['id']));
        if (!class_exists('TIX_Simple_PDF') && defined('TIXOMAT_PATH')) require_once TIXOMAT_PATH . 'includes/class-tix-event-report.php';
        if (!class_exists('TIX_Simple_PDF')) return new WP_Error('tix_pdf', 'PDF nicht verfügbar.', ['status' => 500]);
        $eur = function ($v) { return number_format(floatval($v), 2, ',', '.') . ' EUR'; };
        $pdf = new TIX_Simple_PDF(595, 842, 50, 40);
        $pdf->add_page();
        $y = 60;
        $pdf->set_font('Helvetica-Bold', 16); $pdf->text(50, $y, 'Ausgleichs-Abrechnung'); $y += 24;
        $pdf->set_font('Helvetica', 11);
        foreach ([
            ['Event', $b['event']['title'] . ($b['event']['date'] ? ' (' . $b['event']['date'] . ')' : '')],
            ['Verkauf durch', $b['seller']['name'] . ($b['seller']['host'] ? ' · ' . $b['seller']['host'] : '')],
            ['Stand', wp_date('d.m.Y H:i') . ($b['final'] ? ' (abgeschlossen)' : ' (Zwischenstand)')],
        ] as [$k, $v]) { $pdf->text(50, $y, $k . ':'); $pdf->text(180, $y, $v); $y += 16; }
        $y += 10;
        foreach ([
            ['Bestellungen / Tickets', $b['orders'] . ' / ' . $b['tickets']],
            ['Ticketumsatz', $eur($b['gross'])], ['Erstattungen', '- ' . $eur($b['refunds'])],
            ['Gebühren Plattform', '- ' . $eur($b['fees']['platform'])], ['Gebühren Zahlungsanbieter', '- ' . $eur($b['fees']['gateway'])],
            ['Netto', $eur($b['net'])],
        ] as [$k, $v]) { $pdf->text(50, $y, $k); $pdf->text(330, $y, $v); $y += 16; }
        foreach ($b['parts'] as $pp) { $pdf->text(50, $y, 'Anteil ' . $pp['name'] . ' (' . self::pct($pp['percent']) . ' %)'); $pdf->text(330, $y, $eur($pp['amount'])); $y += 16; }
        $y += 12;
        $pdf->set_font('Helvetica-Bold', 11);
        if (!$b['parts']) { $pdf->text(50, $y, 'Kein Ausgleich.'); $y += 18; }
        foreach ($b['parts'] as $pp) if ($pp['amount'] != 0) { $pdf->text(50, $y, $b['payer']['name'] . ' zahlt an ' . $pp['name'] . ': ' . $eur($pp['amount'])); $y += 18; }
        $pdf->set_font('Helvetica', 9);
        $pdf->text(50, $y, $b['note']); $y += 12;
        $pdf->text(50, $y, 'Diese Übersicht ist keine Rechnung. Die Überweisung erfolgt zwischen den Veranstaltern.');
        $bytes = $pdf->output();
        return rest_ensure_response(['ok' => true, 'filename' => 'ausgleich-' . sanitize_title($b['event']['title']) . '.pdf', 'mime' => 'application/pdf', 'data' => base64_encode($bytes)]);
    }

    public static function routes() {
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-money/consent', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_consent'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
        if (self::multi()) {
            register_rest_route(self::NS, '/organizer/co-consents', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_my_consents'], 'permission_callback' => ['TIX_REST_API', 'check_organizer']]);
            register_rest_route(self::NS, '/partner/co-events/(?P<id>\d+)/money-consent', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_site_consent'], 'permission_callback' => [__CLASS__, 'perm_site_consent']]);
        } else {
            register_rest_route(self::NS, '/partner/co/(?P<id>\d+)/money', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_source_money'], 'permission_callback' => ['TIX_Co_Remote', 'perm_assign']]);
        }
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-balance', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_balance'], 'permission_callback' => [__CLASS__, 'perm_balance']]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/co-balance/pdf', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_balance_pdf'], 'permission_callback' => [__CLASS__, 'perm_balance']]);
    }
}
