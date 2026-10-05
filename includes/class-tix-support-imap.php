<?php
/**
 * Tixomat – Support: schlanker IMAP-Client (reine Sockets) + MIME-Parser
 *
 * Bewusst ohne ext-imap: die Erweiterung fehlt ab PHP 8.4 (auf dem Server
 * schon bei lsphp84) und kann kein UID MOVE. Benötigt nur openssl (für SSL/TLS),
 * mbstring/iconv (Zeichensätze). Keine WordPress-Abhängigkeit – direkt testbar.
 *
 * @since 1.38.340
 */
if (!defined('ABSPATH')) exit;

class TIX_Support_IMAP {

    private $fp     = null;
    private $tag    = 0;
    private $caps   = [];
    private $timeout = 20;

    /**
     * Verbindung aufbauen. $enc: ssl (IMAPS, meist 993) | tls (STARTTLS, meist 143) | none
     * @throws RuntimeException
     */
    public function connect($host, $port, $enc = 'ssl', $timeout = 20, $verify_ssl = true) {
        $this->timeout = max(5, intval($timeout));
        $host = trim((string) $host);
        $ctx  = stream_context_create(['ssl' => [
            'verify_peer'       => (bool) $verify_ssl,
            'verify_peer_name'  => (bool) $verify_ssl,
            'allow_self_signed' => !$verify_ssl,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);
        $scheme = ($enc === 'ssl') ? 'ssl://' : 'tcp://';
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($scheme . $host . ':' . intval($port), $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException('Verbindung zu ' . $host . ':' . intval($port) . ' fehlgeschlagen' . ($errstr ? ': ' . $errstr : '') . '.');
        }
        stream_set_timeout($fp, $this->timeout);
        $this->fp = $fp;

        $greeting = $this->read_line();
        if (strpos($greeting, '* OK') !== 0 && strpos($greeting, '* PREAUTH') !== 0) {
            throw new RuntimeException('Unerwartete Server-Begrüßung: ' . self::clip($greeting));
        }
        $this->parse_caps_from($greeting);

        if ($enc === 'tls') {
            $r = $this->command('STARTTLS');
            if (!$r['ok']) throw new RuntimeException('Server unterstützt kein STARTTLS.');
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($this->fp, true, $method)) {
                throw new RuntimeException('TLS-Aushandlung fehlgeschlagen (Zertifikat?).');
            }
            $this->caps = [];
        }
        return true;
    }

    /** Anmelden. Das Passwort wird nie in Fehlermeldungen übernommen. */
    public function login($user, $pass) {
        if (!$this->caps) $this->capability();
        $user = (string) $user; $pass = (string) $pass;
        $printable = function ($s) { return $s !== '' && !preg_match('/[\x00-\x1f\x7f-\xff]/', $s); };

        if ($printable($user) && $printable($pass) && !$this->has_cap('LOGINDISABLED')) {
            $r = $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
        } else {
            // Sonderzeichen/Umlaute → AUTHENTICATE PLAIN (Base64, beliebige Bytes)
            $b64 = base64_encode("\0" . $user . "\0" . $pass);
            $r = $this->command('AUTHENTICATE PLAIN', $b64);
        }
        if (!$r['ok']) {
            throw new RuntimeException('Anmeldung fehlgeschlagen: ' . self::clip($r['text']));
        }
        $this->caps = [];
        $this->parse_caps_from($r['text']);
        return true;
    }

    public function capability() {
        $r = $this->command('CAPABILITY');
        foreach ($r['untagged'] as $u) {
            if (stripos($u['text'], '* CAPABILITY ') === 0) $this->parse_caps_from('[' . substr($u['text'], 2) . ']');
        }
        return $this->caps;
    }

    public function has_cap($cap) {
        if (!$this->caps) $this->capability();
        return in_array(strtoupper($cap), $this->caps, true);
    }

    /** Ordner öffnen. Liefert exists, uidvalidity, uidnext. */
    public function select($folder) {
        $r = $this->command('SELECT ' . self::quote(self::encode_folder($folder)));
        if (!$r['ok']) throw new RuntimeException('Ordner „' . $folder . '“ nicht gefunden: ' . self::clip($r['text']));
        $info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($r['untagged'] as $u) {
            $t = $u['text'];
            if (preg_match('/^\* (\d+) EXISTS/i', $t, $m)) $info['exists'] = intval($m[1]);
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $t, $m)) $info['uidvalidity'] = intval($m[1]);
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $t, $m)) $info['uidnext'] = intval($m[1]);
        }
        if (!$info['uidnext']) {
            $all = $this->uid_search('ALL');
            $info['uidnext'] = $all ? max($all) + 1 : 1;
        }
        return $info;
    }

    /** UID SEARCH → sortierte UID-Liste */
    public function uid_search($criteria) {
        $r = $this->command('UID SEARCH ' . $criteria);
        if (!$r['ok']) throw new RuntimeException('Suche fehlgeschlagen: ' . self::clip($r['text']));
        $uids = [];
        foreach ($r['untagged'] as $u) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $u['text'], $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $n) {
                    if ($n !== '' && ctype_digit($n)) $uids[] = intval($n);
                }
            }
        }
        $uids = array_values(array_unique($uids));
        sort($uids);
        return $uids;
    }

    public function fetch_size($uid) {
        $r = $this->command('UID FETCH ' . intval($uid) . ' (UID RFC822.SIZE)');
        foreach ($r['untagged'] as $u) {
            if (self::is_fetch_for($u['text'], $uid) && preg_match('/RFC822\.SIZE (\d+)/i', $u['text'], $m)) return intval($m[1]);
        }
        return 0;
    }

    /** Nur die Kopfzeilen (ohne \Seen zu setzen) */
    public function fetch_header($uid) {
        return $this->fetch_section($uid, 'BODY.PEEK[HEADER]');
    }

    /** Komplette Rohnachricht (ohne \Seen zu setzen) */
    public function fetch_raw($uid) {
        return $this->fetch_section($uid, 'BODY.PEEK[]');
    }

    private function fetch_section($uid, $item) {
        $r = $this->command('UID FETCH ' . intval($uid) . ' (UID ' . $item . ')');
        if (!$r['ok']) throw new RuntimeException('Abruf fehlgeschlagen: ' . self::clip($r['text']));
        foreach ($r['untagged'] as $u) {
            if (!self::is_fetch_for($u['text'], $uid)) continue;
            if (!empty($u['literals'])) return $u['literals'][0];
            // Sehr kleine Inhalte kommen als Quoted String
            if (preg_match('/BODY\[[^\]]*\] "((?:[^"\\\\]|\\\\.)*)"/i', $u['text'], $m)) return stripcslashes($m[1]);
        }
        return '';
    }

    public function mark_seen($uid) {
        $r = $this->command('UID STORE ' . intval($uid) . ' +FLAGS.SILENT (\\Seen)');
        return $r['ok'];
    }

    /**
     * In Ordner verschieben. Ohne MOVE-Unterstützung nur kopieren (Original bleibt
     * als gelesen im Posteingang) – es wird nie etwas gelöscht oder expunged.
     */
    public function move($uid, $folder) {
        $this->ensure_folder($folder);
        $f = self::quote(self::encode_folder($folder));
        if ($this->has_cap('MOVE')) {
            $r = $this->command('UID MOVE ' . intval($uid) . ' ' . $f);
            if ($r['ok']) return 'moved';
        }
        $r = $this->command('UID COPY ' . intval($uid) . ' ' . $f);
        $this->mark_seen($uid);
        return $r['ok'] ? 'copied' : 'failed';
    }

    public function ensure_folder($folder) {
        $enc = self::encode_folder($folder);
        $r = $this->command('LIST "" ' . self::quote($enc));
        foreach ($r['untagged'] as $u) {
            if (stripos($u['text'], '* LIST') === 0) return true;
        }
        $c = $this->command('CREATE ' . self::quote($enc));
        if ($c['ok']) $this->command('SUBSCRIBE ' . self::quote($enc));
        return $c['ok'];
    }

    public function folder_exists($folder) {
        $r = $this->command('LIST "" ' . self::quote(self::encode_folder($folder)));
        foreach ($r['untagged'] as $u) {
            if (stripos($u['text'], '* LIST') === 0) return true;
        }
        return false;
    }

    public function logout() {
        if (!$this->fp) return;
        try { $this->command('LOGOUT'); } catch (\Throwable $e) {}
        @fclose($this->fp);
        $this->fp = null;
    }

    public function __destruct() {
        if ($this->fp) @fclose($this->fp);
    }

    // ── Protokoll ─────────────────────────────────

    /**
     * Befehl senden, Antworten bis zur getaggten Statuszeile lesen.
     * $continuation: wird gesendet, sobald der Server „+“ antwortet (AUTHENTICATE).
     */
    public function command($cmd, $continuation = null) {
        if (!$this->fp) throw new RuntimeException('Keine IMAP-Verbindung.');
        $tag = 'T' . (++$this->tag);
        $this->write($tag . ' ' . $cmd . "\r\n");
        $untagged = [];
        while (true) {
            $resp = $this->read_response();
            $t = $resp['text'];
            if (strpos($t, $tag . ' ') === 0) {
                $rest = substr($t, strlen($tag) + 1);
                return [
                    'ok'       => stripos($rest, 'OK') === 0,
                    'text'     => $rest,
                    'untagged' => $untagged,
                ];
            }
            if (strpos($t, '+') === 0) {
                // Fortsetzung: Daten senden oder abbrechen
                $this->write(($continuation !== null ? $continuation : '*') . "\r\n");
                $continuation = '*';
                continue;
            }
            $untagged[] = $resp;
        }
    }

    /** Eine vollständige Antwort inkl. Literale {n} lesen */
    private function read_response() {
        $text = '';
        $literals = [];
        while (true) {
            $line = $this->read_line();
            if (preg_match('/\{(\d+)\+?\}\r\n$/', $line, $m)) {
                $text .= substr($line, 0, -2);
                $literals[] = $this->read_bytes(intval($m[1]));
                continue;
            }
            $text .= rtrim($line, "\r\n");
            break;
        }
        return ['text' => $text, 'literals' => $literals];
    }

    private function read_line() {
        $line = '';
        while (true) {
            $chunk = fgets($this->fp, 65536);
            if ($chunk === false) {
                $meta = stream_get_meta_data($this->fp);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'Zeitüberschreitung beim IMAP-Server.' : 'IMAP-Verbindung unterbrochen.');
            }
            $line .= $chunk;
            if (substr($line, -1) === "\n") return $line;
        }
    }

    private function read_bytes($n) {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->fp, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->fp);
                if (!empty($meta['timed_out']) || feof($this->fp)) throw new RuntimeException('IMAP-Verbindung beim Lesen unterbrochen.');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function write($s) {
        $len = strlen($s); $done = 0;
        while ($done < $len) {
            $w = fwrite($this->fp, substr($s, $done));
            if ($w === false || $w === 0) throw new RuntimeException('IMAP-Verbindung beim Senden unterbrochen.');
            $done += $w;
        }
    }

    private function parse_caps_from($text) {
        if (preg_match('/\[CAPABILITY ([^\]]+)\]/i', $text, $m)) {
            $this->caps = array_map('strtoupper', preg_split('/\s+/', trim($m[1])));
        }
    }

    private static function is_fetch_for($text, $uid) {
        return preg_match('/^\* \d+ FETCH /i', $text) && preg_match('/\bUID ' . intval($uid) . '\b/i', $text);
    }

    public static function quote($s) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $s) . '"';
    }

    /** Ordnernamen mit Umlauten → modifiziertes UTF-7 (RFC 3501) */
    public static function encode_folder($name) {
        $name = (string) $name;
        if (!preg_match('/[^\x20-\x7e]/', $name)) return $name;
        if (function_exists('mb_convert_encoding')) {
            $enc = @mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
            if (is_string($enc) && $enc !== '') return $enc;
        }
        return $name;
    }

    private static function clip($s) {
        $s = trim(preg_replace('/\s+/', ' ', (string) $s));
        return function_exists('mb_substr') ? mb_substr($s, 0, 200) : substr($s, 0, 200);
    }
}


