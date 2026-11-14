<?php
/**
 * A3 Barrierefreiheit Ticket-PDF und Mails (1.38.814): getaggtes PDF mit Textebene, Sprache, Titel, Alternativtext;
 * Mail-Bausteine mit QR-Alt, Emoji ausgeblendet, Bild-Alt, Kontrast der Markenfarbe.
 *   wp eval-file …/test-a11y-tickets-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !method_exists('TIX_Ticket_Template', 'a11y_for') || !method_exists('TIX_Mail_Layout', 'accent_on_light')) { echo "Tixomat ab 1.38.814 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$img = imagecreatetruecolor(400, 560); ob_start(); imagejpeg($img, null, 80); $jpeg = ob_get_clean(); imagedestroy($img);
$d = ['event_name' => 'Ü30 Party', 'event_date' => 'Sa, 12.12.2026', 'event_time' => '21:00 Uhr', 'event_location' => 'Halle', 'cat_name' => 'VIP', 'seat' => 'Reihe 3, Platz 7', 'owner_name' => 'Jörg Müller', 'ticket_code' => 'ABC-123'];
$a = TIX_Ticket_Template::a11y_for($d);
$pdf = TIX_Ticket_Template::create_minimal_pdf($jpeg, 400, 560, $a);
$eq('PDF: Sprache, getaggt, Strukturbaum, Abbildung mit Alt', [true, true, true, true], [strpos($pdf, '/Lang (de-DE)') !== false, strpos($pdf, '/MarkInfo << /Marked true >>') !== false, strpos($pdf, '/StructTreeRoot') !== false, (bool) preg_match('#/S /Figure .*?/Alt <FEFF#s', $pdf)]);
$eq('PDF: unsichtbare Textebene mit Ticket-Nr. und Umlauten (WinAnsi)', [true, true, true], [strpos($pdf, '3 Tr') !== false, strpos($pdf, '(Ticket-Nr.: ABC-123)') !== false, strpos($pdf, "M\xFCller") !== false]);
$eq('PDF: Titel im Info-Wörterbuch, Fenster zeigt Titel', [true, true], [strpos($pdf, '/Title <FEFF') !== false, strpos($pdf, '/DisplayDocTitle true') !== false]);
$eq('Alternativtext nennt Event, Ticketart, Platz, Nummer', true, strpos($a['alt'], 'Ü30 Party') !== false && strpos($a['alt'], 'VIP') !== false && strpos($a['alt'], 'Platz Reihe 3') !== false && strpos($a['alt'], 'ABC-123') !== false);
$plain = TIX_Ticket_Template::create_minimal_pdf($jpeg, 400, 560);
$eq('Ohne Angaben: trotzdem Sprache und Titel „Ticket“', [true, true], [strpos($plain, '/Lang (de-DE)') !== false, strpos($plain, '/Title <FEFF005400690063006B00650074>') !== false]);
$eq('Klammern in Texten maskiert', true, strpos(TIX_Ticket_Template::create_minimal_pdf($jpeg, 400, 560, ['lines' => ['A (B) C\\D']]), '(A \\(B\\) C\\\\D)') !== false);
// Mails
$html = TIX_Mail_Layout::render(['title' => 'Deine Tickets', 'blocks' => [
    ['type' => 'event', 'title' => 'Ü30 Party', 'date' => '12.12.', 'location' => 'Halle', 'image' => 'https://example.test/a.jpg'],
    ['type' => 'ticket', 'qr' => 'https://example.test/qr.png', 'title' => 'VIP', 'code' => 'ABC-123'],
    ['type' => 'button', 'label' => 'Tickets öffnen', 'url' => 'https://example.test/']]]);
$eq('Mail: QR-Alt mit Ticket-Nr., Emoji ausgeblendet, Bild-Alt', [true, true, true], [strpos($html, 'alt="QR-Code zum Einlass, Ticket ABC-123"') !== false, strpos($html, '<span aria-hidden="true">📅 </span>') !== false, strpos($html, 'alt="Bild zum Event Ü30 Party"') !== false]);
$eq('Mail: Knopf 19 px (große Schrift), Fußzeile 13 px', [true, true], [strpos($html, 'font-size:19px') !== false, strpos($html, 'class="tm-foot" style="padding:20px 24px 28px;text-align:center;font-size:13px') !== false]);
$okc = true;
foreach (['#F5C400', '#00A3E0', '#22C55E', '#FC2F55', '#E6007E', '#123456'] as $h) if (TIX_Mail_Layout::contrast(TIX_Mail_Layout::accent_on_light($h), '#FFFFFF') < 4.5) $okc = false;
$eq('Akzent als Schrift auf Weiß immer ≥ 4,5:1', true, $okc);
$okb = true;
foreach (['#F5C400', '#00A3E0', '#22C55E', '#FC2F55', '#E6007E', '#123456', '#FFFFFF', '#000000'] as $h) if (TIX_Mail_Layout::contrast($h, TIX_Mail_Layout::on_color($h)) < 3.0) $okb = false;
$eq('Knopfschrift immer ≥ 3:1 (große Schrift), evendis-Rot bleibt weiß', [true, '#FFFFFF', '#111111'], [$okb, TIX_Mail_Layout::on_color('#FC2F55'), TIX_Mail_Layout::on_color('#F5C400')]);
$eq('Unbekanntes Farbformat → Weiß wie bisher', '#FFFFFF', TIX_Mail_Layout::on_color('rgb(1,2,3)'));
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
