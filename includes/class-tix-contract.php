<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Contract – Vermittlungsvertrag komplett digital (nur Mehr-Veranstalter-Modus, evendis). Baut auf TIX_Org_Approval auf.
 *
 * 1. Eingefrorene Fassungen: Beim Anheben der Vertragsversion (Tixomat → Veranstalter-Prüfung → Einstellungen) wird der
 *    Wortlaut der Vertragsseite als Schnappschuss gespeichert (Option tix_contract_snapshots: version → {title, url, html,
 *    text, sha256, frozen_at, by}); fehlt er bei einer Zustimmung, wird er dann angelegt. Eine eingefrorene Fassung ändert
 *    sich nicht mehr – neuer Wortlaut = neue Version.
 * 2. Nachweis: Die Zustimmung (_tix_org_terms) trägt zusätzlich sha256; jede Zustimmung landet auch in
 *    _tix_org_terms_history. Danach geht eine Mail mit dem Vertrag als PDF (Wortlaut, Veranstalter, Nutzer, Zeitpunkt, IP,
 *    Prüfsumme) an den Inhaber, auf Wunsch als Kopie ans Admin-Postfach (Einstellung contract_copy_admin).
 * 3. Zusatzvereinbarung: Setzt ein Admin eine eigene Gebühr (oder weitere Sonderkonditionen als Text), entsteht ein Vorschlag
 *    (_tix_org_addendum_pending). Bis der Inhaber zustimmt, gilt die bisherige Kondition. Zustimmung im WP („Mein Konto“) und
 *    per REST; danach greift die neue Gebühr, Nachweis in _tix_org_addenda, Mail mit PDF.
 * 4. Hinweis beim ersten Verkauf: Event-Editor (WP-Hinweis, App: contract_notice in GET /events/{id}/edit).
 *
 * REST:
 *   GET  /organizer/approval/addendum → {pending: {id, title, text, values, previous, created_at, sha256} | null, accepted: [...]}
 *   POST /organizer/approval/addendum {id, accept: true} (Inhaber/Team-Admin) → wie GET; 409 tix_addendum_changed bei alter id
 *   GET  /organizer/approval/document?type=terms|addendum[&id=] → PDF (Inhaber/Team-Admin)
 *   GET  /organizer/approval → zusätzlich addendum (pending oder null), terms.sha256
 */
class TIX_Contract {