/**
 * Minimaler MIME-Parser für eingehende Kunden-Mails.
 * Liefert Kopfzeilen, Text, HTML und Anhänge (bereits dekodiert, UTF-8).
 */
class TIX_Support_Mime {

    const MAX_DEPTH = 8;

    public static function parse($raw) {
        $out  = ['headers' => [], 'text' => '', 'html' => '', 'attachments' => []];
        $part = self::parse_part((string) $raw, 0);
        $out['headers'] = $part['headers'];
        self::walk($part, $out, 0);
        $out['text'] = trim($out['text']);
        $out['html'] = trim($out['html']);
        return $out;
    }

    /** Nur Kopfzeilen parsen (für Vorprüfung ohne Body-Download) */
    public static function parse_headers_only($raw) {
        list($head) = self::split_head_body((string) $raw);
        return self::parse_headers($head);
    }

    // ── Kopfzeilen ────────────────────────────────

    public static function split_head_body($raw) {
        $p1 = strpos($raw, "\r\n\r\n");
        $p2 = strpos($raw, "\n\n");
        if ($p1 === false && $p2 === false) return [$raw, ''];
        if ($p1 !== false && ($p2 === false || $p1 <= $p2)) return [substr($raw, 0, $p1), substr($raw, $p1 + 4)];
        return [substr($raw, 0, $p2), substr($raw, $p2 + 2)];
    }

