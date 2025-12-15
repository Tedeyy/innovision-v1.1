(function(){
  var tabs = document.querySelectorAll('.tabs button');
  tabs.forEach(function(b){ b.addEventListener('click', function(){
    tabs.forEach(function(x){ x.classList.remove('active'); });
    b.classList.add('active');
    ['buyer','seller','bat'].forEach(function(t){
      var el = document.getElementById('tab-'+t);
      if (el) el.style.display = (b.dataset.tab===t) ? '' : 'none';
    });
  });});

  function esc(s){ return (s==null)?'':String(s); }
  function row(label, val){ return '<div class="row"><div class="label">'+label+'</div><div>'+val+'</div></div>'; }
  var modal = document.getElementById('detailModal');
  var close = document.getElementById('closeModal');
  var approveBtn = document.getElementById('approveBtn');
  var denyBtn = document.getElementById('denyBtn');
  var actionStatus = document.getElementById('actionStatus');
  var current = { role:null, data:null, tr:null, ds:null };
  if (close) close.addEventListener('click', function(){ modal.style.display='none'; });
  if (modal) modal.addEventListener('click', function(e){ if (e.target===modal) modal.style.display='none'; });

  function renderDetails(obj, role){
    var html = '';
    var fields = Object.keys(obj);
    var hide = { username:1, password:1 };
    fields.forEach(function(k){ if (hide[k]) return; html += row(k, esc(obj[k])); });
    var el = document.getElementById('detailBody');
    if (el) el.innerHTML = '<div class="grid">'+html+'</div>';
  }

  function loadDoc(role, fname, mname, lname, created, email){
    var s = document.getElementById('docStatus');
    var box = document.getElementById('docPreview');
    if (s) s.textContent = 'Loading document...';
    if (box) box.innerHTML = '';
    var url = 'usermanagement.php?doc=1&role='+encodeURIComponent(role)+'&fname='+encodeURIComponent(fname)+'&mname='+encodeURIComponent(mname||'')+'&lname='+encodeURIComponent(lname)+'&created='+encodeURIComponent(created||'')+'&email='+encodeURIComponent(email||'');
    fetch(url, {credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
      if (!j.ok){ if (s) s.textContent = 'Document not available ('+(j.error||'unknown')+').'; return; }
      if (s) s.textContent = '';
      var u = j.url || '';
      if (u.match(/\.(pdf)(\?|$)/i)){
        var a = document.createElement('a'); a.href=u; a.textContent='Open document'; a.target='_blank'; if (box) box.appendChild(a);
      } else {
        var img = document.createElement('img');
        img.src = u;
        img.alt = 'Supporting document';
        img.style.maxWidth = '400px';
        img.style.maxHeight = '400px';
        img.style.width = 'auto';
        img.style.height = 'auto';
        img.style.objectFit = 'contain';
        img.style.display = 'block';
        img.style.margin = '0 auto';
        img.style.border = '1px solid #e5e7eb';
        img.style.borderRadius = '8px';
        if (box) box.appendChild(img);
      }
    }).catch(function(){ if (s) s.textContent='Failed to load document.'; });
  }

  function setButtons(enabled){ if (approveBtn) approveBtn.disabled = !enabled; if (denyBtn) denyBtn.disabled = !enabled; }
  function submitDecision(action){
    if (!current || !current.data){ return; }
    setButtons(false);
    if (actionStatus) actionStatus.textContent = 'Processing...';
    var body = {
      role: current.role,
      id: current.data.user_id,
      fname: current.ds.fname,
      mname: current.ds.mname,
      lname: current.ds.lname,
      email: current.ds.email,
      created: current.ds.created
    };
    fetch('usermanagement.php?decide='+encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function(r){ return r.json(); }).then(function(j){
      if (!j || !j.ok){
        var msg = 'Failed: '+((j&&j.error)||'');
        if (j && j.detail) { try { var d = (typeof j.detail==='string')? j.detail : JSON.stringify(j.detail); msg += ' • '+d; } catch(e){} }
        if (actionStatus) actionStatus.textContent = msg;
        setButtons(true);
        return;
      }
      if (actionStatus) actionStatus.textContent = 'Success';
      if (current.tr && current.tr.parentNode){ current.tr.parentNode.removeChild(current.tr); }
      setTimeout(function(){ if (actionStatus) actionStatus.textContent=''; if (modal) modal.style.display='none'; }, 400);
    }).catch(function(){ if (actionStatus) actionStatus.textContent = 'Failed'; setButtons(true); });
  }

  if (approveBtn){ approveBtn.addEventListener('click', function(){ submitDecision('approve'); }); }
  if (denyBtn){ denyBtn.addEventListener('click', function(){ submitDecision('deny'); }); }

  document.querySelectorAll('.show').forEach(function(btn){ btn.addEventListener('click', function(){
    var role = btn.dataset.role;
    var data = {};
    try { data = JSON.parse(btn.dataset.json); } catch(e){}
    var mt = document.getElementById('modalTitle');
    if (mt) mt.textContent = (data.user_fname||'')+' '+(data.user_lname||'');
    renderDetails(data, role);
    var ds = { fname: btn.dataset.fname||'', mname: btn.dataset.mname||'', lname: btn.dataset.lname||'', created: btn.dataset.created||'', email: btn.dataset.email||'' };
    current = { role: role, data: data, tr: btn.closest('tr'), ds: ds };
    setButtons(true);
    if (actionStatus) actionStatus.textContent = '';
    loadDoc(role, ds.fname, ds.mname, ds.lname, ds.created, ds.email);
    if (modal) modal.style.display='flex';
  }); });
})();
