<?php
/**
 * Plugin Name: Tixomat Chat Widget
 * Description: Ticket-Assistent Chat - [tix_chat] und [tix_chat_widget]
 * Version: 1.0
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// CONFIG
// ============================================================
define('TIX_CHAT_API', 'https://tixomat-dpconnect.pythonanywhere.com');
define('TIX_CHAT_LOGO', '/wp-content/uploads/2026/03/icon-tixomat-orange.png');
define('TIX_CHAT_BETA', true);

// ============================================================
// FARBEN
// ============================================================
$TIX_COLORS = [
    'bg'            => '#FAF8F4',
    'bg_header'     => '#ffffff',
    'bg_input'      => '#ffffff',
    'bg_card'       => '#ffffff',
    'bg_input_field'=> '#F3F0EA',
    'accent'        => '#FF5500',
    'accent_hover'  => '#CC4400',
    'accent_bg'     => 'rgba(255,85,0,.08)',
    'accent_shadow' => 'rgba(255,85,0,.25)',
    'text'          => '#0D0B09',
    'text_muted'    => 'rgba(13,11,9,.50)',
    'text_light'    => 'rgba(13,11,9,.35)',
    'border'        => '#EDE9E0',
    'border_hover'  => '#FF5500',
    'user_bubble'   => '#FF5500',
    'user_text'     => '#ffffff',
    'cart_accent'   => '#FF5500',
];


// ============================================================
// WC AJAX
// ============================================================
add_action('wp_ajax_tix_bot_get_cart', 'tix_ajax_get_cart');
add_action('wp_ajax_nopriv_tix_bot_get_cart', 'tix_ajax_get_cart');
function tix_ajax_get_cart() {
    if (!function_exists('WC') || !WC()->cart) wp_send_json(['ok'=>false]);
    $items = []; $total = 0;
    foreach (WC()->cart->get_cart() as $key => $item) {
        $product = $item['data'];
        $line = $item['line_total'] + ($item['line_tax'] ?? 0);
        $total += $line;
        $items[] = [
            'key'=>$key, 'product_id'=>$item['product_id'],
            'name'=>$product->get_name(), 'quantity'=>$item['quantity'],
            'price'=>round($line/max($item['quantity'],1),2),
            'line_total'=>round($line,2),
        ];
    }
    wp_send_json(['ok'=>true,'items'=>$items,'count'=>WC()->cart->get_cart_contents_count(),
        'total'=>round($total,2),'cart_url'=>wc_get_cart_url(),'checkout_url'=>wc_get_checkout_url()]);
}

add_action('wp_ajax_tix_bot_add_to_cart', 'tix_ajax_add_to_cart');
add_action('wp_ajax_nopriv_tix_bot_add_to_cart', 'tix_ajax_add_to_cart');
function tix_ajax_add_to_cart() {
    if (!function_exists('WC') || !WC()->cart) wp_send_json(['ok'=>false]);
    $pid = absint($_POST['product_id']??0);
    $qty = absint($_POST['quantity']??1);
    if (!$pid||!$qty) wp_send_json(['ok'=>false]);
    $product = wc_get_product($pid);
    if (!$product||!$product->is_purchasable()) wp_send_json(['ok'=>false,'error'=>'N/A']);
    $r = WC()->cart->add_to_cart($pid, $qty);
    if ($r) tix_ajax_get_cart(); else wp_send_json(['ok'=>false]);
}

add_action('wp_ajax_tix_bot_add_batch', 'tix_ajax_add_batch');
add_action('wp_ajax_nopriv_tix_bot_add_batch', 'tix_ajax_add_batch');
function tix_ajax_add_batch() {
    if (!function_exists('WC') || !WC()->cart) wp_send_json(['ok'=>false]);
    $items = json_decode(stripslashes($_POST['items']??''),true);
    if (!is_array($items)) wp_send_json(['ok'=>false]);
    foreach ($items as $item) {
        $pid=absint($item['product_id']??0); $qty=absint($item['quantity']??1);
        if (!$pid||!$qty) continue;
        $product=wc_get_product($pid); if (!$product||!$product->is_purchasable()) continue;
        WC()->cart->add_to_cart($pid,$qty);
    }
    tix_ajax_get_cart();
}

add_action('wp_ajax_tix_bot_remove_from_cart', 'tix_ajax_remove_from_cart');
add_action('wp_ajax_nopriv_tix_bot_remove_from_cart', 'tix_ajax_remove_from_cart');
function tix_ajax_remove_from_cart() {
    if (!function_exists('WC') || !WC()->cart) wp_send_json(['ok'=>false]);
    $pid = absint($_POST['product_id']??0);
    if (!$pid) wp_send_json(['ok'=>false]);
    foreach (WC()->cart->get_cart() as $key => $item) {
        if ($item['product_id'] == $pid) {
            WC()->cart->remove_cart_item($key);
        }
    }
    tix_ajax_get_cart();
}

add_action('wp_ajax_tix_bot_clear_cart', 'tix_ajax_clear_cart');
add_action('wp_ajax_nopriv_tix_bot_clear_cart', 'tix_ajax_clear_cart');
function tix_ajax_clear_cart() {
    if (!function_exists('WC') || !WC()->cart) wp_send_json(['ok'=>false]);
    WC()->cart->empty_cart();
    tix_ajax_get_cart();
}


// ============================================================
// CSS
// ============================================================
function tix_css($c, $height = '700px') {
    return '
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");

.tix-chat{font-family:"Inter",-apple-system,BlinkMacSystemFont,sans-serif;background:'.$c['bg'].';border-radius:24px;overflow:hidden;display:flex;flex-direction:column;height:'.$height.';max-height:85vh;min-height:400px;position:relative;box-shadow:0 4px 30px rgba(0,0,0,.08)}
.tix-chat *{margin:0;padding:0;box-sizing:border-box}
.tix-chat.fullscreen{position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100%!important;height:100%!important;max-height:100%!important;border-radius:0!important;z-index:100000!important;box-shadow:none!important}

.tix-hdr{padding:18px 22px;background:'.$c['bg_header'].';border-bottom:1px solid '.$c['border'].';display:flex;align-items:center;gap:14px;flex-shrink:0}
.tix-av{width:44px;height:44px;border-radius:50%;background:transparent;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:'.$c['text'].';flex-shrink:0;overflow:hidden}
.tix-av img{width:100%;height:100%;border-radius:50%;object-fit:cover}
.tix-hi{flex:1}
.tix-hn{font-size:16px;font-weight:700;color:'.$c['text'].';letter-spacing:-.02em}
.tix-hs{font-size:12px;color:'.$c['accent'].';font-weight:500;display:flex;align-items:center;gap:5px;margin-top:1px}
.tix-hs::before{content:"";width:7px;height:7px;border-radius:50%;background:'.$c['accent'].';display:inline-block}
.tix-hx,.tix-hfs,.tix-hreset{width:34px;height:34px;border-radius:50%;border:none;background:'.$c['bg'].';color:'.$c['text_muted'].';cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px;transition:all .2s;font-weight:300;flex-shrink:0}
.tix-hx:hover,.tix-hfs:hover,.tix-hreset:hover{background:'.$c['border'].';color:'.$c['text'].'}
.tix-hfs svg,.tix-hreset svg{width:16px;height:16px;fill:currentColor}

.tix-wel{padding:20px;flex-shrink:0;animation:tixFade .5s ease}
@keyframes tixFade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.tix-wel-card{background:'.$c['bg_card'].';border-radius:20px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.tix-wel-t{font-size:18px;font-weight:700;color:'.$c['text'].';margin-bottom:6px}
.tix-wel-s{font-size:13px;color:'.$c['text_muted'].';line-height:1.5;margin-bottom:16px}
.tix-beta{font-size:11px;color:'.$c['text_muted'].';opacity:.7;text-align:center;padding:8px 16px;line-height:1.4;font-style:italic}
.tix-badge-beta{display:inline-block;font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;background:'.$c['accent'].';color:#fff;padding:2px 6px;border-radius:6px;margin-left:6px;vertical-align:middle;line-height:1.2}

/* Mode selection buttons */
.tix-mode-btns{display:flex;flex-direction:column;gap:10px}
.tix-mode-btn{display:flex;align-items:center;gap:14px;padding:16px 18px;border-radius:16px;border:1.5px solid '.$c['border'].';background:'.$c['bg_card'].';cursor:pointer;transition:all .2s;text-align:left;font-family:"Inter",sans-serif;width:100%}
.tix-mode-btn:hover{border-color:'.$c['accent'].';transform:translateY(-1px);box-shadow:0 4px 12px '.$c['accent_bg'].'}
.tix-mode-btn:active{transform:translateY(0)}
.tix-mode-ico{font-size:28px;flex-shrink:0}
.tix-mode-t{font-size:14px;font-weight:700;color:'.$c['text'].';display:block}
.tix-mode-s{font-size:11.5px;color:'.$c['text_muted'].';display:block;margin-top:2px}
.tix-mode-btn.selected{border-color:'.$c['accent'].';background:'.$c['accent_bg'].'}

