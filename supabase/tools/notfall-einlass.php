<?php
/**
 * Notfall-Einlass aus dem Datenspiegel – läuft ohne WordPress.
 *
 * Fällt eine Seite aus, holt dieses Skript die Ticket-Liste eines Events direkt aus Supabase
 * und schreibt eine CSV und eine HTML-Datei, die offline im Browser läuft: Suche nach Code
 * oder Name, Haken „eingelassen“ (merkt sich das Gerät), Zähler. Nur lesend; nichts geht
 * zurück nach WordPress oder Supabase.
 *
 *   php notfall-einlass.php --url=https://<projekt>.supabase.co --key-file=~/.private_keys/supabase_spiegel.txt \
 *       --site=kitchenklub.de [--date=2026-10-17]          → Events des Tages (Standard: heute)
 *   php notfall-einlass.php … --site=kitchenklub.de --event=123 [--out=.]   → CSV + HTML schreiben
 *
 * Der Schlüssel kommt aus --key-file oder der Umgebungsvariable MIRROR_KEY und wird nie ausgegeben.
 */

if (PHP_SAPI !== 'cli') exit(1);

$opt = getopt('', ['url:', 'key-file:', 'site:', 'event:', 'date:', 'out:', 'help']);
if (isset($opt['help']) || empty($opt['url']) || empty($opt['site'])) {
    fwrite(STDERR, "Aufruf: php notfall-einlass.php --url=… --key-file=… --site=… [--event=ID | --date=JJJJ-MM-TT] [--out=Ordner]\n");
    exit(2);
}
$url  = rtrim($opt['url'], '/');
$key  = getenv('MIRROR_KEY') ?: '';
if (!empty($opt['key-file'])) {
    $f = preg_replace('/^~/', (string) getenv('HOME'), $opt['key-file']);
    $key = is_readable($f) ? trim((string) file_get_contents($f)) : '';
}
if ($key === '') { fwrite(STDERR, "Kein Schlüssel (--key-file oder MIRROR_KEY).\n"); exit(2); }
$site = $opt['site'];
$tz   = new DateTimeZone('Europe/Berlin');

/** GET auf PostgREST, seitenweise (Supabase liefert höchstens 1000 Zeilen je Abruf). */
function mirror_get($url, $key, $table, array $q) {
    $rows = [];
    for ($offset = 0; ; $offset += 1000) {
        $qs = http_build_query(array_merge($q, ['limit' => 1000, 'offset' => $offset]), '', '&', PHP_QUERY_RFC3986);
        $h = ['apikey: ' . $key, 'Accept: application/json'];
        if (strpos($key, 'sb_') !== 0) $h[] = 'Authorization: Bearer ' . $key;
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $h), 'timeout' => 30, 'ignore_errors' => true]]);
        $body = @file_get_contents($url . '/rest/v1/' . $table . '?' . $qs, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) $status = (int) $m[1];
        if ($body === false || $status < 200 || $status >= 300) {
            $msg = is_string($body) ? substr(str_replace($key, '***', $body), 0, 300) : 'keine Antwort';
            fwrite(STDERR, "Fehler beim Lesen von {$table} (HTTP {$status}): {$msg}\n");
            exit(1);
        }
        $chunk = json_decode($body, true) ?: [];
        $rows = array_merge($rows, $chunk);
        if (count($chunk) < 1000) return $rows;
    }
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// ── Ohne Event: Events des Tages auflisten ──
if (empty($opt['event'])) {
    $day = !empty($opt['date']) ? $opt['date'] : (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $from = (new DateTimeImmutable($day . ' 00:00', $tz))->modify('-6 hours');
    $to   = (new DateTimeImmutable($day . ' 00:00', $tz))->modify('+30 hours');
    $events = mirror_get($url, $key, 'events', [
        'site' => 'eq.' . $site, 'select' => 'id,title,starts_at,status,location_name',
        'and' => '(starts_at.gte.' . $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') . ',starts_at.lt.' . $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') . ')',
        'order' => 'starts_at.asc',
    ]);
    if (!$events) { echo "Keine Events am {$day} für {$site} im Spiegel.\n"; exit(0); }
    echo "Events am {$day} ({$site}):\n";
    foreach ($events as $e) {
        $t = $e['starts_at'] ? (new DateTimeImmutable($e['starts_at']))->setTimezone($tz)->format('d.m. H:i') : '?';
        echo sprintf("  --event=%-8s %s  %s%s\n", $e['id'], $t, $e['title'], $e['location_name'] ? ' · ' . $e['location_name'] : '');
    }
    exit(0);
}

