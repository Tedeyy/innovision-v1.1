<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'bat') {
    $isVerified = ($src === 'bat');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';
require_once __DIR__ . '/../../authentication/lib/supabase_client.php';
$batId = $_SESSION['user_id'] ?? null;
$toReview = 0; $approvedCount = 0; $deniedCount = 0;
if ($batId){
    [$r1,$s1,$e1] = sb_rest('GET','reviewlivestocklisting',['select'=>'listing_id']);
    if ($s1>=200 && $s1<300 && is_array($r1)) $toReview = count($r1);
    [$r2,$s2,$e2] = sb_rest('GET','livestocklisting',['select'=>'listing_id','bat_id'=>'eq.'.$batId]);
    if ($s2>=200 && $s2<300 && is_array($r2)) $approvedCount = count($r2);
    [$r3,$s3,$e3] = sb_rest('GET','deniedlivestocklisting',['select'=>'listing_id','bat_id'=>'eq.'.$batId]);
    if ($s3>=200 && $s3<300 && is_array($r3)) $deniedCount = count($r3);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BAT Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/dashboard.css">
    </head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="brand">Dashboard</div>
            <form class="search" method="get" action="#">
                <input type="search" name="q" placeholder="Search" />
            </form>
        </div>
        <div class="nav-right">
            <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <a class="btn" href="../logout.php">Logout</a>
            <a class="notify" href="#" aria-label="Notifications" title="Notifications">
                <span class="avatar">🔔</span>
            </a>
            <a class="profile" href="pages/profile.php" aria-label="Profile">
                <span class="avatar">👤</span>
            </a>
        </div>
    </nav>
    <div class="wrap">
        <div class="top">
            <div>
                <h1>BAT Dashboard</h1>
            </div>
        </div>
        <div class="card">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:10px 0;">
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">To Review</div>
                    <div style="font-size:20px;font-weight:600;"><?php echo (int)$toReview; ?></div>
                </div>
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">Approved</div>
                    <div style="font-size:20px;font-weight:600;"><?php echo (int)$approvedCount; ?></div>
                </div>
                <div class="card" style="padding:12px;">
                    <div style="color:#4a5568;font-size:12px;">Denied</div>
                    <div style="font-size:20px;font-weight:600;"><?php echo (int)$deniedCount; ?></div>
                </div>
            </div>
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                <a class="btn" href="pages/review_listings.php">Review Listings</a>
            </div>
        </div>
        <div class="card">
            <h2 style="margin-top:0">Scheduling Calendar</h2>
            <div id="calendar"></div>
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
              <button class="btn" id="btn-add">Add</button>
              <button class="btn" id="btn-edit" style="background:#4a5568;">Edit</button>
              <button class="btn" id="btn-delete" style="background:#e53e3e;">Delete</button>
              <button class="btn" id="btn-done" style="background:#2f855a;">Mark as Done</button>
              <button class="btn" id="btn-view" style="background:#805ad5;">View</button>
            </div>
        </div>
        <div id="geoStatus" style="margin-top:8px;color:#4a5568;font-size:14px"></div>
    </div>
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css' rel='stylesheet' />
    <style>
      .done-event .fc-event-title { text-decoration: line-through; opacity: 0.7; }
    </style>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>
    <script src="script/dashboard.js"></script>
    <div id="modal" style="position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:10000;">
      <div style="background:#fff;border-radius:10px;min-width:300px;max-width:90vw;padding:16px;">
        <h3 id="modal-title" style="margin-top:0">Add Schedule</h3>
        <form id="modal-form">
          <div style="display:grid;grid-template-columns:1fr;gap:8px;">
            <label>Title<input type="text" name="title" required /></label>
            <label>Description<input type="text" name="description" required /></label>
            <label>Date<input type="date" name="date" required /></label>
            <label>Time<input type="time" name="time" required /></label>
          </div>
          <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn" id="modal-cancel" style="background:#718096;">Cancel</button>
            <button type="submit" class="btn" id="modal-save">Save</button>
          </div>
        </form>
      </div>
    </div>
    <script>
      (function(){
        var calEl = document.getElementById('calendar');
        if (!calEl) return;
        var calendar = new FullCalendar.Calendar(calEl, {
          initialView: 'dayGridMonth',
          selectable: true,
          editable: true,
          headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
          events: async function(fetchInfo, success, failure){
            try {
              const res = await fetch('pages/schedule_api.php?action=list&start='+encodeURIComponent(fetchInfo.startStr)+'&end='+encodeURIComponent(fetchInfo.endStr));
              const data = await res.json();
              if (!data.ok) throw new Error(data.error||'Failed');
              success((data.events||[]).map(function(e){
                const cls = (e.done? ['done-event'] : []);
                return { id: e.id, title: e.title, start: e.start, end: e.end, allDay: false, classNames: cls };
              }));
            } catch(err){ failure(err); }
          },
          eventDrop: function(){ /* not supported for schedule table without date fields per move; keep disabled */ },
          eventResize: function(){ /* not supported */ },
          eventClick: function(clickInfo){
            window.selectedEvent = clickInfo.event;
          }
        });
        calendar.render();
        window.calendar = calendar;
        // Modal helpers
        function openModal(title, values){
          var modal = document.getElementById('modal');
          if (!modal) return;
          document.getElementById('modal-title').textContent = title || 'Schedule';
          var form = document.getElementById('modal-form');
          form.reset();
          document.getElementById('modal-save').style.display = '';
          document.getElementById('modal-cancel').textContent = 'Cancel';
          Array.from(form.elements).forEach(function(el){ if (el.tagName==='INPUT') el.readOnly = false; });
          if (values){
            if (form.title) form.title.value = values.title || '';
            if (form.description) form.description.value = values.description || '';
            if (form.date) form.date.value = values.date || '';
            if (form.time) form.time.value = values.time || '';
          }
          modal.style.display = 'flex';
        }
        function closeModal(){ var m=document.getElementById('modal'); if (m) m.style.display='none'; }
        var modalCancel = document.getElementById('modal-cancel');
        if (modalCancel){ modalCancel.addEventListener('click', closeModal); }
        var modalOverlay = document.getElementById('modal');
        if (modalOverlay){ modalOverlay.addEventListener('click', function(e){ if (e.target.id==='modal') closeModal(); }); }
        // Add
        var btnAdd = document.getElementById('btn-add');
        if (btnAdd){
          btnAdd.addEventListener('click', function(){
            const today = new Date();
            const yyyy = today.getFullYear(); const mm = String(today.getMonth()+1).padStart(2,'0'); const dd = String(today.getDate()).padStart(2,'0');
            openModal('Add Schedule', { date: `${yyyy}-${mm}-${dd}`, time: '09:00' });
            const form = document.getElementById('modal-form');
            form.onsubmit = async function(e){ e.preventDefault();
              const payload = { title: form.title.value, description: form.description.value, date: form.date.value, time: form.time.value };
              const res = await fetch('pages/schedule_api.php?action=create', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
              const data = await res.json(); if (data.ok){ closeModal(); calendar.refetchEvents(); }
            };
          });
        }
        // Edit
        var btnEdit = document.getElementById('btn-edit');
        if (btnEdit){
          btnEdit.addEventListener('click', async function(){
            if (!window.selectedEvent){ alert('Select a schedule first'); return; }
            const id = window.selectedEvent.id;
            const res = await fetch('pages/schedule_api.php?action=get&id='+encodeURIComponent(id));
            const data = await res.json(); if (!data.ok){ alert('Failed to load schedule'); return; }
            openModal('Edit Schedule', { title: data.item.title, description: data.item.description, date: data.item.date, time: data.item.time });
            const form = document.getElementById('modal-form');
            form.onsubmit = async function(e){ e.preventDefault();
              const payload = { title: form.title.value, description: form.description.value, date: form.date.value, time: form.time.value };
              const res2 = await fetch('pages/schedule_api.php?action=update&id='+encodeURIComponent(id), { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
              const data2 = await res2.json(); if (data2.ok){ closeModal(); calendar.refetchEvents(); }
            };
          });
        }
        // Delete
        var btnDelete = document.getElementById('btn-delete');
        if (btnDelete){
          btnDelete.addEventListener('click', async function(){
            if (!window.selectedEvent){ alert('Select a schedule first'); return; }
            if (!confirm('Delete this schedule?')) return;
            const id = window.selectedEvent.id;
            const res = await fetch('pages/schedule_api.php?action=delete&id='+encodeURIComponent(id), { method:'POST' });
            const data = await res.json(); if (data.ok){ calendar.refetchEvents(); window.selectedEvent = null; }
          });
        }
        // Done
        var btnDone = document.getElementById('btn-done');
        if (btnDone){
          btnDone.addEventListener('click', async function(){
            if (!window.selectedEvent){ alert('Select a schedule first'); return; }
            const id = window.selectedEvent.id;
            const res = await fetch('pages/schedule_api.php?action=done&id='+encodeURIComponent(id), { method:'POST' });
            const data = await res.json(); if (data.ok){ calendar.refetchEvents(); }
          });
        }
        // View
        var btnView = document.getElementById('btn-view');
        if (btnView){
          btnView.addEventListener('click', async function(){
            if (!window.selectedEvent){ alert('Select a schedule first'); return; }
            const id = window.selectedEvent.id;
            const res = await fetch('pages/schedule_api.php?action=get&id='+encodeURIComponent(id));
            const data = await res.json(); if (!data.ok){ alert('Failed to load schedule'); return; }
            openModal('View Schedule', { title: data.item.title, description: data.item.description, date: data.item.date, time: data.item.time });
            var form = document.getElementById('modal-form');
            Array.from(form.elements).forEach(function(el){ if (el.tagName==='INPUT') el.readOnly = true; });
            document.getElementById('modal-save').style.display = 'none';
            document.getElementById('modal-cancel').textContent = 'Close';
          });
        }
      })();
    </script>
</body>
</html>