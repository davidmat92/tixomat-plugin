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

    /** Fiktive Acts ohne Musikdienst-Suche (sonst fragt die Seite Apple/Deezer nach erfundenen Namen) */
    public static function no_lookup($event_id, array $names) {
        if (!class_exists('TIX_Lineup_Links')) return;
        $ov = [];
        foreach ($names as $n) $ov[TIX_Lineup_Links::norm(TIX_Lineup_Links::artist(trim((string) $n)))] = ['source' => 'none', 'spotify' => '', 'apple_music' => ''];
        update_post_meta($event_id, TIX_Lineup_Links::META, $ov);
    }

    /** Künstler-Einträge der fiktiven Acts (Art host für die Moderation), verwaltet vom Festival-Veranstalter; vorherige Demo-Acts weg */
    public static function artists($event_id, $org_id, array $names) {
        if (!class_exists('TIX_Artists')) return 0;
        foreach (get_posts(['post_type' => TIX_Artists::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'meta_key' => '_tix_demo_artist', 'meta_value' => '1']) as $old) wp_delete_post($old, true);
        $list = [];
        foreach (array_values(array_unique($names)) as $i => $n) {
            $aid = wp_insert_post(['post_type' => TIX_Artists::CPT, 'post_status' => 'publish', 'post_title' => $n, 'post_name' => 'demo-' . sanitize_title($n)], true);
            if (is_wp_error($aid) || !$aid) continue;
            $kind = stripos($n, 'Mikro') !== false ? 'host' : (stripos($n, 'DJ ') === 0 ? 'dj' : (preg_match('/^(Die|Duo) /', $n) ? 'band' : 'solo'));
            update_post_meta($aid, TIX_Artists::META, ['kind' => $kind, 'genres' => ['Schlager', 'Party'], 'origin' => 'Musterstadt', 'short_bio' => 'Fiktiver Act des Beispiel-Festivals.', 'bio' => '',
                'images' => [], 'links' => [], 'ids' => [], 'tours' => [], 'status' => 'public', 'verified' => false, 'managed_by' => ['type' => 'organizer', 'id' => intval($org_id)], 'duplicate_of' => 0]);
            update_post_meta($aid, TIX_Artists::META_NORM, TIX_Artists::norm($n));
            update_post_meta($aid, '_tix_demo_artist', '1');
            $list[] = ['artist_id' => intval($aid), 'role' => $i < 2 ? 'headliner' : 'support', 'tour' => null, 'by_organizer' => intval($org_id), 'at' => current_time('c'), 'notified' => true];
        }
        update_post_meta($event_id, TIX_Artists::EV_META, $list);
        delete_post_meta($event_id, TIX_Artists::EV_REF);
        foreach ($list as $r) add_post_meta($event_id, TIX_Artists::EV_REF, (string) $r['artist_id']);
        return count($list);
    }

    /** Wörter, die nie in Demoinhalten stehen dürfen (Marken, geschützte Begriffe) – geprüft im Test */
    const FORBIDDEN = ['kitchenklub', 'kitchen klub', 'mallorca', 'ballermann', 'wuppertal', 'remscheid'];
}