// ── Mit Event: Liste schreiben ──
$eid = (int) $opt['event'];
$ev = mirror_get($url, $key, 'events', ['site' => 'eq.' . $site, 'id' => 'eq.' . $eid, 'select' => 'id,title,starts_at,location_name']);
if (!$ev) { fwrite(STDERR, "Event {$eid} nicht im Spiegel von {$site}.\n"); exit(1); }
$ev = $ev[0];
$tickets = mirror_get($url, $key, 'tickets', [
    'site' => 'eq.' . $site, 'event_id' => 'eq.' . $eid,
    'select' => 'id,code,status,post_status,owner_name,assigned_name,owner_email,cat_name,seat_id,checked_in,checkin_at',
    'order' => 'id.asc',
]);
$valid = [];
foreach ($tickets as $t) {
    if (in_array($t['post_status'], ['cancelled', 'trash'], true) || in_array($t['status'], ['cancelled', 'transferred'], true)) continue;
    $t['name'] = trim($t['assigned_name'] ?: $t['owner_name']);
    $valid[] = $t;
}
usort($valid, function ($a, $b) { return strcasecmp($a['name'], $b['name']) ?: strcmp($a['code'], $b['code']); });

$out = rtrim($opt['out'] ?? '.', '/');
$base = $out . '/notfall-' . preg_replace('/[^a-z0-9.-]+/i', '-', $site) . '-' . $eid;
$fh = fopen($base . '.csv', 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['Code', 'Name', 'Kategorie', 'Platz', 'Status', 'Schon eingecheckt (Stand Spiegel)'], ';');
foreach ($valid as $t) fputcsv($fh, [$t['code'], $t['name'], $t['cat_name'], $t['seat_id'], $t['status'], $t['checked_in'] ? 'ja' : 'nein'], ';');
fclose($fh);

$when = $ev['starts_at'] ? (new DateTimeImmutable($ev['starts_at']))->setTimezone($tz)->format('d.m.Y H:i') : '';
$stand = (new DateTimeImmutable('now', $tz))->format('d.m.Y H:i');
$data = json_encode(array_map(function ($t) {
    return ['c' => $t['code'], 'n' => $t['name'], 'k' => $t['cat_name'], 'p' => $t['seat_id'], 'i' => (bool) $t['checked_in']];
}, $valid), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$storage = json_encode('notfall-' . $site . '-' . $eid);
$html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
. '<title>Notfall-Einlass ' . h($ev['title']) . '</title><style>'
. 'body{font:16px/1.4 system-ui,sans-serif;margin:0;background:#fff;color:#111}header{position:sticky;top:0;background:#111;color:#fff;padding:12px 16px}'
. 'h1{font-size:18px;margin:0 0 4px}small{opacity:.8}input{width:100%;box-sizing:border-box;font-size:20px;padding:10px;margin-top:8px;border-radius:8px;border:0}'
. 'ul{list-style:none;margin:0;padding:0}li{display:flex;gap:12px;align-items:center;padding:10px 16px;border-bottom:1px solid #ddd}'
. 'li.in{background:#e7f6ec}li.pre{background:#fff7e0}code{font-size:15px}.n{flex:1}.k{color:#555;font-size:14px}button{font-size:16px;padding:8px 12px;border-radius:8px;border:1px solid #999;background:#fff}'
. 'li.in button{background:#15803d;color:#fff;border-color:#15803d}</style></head><body>'
. '<header><h1>' . h($ev['title']) . '</h1><small>' . h($when) . ($ev['location_name'] ? ' · ' . h($ev['location_name']) : '') . ' · Stand Spiegel ' . h($stand)
. ' · <span id="cnt"></span></small><input id="q" placeholder="Code oder Name suchen" autocomplete="off" autofocus></header><ul id="l"></ul>'
. '<script>const T=' . $data . ';const K=' . $storage . ';let S={};try{S=JSON.parse(localStorage.getItem(K)||"{}")}catch(e){}'
. 'const l=document.getElementById("l"),q=document.getElementById("q"),cnt=document.getElementById("cnt");'
. 'function save(){try{localStorage.setItem(K,JSON.stringify(S))}catch(e){}}'
. 'function draw(){const s=q.value.trim().toLowerCase();l.innerHTML="";let n=0;for(const t of T){if(S[t.c])n++;'
. 'if(s&&!(t.c.toLowerCase().includes(s)||t.n.toLowerCase().includes(s)))continue;const li=document.createElement("li");'
. 'li.className=S[t.c]?"in":(t.i?"pre":"");li.innerHTML="<div class=n><b></b><div class=k></div></div><code></code><button></button>";'
. 'li.querySelector("b").textContent=t.n||"(ohne Namen)";li.querySelector(".k").textContent=[t.k,t.p,t.i?"laut Spiegel schon eingecheckt":""].filter(Boolean).join(" · ");'
. 'li.querySelector("code").textContent=t.c;const b=li.querySelector("button");b.textContent=S[t.c]?"drin ✓":"einlassen";'
. 'b.onclick=()=>{if(S[t.c]){if(!confirm("Einlass zurücknehmen?"))return;delete S[t.c]}else{if(t.i&&!confirm("Laut Spiegel schon eingecheckt. Trotzdem einlassen?"))return;S[t.c]=Date.now()}save();draw()};l.appendChild(li)}'
. 'cnt.textContent=n+" von "+T.length+" auf diesem Gerät eingelassen"}q.oninput=draw;draw();</script></body></html>';
file_put_contents($base . '.html', $html);

echo "Event {$eid}: " . count($valid) . " gültige Tickets (" . count($tickets) . " gesamt)\n";
echo "  {$base}.csv\n  {$base}.html\n";
