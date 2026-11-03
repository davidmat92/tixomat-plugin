<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Emoji – Emojis in Titeln und Texten, aber nie in Adressen und nie als „?“ in WinAnsi-PDFs (1.38.609).
 * Das Studio erlaubt Emojis in Event-Titel, Kurztext und Künstlernamen (Emoji-Auswahl wie am Handy).
 *  - Slug: WordPress würde Emojis prozent-kodieren („%f0%9f%8e%89-party“). Der Filter läuft vor
 *    sanitize_title_with_dashes und nimmt sie heraus; bleibt nichts übrig, greift der Rückfall (Beitrags-ID).
 *  - PDF/CSV in CP1252 (TIX_PDFA, TIX_Simple_PDF, DATEV-Export): iconv //TRANSLIT macht aus Emojis „?“ –
 *    vorher TIX_Emoji::strip() aufrufen.
 */
class TIX_Emoji {

    const RE = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{1F1E6}-\x{1F1FF}\x{E0020}-\x{E007F}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{3030}\x{303D}\x{3297}\x{3299}]/u';

    public static function init() {
        add_filter('sanitize_title', [__CLASS__, 'slug'], 9, 3);
    }

    /** Emojis entfernen, doppelte Leerzeichen zusammenziehen (Text bleibt sonst unverändert). */
    public static function strip($s) {
        if (!is_string($s) || $s === '' || !preg_match('/[^\x00-\x7F]/', $s)) return $s;
        $t = preg_replace(self::RE, '', $s);
        return $t === null || $t === $s ? $s : trim(preg_replace('/ {2,}/u', ' ', $t));
    }

    /** sanitize_title-Filter: auch &#x1f389; (wp_encode_emoji auf utf8-Tabellen) */
    public static function slug($title, $raw = '', $context = 'display') {
        if (!is_string($title) || $title === '' || (!preg_match('/[^\x00-\x7F]/', $title) && strpos($title, '&#') === false)) return $title;
        $t = self::strip(html_entity_decode($title, ENT_QUOTES, 'UTF-8'));
        return $t === html_entity_decode($title, ENT_QUOTES, 'UTF-8') ? $title : $t;
    }
}

TIX_Emoji::init();
