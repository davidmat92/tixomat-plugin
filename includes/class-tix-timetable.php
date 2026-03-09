<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat Timetable – Multi-Stage Event Program
 *
 * Supports:
 * - Multiple stages/rooms with colors
 * - Multi-day events
 * - Grid layout (desktop) + list layout (mobile)
 * - Day-tab switching
 * - Shortcode [tix_timetable]
 */
class TIX_Timetable {

    public static function init() {
        add_shortcode('tix_timetable', [__CLASS__, 'shortcode']);
    }

    /* ════════════════════════════════════════
       SHORTCODE [tix_timetable]
       ════════════════════════════════════════ */
    public static function shortcode($atts = []) {
        $atts    = shortcode_atts(['id' => 0], $atts, 'tix_timetable');
        $post_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

        if (!$post_id || get_post_type($post_id) !== 'event') {
            return '';
        }

        $stages    = get_post_meta($post_id, '_tix_stages', true);
        $timetable = get_post_meta($post_id, '_tix_timetable', true);

        if (!is_array($stages) || empty($stages) || !is_array($timetable) || empty($timetable)) {
            return '';
        }

        self::enqueue();

        ob_start();
        $days = array_keys($timetable);
        sort($days);
        ?>
        <div class="tix-tt" data-stages="<?php echo count($stages); ?>">

            <?php // ── Tages-Tabs (nur bei Mehrtages-Events) ── ?>
            <?php if (count($days) > 1): ?>
            <div class="tix-tt-days">
                <?php foreach ($days as $idx => $day):
                    $dt = date_create($day);
                    $label = $dt ? $dt->format('D j. M') : $day;
                    // Deutsche Tagesabkürzungen
                    $label = str_replace(
                        ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                        ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'],
                        $label
                    );
                    $label = str_replace(
                        ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                        ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'],
                        $label
                    );
                ?>
                    <button type="button" class="tix-tt-day<?php echo $idx === 0 ? ' active' : ''; ?>" data-day="<?php echo esc_attr($day); ?>">
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php // ── Bühnen-Filter (Mobile) ── ?>
            <div class="tix-tt-stage-filter">
                <button type="button" class="tix-tt-filter-btn active" data-stage="all">Alle</button>
                <?php foreach ($stages as $si => $stage): ?>
                    <button type="button" class="tix-tt-filter-btn" data-stage="<?php echo $si; ?>"
                            style="--tt-stage-color: <?php echo esc_attr($stage['color'] ?? '#6366f1'); ?>">
                        <?php echo esc_html($stage['name']); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php // ── Timetable pro Tag ── ?>
            <?php foreach ($days as $idx => $day):
                $slots = $timetable[$day] ?? [];
                // Sort by time
                usort($slots, function($a, $b) {
                    return strcmp($a['time'] ?? '', $b['time'] ?? '');
                });
            ?>
            <div class="tix-tt-content<?php echo $idx === 0 ? ' active' : ''; ?>" data-day="<?php echo esc_attr($day); ?>">

                <?php // ── Desktop: Grid-Ansicht ── ?>
                <div class="tix-tt-grid">
                    <div class="tix-tt-grid-header">
                        <div class="tix-tt-time-header"></div>
                        <?php foreach ($stages as $si => $stage): ?>
                            <div class="tix-tt-stage-header" style="--tt-stage-color: <?php echo esc_attr($stage['color'] ?? '#6366f1'); ?>">
                                <?php echo esc_html($stage['name']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    // Sammle alle Zeitslots
                    $time_slots = [];
                    foreach ($slots as $slot) {
                        $t = $slot['time'] ?? '';
                        if ($t && !in_array($t, $time_slots)) $time_slots[] = $t;
                    }
                    sort($time_slots);

                    foreach ($time_slots as $time):
                    ?>
                    <div class="tix-tt-grid-row">
                        <div class="tix-tt-time"><?php echo esc_html($time); ?></div>
                        <?php foreach ($stages as $si => $stage):
                            // Finde Slot für diese Bühne und diese Zeit
                            $match = null;
                            foreach ($slots as $slot) {
                                if (($slot['time'] ?? '') === $time && intval($slot['stage'] ?? -1) === $si) {
                                    $match = $slot;
                                    break;
                                }
                            }
                        ?>
                            <div class="tix-tt-cell" style="--tt-stage-color: <?php echo esc_attr($stage['color'] ?? '#6366f1'); ?>">
                                <?php if ($match): ?>
                                    <div class="tix-tt-slot">
                                        <span class="tix-tt-slot-time"><?php echo esc_html($match['time']); ?><?php if (!empty($match['end'])): ?> – <?php echo esc_html($match['end']); ?><?php endif; ?></span>
                                        <span class="tix-tt-slot-title"><?php echo esc_html($match['title'] ?? ''); ?></span>
                                        <?php if (!empty($match['desc'])): ?>
                                            <span class="tix-tt-slot-desc"><?php echo esc_html($match['desc']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php // ── Mobile: Listen-Ansicht ── ?>
                <div class="tix-tt-list">
                    <?php foreach ($slots as $slot):
                        $s_idx = intval($slot['stage'] ?? 0);
                        $s_color = $stages[$s_idx]['color'] ?? '#6366f1';
                        $s_name  = $stages[$s_idx]['name'] ?? '';
                    ?>
                        <div class="tix-tt-list-item" data-stage="<?php echo $s_idx; ?>" style="--tt-stage-color: <?php echo esc_attr($s_color); ?>">
                            <div class="tix-tt-list-time"><?php echo esc_html($slot['time'] ?? ''); ?><?php if (!empty($slot['end'])): ?> – <?php echo esc_html($slot['end']); ?><?php endif; ?></div>
                            <div class="tix-tt-list-info">
                                <span class="tix-tt-list-title"><?php echo esc_html($slot['title'] ?? ''); ?></span>
                                <?php if (!empty($slot['desc'])): ?>
                                    <span class="tix-tt-list-desc"><?php echo esc_html($slot['desc']); ?></span>
                                <?php endif; ?>
                                <span class="tix-tt-list-stage"><?php echo esc_html($s_name); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
            <?php endforeach; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /* ══ Assets ══ */
    private static function enqueue() {
        wp_enqueue_style('tix-timetable', TIXOMAT_URL . 'assets/css/timetable.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-timetable', TIXOMAT_URL . 'assets/js/timetable.js', [], TIXOMAT_VERSION, true);
    }
}
