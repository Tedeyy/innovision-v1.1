(function(){
  async function fetchNotifs(){
    try{
      const res = await fetch('/pages/notifications/notifications_api.php', {cache:'no-store'});
      const data = await res.json();
      if (!data || !data.ok) return;
      const badge = document.getElementById('notifBadge');
      if (badge){ badge.textContent = String(data.unread||0); badge.style.display = (data.unread>0)?'inline-block':'none'; }
      if (typeof window.updateNotifications === 'function'){
        window.updateNotifications(data.items||[]);
      } else {
        const listEl = document.getElementById('notifList');
        if (listEl){
          listEl.innerHTML = '';
          (data.items||[]).forEach(function(item){
            const row = document.createElement('div');
            row.style.cssText='padding:10px 12px;border-bottom:1px solid #f3f4f6;';
            row.textContent = (item.title? (item.title+': ') : '') + (item.message||'');
            listEl.appendChild(row);
          });
        }
      }
    }catch(e){ /* ignore */ }
  }
  function init(){ fetchNotifs(); setInterval(fetchNotifs, 30000); }
  if (document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
