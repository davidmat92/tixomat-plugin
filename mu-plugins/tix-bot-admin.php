<?php
/**
 * Plugin Name: Tixomat Bot Admin Dashboard
 * Description: Admin-Dashboard für den Tixomat Ticket-Bot
 * Version: 1.0
 */
if (!defined('ABSPATH')) exit;

define('TXBA_API', 'https://tixomat-dpconnect.pythonanywhere.com');
define('TXBA_KEY', 'tixomat_admin_2026_secret');

add_action('admin_menu', function() {
    add_menu_page('Ticket-Bot', 'Ticket-Bot', 'manage_woocommerce', 'tix-bot', 'txba_page_live', 'dashicons-format-chat', 58);
    add_submenu_page('tix-bot', 'Live-Gespräche', '💬 Live', 'manage_woocommerce', 'tix-bot', 'txba_page_live');
    add_submenu_page('tix-bot', 'Statistiken', '📊 Statistiken', 'manage_woocommerce', 'tix-bot-stats', 'txba_page_stats');
    add_submenu_page('tix-bot', 'Suche', '🔍 Suche', 'manage_woocommerce', 'tix-bot-search', 'txba_page_search');
    add_submenu_page('tix-bot', 'Einstellungen', '⚙️ Einstellungen', 'manage_woocommerce', 'tix-bot-settings', 'txba_page_settings');
});

// AJAX Proxy
add_action('wp_ajax_txba_proxy', function() {
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');
    $ep = sanitize_text_field($_POST['endpoint'] ?? '');
    $method = strtoupper(sanitize_text_field($_POST['method'] ?? 'GET'));
    $body = $_POST['body'] ?? '';
    if (!$ep) wp_send_json_error('No endpoint');
    $url = TXBA_API . $ep;
    $args = ['timeout'=>15,'headers'=>['X-Admin-Key'=>TXBA_KEY,'Content-Type'=>'application/json']];
    if ($method==='POST'){$args['body']=is_string($body)?$body:json_encode($body);$r=wp_remote_post($url,$args);}
    else{$r=wp_remote_get($url,$args);}
    if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    wp_send_json(json_decode(wp_remote_retrieve_body($r),true));
});