.tix-msgs{flex:1;overflow-y:auto;padding:10px 20px 20px;display:flex;flex-direction:column;gap:8px;scrollbar-width:thin;scrollbar-color:'.$c['border'].' transparent}
.tix-msgs::-webkit-scrollbar{width:4px}
.tix-msgs::-webkit-scrollbar-thumb{background:'.$c['border'].';border-radius:4px}

.tix-m{max-width:80%;animation:tixIn .3s cubic-bezier(.34,1.4,.64,1)}
@keyframes tixIn{from{opacity:0;transform:translateY(6px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
.tix-m.b{align-self:flex-start}
.tix-m.u{align-self:flex-end}
.tix-mb{padding:12px 16px;font-size:13.5px;line-height:1.6;white-space:pre-wrap;word-break:break-word}
.tix-m.b .tix-mb{background:'.$c['bg_card'].';color:'.$c['text'].';border-radius:18px 18px 18px 6px;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.tix-m.u .tix-mb{background:'.$c['user_bubble'].';color:'.$c['user_text'].';border-radius:18px 18px 6px 18px}
.tix-mt{font-size:10px;color:'.$c['text_light'].';margin-top:3px;padding:0 6px}
.tix-m.u .tix-mt{text-align:right}

.tix-tp{display:flex;gap:4px;padding:14px 18px;background:'.$c['bg_card'].';border-radius:18px 18px 18px 6px;width:fit-content;animation:tixIn .25s ease;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.tix-tp span{width:6px;height:6px;background:'.$c['accent'].';border-radius:50%;animation:tixDot 1.3s ease-in-out infinite;opacity:.4}
.tix-tp span:nth-child(2){animation-delay:.15s}
.tix-tp span:nth-child(3){animation-delay:.3s}
@keyframes tixDot{0%,70%,100%{transform:translateY(0);opacity:.3}35%{transform:translateY(-5px);opacity:1}}

.tix-kb{display:flex;flex-wrap:wrap;gap:6px;padding:6px 0;animation:tixIn .3s ease .08s both}
.tix-kbb{padding:8px 15px;border-radius:30px;border:1.5px solid '.$c['border'].';background:'.$c['bg_card'].';color:'.$c['text'].';font-size:12.5px;font-weight:500;font-family:"Inter",sans-serif;cursor:pointer;transition:all .2s;white-space:nowrap}
.tix-kbb:hover{border-color:'.$c['accent'].';color:'.$c['accent'].';transform:translateY(-1px)}
.tix-kbb.sel{background:'.$c['accent'].';border-color:'.$c['accent'].';color:#fff}
.tix-kbb.q{min-width:65px;text-align:center;font-weight:600}
.tix-kbl{font-size:11px;color:'.$c['text_muted'].';margin-bottom:5px;font-weight:500}
.tix-kbs{font-size:10px;color:'.$c['text_muted'].';margin-left:3px}

/* Callback/Contact buttons */
.tix-cb{display:flex;flex-direction:column;gap:8px;padding:8px 0;animation:tixIn .3s ease .08s both}
.tix-cbb{padding:12px 18px;border-radius:14px;border:1.5px solid '.$c['border'].';background:'.$c['bg_card'].';color:'.$c['text'].';font-size:13px;font-weight:500;font-family:"Inter",sans-serif;cursor:pointer;transition:all .2s;text-align:left;display:flex;align-items:center;gap:10px}
.tix-cbb:hover{border-color:'.$c['accent'].';color:'.$c['accent'].';transform:translateY(-1px)}

.tix-ct{padding:12px 22px;background:'.$c['bg_header'].';border-top:1px solid '.$c['border'].';display:none;align-items:center;gap:12px;flex-shrink:0}
.tix-ct.active{display:flex}
.tix-ct-ico{font-size:18px}
.tix-ct-nfo{flex:1}
.tix-ct-cnt{font-size:13px;color:'.$c['text'].';font-weight:600}
.tix-ct-sub{font-size:11px;color:'.$c['text_light'].'}
.tix-ct-tot{font-size:14px;color:'.$c['cart_accent'].';font-weight:700;letter-spacing:-.02em}
.tix-ct-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:30px;background:'.$c['accent'].' !important;border:none;color:#fff !important;font-size:12px;font-weight:700;cursor:pointer;font-family:"Inter",sans-serif;transition:all .2s;text-decoration:none !important}
.tix-ct-btn:hover{background:'.$c['accent_hover'].' !important;color:#fff !important;transform:translateY(-1px);text-decoration:none !important}
.tix-ct-btn:visited,.tix-ct-btn:active,.tix-ct-btn:focus{color:#fff !important;text-decoration:none !important}

.tix-ia{padding:16px 20px;background:'.$c['bg_input'].';border-top:1px solid '.$c['border'].';display:flex;gap:10px;align-items:center;flex-shrink:0}
.tix-in{flex:1;padding:12px 18px;border-radius:30px;border:1.5px solid '.$c['border'].';background:'.$c['bg_input_field'].';color:'.$c['text'].';font-size:13.5px;font-family:"Inter",sans-serif;outline:none;transition:all .2s}
.tix-in::placeholder{color:'.$c['text_light'].'}
.tix-in:focus{border-color:'.$c['accent'].';background:#fff;box-shadow:0 0 0 3px '.$c['accent_bg'].'}
.tix-snd{width:44px;height:44px;border-radius:50%;background:'.$c['accent'].';border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0}
.tix-snd:hover{background:'.$c['accent_hover'].';transform:translateY(-1px)}
.tix-snd:disabled{opacity:.3;cursor:not-allowed;transform:none}
.tix-snd svg{width:18px;height:18px;fill:#fff;margin-left:2px}

@media(max-width:640px){.tix-chat{border-radius:0;height:100vh;max-height:100vh}.tix-hdr{padding:14px 16px}.tix-msgs{padding:8px 14px}.tix-ia{padding:12px 14px}.tix-wel{padding:14px}.tix-hfs{display:none}}
';
}


// ============================================================
// JS
// ============================================================
function tix_js($api, $pfx, $ids, $ajax_url, $wp_user = []) {
    $user_json = !empty($wp_user) ? json_encode($wp_user) : 'null';
    return '
const '.$pfx.'=(()=>{
const API="'.esc_js($api).'";
const AJAX="'.esc_js($ajax_url).'";
const WP_USER='.$user_json.';
const STORE_KEY="tix_history_"+window.location.hostname;
let chatId=null,ld=false,started=false,pollTimer=null,lastWcCart=null;
const $=id=>document.getElementById(id);

function saveHistory(){
  const box=$("'.$ids['msgs'].'");if(!box)return;
  const entries=[];
  box.querySelectorAll(".tix-m").forEach(el=>{entries.push({cls:el.className,html:el.innerHTML})});
  try{localStorage.setItem(STORE_KEY,JSON.stringify({chatId,started,entries:entries.slice(-100)}))}catch(e){}
}
function loadHistory(){
  try{
    const raw=localStorage.getItem(STORE_KEY);if(!raw)return false;
    const data=JSON.parse(raw);if(!data.entries||!data.entries.length)return false;
    if(data.chatId)chatId=data.chatId;
    if(data.started){started=true;const w=$("'.$ids['welcome'].'");if(w){w.style.display="none"}}
    const box=$("'.$ids['msgs'].'");
    data.entries.forEach(e=>{const el=document.createElement("div");el.className=e.cls;el.innerHTML=e.html;el.style.animation="none";box.appendChild(el)});
    requestAnimationFrame(()=>box.scrollTop=box.scrollHeight);
    return true;
  }catch(e){return false}
}

function pollWcCart(){
  fetch(AJAX+"?action=tix_bot_get_cart",{credentials:"same-origin"})
    .then(r=>r.json()).then(d=>{if(d.ok)renderWcCart(d)}).catch(()=>{});
}
function renderWcCart(d){
  lastWcCart=d;const bar=$("'.$ids['cart'].'");
  if(d.count>0){bar.classList.add("active");
    $("'.$ids['cart_count'].'").textContent=d.count+" Ticket"+(d.count>1?"s":"");
    $("'.$ids['cart_total'].'").textContent=d.total.toFixed(2).replace(".",",")+"\u20AC";
    $("'.$ids['cart_btn'].'").href=d.checkout_url;
  }else bar.classList.remove("active");
}
function addToWcCart(pid,qty){
  const fd=new FormData();fd.append("action","tix_bot_add_to_cart");fd.append("product_id",pid);fd.append("quantity",qty);
  return fetch(AJAX,{method:"POST",body:fd,credentials:"same-origin"}).then(r=>r.json()).then(d=>{if(d.ok)renderWcCart(d);return d}).catch(()=>({ok:false}));
}
function removeFromWcCart(pid){
  const fd=new FormData();fd.append("action","tix_bot_remove_from_cart");fd.append("product_id",pid);
  return fetch(AJAX,{method:"POST",body:fd,credentials:"same-origin"}).then(r=>r.json()).then(d=>{if(d.ok)renderWcCart(d);return d}).catch(()=>({ok:false}));
}
function clearWcCart(){
  const fd=new FormData();fd.append("action","tix_bot_clear_cart");
  return fetch(AJAX,{method:"POST",body:fd,credentials:"same-origin"}).then(r=>r.json()).then(d=>{if(d.ok)renderWcCart(d);return d}).catch(()=>({ok:false}));
}
async function processWcActions(actions){
  if(!actions||!actions.length){pollWcCart();return}
  for(const a of actions){
    if(a.action==="add")await addToWcCart(a.product_id,a.quantity);
    else if(a.action==="remove")await removeFromWcCart(a.product_id);
    else if(a.action==="clear")await clearWcCart();
  }
}

(async function(){
  const hadHistory=loadHistory();
  const vid=localStorage.getItem("tix_visitor")||(()=>{const id=Date.now().toString(36)+Math.random().toString(36);localStorage.setItem("tix_visitor",id);return id})();
  if(!chatId){try{const initData={visitor_id:vid};if(WP_USER){initData.wp_user_id=WP_USER.id;initData.wp_display_name=WP_USER.name;initData.wp_email=WP_USER.email;initData.wp_username=WP_USER.login;initData.customer_name=WP_USER.name}const r=await fetch(API+"/chat/init",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(initData)});const d=await r.json();if(d.ok)chatId=d.chat_id;saveHistory()}catch(e){}}
  pollWcCart();pollTimer=setInterval(pollWcCart,15000);
})();

function hw(){if(!started){started=true;const w=$("'.$ids['welcome'].'");if(w){w.style.transition="all .3s";w.style.opacity="0";w.style.maxHeight="0";w.style.padding="0";w.style.overflow="hidden";w.style.display="none"}saveHistory()}}
function showWelcome(){const w=$("'.$ids['welcome'].'");if(w){w.style.transition="all .3s";w.style.opacity="1";w.style.maxHeight="";w.style.padding="";w.style.overflow="";w.style.display="";w.querySelectorAll(".tix-mode-btn").forEach(b=>{b.classList.remove("selected");b.style.opacity="";b.style.pointerEvents=""})}}
async function resetChat(){started=false;chatId=null;const box=$("'.$ids['msgs'].'");if(box)box.innerHTML="";clearHistory();showWelcome();
  try{const vid=localStorage.getItem("tix_visitor")||Date.now().toString(36);const initData={visitor_id:vid};if(WP_USER){initData.wp_user_id=WP_USER.id;initData.wp_display_name=WP_USER.name;initData.wp_email=WP_USER.email;initData.wp_username=WP_USER.login;initData.customer_name=WP_USER.name}const r=await fetch(API+"/chat/init",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(initData)});const d=await r.json();if(d.ok)chatId=d.chat_id}catch(e){}
}

async function send(t){
  const msg=t||$("'.$ids['input'].'").value.trim();if(!msg||!chatId||ld)return;if(!t)$("'.$ids['input'].'").value="";
  hw();addMsg(msg,"u");showT();ld=true;$("'.$ids['send'].'").disabled=true;
  try{const payload={chat_id:chatId,message:msg};if(lastWcCart&&lastWcCart.items)payload.wc_cart=lastWcCart.items;
  const r=await fetch(API+"/chat/send",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(payload)});const d=await r.json();hideT();
  if(d.ok){addMsg(d.text,"b");if(d.keyboards&&d.keyboards.length)rKB(d.keyboards);
    await processWcActions(d.wc_actions);
    if(d.checkout_url){const bar=$("'.$ids['cart'].'");if(bar)bar.classList.add("active");$("'.$ids['cart_btn'].'").href=d.checkout_url}
  }else addMsg("Fehler – nochmal versuchen! 🔄","b")}
  catch(e){hideT();addMsg("Verbindungsfehler.","b")}ld=false;$("'.$ids['send'].'").disabled=false;$("'.$ids['input'].'").focus();
}

async function sAct(cb){
  if(!chatId)return;showT();
  try{const r=await fetch(API+"/chat/action",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({chat_id:chatId,callback:cb})});const d=await r.json();hideT();
  if(d.ok){if(d.text)addMsg(d.text,"b");if(d.keyboards&&d.keyboards.length)rKB(d.keyboards);
    await processWcActions(d.wc_actions);
    if(d.checkout_url){$("'.$ids['cart_btn'].'").href=d.checkout_url}
  }}catch(e){hideT()}
}

function addMsg(text,type){
  const box=$("'.$ids['msgs'].'"),now=new Date(),time=String(now.getHours()).padStart(2,"0")+":"+String(now.getMinutes()).padStart(2,"0");
  let html=text.replace(/\\*\\*(.*?)\\*\\*/g,"<strong>$1</strong>").replace(/\\*(.*?)\\*/g,"<strong>$1</strong>");
  html=html.replace(/\\[([^\\]]+)\\]\\(([^)]+)\\)/g,\'<a href="$2" target="_blank" style="color:inherit;text-decoration:underline">$1</a>\');
  const el=document.createElement("div");el.className="tix-m "+type;
  el.innerHTML=\'<div class="tix-mb">\'+html+\'</div><div class="tix-mt">\'+time+\'</div>\';
  box.appendChild(el);requestAnimationFrame(()=>box.scrollTop=box.scrollHeight);saveHistory();
}
function showT(){const box=$("'.$ids['msgs'].'"),el=document.createElement("div");el.className="tix-m b";el.id="'.$ids['typing'].'";el.innerHTML=\'<div class="tix-tp"><span></span><span></span><span></span></div>\';box.appendChild(el);box.scrollTop=box.scrollHeight}
function hideT(){const el=$("'.$ids['typing'].'");if(el)el.remove()}

function rKB(kbs){
  const box=$("'.$ids['msgs'].'");
  kbs.forEach(kb=>{const w=document.createElement("div");w.className="tix-m b";
  if(kb.type==="categories"||kb.type==="events"){
    let h=\'<div class="tix-kb">\';
    kb.buttons.forEach(b=>{
      h+=\'<button class="tix-kbb" onclick="'.$pfx.'.sAct(\\\'\'+b.callback_data+\'\\\')">\'+b.text;
      if(b.sublabel)h+=\'<span class="tix-kbs">\'+b.sublabel+\'</span>\';
      h+=\'</button>\';
    });
    h+=\'</div>\';w.innerHTML=h;
  }
  else if(kb.type==="quantities"){
    let h=\'<div class="tix-kbl">\'+kb.label+\' (\'+kb.price+\')</div><div class="tix-kb">\';
    kb.buttons.forEach(b=>{
      let label=b.text+(b.sublabel?" ("+b.sublabel+")":"");
      h+=\'<button class="tix-kbb q" onclick="'.$pfx.'.sAct(\\\'\'+b.callback_data+\'\\\')">\'+label+\'</button>\';
    });
    h+=\'<button class="tix-kbb q" style="border-style:dashed;opacity:.6" onclick="'.$pfx.'.cQty(\\\'\'+kb.product_id+\'\\\')">\u270F\uFE0F</button></div>\';
    w.innerHTML=h;
  }
  else if(kb.type==="mode_choice"){
    let h=\'<div class="tix-kb">\';
    kb.buttons.forEach(b=>{h+=\'<button class="tix-kbb" onclick="'.$pfx.'.sAct(\\\'\'+b.callback_data+\'\\\')">\'+b.text+\'</button>\'});
    h+=\'</div>\';w.innerHTML=h;
  }
  else if(kb.type==="login_options"){
    let h=\'<div class="tix-cb">\';
    kb.buttons.forEach(b=>{h+=\'<button class="tix-cbb" onclick="'.$pfx.'.sAct(\\\'\'+b.callback_data+\'\\\');">\'+b.text+\'</button>\'});
    h+=\'</div>\';w.innerHTML=h;
  }
  else if(kb.type==="callback"){
    let h=\'<div class="tix-cb">\';
    kb.buttons.forEach(b=>{h+=\'<button class="tix-cbb" onclick="'.$pfx.'.sAct(\\\'\'+b.callback_data+\'\\\');">\'+b.text+\'</button>\'});
    h+=\'</div>\';w.innerHTML=h;
  }
  box.appendChild(w);requestAnimationFrame(()=>box.scrollTop=box.scrollHeight)});saveHistory();
}

function cQty(pid){const q=prompt("Wie viele Tickets?");if(q&&!isNaN(parseInt(q))){sAct("custom_"+pid);setTimeout(()=>send(q.toString()),600)}}

function toggleFs(){
  const btn=$("'.$ids['fs'].'");if(!btn)return;
  const chat=btn.closest(".tix-chat");if(!chat)return;
  const win=chat.closest("#tix-window");
  if(win){win.classList.toggle("tix-fs");chat.classList.toggle("fullscreen")}
  else{chat.classList.toggle("fullscreen")}
  const isFs=chat.classList.contains("fullscreen");
  btn.innerHTML=isFs
    ?\'<svg viewBox="0 0 24 24"><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>\'
    :\'<svg viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>\';
}

function checkout(){const btn=$("'.$ids['cart_btn'].'");if(btn&&btn.href)window.location.href=btn.href}
function clearHistory(){try{localStorage.removeItem(STORE_KEY)}catch(e){}}

async function setMode(mode, btn){
  if(btn){btn.classList.add("selected");btn.closest(".tix-mode-btns").querySelectorAll(".tix-mode-btn").forEach(b=>{if(b!==btn){b.style.opacity="0.4";b.style.pointerEvents="none"}})}
  hw();
  const cb=mode==="order"?"mode_order":mode==="tickets"?"mode_tickets":"mode_support";
  if(!chatId){showWelcome();addMsg("Verbindungsfehler – bitte neu laden.","b");return}
  showT();
  try{
    const r=await fetch(API+"/chat/action",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({chat_id:chatId,callback:cb})});const d=await r.json();hideT();
    if(d.ok){if(d.text)addMsg(d.text,"b");if(d.keyboards&&d.keyboards.length)rKB(d.keyboards);await processWcActions(d.wc_actions);if(d.checkout_url){$("'.$ids['cart_btn'].'").href=d.checkout_url}}
    else{showWelcome();started=false;addMsg("Modus konnte nicht gewechselt werden. Bitte erneut versuchen.","b")}
  }catch(e){hideT();showWelcome();started=false;addMsg("Verbindungsfehler – bitte erneut versuchen.","b")}
}

return{send,quickSend:t=>{$("'.$ids['input'].'").value=t;send()},sAct,cQty,checkout,clearHistory,pollWcCart,toggleFs,setMode,resetChat};
})();
';
}


// ============================================================
// FULLSCREEN BUTTON SVG
// ============================================================
function tix_fs_btn_html($id) {
    return '<button class="tix-hfs" id="'.$id.'" onclick="'
        . ($id === 'txe-fs' ? 'TXE' : 'TXW')
        . '.toggleFs()" title="Vollbild"><svg viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg></button>';
}


// ============================================================
// [tix_chat] - Embedded
// ============================================================
function tix_embed_shortcode($atts) {
    global $TIX_COLORS;
    $atts = shortcode_atts(['height' => '700px'], $atts);
    $api = TIX_CHAT_API;
    $logo = TIX_CHAT_LOGO;
    $ajax_url = admin_url('admin-ajax.php');
    $ids = ['wrap'=>'txe-wrap','msgs'=>'txe-m','input'=>'txe-i','send'=>'txe-s','cart'=>'txe-c',
            'cart_count'=>'txe-cc','cart_total'=>'txe-ct','cart_btn'=>'txe-cb','welcome'=>'txe-w',
            'typing'=>'txe-t','fs'=>'txe-fs'];

    ob_start();
    echo '<style>' . tix_css($TIX_COLORS, $atts['height']) . '</style>';
    ?>
<div id="txe-wrap">
<div class="tix-chat">
  <div class="tix-hdr">
    <div class="tix-av"><img src="<?php echo esc_url($logo); ?>" alt="TX" onerror="this.remove();this.parentNode.textContent='🎫'"></div>
    <div class="tix-hi"><div class="tix-hn">Tixomat<?php if (TIX_CHAT_BETA): ?><span class="tix-badge-beta">Beta</span><?php endif; ?></div><div class="tix-hs">Online</div></div>
    <button class="tix-hreset" onclick="TXE.resetChat()" title="Neue Sitzung"><svg viewBox="0 0 24 24"><path d="M17.65 6.35A7.958 7.958 0 0012 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08A5.99 5.99 0 0112 18c-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg></button>
    <?php echo tix_fs_btn_html('txe-fs'); ?>
  </div>
  <div class="tix-wel" id="txe-w">
    <div class="tix-wel-card">
      <div class="tix-wel-t">Hey! 👋</div>
      <div class="tix-wel-s">Willkommen bei Tixomat! Wie kann ich dir helfen?</div>
      <?php if (TIX_CHAT_BETA): ?><div class="tix-beta">* Dieser Bot befindet sich in einer Testphase.</div><?php endif; ?>
      <div class="tix-mode-btns">
        <button class="tix-mode-btn" onclick="TXE.setMode('order',this)">
          <span class="tix-mode-ico">🎫</span>
          <div><span class="tix-mode-t">Tickets kaufen</span><span class="tix-mode-s">Events entdecken &amp; Tickets buchen</span></div>
        </button>
        <button class="tix-mode-btn" onclick="TXE.setMode('tickets',this)">
          <span class="tix-mode-ico">🔍</span>
          <div><span class="tix-mode-t">Meine Tickets</span><span class="tix-mode-s">Gekaufte Tickets finden &amp; herunterladen</span></div>
        </button>
        <button class="tix-mode-btn" onclick="TXE.setMode('support',this)">
          <span class="tix-mode-ico">🎧</span>
          <div><span class="tix-mode-t">Kundenservice</span><span class="tix-mode-s">Fragen, Stornierungen &amp; mehr</span></div>
        </button>
      </div>
    </div>
  </div>
  <div class="tix-msgs" id="txe-m"></div>
  <div class="tix-ct" id="txe-c">
    <span class="tix-ct-ico">🎫</span>
    <div class="tix-ct-nfo"><div class="tix-ct-cnt" id="txe-cc">0 Tickets</div><div class="tix-ct-sub">inkl. MwSt.</div></div>
    <div class="tix-ct-tot" id="txe-ct">0,00€</div>
    <a class="tix-ct-btn" id="txe-cb" href="<?php echo function_exists('wc_get_checkout_url') ? esc_url(wc_get_checkout_url()) : '#'; ?>">Jetzt buchen →</a>
  </div>
  <div class="tix-ia">
    <input class="tix-in" id="txe-i" placeholder="Welches Event interessiert dich? ..." onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();TXE.send()}" autocomplete="off">
    <button class="tix-snd" id="txe-s" onclick="TXE.send()"><svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="white"/></svg></button>
  </div>
  <script><?php
    $wp_user_data = [];
    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        $wp_user_data = ['id'=>$u->ID,'name'=>$u->display_name,'email'=>$u->user_email,'login'=>$u->user_login];
    }
    echo tix_js($api, 'TXE', $ids, $ajax_url, $wp_user_data);
  ?></script>
</div>
</div>
    <?php
    return ob_get_clean();
}
add_shortcode('tix_chat', 'tix_embed_shortcode');


// ============================================================
// [tix_chat_widget] - Floating Widget
// ============================================================
function tix_widget_shortcode($atts) {
    global $TIX_COLORS;
    $c = $TIX_COLORS;
    $api = TIX_CHAT_API;
    $logo = TIX_CHAT_LOGO;
    $ajax_url = admin_url('admin-ajax.php');
    $ids = ['wrap'=>'txw-wrap','msgs'=>'txw-m','input'=>'txw-i','send'=>'txw-s','cart'=>'txw-c',
            'cart_count'=>'txw-cc','cart_total'=>'txw-ct','cart_btn'=>'txw-cb','welcome'=>'txw-w',
            'typing'=>'txw-t','fs'=>'txw-fs'];

    ob_start();
    echo '<style>' . tix_css($c, '560px');
    ?>

#tix-launcher{position:fixed;bottom:24px;right:24px;width:60px;height:60px;border-radius:50%;background:<?php echo $c['accent'];?>;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px <?php echo $c['accent_shadow'];?>;transition:all .3s cubic-bezier(.34,1.56,.64,1);z-index:99998}
#tix-launcher:hover{transform:scale(1.08);background:<?php echo $c['accent_hover'];?>}
#tix-launcher.open{transform:rotate(45deg) scale(.9)}
#tix-launcher svg{width:26px;height:26px;fill:#fff}
#tix-window{position:fixed!important;bottom:96px;right:24px;width:400px;max-width:calc(100vw - 32px);z-index:99999;opacity:0;transform:translateY(16px) scale(.96);pointer-events:none;transition:all .3s cubic-bezier(.34,1.56,.64,1);display:flex;flex-direction:column;justify-content:flex-end}
#tix-window.visible{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}
#tix-window .tix-chat{height:660px;max-height:calc(100vh - 130px);box-shadow:0 8px 40px rgba(0,0,0,.12)}
#tix-window .tix-chat.fullscreen{max-height:100%}
#tix-window.tix-fs{top:0!important;left:0!important;right:0!important;bottom:0!important;width:100%!important;max-width:100%!important;border-radius:0}
#tix-window.tix-fs .tix-chat{height:100%!important;max-height:100%!important;border-radius:0!important}
@media(max-width:480px){
  #tix-window{position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100%!important;max-width:100%!important;transform:none!important}
  #tix-window.visible{transform:none!important}
  #tix-window .tix-chat{height:100%!important;max-height:100%!important;border-radius:0!important}
  #tix-launcher{bottom:16px;right:16px}
  #tix-window .tix-hfs{display:none}
}
</style>

<button id="tix-launcher" onclick="TXW.toggle()">
  <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"/><path d="M7 9h2v2H7zm4 0h2v2h-2zm4 0h2v2h-2z"/></svg>
</button>
<div id="tix-window">
<div class="tix-chat">
  <div class="tix-hdr">
    <div class="tix-av"><img src="<?php echo esc_url($logo); ?>" alt="TX" onerror="this.remove();this.parentNode.textContent='🎫'"></div>
    <div class="tix-hi"><div class="tix-hn">Tixomat<?php if (TIX_CHAT_BETA): ?><span class="tix-badge-beta">Beta</span><?php endif; ?></div><div class="tix-hs">Online</div></div>
    <button class="tix-hreset" onclick="TXW.resetChat()" title="Neue Sitzung"><svg viewBox="0 0 24 24"><path d="M17.65 6.35A7.958 7.958 0 0012 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08A5.99 5.99 0 0112 18c-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg></button>
    <?php echo tix_fs_btn_html('txw-fs'); ?>
    <button class="tix-hx" onclick="TXW.toggle()">✕</button>
  </div>
  <div class="tix-wel" id="txw-w">
    <div class="tix-wel-card">
      <div class="tix-wel-t">Hey! 👋</div>
      <div class="tix-wel-s">Willkommen bei Tixomat! Wie kann ich dir helfen?</div>
      <?php if (TIX_CHAT_BETA): ?><div class="tix-beta">* Dieser Bot befindet sich in einer Testphase.</div><?php endif; ?>
      <div class="tix-mode-btns">
        <button class="tix-mode-btn" onclick="TXW.setMode('order',this)">
          <span class="tix-mode-ico">🎫</span>
          <div><span class="tix-mode-t">Tickets kaufen</span><span class="tix-mode-s">Events entdecken &amp; Tickets buchen</span></div>
        </button>
        <button class="tix-mode-btn" onclick="TXW.setMode('tickets',this)">
          <span class="tix-mode-ico">🔍</span>
          <div><span class="tix-mode-t">Meine Tickets</span><span class="tix-mode-s">Gekaufte Tickets finden &amp; herunterladen</span></div>
        </button>
        <button class="tix-mode-btn" onclick="TXW.setMode('support',this)">
          <span class="tix-mode-ico">🎧</span>
          <div><span class="tix-mode-t">Kundenservice</span><span class="tix-mode-s">Fragen, Stornierungen &amp; mehr</span></div>
        </button>
      </div>
    </div>
  </div>
  <div class="tix-msgs" id="txw-m"></div>
  <div class="tix-ct" id="txw-c">
    <span class="tix-ct-ico">🎫</span>
    <div class="tix-ct-nfo"><div class="tix-ct-cnt" id="txw-cc">0 Tickets</div><div class="tix-ct-sub">inkl. MwSt.</div></div>
    <div class="tix-ct-tot" id="txw-ct">0,00€</div>
    <a class="tix-ct-btn" id="txw-cb" href="<?php echo function_exists('wc_get_checkout_url') ? esc_url(wc_get_checkout_url()) : '#'; ?>">Jetzt buchen →</a>
  </div>
  <div class="tix-ia">
    <input class="tix-in" id="txw-i" placeholder="Welches Event interessiert dich? ..." onkeydown="if(event.key==='Enter')TXW.send()" autocomplete="off">
    <button class="tix-snd" id="txw-s" onclick="TXW.send()"><svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="white"/></svg></button>
  </div>
  <script>
  <?php
    $wp_user_data = [];
    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        $wp_user_data = ['id'=>$u->ID,'name'=>$u->display_name,'email'=>$u->user_email,'login'=>$u->user_login];
    }
    echo tix_js($api, 'TXW', $ids, $ajax_url, $wp_user_data);
  ?>
  TXW.toggle=function(){
    const w=document.getElementById("tix-window"),l=document.getElementById("tix-launcher");
    const o=w.classList.toggle("visible");l.classList.toggle("open",o);
    if(o)setTimeout(()=>document.getElementById("txw-i").focus(),350);
    if(!o){w.classList.remove("tix-fs");const c=w.querySelector(".tix-chat");if(c)c.classList.remove("fullscreen")}
  };
  </script>
</div>
</div>
    <?php
    return ob_get_clean();
}
add_shortcode('tix_chat_widget', 'tix_widget_shortcode');


// Auto-widget
if (get_option('tix_auto_widget', false)) {
    add_action('wp_footer', function() { if (!is_admin()) echo do_shortcode('[tix_chat_widget]'); });
}