    public static function parse_headers($head) {
        $head = preg_replace("/\r?\n[ \t]+/", ' ', (string) $head);
        $headers = [];
        foreach (preg_split("/\r?\n/", $head) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false || $pos === 0) continue;
            $name = strtolower(trim(substr($line, 0, $pos)));
            if ($name === '' || preg_match('/\s/', $name)) continue;
            $headers[$name][] = trim(substr($line, $pos + 1));
        }
        return $headers;
    }

    /** Roher Kopfzeilenwert (erstes Vorkommen) */
    public static function header(array $headers, $name) {
        $name = strtolower($name);
        return isset($headers[$name][0]) ? (string) $headers[$name][0] : '';
    }

    /** Kopfzeilenwert mit dekodierten Encoded-Words (=?utf-8?B?...?=) */
    public static function header_decoded(array $headers, $name) {
        return self::decode_words(self::header($headers, $name));
    }

    public static function decode_words($v) {
        $v = (string) $v;
        if (strpos($v, '=?') === false) {
            return self::to_utf8($v, '');
        }
        // Leerraum zwischen zwei Encoded-Words entfällt (RFC 2047)
        $v = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $v);
        return preg_replace_callback('/=\?([^?\s]+)\?([QqBb])\?([^?]*)\?=/', function ($m) {
            $charset = preg_replace('/\*.*$/', '', $m[1]); // RFC 2231 Sprachsuffix
            if (strtoupper($m[2]) === 'B') {
                $txt = base64_decode($m[3]);
            } else {
                $txt = quoted_printable_decode(str_replace('_', ' ', $m[3]));
            }
            return self::to_utf8((string) $txt, $charset);
        }, $v);
    }

    /** "Name" <mail@x.de> → ['email' => ..., 'name' => ...] */
    public static function parse_address($v) {
        $v = trim((string) $v);
        $email = ''; $name = '';
        if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $v, $m)) {
            $email = $m[1];
            $name  = trim(substr($v, 0, strpos($v, '<')));
        } elseif (preg_match('/([^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+)/', $v, $m)) {
            $email = $m[1];
            $name  = trim(str_replace($m[1], '', $v), " \t()");
        }
        $name = trim($name, " \t\"'");
        return ['email' => strtolower(trim($email, '.')), 'name' => $name];
    }

    /** Alle <message-id>s aus einer Kopfzeile */
    public static function message_ids($v) {
        preg_match_all('/<[^<>\s]+>/', (string) $v, $m);
        return $m[0];
    }

    /** Content-Type/Disposition → [wert, params] (inkl. RFC 2231 filename*=) */
    public static function parse_params($v) {
        $v = (string) $v;
        $parts = self::split_params($v);
        $value = strtolower(trim(array_shift($parts)));
        $params = []; $ext = [];
        foreach ($parts as $p) {
            $eq = strpos($p, '=');
            if ($eq === false) continue;
            $k = strtolower(trim(substr($p, 0, $eq)));
            $val = trim(substr($p, $eq + 1));
            if (strlen($val) >= 2 && $val[0] === '"' && substr($val, -1) === '"') {
                $val = stripcslashes(substr($val, 1, -1));
            }
            if (preg_match('/^([a-z0-9_.-]+)\*(\d+)?(\*)?$/', $k, $m)) {
                $ext[$m[1]][intval($m[2] ?? 0)] = [$val, !empty($m[3]) || (!isset($m[2]) || $m[2] === '')];
                continue;
            }
            $params[$k] = $val;
        }
        foreach ($ext as $k => $segs) {
            ksort($segs);
            $charset = ''; $buf = '';
            foreach ($segs as $i => $seg) {
                list($val, $encoded) = $seg;
                if ($i === 0 && $encoded && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $m)) {
                    $charset = $m[1];
                    $val = $m[2];
                }
                $buf .= $encoded ? rawurldecode($val) : $val;
            }
            $params[$k] = self::to_utf8($buf, $charset ?: 'UTF-8');
        }
        return [$value, $params];
    }

    private static function split_params($v) {
        $out = []; $cur = ''; $inq = false; $len = strlen($v);
        for ($i = 0; $i < $len; $i++) {
            $c = $v[$i];
            if ($c === '\\' && $inq && $i + 1 < $len) { $cur .= $c . $v[++$i]; continue; }
            if ($c === '"') $inq = !$inq;
            if ($c === ';' && !$inq) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $c;
        }
        $out[] = $cur;
        return $out;
    }

    // ── Teile ─────────────────────────────────────

    private static function parse_part($raw, $depth) {
        list($head, $body) = self::split_head_body($raw);
        $headers = self::parse_headers($head);
        list($ctype, $cparams) = self::parse_params(self::header($headers, 'content-type') ?: 'text/plain');
        if ($ctype === '') $ctype = 'text/plain';
        $part = ['headers' => $headers, 'type' => $ctype, 'params' => $cparams, 'body' => $body, 'children' => []];

        if (strpos($ctype, 'multipart/') === 0 && !empty($cparams['boundary']) && $depth < self::MAX_DEPTH) {
            $b = $cparams['boundary'];
            $segments = explode("\n--" . $b, "\n" . $body);
            array_shift($segments); // Präambel
            foreach ($segments as $seg) {
                if (strpos($seg, '--') === 0) break; // Abschluss-Boundary
                $nl = strpos($seg, "\n");
                if ($nl === false) continue;
                $seg = substr($seg, $nl + 1);
                if (substr($seg, -1) === "\r") $seg = substr($seg, 0, -1);
                $part['children'][] = self::parse_part($seg, $depth + 1);
            }
        }
        return $part;
    }

    private static function walk(array $part, array &$out, $depth) {
        if ($part['children']) {
            foreach ($part['children'] as $c) self::walk($c, $out, $depth + 1);
            return;
        }
        $h = $part['headers'];
        list($disp, $dparams) = self::parse_params(self::header($h, 'content-disposition'));
        $filename = $dparams['filename'] ?? ($part['params']['name'] ?? '');
        $filename = self::decode_words($filename);
        $data = self::decode_transfer($part['body'], self::header($h, 'content-transfer-encoding'));
        $type = $part['type'];
        if ($disp !== 'attachment' && $filename === '' && ($type === 'text/plain' || $type === 'text/html')) {
            $txt = self::to_utf8($data, $part['params']['charset'] ?? '');
            if ($type === 'text/plain') {
                $out['text'] .= ($out['text'] !== '' ? "\n" : '') . $txt;
            } else {
                $out['html'] .= ($out['html'] !== '' ? "\n" : '') . $txt;
            }
            return;
        }
        if ($type === 'message/rfc822' && $filename === '') $filename = 'nachricht.eml';
        if ($filename === '' && strpos($type, 'text/') === 0) return; // z. B. text/calendar ohne Namen
        $out['attachments'][] = [
            'name'   => $filename !== '' ? $filename : 'anhang',
            'mime'   => $type,
            'data'   => $data,
            'size'   => strlen($data),
            'inline' => ($disp === 'inline') || (self::header($h, 'content-id') !== '' && $disp !== 'attachment'),
            'cid'    => trim(self::header($h, 'content-id'), '<> '),
        ];
    }

    public static function decode_transfer($body, $cte) {
        $cte = strtolower(trim((string) $cte));
        if ($cte === 'base64') return (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body));
        if ($cte === 'quoted-printable') return quoted_printable_decode(str_replace("\r\n", "\n", $body));
        return $body;
    }

    /** Beliebigen Zeichensatz nach UTF-8 (fehlertolerant) */
    public static function to_utf8($s, $charset) {
        $s  = (string) $s;
        $cs = strtoupper(trim((string) $charset, " \t\"'"));
        if ($cs === '' || $cs === 'UTF-8' || $cs === 'UTF8' || $cs === 'US-ASCII' || $cs === 'ASCII') {
            if (self::is_utf8($s)) return $s;
            $cs = 'WINDOWS-1252';
        }
        if ($cs === 'ISO-8859-1' || $cs === 'LATIN1') $cs = 'WINDOWS-1252'; // Obermenge, häufig falsch deklariert
        if (function_exists('mb_convert_encoding')) {
            try {
                $r = @mb_convert_encoding($s, 'UTF-8', $cs);
                if (is_string($r) && $r !== '') return $r;
            } catch (\Throwable $e) {}
        }
        if (function_exists('iconv')) {
            $r = @iconv($cs, 'UTF-8//IGNORE', $s);
            if (is_string($r) && $r !== '') return $r;
        }
        return self::is_utf8($s) ? $s : (function_exists('mb_convert_encoding') ? (string) @mb_convert_encoding($s, 'UTF-8', 'UTF-8') : $s);
    }

    private static function is_utf8($s) {
        return function_exists('mb_check_encoding') ? mb_check_encoding($s, 'UTF-8') : (bool) preg_match('//u', $s);
    }
}
