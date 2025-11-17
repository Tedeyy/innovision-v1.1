<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

// Allow all roles; require login only
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId === 0){
  $_SESSION['flash_error'] = 'Please sign in to view transactions.';
  header('Location: ../dashboard.php');
  exit;
}

if (isset($_POST['action']) && $_POST['action']==='complete_transaction'){
  // ... (rest of the code remains the same)

  // ... (rest of the code remains the same)
  echo json_encode(['ok'=>true,'data'=>$ores[0] ?? null, 'warning'=>$warning, 'warning2'=>$warning2, 'warning3'=>$warning3]);
  exit;
}

if (isset($_GET['action']) && $_GET['action']==='has_rating'){
  header('Content-Type: application/json');
  $txId = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;
  if (!$txId){
    echo json_encode(['ok'=>false,'error'=>'missing_params']);
    exit;
  }
  // Did this seller already rate this transaction?
  [$rows,$status,$error] = sb_rest('GET','userrating',[ 'select'=>'rating_id', 'transaction_id'=>'eq.'.$txId, 'seller_id'=>'eq.'.$userId, 'limit'=>1 ]);
  if (!($status>=200 && $status<300) || !is_array($rows)) $rows = [];
  echo json_encode(['ok'=>true,'has_rating'=>(count($rows)>0)]);
  exit;
}