    const NS              = 'tixomat/v1';
    const OPTION_SNAPS    = 'tix_contract_snapshots';
    const META_HISTORY    = '_tix_org_terms_history';
    const META_PENDING    = '_tix_org_addendum_pending';
    const META_ADDENDA    = '_tix_org_addenda';

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && class_exists('TIX_Org_Approval');
    }

    public static function init() {
        if (!self::multi()) return;
        add_action('update_option_' . TIX_Org_Approval::OPTION, [__CLASS__, 'on_settings'], 10, 2);
        add_action('add_option_' . TIX_Org_Approval::OPTION, function ($name, $value) { TIX_Contract::on_settings([], $value); }, 10, 2);
        add_action('tix_org_terms_accepted', [__CLASS__, 'on_accepted'], 10, 3);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('add_meta_boxes', function () {
            add_meta_box('tix_contract_docs', 'Vertrag & Nachweise', [__CLASS__, 'render_box'], 'tix_organizer', 'side', 'default');
        });
        add_action('admin_post_tix_contract_doc', [__CLASS__, 'handle_doc']);
        add_action('admin_post_tix_contract_addendum', [__CLASS__, 'handle_accept_form']);
        add_action('tix_org_account_sections', [__CLASS__, 'render_account_section'], 10, 2);
        add_action('tix_org_account_top', [__CLASS__, 'render_account_top'], 30, 2);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
    }

    // ── 1. Fassungen ──

    public static function snapshots() {
        $s = get_option(self::OPTION_SNAPS, []);
        return is_array($s) ? $s : [];
    }

    public static function snapshot($version) {
        $s = self::snapshots();
        return isset($s[(string) $version]) ? $s[(string) $version] : null;
    }

    public static function on_settings($old, $new) {
        if (is_array($new) && ($new['terms_source'] ?? 'page') === 'template') return; // Versionen macht TIX_Contract_Template
        $v = is_array($new) ? (string) ($new['terms_version'] ?? '') : '';
        if ($v !== '' && !self::snapshot($v)) self::freeze($v);
    }

    /** Wortlaut in Absätzen (Text) aus HTML. */
    public static function html_to_text($html) {
        $html = preg_replace('#<(script|style|noscript|svg|head|nav|header|footer)\b[^>]*>.*?</\1>#is', ' ', (string) $html);
        $html = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr|/section|/article)\b[^>]*>#i', "\n", $html);
        $html = preg_replace('#<li\b[^>]*>#i', "\n• ", $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
        $lines = array_map(function ($l) { return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)); }, preg_split('/\R/u', $text));
        $out = []; $blank = false;
        foreach ($lines as $l) {
            if ($l === '') { if (!$blank && $out) $out[] = ''; $blank = true; continue; }
            $out[] = $l; $blank = false;
        }
        return trim(implode("\n", $out));
    }

    /** Wortlaut der aktuellen Vertragsseite holen: Seite im WordPress, sonst Adresse. */
    private static function fetch_current() {
        $s = TIX_Org_Approval::settings();
        $t = TIX_Org_Approval::terms();
        $html = '';
        $pid = intval($s['terms_page_id']);
        if ($pid && ($p = get_post($pid))) {
            $html = '<h1>' . esc_html(get_the_title($p)) . '</h1>' . wpautop(do_shortcode(function_exists('do_blocks') ? do_blocks((string) $p->post_content) : (string) $p->post_content));
        } elseif ($t['url'] !== '') {
            $res = wp_remote_get($t['url'], ['timeout' => 10, 'redirection' => 3]);
            if (!is_wp_error($res) && intval(wp_remote_retrieve_response_code($res)) === 200) {
                $body = (string) wp_remote_retrieve_body($res);
                if (preg_match('#<main\b[^>]*>(.*)</main>#is', $body, $m)) $body = $m[1];
                elseif (preg_match('#<body\b[^>]*>(.*)</body>#is', $body, $m)) $body = $m[1];
                $html = wp_kses_post($body);
            }
        }
        return ['title' => $t['title'], 'url' => $t['url'], 'html' => $html, 'text' => self::html_to_text($html)];
    }

    /** Fassung einfrieren (nur einmal je Version). */
    public static function freeze($version, $by = 0) {
        $version = (string) $version;
        $snaps = self::snapshots();
        if ($version === '' || isset($snaps[$version])) return $snaps[$version] ?? null;
        $c = self::fetch_current();
        if ($c['text'] === '') return null; // Wortlaut nicht lesbar: später erneut (bei der nächsten Zustimmung)
        $snaps[$version] = $c + ['version' => $version, 'sha256' => hash('sha256', $c['text']), 'frozen_at' => time(), 'by' => intval($by ?: get_current_user_id())];
        update_option(self::OPTION_SNAPS, $snaps, false);
        return $snaps[$version];
    }

    /** Prüfsumme der Version (friert bei Bedarf ein); '' wenn kein Wortlaut lesbar ist. */
    public static function sha_for($version) {
        $s = self::snapshot($version) ?: self::freeze($version);
        return $s ? (string) $s['sha256'] : '';
    }

    // ── 2. Zustimmung: Verlauf + Mail ──

    public static function on_accepted($oid, $uid, $record) {
        $hist = get_post_meta($oid, self::META_HISTORY, true);
        $hist = is_array($hist) ? $hist : [];
        $hist[] = $record;
        update_post_meta($oid, self::META_HISTORY, array_slice($hist, -50));
        $snap = self::snapshot($record['version'] ?? '');
        $pdf = self::pdf_terms($oid, $record, $snap);
        $name = self::org_name($oid);
        $t = TIX_Org_Approval::terms();
        $body = '<p>Hallo,</p><p>danke – die Zustimmung von <strong>' . esc_html($name) . '</strong> zum ' . esc_html($t['title']) . ' (Version ' . esc_html((string) $record['version']) . ') ist gespeichert.</p>'
            . '<p>Im Anhang findest du den Vertrag mit dem Nachweis der Zustimmung (Zeitpunkt, Nutzer, IP-Adresse, Prüfsumme). Bitte bewahre ihn auf.</p>';
        self::send($oid, 'Bestätigung: ' . $t['title'] . ' (Version ' . $record['version'] . ')', 'Vertrag abgeschlossen', $body, $pdf, 'Vermittlungsvertrag-' . self::file_part($record['version']) . '.pdf');
    }

    private static function org_name($oid) {
        return html_entity_decode((string) get_the_title($oid), ENT_QUOTES, 'UTF-8');
    }

    private static function file_part($s) {
        return trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $s), '-') ?: 'Vertrag';
    }

    private static function owner_email($oid) {
        $u = get_user_by('id', intval(get_post_meta($oid, '_tix_org_user_id', true)));
        if ($u && is_email($u->user_email)) return $u->user_email;
        $e = (string) get_post_meta($oid, '_tix_org_email', true);
        return is_email($e) ? $e : '';
    }

    /** Mail mit PDF-Anhang an den Inhaber (+ Kopie ans Admin-Postfach, falls eingestellt). */
    private static function send($oid, $subject, $heading, $body, $pdf, $filename) {
        $to = self::owner_email($oid);
        $copy = !empty(TIX_Org_Approval::settings()['contract_copy_admin']) ? TIX_Org_Approval::admin_email() : '';
        if ($to === '' && $copy === '') return false;
        $files = [];
        if ($pdf) {
            $up = wp_upload_dir();
            $dir = trailingslashit($up['basedir']) . 'tix-mail-tmp/';
            if (wp_mkdir_p($dir)) {
                if (!file_exists($dir . 'index.php')) @file_put_contents($dir . 'index.php', "<?php // Stille.\n");
                if (!file_exists($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");
                $path = $dir . wp_generate_password(16, false, false) . '-' . $filename;
                if (@file_put_contents($path, $pdf) !== false) $files[] = $path;
            }
        }
        $mail = function ($addr, $subj) use ($heading, $body, $files) {
            if (class_exists('TIX_Payout_Details')) return TIX_Payout_Details::mail($addr, $subj, $heading, $body, $files);
            return wp_mail($addr, $subj, $body, ['Content-Type: text/html; charset=UTF-8'], $files);
        };
        $ok = $to !== '' ? $mail($to, $subject) : false;
        if ($copy !== '' && strcasecmp($copy, $to) !== 0) $mail($copy, '[Kopie] ' . $subject . ' – ' . self::org_name($oid));
        foreach ($files as $f) @unlink($f);
        return $ok;
    }

    // ── PDF ──

    private static function pdf_new() {
        if (!class_exists('TIX_Simple_PDF') && defined('TIXOMAT_PATH')) require_once TIXOMAT_PATH . 'includes/class-tix-event-report.php';
        return class_exists('TIX_Simple_PDF') ? new TIX_Simple_PDF(595, 842, 50, 40) : null;
    }

    private static function wrap($text, $max) {
        $out = [];
        foreach (preg_split('/\R/u', (string) $text) as $para) {
            if (trim($para) === '') { $out[] = ''; continue; }
            $line = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                while (mb_strlen($word) > $max) { if ($line !== '') { $out[] = $line; $line = ''; } $out[] = mb_substr($word, 0, $max); $word = mb_substr($word, $max); }
                if ($line !== '' && mb_strlen($line . ' ' . $word) > $max) { $out[] = $line; $line = $word; }
                else $line = $line === '' ? $word : $line . ' ' . $word;
            }
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }

    /** Ein Dokument: Titel, Nachweis-Block, Wortlaut (mehrseitig). */
    private static function pdf_doc($title, array $proof, $text, $sha) {
        $pdf = self::pdf_new();
        if (!$pdf) return '';
        $m = 50; $top = 842 - 50; $bottom = 60;
        $pdf->set_footer(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . ' · ' . $title . ' · Seite {p} von {n}');
        $pdf->add_page();
        $y = $top;
        $pdf->set_font('Helvetica-Bold', 16); $pdf->set_color(0.17, 0.18, 0.25);
        foreach (self::wrap($title, 55) as $l) { $pdf->text($m, $y, $l); $y -= 20; }
        $y -= 6;
        // Nachweis
        $pdf->set_color(0.95, 0.95, 0.97);
        $h = 16 + 13 * (count($proof) + 2);
        $pdf->rect($m - 6, $y - $h + 10, 595 - 2 * $m + 12, $h, true);
        $pdf->set_font('Helvetica-Bold', 9); $pdf->set_color(0.17, 0.18, 0.25);
        $pdf->text($m, $y, 'Nachweis der Zustimmung'); $y -= 15;
        foreach ($proof as $k => $v) {
            $pdf->set_font('Helvetica', 8); $pdf->set_color(0.42, 0.44, 0.51); $pdf->text($m, $y, $k);
            $pdf->set_font('Helvetica', 9); $pdf->set_color(0, 0, 0); $pdf->text($m + 110, $y, mb_substr((string) $v, 0, 70));
            $y -= 13;
        }
        $pdf->set_font('Helvetica', 8); $pdf->set_color(0.42, 0.44, 0.51); $pdf->text($m, $y, 'Prüfsumme (SHA-256)');
        $pdf->set_font('Helvetica', 7); $pdf->set_color(0, 0, 0); $pdf->text($m + 110, $y, $sha !== '' ? $sha : '–');
        $y -= 30;
        // Wortlaut
        $pdf->set_font('Helvetica-Bold', 11); $pdf->set_color(0.17, 0.18, 0.25); $pdf->text($m, $y, 'Wortlaut'); $y -= 16;
        $pdf->set_font('Helvetica', 9); $pdf->set_color(0, 0, 0);
        foreach (self::wrap($text !== '' ? $text : '(Der Wortlaut dieser Version konnte nicht gespeichert werden.)', 100) as $l) {
            if ($y < $bottom) { $pdf->add_page(); $y = $top; $pdf->set_font('Helvetica', 9); $pdf->set_color(0, 0, 0); }
            if ($l !== '') $pdf->text($m, $y, $l);
            $y -= $l === '' ? 6 : 12;
        }
        return (string) $pdf->output();
    }

    private static function when($ts) {
        $ts = intval($ts);
        return $ts ? wp_date('d.m.Y H:i:s', $ts) . ' Uhr (' . gmdate('Y-m-d\TH:i:s\Z', $ts) . ')' : '';
    }

    private static function user_line($uid) {
        $u = get_user_by('id', intval($uid));
        return $u ? trim($u->display_name . ' <' . $u->user_email . '> · ID ' . $u->ID) : ('ID ' . intval($uid));
    }

    public static function pdf_terms($oid, $record, $snap = null) {
        $snap = $snap ?: self::snapshot($record['version'] ?? '');
        $t = TIX_Org_Approval::terms();
        $title = ($snap['title'] ?? $t['title']) . ' – Version ' . ($record['version'] ?? '');
        $proof = [
            'Veranstalter' => self::org_name($oid) . ' (ID ' . intval($oid) . ')',
            'Zugestimmt von' => self::user_line($record['user_id'] ?? 0),
            'Zeitpunkt' => self::when($record['accepted_at'] ?? 0),
            'IP-Adresse' => (string) ($record['ip'] ?? ''),
            'Fundstelle' => (string) ($record['url'] ?? ($snap['url'] ?? '')),
        ];
        // Vorlagen-Modus: Wortlaut mit den Angaben des Veranstalters + Anlage „Aktuelle Konditionen“ zum Zeitpunkt der Zustimmung
        $text = (string) ($snap['text'] ?? '');
        if (!empty($record['doc_text'])) {
            $text = (string) $record['doc_text'] . (!empty($record['annex_text']) ? "\n\n" . (string) $record['annex_text'] : '');
            if (!empty($snap['valid_from'])) $proof['Gültig ab'] = wp_date('d.m.Y', intval($snap['valid_from']));
            if (!empty($record['doc_sha256'])) $proof['Prüfsumme Dokument'] = substr((string) $record['doc_sha256'], 0, 32) . '…';
        }
        return self::pdf_doc($title, $proof, $text, (string) ($record['sha256'] ?? ($snap['sha256'] ?? '')));
    }

    public static function pdf_addendum($oid, $a) {
        return self::pdf_doc((string) $a['title'], [
            'Veranstalter' => self::org_name($oid) . ' (ID ' . intval($oid) . ')',
            'Zugestimmt von' => !empty($a['user_id']) ? self::user_line($a['user_id']) : '– noch offen –',
            'Zeitpunkt' => !empty($a['accepted_at']) ? self::when($a['accepted_at']) : '',
            'IP-Adresse' => (string) ($a['ip'] ?? ''),
            'Vorgeschlagen' => self::when($a['created_at'] ?? 0),
        ], (string) $a['text'], (string) $a['sha256']);
    }

    // ── 3. Zusatzvereinbarung ──

    private static function eur($v) { return number_format((float) $v, 2, ',', '.') . ' €'; }
    private static function pct($v) { return rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ','); }

    /** Aktuelle Kondition des Veranstalters (eigene Gebühr ja/nein + Werte). */
    public static function current_fee($oid) {
        return [
            'custom'  => (bool) get_post_meta($oid, '_tix_fee_custom', true),
            'percent' => round(floatval(get_post_meta($oid, '_tix_fee_percent', true)), 4),
            'fixed'   => round(floatval(get_post_meta($oid, '_tix_fee_fixed', true)), 2),
        ];
    }

    private static function fee_sentence($fee) {
        $g = class_exists('TIX_Fees') ? TIX_Fees::get_fee_config(null) : [];
        $incl = !empty($g['gateway_fee_included']) ? ' (inkl. MwSt. und Zahlungsgebühren)' : ' (inkl. MwSt.)';
        if (empty($fee['custom'])) {
            return 'die allgemeine Plattformgebühr von ' . self::pct($g['fee_percent'] ?? 0) . ' % zzgl. ' . self::eur($g['fee_fixed'] ?? 0) . ' je Ticket' . $incl;
        }
        return 'eine Plattformgebühr von ' . self::pct($fee['percent']) . ' % zzgl. ' . self::eur($fee['fixed']) . ' je Ticket' . $incl;
    }

    /** Vorschlag anlegen, wenn sich Gebühr oder Sonderkonditionen ändern; sonst null. */
    public static function propose($oid, array $fee, $extra = '') {
        $oid = intval($oid);
        $fee = ['custom' => !empty($fee['custom']), 'percent' => round(max(0, min(100, floatval($fee['percent'] ?? 0))), 4), 'fixed' => round(max(0, floatval($fee['fixed'] ?? 0)), 2)];
        if (!$fee['custom']) { $fee['percent'] = 0.0; $fee['fixed'] = 0.0; }
        $extra = trim(wp_strip_all_tags((string) $extra));
        $cur = self::current_fee($oid);
        $cur_cmp = $cur['custom'] ? $cur : ['custom' => false, 'percent' => 0.0, 'fixed' => 0.0];
        $last = self::last_accepted($oid);
        $cur_extra = $last ? (string) ($last['values']['extra'] ?? '') : '';
        $pending = self::pending($oid);
        if ($fee == $cur_cmp && $extra === $cur_extra) {
            if ($pending) { delete_post_meta($oid, self::META_PENDING); TIX_Org_Approval::log($oid, 'addendum', ['note' => 'Vorschlag zurückgezogen (Kondition unverändert)']); }
            return null;
        }
        if ($pending && $pending['values']['fee'] == $fee && (string) $pending['values']['extra'] === $extra) return $pending;
        $t = TIX_Org_Approval::terms();
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $text = 'Zusatzvereinbarung zum ' . $t['title'] . ($t['version'] !== '' ? ' (Version ' . $t['version'] . ')' : '') . "\n"
            . 'zwischen ' . $site . ' und ' . self::org_name($oid) . ".\n\n"
            . '1. Ab der Bestätigung dieser Vereinbarung gilt für neue Bestellungen ' . self::fee_sentence($fee) . ".\n"
            . '   Bisher: ' . self::fee_sentence($cur) . ".\n"
            . ($extra !== '' ? "\n2. Weitere Vereinbarungen:\n" . $extra . "\n" : '')
            . "\n" . ($extra !== '' ? '3' : '2') . '. Alle übrigen Regelungen des Vermittlungsvertrags bleiben unverändert. Bis zur Bestätigung gilt die bisherige Kondition.';
        $a = [
            'id'         => wp_generate_password(12, false, false),
            'title'      => 'Zusatzvereinbarung: individuelle Konditionen',
            'text'       => $text,
            'sha256'     => hash('sha256', $text),
            'values'     => ['fee' => $fee, 'extra' => $extra],
            'previous'   => ['fee' => $cur, 'extra' => $cur_extra],
            'terms_version' => $t['version'],
            'created_at' => time(),
            'created_by' => get_current_user_id(),
        ];
        update_post_meta($oid, self::META_PENDING, $a);
        TIX_Org_Approval::log($oid, 'addendum', ['note' => 'Zusatzvereinbarung vorgeschlagen (' . self::fee_sentence($fee) . ')']);
        self::notify_pending($oid, $a);
        return $a;
    }

    public static function pending($oid) {
        $p = get_post_meta(intval($oid), self::META_PENDING, true);
        return is_array($p) && !empty($p['id']) ? $p : null;
    }

    public static function accepted_list($oid) {
        $l = get_post_meta(intval($oid), self::META_ADDENDA, true);
        return is_array($l) ? $l : [];
    }

    private static function last_accepted($oid) {
        $l = self::accepted_list($oid);
        return $l ? end($l) : null;
    }

    private static function notify_pending($oid, $a) {
        $to = self::owner_email($oid);
        if ($to === '') return;
        $url = admin_url('admin.php?page=' . TIX_Org_Approval::ORG_SLUG . '#tix-acc-addendum');
        $body = '<p>Hallo,</p><p>für <strong>' . esc_html(self::org_name($oid)) . '</strong> liegt eine Zusatzvereinbarung mit individuellen Konditionen bereit:</p>'
            . '<p style="background:#f3f4f6;padding:12px;border-radius:8px;">' . nl2br(esc_html($a['text'])) . '</p>'
            . '<p>Bitte bestätige sie in der App oder unter <a href="' . esc_url($url) . '">Mein Konto</a>. Bis dahin gilt die bisherige Kondition.</p>';
        if (class_exists('TIX_Payout_Details')) TIX_Payout_Details::mail($to, 'Zusatzvereinbarung bitte bestätigen', 'Neue Konditionen', $body);
        foreach (TIX_Org_Approval::manager_ids($oid) as $uid) {
            if (class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'add_user_item')) {
                TIX_Notifications::add_user_item($uid, 'Zusatzvereinbarung bestätigen', 'Neue Konditionen für ' . self::org_name($oid) . ' – bitte im Konto bestätigen.', ['type' => 'message', 'action' => 'organizer:approval']);
            }
        }
    }

    /** Zustimmung zur Zusatzvereinbarung: Kondition anwenden, Nachweis, Mail mit PDF. */
    public static function accept_addendum($oid, $uid, $id) {
        $oid = intval($oid);
        $p = self::pending($oid);
        if (!$p) return new WP_Error('tix_no_addendum', 'Es liegt keine Zusatzvereinbarung zur Bestätigung vor.', ['status' => 404]);
        if ((string) $id !== (string) $p['id']) return new WP_Error('tix_addendum_changed', 'Die Zusatzvereinbarung wurde inzwischen geändert. Bitte lies die aktuelle Fassung.', ['status' => 409, 'addendum' => self::public_addendum($p)]);
        $fee = $p['values']['fee'];
        if (!empty($fee['custom'])) {
            update_post_meta($oid, '_tix_fee_custom', 1);
            update_post_meta($oid, '_tix_fee_percent', $fee['percent']);
            update_post_meta($oid, '_tix_fee_fixed', $fee['fixed']);
        } else {
            delete_post_meta($oid, '_tix_fee_custom');
        }
        update_post_meta($oid, '_tix_fee_custom_changed', ['by' => intval($p['created_by'] ?? 0), 'at' => current_time('mysql'), 'accepted_by' => intval($uid), 'addendum' => $p['id']]);
        $rec = $p + ['accepted_at' => time(), 'user_id' => intval($uid), 'ip' => class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? ''))];
        $list = self::accepted_list($oid);
        $list[] = $rec;
        update_post_meta($oid, self::META_ADDENDA, $list);
        delete_post_meta($oid, self::META_PENDING);
        TIX_Org_Approval::log($oid, 'addendum', ['by' => intval($uid), 'note' => 'Zusatzvereinbarung bestätigt (' . self::fee_sentence($fee) . ')']);
        $body = '<p>Hallo,</p><p>die Zusatzvereinbarung für <strong>' . esc_html(self::org_name($oid)) . '</strong> ist bestätigt und gilt ab sofort für neue Bestellungen.</p><p>Im Anhang findest du sie mit dem Nachweis (Zeitpunkt, Nutzer, IP-Adresse, Prüfsumme).</p>';
        self::send($oid, 'Bestätigung: Zusatzvereinbarung', 'Zusatzvereinbarung bestätigt', $body, self::pdf_addendum($oid, $rec), 'Zusatzvereinbarung-' . self::file_part(wp_date('Y-m-d', $rec['accepted_at'])) . '.pdf');
        return $rec;
    }

    public static function public_addendum($a) {
        if (!$a) return null;
        $out = [
            'id' => (string) $a['id'], 'title' => (string) $a['title'], 'text' => (string) $a['text'], 'sha256' => (string) $a['sha256'],
            'values' => $a['values'], 'previous' => $a['previous'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', intval($a['created_at'])),
        ];
        if (!empty($a['accepted_at'])) $out['accepted_at'] = gmdate('Y-m-d\TH:i:s\Z', intval($a['accepted_at']));
        return $out;
    }

    // ── REST ──

    public static function register_routes() {
        register_rest_route(self::NS, '/organizer/approval/addendum', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => ['TIX_Org_Approval', 'perm_member']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_accept'], 'permission_callback' => ['TIX_Org_Approval', 'perm_manager']],
        ]);
        register_rest_route(self::NS, '/organizer/approval/document', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_document'], 'permission_callback' => ['TIX_Org_Approval', 'perm_manager'],
        ]);
    }

    private static function oid() {
        return class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
    }

    public static function addendum_payload($oid) {
        return [
            'ok' => true,
            'pending' => self::public_addendum(self::pending($oid)),
            'accepted' => array_values(array_map([__CLASS__, 'public_addendum'], self::accepted_list($oid))),
        ];
    }

    public static function rest_get(WP_REST_Request $req) {
        return rest_ensure_response(self::addendum_payload(self::oid()));
    }

    public static function rest_accept(WP_REST_Request $req) {
        $oid = self::oid();
        if (!filter_var($req->get_param('accept'), FILTER_VALIDATE_BOOLEAN)) return new WP_Error('tix_accept_required', 'Bitte bestätige die Zusatzvereinbarung.', ['status' => 400, 'field' => 'accept']);
        $r = self::accept_addendum($oid, get_current_user_id(), (string) $req->get_param('id'));
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(self::addendum_payload($oid));
    }

    /** PDF eines Dokuments: terms (aktuelle Zustimmung) oder addendum (id; ohne id die letzte bestätigte bzw. offene). */
    public static function document($oid, $type, $id = '') {
        if ($type === 'addendum') {
            $a = null;
            foreach (array_reverse(self::accepted_list($oid)) as $x) { if ($id === '' || $x['id'] === $id) { $a = $x; break; } }
            if (!$a && ($p = self::pending($oid)) && ($id === '' || $p['id'] === $id)) $a = $p;
            return $a ? ['bytes' => self::pdf_addendum($oid, $a), 'name' => 'Zusatzvereinbarung-' . self::file_part($a['id']) . '.pdf'] : null;
        }
        $rec = get_post_meta($oid, TIX_Org_Approval::META_TERMS, true);
        if (!is_array($rec) || empty($rec['version'])) return null;
        return ['bytes' => self::pdf_terms($oid, $rec), 'name' => 'Vermittlungsvertrag-' . self::file_part($rec['version']) . '.pdf'];
    }

    public static function rest_document(WP_REST_Request $req) {
        $doc = self::document(self::oid(), sanitize_key((string) $req->get_param('type')), (string) $req->get_param('id'));
        if (!$doc || $doc['bytes'] === '') return new WP_Error('tix_no_document', 'Dokument nicht gefunden.', ['status' => 404]);
        self::stream($doc);
    }

    private static function stream($doc) {
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $doc['name'] . '"');
        header('Content-Length: ' . strlen($doc['bytes']));
        echo $doc['bytes'];
        exit;
    }

    // ── WP-Admin ──

    /** Admin-Ansicht/PDF je Veranstalter (manage_options oder eigener Veranstalter). */
    public static function handle_doc() {
        $oid = intval($_GET['oid'] ?? 0);
        check_admin_referer('tix_contract_doc_' . $oid);
        if (!current_user_can('manage_options') && !TIX_Org_Approval::user_is_manager($oid, get_current_user_id())) wp_die('Kein Zugriff.');
        $type = sanitize_key($_GET['type'] ?? 'terms');
        $id = sanitize_text_field(wp_unslash($_GET['id'] ?? ''));
        if (($_GET['format'] ?? '') === 'html') {
            if ($type === 'terms') {
                $rec = get_post_meta($oid, TIX_Org_Approval::META_TERMS, true);
                $v = is_array($rec) ? (string) ($_GET['version'] ?? $rec['version'] ?? '') : (string) ($_GET['version'] ?? '');
                $snap = self::snapshot(sanitize_text_field(wp_unslash($v)));
                if (!$snap) wp_die('Für diese Version ist kein Wortlaut gespeichert.');
                echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . esc_html($snap['title'] . ' – Version ' . $snap['version']) . '</title><style>body{font-family:system-ui,sans-serif;max-width:760px;margin:24px auto;padding:0 16px;line-height:1.55;color:#1f2937}.p{background:#f3f4f6;padding:10px 14px;border-radius:8px;font-size:13px}</style></head><body>';
                echo '<p class="p">Eingefrorene Fassung · Version ' . esc_html($snap['version']) . ' · gespeichert ' . esc_html(self::when($snap['frozen_at'])) . '<br>SHA-256 ' . esc_html($snap['sha256']) . '</p>';
                echo wp_kses_post($snap['html']) . '</body></html>';
                exit;
            }
        }
        $doc = self::document($oid, $type, $id);
        if (!$doc || $doc['bytes'] === '') wp_die('Dokument nicht gefunden.');
        self::stream($doc);
    }

    private static function doc_url($oid, $type, $args = []) {
        return wp_nonce_url(add_query_arg(['action' => 'tix_contract_doc', 'oid' => intval($oid), 'type' => $type] + $args, admin_url('admin-post.php')), 'tix_contract_doc_' . intval($oid));
    }

    public static function render_box($post) {
        $oid = intval($post->ID);
        $rec = get_post_meta($oid, TIX_Org_Approval::META_TERMS, true);
        echo '<div style="font-size:13px;line-height:1.5;">';
        if (is_array($rec) && !empty($rec['version'])) {
            echo '<p><strong>Vermittlungsvertrag</strong><br>Version ' . esc_html($rec['version']) . ' · ' . esc_html(wp_date('d.m.Y H:i', intval($rec['accepted_at']))) . '<br>'
                . '<small>' . esc_html(self::user_line($rec['user_id'] ?? 0)) . ' · IP ' . esc_html((string) ($rec['ip'] ?? '')) . '</small><br>'
                . '<small style="word-break:break-all;color:#6b7280;">SHA-256 ' . esc_html((string) ($rec['sha256'] ?? '–')) . '</small><br>'
                . '<a href="' . esc_url(self::doc_url($oid, 'terms', ['format' => 'html'])) . '" target="_blank">Wortlaut ansehen</a> · <a href="' . esc_url(self::doc_url($oid, 'terms')) . '">PDF</a></p>';
        } else {
            echo '<p>Noch keine Zustimmung zum Vermittlungsvertrag.</p>';
        }
        $hist = get_post_meta($oid, self::META_HISTORY, true);
        if (is_array($hist) && count($hist) > 1) {
            echo '<p><strong>Frühere Zustimmungen</strong><br>';
            foreach (array_reverse(array_slice($hist, 0, -1)) as $h) {
                echo 'Version ' . esc_html((string) $h['version']) . ' · ' . esc_html(wp_date('d.m.Y', intval($h['accepted_at']))) . ' · <a href="' . esc_url(self::doc_url($oid, 'terms', ['format' => 'html', 'version' => (string) $h['version']])) . '" target="_blank">Wortlaut</a><br>';
            }
            echo '</p>';
        }
        $p = self::pending($oid);
        if ($p) echo '<p style="background:#fff7ed;padding:8px;border-radius:6px;"><strong>Zusatzvereinbarung offen</strong> seit ' . esc_html(wp_date('d.m.Y H:i', intval($p['created_at']))) . ' – die neue Kondition gilt erst nach Bestätigung durch den Veranstalter. <a href="' . esc_url(self::doc_url($oid, 'addendum', ['id' => $p['id']])) . '">PDF</a></p>';
        $list = self::accepted_list($oid);
        if ($list) {
            echo '<p><strong>Zusatzvereinbarungen</strong><br>';
            foreach (array_reverse($list) as $a) {
                echo esc_html(wp_date('d.m.Y', intval($a['accepted_at']))) . ' · ' . esc_html(self::fee_sentence($a['values']['fee'])) . ' · <a href="' . esc_url(self::doc_url($oid, 'addendum', ['id' => $a['id']])) . '">PDF</a><br>';
            }
            echo '</p>';
        }
        echo '</div>';
    }

    /** „Mein Konto“ oben: offene Zusatzvereinbarung deutlich als Hinweis. */
    public static function render_account_top($oid, $manager) {
        $p = self::pending($oid);
        if (!$p) return;
        echo '<div style="background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid #f59e0b;border-radius:12px;padding:14px 18px;margin:0 0 18px;max-width:820px;">'
            . '<strong>Zusatzvereinbarung wartet auf deine Zustimmung</strong><br>Neue Konditionen: ' . esc_html(self::fee_sentence($p['values']['fee']))
            . ($p['values']['extra'] !== '' ? ' · ' . esc_html(mb_substr($p['values']['extra'], 0, 120)) : '') . '. Bis zur Zustimmung gilt die bisherige Kondition.'
            . ($manager ? ' <a class="button button-primary" style="margin-left:6px;" href="#tix-acc-addendum">Lesen und zustimmen</a>' : '') . '</div>';
    }

    /** „Mein Konto“: offene Zusatzvereinbarung bestätigen + Dokumente. */
    public static function render_account_section($oid, $manager) {
        $p = self::pending($oid);
        $box = 'background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin:0 0 18px;max-width:820px;';
        if ($p) {
            echo '<div id="tix-acc-addendum" style="' . $box . 'border-left:4px solid #f59e0b;"><h2 style="margin-top:0;">' . esc_html($p['title']) . '</h2>'
                . '<div style="background:#f9fafb;border-radius:8px;padding:12px 14px;white-space:pre-wrap;">' . esc_html($p['text']) . '</div>';
            if ($manager) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">' . wp_nonce_field('tix_contract_addendum', '_wpnonce', true, false)
                    . '<input type="hidden" name="action" value="tix_contract_addendum"><input type="hidden" name="id" value="' . esc_attr($p['id']) . '">'
                    . '<p><label><input type="checkbox" name="accept" value="1" required> Ich habe die Zusatzvereinbarung gelesen und stimme ihr zu.</label></p>'
                    . '<button class="button button-primary">Zustimmen</button></form>';
            } else {
                echo '<p style="color:#6b7280;">Zustimmen kann der Inhaber oder ein Team-Admin.</p>';
            }
            echo '</div>';
        }
        if (!$manager) return;
        $rec = get_post_meta($oid, TIX_Org_Approval::META_TERMS, true);
        $list = self::accepted_list($oid);
        if ((is_array($rec) && !empty($rec['version'])) || $list) {
            echo '<div style="' . $box . '"><h2 style="margin-top:0;">Deine Vertragsunterlagen</h2><ul style="margin:0;">';
            if (is_array($rec) && !empty($rec['version'])) echo '<li><a href="' . esc_url(self::doc_url($oid, 'terms')) . '">Vermittlungsvertrag, Version ' . esc_html($rec['version']) . ' (PDF)</a></li>';
            foreach (array_reverse($list) as $a) echo '<li><a href="' . esc_url(self::doc_url($oid, 'addendum', ['id' => $a['id']])) . '">Zusatzvereinbarung vom ' . esc_html(wp_date('d.m.Y', intval($a['accepted_at']))) . ' (PDF)</a></li>';
            echo '</ul></div>';
        }
    }

    public static function handle_accept_form() {
        check_admin_referer('tix_contract_addendum');
        $oid = self::oid();
        $back = admin_url('admin.php?page=' . TIX_Org_Approval::ORG_SLUG);
        if (!$oid || !TIX_Org_Approval::user_is_manager($oid, get_current_user_id())) { wp_safe_redirect(add_query_arg('err', rawurlencode('Kein Zugriff.'), $back)); exit; }
        if (empty($_POST['accept'])) { wp_safe_redirect(add_query_arg('err', rawurlencode('Bitte bestätige die Zustimmung.'), $back)); exit; }
        $r = self::accept_addendum($oid, get_current_user_id(), sanitize_text_field(wp_unslash($_POST['id'] ?? '')));
        wp_safe_redirect(add_query_arg(is_wp_error($r) ? ['err' => rawurlencode($r->get_error_message())] : ['msg' => rawurlencode('Danke – die Zusatzvereinbarung ist bestätigt und gilt ab sofort.')], $back));
        exit;
    }

    // ── 4. Hinweis beim ersten Verkauf ──

    /** Hinweis, solange der Veranstalter noch kein anderes Event mit Online-Verkauf hat; sonst null. */
    public static function first_sale_notice($event_id) {
        $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        if (!$oid && class_exists('TIX_App_Scope')) $oid = intval(TIX_App_Scope::organizer_id_for_user());
        $t = TIX_Org_Approval::terms();
        if (!$oid || $t['version'] === '') return null;
        $others = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'future', 'pending', 'private'], 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
            'post__not_in' => [intval($event_id)],
            'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) $oid], ['key' => '_tix_tickets_enabled', 'value' => '1'], ['key' => '_tix_system_giftcard_event', 'compare' => 'NOT EXISTS']]]);
        if ($others) return null;
        return ['version' => $t['version'], 'title' => $t['title'], 'url' => $t['url'],
            'text' => 'Mit dem Ticketverkauf gilt der ' . $t['title'] . ' (Version ' . $t['version'] . ').'];
    }

    public static function admin_notices() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;
        $oid = self::oid();
        if ($oid && ($p = self::pending($oid)) && $screen->id !== 'tixomat_page_' . TIX_Org_Approval::ORG_SLUG && TIX_Org_Approval::user_is_manager($oid, get_current_user_id())) {
            echo '<div class="notice notice-warning"><p><strong>Zusatzvereinbarung bereit:</strong> Für dein Konto liegen neue Konditionen vor. <a href="' . esc_url(admin_url('admin.php?page=' . TIX_Org_Approval::ORG_SLUG . '#tix-acc-addendum')) . '">Jetzt ansehen und bestätigen</a></p></div>';
        }
        if ($screen->base === 'post' && $screen->post_type === 'event' && !current_user_can('manage_options')) {
            $eid = intval($_GET['post'] ?? 0);
            $n = self::first_sale_notice($eid);
            if ($n) {
                echo '<div class="notice notice-info"><p>' . esc_html($n['text']) . ($n['url'] !== '' ? ' <a href="' . esc_url($n['url']) . '" target="_blank" rel="noopener">Vertrag ansehen</a>' : '') . '</p></div>';
            }
        }
    }
}
