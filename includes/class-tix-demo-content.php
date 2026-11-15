<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Demo_Content – Seed-Vorlagen der Beispiel-Veranstalter (1.38.669, User 06.11.): Club „Ostkai“ (Profil club, mehrere Events)
 * und Festival „Seewiese“ (Profil festival, 1 Event über 2 Tage mit Programm). Aufbau nach gut gefüllten echten Seiten, alles
 * verfremdet: fiktive Namen für Veranstalter, Events, Orte, Adressen und Künstler, eigene Texte, keine Kontaktdaten, keine Marken.
 * Bilder nur die fünf lizenzierten Adobe-Stock-Fotos aus assets/demo (einmal als Medien angelegt, Option tix_demo_images).
 * Künstler ohne Foto (Initialen-Platzhalter der Seiten), Musikdienst-Suche für fiktive Acts aus (_tix_lineup_links source none).
 */
class TIX_Demo_Content {

    const OPT_IMAGES = 'tix_demo_images';
    const IMAGES = ['publikum', 'festival', 'konzert', 'freunde', 'comedy'];
    const ADDRESS_CLUB = 'Ostkai 12, 12345 Musterstadt';
    const ADDRESS_FESTIVAL = 'Seewiese am Uferweg, 12345 Musterstadt';

    /** Medien-ID eines Demo-Fotos (legt es beim ersten Mal an) */
    public static function image($name) {
        $map = get_option(self::OPT_IMAGES, []);
        $map = is_array($map) ? $map : [];
        if (!empty($map[$name]) && get_post_type(intval($map[$name])) === 'attachment') return intval($map[$name]);
        $src = TIXOMAT_PATH . 'assets/demo/' . $name . '.jpg';
        if (!in_array($name, self::IMAGES, true) || !file_exists($src)) return 0;
        $up = wp_upload_bits('evendis-demo-' . $name . '.jpg', null, (string) file_get_contents($src));
        if (!empty($up['error'])) return 0;
        $aid = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Beispielbild ' . $name, 'post_status' => 'inherit'], $up['file']);
        if (!$aid || is_wp_error($aid)) return 0;
        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $up['file']));
        update_post_meta($aid, '_tix_demo_image', 1);
        $map[$name] = intval($aid);
        update_option(self::OPT_IMAGES, $map, false);
        return intval($aid);
    }

    private static function cat($name, $price, $qty, array $extra = []) {
        return array_merge(['name' => $name, 'price' => $price, 'qty' => $qty, 'online' => '1', 'desc' => ''], $extra);
    }

    private static function faq(array $pairs) {
        return array_map(function ($p) { return ['q' => $p[0], 'a' => $p[1]]; }, $pairs);
    }

    /** Club Ostkai: Events (key, Titel, Tage ab heute, Beginn, Kategorien, Extras) */
    public static function club_events() {
        $c = [__CLASS__, 'cat'];
        $house = [['Gibt es eine Garderobe?', 'Ja, direkt am Eingang – 2 € je Teil.'], ['Kann ich mit Karte zahlen?', 'An der Bar und an der Abendkasse geht Karte und Handy.'], ['Ab welchem Alter?', 'Wenn nicht anders angegeben ab 18 Jahren. Bitte Ausweis mitbringen.']];
        return [
            ['key' => 'live', 'title' => 'Live-Abend (Beispiel)', 'day' => 0, 'time' => '19:00', 'image' => 'konzert',
                'cats' => [call_user_func($c, TIX_Demo::DEMO_CAT, 0, 10000, ['desc' => 'Kostenlos – zum Ausprobieren des Einlasses']), call_user_func($c, 'Normal', 12, 300)],
                'excerpt' => 'Akustik-Abend mit Band und DJ – perfekt, um den Einlass live auszuprobieren.',
                'desc' => '<p>Ein entspannter Abend am Kai: erst Live-Musik, dann legt DJ Kaimauer bis Mitternacht auf. Hol dir das kostenlose Beispiel-Ticket und lass es am Einlass scannen.</p>',
                'lineup' => 'Die Kaispatzen (live)<br>DJ Kaimauer', 'faq' => $house, 'gallery' => ['konzert', 'publikum']],
            ['key' => 'vino', 'title' => 'Vinyl & Wein', 'day' => 2, 'time' => '20:00', 'image' => 'freunde',
                'cats' => [call_user_func($c, 'Eintritt', 10, 150, ['desc' => 'inkl. Probierglas'])],
                'excerpt' => 'Ausgesuchte Weine, Platten aus den 70ern und 80ern und gute Gespräche.',
                'desc' => '<p>Jeden ersten Freitag im Monat: Plattenspieler statt Playlist, drei Winzer aus der Region und kleine Teller aus der Kai-Küche.</p>',
                'lineup' => 'DJane Leuchtturm (Vinyl-Set)', 'faq' => [['Ist Essen dabei?', 'Kleine Teller gibt es an der Bar.']]],
            ['key' => 'classix', 'title' => 'Classix – Hip-Hop-Nacht', 'day' => 3, 'time' => '22:00', 'image' => 'publikum',
                'cats' => [call_user_func($c, 'Early Bird', 8, 120), call_user_func($c, 'Normal', 12, 380)],
                'excerpt' => 'Hip-Hop und R&B der 90er und 2000er auf zwei Floors.',
                'desc' => '<p>Zwei Floors, ein Gefühl: Old-School-Hip-Hop im großen Saal, R&B-Klassiker in der Kai-Lounge.</p>',
                'lineup' => 'MC Funkenflug<br>DJ Möwe<br>Rapid Retro', 'faq' => $house, 'gallery' => ['publikum', 'freunde']],
            ['key' => 'w1', 'title' => 'Neon Night', 'day' => 7, 'time' => '22:00', 'image' => 'festival',
                'cats' => [call_user_func($c, 'Early Bird', 9, 100), call_user_func($c, 'Normal', 14, 400), call_user_func($c, 'VIP', 39, 40, ['desc' => 'Eigener Bereich, ein Getränk inklusive'])],
                'excerpt' => 'Leuchtfarben, Laser und die größten Clubhits – Dresscode: Neon.',
                'desc' => '<p>Bring deine knalligsten Farben mit: Am Eingang gibt es Leuchtstifte, auf der Tanzfläche Laser und Nebel. Die Nachtfalter-Crew spielt Clubhits von gestern und heute.</p>',
                'lineup' => 'Nachtfalter-Crew<br>DJ Möwe', 'faq' => array_merge($house, [['Was ist der Dresscode?', 'Neon, Weiß oder Glitzer – je bunter, desto besser.']]), 'gallery' => ['festival', 'publikum']],
            ['key' => 'singles', 'title' => 'Herzklopfen – die Singleparty', 'day' => 8, 'time' => '22:00', 'image' => 'freunde',
                'cats' => [call_user_func($c, 'Normal', 10, 300)], 'age' => '18',
                'excerpt' => 'Ampel-Bändchen, Flirtkarten und eine Tanzfläche voller Möglichkeiten.',
                'desc' => '<p>Grün heißt Single, Gelb heißt kompliziert, Rot heißt vergeben – das Bändchen gibt es am Einlass. Dazu Flirtkarten an der Bar und die besten Lieder zum Mitsingen.</p>',
                'lineup' => 'DJ Kaimauer', 'faq' => $house],
            ['key' => 'w2', 'title' => 'Schlagerabend am Kai', 'day' => 14, 'time' => '20:00', 'image' => 'konzert',
                'cats' => [call_user_func($c, 'Vorverkauf', 13.5, 500), call_user_func($c, 'Abendkasse', 18.5, 200)],
                'excerpt' => 'Mitsingen, Schunkeln, Tanzen – die Kaispatzen spielen live.',
                'desc' => '<p>Der Abend für alle, die jede Zeile kennen: erst die Kaispatzen live, danach Schlager-Disco bis zum Morgen.</p>',
                'lineup' => 'Die Kaispatzen (live)<br>DJ Seemannsgarn', 'faq' => $house],
            ['key' => 'retro', 'title' => 'Zurück in die 2000er', 'day' => 21, 'time' => '22:00', 'image' => 'publikum',
                'cats' => [call_user_func($c, 'Normal', 11, 400)],
                'excerpt' => 'Klapphandy-Feeling: Pop, R&B und Party-Hymnen von 2000 bis 2012.',
                'desc' => '<p>Von der ersten Boyband bis zum letzten Sommerhit – Rapid Retro bringt die Lieder, die du heimlich immer noch auswendig kannst.</p>',
                'lineup' => 'Rapid Retro', 'faq' => $house],
            ['key' => 'm1', 'title' => 'Sommerfest im Hof', 'day' => 30, 'time' => '15:00', 'image' => 'festival',
                'cats' => [call_user_func($c, 'Tagesticket', 29, 1500), call_user_func($c, 'Familie (2+2)', 69, 200), call_user_func($c, 'Kinder', 9, 300)], 'age' => '0',
                'excerpt' => 'Open Air im Innenhof: Livemusik, Streetfood und Kinderprogramm.',
                'desc' => '<p>Einmal im Jahr wird der Innenhof zum Festivalgelände: drei Bands, Foodtrucks, Hüpfburg und abends DJ-Set unter Lichterketten.</p>',
                'lineup' => 'Die Kaispatzen<br>Duo Hafenblick<br>DJane Leuchtturm', 'faq' => [['Dürfen Kinder mit?', 'Ja, Kinder bis 6 Jahre kostenlos, danach mit Kinderticket.'], ['Gibt es vegetarisches Essen?', 'Ja, an mehreren Foodtrucks.']], 'gallery' => ['festival', 'konzert', 'freunde']],
            ['key' => 'past', 'title' => 'Herbst-Opening', 'day' => -10, 'time' => '21:00', 'image' => 'publikum',
                'cats' => [call_user_func($c, 'Normal', 12, 300), call_user_func($c, 'VIP', 30, 30)],
                'excerpt' => 'Saisonstart mit allen Resident-DJs.', 'desc' => '<p>Die Saison ist eröffnet – danke an alle, die dabei waren!</p>', 'lineup' => 'Nachtfalter-Crew<br>DJ Kaimauer<br>DJane Leuchtturm'],
        ];
    }

    /**
     * Openair Seewiese (Demo-Shop „Eigene Website“, 1.38.841): Events rund um das Festival – „heute Abend“ zum Scannen
     * (0-€-Beispiel-Ticket), Kino am Ufer, vergangener Saisonstart für die Statistik. Das Festival selbst kommt aus festival_event().
     */
    public static function openair_events() {
        $c = [__CLASS__, 'cat'];
        $lake = [['Wo parke ich?', 'Parkplätze an der Wiese (5 € je Tag), Shuttle ab Bahnhof Musterstadt.'], ['Was passiert bei Regen?', 'Wir spielen bei jedem Wetter, nur bei Unwetter unterbrechen wir.'], ['Darf ich Getränke mitbringen?', 'Nein, Getränke gibt es an den Ständen auf dem Gelände.']];
        return [
            ['key' => 'live', 'title' => 'Warm-up am See (Beispiel)', 'day' => 0, 'time' => '19:00', 'image' => 'freunde',
                'cats' => [call_user_func($c, TIX_Demo::DEMO_CAT, 0, 10000, ['desc' => 'Kostenlos – zum Ausprobieren des Einlasses']), call_user_func($c, 'Abendticket', 9.9, 400)],
                'excerpt' => 'Sonnenuntergang, DJ-Set und Akustik am Ufer – heute Abend, perfekt zum Ausprobieren des Einlasses.',
                'desc' => '<p>Der Abend vor dem großen Wochenende: Lichterketten am Steg, ein entspanntes DJ-Set und zwei Akustik-Sets am Lagerfeuer. Hol dir das kostenlose Beispiel-Ticket und lass es am Einlass scannen.</p>',
                'lineup' => 'DJ Seegang<br>Duo Liebeslied', 'faq' => $lake, 'gallery' => ['freunde', 'publikum']],
            ['key' => 'kino', 'title' => 'Open-Air-Kino am Ufer', 'day' => 10, 'time' => '20:30', 'image' => 'publikum',
                'cats' => [call_user_func($c, 'Liegestuhl', 12.5, 200, ['desc' => 'Mit Decke, solange der Vorrat reicht']), call_user_func($c, 'Wiese', 8, 300)],
                'excerpt' => 'Kinoabend unter freiem Himmel mit Popcorn und Blick aufs Wasser.',
                'desc' => '<p>Wenn es dunkel wird, läuft der Film auf der großen Leinwand am Steg. Vorher gibt es Livemusik und Popcorn aus der Seeküche.</p>',
                'lineup' => 'Vorprogramm: Duo Liebeslied', 'faq' => $lake],
            ['key' => 'past', 'title' => 'Saisonstart am See', 'day' => -12, 'time' => '16:00', 'image' => 'konzert',
                'cats' => [call_user_func($c, 'Eintritt', 15, 800), call_user_func($c, 'VIP-Steg', 39, 60)],
                'excerpt' => 'Der Auftakt der Openair-Saison mit drei Bands.', 'desc' => '<p>Die Saison ist eröffnet – danke an alle, die dabei waren!</p>',
                'lineup' => 'Die Uferbande<br>Kati Konfetti<br>DJ Seegang'],
        ];
    }

    /** Festival Seewiese: 1 Event, Samstag + Sonntag, zwei Bühnen, 12 fiktive Acts (einer moderiert) */
    public static function festival_event() {
        $c = [__CLASS__, 'cat'];
        $acts = [
            // [Tag 0|1, Bühne 0|1, Beginn, Ende, Act, Beschreibung]
            [0, 1, '14:00', '15:00', 'Duo Liebeslied', 'Akustik-Schlager zum Ankommen'],
            [0, 0, '15:00', '15:45', 'Kati Konfetti', 'Gute-Laune-Pop zum Mitklatschen'],
            [0, 1, '16:00', '17:00', 'DJ Seegang', 'Party-Set an der Strandbühne'],
            [0, 0, '17:00', '18:00', 'Die Uferbande', 'Die Band vom See – mit Bläsern'],
            [0, 0, '19:00', '20:00', 'Toni Tanzbein', 'Der Discofox-König'],
            [0, 0, '20:30', '22:00', 'Lena Sonnenschein', 'Headliner am Samstag'],
            [0, 0, '13:45', '14:00', 'Max Mikro', 'Moderation und Begrüßung'],
            [1, 1, '13:00', '14:00', 'Sandra Strandkorb', 'Sonntagsbrunch mit Liedern'],
            [1, 0, '14:30', '15:30', 'Paul Partyboot', 'Mitsing-Hits für alle'],
            [1, 0, '16:00', '17:00', 'Hannes Hitparade', 'Die größten Hits aus fünf Jahrzehnten'],
            [1, 1, '17:00', '18:30', 'DJ Seegang', 'Sonnenuntergangs-Set'],
            [1, 0, '19:00', '20:30', 'Die Wellenwerker', 'Abschluss-Show mit Feuerwerk'],
        ];
        return [
            'title' => 'Seewiese Festival (Beispiel)', 'day' => 60, 'days' => 2, 'time' => '13:00', 'time_end' => '22:30', 'doors' => '12:00', 'image' => 'festival',
            'cats' => [
                call_user_func($c, 'Tagesticket Samstag', 34.9, 3000), call_user_func($c, 'Tagesticket Sonntag', 29.9, 2500),
                call_user_func($c, 'Wochenende', 54.9, 4000, ['desc' => 'Beide Tage, ein Bändchen']),
                call_user_func($c, 'VIP Wochenende', 139.9, 300, ['desc' => 'Tribüne, eigener Eingang, Getränke-Flat']),
            ],
            'excerpt' => 'Zwei Tage Schlager und Party am See – zwei Bühnen, zwölf Programmpunkte.',
            'desc' => '<p>Das Seewiese Festival ist das Festival am Uferweg: Hauptbühne im großen Zelt, Strandbühne direkt am Wasser. Zwei Tage Mitsingen, Tanzen und Feiern – mit Foodcourt, Cocktailbar und Feuerwerk am Sonntag.</p>',
            'extra' => '<p><b>Anfahrt:</b> Shuttle-Busse ab Bahnhof Musterstadt alle 15 Minuten. Parkplätze an der Wiese (5 € je Tag).</p><p><b>Camping:</b> Zeltwiese direkt nebenan, Einlass ab Freitag 18 Uhr.</p>',
            'age' => '16', 'stages' => [['name' => 'Hauptbühne', 'color' => '#FC2F55'], ['name' => 'Strandbühne', 'color' => '#14B8A6']], 'acts' => $acts,
            'faq' => [
                ['Ab welchem Alter darf ich kommen?', 'Ab 16 Jahren. Unter 18 nur mit unterschriebenem Muttizettel und Ausweis.'],
                ['Gilt das Wochenendticket für beide Tage?', 'Ja, du bekommst am ersten Tag ein Bändchen, das bis Sonntag gilt.'],
                ['Darf ich Getränke mitbringen?', 'Nein, Getränke gibt es an den Bars auf dem Gelände. Leere Plastikflaschen bis 0,5 l sind erlaubt.'],
                ['Was ist, wenn es regnet?', 'Das Festival findet bei jedem Wetter statt. Nur bei Unwetter unterbrechen wir.'],
                ['Gibt es Schließfächer?', 'Ja, am Eingang – mit Handy-Ladestation.'],
                ['Kann ich bargeldlos zahlen?', 'Ja, an allen Ständen mit Karte, Handy oder Festival-Guthaben.'],
            ],
            'gallery' => ['festival', 'konzert', 'publikum', 'freunde'],
        ];
    }

    // ═══════════════ Demo A: Eigene Website mit EINEM Event (demo-shop.evendis.de, 1.38.844, User 15.11. abends) ═══════════════

    const OPENAIR_TITLE = 'Uferklang Festival 2027 (Beispiel)';

    /**
     * Termin des Festivals: Freitag bis Sonntag, der erste Freitag ab dem 8. Juli 2027 (= 9.–11.07.2027). Liegt das Wochenende
     * schon zurück (die Demo läuft länger), dasselbe Wochenende im nächsten Jahr. → [Start Y-m-d, Ende Y-m-d]
     */
    public static function openair_dates($now = null) {
        $today = wp_date('Y-m-d', $now === null ? time() : intval($now));
        for ($y = 2027; $y < 2100; $y++) {
            $d = new DateTime($y . '-07-08 12:00:00', wp_timezone());
            while ($d->format('w') !== '5') $d->modify('+1 day');
            $start = $d->format('Y-m-d');
            $end = (clone $d)->modify('+2 days')->format('Y-m-d');
            if ($end >= $today) return [$start, $end];
        }
        return ['2027-07-09', '2027-07-11'];
    }

    /**
     * Das eine Festival: Fr–So, drei Bühnen, 21 fiktive Acts mit Zeiten je Tag, drei Headliner (je Abend einer), Festival-Guide,
     * Kategorien Wochenende / Tagestickets Fr–So / VIP / Camping / Parken (1.38.847: drei Bühnen statt zwei, mehr Inhalt nach dem
     * Vorbild gut gefüllter Festival-Seiten). Acts: [Tag 0–2, Bühne 0–2, Beginn, Ende, Act, Beschreibung, Headliner, Genre].
     */
    public static function openair_festival() {
        $c = [__CLASS__, 'cat'];
        $acts = [
            [0, 0, '16:30', '16:45', 'Max Mikro', 'Eröffnung und Moderation', false, 'Moderation'],
            [0, 1, '17:00', '18:00', 'Duo Liebeslied', 'Akustik zum Ankommen an der Strandbühne', false, 'Akustik'],
            [0, 0, '18:30', '19:45', 'Die Uferbande', 'Die Band vom See – mit Bläsern', false, 'Pop'],
            [0, 2, '19:00', '21:00', 'DJane Morgentau', 'Deep House zwischen den Bäumen', false, 'House'],
            [0, 0, '21:00', '23:00', 'Nordlicht Kollektiv', 'Headliner am Freitag: Indie-Pop mit Lichtshow', true, 'Indie-Pop'],
            [0, 2, '21:15', '23:30', 'Mondsteg Soundsystem', 'Bass und Breaks bis kurz vor Mitternacht', false, 'Elektro'],
            [1, 1, '13:00', '14:00', 'Tante Polka & die Neffen', 'Blasmusik trifft Pop – zum Mitschunkeln', false, 'Blasmusik'],
            [1, 1, '14:30', '15:30', 'Kati Konfetti', 'Gute-Laune-Pop zum Mitklatschen', false, 'Pop'],
            [1, 0, '15:30', '16:30', 'Die Sandbankdiebe', 'Surf-Rock mit Sonnenbrille', false, 'Rock'],
            [1, 1, '16:00', '17:30', 'DJ Seegang', 'Party-Set direkt am Wasser', false, 'Party'],
            [1, 2, '17:00', '19:00', 'Kiefernklang Kollektiv', 'Melodic Techno im Wald', false, 'Techno'],
            [1, 0, '18:00', '19:15', 'Toni Tanzbein', 'Der Discofox-König', false, 'Discofox'],
            [1, 2, '19:30', '21:30', 'Lichtungsrauschen', 'Live-Elektronik mit Visuals', false, 'Elektro'],
            [1, 0, '20:30', '22:30', 'Lena Sonnenschein', 'Headliner am Samstag: alle Hits und eine Überraschung', true, 'Pop'],
            [2, 1, '12:00', '13:00', 'Sandra Strandkorb', 'Sonntagsbrunch mit Liedern', false, 'Akustik'],
            [2, 0, '13:30', '14:30', 'Kapelle Kompass', 'Folk-Pop zum Mitklatschen', false, 'Folk-Pop'],
            [2, 0, '15:00', '16:15', 'Paul Partyboot', 'Mitsing-Hits für alle', false, 'Party'],
            [2, 2, '16:00', '18:00', 'Waldmeister Disco', 'Disco-Edits am Nachmittag', false, 'Disco'],
            [2, 1, '17:00', '18:30', 'Hannes Hitparade', 'Die größten Hits aus fünf Jahrzehnten', false, 'Schlager'],
            [2, 0, '18:30', '19:30', 'Marlene Mitternacht', 'Chanson-Pop mit großem Orchester-Sound', false, 'Chanson'],
            [2, 0, '20:00', '22:00', 'Die Wellenwerker', 'Headliner am Sonntag: Abschluss-Show mit Feuerwerk', true, 'Pop'],
        ];
        return [
            'title' => self::OPENAIR_TITLE, 'day' => 0, 'time' => '16:00', 'time_end' => '23:30', 'doors' => '15:00', 'image' => 'festival',
            'cats' => [
                call_user_func($c, 'Wochenend-Ticket', 89, 4000, ['desc' => 'Freitag bis Sonntag, ein Bändchen für alle drei Tage']),
                call_user_func($c, 'Tagesticket Freitag', 34.9, 1500),
                call_user_func($c, 'Tagesticket Samstag', 44.9, 2000),
                call_user_func($c, 'Tagesticket Sonntag', 39.9, 1500),
                call_user_func($c, 'VIP Wochenende', 189, 300, ['desc' => 'VIP-Paket: Tribüne an der Hauptbühne, eigener Eingang, Getränke-Flat, Lounge mit Snacks, Parkplatz direkt am Gelände']),
                call_user_func($c, 'Camping-Ticket', 29, 800, ['desc' => 'Zeltplatz von Freitag 12 Uhr bis Montag 12 Uhr – nur zusammen mit einem Festival-Ticket']),
                call_user_func($c, 'Parkticket Wochenende', 15, 600, ['desc' => 'Parkplatz P1 an der Wiese, gilt alle drei Tage']),
            ],
            'excerpt' => 'Drei Tage Musik am See – drei Bühnen, 21 Acts, drei Headliner. Freitag bis Sonntag im Sommer 2027.',
            'desc' => '<p>Das Uferklang Festival ist das Sommerwochenende am Uferweg: die Hauptbühne im großen Zelt, die Strandbühne direkt am Wasser und die Waldbühne für alle, die elektronisch tanzen wollen. Drei Tage Pop, Party und Mitsingen – mit Foodcourt, Cocktailbar, Zeltwiese und Feuerwerk zum Abschluss am Sonntag.</p><p>Neu in diesem Jahr: das Familienfeld mit Kinderschminken am Samstag und Sonntag, ein Trinkwasser-Brunnen an jeder Bühne und die Silent Disco nach dem letzten Act.</p><p>Alle Namen, Acts und Orte sind erfunden – das ist ein Beispiel-Event von evendis.</p>',
            'extra' => '<p><b>Anreise mit Bus und Bahn:</b> Shuttle-Busse ab Bahnhof Musterstadt alle 15 Minuten, ab 14 Uhr bis eine Stunde nach dem letzten Act. Dein Festival-Ticket gilt im Shuttle.</p>'
                . '<p><b>Mit dem Auto:</b> Abfahrt Musterstadt-Nord, dann der Beschilderung „Uferklang“ folgen. Parkplatz P1 direkt an der Wiese (Parkticket im Vorverkauf 15 €), P2 am Sportplatz kostenlos mit 10 Minuten Fußweg.</p>'
                . '<p><b>Mit dem Rad:</b> Bewachter Fahrradparkplatz am Eingang Uferweg.</p>'
                . '<p><b>Camping:</b> Zeltwiese direkt nebenan, geöffnet von Freitag 12 Uhr bis Montag 12 Uhr. Duschen, Toiletten, Trinkwasser und ein kleiner Kiosk sind vor Ort. Pavillons bis 3 × 3 m erlaubt, offenes Feuer nicht.</p>'
                . '<p><b>Bändchen:</b> Am ersten Besuchstag gegen das Ticket tauschen – es gilt bis zum Ende deines Tickets. Bitte nicht abschneiden.</p>'
                . '<p><b>Bezahlen:</b> Auf dem ganzen Gelände bargeldlos mit Karte oder Handy.</p>'
                . '<p><b>Awareness:</b> Das Awareness-Team findest du am Infozelt mit der lila Fahne und an allen Bühnen.</p>',
            'entry_rules' => '<p>Einlass ab 15 Uhr (Samstag und Sonntag ab 12 Uhr) am Haupteingang Uferweg. Keine Glasflaschen, keine Dosen, keine Pyrotechnik, keine Drohnen. Leere Plastikflaschen bis 0,5 l und kleine Rucksäcke (A4) sind erlaubt.</p>',
            'ticket_notes' => '<p>Tagestickets gelten nur am aufgedruckten Tag. Das Wochenend-Ticket gilt von Freitag bis Sonntag. Camping- und Parkticket gelten nur zusammen mit einem Festival-Ticket.</p>',
            'dresscode' => 'Bequeme Schuhe, Sonnenschutz und eine Regenjacke für alle Fälle.',
            'specials' => '<p>Silent Disco nach dem letzten Act · Feuerwerk am Sonntag · Familienfeld mit Kinderschminken (Sa + So)</p>',
            'age' => '16', 'stages' => [['name' => 'Hauptbühne', 'color' => '#FC2F55'], ['name' => 'Strandbühne', 'color' => '#14B8A6'], ['name' => 'Waldbühne', 'color' => '#8E5CF7']], 'acts' => $acts,
            'faq' => [
                ['Ab welchem Alter darf ich kommen?', 'Ab 16 Jahren. Unter 18 nur mit unterschriebenem Muttizettel und Ausweis. Kinder bis 12 Jahre in Begleitung Erwachsener kostenlos.'],
                ['Gilt das Wochenend-Ticket für alle drei Tage?', 'Ja, du bekommst am ersten Tag ein Bändchen, das bis Sonntag gilt.'],
                ['Brauche ich für das Camping ein extra Ticket?', 'Ja, das Camping-Ticket gibt es zusätzlich zum Festival-Ticket. Es gilt von Freitag 12 Uhr bis Montag 12 Uhr.'],
                ['Was ist im VIP-Paket enthalten?', 'Tribüne an der Hauptbühne, eigener Eingang, Getränke-Flat, Lounge mit Snacks und ein Parkplatz direkt am Gelände.'],
                ['Darf ich Getränke mitbringen?', 'Nein, Getränke gibt es an den Bars auf dem Gelände. Leere Plastikflaschen bis 0,5 l sind erlaubt – Trinkwasser ist an allen Bühnen kostenlos.'],
                ['Was ist, wenn es regnet?', 'Das Festival findet bei jedem Wetter statt. Nur bei Unwetter unterbrechen wir – alle Infos kommen dann als Mitteilung aufs Handy.'],
                ['Gibt es Schließfächer?', 'Ja, am Eingang – mit Handy-Ladestation.'],
                ['Kann ich bargeldlos zahlen?', 'Ja, an allen Ständen mit Karte oder Handy.'],
                ['Ist das Gelände barrierefrei?', 'Die Wege sind befestigt, es gibt eine Rollstuhl-Plattform an der Hauptbühne und barrierefreie Toiletten. Begleitpersonen mit Merkzeichen B haben freien Eintritt.'],
                ['Darf ich mein Ticket weitergeben?', 'Ja, über „Ticket umschreiben“ in deinem Konto – kostenlos bis zum Festivalbeginn.'],
            ],
            'gallery' => ['festival', 'konzert', 'publikum', 'freunde'],
        ];
    }

    // ═══════════════ Demo B: Eigene Website mit mehreren Events (demo-club.evendis.de, 1.38.844) ═══════════════

    /**
     * Weitere Wochen des Clubs Ostkai (Wochen 5–9, je Freitag), damit das Programm über die nächsten zehn Wochen reicht –
     * zusätzlich zu club_events() (Wochen 0–4). Nur für die Demo-Instanz; der Beispiel-Club der Plattform bleibt, wie er ist.
     */
    public static function club_weekly($now = null) {
        $c = [__CLASS__, 'cat'];
        $fri = (5 - intval(wp_date('w', $now === null ? time() : intval($now))) + 7) % 7;
        $house = [['Gibt es eine Garderobe?', 'Ja, direkt am Eingang – 2 € je Teil.'], ['Ab welchem Alter?', 'Wenn nicht anders angegeben ab 18 Jahren. Bitte Ausweis mitbringen.']];
        $list = [
            ['title' => 'Kai Club Nacht', 'time' => '22:00', 'image' => 'publikum', 'cats' => [call_user_func($c, 'Early Bird', 8, 100), call_user_func($c, 'Normal', 12, 350)],
                'excerpt' => 'House und Disco auf zwei Floors – jede Woche am Kai.', 'desc' => '<p>Die Clubnacht am Kai: House im großen Saal, Disco-Klassiker in der Lounge.</p>', 'lineup' => 'DJ Möwe<br>Nachtfalter-Crew'],
            ['title' => 'Ostkai Classics', 'time' => '22:00', 'image' => 'freunde', 'cats' => [call_user_func($c, 'Normal', 11, 400)],
                'excerpt' => 'Die besten Partyhits von den 80ern bis heute.', 'desc' => '<p>Hits zum Mitsingen aus vier Jahrzehnten – gespielt von Rapid Retro.</p>', 'lineup' => 'Rapid Retro'],
            ['title' => 'Latin Night am Kai', 'time' => '21:00', 'image' => 'konzert', 'cats' => [call_user_func($c, 'Normal', 12, 300), call_user_func($c, 'Mit Tanzkurs', 18, 60, ['desc' => 'Salsa-Schnupperkurs ab 20 Uhr'])],
                'excerpt' => 'Salsa, Bachata und Reggaeton – mit Schnupperkurs zum Start.', 'desc' => '<p>Erst lernen, dann tanzen: Um 20 Uhr gibt es einen Schnupperkurs, danach Latin-Sound bis zum Morgen.</p>', 'lineup' => 'Duo Hafenblick (live)<br>DJ Kaimauer'],
            ['title' => 'Neon Night Vol. 2', 'time' => '22:00', 'image' => 'festival', 'cats' => [call_user_func($c, 'Early Bird', 9, 100), call_user_func($c, 'Normal', 14, 400), call_user_func($c, 'VIP', 39, 40, ['desc' => 'Eigener Bereich, ein Getränk inklusive'])],
                'excerpt' => 'Die Neon Night geht weiter – Leuchtfarben, Laser, Clubhits.', 'desc' => '<p>Bring deine knalligsten Farben mit – am Eingang gibt es Leuchtstifte.</p>', 'lineup' => 'Nachtfalter-Crew<br>DJ Möwe'],
            ['title' => 'Ü30 am Kai', 'time' => '21:00', 'image' => 'publikum', 'cats' => [call_user_func($c, 'Vorverkauf', 11, 400), call_user_func($c, 'Abendkasse', 14, 150)], 'age' => '30',
                'excerpt' => 'Die Party für alle ab 30 – Hits, die jeder kennt.', 'desc' => '<p>Ohne Gedränge, mit Sitzplätzen an der Bar und der besten Musik der 90er und 2000er.</p>', 'lineup' => 'DJ Seemannsgarn'],
        ];
        $out = [];
        foreach ($list as $i => $s) $out[] = $s + ['key' => 'wk' . ($i + 5), 'day' => $fri + 7 * ($i + 5), 'faq' => $house];
        return $out;
    }

    /** App-Inhalte je Profil (Form wie GET /app/config: home.tiles, home.hashtag, pages.{slug}) – auf evendis sind die echten seitenweit */
    public static function app_content($profile) {
        if ($profile === 'festival') {
            return [
                'home' => ['hashtag' => '#seewiesefestival', 'tiles' => [
                    ['label' => 'Line-up', 'icon' => 'mic', 'action' => 'page:line-up'], ['label' => 'Festival-Guide', 'icon' => 'map', 'action' => 'page:guide'],
                    ['label' => 'Tickets', 'icon' => 'ticket', 'action' => 'tab:events'], ['label' => 'FAQ', 'icon' => 'question', 'action' => 'page:faq'],
                ]],
                'pages' => [
                    'faq' => ['title' => 'Häufige Fragen', 'faq' => array_map(function ($p) { return ['q' => $p[0], 'a' => $p[1]]; }, self::festival_event()['faq'])],
                    'guide' => ['title' => 'Festival-Guide', 'html' => '<p><b>Einlass</b> ab 12 Uhr am Haupteingang Uferweg. <b>Bändchen</b> gelten bis Sonntag. <b>Awareness-Team</b> an der Infozelt-Fahne – sprich uns jederzeit an.</p>'],
                ],
            ];
        }
        return [
            'home' => ['hashtag' => '#ostkainacht', 'tiles' => [
                ['label' => 'Events', 'icon' => 'calendar', 'action' => 'tab:events'], ['label' => 'Vorverkauf', 'icon' => 'ticket', 'action' => 'presale'],
                ['label' => 'Musikwunsch', 'icon' => 'music', 'action' => 'page:musikwunsch'], ['label' => 'FAQ', 'icon' => 'question', 'action' => 'page:faq'],
            ]],
            'pages' => [
                'faq' => ['title' => 'Häufige Fragen', 'faq' => [
                    ['q' => 'Wo ist der Beispiel-Club Ostkai?', 'a' => 'Am Ostkai 12 in Musterstadt – direkt am Hafenbecken. (Fiktive Adresse, nur Demo.)'],
                    ['q' => 'Ab welchem Alter?', 'a' => 'In der Regel ab 18 Jahren, bei Ü16-Partys mit Muttizettel.'],
                    ['q' => 'Gibt es Tickets an der Abendkasse?', 'a' => 'Meistens ja – im Vorverkauf bist du aber sicher dabei und sparst.'],
                    ['q' => 'Kann ich mein Ticket weitergeben?', 'a' => 'Ja, über „Ticket umschreiben“ in der App oder per Mail an uns.'],
                ]],
                'kontakt' => ['title' => 'Kontakt', 'html' => '<p>Das ist ein Beispiel-Club von evendis – hier gibt es keinen echten Kontakt.</p>'],
            ],
        ];
    }

    // ═══════════════ Demo-Instanz: KI-Beispielbilder und mehr Inhalt (1.38.847, User 15.11. „lebendiger, wie KitchenKlub/Mallorca“) ═══════════════

    const OPT_KI = 'tix_demo_ki_images';
    /** KI-generierte Beispiel-Eventbilder (assets/demo-ki, Herkunft in HERKUNFT.txt) – nur diese auf den Demo-Instanzen (User 15.11.) */
    const KI_IMAGES = ['band', 'techno', 'hiphop', 'cocktail'];

    /**
     * Medien-ID eines KI-Beispielbilds (legt es beim ersten Mal an, Option tix_demo_ki_images, Medien mit _tix_demo_image und
     * _tix_demo_ki). Die Adobe-Stock-Fotos aus assets/demo nutzt die Demo-Instanz nicht (nur für die App lizenziert). → 0 ohne Datei
     */
    public static function ki_image($name) {
        $map = get_option(self::OPT_KI, []);
        $map = is_array($map) ? $map : [];
        if (!empty($map[$name]) && get_post_type(intval($map[$name])) === 'attachment') return intval($map[$name]);
        $src = TIXOMAT_PATH . 'assets/demo-ki/' . $name . '.jpg';
        if (!in_array($name, self::KI_IMAGES, true) || !file_exists($src)) return 0;
        $up = wp_upload_bits('evendis-beispiel-' . $name . '.jpg', null, (string) file_get_contents($src));
        if (!empty($up['error'])) return 0;
        $aid = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Beispielbild ' . $name . ' (KI-generiert)', 'post_status' => 'inherit'], $up['file']);
        if (!$aid || is_wp_error($aid)) return 0;
        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $up['file']));
        update_post_meta($aid, '_tix_demo_image', 1);
        update_post_meta($aid, '_tix_demo_ki', 1);
        $map[$name] = intval($aid);
        update_option(self::OPT_KI, $map, false);
        return intval($aid);
    }

    /** KI-Bild je Club-Abend: Live-Musik → band, Clubnächte/Techno → techno, Hip-Hop/Partyhits → hiphop, Bar/Wein/Brunch → cocktail */
    public static function club_image($key) {
        $m = ['live' => 'band', 'w2' => 'band', 'm1' => 'band', 'wk7' => 'band', 'karaoke' => 'band', 'comedy' => 'band',
            'keller' => 'techno', 'w1' => 'techno', 'wk8' => 'techno', 'wk5' => 'techno', 'past' => 'techno',
            'classix' => 'hiphop', 'u16' => 'hiphop', 'retro' => 'hiphop', 'wk6' => 'hiphop', 'badtaste' => 'hiphop', 'singles' => 'hiphop', 'wk9' => 'hiphop',
            'vino' => 'cocktail', 'afterwork' => 'cocktail', 'brunch' => 'cocktail'];
        return $m[$key] ?? 'hiphop';
    }

    /**
     * Zusätzliche Abende des Clubs Ostkai nur für die Demo-Instanz (demo-club): Ü16-Party, Karaoke, Techno, Afterwork mit freiem
     * Eintritt, Bad-Taste, Brunch, Comedy – damit Programm, Highlights und Vorverkauf so voll wirken wie bei einem echten Club.
     */
    public static function club_more($now = null) {
        $c = [__CLASS__, 'cat'];
        $w = intval(wp_date('w', $now === null ? time() : intval($now)));
        // passender Wochentag: $dow (0 = So) in Woche $week ab heute
        $on = function ($dow, $week) use ($w) { return ($dow - $w + 7) % 7 + 7 * $week; };
        $house = [['Gibt es eine Garderobe?', 'Ja, direkt am Eingang – 2 € je Teil.'], ['Kann ich mit Karte zahlen?', 'An der Bar und an der Abendkasse geht Karte und Handy.']];
        return [
            ['key' => 'u16', 'title' => 'Ü16 Freitag – Teens Night', 'day' => $on(5, 1), 'time' => '20:00', 'image' => 'freunde', 'age' => '16',
                'cats' => [call_user_func($c, 'Vorverkauf', 9, 300), call_user_func($c, 'Abendkasse', 12, 100)],
                'excerpt' => 'Die Party ab 16 – Charts, Hip-Hop und Mitsing-Hits bis 24 Uhr.',
                'desc' => '<p>Einmal im Monat gehört der Kai allen ab 16: Charts, Hip-Hop und Mitsing-Hits, alkoholfreie Cocktails an der Bar und um Mitternacht ist Schluss. Unter 18? Bring deinen Muttizettel und deinen Ausweis mit.</p>',
                'lineup' => 'DJ Möwe<br>Rapid Retro', 'faq' => array_merge($house, [['Brauche ich einen Muttizettel?', 'Ja, wenn du unter 18 bist – den Muttizettel kannst du direkt auf dieser Seite ausfüllen.']])],
            ['key' => 'karaoke', 'title' => 'Kai Karaoke', 'day' => $on(4, 0), 'time' => '20:00', 'image' => 'comedy',
                'cats' => [call_user_func($c, 'Eintritt', 6, 150, ['desc' => 'inkl. einem Lied auf der Bühne'])],
                'excerpt' => 'Mikro an, Lampenfieber aus – 3.000 Lieder zur Auswahl.',
                'desc' => '<p>Die große Liederliste liegt an der Bar, das Mikro auf der Bühne. Wer sich traut, singt – alle anderen feiern mit. Um 22 Uhr küren wir die Stimme des Abends.</p>',
                'lineup' => 'Moderation: Max Mikro', 'faq' => $house],
            ['key' => 'keller', 'title' => 'Techno im Keller', 'day' => $on(6, 1), 'time' => '23:00', 'image' => 'konzert',
                'cats' => [call_user_func($c, 'Early Bird', 10, 80), call_user_func($c, 'Normal', 15, 300)],
                'excerpt' => 'Treibender Techno bis zum Morgen – Nebel, Strobo, keine Fotos.',
                'desc' => '<p>Der Gewölbekeller unter dem Club öffnet nur ein paar Mal im Jahr: ein Floor, eine Anlage, acht Stunden Techno. Handykameras werden am Einlass abgeklebt.</p>',
                'lineup' => 'Kiefernklang Kollektiv<br>Mondsteg Soundsystem<br>DJane Morgentau', 'faq' => $house],
            ['key' => 'afterwork', 'title' => 'Afterwork am Kai', 'day' => $on(3, 1), 'time' => '18:00', 'image' => 'freunde', 'free' => 'Freier Eintritt bis 21 Uhr – einfach vorbeikommen.',
                'cats' => [],
                'excerpt' => 'Feierabend mit Blick aufs Wasser – Eintritt frei, Drinks zum halben Preis bis 19 Uhr.',
                'desc' => '<p>Der Feierabend beginnt auf der Terrasse: entspannter House, kühle Getränke und kleine Teller aus der Kai-Küche. Bis 19 Uhr alle Drinks zum halben Preis.</p>',
                'lineup' => 'DJane Leuchtturm', 'faq' => $house],
            ['key' => 'badtaste', 'title' => 'Bad Taste Party', 'day' => $on(6, 2), 'time' => '22:00', 'image' => 'publikum',
                'cats' => [call_user_func($c, 'Vorverkauf', 9, 300), call_user_func($c, 'Abendkasse', 12, 150)],
                'excerpt' => 'Je schräger das Outfit, desto besser – das schlimmste gewinnt.',
                'desc' => '<p>Hol die Leggings mit Leopardenmuster raus: Bei der Bad Taste Party gewinnt das schrägste Outfit einen Abend an der VIP-Bar. Dazu Partyhits, die du eigentlich nicht magst – aber jede Zeile kennst.</p>',
                'lineup' => 'DJ Seemannsgarn', 'faq' => array_merge($house, [['Muss ich verkleidet kommen?', 'Nein – aber mit schrägem Outfit macht es mehr Spaß, und es gibt einen Preis.']])],
            ['key' => 'brunch', 'title' => 'Sunday Beats & Brunch', 'day' => $on(0, 3), 'time' => '11:00', 'image' => 'freunde', 'age' => '0',
                'cats' => [call_user_func($c, 'Brunch-Ticket', 24, 120, ['desc' => 'Buffet und ein Heißgetränk']), call_user_func($c, 'Kinder (6–12)', 9, 40)],
                'excerpt' => 'Buffet, Kaffee und ruhige Beats am Sonntagmittag – für die ganze Familie.',
                'desc' => '<p>Sonntag am Kai: großes Frühstücksbuffet, Kaffee aus der Siebträgermaschine und dazu ein entspanntes Vinyl-Set. Kinder bis 5 Jahre essen kostenlos.</p>',
                'lineup' => 'DJane Leuchtturm (Vinyl-Set)', 'faq' => [['Gibt es vegane Gerichte?', 'Ja, das Buffet ist zur Hälfte vegan.']]],
            ['key' => 'comedy', 'title' => 'Lachen am Kai – Comedy Mixed Show', 'day' => $on(4, 3), 'time' => '20:00', 'image' => 'comedy', 'age' => '16',
                'cats' => [call_user_func($c, 'Reihe 1–5', 22, 80), call_user_func($c, 'Reihe 6–12', 18, 160)],
                'excerpt' => 'Vier Comedians, ein Abend – moderiert von Max Mikro.',
                'desc' => '<p>Vier Comedians, je 20 Minuten, und dazwischen Max Mikro: Die Mixed Show bringt neue Gesichter und bekannte Stimmen der Kleinkunst auf die Kai-Bühne.</p>',
                'lineup' => 'Max Mikro (Moderation)<br>Frieda Freiwurf<br>Olli Ohrwurm<br>Die Pointen-Piraten', 'faq' => $house],
        ];
    }

    /**
     * Zusätze je Club-Abend (Schlüssel aus club_events/club_weekly/club_more) für die Demo-Instanz: [Farbe, Kurzzeile (beide nur
     * Beschreibung, nicht angezeigt), Ende, Specials, Dresscode]. Unbekannte Schlüssel bekommen Vorgaben.
     */
    public static function club_extras() {
        return [
            'live' => ['#14B8A6', 'Band live, danach DJ bis Mitternacht', '00:00', 'Beispiel-Ticket kostenlos – zum Ausprobieren des Einlasses', ''],
            'vino' => ['#B4536A', 'Platten der 70er und 80er · drei Winzer', '01:00', 'Probierglas inklusive', 'Kein Dresscode'],
            'classix' => ['#F59E0B', 'Hip-Hop & R&B der 90er und 2000er · 2 Floors', '05:00', 'Bis 23 Uhr: Longdrinks 5 €', 'Sneaker willkommen'],
            'w1' => ['#22D3EE', 'Leuchtfarben · Laser · Clubhits', '05:00', 'Leuchtstifte gratis am Eingang', 'Neon, Weiß oder Glitzer'],
            'singles' => ['#E8445A', 'Ampel-Bändchen · Flirtkarten · Mitsing-Hits', '04:00', 'Ampel-Bändchen am Einlass', ''],
            'w2' => ['#F97316', 'Die Kaispatzen live · Schlager-Disco', '03:00', 'Mitsingen ausdrücklich erwünscht', 'Gern mit Blumenkranz'],
            'retro' => ['#8E5CF7', 'Pop, R&B und Party-Hymnen 2000–2012', '04:00', '', ''],
            'm1' => ['#1F8F5F', 'Open Air im Innenhof · Livemusik · Streetfood', '23:00', 'Hüpfburg und Kinderschminken bis 19 Uhr', ''],
            'past' => ['#64748B', 'Saisonstart mit allen Resident-DJs', '05:00', '', ''],
            'wk5' => ['#3D7BF7', 'House & Disco auf zwei Floors', '05:00', '', ''],
            'wk6' => ['#F59E0B', 'Partyhits von den 80ern bis heute', '04:00', '', ''],
            'wk7' => ['#E11D48', 'Salsa · Bachata · Reggaeton · Schnupperkurs', '03:00', 'Salsa-Schnupperkurs um 20 Uhr', ''],
            'wk8' => ['#22D3EE', 'Die Neon Night geht weiter', '05:00', 'Leuchtstifte gratis am Eingang', 'Neon, Weiß oder Glitzer'],
            'wk9' => ['#B4536A', 'Die Party ab 30 – Hits, die jeder kennt', '03:00', 'Sitzplätze an der Bar', ''],
            'u16' => ['#8E5CF7', 'Charts · Hip-Hop · Mitsing-Hits · ab 16', '00:00', 'Alkoholfreie Cocktails 4 €', ''],
            'karaoke' => ['#F472B6', '3.000 Lieder · ein Mikro · deine Bühne', '01:00', 'Stimme des Abends um 22 Uhr', ''],
            'keller' => ['#64748B', 'Acht Stunden Techno im Gewölbekeller', '07:00', 'Keine Fotos auf dem Floor', 'Schwarz'],
            'afterwork' => ['#14B8A6', 'Terrasse · House · halber Preis bis 19 Uhr', '23:00', 'Eintritt frei', ''],
            'badtaste' => ['#84CC16', 'Je schräger, desto besser', '04:00', 'Preis für das schrägste Outfit', 'Bad Taste – je schlimmer, desto besser'],
            'brunch' => ['#F59E0B', 'Buffet · Kaffee · Vinyl am Sonntagmittag', '15:00', 'Kinder bis 5 Jahre essen kostenlos', ''],
            'comedy' => ['#E8445A', 'Vier Comedians · ein Abend', '22:30', 'Freie Platzwahl in deiner Reihe', ''],
        ];
    }

    /** Tipps des Tages (Startseite, Event-Seite, Tickets) – $ev: Schlüssel ⇒ Event-ID; [Text, Name, Etikett, Plätze, Event-Schlüssel] */
    public static function tips($vorlage) {
        if ($vorlage === 'club') {
            return [
                ['Bei der Neon Night gibt es Leuchtstifte gratis am Eingang – komm in Weiß, dann leuchtest du am meisten.', 'Team Ostkai', 'Tipp des Tages', ['home_top', 'event_detail'], 'w1'],
                ['Früh da sein lohnt sich: Bis 23 Uhr kosten alle Longdrinks an der Bar nur 5 €.', 'Barteam Ostkai', 'Insider-Tipp', ['home_middle', 'tickets'], 'classix'],
                ['Afterwork am Kai: Eintritt frei, Terrasse mit Blick aufs Wasser – perfekt für den Feierabend.', 'Team Ostkai', 'Neu im Programm', ['home_bottom'], 'afterwork'],
            ];
        }
        return [
            ['Lena Sonnenschein spielt am Samstag zum ersten Mal ihr neues Album live – sei pünktlich um 20:30 Uhr an der Hauptbühne.', 'Festival-Team', 'Tipp des Tages', ['home_top', 'event_detail'], 'fest'],
            ['Camping-Tickets sind begrenzt: Wer auf der Zeltwiese schlafen will, sichert sich das Camping-Ticket am besten jetzt.', 'Festival-Team', 'Gut zu wissen', ['home_middle', 'tickets'], 'fest'],
            ['Die Waldbühne ist der Geheimtipp für den Sonnenuntergang – ab 19 Uhr wird es dort richtig schön.', 'Crew Waldbühne', 'Insider-Tipp', ['home_bottom'], ''],
        ];
    }

    /** Mitteilungen im Posteingang: [Titel, Text, Event-Schlüssel, vor Stunden] */
    public static function notifications($vorlage) {
        if ($vorlage === 'club') {
            return [
                ['Heute Abend: Live-Abend am Kai', 'Einlass ab 18 Uhr, die Kaispatzen starten um 19 Uhr. Das Beispiel-Ticket ist kostenlos.', 'live', 2],
                ['Neon Night: Early Bird fast weg', 'Nur noch wenige Early-Bird-Tickets für 9 € – danach gilt der Normalpreis.', 'w1', 26],
                ['Neu im Programm: Latin Night', 'Salsa, Bachata und Reggaeton – mit Schnupperkurs um 20 Uhr.', 'wk7', 74],
            ];
        }
        return [
            ['Timetable ist online', 'Drei Tage, drei Bühnen, 21 Acts – plane jetzt deinen Festival-Tag.', 'fest', 3],
            ['Gewinnspiel: 2 VIP-Wochenend-Tickets', 'Mitmachen lohnt sich – die Gewinner ziehen wir in drei Wochen.', 'fest', 30],
            ['Camping-Tickets im Vorverkauf', 'Die Zeltwiese öffnet am Freitag um 12 Uhr. Camping-Tickets gibt es zusätzlich zum Festival-Ticket.', 'fest', 96],
        ];
    }

    /**
     * App- und Website-Inhalte der Demo-Instanz (Form wie GET /app/config: home.tiles, home.hashtag, pages.{slug}) – reicher als
     * app_content() des Plattform-Beispiels: Kacheln wie bei echten Seiten, Ü16, Musikwunsch, Kontakt, Festival-Guide.
     */
    public static function instance_content($vorlage) {
        $p = function ($html) { return '<p>' . implode('</p><p>', (array) $html) . '</p>'; };
        $u16 = ['title' => 'Ü16-Partys', 'html' => $p(['Bei Ü16-Partys darfst du ab 16 Jahren rein. Bis 18 brauchst du eine erziehungsbeauftragte Person über 18 und den unterschriebenen Muttizettel.', 'Den Muttizettel füllst du direkt auf dieser Seite aus und speicherst ihn als PDF. Bitte bring ihn zusammen mit deinem Ausweis und einer Kopie des Ausweises deiner Eltern mit.', 'Beispieltext der evendis-Demo.'])];
        if ($vorlage === 'club') {
            return [
                'home' => ['hashtag' => '#ostkainacht', 'tiles' => [
                    ['icon' => 'calendar', 'label' => 'Events', 'action' => 'tab:events'], ['icon' => 'ticket', 'label' => 'Vorverkauf', 'action' => 'presale'],
                    ['icon' => 'heart', 'label' => 'Musikwunsch', 'action' => 'page:musikwunsch'], ['icon' => 'gift', 'label' => 'Gewinnspiele', 'action' => 'raffles'],
                    ['icon' => 'info', 'label' => 'Ü16-Partys', 'action' => 'page:u16'], ['icon' => 'doc', 'label' => 'Muttizettel', 'action' => 'muttizettel'],
                    ['icon' => 'loyalty', 'label' => 'Prämien', 'action' => 'loyalty'], ['icon' => 'ticket', 'label' => 'Meine Tickets', 'action' => 'tab:tickets'],
                    ['icon' => 'help', 'label' => 'FAQ', 'action' => 'page:faq'], ['icon' => 'chat', 'label' => 'Kontakt', 'action' => 'page:kontakt'],
                ]],
                'pages' => [
                    'faq' => ['title' => 'Häufige Fragen', 'faq' => [
                        ['q' => 'Wo ist der Demo-Club Ostkai?', 'a' => '<p>Am Ostkai 12 in Musterstadt – direkt am Hafenbecken. (Fiktive Adresse, nur Demo.)</p>'],
                        ['q' => 'Ab welchem Alter darf ich rein?', 'a' => '<p>In der Regel ab 18 Jahren. Bei Ü16-Partys ab 16 mit Muttizettel und Ausweis.</p>'],
                        ['q' => 'Gibt es Tickets an der Abendkasse?', 'a' => '<p>Meistens ja – im Vorverkauf bist du aber sicher dabei und sparst.</p>'],
                        ['q' => 'Kann ich mein Ticket weitergeben?', 'a' => '<p>Ja, über „Ticket umschreiben“ in deinem Konto.</p>'],
                        ['q' => 'Womit kann ich bezahlen?', 'a' => '<p>An Bar und Abendkasse mit Karte, Handy oder bar.</p>'],
                        ['q' => 'Gibt es eine Garderobe?', 'a' => '<p>Ja, direkt am Eingang – 2 € je Teil.</p>'],
                        ['q' => 'Wie funktionieren die Prämien?', 'a' => '<p>Bei jedem Besuch bekommst du einen Stempel. Nach 5 Stempeln ist der Eintritt frei, nach 10 gibt es ein Freigetränk.</p>'],
                        ['q' => 'Darf ich mir einen Song wünschen?', 'a' => '<p>Ja – während der Party über „Musikwunsch“ auf dieser Seite oder in der App.</p>'],
                    ]],
                    'u16' => $u16,
                    'musikwunsch' => ['title' => 'Musikwunsch', 'html' => $p(['Dein Lieblingslied fehlt noch? Während der Party kannst du dir hier einen Song wünschen – der DJ sieht deinen Wunsch sofort.', 'Musikwünsche sind ab einer Stunde vor Beginn bis zum Ende der Party offen.'])],
                    'kontakt' => ['title' => 'Kontakt', 'html' => $p(['Das ist ein Beispiel-Club der evendis-Demo – hier gibt es keinen echten Kontakt.', 'Fragen zur Demo? Schreib uns über das Support-Formular.'])],
                ],
            ];
        }
        $f = self::openair_festival();
        return [
            'home' => ['hashtag' => '#uferklang', 'tiles' => [
                ['icon' => 'mic', 'label' => 'Line-up', 'action' => 'page:line-up'], ['icon' => 'map', 'label' => 'Festival-Guide', 'action' => 'page:guide'],
                ['icon' => 'ticket', 'label' => 'Tickets', 'action' => 'tab:events'], ['icon' => 'gift', 'label' => 'Gewinnspiel', 'action' => 'raffles'],
                ['icon' => 'doc', 'label' => 'Muttizettel', 'action' => 'muttizettel'], ['icon' => 'ticket', 'label' => 'Meine Tickets', 'action' => 'tab:tickets'],
                ['icon' => 'help', 'label' => 'FAQ', 'action' => 'page:faq'], ['icon' => 'chat', 'label' => 'Kontakt', 'action' => 'page:kontakt'],
            ]],
            'pages' => [
                'faq' => ['title' => 'Häufige Fragen', 'faq' => array_map(function ($x) { return ['q' => $x[0], 'a' => '<p>' . $x[1] . '</p>']; }, $f['faq'])],
                'u16' => $u16,
                'guide' => ['title' => 'Festival-Guide', 'html' => $f['extra']],
                'kontakt' => ['title' => 'Kontakt', 'html' => $p(['Das ist ein Beispiel-Festival der evendis-Demo – hier gibt es keinen echten Kontakt.', 'Fragen zur Demo? Schreib uns über das Support-Formular.'])],
            ],
        ];
    }

    /** Awareness je Event (Modus „eigene Angaben“) */
    public static function awareness($vorlage) {
        return $vorlage === 'club'
            ? ['enabled' => 1, 'team' => 1, 'sign' => 'Gelbe Bänder am Arm', 'point' => 'Neben der Garderobe', 'codeword' => 'Frag an der Bar nach dem „Leuchtturm“.', 'drink_tests' => 1,
                'rules' => 'Respekt für alle. Kein Platz für Belästigung, Rassismus oder Gewalt – sprich uns jederzeit an.']
            : ['enabled' => 1, 'team' => 1, 'sign' => 'Lila Westen mit Herz', 'point' => 'Infozelt am Haupteingang', 'codeword' => 'Frag an jeder Bar nach dem „Leuchtturm“.', 'drink_tests' => 1,
                'rules' => 'Das Uferklang ist für alle da. Grenzen werden respektiert – das Awareness-Team ist an allen drei Bühnen unterwegs.'];
    }

    /** Fiktive Acts ohne Musikdienst-Suche (sonst fragt die Seite Apple/Deezer nach erfundenen Namen) */
    public static function no_lookup($event_id, array $names) {
        if (!class_exists('TIX_Lineup_Links')) return;
        $ov = [];
        foreach ($names as $n) $ov[TIX_Lineup_Links::norm(TIX_Lineup_Links::artist(trim((string) $n)))] = ['source' => 'none', 'spotify' => '', 'apple_music' => ''];
        update_post_meta($event_id, TIX_Lineup_Links::META, $ov);
    }

    /** Künstler-Einträge der fiktiven Acts (Art host für die Moderation), verwaltet vom Festival-Veranstalter; vorherige Demo-Acts weg */
    public static function artists($event_id, $org_id, array $names, $heads = 2, array $meta = []) { // $heads: die ersten N sind Headliner (1.38.844); $meta: Name ⇒ {kind, genres, short_bio, image} (1.38.847)
        if (!class_exists('TIX_Artists')) return 0;
        foreach (get_posts(['post_type' => TIX_Artists::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'meta_key' => '_tix_demo_artist', 'meta_value' => '1']) as $old) wp_delete_post($old, true);
        $list = [];
        foreach (array_values(array_unique($names)) as $i => $n) {
            $aid = wp_insert_post(['post_type' => TIX_Artists::CPT, 'post_status' => 'publish', 'post_title' => $n, 'post_name' => 'demo-' . sanitize_title($n)], true);
            if (is_wp_error($aid) || !$aid) continue;
            $m = (array) ($meta[$n] ?? []);
            $kind = (string) ($m['kind'] ?? '') ?: (stripos($n, 'Mikro') !== false ? 'host' : (preg_match('/^DJ(ane)? /', $n) ? 'dj' : (preg_match('/^(Die|Duo|Kapelle) |Kollektiv|Soundsystem|& die /', $n) ? 'band' : 'solo')));
            $img = intval($m['image'] ?? 0);
            update_post_meta($aid, TIX_Artists::META, ['kind' => $kind, 'genres' => (array) ($m['genres'] ?? ['Schlager', 'Party']), 'origin' => 'Musterstadt', 'short_bio' => (string) ($m['short_bio'] ?? 'Fiktiver Act des Beispiel-Festivals.'), 'bio' => '',
                'images' => $img ? ['portrait' => ['id' => $img, 'credit' => 'Beispielbild']] : [], 'links' => [], 'ids' => [], 'tours' => [], 'status' => 'public', 'verified' => false, 'managed_by' => ['type' => 'organizer', 'id' => intval($org_id)], 'duplicate_of' => 0]);
            update_post_meta($aid, TIX_Artists::META_NORM, TIX_Artists::norm($n));
            update_post_meta($aid, '_tix_demo_artist', '1');
            $list[] = ['artist_id' => intval($aid), 'role' => $i < intval($heads) ? 'headliner' : 'support', 'tour' => null, 'by_organizer' => intval($org_id), 'at' => current_time('c'), 'notified' => true];
        }
        update_post_meta($event_id, TIX_Artists::EV_META, $list);
        delete_post_meta($event_id, TIX_Artists::EV_REF);
        foreach ($list as $r) add_post_meta($event_id, TIX_Artists::EV_REF, (string) $r['artist_id']);
        return count($list);
    }

    /** Wörter, die nie in Demoinhalten stehen dürfen (Marken, geschützte Begriffe) – geprüft im Test */
    const FORBIDDEN = ['kitchenklub', 'kitchen klub', 'mallorca', 'ballermann', 'wuppertal', 'remscheid'];
}