function txba_css() { ?>
<style>
*{box-sizing:border-box}
.txba{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:1440px}
.txba h1{font-size:20px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:12px}
.txba-g{display:grid;gap:20px;height:calc(100vh - 120px);min-height:600px}
.txba-g2{grid-template-columns:380px 1fr}
.txba-p{background:#fff;border:1px solid #e0e0e0;border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.txba-ph{padding:14px 18px;border-bottom:1px solid #eee;font-weight:600;font-size:14px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.txba-b{background:#FF5500;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:700}
.txba-ph select{margin-left:auto;padding:4px 8px;border:1px solid #ddd;border-radius:6px;font-size:12px}
.btn{padding:6px 14px;border:1px solid #ddd;border-radius:6px;font-size:12px;cursor:pointer;background:#fff;transition:all .15s;font-weight:500}
.btn:hover{border-color:#FF5500;color:#FF5500}
.btn.on{background:#FF5500;color:#fff;border-color:#FF5500}
.s{padding:12px 16px;border-bottom:1px solid #f0f0f0;cursor:pointer;transition:background .1s}
.s:hover{background:#f0f0ff}.s.act{background:#eef2ff;border-left:3px solid #FF5500}
.s-r{display:flex;align-items:center;gap:8px;margin-bottom:3px}
.s-n{font-weight:600;font-size:13px;color:#1a1a2e}
.ch{font-size:10px;padding:1px 5px;border-radius:4px;font-weight:600;text-transform:uppercase}
.ch.web{background:#e3f2fd;color:#1565c0}.ch.telegram{background:#e8f5e9;color:#2e7d32}.ch.whatsapp{background:#e8f5e9;color:#1b5e20}
.tag{font-size:10px;padding:1px 5px;border-radius:4px;font-weight:600;color:#fff}
.tag.hm{background:#ff9800}.tag.ar{background:#9e9e9e}.tag.tk{background:#e53935}
.s-t{margin-left:auto;font-size:11px;color:#999}
.s-p{font-size:12px;color:#666;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.s-m{font-size:11px;color:#999;margin-top:3px;display:flex;gap:12px}
.cv{flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:8px}
.mt{text-align:center;padding:40px;color:#aaa;font-size:14px}
.m{max-width:78%;padding:10px 14px;border-radius:14px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.m.u{align-self:flex-end;background:#FF5500;color:#fff;border-radius:14px 14px 4px 14px}
.m.b{align-self:flex-start;background:#f0f0f0;color:#1a1a2e;border-radius:14px 14px 14px 4px}
.m.a{align-self:flex-start;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe;border-radius:14px 14px 14px 4px}
.m.tk{align-self:center;background:#fce4ec;color:#b71c1c;border:1px solid #ffcdd2;border-radius:10px;font-size:12px;padding:8px 16px;max-width:90%}
.rp{padding:14px 18px;border-top:1px solid #eee;display:none;gap:8px;align-items:center;flex-shrink:0}
.rp.on{display:flex}
.rp input{flex:1;padding:10px 16px;border:1px solid #ddd;border-radius:24px;font-size:13px;outline:none}
.rp input:focus{border-color:#FF5500}
.rp .snd{padding:10px 20px;border:none;border-radius:24px;font-size:12px;font-weight:700;cursor:pointer;background:#FF5500;color:#fff}
.rp .snd:hover{background:#CC4400}
.rp .hb{padding:7px 12px;border:1px solid #ddd;border-radius:6px;font-size:11px;cursor:pointer;background:#fff;font-weight:600}
.rp .hb.on{background:#ff9800;color:#fff;border-color:#ff9800}
.inf{padding:12px 18px;border-bottom:1px solid #eee;font-size:12px;color:#666;display:none;gap:16px;flex-wrap:wrap;flex-shrink:0}
.inf.on{display:flex}.inf strong{color:#1a1a2e}
.cds{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.cd{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:14px;text-align:center}
.cd-n{font-size:26px;font-weight:700;color:#FF5500}.cd-l{font-size:11px;color:#666;margin-top:2px}
.cw{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:18px;margin-bottom:20px}
.tb{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e0e0e0;margin-bottom:20px}
.tb th{background:#f8f8f8;padding:10px 14px;text-align:left;font-size:12px;font-weight:600;border-bottom:1px solid #e0e0e0}
.tb td{padding:8px 14px;font-size:12px;border-bottom:1px solid #f0f0f0}
.tb tr:hover{background:#fafafa}
.sec{margin-bottom:24px}.sec h3{font-size:14px;font-weight:600;margin-bottom:10px;color:#1a1a2e}
.sb{display:flex;gap:10px;margin-bottom:16px}
.sb input{flex:1;padding:12px 20px;border:1px solid #ddd;border-radius:24px;font-size:14px;outline:none}
.sb input:focus{border-color:#FF5500}
.toast-box{position:fixed;top:40px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px}
.toast{background:#1a1a2e;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;box-shadow:0 4px 20px rgba(0,0,0,.2);cursor:pointer;max-width:340px;animation:tin .3s}
@keyframes tin{from{opacity:0;transform:translateX(60px)}to{opacity:1;transform:translateX(0)}}
@media(max-width:1100px){.txba-g2{grid-template-columns:1fr}}
</style>
<?php }

function txba_js() { ?>
<script>
const AX='<?php echo admin_url("admin-ajax.php"); ?>';
let _pq={};
async function api(ep,m='GET',body=null){
  const k=ep+m;if(_pq[k])return _pq[k];
  const fd=new FormData();fd.append('action','txba_proxy');fd.append('endpoint',ep);fd.append('method',m);
  if(body)fd.append('body',JSON.stringify(body));
  _pq[k]=fetch(AX,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).catch(e=>({ok:false})).finally(()=>delete _pq[k]);
  return _pq[k];
}
function E(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML}
function ago(h){return h<1?Math.round(h*60)+'m':h<24?Math.round(h)+'h':Math.round(h/24)+'d'}
function sH(s){
  const n=s.customer_name||s.user_info?.wp_display_name||s.user_info?.tg_first_name||s.chat_id?.slice(0,12)||'?';
  const c=s.channel||'web';const t=s.hours_ago!=null?ago(s.hours_ago):s.last_activity?new Date(s.last_activity).toLocaleDateString('de-DE'):'';
  let tg='';if(s.is_human_mode)tg+='<span class="tag hm">HUMAN</span> ';if(s.is_active===false)tg+='<span class="tag ar">ARCHIV</span> ';
  const lm=s.last_message||'';if(lm.includes('SUPPORT-TICKET'))tg+='<span class="tag tk">TICKET</span> ';
  return `<div class="s-r">${tg}<span class="s-n">${E(n)}</span><span class="ch ${c}">${c}</span><span class="s-t">${t}</span></div>
  <div class="s-p">${E(s.last_message||s.match_reason||'')}</div>
  <div class="s-m"><span>💬 ${s.user_msgs!=null?s.user_msgs+'/'+s.bot_msgs:s.message_count||0}</span>${s.cart_items?'<span>🎫 '+s.cart_items+'</span>':''}${s.user_info?.wp_email?'<span>📧 '+E(s.user_info.wp_email)+'</span>':''}${s.user_info?.tg_username?'<span>@'+E(s.user_info.tg_username)+'</span>':''}</div>`;
}
function rC(el,msgs){
  el.innerHTML='';(msgs||[]).forEach(m=>{const d=document.createElement('div');
  const ia=m.content?.startsWith('[ADMIN]'),it=m.content?.startsWith('[SUPPORT-TICKET]');
  d.className='m '+(it?'tk':m.role==='user'?'u':ia?'a':'b');
  d.textContent=ia?'👤 '+m.content.replace('[ADMIN] ',''):m.content;el.appendChild(d)});
  el.scrollTop=el.scrollHeight;
}
// Notifications
let _nOn=localStorage.getItem('txba_n')==='true',_nTs=new Date().toISOString();
async function ck(){if(!_nOn)return;try{const d=await api('/admin/notifications?since='+encodeURIComponent(_nTs));if(d.ok&&d.messages?.length)d.messages.forEach(toast);if(d.now)_nTs=d.now}catch(e){}}
function toast(msg){
  if(Notification.permission==='granted')new Notification('Tixomat Bot',{body:`${msg.customer_name||'Kunde'}: ${msg.message}`,tag:msg.chat_id});
  let b=document.getElementById('tb');if(!b){b=document.createElement('div');b.id='tb';b.className='toast-box';document.body.appendChild(b)}
  const t=document.createElement('div');t.className='toast';t.textContent=`${msg.customer_name||'Kunde'} (${msg.channel}): ${msg.message}`;
  t.onclick=()=>{t.remove();if(typeof openChat==='function')openChat(msg.chat_id)};b.appendChild(t);setTimeout(()=>t.remove(),8000);
}
function tgN(){_nOn=!_nOn;localStorage.setItem('txba_n',_nOn);if(_nOn&&Notification.permission==='default')Notification.requestPermission();uN()}
function uN(){const b=document.getElementById('nb');if(b){b.textContent=_nOn?'🔔':'🔕';b.classList.toggle('on',_nOn)}}
setInterval(ck,12000);
</script>
<?php }

// ============================================================
// LIVE
// ============================================================
function txba_page_live() { txba_css(); ?>
<div class="wrap txba">
  <h1>🎫 Live-Gespräche <button class="btn" id="nb" onclick="tgN()">🔕</button></h1>
  <div class="txba-g txba-g2">
    <div class="txba-p">
      <div class="txba-ph">Gespräche <span class="txba-b" id="cnt">0</span>
        <select id="cf" onchange="ld()"><option value="">Alle</option><option value="web">Web</option><option value="telegram">TG</option><option value="whatsapp">WA</option></select>
        <button class="btn" onclick="ld()">↻</button>
      </div>
      <div style="flex:1;overflow-y:auto" id="ls"><div class="mt">Laden...</div></div>
    </div>
    <div class="txba-p">
      <div class="txba-ph" id="ch">Gespräch auswählen...</div>
      <div class="inf" id="ci"></div>
      <div class="cv" id="cc"><div class="mt">← Wähle ein Gespräch</div></div>
      <div class="rp" id="rb">
        <button class="hb" id="hb" onclick="tH()">🤖 Bot</button>
        <input id="ri" placeholder="Als Mensch antworten..." onkeydown="if(event.key==='Enter')sR()">
        <button class="snd" onclick="sR()">Senden</button>
      </div>
    </div>
  </div>
</div>
<?php txba_js(); ?>
<script>
let cur=null,hm=false;
async function ld(){
  const f=document.getElementById('cf').value;
  const d=await api('/admin/sessions'+(f?'?channel='+f:''));
  const ls=document.getElementById('ls');
  if(!d.ok){ls.innerHTML='<div class="mt" style="color:#e53935">Verbindungsfehler</div>';return}
  document.getElementById('cnt').textContent=d.total||0;
  ls.innerHTML='';
  if(!d.sessions?.length){ls.innerHTML='<div class="mt">Keine aktiven Gespräche</div>';return}
  d.sessions.forEach(s=>{const el=document.createElement('div');el.className='s'+(cur===s.chat_id?' act':'');el.innerHTML=sH(s);el.onclick=()=>openChat(s.chat_id);ls.appendChild(el)});
}
async function openChat(id){
  cur=id;
  let d=await api('/admin/conversation/'+id);
  if(!d.ok)d=await api('/admin/history/'+id);
  if(!d.ok)return;
  const n=d.customer_name||d.user_info?.wp_display_name||id.slice(0,12);
  document.getElementById('ch').innerHTML=`💬 ${E(n)} <span class="txba-b">${d.channel||'?'}</span>`+(d.is_archived?' <span class="tag ar">ARCHIV</span>':'')+(d.is_human_mode?' <span class="tag hm">HUMAN</span>':'');
  const ci=document.getElementById('ci');let ih=[];
  ih.push(`<strong>ID:</strong> ${id.slice(0,16)}`);
  if(d.user_info?.wp_email)ih.push(`<strong>Email:</strong> ${E(d.user_info.wp_email)}`);
  if(d.user_info?.wp_username)ih.push(`<strong>WP:</strong> ${E(d.user_info.wp_username)}`);
  if(d.user_info?.tg_username)ih.push(`<strong>TG:</strong> @${E(d.user_info.tg_username)}`);
  ih.push(`<strong>Msgs:</strong> ${d.message_count||0}`);ih.push(`<strong>🎫</strong> ${(d.cart||[]).length}`);
  if(d.created_at)ih.push(`<strong>Seit:</strong> ${new Date(d.created_at).toLocaleString('de-DE')}`);
  ci.innerHTML=ih.map(x=>`<span>${x}</span>`).join('');ci.classList.add('on');
  rC(document.getElementById('cc'),d.conversation);
  hm=d.is_human_mode||false;uH();
  document.getElementById('rb').classList.toggle('on',!d.is_archived);
  document.querySelectorAll('.s').forEach(el=>el.classList.remove('act'));
  ld();
}
async function sR(){if(!cur)return;const i=document.getElementById('ri');const m=i.value.trim();if(!m)return;i.value='';await api('/admin/reply','POST',{chat_id:cur,message:m});openChat(cur)}
async function tH(){if(!cur)return;hm=!hm;await api('/admin/reply','POST',{chat_id:cur,human_mode:hm});uH()}
function uH(){const b=document.getElementById('hb');b.textContent=hm?'👤 Human':'🤖 Bot';b.classList.toggle('on',hm)}
ld();uN();
setInterval(()=>{ld();if(cur&&document.hasFocus())openChat(cur)},15000);
</script>
<?php }

// ============================================================
// STATS
// ============================================================
function txba_page_stats() { txba_css(); ?>
<div class="wrap txba">
  <h1>📊 Statistiken
    <select id="dy" onchange="lS()" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px">
      <option value="7">7 Tage</option><option value="14">14 Tage</option><option value="30" selected>30 Tage</option><option value="90">90 Tage</option>
    </select><button class="btn" onclick="lS()">↻</button>
  </h1>
  <div id="so"><div class="mt">Laden...</div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php txba_js(); ?>
<script>
let c1=null,c2=null;
async function lS(){
  const d=await api('/admin/stats?days='+document.getElementById('dy').value);
  const o=document.getElementById('so');
  if(!d.ok){o.innerHTML='<div class="mt" style="color:#e53935">Fehler</div>';return}
  let h=`<div class="cds">
    <div class="cd"><div class="cd-n">${d.active_1h||0}</div><div class="cd-l">Aktiv (1h)</div></div>
    <div class="cd"><div class="cd-n">${d.active_24h||0}</div><div class="cd-l">Aktiv (24h)</div></div>
    <div class="cd"><div class="cd-n">${d.total_sessions||0}</div><div class="cd-l">Live</div></div>
    <div class="cd"><div class="cd-n">${d.total_archived||0}</div><div class="cd-l">Archiviert</div></div>
    <div class="cd"><div class="cd-n">${d.total_messages||0}</div><div class="cd-l">Nachrichten</div></div>
    <div class="cd"><div class="cd-n">${d.avg_conversation_length||0}</div><div class="cd-l">⌀ Msgs</div></div>
    <div class="cd"><div class="cd-n">${d.conversion_rate||0}%</div><div class="cd-l">🎫 Conversion</div></div>
    <div class="cd"><div class="cd-n">${d.drop_off_rate||0}%</div><div class="cd-l">📉 Abbruch</div></div>
  </div><div style="display:grid;grid-template-columns:2fr 1fr;gap:20px">
    <div class="cw"><h3 style="margin:0 0 10px;font-size:13px">Nachrichten/Tag</h3><canvas id="c1" height="200"></canvas></div>
    <div class="cw"><h3 style="margin:0 0 10px;font-size:13px">Kanäle</h3><canvas id="c2" height="200"></canvas></div>
  </div>`;
  if(d.top_searches?.length){h+='<div class="sec"><h3>🔍 Top Suchanfragen</h3><table class="tb"><thead><tr><th>#</th><th>Suchbegriff</th><th>Anzahl</th><th>⌀ Ergebnisse</th></tr></thead><tbody>';d.top_searches.slice(0,15).forEach((s,i)=>{const r=s.avg_results==0?' style="color:#e53935;font-weight:600"':'';h+=`<tr><td>${i+1}</td><td>${E(s.query)}</td><td><b>${s.count}</b></td><td${r}>${s.avg_results}</td></tr>`});h+='</tbody></table></div>'}
  if(d.search_no_results?.length){h+='<div class="sec"><h3>❌ Ohne Ergebnis</h3><table class="tb"><thead><tr><th>Suchbegriff</th><th>Anzahl</th></tr></thead><tbody>';d.search_no_results.slice(0,10).forEach(s=>h+=`<tr><td style="color:#e53935"><b>${E(s.query)}</b></td><td>${s.count}</td></tr>`);h+='</tbody></table></div>'}
  if(d.top_products?.length){h+='<div class="sec"><h3>🔥 Top Events/Tickets</h3><table class="tb"><thead><tr><th>#</th><th>Event / Kategorie</th><th>Menge</th></tr></thead><tbody>';d.top_products.slice(0,10).forEach((p,i)=>h+=`<tr><td>${i+1}</td><td>${E(p.name)}</td><td><b>${p.quantity}</b></td></tr>`);h+='</tbody></table></div>'}
  o.innerHTML=h;
  const e1=document.getElementById('c1');
  if(e1&&d.daily){if(c1)c1.destroy();c1=new Chart(e1,{type:'bar',data:{labels:d.daily.map(x=>new Date(x.date).toLocaleDateString('de-DE',{day:'2-digit',month:'2-digit'})),datasets:[{label:'Nachrichten',data:d.daily.map(x=>x.messages||0),backgroundColor:'rgba(255,85,0,.7)',borderRadius:3},{label:'Sessions',data:d.daily.map(x=>x.sessions||0),backgroundColor:'rgba(26,26,46,.12)',borderRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:11}}}},scales:{y:{beginAtZero:true},x:{ticks:{font:{size:10}}}}}})}
  const e2=document.getElementById('c2');
  if(e2&&d.channels){if(c2)c2.destroy();c2=new Chart(e2,{type:'doughnut',data:{labels:['Web','Telegram','WhatsApp'],datasets:[{data:[d.channels.web||0,d.channels.telegram||0,d.channels.whatsapp||0],backgroundColor:['#FF5500','#2e7d32','#1b5e20']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:12}}}}}})}
}
lS();
</script>
<?php }

// ============================================================
// SEARCH
// ============================================================
function txba_page_search() { txba_css(); ?>
<div class="wrap txba">
  <h1>🔍 Suche</h1>
  <div class="sb"><input id="sq" placeholder="Kundenname, E-Mail, Event, Nachricht..." onkeydown="if(event.key==='Enter')ds()"><button class="btn on" onclick="ds()" style="padding:12px 24px;border-radius:24px">Suchen</button></div>
  <div class="txba-g txba-g2">
    <div class="txba-p">
      <div class="txba-ph">Ergebnisse <span class="txba-b" id="sc">0</span></div>
      <div style="flex:1;overflow-y:auto" id="sl"><div class="mt">Suchbegriff eingeben</div></div>
    </div>
    <div class="txba-p">
      <div class="txba-ph" id="sh">Gespräch</div>
      <div class="inf" id="si"></div>
      <div class="cv" id="sv"><div class="mt">← Ergebnis auswählen</div></div>
    </div>
  </div>
</div>
<?php txba_js(); ?>
<script>
async function ds(){
  const q=document.getElementById('sq').value.trim();if(!q||q.length<2)return;
  const d=await api('/admin/search?q='+encodeURIComponent(q));if(!d.ok)return;
  document.getElementById('sc').textContent=d.total||0;
  const l=document.getElementById('sl');l.innerHTML='';
  if(!d.results?.length){l.innerHTML='<div class="mt">Keine Ergebnisse</div>';return}
  d.results.forEach(s=>{const el=document.createElement('div');el.className='s';el.innerHTML=sH(s);el.onclick=()=>oR(s.chat_id,s.is_active);l.appendChild(el)});
}
async function oR(id,ia){
  const d=await api((ia?'/admin/conversation/':'/admin/history/')+id);if(!d.ok)return;
  document.getElementById('sh').innerHTML=`💬 ${E(d.customer_name||id.slice(0,12))} <span class="txba-b">${d.channel||'?'}</span>`+(d.is_archived?' <span class="tag ar">ARCHIV</span>':'');
  const si=document.getElementById('si');let ih=[];
  ih.push(`<strong>ID:</strong> ${id.slice(0,16)}`);
  if(d.user_info?.wp_email)ih.push(`<strong>Email:</strong> ${E(d.user_info.wp_email)}`);
  if(d.user_info?.tg_username)ih.push(`<strong>TG:</strong> @${E(d.user_info.tg_username)}`);
  ih.push(`<strong>Msgs:</strong> ${d.message_count||0}`);
  if(d.created_at)ih.push(`<strong>Seit:</strong> ${new Date(d.created_at).toLocaleString('de-DE')}`);
  si.innerHTML=ih.map(x=>`<span>${x}</span>`).join('');si.classList.add('on');
  rC(document.getElementById('sv'),d.conversation);
}
document.getElementById('sq').focus();
</script>
<?php }

// ============================================================
// SETTINGS AJAX
// ============================================================
add_action('wp_ajax_txba_save_settings', function() {
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

    $auto_widget = filter_var($_POST['auto_widget'] ?? false, FILTER_VALIDATE_BOOLEAN);

    update_option('tix_auto_widget', $auto_widget);

    // Sync to bot backend
    $url = TXBA_API . '/admin/config';
    $r = wp_remote_post($url, [
        'timeout' => 10,
        'headers' => ['X-Admin-Key' => TXBA_KEY, 'Content-Type' => 'application/json'],
        'body' => json_encode(['auto_widget' => $auto_widget]),
    ]);

    $sync_ok = !is_wp_error($r);
    $sync_msg = $sync_ok ? '' : $r->get_error_message();

    wp_send_json(['ok' => true, 'sync_ok' => $sync_ok, 'sync_msg' => $sync_msg]);
});

// ============================================================
// SETTINGS
// ============================================================
function txba_page_settings() {
    $auto_widget = get_option('tix_auto_widget', false);
    txba_css();
?>
<style>
.txba-set{max-width:640px}
.txba-card{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:24px;margin-bottom:16px}
.txba-card h3{font-size:15px;font-weight:700;margin:0 0 4px;color:#1a1a2e}
.txba-card p{font-size:13px;color:#666;margin:0 0 16px}
.txba-row{display:flex;align-items:center;justify-content:space-between;gap:16px}
.txba-row-info{flex:1}
.txba-row-info .label{font-weight:600;font-size:14px;color:#1a1a2e}
.txba-row-info .desc{font-size:12px;color:#888;margin-top:2px}
.txba-sw{position:relative;width:48px;height:26px;flex-shrink:0}
.txba-sw input{opacity:0;width:0;height:0}
.txba-sw .sl{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:26px;transition:.3s}
.txba-sw .sl:before{content:'';position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
.txba-sw input:checked+.sl{background:#FF5500}
.txba-sw input:checked+.sl:before{transform:translateX(22px)}
.txba-save{margin-top:20px}
.txba-save .btn{padding:10px 28px;font-size:14px;font-weight:600;border-radius:8px}
.txba-save .btn.on{background:#FF5500;color:#fff;border-color:#FF5500}
.txba-save .btn.on:hover{background:#CC4400}
.txba-toast{position:fixed;top:40px;right:20px;padding:14px 22px;border-radius:10px;font-size:13px;font-weight:500;color:#fff;box-shadow:0 4px 20px rgba(0,0,0,.15);z-index:99999;animation:tin .3s}
.txba-toast.ok{background:#2e7d32}
.txba-toast.err{background:#c62828}
.txba-info{background:#eef2ff;border:1px solid #c7d2fe;border-radius:12px;padding:16px 20px;margin-bottom:16px;font-size:13px;color:#4338ca}
.txba-info code{background:#e0e7ff;padding:2px 6px;border-radius:4px;font-size:12px}
</style>
<div class="wrap txba txba-set">
  <h1>⚙️ Bot-Einstellungen</h1>

  <div class="txba-info">
    <strong>🤖 Bot-Status:</strong> <span id="bot_status">Prüfe...</span><br>
    <strong>API:</strong> <code><?php echo TXBA_API; ?></code>
  </div>

  <div class="txba-card">
    <h3>Chat-Widget</h3>
    <p>Konfiguration des Chat-Widgets auf der Website</p>
    <div class="txba-row">
      <div class="txba-row-info">
        <div class="label">Widget auf allen Seiten anzeigen</div>
        <div class="desc">Zeigt das Chat-Icon (unten rechts) auf allen Seiten der Website. Wenn deaktiviert, wird das Widget nur dort angezeigt, wo der <code>[tix_chat_widget]</code> Shortcode eingebunden ist.</div>
      </div>
      <label class="txba-sw">
        <input type="checkbox" id="sw_widget" <?php echo $auto_widget ? 'checked' : ''; ?>>
        <span class="sl"></span>
      </label>
    </div>
  </div>

  <div class="txba-card">
    <h3>Shortcodes</h3>
    <p>Verfügbare Shortcodes für den Bot</p>
    <table class="tb" style="margin:0">
      <tr><td><code>[tix_chat]</code></td><td>Eingebettetes Chat-Fenster (auf einer Seite)</td></tr>
      <tr><td><code>[tix_chat_widget]</code></td><td>Floating Chat-Button (unten rechts)</td></tr>
    </table>
  </div>

  <div class="txba-card">
    <h3>Kanäle</h3>
    <p>Übersicht der aktiven Bot-Kanäle</p>
    <div id="channels_info" style="font-size:13px;color:#666">Laden...</div>
  </div>

  <div class="txba-card">
    <h3>Gespräche zurücksetzen</h3>
    <p>Alle Bot-Unterhaltungen löschen. Aktive Sessions werden vorher archiviert.</p>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
      <button class="btn" onclick="clearSessions(false)" style="color:#e53935;border-color:#e53935">🗑️ Aktive Sessions löschen</button>
      <button class="btn" onclick="clearSessions(true)" style="color:#b71c1c;border-color:#b71c1c">🗑️ Alles löschen (inkl. Archiv)</button>
      <span id="clear_status" style="font-size:13px;color:#888"></span>
    </div>
  </div>

  <div class="txba-save">
    <button class="btn on" onclick="saveSettings()">Einstellungen speichern</button>
    <span id="save_status" style="margin-left:12px;font-size:13px;color:#888"></span>
  </div>
</div>
<?php txba_js(); ?>
<script>
// Check bot health
(async function(){
  try{
    const r=await fetch('<?php echo TXBA_API; ?>/health');
    const d=await r.json();
    const s=document.getElementById('bot_status');
    if(d.status==='ok'){
      s.innerHTML='<span style="color:#2e7d32;font-weight:600">✅ Online</span> – '+
        (d.upcoming_events||0)+' Events, '+(d.active_sessions||0)+' aktive Sessions';
    }else{
      s.innerHTML='<span style="color:#e53935;font-weight:600">❌ Offline</span>';
    }
    const ch=document.getElementById('channels_info');
    if(d.channels){
      let h='';
      h+='<div style="display:flex;gap:16px;flex-wrap:wrap">';
      h+='<span>'+(d.channels.webchat?'✅':'❌')+' Webchat</span>';
      h+='<span>'+(d.channels.telegram?'✅':'❌')+' Telegram</span>';
      h+='<span>'+(d.channels.whatsapp?'✅':'❌')+' WhatsApp</span>';
      h+='</div>';
      ch.innerHTML=h;
    }
  }catch(e){
    document.getElementById('bot_status').innerHTML='<span style="color:#e53935;font-weight:600">❌ Nicht erreichbar</span>';
  }
})();

async function saveSettings() {
  const btn = document.querySelector('.txba-save .btn');
  btn.disabled = true;
  btn.textContent = 'Speichern...';

  const fd = new FormData();
  fd.append('action', 'txba_save_settings');
  fd.append('auto_widget', document.getElementById('sw_widget').checked ? '1' : '0');

  try {
    const r = await fetch(AX, { method: 'POST', body: fd, credentials: 'same-origin' });
    const d = await r.json();
    if (d.ok) {
      showToast('Einstellungen gespeichert!', 'ok');
      if (!d.sync_ok) showToast('Backend-Sync fehlgeschlagen: ' + (d.sync_msg || 'Timeout'), 'err');
    } else {
      showToast('Fehler beim Speichern', 'err');
    }
  } catch (e) {
    showToast('Verbindungsfehler', 'err');
  }
  btn.disabled = false;
  btn.textContent = 'Einstellungen speichern';
}

async function clearSessions(includeHistory) {
  const msg = includeHistory
    ? 'Alle aktiven Sessions UND das gesamte Archiv unwiderruflich löschen?'
    : 'Alle aktiven Sessions löschen? (werden vorher archiviert)';
  if (!confirm(msg)) return;
  const st = document.getElementById('clear_status');
  st.textContent = 'Lösche...';
  st.style.color = '#888';
  try {
    const d = await api('/admin/sessions/clear', 'POST', {active: true, history: includeHistory});
    if (d.ok) {
      let info = d.deleted_active + ' Session(s) gelöscht';
      if (includeHistory) info += ', ' + d.deleted_history + ' archivierte Gespräche gelöscht';
      st.textContent = '✅ ' + info;
      st.style.color = '#2e7d32';
      showToast(info, 'ok');
    } else {
      st.textContent = '❌ ' + (d.error || 'Fehler');
      st.style.color = '#e53935';
      showToast('Fehler: ' + (d.error || 'Unbekannt'), 'err');
    }
  } catch (e) {
    st.textContent = '❌ Verbindungsfehler';
    st.style.color = '#e53935';
    showToast('Verbindungsfehler', 'err');
  }
}

function showToast(msg, type) {
  const t = document.createElement('div');
  t.className = 'txba-toast ' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}
</script>
<?php }
