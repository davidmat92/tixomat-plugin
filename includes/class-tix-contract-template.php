<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Contract_Template – Vermittlungsvertrag als Vorlage mit Platzhaltern {{…}} und automatischer Versionierung
 * (nur Mehr-Veranstalter-Modus, aktiv mit Vertragsquelle „Vorlage im Plugin“: Option tix_org_approval_settings.terms_source = template).
 *
 * Vorlage: Option tix_contract_template (Markdown, im Admin editierbar), sonst includes/contract/vermittlungsvertrag.md.
 * Allgemeine Platzhalter kommen aus den Einstellungen (Gebühren, Zahlungskosten, Zahlungsarten, Kanal-Gebühr, Auszahlung,
 * Stammdaten invoice_company_*, Vertragswerte der Veranstalter-Prüfung), Veranstalter-Platzhalter aus Veranstalter + Rechnungsadresse.
 * Die Anlage „Aktuelle Konditionen“ (ab „### Anlage“) zeigt die wirksamen Werte je Veranstalter und gehört nicht zum
 * eingefrorenen allgemeinen Wortlaut; sie steht im Zustimmungs-PDF.
 *
 * Versionen (Option tix_contract_versions): Ändert sich ein allgemeiner Wert oder die Vorlage, entsteht ein Entwurf mit der
 * Liste der Änderungen und einer Einstufung (Option tix_contract_draft). Der Admin sieht ihn (Hinweis + Veranstalter-Prüfung →
 * Einstellungen), kann die Einstufung ändern und veröffentlichen; sonst veröffentlicht der Cron nach einer Stunde.
 *   favorable        – nur zugunsten: gilt sofort, keine neue Zustimmung, Info-Mail
 *   adverse          – sonst: Ankündigung per Mail + Hinweis, gilt nach dem Änderungsvorlauf; ab dann Zustimmung vor dem Verkauf
 *   immediate        – sofort gültig, Zustimmung nötig (z. B. erste Fassung)
 * Fehlen die Stammdaten (Firma, Anschrift, Vertretung, USt-IdNr.), wird nichts veröffentlicht und nicht zugestimmt.
 */
class TIX_Contract_Template {

    const OPTION_TEMPLATE = 'tix_contract_template';
    const OPTION_VERSIONS = 'tix_contract_versions';
    const OPTION_DRAFT    = 'tix_contract_draft';
    const CRON            = 'tix_contract_template_cron';
    const ANNEX_MARK      = '### Anlage';
    const DRAFT_AUTOPUBLISH = 3600;

    const DEFAULTS = [
        'evendis_vertreten_durch' => '',
        'evendis_register'        => '',
        'einwand_frist_tage'      => 30,
        'kuendigung_monate'       => 1,
        'aenderung_vorlauf_tage'  => 42,
        'gerichtsstand'           => 'der Sitz von evendis',
        'zahlungsziel_tage'       => 14,
        'mwst_hinweis'            => 'inklusive Umsatzsteuer',
    ];

    /** Bezeichnung + Richtung (lower = kleiner ist zugunsten, higher = größer ist zugunsten, any = jede Änderung zugunsten, none = nie). */
    const FIELDS = [
        'evendis_firma'          => ['Firma von evendis', 'any'],
        'evendis_anschrift'      => ['Anschrift von evendis', 'any'],
        'evendis_vertreten_durch'=> ['Vertreten durch', 'any'],
        'evendis_register'       => ['Register', 'any'],
        'evendis_ust_id'         => ['USt-IdNr.', 'any'],
        'gebuehr_prozent'        => ['Gebühr in %', 'lower'],
        'gebuehr_fix'            => ['Gebühr je Ticket (€)', 'lower'],
        'gebuehr_mwst_hinweis'   => ['Hinweis zur Umsatzsteuer', 'none'],
        'zahlungskosten'         => ['Zahlungskosten in der Gebühr enthalten', 'higher'],
        'zahlungsarten'          => ['Zahlungsarten', 'any'],
        'anteil_gast_prozent'    => ['Anteil des Gasts bei „geteilt“ (%)', 'higher'],
        'gebuehr_modus_vorgabe'  => ['Vorgabe für neue Veranstalter', 'any'],
        'kanal_gebuehr_prozent'  => ['Kanal-Gebühr in %', 'lower'],
        'kanal_gebuehr_fix'      => ['Kanal-Gebühr je Ticket (€)', 'lower'],
        'auszahlung_tage'        => ['Auszahlung nach Tagen', 'lower'],
        'zahlungsziel_tage'      => ['Zahlungsziel (Tage)', 'higher'],
        'einwand_frist_tage'     => ['Einwandfrist (Tage)', 'higher'],
        'kuendigungsfrist'       => ['Kündigungsfrist (Monate)', 'none'],
        'aenderung_vorlauf'      => ['Änderungsvorlauf (Tage)', 'higher'],
        'gerichtsstand'          => ['Gerichtsstand', 'none'],
        'vorlage'                => ['Vertragstext (Vorlage)', 'none'],
    ];

    public static function active() {
        return class_exists('TIX_Contract') && TIX_Contract::multi()
            && (string) (TIX_Org_Approval::settings()['terms_source'] ?? 'page') === 'template';
    }

    public static function init() {
        if (!(class_exists('TIX_Contract') && TIX_Contract::multi())) return;
        foreach (['tix_settings', 'tix_channel_fee_settings', TIX_Org_Approval::OPTION, self::OPTION_TEMPLATE] as $opt) {
            add_action('update_option_' . $opt, [__CLASS__, 'detect'], 20, 0);
            add_action('add_option_' . $opt, [__CLASS__, 'detect'], 20, 0);
        }
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Contract_Template::CRON)) wp_schedule_event(time() + 300, 'hourly', TIX_Contract_Template::CRON);
        }, 26);
        add_action('admin_init', function () { if (current_user_can('manage_options') && !get_transient('tix_contract_detect')) { set_transient('tix_contract_detect', 1, 600); TIX_Contract_Template::detect(); } });
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_action('tix_org_account_top', [__CLASS__, 'render_account_top'], 10, 2);
        add_action('admin_post_tix_contract_publish', [__CLASS__, 'handle_publish']);
        add_action('admin_post_tix_contract_view', [__CLASS__, 'handle_view']);
        add_action('rest_api_init', function () {
            register_rest_route('tixomat/v1', '/organizer/approval/contract', [
                'methods' => 'GET', 'callback' => [__CLASS__, 'rest_contract'], 'permission_callback' => ['TIX_Org_Approval', 'perm_member'],
            ]);
        });
    }

    // ── Einstellungen + Vorlage ──

    public static function values_settings() {
        $s = TIX_Org_Approval::settings();
        $c = is_array($s['contract'] ?? null) ? $s['contract'] : [];
        return array_merge(self::DEFAULTS, $c);
    }

    public static function default_template() {
        $f = TIXOMAT_PATH . 'includes/contract/vermittlungsvertrag.md';
        return is_readable($f) ? (string) file_get_contents($f) : '';
    }

    public static function template() {
        $t = (string) get_option(self::OPTION_TEMPLATE, '');
        return trim($t) !== '' ? $t : self::default_template();
    }

    // ── Zahlen/Wörter ──

    private static function num($v, $dec = 2) {
        $s = number_format((float) $v, $dec, ',', '.');
        return strpos($s, ',') !== false ? rtrim(rtrim($s, '0'), ',') : $s;
    }
    private static function eur($v) { return number_format((float) $v, 2, ',', '.'); }

    private static function word($n, $case) {
        $w = [1 => $case === 'dat' ? 'einem' : 'eine', 2 => 'zwei', 3 => 'drei', 4 => 'vier', 5 => 'fünf', 6 => 'sechs', 7 => 'sieben', 8 => 'acht', 9 => 'neun', 10 => 'zehn', 11 => 'elf', 12 => 'zwölf'];
        return $w[$n] ?? (string) $n;
    }

    public static function months_text($m) {
        $m = max(1, intval($m));
        return $m === 1 ? 'einem Monat' : self::word($m, 'dat') . ' Monaten';
    }

    public static function lead_text($days) {
        $d = max(1, intval($days));
        if ($d % 7 === 0 && $d / 7 <= 12) { $w = $d / 7; return $w === 1 ? 'eine Woche' : self::word($w, 'akk') . ' Wochen'; }
        return $d === 1 ? 'einen Tag' : $d . ' Tage';
    }

    private static function mode_label($m) {
        return ['organizer' => 'Veranstalter trägt die Gebühr', 'split' => 'geteilt', 'customer' => 'Gast trägt die Gebühr'][$m] ?? 'zentrale Vorgabe';
    }

    // ── Werte ──

    /** Rohwerte der allgemeinen Platzhalter (für Vergleich/Einstufung). */
    public static function raw_values() {
        $s = function_exists('tix_get_settings') ? (array) tix_get_settings() : [];
        $fresh = get_option('tix_settings', []);
        if (is_array($fresh)) $s = array_merge($s, $fresh); // tix_get_settings() ist je Request zwischengespeichert
        $c = self::values_settings();
        $ch = class_exists('TIX_Channel_Fee') ? TIX_Channel_Fee::settings() : ['percent' => 0, 'fixed' => 0];
        $pm = class_exists('TIX_App_Checkout') ? array_values(array_unique(array_column(TIX_App_Checkout::info_methods(), 'title'))) : [];
        sort($pm);
        $mode_new = (string) ($s['fee_mode_new_org'] ?? 'customer');
        if ($mode_new === '') $mode_new = (string) ($s['fee_mode'] ?? 'organizer');
        $raw = [
            'evendis_firma'          => trim((string) ($s['invoice_company_name'] ?? '')),
            'evendis_anschrift'      => trim(preg_replace('/\s*\R\s*/u', ', ', (string) ($s['invoice_company_address'] ?? ''))),
            'evendis_vertreten_durch'=> trim((string) $c['evendis_vertreten_durch']),
            'evendis_register'       => trim((string) $c['evendis_register']),
            'evendis_ust_id'         => trim((string) ($s['invoice_company_ust_id'] ?? '')),
            'gebuehr_prozent'        => round(floatval($s['fee_percent'] ?? 0), 4),
            'gebuehr_fix'            => round(floatval($s['fee_fixed'] ?? 0), 2),
            'gebuehr_mwst_hinweis'   => trim((string) $c['mwst_hinweis']),
            'zahlungskosten'         => !empty($s['gateway_fee_included']) ? 1 : 0,
            'zahlungsarten'          => implode(', ', $pm),
            'anteil_gast_prozent'    => class_exists('TIX_Fees') ? round(TIX_Fees::split_share($s), 2) : 50.0,
            'gebuehr_modus_vorgabe'  => $mode_new,
            'kanal_gebuehr_prozent'  => round(floatval($ch['percent']), 4),
            'kanal_gebuehr_fix'      => round(floatval($ch['fixed']), 2),
            'auszahlung_tage'        => max(0, intval($s['settlement_delay_days'] ?? 7)),
            'zahlungsziel_tage'      => max(0, intval($c['zahlungsziel_tage'])),
            'einwand_frist_tage'     => max(1, intval($c['einwand_frist_tage'])),
            'kuendigungsfrist'       => max(1, intval($c['kuendigung_monate'])),
            'aenderung_vorlauf'      => max(1, intval($c['aenderung_vorlauf_tage'])),
            'gerichtsstand'          => trim((string) $c['gerichtsstand']),
            'vorlage'                => hash('sha256', self::template()),
        ];
        // Geplante Werte (TIX_Planned_Values) sind Teil der nächsten Fassung
        if (class_exists('TIX_Planned_Values') && TIX_Planned_Values::active()) {
            foreach (TIX_Planned_Values::target_fields() as $f => $v) {
                if (!array_key_exists($f, $raw)) continue;
                $raw[$f] = in_array($f, ['zahlungskosten', 'auszahlung_tage'], true) ? intval($v)
                    : (in_array($f, ['gebuehr_fix', 'kanal_gebuehr_fix', 'anteil_gast_prozent'], true) ? round(floatval($v), 2) : round(floatval($v), 4));
            }
        }
        return $raw;
    }

    /** Stammdaten, ohne die nicht zugestimmt werden kann. */
    public static function missing_master_data($raw = null) {
        $raw = $raw ?: self::raw_values();
        $need = ['evendis_firma' => 'Firma', 'evendis_anschrift' => 'Anschrift', 'evendis_vertreten_durch' => 'Vertreten durch', 'evendis_ust_id' => 'USt-IdNr.'];
        $out = [];
        foreach ($need as $k => $l) if ((string) $raw[$k] === '') $out[] = $l;
        return $out;
    }

    /** Anzeigewerte der allgemeinen Platzhalter. */
    public static function display_values($raw) {
        $incl = !empty($raw['zahlungskosten']);
        return [
            'evendis_firma'          => $raw['evendis_firma'],
            'evendis_anschrift'      => $raw['evendis_anschrift'],
            'evendis_vertreten_durch'=> $raw['evendis_vertreten_durch'],
            'evendis_register'       => $raw['evendis_register'],
            'evendis_ust_id'         => $raw['evendis_ust_id'],
            'gebuehr_prozent'        => self::num($raw['gebuehr_prozent']),
            'gebuehr_fix'            => self::eur($raw['gebuehr_fix']),
            'gebuehr_mwst_hinweis'   => $raw['gebuehr_mwst_hinweis'],
            'zahlungskosten_satz'    => $incl ? 'Die Kosten der Zahlungsabwicklung sind in der Gebühr enthalten.' : 'Die Kosten der Zahlungsabwicklung werden zusätzlich in tatsächlicher Höhe abgezogen.',
            'zahlungskosten_kurz'    => $incl ? 'in der Gebühr enthalten' : 'zusätzlich in tatsächlicher Höhe',
            'zahlungsarten'          => $raw['zahlungsarten'] !== '' ? $raw['zahlungsarten'] : 'die im Bestellvorgang angezeigten Zahlungsarten',
            'anteil_gast_prozent'    => self::num($raw['anteil_gast_prozent']),
            'gebuehr_modus_vorgabe'  => self::mode_label($raw['gebuehr_modus_vorgabe']),
            'kanal_gebuehr_prozent'  => self::num($raw['kanal_gebuehr_prozent']),
            'kanal_gebuehr_fix'      => self::eur($raw['kanal_gebuehr_fix']),
            'auszahlung_tage'        => (string) $raw['auszahlung_tage'],
            'zahlungsziel_tage'      => (string) $raw['zahlungsziel_tage'],
            'einwand_frist_tage'     => (string) $raw['einwand_frist_tage'],
            'kuendigungsfrist'       => self::months_text($raw['kuendigungsfrist']),
            'aenderung_vorlauf'      => self::lead_text($raw['aenderung_vorlauf']),
            'gerichtsstand'          => $raw['gerichtsstand'],
        ];
    }

    /** Veranstalter-Platzhalter + Anlage. $oid = 0 → neutrale Angaben (allgemeiner Wortlaut). */
    public static function organizer_values($oid) {
        if (!$oid) return ['veranstalter_name' => '[Veranstalter]', 'veranstalter_anschrift' => '[Anschrift laut Registrierung]', 'veranstalter_slug' => '[name]'];
        $pd = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::payload($oid) : [];
        $b = is_array($pd['billing'] ?? null) ? $pd['billing'] : [];
        $name = trim((string) ($b['company'] ?? '')) ?: trim((string) ($b['name'] ?? '')) ?: html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8');
        $addr = trim(implode(', ', array_filter([trim((string) ($b['street'] ?? '')), trim(trim((string) ($b['zip'] ?? '')) . ' ' . trim((string) ($b['city'] ?? '')))])));
        if ($addr === '') $addr = trim((string) get_post_meta($oid, '_tix_org_address', true));
        $slug = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($oid) : (string) get_post_field('post_name', $oid);
        $cfg = class_exists('TIX_Fees') ? TIX_Fees::get_fee_config($oid) : [];
        $ch = 'nicht aktiviert';
        if (class_exists('TIX_Partners') && class_exists('TIX_Channel_Fee')) {
            foreach ((array) TIX_Partners::all() as $p) {
                if (intval($p['organizer_id'] ?? 0) !== intval($oid)) continue;
                $r = TIX_Channel_Fee::for_partner($p);
                if ($r) $ch = self::num($r['percent']) . ' % + ' . self::eur($r['fixed']) . ' € je Ticket';
            }
        }
        $add = [];
        if (class_exists('TIX_Contract')) foreach (TIX_Contract::accepted_list($oid) as $a) $add[] = wp_date('d.m.Y', intval($a['accepted_at'])) . ': ' . (string) $a['title'];
        $delay = class_exists('TIX_Settlement') ? TIX_Settlement::delay_days($oid) : 7;
        return [
            'veranstalter_name'      => $name ?: '[Veranstalter]',
            'veranstalter_anschrift' => $addr !== '' ? $addr : '[Anschrift fehlt]',
            'veranstalter_slug'      => $slug,
            'eff_gebuehr_prozent'    => self::num($cfg['fee_percent'] ?? 0),
            'eff_gebuehr_fix'        => self::eur($cfg['fee_fixed'] ?? 0),
            'eff_gebuehr_modus'      => self::mode_label((string) ($cfg['fee_mode'] ?? '')),
            'eff_kanal_gebuehr'      => $ch,
            'eff_auszahlung'         => $delay . ' Tage nach Veranstaltungsende',
            'zusatzvereinbarungen_liste' => $add ? implode('; ', $add) : 'keine',
            'konditionen_stand'      => wp_date('d.m.Y H:i') . ' Uhr',
        ];
    }

    // ── Rendern ──

    /** Vorlage in allgemeinen Teil und Anlage teilen. */
    public static function split($tpl) {
        $i = strpos($tpl, self::ANNEX_MARK);
        return $i === false ? [$tpl, ''] : [rtrim(substr($tpl, 0, $i)), substr($tpl, $i)];
    }

    public static function fill($md, array $vals) {
        $md = preg_replace('/\s*\*\*\[PRÜFEN[^\]]*\]\*\*/u', '', $md); // Prüfvermerke für den Anwalt nicht anzeigen
        $md = preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/', function ($m) use ($vals) {
            return array_key_exists($m[1], $vals) ? (string) $vals[$m[1]] : '{{' . $m[1] . '}}';
        }, $md);
        // leere optionale Angaben (z. B. Register) ohne doppelte Kommas
        return preg_replace(['/,\s*,/u', '/,\s*(\(|\n)/u'], [',', ' $1'], $md);
    }

    private static function inline($s) {
        $s = esc_html($s);
        return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    }

    /** Kleines Markdown → HTML (Überschriften, Absätze, Listen, Tabellen, fett). */
    public static function md_html($md) {
        $out = []; $list = null; $para = [];
        $flush_para = function () use (&$para, &$out) { if ($para) { $out[] = '<p>' . implode('<br>', $para) . '</p>'; $para = []; } };
        $flush_list = function () use (&$list, &$out) { if ($list) { $out[] = '<' . $list['t'] . '>' . implode('', $list['items']) . '</' . $list['t'] . '>'; $list = null; } };
        $table = [];
        $flush_table = function () use (&$table, &$out) {
            if (!$table) return;
            $h = '<table class="tix-contract-table">';
            foreach ($table as $r) $h .= '<tr><td>' . implode('</td><td>', $r) . '</td></tr>';
            $out[] = $h . '</table>'; $table = [];
        };
        foreach (preg_split('/\R/u', (string) $md) as $line) {
            $t = trim($line);
            if ($t === '' ) { $flush_para(); $flush_list(); $flush_table(); continue; }
            if (preg_match('/^\|(.+)\|$/u', $t, $m)) {
                $flush_para(); $flush_list();
                $cells = array_map('trim', explode('|', $m[1]));
                if (preg_match('/^[\s|:-]+$/u', $m[1])) continue;
                if (count(array_filter($cells, function ($c) { return $c !== ''; })) === 0) continue;
                $table[] = array_map([__CLASS__, 'inline'], $cells); continue;
            }
            $flush_table();
            if (preg_match('/^(#{2,4})\s+(.*)$/u', $t, $m)) { $flush_para(); $flush_list(); $lv = strlen($m[1]); $out[] = '<h' . $lv . '>' . self::inline($m[2]) . '</h' . $lv . '>'; continue; }
            if ($t === '---') { $flush_para(); $flush_list(); $out[] = '<hr>'; continue; }
            $indent = strlen($line) - strlen(ltrim($line));
            if (preg_match('/^(\d+)\.\s+(.*)$/u', $t, $m) && $indent < 3) {
                $flush_para();
                if (!$list || $list['t'] !== 'ol') { $flush_list(); $list = ['t' => 'ol', 'items' => []]; }
                $list['items'][] = '<li>' . self::inline($m[2]) . '</li>'; continue;
            }
            if (preg_match('/^[-•]\s+(.*)$/u', $t, $m)) {
                if ($list && $indent >= 3) { $last = array_pop($list['items']); $last = preg_replace('#</li>$#', '', $last); $sub = '<li>' . self::inline($m[1]) . '</li>';
                    if (substr($last, -5) === '</ul>') $last = substr($last, 0, -5) . $sub . '</ul>'; else $last .= '<ul>' . $sub . '</ul>';
                    $list['items'][] = $last . '</li>'; continue; }
                $flush_para();
                if (!$list || $list['t'] !== 'ul') { $flush_list(); $list = ['t' => 'ul', 'items' => []]; }
                $list['items'][] = '<li>' . self::inline($m[1]) . '</li>'; continue;
            }
            if ($list && $indent >= 3) { $last = array_pop($list['items']); $list['items'][] = preg_replace('#</li>$#', '<br>' . self::inline($t) . '</li>', $last); continue; }
            $flush_list();
            $para[] = self::inline($t);
        }
        $flush_para(); $flush_list(); $flush_table();
        return implode("\n", $out);
    }

    /** Markdown → Text für PDF/Prüfsumme. */
    public static function md_text($md) {
        $lines = [];
        foreach (preg_split('/\R/u', (string) $md) as $line) {
            $t = rtrim($line);
            if (preg_match('/^\s*\|(.+)\|\s*$/u', $t, $m)) {
                if (preg_match('/^[\s|:-]+$/u', $m[1])) continue;
                $cells = array_values(array_filter(array_map('trim', explode('|', $m[1])), function ($c) { return $c !== ''; }));
                if (!$cells) continue;
                $t = count($cells) >= 2 ? $cells[0] . ': ' . implode(' ', array_slice($cells, 1)) : $cells[0];
            }
            $t = preg_replace('/^#{2,4}\s+/u', '', $t);
            $t = preg_replace('/^(\s*)[-]\s+/u', '$1• ', $t);
            $t = str_replace('**', '', $t);
            if (trim($t) === '---') $t = '';
            $lines[] = $t;
        }
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** Vollständige Werte einer Version (Rohwerte gespeichert in der Version). */
    private static function version_vals($ver) {
        $vals = self::display_values($ver['values']);
        $vals['vertrag_version'] = $ver['version'];
        $vals['vertrag_gueltig_ab'] = wp_date('d.m.Y', intval($ver['valid_from']));
        return $vals;
    }

    /** Allgemeiner Wortlaut (eingefroren) einer Version: [html, text]. */
    public static function general($ver, $tpl = null) {
        list($main) = self::split($tpl ?? (string) $ver['template']);
        $md = self::fill($main, self::version_vals($ver) + self::organizer_values(0));
        return [self::md_html($md), self::md_text($md)];
    }

    /** Dokument für einen Veranstalter: Wortlaut mit seinen Angaben + Anlage. */
    public static function document($oid, $ver) {
        list($main, $annex) = self::split((string) $ver['template']);
        $vals = self::version_vals($ver) + self::organizer_values($oid);
        $m = self::fill($main, $vals);
        $a = $annex !== '' ? self::fill($annex, $vals) : '';
        return ['html' => self::md_html($m), 'text' => self::md_text($m), 'annex_html' => self::md_html($a), 'annex_text' => self::md_text($a)];
    }

    // ── Versionen ──

    public static function versions() {
        $v = get_option(self::OPTION_VERSIONS, []);
        return is_array($v) ? array_values($v) : [];
    }

    /** In Kraft: letzte Version mit valid_from ≤ jetzt. */
    public static function current() {
        $cur = null;
        foreach (self::versions() as $v) if (intval($v['valid_from']) <= time()) $cur = $v;
        return $cur;
    }

    /** Angekündigt: letzte Version mit valid_from in der Zukunft. */
    public static function pending() {
        $vs = self::versions();
        $last = $vs ? end($vs) : null;
        return ($last && intval($last['valid_from']) > time()) ? $last : null;
    }

    public static function find($version) {
        foreach (self::versions() as $v) if ($v['version'] === (string) $version) return $v;
        return null;
    }

    private static function index_of($version) {
        foreach (self::versions() as $i => $v) if ($v['version'] === (string) $version) return $i;
        return -1;
    }

    /** Mindestens nötige Version: letzte gültige Version, die eine Zustimmung verlangt. */
    public static function required_version() {
        $req = null;
        foreach (self::versions() as $v) if (intval($v['valid_from']) <= time() && !empty($v['requires_consent'])) $req = $v;
        return $req;
    }

    public static function accepted($oid) {
        $req = self::required_version();
        if (!$req) return true;
        $a = get_post_meta(intval($oid), TIX_Org_Approval::META_TERMS, true);
        if (!is_array($a) || empty($a['version'])) return false;
        $i = self::index_of($a['version']);
        if ($i < 0) { // zurückgenommene Version: letzte noch vorhandene Zustimmung aus dem Verlauf
            $hist = get_post_meta(intval($oid), TIX_Contract::META_HISTORY, true);
            foreach (array_reverse(is_array($hist) ? $hist : []) as $h) { $i = self::index_of($h['version'] ?? ''); if ($i >= 0) break; }
        }
        return $i >= 0 && $i >= self::index_of($req['version']);
    }

    /**
     * Angekündigte Version zurücknehmen, wenn die Ziel-Werte wieder der gültigen Fassung entsprechen (z. B. geplante
     * Änderung zurückgezogen). Zustimmungen zur zurückgenommenen Version fallen auf die vorherige zurück; Mail an die Inhaber.
     */
    public static function retract_obsolete() {
        $pend = self::pending();
        $cur = self::current();
        if (!$pend || !$cur || $cur['values'] != self::raw_values()) return null;
        $vs = array_values(array_filter(self::versions(), function ($v) use ($pend) { return $v['version'] !== $pend['version']; }));
        update_option(self::OPTION_VERSIONS, $vs, false);
        $snaps = TIX_Contract::snapshots(); unset($snaps[$pend['version']]); update_option(TIX_Contract::OPTION_SNAPS, $snaps, false);
        delete_option(self::OPTION_DRAFT);
        $t = TIX_Org_Approval::terms();
        foreach (get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1000, 'fields' => 'ids', 'no_found_rows' => true]) as $oid) {
            $a = get_post_meta($oid, TIX_Org_Approval::META_TERMS, true);
            if (is_array($a) && ($a['version'] ?? '') === $pend['version']) {
                $hist = get_post_meta($oid, TIX_Contract::META_HISTORY, true);
                $prev = null;
                foreach (array_reverse(is_array($hist) ? $hist : []) as $h) if (($h['version'] ?? '') !== $pend['version'] && self::find($h['version'] ?? '')) { $prev = $h; break; }
                if ($prev) update_post_meta($oid, TIX_Org_Approval::META_TERMS, $prev); else delete_post_meta($oid, TIX_Org_Approval::META_TERMS);
            }
            $u = get_user_by('id', intval(get_post_meta($oid, '_tix_org_user_id', true)));
            if ($u && is_email($u->user_email) && class_exists('TIX_Payout_Details')) {
                TIX_Payout_Details::mail($u->user_email, $t['title'] . ': angekündigte Änderung zurückgenommen', 'Vermittlungsvertrag',
                    '<p>Hallo,</p><p>die zum ' . esc_html(wp_date('d.m.Y', intval($pend['valid_from']))) . ' angekündigte Version ' . esc_html($pend['version']) . ' des ' . esc_html($t['title']) . ' wird nicht wirksam. Es bleibt bei der Version ' . esc_html($cur['version']) . ' – du musst nichts tun.</p>');
            }
        }
        self::push_all(['Vertragsänderung zurückgenommen', 'Version ' . $pend['version'] . ' wird nicht wirksam – es bleibt bei Version ' . $cur['version'] . '.']);
        return $pend;
    }

    /** Hatte zugestimmt, aber nicht der nötigen Version (tix_terms_outdated). */
    public static function outdated($oid) {
        $a = get_post_meta(intval($oid), TIX_Org_Approval::META_TERMS, true);
        return is_array($a) && !empty($a['version']) && !self::accepted($oid);
    }

    // ── Änderungen erkennen ──

    /** Unterschiede zwischen Rohwerten: [{key, label, old, new, favorable}] */
    public static function diff($old, $new) {
        $out = [];
        foreach (self::FIELDS as $k => $f) {
            $o = $old[$k] ?? null; $n = $new[$k] ?? null;
            if ((string) $o === (string) $n) continue;
            $fav = false;
            if ($f[1] === 'any') $fav = true;
            elseif ($f[1] === 'lower') $fav = is_numeric($o) && is_numeric($n) && floatval($n) < floatval($o);
            elseif ($f[1] === 'higher') $fav = is_numeric($o) && is_numeric($n) && floatval($n) > floatval($o);
            $out[] = ['key' => $k, 'label' => $f[0], 'old' => $k === 'vorlage' ? '' : $o, 'new' => $k === 'vorlage' ? 'geändert' : $n, 'favorable' => $fav];
        }
        return $out;
    }

    /** Neuen Entwurf anlegen/aktualisieren, wenn sich allgemeine Werte geändert haben (nur Vorlagen-Modus). */
    public static function detect() {
        if (!self::active()) return null;
        self::retract_obsolete();
        $raw = self::raw_values();
        $vs = self::versions();
        $last = $vs ? end($vs) : null;
        if ($last && $last['values'] == $raw) { delete_option(self::OPTION_DRAFT); return null; }
        $changes = $last ? self::diff($last['values'], $raw) : [];
        $draft = get_option(self::OPTION_DRAFT, []);
        if (is_array($draft) && !empty($draft['values']) && $draft['values'] == $raw) return $draft;
        $auto = !$last ? 'immediate' : (($changes && !in_array(false, array_column($changes, 'favorable'), true)) ? 'favorable' : 'adverse');
        $draft = ['values' => $raw, 'template' => self::template(), 'changes' => $changes, 'auto' => $auto,
            'classification' => (is_array($draft) && !empty($draft['override'])) ? $draft['classification'] : $auto,
            'override' => is_array($draft) && !empty($draft['override']), 'created_at' => time(), 'first' => !$last];
        update_option(self::OPTION_DRAFT, $draft, false);
        return $draft;
    }

    public static function draft() {
        $d = get_option(self::OPTION_DRAFT, []);
        return is_array($d) && !empty($d['values']) ? $d : null;
    }

    private static function next_version_name() {
        $base = wp_date('Y-m-d');
        $name = $base; $n = 2;
        while (self::find($name)) $name = $base . '-' . $n++;
        return $name;
    }

    /** Entwurf veröffentlichen: Version anlegen, einfrieren, benachrichtigen. */
    public static function publish($classification = null, $by = 0) {
        $d = self::draft();
        if (!$d) return new WP_Error('tix_no_draft', 'Es gibt keine Änderungen zu veröffentlichen.');
        if (self::missing_master_data($d['values'])) return new WP_Error('tix_master_data', 'Stammdaten fehlen – Version nicht veröffentlicht.');
        $cls = in_array($classification, ['favorable', 'adverse', 'immediate'], true) ? $classification : $d['classification'];
        $now = time();
        $lead = max(1, intval($d['values']['aenderung_vorlauf'])) * DAY_IN_SECONDS;
        // Die Frist der bisherigen Fassung gilt (Vorlauf aus der zuletzt gültigen Version)
        $cur = self::current();
        if ($cur) $lead = max(1, intval($cur['values']['aenderung_vorlauf'])) * DAY_IN_SECONDS;
        $pend = self::pending();
        $valid = $cls === 'adverse' ? $now + $lead : $now;
        if ($pend) $valid = max($valid, intval($pend['valid_from']));
        $ver = [
            'version'          => self::next_version_name(),
            'created_at'       => $now,
            'valid_from'       => $valid,
            'classification'   => $cls,
            'requires_consent' => $cls !== 'favorable' || ($pend && !empty($pend['requires_consent'])),
            'values'           => $d['values'],
            'template'         => $d['template'],
            'changes'          => $d['changes'],
            'by'               => intval($by ?: get_current_user_id()),
        ];
        list($html, $text) = self::general($ver);
        $ver['sha256'] = hash('sha256', $text);
        $vs = self::versions(); $vs[] = $ver;
        update_option(self::OPTION_VERSIONS, $vs, false);
        delete_option(self::OPTION_DRAFT);
        // Fassung auch für TIX_Contract (PDF, Metabox, Nachweis)
        $snaps = TIX_Contract::snapshots();
        $snaps[$ver['version']] = ['version' => $ver['version'], 'title' => TIX_Org_Approval::settings()['terms_title'], 'url' => self::view_url($ver['version']),
            'html' => $html, 'text' => $text, 'sha256' => $ver['sha256'], 'frozen_at' => $now, 'by' => $ver['by'], 'valid_from' => $valid];
        update_option(TIX_Contract::OPTION_SNAPS, $snaps, false);
        self::notify($ver);
        if (class_exists('TIX_Planned_Values')) TIX_Planned_Values::link_version($ver);
        return $ver;
    }

    public static function cron() {
        if (class_exists('TIX_Planned_Values')) TIX_Planned_Values::apply_due();
        self::detect();
        $d = self::draft();
        if ($d && time() - intval($d['created_at']) >= self::DRAFT_AUTOPUBLISH && !self::missing_master_data($d['values'])) self::publish(null, 0);
    }

    // ── Benachrichtigung ──

    private static function changes_html($changes) {
        if (!$changes) return '';
        $h = '<ul>';
        foreach ($changes as $c) $h .= '<li>' . esc_html($c['label']) . ($c['key'] === 'vorlage' ? ': Wortlaut überarbeitet' : ': ' . esc_html((string) $c['old']) . ' → ' . esc_html((string) $c['new'])) . '</li>';
        return $h . '</ul>';
    }

    private static function notify($ver) {
        $t = TIX_Org_Approval::terms();
        $date = wp_date('d.m.Y', intval($ver['valid_from']));
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1000, 'fields' => 'ids', 'no_found_rows' => true]);
        foreach ($ids as $oid) {
            $u = get_user_by('id', intval(get_post_meta($oid, '_tix_org_user_id', true)));
            if (!$u || !is_email($u->user_email)) continue;
            $url = admin_url('admin.php?page=' . TIX_Org_Approval::ORG_SLUG . '#tix-acc-terms');
            if ($ver['classification'] === 'favorable') {
                $subj = $t['title'] . ': Konditionen verbessert (Version ' . $ver['version'] . ')';
                $body = '<p>Hallo,</p><p>wir haben den ' . esc_html($t['title']) . ' zu deinen Gunsten angepasst. Die neue Version ' . esc_html($ver['version']) . ' gilt ab sofort, du musst nichts tun.</p>' . self::changes_html($ver['changes']);
            } elseif ($ver['classification'] === 'immediate') {
                $subj = $t['title'] . ': neue Fassung bitte bestätigen';
                $body = '<p>Hallo,</p><p>der ' . esc_html($t['title']) . ' liegt in einer neuen Fassung vor (Version ' . esc_html($ver['version']) . '). Bitte lies sie und stimme zu: <a href="' . esc_url($url) . '">Mein Konto</a>.</p>' . self::changes_html($ver['changes']);
            } else {
                $subj = $t['title'] . ': Änderung ab ' . $date;
                $body = '<p>Hallo,</p><p>der ' . esc_html($t['title']) . ' ändert sich zum <strong>' . esc_html($date) . '</strong> (Version ' . esc_html($ver['version']) . '):</p>' . self::changes_html($ver['changes'])
                    . '<p>Ab diesem Tag kannst du neue Tickets erst wieder verkaufen, wenn du der neuen Version zugestimmt hast – das geht schon jetzt unter <a href="' . esc_url($url) . '">Mein Konto</a> oder in der App. Bereits verkaufte Tickets werden zu den bisherigen Konditionen abgerechnet. Bist du nicht einverstanden, kannst du den Vertrag zum ' . esc_html($date) . ' kündigen.</p>';
            }
            if (class_exists('TIX_Payout_Details')) TIX_Payout_Details::mail($u->user_email, $subj, 'Vermittlungsvertrag', $body);
        }
        self::push_all($ver['classification'] === 'favorable'
            ? ['Vertrag verbessert', 'Version ' . $ver['version'] . ' gilt ab sofort – du musst nichts tun.']
            : ($ver['classification'] === 'immediate'
                ? ['Neue Vertragsfassung', 'Bitte lies Version ' . $ver['version'] . ' und stimme zu.']
                : ['Vertrag ändert sich zum ' . $date, 'Bitte stimme Version ' . $ver['version'] . ' bis dahin zu, damit dein Ticketverkauf weiterläuft.']));
    }

    /** Mitteilung (Feed/Push) an Inhaber + Team-Admins aller Veranstalter, Aktion organizer:approval. */
    public static function push_all(array $msg) {
        if (!class_exists('TIX_Notifications') || !method_exists('TIX_Notifications', 'add_user_item')) return 0;
        $n = 0;
        foreach (get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1000, 'fields' => 'ids', 'no_found_rows' => true]) as $oid) {
            foreach (TIX_Org_Approval::manager_ids($oid) as $uid) {
                TIX_Notifications::add_user_item($uid, $msg[0], $msg[1], ['type' => 'message', 'action' => 'organizer:approval']);
                $n++;
            }
        }
        return $n;
    }

    // ── Anzeige / REST ──

    public static function view_url($version = '') {
        return add_query_arg(array_filter(['action' => 'tix_contract_view', 'version' => $version]), admin_url('admin-post.php'));
    }

    /** Vertrag für einen Veranstalter (App/Web): aktuelle Fassung + ggf. angekündigte. */
    public static function payload($oid) {
        $raw = self::raw_values();
        $missing = self::missing_master_data($raw);
        $cur = self::current();
        $pend = self::pending();
        $mk = function ($ver) use ($oid) {
            if (!$ver) return null;
            $d = self::document($oid, $ver);
            return ['version' => $ver['version'], 'valid_from' => gmdate('Y-m-d\TH:i:s\Z', intval($ver['valid_from'])), 'sha256' => (string) $ver['sha256'],
                'requires_consent' => !empty($ver['requires_consent']), 'changes' => $ver['changes'], 'html' => $d['html'], 'text' => $d['text'], 'annex_html' => $d['annex_html'], 'annex_text' => $d['annex_text']];
        };
        return [
            'ok'                  => true,
            'source'              => 'template',
            'title'               => TIX_Org_Approval::terms()['title'],
            'missing_master_data' => $missing,
            'can_accept'          => !$missing && (bool) ($cur || $pend),
            'accepted'            => self::accepted($oid),
            'outdated'            => self::outdated($oid),
            'current'             => $mk($cur),
            'pending'             => $mk($pend),
        ];
    }

    public static function rest_contract(WP_REST_Request $req) {
        $oid = class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
        if (!self::active()) return new WP_Error('tix_contract_page', 'Der Vertrag liegt als Seite vor.', ['status' => 404, 'url' => TIX_Org_Approval::terms()['url']]);
        return rest_ensure_response(self::payload($oid));
    }

    /** Lese-Ansicht (eingeloggt): ?version= (sonst aktuelle, sonst angekündigte). */
    public static function handle_view() {
        if (!is_user_logged_in()) auth_redirect();
        $oid = class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
        $ver = !empty($_GET['version']) ? self::find(sanitize_text_field(wp_unslash($_GET['version']))) : (self::current() ?: self::pending());
        $raw = self::raw_values();
        $missing = self::missing_master_data($raw);
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html(TIX_Org_Approval::terms()['title']) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:780px;margin:24px auto;padding:0 16px;line-height:1.6;color:#1f2937}h2{font-size:22px}h3{font-size:17px;margin-top:28px}table{border-collapse:collapse;width:100%}td{border-top:1px solid #e5e7eb;padding:6px 8px;vertical-align:top}.n{background:#fef2f2;border-left:4px solid #ef4444;padding:10px 14px;border-radius:6px}.m{background:#f3f4f6;padding:8px 12px;border-radius:6px;font-size:13px}</style></head><body>';
        if ($missing) echo '<p class="n"><strong>Stammdaten fehlen:</strong> ' . esc_html(implode(', ', $missing)) . '. Der Vertrag kann erst bestätigt werden, wenn sie eingetragen sind.</p>';
        if (!$ver) {
            // Noch keine Version: Vorschau mit den aktuellen Werten
            $ver = ['version' => '(Vorschau)', 'valid_from' => time(), 'values' => $raw, 'template' => self::template(), 'sha256' => '', 'changes' => []];
        }
        $d = self::document($oid, $ver);
        echo '<p class="m">Version ' . esc_html($ver['version']) . ' · gültig ab ' . esc_html(wp_date('d.m.Y', intval($ver['valid_from']))) . ($ver['sha256'] ? ' · SHA-256 ' . esc_html($ver['sha256']) : '') . '</p>';
        echo $d['html'] . ($d['annex_html'] !== '' ? '<hr>' . $d['annex_html'] : '') . '</body></html>';
        exit;
    }

    /** „Mein Konto“ oben: gesperrte Kasse, angekündigte Fassung (alt → neu, Datum), Konditionen mit geplanten Werten. */
    public static function render_account_top($oid, $manager) {
        if (!self::active() || !self::versions()) return;
        $box = 'border-radius:12px;padding:14px 18px;margin:0 0 18px;max-width:820px;';
        $acc = get_post_meta($oid, TIX_Org_Approval::META_TERMS, true);
        $acc_v = is_array($acc) ? (string) ($acc['version'] ?? '') : '';
        if (self::outdated($oid)) {
            $cur = self::current();
            echo '<div style="' . $box . 'background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #ef4444;"><strong>Ticketverkauf pausiert</strong><br>'
                . 'Seit ' . esc_html(wp_date('d.m.Y', intval($cur['valid_from']))) . ' gilt Version ' . esc_html($cur['version']) . ' des Vermittlungsvertrags. Bis du ihr zustimmst, können keine neuen Tickets verkauft werden; bereits verkaufte bleiben gültig und werden zu den bisherigen Konditionen abgerechnet.'
                . ($manager ? ' <a class="button button-primary" style="margin-left:6px;" href="#tix-acc-terms">Lesen und zustimmen</a>' : ' Zustimmen kann der Inhaber oder ein Team-Admin.') . '</div>';
        }
        $p = self::pending();
        if ($p && $acc_v !== $p['version']) {
            $rows = '';
            foreach ((array) $p['changes'] as $c) {
                $rows .= '<li>' . esc_html($c['label']) . ($c['key'] === 'vorlage' ? ': Wortlaut überarbeitet' : ': ' . esc_html(self::change_value($c['key'], $c['old'])) . ' → <strong>' . esc_html(self::change_value($c['key'], $c['new'])) . '</strong>') . '</li>';
            }
            $need = !empty($p['requires_consent']);
            echo '<div style="' . $box . 'background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;"><strong>Vertrag ändert sich zum ' . esc_html(wp_date('d.m.Y', intval($p['valid_from']))) . '</strong> (Version ' . esc_html($p['version']) . ')'
                . ($rows !== '' ? '<ul style="margin:6px 0 6px 18px;list-style:disc;">' . $rows . '</ul>' : '<br>')
                . ($need ? 'Bitte stimme bis dahin zu, damit dein Ticketverkauf ohne Pause weiterläuft. Bist du nicht einverstanden, kannst du zum Stichtag kündigen.' : 'Die Änderung ist zu deinen Gunsten, du musst nichts tun.')
                . ' <a class="button' . ($need && $manager ? ' button-primary' : '') . '" style="margin-left:6px;" href="' . esc_url($need && $manager ? '#tix-acc-terms' : self::view_url($p['version'])) . '"' . ($need && $manager ? '' : ' target="_blank"') . '>' . ($need && $manager ? 'Lesen und zustimmen' : 'Neue Fassung lesen') . '</a></div>';
        }
        // Konditionen mit geplanten Werten
        $cfg = class_exists('TIX_Fees') ? TIX_Fees::get_fee_config($oid) : [];
        $planned = class_exists('TIX_Planned_Values') ? TIX_Planned_Values::public_list(!empty($cfg['fee_custom']) ? ['gateway_fee_included', 'fee_split_customer_share', 'settlement_delay_days'] : null) : [];
        $planned = array_values(array_filter($planned, function ($x) { return $x['setting'] !== 'percent' && $x['setting'] !== 'fixed'; })); // Kanal-Gebühr betrifft Partner-Shops
        $fee = self::num($cfg['fee_percent'] ?? 0) . ' % + ' . self::eur($cfg['fee_fixed'] ?? 0) . ' € je Ticket' . (!empty($cfg['fee_custom']) ? ' (Zusatzvereinbarung)' : '');
        echo '<div style="' . $box . 'background:#fff;border:1px solid #e5e7eb;"><strong>Deine Konditionen</strong><br>Gebühr: ' . esc_html($fee) . ' · Zahlungskosten: ' . (!empty($cfg['gateway_fee_included']) ? 'in der Gebühr enthalten' : 'zusätzlich') . ' · Auszahlung: ' . esc_html((string) (class_exists('TIX_Settlement') ? TIX_Settlement::delay_days($oid) : 7)) . ' Tage nach dem Event';
        foreach ($planned as $x) {
            echo '<br><span style="color:#92400e;">' . ($x['valid_from'] ? 'Ab ' . esc_html(wp_date('d.m.Y', strtotime($x['valid_from']))) : 'Geplant (Ankündigung folgt)') . ': ' . esc_html($x['label']) . ' ' . esc_html($x['current_label']) . ' → ' . esc_html($x['planned_label']) . '</span>';
        }
        echo '</div>';
    }

    /** Anzeigewert einer Änderung (Zahlen deutsch, Ja/Nein). */
    private static function change_value($key, $v) {
        if ($key === 'zahlungskosten') return $v ? 'enthalten' : 'zusätzlich';
        if (in_array($key, ['gebuehr_fix', 'kanal_gebuehr_fix'], true)) return self::eur($v) . ' €';
        if (in_array($key, ['gebuehr_prozent', 'kanal_gebuehr_prozent', 'anteil_gast_prozent'], true)) return self::num($v) . ' %';
        if ($key === 'gebuehr_modus_vorgabe') return self::mode_label((string) $v);
        if (in_array($key, ['auszahlung_tage', 'zahlungsziel_tage', 'einwand_frist_tage', 'aenderung_vorlauf'], true)) return intval($v) . ' Tage';
        if ($key === 'kuendigungsfrist') return self::months_text($v);
        return (string) $v;
    }

    // ── Admin ──

    public static function admin_notices() {
        if (!self::active()) return;
        if (current_user_can('manage_options') && ($d = self::draft())) {
            $url = admin_url('admin.php?page=' . TIX_Org_Approval::ADMIN_SLUG . '&tab=settings#tix-contract-draft');
            $lbl = ['favorable' => 'zugunsten (gilt sofort)', 'adverse' => 'nachteilig (Ankündigung + Vorlauf)', 'immediate' => 'sofort gültig, Zustimmung nötig'][$d['classification']] ?? '';
            $miss = self::missing_master_data($d['values']);
            echo '<div class="notice notice-warning"><p><strong>Vermittlungsvertrag:</strong> Werte im Vertrag haben sich geändert (' . esc_html(implode(', ', array_column($d['changes'], 'label')) ?: 'erste Fassung') . ') – Einstufung: ' . esc_html($lbl) . '. '
                . ($miss ? 'Stammdaten fehlen (' . esc_html(implode(', ', $miss)) . '), daher wird nichts veröffentlicht. ' : 'Ohne Eingriff wird die neue Version in einer Stunde veröffentlicht. ')
                . '<a href="' . esc_url($url) . '">Prüfen und veröffentlichen</a></p></div>';
        }
        $oid = class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
        if ($oid && !current_user_can('manage_options') && ($p = self::pending()) && !empty($p['requires_consent'])) {
            $a = get_post_meta($oid, TIX_Org_Approval::META_TERMS, true);
            if (!is_array($a) || ($a['version'] ?? '') !== $p['version']) {
                echo '<div class="notice notice-info"><p><strong>Vermittlungsvertrag:</strong> Ab ' . esc_html(wp_date('d.m.Y', intval($p['valid_from']))) . ' gilt eine neue Version. Bitte lies sie und stimme zu: <a href="' . esc_url(admin_url('admin.php?page=' . TIX_Org_Approval::ORG_SLUG . '#tix-acc-terms')) . '">Mein Konto</a></p></div>';
            }
        }
    }

    /** Einstellungs-Abschnitt in Veranstalter-Prüfung → Einstellungen. */
    public static function render_settings($s) {
        $c = self::values_settings();
        $raw = self::raw_values();
        $disp = self::display_values($raw);
        $d = self::draft();
        $miss = self::missing_master_data($raw);
        ?>
        <tr><th>Vertragsquelle</th><td>
            <label><input type="radio" name="terms_source" value="page" <?php checked(($s['terms_source'] ?? 'page') !== 'template'); ?>> Seite bzw. Link (Version von Hand)</label><br>
            <label><input type="radio" name="terms_source" value="template" <?php checked(($s['terms_source'] ?? 'page') === 'template'); ?>> Vorlage im Plugin mit Platzhaltern, automatische Versionen</label>
        </td></tr>
        <tr><th>Stammdaten evendis</th><td>
            <p class="description">Firma, Anschrift und USt-IdNr. kommen aus Tixomat → Einstellungen → Rechnungen (<?php echo esc_html($disp['evendis_firma'] ?: '–'); ?> · <?php echo esc_html($disp['evendis_anschrift'] ?: '–'); ?> · <?php echo esc_html($disp['evendis_ust_id'] ?: '–'); ?>).</p>
            <p><label>Vertreten durch<br><input type="text" name="contract[evendis_vertreten_durch]" value="<?php echo esc_attr($c['evendis_vertreten_durch']); ?>" class="regular-text" placeholder="z. B. die Geschäftsführerin …"></label></p>
            <p><label>Register (optional)<br><input type="text" name="contract[evendis_register]" value="<?php echo esc_attr($c['evendis_register']); ?>" class="regular-text" placeholder="Amtsgericht …, HRB …"></label></p>
            <?php if ($miss) : ?><p style="color:#b91c1c;"><strong>Stammdaten fehlen:</strong> <?php echo esc_html(implode(', ', $miss)); ?> – bis dahin kann niemand zustimmen.</p><?php endif; ?>
        </td></tr>
        <tr><th>Vertragswerte</th><td>
            <p><label>Einwandfrist (Tage) <input type="number" name="contract[einwand_frist_tage]" value="<?php echo esc_attr($c['einwand_frist_tage']); ?>" min="1" max="365" style="width:80px;"></label>
               <label style="margin-left:14px;">Zahlungsziel (Tage) <input type="number" name="contract[zahlungsziel_tage]" value="<?php echo esc_attr($c['zahlungsziel_tage']); ?>" min="0" max="365" style="width:80px;"></label></p>
            <p><label>Kündigungsfrist (Monate) <input type="number" name="contract[kuendigung_monate]" value="<?php echo esc_attr($c['kuendigung_monate']); ?>" min="1" max="12" style="width:80px;"></label>
               <label style="margin-left:14px;">Änderungsvorlauf (Tage) <input type="number" name="contract[aenderung_vorlauf_tage]" value="<?php echo esc_attr($c['aenderung_vorlauf_tage']); ?>" min="1" max="365" style="width:80px;"></label></p>
            <p><label>Gerichtsstand<br><input type="text" name="contract[gerichtsstand]" value="<?php echo esc_attr($c['gerichtsstand']); ?>" class="regular-text"></label></p>
            <p><label>Hinweis zur Umsatzsteuer<br><input type="text" name="contract[mwst_hinweis]" value="<?php echo esc_attr($c['mwst_hinweis']); ?>" class="regular-text"></label></p>
        </td></tr>
        <tr><th>Vorlage</th><td>
            <textarea name="contract_template" rows="14" class="large-text code"><?php echo esc_textarea(self::template()); ?></textarea>
            <p class="description">Markdown (## Überschrift, **fett**, 1. Liste, - Punkt, | Tabelle |). Platzhalter in {{doppelten Klammern}}. Alles ab „### Anlage“ ist die Anlage „Aktuelle Konditionen“ je Veranstalter. Vermerke **[PRÜFEN …]** werden nicht angezeigt. Leer speichern = Startvorlage. <a href="<?php echo esc_url(self::view_url()); ?>" target="_blank">Vorschau</a></p>
            <details><summary>Platzhalter mit aktuellen Werten</summary><table class="widefat striped" style="max-width:720px;margin-top:6px;">
                <?php foreach ($disp as $k => $v) : ?><tr><td><code>{{<?php echo esc_html($k); ?>}}</code></td><td><?php echo esc_html((string) $v); ?></td></tr><?php endforeach; ?>
                <tr><td><code>{{vertrag_version}}</code>, <code>{{vertrag_gueltig_ab}}</code></td><td>aus der Version</td></tr>
                <tr><td><code>{{veranstalter_*}}</code>, <code>{{eff_*}}</code>, <code>{{zusatzvereinbarungen_liste}}</code>, <code>{{konditionen_stand}}</code></td><td>je Veranstalter</td></tr>
            </table></details>
        </td></tr>
        <?php
    }

    /** Entwurf + Versionen (unterhalb der Einstellungen). */
    public static function render_draft_box() {
        if (!self::active()) return;
        $d = self::draft();
        echo '<div id="tix-contract-draft" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 20px;margin:18px 0;max-width:900px;">';
        echo '<h2 style="margin-top:0;">Versionen des Vertrags</h2>';
        if ($d) {
            $miss = self::missing_master_data($d['values']);
            echo '<p><strong>Neue Version vorbereitet</strong> (' . esc_html(wp_date('d.m.Y H:i', intval($d['created_at']))) . '):</p>' . (self::changes_html($d['changes']) ?: '<p>Erste Fassung aus der Vorlage.</p>');
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('tix_contract_publish', '_wpnonce', true, false) . '<input type="hidden" name="action" value="tix_contract_publish">';
            foreach (['favorable' => 'Zugunsten der Veranstalter – gilt sofort, keine neue Zustimmung (Info-Mail)', 'adverse' => 'Nachteilig – Ankündigung per Mail, gilt nach dem Änderungsvorlauf, dann Zustimmung vor dem Verkauf', 'immediate' => 'Sofort gültig, Zustimmung nötig (z. B. erste Fassung)'] as $k => $l) {
                echo '<label style="display:block;margin:4px 0;"><input type="radio" name="classification" value="' . esc_attr($k) . '" ' . checked($d['classification'], $k, false) . '> ' . esc_html($l) . ($d['auto'] === $k ? ' <em>(automatisch eingestuft)</em>' : '') . '</label>';
            }
            echo $miss ? '<p style="color:#b91c1c;">Stammdaten fehlen: ' . esc_html(implode(', ', $miss)) . '.</p><button class="button" disabled>Veröffentlichen</button>' : '<p><button class="button button-primary">Jetzt veröffentlichen</button> <span class="description">Sonst automatisch eine Stunde nach der Änderung.</span></p>';
            echo '</form>';
        } else {
            echo '<p>Keine offenen Änderungen.</p>';
        }
        if (class_exists('TIX_Planned_Values')) TIX_Planned_Values::render_box();
        $vs = array_reverse(self::versions());
        if ($vs) {
            echo '<table class="widefat striped" style="margin-top:10px;"><thead><tr><th>Version</th><th>gültig ab</th><th>Einstufung</th><th>Zustimmung</th><th>SHA-256</th></tr></thead><tbody>';
            foreach ($vs as $v) {
                echo '<tr><td><a href="' . esc_url(self::view_url($v['version'])) . '" target="_blank">' . esc_html($v['version']) . '</a></td><td>' . esc_html(wp_date('d.m.Y', intval($v['valid_from']))) . '</td><td>' . esc_html(['favorable' => 'zugunsten', 'adverse' => 'nachteilig', 'immediate' => 'sofort, Zustimmung'][$v['classification']] ?? '') . '</td><td>' . (!empty($v['requires_consent']) ? 'nötig' : '–') . '</td><td><code>' . esc_html(substr($v['sha256'], 0, 16)) . '…</code></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /** Speichern aus dem Einstellungsformular (von TIX_Org_Approval::handle_settings). */
    public static function save_settings_post(array &$s) {
        $s['terms_source'] = (($_POST['terms_source'] ?? '') === 'template') ? 'template' : 'page';
        if (isset($_POST['contract']) && is_array($_POST['contract'])) {
            $in = wp_unslash($_POST['contract']);
            $s['contract'] = [
                'evendis_vertreten_durch' => sanitize_text_field((string) ($in['evendis_vertreten_durch'] ?? '')),
                'evendis_register'        => sanitize_text_field((string) ($in['evendis_register'] ?? '')),
                'einwand_frist_tage'      => max(1, min(365, intval($in['einwand_frist_tage'] ?? 30))),
                'zahlungsziel_tage'       => max(0, min(365, intval($in['zahlungsziel_tage'] ?? 14))),
                'kuendigung_monate'       => max(1, min(12, intval($in['kuendigung_monate'] ?? 1))),
                'aenderung_vorlauf_tage'  => max(1, min(365, intval($in['aenderung_vorlauf_tage'] ?? 42))),
                'gerichtsstand'           => sanitize_text_field((string) ($in['gerichtsstand'] ?? '')) ?: self::DEFAULTS['gerichtsstand'],
                'mwst_hinweis'            => sanitize_text_field((string) ($in['mwst_hinweis'] ?? '')) ?: self::DEFAULTS['mwst_hinweis'],
            ];
        }
        if (isset($_POST['contract_template'])) {
            $tpl = (string) wp_unslash($_POST['contract_template']);
            $tpl = str_replace("\r\n", "\n", $tpl);
            if (trim($tpl) === '' || trim($tpl) === trim(self::default_template())) delete_option(self::OPTION_TEMPLATE);
            else update_option(self::OPTION_TEMPLATE, wp_kses_post($tpl), false);
        }
    }

    public static function handle_publish() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_contract_publish');
        $r = self::publish(sanitize_key($_POST['classification'] ?? ''), get_current_user_id());
        $msg = is_wp_error($r) ? $r->get_error_message() : 'Version ' . $r['version'] . ' veröffentlicht (gültig ab ' . wp_date('d.m.Y', intval($r['valid_from'])) . ').';
        wp_safe_redirect(add_query_arg(['page' => TIX_Org_Approval::ADMIN_SLUG, 'tab' => 'settings', 'msg' => rawurlencode($msg)], admin_url('admin.php')));
        exit;
    }
}
