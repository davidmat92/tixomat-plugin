<?php
/**
 * Voraussetzungen der Testumgebung (1.38.886): Prüfungen, die eine Voraussetzung brauchen, die hier fehlt, melden
 * „skip … übersprungen: <Grund>“ statt rot zu werden. Einbinden mit require_once __DIR__ . '/inc-voraussetzungen.php';
 */
if (!function_exists('tix_t_mail_reason')) {
    /** '' = Mails kommen beim Test an; sonst Grund (Demo-Instanz verwirft Kundenmails absichtlich) */
    function tix_t_mail_reason() {
        return class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::on() ? 'Demo-Instanz verwirft Mails (tix_demo_instance=1)' : '';
    }
    /** '' = Promoter-Modul an; sonst Grund (Provisionen werden nur mit Modul berechnet) */
    function tix_t_promoter_reason() {
        return function_exists('tix_get_settings') && tix_get_settings('promoter_enabled') ? '' : 'Promoter-Modul aus (promoter_enabled)';
    }
    function tix_t_skip($label, $reason) {
        $GLOBALS['t_skipped'] = intval($GLOBALS['t_skipped'] ?? 0) + 1;
        $GLOBALS['t_skip_reasons'][$reason] = true;
        echo "skip  $label – übersprungen: $reason\n";
    }
    /** Schluss-Zeile ergänzen: „, 3 übersprungen (Grund)“ */
    function tix_t_skip_suffix() {
        $n = intval($GLOBALS['t_skipped'] ?? 0);
        return $n ? ', ' . $n . ' übersprungen (' . implode('; ', array_keys((array) ($GLOBALS['t_skip_reasons'] ?? []))) . ')' : '';
    }
}