if (isset($_POST['action']) && $_POST['action']==='rate_buyer'){
  header('Content-Type: application/json');
  $txId = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
  $sellerIdIn = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
  $buyerIdIn  = isset($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : 0;
  $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
  $description = isset($_POST['description']) ? (string)$_POST['description'] : '';
  if (!$txId || $sellerIdIn!==$userId || $buyerIdIn<=0 || $rating<1 || $rating>5){
    echo json_encode(['ok'=>false,'error'=>'missing_params']);
    exit;
  }
  $payload = [[
    'transaction_id'=>$txId,
    'seller_id'=>$sellerIdIn,
    'buyer_id'=>$buyerIdIn,
    'rating'=>$rating,
    'description'=>$description
  ]];
  [$res,$status,$error] = sb_rest('POST','userrating',[], $payload, ['Prefer: return=representation']);
  if (!($status>=200 && $status<300)){
    $detail = '';
    if (is_array($res) && isset($res['message'])) { $detail = $res['message']; }
    elseif (is_string($res) && $res!=='') { $detail = $res; }
    echo json_encode(['ok'=>false,'error'=>'insert_failed','code'=>$status,'detail'=>$detail]);
    exit;
  }
  echo json_encode(['ok'=>true]);
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Transactions</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    .modal{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:9999}
    .panel{background:#fff;border-radius:10px;max-width:900px;width:95vw;max-height:90vh;overflow:auto;padding:16px}
    .subtle{color:#4a5568;font-size:12px}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{padding:8px;text-align:left}
    .table thead tr{border-bottom:1px solid #e2e8f0}
    .close-btn{background:#e53e3e;color:#fff;border:none;border-radius:6px;padding:6px 10px;cursor:pointer}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600}
    .badge-started{background:#ebf5ff;color:#1e40af;border:1px solid #bfdbfe}
    .badge-ongoing{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
    .badge-completed{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .badge-default{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top" style="margin-bottom:8px;">
      <div><h1>My Transactions</h1></div>
      <div>
        <a class="btn" href="../dashboard.php">Back to Dashboard</a>
      </div>

  <div id="rateModalSeller" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Rate Buyer</h2>
        <button class="close-btn" data-close="rateModalSeller">Close</button>
      </div>
      <div style="margin-top:8px;">
        <div class="subtle" style="margin-bottom:8px;">Please rate your experience with this buyer.</div>
        <div id="rateStarsSeller" style="font-size:28px;color:#e2e8f0;cursor:pointer;user-select:none;">
          <span data-v="1">★</span>
          <span data-v="2">★</span>
          <span data-v="3">★</span>
          <span data-v="4">★</span>
          <span data-v="5">★</span>
        </div>
        <textarea id="rateDescSeller" placeholder="Optional description (max 255 chars)" maxlength="255" style="margin-top:10px;width:100%;min-height:90px;padding:10px;border:1px solid #e2e8f0;border-radius:8px;"></textarea>
        <div style="margin-top:10px;display:flex;gap:8px;align-items:center;">
          <button class="btn" id="btnSubmitRatingSeller">Submit Rating</button>
          <span class="subtle" id="rateMsgSeller"></span>
        </div>
      </div>
    </div>
  </div>
    </div>

    <div class="card">
      <table class="table" id="txTable">
        <thead>
          <tr>
            <th>Tx ID</th>
            <th>Buyer</th>
            <th>Listing</th>
            <th>Status</th>
            <th>Started</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <div id="txModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Transaction Details</h2>
        <button class="close-btn" data-close="txModal">Close</button>
      </div>
      <div id="txBody" style="margin-top:8px;"></div>
    </div>
  </div>

  <div id="completeModal" class="modal">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <h2 style="margin:0;">Complete Transaction</h2>
        <button class="close-btn" data-close="completeModal">Close</button>
      </div>
      <div style="margin-top:8px;">
        <div style="display:grid;gap:10px;">
          <div>
            <label style="display:block;font-weight:600;margin-bottom:4px;">Result</label>
            <label style="margin-right:16px;"><input type="radio" name="txResult" value="successful" checked> Successful</label>
            <label><input type="radio" name="txResult" value="unsuccessful"> Unsuccessful</label>
          </div>
          <div>
            <label style="display:block;font-weight:600;margin-bottom:4px;">Final Price (₱)</label>
            <input type="number" id="txPrice" step="0.01" min="0" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
          </div>
          <div>
            <label style="display:block;font-weight:600;margin-bottom:4px;">Payment Method</label>
            <input type="text" id="txPayment" placeholder="e.g. Cash" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;" />
          </div>
          <div>
            <label style="display:block;font-weight:600;margin-bottom:4px;">Documentary Photo (optional if unsuccessful)</label>
            <input type="file" id="txDoc" accept="image/*" />
          </div>
          <div>
            <button class="btn" id="btnSubmitComplete">Submit</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      function $(s){ return document.querySelector(s); }
      function $all(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); }
      function openModal(id){ var el=document.getElementById(id); if(el) el.style.display='flex'; }
      function closeModal(id){ var el=document.getElementById(id); if(el) el.style.display='none'; }
      $all('.close-btn').forEach(function(b){ b.addEventListener('click', function(){ closeModal(b.getAttribute('data-close')); }); });

      function fullname(p){ if (!p) return ''; var f=p.user_fname||'', m=p.user_mname||'', l=p.user_lname||''; return (f+' '+(m?m+' ':'')+l).trim(); }
      function statusBadge(s){
        var t=(s||'').toLowerCase();
        if (t==='started') return '<span class="badge badge-started">Started</span>';
        if (t==='ongoing') return '<span class="badge badge-ongoing">Ongoing</span>';
        if (t==='completed') return '<span class="badge badge-completed">Completed</span>';
        return '<span class="badge badge-default">'+(s||'')+'</span>';
      }
      function load(){
        fetch('transactions.php?action=list', { credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(res){
            var tb = document.querySelector('#txTable tbody');
            tb.innerHTML = '';
            if (!res || res.ok===false){
              var tr = document.createElement('tr');
              var td = document.createElement('td'); td.colSpan = 6; td.style.color = '#7f1d1d';
              td.textContent = 'Failed to load transactions'+(res && res.code? (' (code '+res.code+')') : '');
              tr.appendChild(td); tb.appendChild(tr); return;
            }
            if (!res.data || res.data.length===0){
              var tr = document.createElement('tr'); var td = document.createElement('td'); td.colSpan = 6; td.textContent = 'No transactions found.'; tr.appendChild(td); tb.appendChild(tr); return;
            }
            (res.data||[]).forEach(function(row){
              var buyer = row.buyer||{}; var seller = row.seller||{}; var listing = row.listing||{};
              var role = (row.seller_id == <?php echo (int)$userId; ?>) ? 'Seller' : 'Buyer';
              var counterparty = role==='Seller' ? (function(p){var f=p.user_fname||'',m=p.user_mname||'',l=p.user_lname||'';return (f+' '+(m?m+' ':'')+l).trim();})(buyer) : (function(p){var f=p.user_fname||'',m=p.user_mname||'',l=p.user_lname||'';return (f+' '+(m?m+' ':'')+l).trim();})(seller);
              var tr = document.createElement('tr');
              tr.innerHTML = '<td>'+ (row.transaction_id||'') +'</td>'+
                '<td>'+ counterparty +'</td>'+
                '<td>'+(listing.livestock_type||'')+' • '+(listing.breed||'')+'</td>'+
                '<td>'+ statusBadge(row.status) +'</td>'+
                '<td>'+(row.started_at||'')+'</td>'+
                '<td><button class="btn btn-show" data-row="'+encodeURIComponent(JSON.stringify(row))+'">Show</button></td>';
              tb.appendChild(tr);
            });
          });
      }
      load();

      document.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('btn-show')){
          var data = JSON.parse(decodeURIComponent(e.target.getAttribute('data-row')||'{}'));
          var buyer = data.buyer||{}; var seller = data.seller||{}; var listing = data.listing||{};
          var body = document.getElementById('txBody');
          var img = document.createElement('img');
          img.src = data.thumb || '';
          img.alt = 'thumb';
          img.style.width = '160px'; img.style.height='160px'; img.style.objectFit='cover'; img.style.border='1px solid #e2e8f0'; img.style.borderRadius='8px';
          img.onerror = function(){ if (data.thumb_fallback && img.src!==data.thumb_fallback) img.src = data.thumb_fallback; else img.style.display='none'; };
          function initials(p){ var f=(p.user_fname||'').trim(), l=(p.user_lname||'').trim(); var s=(f?f[0]:'')+(l?l[0]:''); return s.toUpperCase()||'U'; }
          function avatarHTML(p){ var ini=initials(p); return '<div style="width:40px;height:40px;border-radius:50%;background:#edf2f7;color:#2d3748;display:flex;align-items:center;justify-content:center;font-weight:600;">'+ini+'</div>'; }
          body.innerHTML = ''+
            '<h3>Listing</h3>'+
            '<div style="display:flex;gap:12px;align-items:flex-start;">'+
              '<div><strong>'+(listing.livestock_type||'')+' • '+(listing.breed||'')+'</strong><div>Price: ₱'+(listing.price||'')+'</div><div>Address: '+(listing.address||'')+'</div><div class="subtle">Listing #'+(listing.listing_id||'')+' • Created '+(listing.created||'')+'</div></div>'+
              '<div id="imgWrap"></div>'+
            '</div>'+
            '<div style="margin-top:10px;">'+
              '<div id="txMap" style="height:200px;border:1px solid #e2e8f0;border-radius:8px"></div>'+
            '</div>'+
            '<hr style="margin:12px 0;border:none;border-top:1px solid #e2e8f0" />'+
            '<h3>Seller</h3>'+
            '<div style="display:flex;gap:10px;align-items:flex-start;">'+ avatarHTML(seller) +
              '<div><div><strong>'+fullname(seller)+'</strong></div><div>Email: '+(seller.email||'')+'</div><div>Contact: '+(seller.contact||'')+'</div></div>'+
            '</div>'+
            '<h3 style="margin-top:10px;">Buyer</h3>'+
            '<div style="display:flex;gap:10px;align-items:flex-start;">'+ avatarHTML(buyer) +
              '<div><div><strong>'+fullname(buyer)+'</strong></div><div>Email: '+(buyer.email||'')+'</div><div>Contact: '+(buyer.contact||'')+'</div></div>'+
            '</div>'+
            '<div style="margin-top:14px;color:#4a5568;">Note: Meet-up date and location will be set by BAT.</div>'+ 
            '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">'+
              '<button class="btn" id="btnSchedule">Schedule Meet-Up</button>'+
              '<button class="btn" id="btnDone">Done</button>'+
              ((String(data.status||'').toLowerCase()==='completed') ? '<button class="btn" id="btnRateBuyer">Rate Buyer</button>' : '')+
            '</div>';
          var wrap = document.getElementById('imgWrap'); if (wrap) wrap.appendChild(img);
          // Map (read-only)
          setTimeout(function(){
            var mEl = document.getElementById('txMap'); if (!mEl || !window.L) return;
            var map = L.map(mEl).setView([8.314209 , 124.859425], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            var marker = null;
            if (data.lat!=null && data.lng!=null){
              map.setView([data.lat, data.lng], 12);
              marker = L.marker([data.lat, data.lng]).addTo(map);
            }
          }, 0);
          openModal('txModal');
          var schedBtn = document.getElementById('btnSchedule');
          if (schedBtn){
            schedBtn.addEventListener('click', function(){
              var fd = new FormData();
              fd.append('action','schedule_meetup');
              fd.append('listing_id', (listing.listing_id||''));
              fd.append('seller_id', (data.seller_id||''));
              fd.append('buyer_id', (data.buyer_id||''));
              schedBtn.disabled = true; schedBtn.textContent = 'Scheduling...';
              fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res){
                  if (!res || res.ok===false){
                    alert('Failed to move to Ongoing'+(res && res.code? (' (code '+res.code+')') : ''));
                    schedBtn.disabled = false; schedBtn.textContent = 'Schedule Meet-Up';
                  } else {
                    alert('Transaction moved to Ongoing.');
                    schedBtn.disabled = true; schedBtn.textContent = 'Scheduled';
                  }
                })
                .catch(function(){ schedBtn.disabled = false; schedBtn.textContent = 'Schedule Meet-Up'; });
            });
          }
          var doneBtn = document.getElementById('btnDone');
          if (doneBtn){
            doneBtn.addEventListener('click', function(){
              var cm = document.getElementById('completeModal');
              cm.setAttribute('data-tx', encodeURIComponent(JSON.stringify(data)));
              openModal('completeModal');
            });
          }
          var rateBtn = document.getElementById('btnRateBuyer');
          if (rateBtn){
            rateBtn.addEventListener('click', function(){
              // check if already rated
              fetch('transactions.php?action=has_rating&transaction_id='+ encodeURIComponent(data.transaction_id||''), { credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res){
                  if (res && res.has_rating){
                    alert('You already rated this transaction.');
                    rateBtn.disabled = true; rateBtn.textContent = 'Already Rated';
                    return;
                  }
                  var rm = document.getElementById('rateModalSeller');
                  rm.setAttribute('data-buyer', String(data.buyer_id||''));
                  rm.setAttribute('data-transaction', String(data.transaction_id||''));
                  openModal('rateModalSeller');
                });
            });
          }
        }
      });

      ['txModal','completeModal','rateModalSeller'].forEach(function(id){ var el=document.getElementById(id); if(el){ el.addEventListener('click', function(ev){ if(ev.target===el) closeModal(id); }); }});

      var btnSubmitComplete = document.getElementById('btnSubmitComplete');
      if (btnSubmitComplete){
        btnSubmitComplete.addEventListener('click', function(){
          var cm = document.getElementById('completeModal');
          var raw = cm.getAttribute('data-tx')||'';
          var tx = {};
          try{ tx = JSON.parse(decodeURIComponent(raw)); }catch(e){}
          var result = (document.querySelector('input[name="txResult"]:checked')||{}).value||'successful';
          var price = (document.getElementById('txPrice')||{}).value||'';
          var paym  = (document.getElementById('txPayment')||{}).value||'';
          var file  = (document.getElementById('txDoc')||{}).files ? (document.getElementById('txDoc').files[0]||null) : null;
          if (!price){ alert('Please enter final price'); return; }
          if (!paym){ alert('Please enter payment method'); return; }
          var fd = new FormData();
          fd.append('action','complete_transaction');
          fd.append('transaction_id', tx.transaction_id||'');
          fd.append('listing_id', tx.listing_id||'');
          fd.append('seller_id', tx.seller_id||'');
          fd.append('buyer_id', tx.buyer_id||'');
          fd.append('result', result);
          fd.append('price', price);
          fd.append('payment_method', paym);
          if (file) fd.append('doc_photo', file);
          btnSubmitComplete.disabled = true; btnSubmitComplete.textContent = 'Submitting...';
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              if (!res || res.ok===false){
                alert('Failed to complete transaction'+(res && res.code? (' (code '+res.code+')') : ''));
                btnSubmitComplete.disabled = false; btnSubmitComplete.textContent = 'Submit';
              } else {
                if (result === 'successful'){
                  window.location.href = 'completion.php?buyer_id='+ encodeURIComponent(tx.buyer_id||'') + '&seller_id=' + encodeURIComponent(tx.seller_id||'') + '&transaction_id=' + encodeURIComponent(tx.transaction_id||'');
                  return;
                }
                alert('Transaction completion saved.');
                btnSubmitComplete.disabled = true; btnSubmitComplete.textContent = 'Saved';
                closeModal('completeModal');
                closeModal('txModal');
                location.reload();
              }
            })
            .catch(function(){ btnSubmitComplete.disabled = false; btnSubmitComplete.textContent = 'Submit'; });
        });
      }

      // Seller rating stars
      var currentRatingSeller = 5;
      function paintStarsSeller(n){
        var stars = document.querySelectorAll('#rateStarsSeller span');
        Array.prototype.forEach.call(stars, function(s){ var v=parseInt(s.getAttribute('data-v'),10); s.style.color = (v<=n)? '#f59e0b' : '#e2e8f0'; });
      }
      paintStarsSeller(currentRatingSeller);
      var starsWrapSeller = document.getElementById('rateStarsSeller');
      if (starsWrapSeller){
        starsWrapSeller.addEventListener('click', function(e){ var v=parseInt(e.target.getAttribute('data-v'),10); if(!isNaN(v)){ currentRatingSeller = v; paintStarsSeller(currentRatingSeller); } });
      }
      var btnSubmitRatingSeller = document.getElementById('btnSubmitRatingSeller');
      if (btnSubmitRatingSeller){
        btnSubmitRatingSeller.addEventListener('click', function(){
          var rm = document.getElementById('rateModalSeller');
          var buyerId = parseInt(rm.getAttribute('data-buyer')||'0',10) || 0;
          var txid = parseInt(rm.getAttribute('data-transaction')||'0',10) || 0;
          if (!(currentRatingSeller>=1 && currentRatingSeller<=5)) { alert('Rating must be 1-5'); return; }
          var fd = new FormData();
          fd.append('action','rate_buyer');
          fd.append('seller_id','<?php echo (int)($_SESSION['user_id'] ?? 0); ?>');
          fd.append('buyer_id', String(buyerId));
          fd.append('transaction_id', String(txid));
          fd.append('rating', String(currentRatingSeller));
          fd.append('description', (document.getElementById('rateDescSeller')||{}).value||'');
          btnSubmitRatingSeller.disabled = true; btnSubmitRatingSeller.textContent = 'Submitting...';
          fetch('transactions.php', { method:'POST', body: fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              if (!res || res.ok===false){
                alert('Failed to submit rating'+(res && res.code? (' (code '+res.code+')') : ''));
                btnSubmitRatingSeller.disabled = false; btnSubmitRatingSeller.textContent = 'Submit Rating';
              } else {
                btnSubmitRatingSeller.textContent = 'Thanks!';
                setTimeout(function(){ closeModal('rateModalSeller'); }, 600);
              }
            })
            .catch(function(){ btnSubmitRatingSeller.disabled = false; btnSubmitRatingSeller.textContent = 'Submit Rating'; });
        });
      }
    })();
  </script>
</body>
</html>
