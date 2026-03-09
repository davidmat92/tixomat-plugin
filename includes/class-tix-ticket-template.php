<?php
/**
 * Tixomat – Ticket Template System
 *
 * Visueller Editor + GD-basiertes Rendering für benutzerdefinierte Ticket-Vorlagen.
 * Unterstützt globales Template (Settings) und per-Event Override (Metabox).
 *
 * @since 1.20.0
 */
if (!defined('ABSPATH')) exit;

class TIX_Ticket_Template {

    // ══════════════════════════════════════════════
    // INIT
    // ══════════════════════════════════════════════

    public static function init() {
        // AJAX: Vorschau generieren (Admin)
        add_action('wp_ajax_tix_template_preview', [__CLASS__, 'ajax_preview']);
    }

    // ══════════════════════════════════════════════
    // FELD-DEFINITIONEN
    // ══════════════════════════════════════════════

    /**
     * Alle verfügbaren Template-Felder mit Labels + Typ
     */
    public static function field_definitions() {
        return [
            'event_name'    => ['label' => 'Event-Name',         'type' => 'text'],
            'event_date'    => ['label' => 'Datum',              'type' => 'text'],
            'event_time'    => ['label' => 'Beginn',             'type' => 'text'],
            'event_doors'   => ['label' => 'Einlass',            'type' => 'text'],
            'event_location'=> ['label' => 'Veranstaltungsort',  'type' => 'text'],
            'event_address' => ['label' => 'Adresse',            'type' => 'text'],
            'cat_name'      => ['label' => 'Ticket-Kategorie',   'type' => 'text'],
            'price'         => ['label' => 'Preis',              'type' => 'text'],
            'owner_name'    => ['label' => 'Inhaber',            'type' => 'text'],
            'owner_email'   => ['label' => 'E-Mail',             'type' => 'text'],
            'ticket_code'   => ['label' => 'Ticket-Code',        'type' => 'text'],
            'order_id'      => ['label' => 'Bestellnummer',      'type' => 'text'],
            'seat'          => ['label' => 'Sitzplatz',           'type' => 'text'],
            'qr_code'       => ['label' => 'QR-Code',            'type' => 'image'],
            'barcode'       => ['label' => 'Strichcode',         'type' => 'image'],
            'custom_text'   => ['label' => 'Eigener Text',       'type' => 'text'],
        ];
    }

    // ══════════════════════════════════════════════
    // DEFAULT-FELDER
    // ══════════════════════════════════════════════

    /**
     * Standard-Feld-Konfiguration basierend auf Canvas-Größe
     */
    public static function default_fields($w = 2480, $h = 3508) {
        $fields = [];
        $y = intval($h * 0.08);
        $line_h = intval($h * 0.04);

        foreach (self::field_definitions() as $key => $def) {
            $field = [
                'enabled'     => in_array($key, ['event_name', 'event_date', 'event_time', 'event_location', 'cat_name', 'ticket_code', 'qr_code']),
                'x'           => intval($w * 0.06),
                'y'           => $y,
                'width'       => intval($w * 0.55),
                'height'      => $line_h,
                'font_size'   => $key === 'event_name' ? 48 : 28,
                'font_family' => $key === 'ticket_code' ? 'monospace' : 'sans-serif',
                'font_weight' => $key === 'event_name' ? 'bold' : 'normal',
                'color'       => '#ffffff',
                'alignment'   => 'left',
            ];

            // QR-Code: links, mittig-unten positionieren
            if ($key === 'qr_code') {
                $field['x']      = intval($w * 0.06);
                $field['y']      = intval($h * 0.55);
                $field['width']  = intval($w * 0.18);
                $field['height'] = intval($w * 0.18);
            }

            // Barcode: unterhalb des QR-Codes
            if ($key === 'barcode') {
                $field['enabled'] = false;
                $field['x']      = intval($w * 0.06);
                $field['y']      = intval($h * 0.55) + intval($w * 0.18) + intval($h * 0.02);
                $field['width']  = intval($w * 0.22);
                $field['height'] = intval($w * 0.06);
            }

            // Eigener Text hat zusätzliches text-Feld
            if ($key === 'custom_text') {
                $field['text'] = '';
            }

            $fields[$key] = $field;
            if ($key !== 'qr_code' && $key !== 'barcode') {
                $y += $line_h + intval($h * 0.01);
            }
        }

        return $fields;
    }

