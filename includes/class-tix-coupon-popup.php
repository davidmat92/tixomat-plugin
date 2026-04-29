<?php
/**
 * TIX_Coupon_Popup — Auffälliges Popup wenn Auto-Apply-Coupon aktiv ist
 *
 * Zeigt einmal pro Session ein Modal mit dem aktiven Auto-Apply-Coupon.
 * - Nur Frontend (kein Admin)
 * - Cookie verhindert Wieder-Anzeige (24h TTL)
 * - Responsive für alle Bildschirmgrößen
 * - Kann vom User per X / Hintergrund-Klick / Escape geschlossen werden
 */
if (!defined('ABSPATH')) exit;

class TIX_Coupon_Popup {

    public static function init() {
        add_action('wp_footer', [__CLASS__, 'render'], 50);
    }

    public static function render() {
        if (is_admin()) return;
        if (!class_exists('TIX_Coupons')) return;

        // Settings: Popup nur zeigen wenn aktiviert (Default = an)
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (isset($s['coupon_popup_enabled']) && empty($s['coupon_popup_enabled'])) return;

        $coupon = TIX_Coupons::get_auto_apply_coupon();
        if (!$coupon || empty($coupon['code'])) return;

        $code  = $coupon['code'];
        $type  = $coupon['discount_type'] ?? 'percent';
        $value = floatval($coupon['value'] ?? 0);

        $value_label = $type === 'percent'
            ? number_format($value, ($value == intval($value)) ? 0 : 1, ',', '.') . '%'
            : number_format($value, 2, ',', '.') . ' €';

        // Popup-Headline + Subtext aus Settings (mit Defaults)
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $headline = $s['coupon_popup_headline'] ?? '🎁 Dein Rabatt ist aktiv!';
        $subtext  = $s['coupon_popup_subtext']  ?? 'Wir haben dir bereits einen Gutschein im Warenkorb hinterlegt — du sparst beim Checkout automatisch.';
        $cta_text = $s['coupon_popup_cta']      ?? 'Jetzt Tickets sichern';
        $cta_url  = $s['coupon_popup_cta_url']  ?? '';

        // Cookie-Name pro Code (verhindert dass alter Popup nervt wenn Code geändert)
        $cookie_name = 'tix_coupon_popup_seen_' . md5($code);
        ?>
        <style>
            /* Layout (nicht über Color-Registry konfigurierbar) */
            .tix-cp-overlay{position:fixed;inset:0;z-index:99998;backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;padding:16px;animation:tix-cp-fade .3s ease;}
            .tix-cp-overlay.tix-cp-open{display:flex;}
            .tix-cp-modal{border-radius:20px;max-width:460px;width:100%;padding:0;box-shadow:0 25px 60px rgba(0,0,0,.35);animation:tix-cp-pop .35s cubic-bezier(.4,0,.2,1);overflow:hidden;position:relative;}
            .tix-cp-banner{padding:30px 28px 28px;text-align:center;position:relative;}
            .tix-cp-banner::before{content:"";position:absolute;inset:0;background-image:radial-gradient(circle at 20% 30%,rgba(255,255,255,.2) 0%,transparent 50%),radial-gradient(circle at 80% 70%,rgba(255,255,255,.15) 0%,transparent 50%);pointer-events:none;}
            .tix-cp-confetti{position:absolute;top:14px;left:16px;font-size:24px;animation:tix-cp-confetti-l 4s ease-in-out infinite;}
            .tix-cp-confetti-r{position:absolute;top:18px;right:16px;font-size:24px;animation:tix-cp-confetti-r 4s ease-in-out infinite .5s;}
            .tix-cp-value{font-size:46px;font-weight:900;line-height:1;margin:8px 0 4px;letter-spacing:-0.02em;text-shadow:0 2px 8px rgba(0,0,0,.15);position:relative;}
            .tix-cp-saving{font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;opacity:.92;position:relative;}
            .tix-cp-body{padding:26px 28px 24px;text-align:center;}
            .tix-cp-headline{font-size:20px;font-weight:700;margin:0 0 10px;line-height:1.25;}
            .tix-cp-subtext{font-size:14px;line-height:1.55;margin:0 0 18px;}
            .tix-cp-code-box{border:1.5px dashed transparent;border-radius:12px;padding:12px 16px;margin:0 0 18px;display:flex;align-items:center;justify-content:space-between;gap:10px;}
            .tix-cp-code-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;}
            .tix-cp-code{font-family:Menlo,monospace;font-size:16px;font-weight:700;letter-spacing:.05em;}
            .tix-cp-code-status{font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
            .tix-cp-cta{border:none;padding:14px 24px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;width:100%;transition:transform .12s,filter .15s;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;}
            .tix-cp-cta:hover{filter:brightness(1.15);transform:translateY(-1px);}
            .tix-cp-close{position:absolute;top:14px;right:14px;border:none;width:32px;height:32px;border-radius:50%;font-size:18px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:filter .15s;z-index:2;backdrop-filter:blur(2px);}
            .tix-cp-close:hover{filter:brightness(1.4);}

            /* Default-Farben (überschreibbar via tix-settings#colors → "Coupon-Popup") */
            .tix-cp-overlay{background:rgba(15,23,42,.65);}
            .tix-cp-modal{background:#fff;}
            .tix-cp-banner{background:#FF5500;color:#fff;}
            .tix-cp-saving{color:#fff;}
            .tix-cp-value{color:#fff;}
            .tix-cp-headline{color:#0f172a;}
            .tix-cp-subtext{color:#64748b;}
            .tix-cp-code-box{background:#f0fdf4;border-color:#22c55e;}
            .tix-cp-code-label{color:#15803d;}
            .tix-cp-code{color:#15803d;}
            .tix-cp-code-status{color:#16a34a;}
            .tix-cp-cta{background:#0f172a;color:#fff;}
            .tix-cp-close{background:rgba(255,255,255,.25);color:#fff;}
            @keyframes tix-cp-fade{from{opacity:0;}to{opacity:1;}}
            @keyframes tix-cp-pop{from{opacity:0;transform:scale(.85) translateY(20px);}to{opacity:1;transform:scale(1) translateY(0);}}
            @keyframes tix-cp-confetti-l{0%,100%{transform:translateY(0) rotate(-12deg);}50%{transform:translateY(-6px) rotate(-18deg);}}
            @keyframes tix-cp-confetti-r{0%,100%{transform:translateY(0) rotate(8deg);}50%{transform:translateY(-8px) rotate(15deg);}}
            @media (max-width:480px){
                .tix-cp-banner{padding:24px 20px 22px;}
                .tix-cp-value{font-size:38px;}
                .tix-cp-body{padding:20px 20px 18px;}
                .tix-cp-headline{font-size:18px;}
                .tix-cp-subtext{font-size:13px;}
                .tix-cp-code-box{flex-wrap:wrap;}
            }
        </style>

        <div class="tix-cp-overlay" id="tix-coupon-popup" role="dialog" aria-modal="true" aria-labelledby="tix-cp-h">
            <div class="tix-cp-modal">
                <div class="tix-cp-banner">
                    <span class="tix-cp-confetti">🎉</span>
                    <span class="tix-cp-confetti-r">✨</span>
                    <button type="button" class="tix-cp-close" aria-label="Schließen" data-tix-cp-close>×</button>
                    <div class="tix-cp-saving">Du sparst</div>
                    <div class="tix-cp-value"><?php echo esc_html($value_label); ?></div>
                </div>
                <div class="tix-cp-body">
                    <h3 class="tix-cp-headline" id="tix-cp-h"><?php echo esc_html($headline); ?></h3>
                    <p class="tix-cp-subtext"><?php echo esc_html($subtext); ?></p>
                    <div class="tix-cp-code-box">
                        <div>
                            <div class="tix-cp-code-label">Gutscheincode</div>
                            <div class="tix-cp-code"><?php echo esc_html($code); ?></div>
                        </div>
                        <span class="tix-cp-code-status">✓ aktiv</span>
                    </div>
                    <?php if ($cta_url): ?>
                        <a href="<?php echo esc_url($cta_url); ?>" class="tix-cp-cta" data-tix-cp-close><?php echo esc_html($cta_text); ?> <span style="font-size:18px;line-height:1;">→</span></a>
                    <?php else: ?>
                        <button type="button" class="tix-cp-cta" data-tix-cp-close><?php echo esc_html($cta_text); ?> <span style="font-size:18px;line-height:1;">→</span></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var COOKIE = <?php echo wp_json_encode($cookie_name); ?>;
            // Cookie-Helper
            function hasSeen() {
                return (document.cookie || '').split('; ').some(function(c){ return c.indexOf(COOKIE + '=') === 0; });
            }
            function markSeen() {
                document.cookie = COOKIE + '=1; path=/; max-age=86400; SameSite=Lax';
            }
            if (hasSeen()) return;

            var overlay = document.getElementById('tix-coupon-popup');
            if (!overlay) return;

            // Erst nach 1.2s anzeigen — User soll erst kurz die Seite sehen
            setTimeout(function(){
                overlay.classList.add('tix-cp-open');
                document.body.style.overflow = 'hidden';
            }, 1200);

            function close(){
                overlay.classList.remove('tix-cp-open');
                document.body.style.overflow = '';
                markSeen();
            }
            overlay.addEventListener('click', function(e){
                if (e.target === overlay || (e.target.dataset && e.target.dataset.tixCpClose !== undefined) || (e.target.closest && e.target.closest('[data-tix-cp-close]'))) {
                    close();
                }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && overlay.classList.contains('tix-cp-open')) close();
            });
        })();
        </script>
        <?php
    }
}