    /**
     * Leere Default-Config (kein Template)
     */
    public static function default_config() {
        return [
            'template_image_id' => 0,
            'canvas_width'      => 2480,
            'canvas_height'     => 3508,
            'fields'            => self::default_fields(),
        ];
    }

    // ══════════════════════════════════════════════
    // CONFIG LADEN
    // ══════════════════════════════════════════════

    /**
     * Globales Template aus tix_settings laden
     */
    public static function get_global_config() {
        $settings = get_option('tix_settings', []);
        $json = $settings['ticket_template'] ?? '';
        if (empty($json)) return null;

        $config = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($config)) return null;

        // Config vollständig sanitizen
        $config = self::sanitize_config($config);
        if (!$config['template_image_id']) return null;

        return $config;
    }

    /**
     * Event-spezifisches Template laden
     */
    public static function get_event_config($event_id) {
        $mode = get_post_meta($event_id, '_tix_ticket_template_mode', true);
        if ($mode !== 'custom') return null;

        $json = get_post_meta($event_id, '_tix_ticket_template', true);
        if (empty($json)) return null;

        $config = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($config)) return null;

        $config = self::sanitize_config($config);
        if (!$config['template_image_id']) return null;

        return $config;
    }

    /**
     * Effektives Template für ein Event (Template CPT > Custom > Global > null)
     */
    public static function get_effective_config($event_id) {
        $mode = get_post_meta($event_id, '_tix_ticket_template_mode', true);

        // Explizit deaktiviert
        if ($mode === 'none') return null;

        // CPT-Vorlage
        if ($mode === 'template') {
            $tpl_id = intval(get_post_meta($event_id, '_tix_ticket_template_id', true));
            if ($tpl_id && class_exists('TIX_Ticket_Template_CPT')) {
                $config = TIX_Ticket_Template_CPT::get_config($tpl_id);
                if ($config) return $config;
            }
        }

        // Custom-Template
        if ($mode === 'custom') {
            $config = self::get_event_config($event_id);
            if ($config) return $config;
        }

        // Global-Template (oder Default-Modus)
        return self::get_global_config();
    }

    // ══════════════════════════════════════════════
    // CONFIG SANITIZE
    // ══════════════════════════════════════════════

    /**
     * Template-Config validieren und bereinigen
     */
    public static function sanitize_config($raw) {
        if (!is_array($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) return self::default_config();

        $config = [
            'template_image_id' => absint($raw['template_image_id'] ?? 0),
            'canvas_width'      => max(100, min(10000, absint($raw['canvas_width'] ?? 2480))),
            'canvas_height'     => max(100, min(15000, absint($raw['canvas_height'] ?? 3508))),
            'fields'            => [],
        ];

        $defs = self::field_definitions();
        $raw_fields = $raw['fields'] ?? [];

        foreach ($defs as $key => $def) {
            $f = $raw_fields[$key] ?? [];
            $field = [
                'enabled'        => !empty($f['enabled']),
                'x'              => max(0, min($config['canvas_width'], intval($f['x'] ?? 0))),
                'y'              => max(0, min($config['canvas_height'], intval($f['y'] ?? 0))),
                'width'          => max(10, min($config['canvas_width'], intval($f['width'] ?? 400))),
                'height'         => max(10, min($config['canvas_height'], intval($f['height'] ?? 60))),
                'font_size'      => max(8, min(200, intval($f['font_size'] ?? 28))),
                'font_family'    => in_array($f['font_family'] ?? '', ['sans-serif', 'serif', 'monospace']) ? $f['font_family'] : 'sans-serif',
                'font_weight'    => in_array($f['font_weight'] ?? '', ['normal', 'bold']) ? $f['font_weight'] : 'normal',
                'color'          => self::sanitize_hex($f['color'] ?? '#ffffff'),
                'alignment'      => in_array($f['alignment'] ?? '', ['left', 'center', 'right']) ? $f['alignment'] : 'left',
                // Erweiterte Properties
                'letter_spacing' => max(-5, min(50, intval($f['letter_spacing'] ?? 0))),
                'line_height'    => max(0.8, min(3.0, round(floatval($f['line_height'] ?? 1.4), 1))),
                'rotation'       => max(-180, min(180, intval($f['rotation'] ?? 0))),
                'opacity'        => max(0.0, min(1.0, round(floatval($f['opacity'] ?? 1.0), 2))),
                'bg_color'       => ($f['bg_color'] ?? '') ? self::sanitize_hex($f['bg_color']) : '',
                'border_color'   => ($f['border_color'] ?? '') ? self::sanitize_hex($f['border_color']) : '',
                'border_width'   => max(0, min(10, intval($f['border_width'] ?? 0))),
                'padding'        => max(0, min(50, intval($f['padding'] ?? 0))),
                'text_transform' => in_array($f['text_transform'] ?? '', ['none', 'uppercase', 'lowercase']) ? $f['text_transform'] : 'none',
            ];

            if ($key === 'custom_text') {
                $field['text'] = sanitize_text_field($f['text'] ?? '');
            }

            $config['fields'][$key] = $field;
        }

        return $config;
    }

    /**
     * Hex-Farbe validieren
     */
    private static function sanitize_hex($color) {
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return $color;
        }
        return '#ffffff';
    }

    // ══════════════════════════════════════════════
    // TICKET-DATEN SAMMELN
    // ══════════════════════════════════════════════

    /**
     * Alle Daten für ein Ticket zusammenstellen
     */
    public static function gather_ticket_data($ticket_id) {
        $event_id   = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        $order_id   = intval(get_post_meta($ticket_id, '_tix_ticket_order_id', true));
        $cat_index  = intval(get_post_meta($ticket_id, '_tix_ticket_cat_index', true));
        $code       = get_post_meta($ticket_id, '_tix_ticket_code', true);
        $owner_name = get_post_meta($ticket_id, '_tix_ticket_owner_name', true);
        $owner_email= get_post_meta($ticket_id, '_tix_ticket_owner_email', true);

        $event = get_post($event_id);
        $event_name = $event ? $event->post_title : '';

        // Datum formatieren
        $date_start  = get_post_meta($event_id, '_tix_date_start', true);
        $date_display = '';
        if ($date_start) {
            $ts = strtotime($date_start);
            $date_display = date_i18n('l, d. F Y', $ts);
        }

        $time_start = get_post_meta($event_id, '_tix_time_start', true);
        $time_doors = get_post_meta($event_id, '_tix_time_doors', true);
        $location   = get_post_meta($event_id, '_tix_location', true);
        $address    = get_post_meta($event_id, '_tix_address', true);

        // Kategorie-Name + tatsächlich bezahlter Preis
        $cats     = get_post_meta($event_id, '_tix_ticket_categories', true);
        $cat_name = '';
        $price    = '';
        if (is_array($cats) && isset($cats[$cat_index])) {
            $cat_name = $cats[$cat_index]['name'] ?? '';
        }
        // Bezahlten Preis vom Ticket-Post holen (nicht Kategoriepreis)
        $paid = get_post_meta($ticket_id, '_tix_ticket_price', true);
        if ($paid !== '' && $paid !== false) {
            $p = floatval($paid);
            if ($p > 0) $price = number_format($p, 2, ',', '.') . ' €';
        }

        // QR-Daten
        $qr_data = 'GL-' . $event_id . '-' . $code;

        // Sitzplatz
        $seat_id = get_post_meta($ticket_id, '_tix_ticket_seat_id', true);
        $seat_display = '';
        if ($seat_id) {
            // Format: "section_1_A5" → "Reihe A, Sitz 5"
            $parts = explode('_', $seat_id);
            $last  = end($parts);
            if (preg_match('/^([A-Z]+)(\d+)$/', $last, $m)) {
                $seat_display = 'Reihe ' . $m[1] . ', Sitz ' . $m[2];
            } else {
                $seat_display = $last;
            }
        }

        return [
            'event_name'     => $event_name,
            'event_date'     => $date_display,
            'event_time'     => $time_start ? $time_start . ' Uhr' : '',
            'event_doors'    => $time_doors ? $time_doors . ' Uhr' : '',
            'event_location' => $location ?: '',
            'event_address'  => $address ?: '',
            'cat_name'       => $cat_name,
            'price'          => $price,
            'owner_name'     => $owner_name ?: '',
            'owner_email'    => $owner_email ?: '',
            'ticket_code'    => $code ?: '',
            'order_id'       => $order_id ? '#' . $order_id : '',
            'seat'           => $seat_display,
            'qr_code'        => $qr_data,
            'barcode'        => $code ?: '',
            'custom_text'    => '', // wird aus Config gelesen
        ];
    }

    /**
     * Vorschau-Daten (keine echten Ticket-Daten)
     */
    public static function preview_data() {
        return [
            'event_name'     => 'Muster-Event 2025',
            'event_date'     => 'Samstag, 15. März 2025',
            'event_time'     => '20:00 Uhr',
            'event_doors'    => '19:00 Uhr',
            'event_location' => 'Muster-Location',
            'event_address'  => 'Musterstraße 1, 12345 Musterstadt',
            'cat_name'       => 'Standard-Ticket',
            'price'          => '29,90 €',
            'owner_name'     => 'Max Mustermann',
            'owner_email'    => 'max@example.com',
            'ticket_code'    => 'A7X3K9M2P5BN',
            'order_id'       => '#12345',
            'seat'           => 'Reihe A, Sitz 5',
            'qr_code'        => 'GL-999-A7X3K9M2P5BN',
            'barcode'        => 'A7X3K9M2P5BN',
            'custom_text'    => '',
        ];
    }

    // ══════════════════════════════════════════════
    // GD-RENDERING
    // ══════════════════════════════════════════════

    /**
     * Template-Bild laden (JPG/PNG → GD-Resource)
     */
    public static function load_template_image($attachment_id) {
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return null;

        $mime = wp_check_filetype($file)['type'] ?? '';

        if (strpos($mime, 'png') !== false) {
            return @imagecreatefrompng($file);
        } elseif (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) {
            return @imagecreatefromjpeg($file);
        }

        return null;
    }

    /**
     * Font-Datei-Pfad basierend auf font_family + font_weight
     */
    private static function get_font_path($family, $weight) {
        $fonts_dir = TIXOMAT_PATH . 'assets/fonts/';
        $map = [
            'sans-serif' => [
                'normal' => 'OpenSans-Regular.ttf',
                'bold'   => 'OpenSans-Bold.ttf',
            ],
            'serif' => [
                'normal' => 'OpenSans-Regular.ttf', // Fallback
                'bold'   => 'OpenSans-Bold.ttf',
            ],
            'monospace' => [
                'normal' => 'RobotoMono-Regular.ttf',
                'bold'   => 'RobotoMono-Regular.ttf',
            ],
        ];

        $file = $map[$family][$weight] ?? $map['sans-serif']['normal'];
        $path = $fonts_dir . $file;

        return file_exists($path) ? $path : null;
    }

    /**
     * Hex-Farbe in RGB umwandeln
     */
    private static function hex_to_rgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0] . $hex[1].$hex[1] . $hex[2].$hex[2];
        }
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Text auf GD-Bild rendern (mit Ausrichtung, Textumbruch + erweiterte Properties)
     */
    public static function render_text_field($img, $field_cfg, $text) {
        if (empty($text) || !$field_cfg['enabled']) return;

        // Text-Transformation
        $transform = $field_cfg['text_transform'] ?? 'none';
        if ($transform === 'uppercase') $text = mb_strtoupper($text);
        elseif ($transform === 'lowercase') $text = mb_strtolower($text);

        $font = self::get_font_path($field_cfg['font_family'], $field_cfg['font_weight']);

        // Opacity-Support
        $opacity     = $field_cfg['opacity'] ?? 1.0;
        $alpha       = max(0, min(127, intval((1 - $opacity) * 127)));
        $rgb         = self::hex_to_rgb($field_cfg['color']);
        $color       = imagecolorallocatealpha($img, $rgb['r'], $rgb['g'], $rgb['b'], $alpha);

        $size    = $field_cfg['font_size'];
        $x       = $field_cfg['x'];
        $y       = $field_cfg['y'];
        $maxW    = $field_cfg['width'];
        $maxH    = $field_cfg['height'];
        $align   = $field_cfg['alignment'];
        $padding = intval($field_cfg['padding'] ?? 0);
        $rotation = intval($field_cfg['rotation'] ?? 0);
        $letter_spacing = intval($field_cfg['letter_spacing'] ?? 0);
        $line_height_factor = floatval($field_cfg['line_height'] ?? 1.4);

        // Hintergrund-Farbe
        $bg_color_hex = $field_cfg['bg_color'] ?? '';
        if ($bg_color_hex) {
            $bg_rgb = self::hex_to_rgb($bg_color_hex);
            $bg_col = imagecolorallocatealpha($img, $bg_rgb['r'], $bg_rgb['g'], $bg_rgb['b'], $alpha);
            imagefilledrectangle($img, $x, $y, $x + $maxW - 1, $y + $maxH - 1, $bg_col);
        }

        // Rahmen
        $border_color_hex = $field_cfg['border_color'] ?? '';
        $border_width     = intval($field_cfg['border_width'] ?? 0);
        if ($border_color_hex && $border_width > 0) {
            $br_rgb = self::hex_to_rgb($border_color_hex);
            $br_col = imagecolorallocatealpha($img, $br_rgb['r'], $br_rgb['g'], $br_rgb['b'], $alpha);
            imagesetthickness($img, $border_width);
            imagerectangle($img, $x, $y, $x + $maxW - 1, $y + $maxH - 1, $br_col);
            imagesetthickness($img, 1);
        }

        // Text-Zeichenbereich nach Padding berechnen
        $text_x = $x + $padding;
        $text_y = $y + $padding;
        $text_maxW = max(10, $maxW - ($padding * 2));

        // FreeType verfügbar?
        if ($font && function_exists('imagettftext')) {
            // Textumbruch berechnen
            $lines = self::wrap_text_gd($font, $size, $text, $text_maxW);

            $line_spacing = intval($size * $line_height_factor);

            foreach ($lines as $i => $line) {
                $bbox   = imagettfbbox($size, 0, $font, $line);
                $line_w = abs($bbox[2] - $bbox[0]);
                $line_h = abs($bbox[7] - $bbox[1]);

                $draw_x = $text_x;
                if ($align === 'center') {
                    $draw_x = $text_x + intval(($text_maxW - $line_w) / 2);
                } elseif ($align === 'right') {
                    $draw_x = $text_x + $text_maxW - $line_w;
                }

                // y bei imagettftext ist die Baseline
                $draw_y = $text_y + $line_h + ($i * $line_spacing);

                // Letter-Spacing: Zeichenweise rendern
                if ($letter_spacing !== 0) {
                    self::render_text_with_spacing($img, $size, $rotation, $draw_x, $draw_y, $color, $font, $line, $letter_spacing);
                } else {
                    imagettftext($img, $size, $rotation, $draw_x, $draw_y, $color, $font, $line);
                }
            }
        } else {
            // Fallback: imagestring (keine TTF-Unterstützung)
            $font_id = ($size > 16) ? 5 : (($size > 12) ? 4 : 3);
            imagestring($img, $font_id, $text_x, $text_y, $text, $color);
        }
    }

    /**
     * Text Zeichen-für-Zeichen rendern (für Letter-Spacing)
     */
    private static function render_text_with_spacing($img, $size, $angle, $x, $y, $color, $font, $text, $spacing) {
        $chars = mb_str_split($text);
        $cur_x = $x;

        foreach ($chars as $char) {
            imagettftext($img, $size, $angle, $cur_x, $y, $color, $font, $char);
            $bbox = imagettfbbox($size, 0, $font, $char);
            $char_w = abs($bbox[2] - $bbox[0]);
            $cur_x += $char_w + $spacing;
        }
    }

    /**
     * Text in Zeilen umbrechen (GD / FreeType)
     */
    private static function wrap_text_gd($font, $size, $text, $max_width) {
        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $test = $current ? "$current $word" : $word;
            $bbox = imagettfbbox($size, 0, $font, $test);
            $w = abs($bbox[2] - $bbox[0]);

            if ($w > $max_width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
        }
        if ($current !== '') $lines[] = $current;

        return $lines ?: [$text];
    }

    /**
     * QR-Code auf GD-Bild rendern (mit Rotation, Opacity, Hintergrund)
     */
    public static function render_qr_code($img, $field_cfg, $qr_data) {
        if (!$field_cfg['enabled'] || empty($qr_data)) return;

        $qr_size = max($field_cfg['width'], $field_cfg['height']);

        // QR-Code via API generieren (mit Transient-Cache)
        $cache_key = 'tix_qr_' . md5($qr_data . $qr_size);
        $qr_png = get_transient($cache_key);

        if (!$qr_png) {
            $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $qr_size . 'x' . $qr_size . '&data=' . urlencode($qr_data) . '&format=png';
            $response = wp_remote_get($url, ['timeout' => 10]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $qr_png = wp_remote_retrieve_body($response);
                set_transient($cache_key, $qr_png, HOUR_IN_SECONDS);
            }
        }

        if (!$qr_png) return;

        $qr_img = @imagecreatefromstring($qr_png);
        if (!$qr_img) return;

        $src_w    = imagesx($qr_img);
        $src_h    = imagesy($qr_img);
        $dst_w    = $field_cfg['width'];
        $dst_h    = $field_cfg['height'];
        $dst_x    = $field_cfg['x'];
        $dst_y    = $field_cfg['y'];
        $rotation = intval($field_cfg['rotation'] ?? 0);
        $opacity  = floatval($field_cfg['opacity'] ?? 1.0);

        // Hintergrund-Farbe
        $bg_color_hex = $field_cfg['bg_color'] ?? '';
        if ($bg_color_hex) {
            $bg_rgb = self::hex_to_rgb($bg_color_hex);
            $alpha  = max(0, min(127, intval((1 - $opacity) * 127)));
            $bg_col = imagecolorallocatealpha($img, $bg_rgb['r'], $bg_rgb['g'], $bg_rgb['b'], $alpha);
            imagefilledrectangle($img, $dst_x, $dst_y, $dst_x + $dst_w - 1, $dst_y + $dst_h - 1, $bg_col);
        }

        // QR-Code auf Zielgröße skalieren
        $scaled = imagecreatetruecolor($dst_w, $dst_h);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $qr_img, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
        imagedestroy($qr_img);

        // Rotation
        if ($rotation !== 0) {
            $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
            $rotated = imagerotate($scaled, -$rotation, $transparent);
            if ($rotated) {
                imagedestroy($scaled);
                $scaled = $rotated;
                // Rotiertes Bild zentriert platzieren
                $rot_w = imagesx($scaled);
                $rot_h = imagesy($scaled);
                $dst_x = $dst_x - intval(($rot_w - $dst_w) / 2);
                $dst_y = $dst_y - intval(($rot_h - $dst_h) / 2);
                $dst_w = $rot_w;
                $dst_h = $rot_h;
            }
        }

        // Opacity: via imagecopymerge (0-100)
        $merge_pct = max(0, min(100, intval($opacity * 100)));
        imagealphablending($img, true);
        if ($merge_pct < 100) {
            imagecopymerge($img, $scaled, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $merge_pct);
        } else {
            imagecopy($img, $scaled, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h);
        }

        imagedestroy($scaled);
    }

    /**
     * Code128-B Barcode auf GD-Bild rendern (mit Rotation, Opacity, Hintergrund)
     * @since 1.22.0
     */
    public static function render_barcode($img, $field_cfg, $barcode_data) {
        if (!$field_cfg['enabled'] || empty($barcode_data)) return;

        $pattern = self::encode_code128b($barcode_data);
        if (!$pattern) return;

        $dst_w    = $field_cfg['width'];
        $dst_h    = $field_cfg['height'];
        $dst_x    = $field_cfg['x'];
        $dst_y    = $field_cfg['y'];
        $rotation = intval($field_cfg['rotation'] ?? 0);
        $opacity  = floatval($field_cfg['opacity'] ?? 1.0);

        // Hintergrund-Farbe
        $bg_color_hex = $field_cfg['bg_color'] ?? '';
        if ($bg_color_hex) {
            $bg_rgb = self::hex_to_rgb($bg_color_hex);
            $alpha  = max(0, min(127, intval((1 - $opacity) * 127)));
            $bg_col = imagecolorallocatealpha($img, $bg_rgb['r'], $bg_rgb['g'], $bg_rgb['b'], $alpha);
            imagefilledrectangle($img, $dst_x, $dst_y, $dst_x + $dst_w - 1, $dst_y + $dst_h - 1, $bg_col);
        }

        $bar_img = self::render_code128_image($pattern, $dst_w, $dst_h);
        if (!$bar_img) return;

        // Rotation
        if ($rotation !== 0) {
            $transparent = imagecolorallocate($bar_img, 255, 255, 255);
            $rotated = imagerotate($bar_img, -$rotation, $transparent);
            if ($rotated) {
                imagedestroy($bar_img);
                $bar_img = $rotated;
                $rot_w = imagesx($bar_img);
                $rot_h = imagesy($bar_img);
                $dst_x = $dst_x - intval(($rot_w - $dst_w) / 2);
                $dst_y = $dst_y - intval(($rot_h - $dst_h) / 2);
                $dst_w = $rot_w;
                $dst_h = $rot_h;
            }
        }

        // Opacity: via imagecopymerge (0-100)
        $merge_pct = max(0, min(100, intval($opacity * 100)));
        imagealphablending($img, true);
        if ($merge_pct < 100) {
            imagecopymerge($img, $bar_img, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $merge_pct);
        } else {
            imagecopy($img, $bar_img, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h);
        }

        imagedestroy($bar_img);
    }

    /**
     * Code128-B Encoding: String → Bit-Pattern
     * Code128-B unterstützt alle druckbaren ASCII-Zeichen (32–126).
     */
    private static function encode_code128b($data) {
        // Code128-B Muster-Tabelle (Index 0–106)
        // Jedes Pattern: 6 Balken/Lücken-Breiten (Summe = 11 Module)
        $patterns = [
            '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
            '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
            '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
            '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
            '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
            '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
            '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
            '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
            '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
            '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
            '114131','311141','411131','211412','211214','211232','2331112',
        ];

        if (strlen($data) === 0) return null;

        // Start Code B = Index 104
        $indices = [104];
        $checksum = 104;

        for ($i = 0; $i < strlen($data); $i++) {
            $code = ord($data[$i]) - 32;
            if ($code < 0 || $code > 94) $code = 0; // Ungültig → Space
            $indices[] = $code;
            $checksum += $code * ($i + 1);
        }

        // Prüfziffer
        $indices[] = $checksum % 103;

        // Stop = Index 106
        $indices[] = 106;

        // Pattern zusammenbauen
        $result = '';
        foreach ($indices as $idx) {
            $result .= $patterns[$idx] ?? '';
        }

        return $result;
    }

    /**
     * Bit-Pattern als GD-Bild rendern (Schwarz/Weiß Balken)
     */
    private static function render_code128_image($pattern, $target_w, $target_h) {
        // Gesamtbreite in Modulen berechnen
        $total_modules = 0;
        for ($i = 0; $i < strlen($pattern); $i++) {
            $total_modules += intval($pattern[$i]);
        }
        if ($total_modules === 0) return null;

        // Quiet Zone (10 Module links + rechts)
        $quiet = 10;
        $full_width = $total_modules + ($quiet * 2);

        // Bild in nativer Auflösung erstellen (1 Modul = 2px für Schärfe)
        $scale = max(1, intval($target_w / $full_width));
        $img_w = $full_width * $scale;
        $img_h = max(1, $target_h);

        $img = imagecreatetruecolor($img_w, $img_h);
        if (!$img) return null;

        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);

        // Weißer Hintergrund
        imagefilledrectangle($img, 0, 0, $img_w - 1, $img_h - 1, $white);

        // Balken zeichnen (Bar, Space, Bar, Space, ...)
        $x = $quiet * $scale;
        $is_bar = true;

        for ($i = 0; $i < strlen($pattern); $i++) {
            $width = intval($pattern[$i]) * $scale;
            if ($is_bar) {
                imagefilledrectangle($img, $x, 0, $x + $width - 1, $img_h - 1, $black);
            }
            $x += $width;
            $is_bar = !$is_bar;
        }

        return $img;
    }

    /**
     * Vollständiges Ticket-Bild rendern
     *
     * @param int   $ticket_id  Ticket-Post-ID (0 = Vorschau)
     * @param array $config     Template-Config
     * @return GdImage|resource|null
     */
    public static function render_ticket_image($ticket_id, $config = null) {
        if (!$config) {
            $event_id = get_post_meta($ticket_id, '_tix_ticket_event_id', true);
            $config = self::get_effective_config($event_id);
        }
        if (!$config || empty($config['template_image_id'])) return null;

        // Memory für große Bilder
        $old_limit = ini_get('memory_limit');
        if (intval($old_limit) < 256) {
            @ini_set('memory_limit', '256M');
        }

        // Template-Bild laden
        $img = self::load_template_image($config['template_image_id']);
        if (!$img) return null;

        // Ticket-Daten sammeln
        $data = ($ticket_id > 0) ? self::gather_ticket_data($ticket_id) : self::preview_data();

        // Custom-Text aus Config einfügen
        if (isset($config['fields']['custom_text']['text'])) {
            $data['custom_text'] = $config['fields']['custom_text']['text'];
        }

        // Felder rendern
        foreach ($config['fields'] as $key => $field_cfg) {
            if (!$field_cfg['enabled']) continue;

            if ($key === 'qr_code') {
                self::render_qr_code($img, $field_cfg, $data['qr_code'] ?? '');
            } elseif ($key === 'barcode') {
                self::render_barcode($img, $field_cfg, $data['barcode'] ?? '');
            } else {
                $text = $data[$key] ?? '';
                self::render_text_field($img, $field_cfg, $text);
            }
        }

        return $img;
    }

    // ══════════════════════════════════════════════
    // PDF-ERZEUGUNG (OHNE LIBRARY)
    // ══════════════════════════════════════════════

    /**
     * Minimale PDF-Datei aus JPEG-Daten erzeugen
     *
     * Struktur: Catalog → Pages → Page → Content-Stream → Image-XObject
     * JPEG wird direkt als DCTDecode-Stream eingebettet.
     */
    public static function create_minimal_pdf($jpeg_data, $img_w, $img_h) {
        // A4-Seitengröße in Points (595.28 x 841.89)
        // Bild auf Seite skalieren
        $page_w = 595.28;
        $page_h = 841.89;

        // Aspect Ratio beibehalten
        $scale_w = $page_w / $img_w;
        $scale_h = $page_h / $img_h;
        $scale   = min($scale_w, $scale_h);

        $draw_w = $img_w * $scale;
        $draw_h = $img_h * $scale;
        $draw_x = ($page_w - $draw_w) / 2;
        $draw_y = ($page_h - $draw_h) / 2;

        $img_len = strlen($jpeg_data);

        // Content-Stream: Bild zeichnen
        $content = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Img Do Q", $draw_w, $draw_h, $draw_x, $draw_y);
        $content_len = strlen($content);

        $offsets = [];
        $pdf = "%PDF-1.4\n";

        // Obj 1: Catalog
        $offsets[1] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // Obj 2: Pages
        $offsets[2] = strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

        // Obj 3: Page
        $offsets[3] = strlen($pdf);
        $pdf .= sprintf(
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents 4 0 R /Resources << /XObject << /Img 5 0 R >> >> >>\nendobj\n",
            $page_w, $page_h
        );

        // Obj 4: Content-Stream
        $offsets[4] = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Length $content_len >>\nstream\n$content\nendstream\nendobj\n";

        // Obj 5: Image XObject (JPEG als DCTDecode)
        $offsets[5] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Type /XObject /Subtype /Image /Width $img_w /Height $img_h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length $img_len >>\nstream\n";
        $pdf .= $jpeg_data;
        $pdf .= "\nendstream\nendobj\n";

        // XRef-Table
        $xref_offset = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        // Trailer
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref_offset\n%%EOF";

        return $pdf;
    }

    // ══════════════════════════════════════════════
    // AJAX: VORSCHAU
    // ══════════════════════════════════════════════

    /**
     * AJAX-Handler: Vorschau-Bild generieren
     * Erwartet POST mit 'config' (JSON) oder 'event_id'
     */
    public static function ajax_preview() {
        check_ajax_referer('tix_template_preview', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');

        $config_json = wp_unslash($_POST['config'] ?? '');
        $config = self::sanitize_config($config_json);

        if (empty($config['template_image_id'])) {
            wp_send_json_error(['message' => 'Kein Template-Bild ausgewählt.']);
        }

        $img = self::render_ticket_image(0, $config);
        if (!$img) {
            wp_send_json_error(['message' => 'Fehler beim Rendern des Tickets.']);
        }

        // Vorschau kleiner rendern (max 800px breit)
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > 800) {
            $new_w = 800;
            $new_h = intval($h * (800 / $w));
            $thumb = imagecreatetruecolor($new_w, $new_h);
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
            imagedestroy($img);
            $img = $thumb;
        }

        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        $base64 = 'data:image/jpeg;base64,' . base64_encode($jpeg);

        wp_send_json_success(['image' => $base64]);
    }

    // ══════════════════════════════════════════════
    // HELPER: GD-VERFÜGBARKEIT
    // ══════════════════════════════════════════════

    /**
     * Prüft ob GD + FreeType verfügbar sind
     */
    public static function check_gd_support() {
        return [
            'gd'       => extension_loaded('gd'),
            'freetype' => function_exists('imagettftext'),
        ];
    }
}
