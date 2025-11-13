<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

// Inline endpoint to sign and fetch supporting document URL
if (isset($_GET['doc']) && $_GET['doc'] === '1'){
  header('Content-Type: application/json');
  $role = $_GET['role'] ?? '';
  $fname = $_GET['fname'] ?? '';
  $mname = $_GET['mname'] ?? '';
  $lname = $_GET['lname'] ?? '';
  $created = $_GET['created'] ?? '';
  $email = $_GET['email'] ?? '';
  if (!in_array($role, ['buyer','seller','bat'], true)) { echo json_encode(['ok'=>false,'error'=>'invalid role']); exit; }
  if ($fname==='' || $lname===''){ echo json_encode(['ok'=>false,'error'=>'missing fields']); exit; }
  $fullname = trim($fname.' '.($mname?:'').' '.$lname);
  $san = strtolower(preg_replace('/[^a-z0-9]+/i','_', $fullname));
  $san = trim($san, '_');
  $esan = strtolower(preg_replace('/[^a-z0-9]+/i','_', $email));
  $esan = trim($esan, '_');
  $expectedEmailBase = ($san !== '' ? $san : 'user').'_'.$esan; // new pattern
  // Prepare legacy created-based base as fallback
  $createdStr = preg_replace('/[^0-9]/','', $created);
  if ($createdStr==='') { $t=strtotime($created); $createdStr = $t? date('YmdHis',$t) : ''; }
  $expectedCreatedBase = ($san !== '' ? $san : 'user').'_'.$createdStr;
  $base = function_exists('sb_base_url') ? sb_base_url() : (getenv('SUPABASE_URL') ?: '');
  $key = function_exists('sb_env') ? (sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '') : (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: (getenv('SUPABASE_KEY') ?: ''));
  // List objects under folder
  $listPrefix = $role.'/';
  $listUrl = rtrim($base,'/').'/storage/v1/object/list/reviewusers';
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $listUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [ 'apikey: '.$key, 'Authorization: Bearer '.$key, 'Content-Type: application/json' ],
    CURLOPT_POSTFIELDS => json_encode(['prefix' => $listPrefix])
  ]);
  $listRaw = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if (!($http>=200 && $http<300)) { echo json_encode(['ok'=>false,'error'=>'list http '.$http]); exit; }
  $items = json_decode($listRaw, true);
  if (!is_array($items)) { echo json_encode(['ok'=>false,'error'=>'bad list']); exit; }
  $objectName = null;
  foreach ($items as $it){
    $name = $it['name'] ?? '';
    // Supabase returns names relative to the prefix; match against expected base
    if ($name!=='' && $esan !== '' && strpos($name, $expectedEmailBase) === 0){ $objectName = $listPrefix.$name; break; }
  }
  // Fallback: legacy created-based filenames
  if (!$objectName && $createdStr !== ''){
    foreach ($items as $it){
      $name = $it['name'] ?? '';
      if ($name!=='' && strpos($name, $expectedCreatedBase) === 0){ $objectName = $listPrefix.$name; break; }
    }
  }
  if (!$objectName){ echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
  // Sign URL
  $signUrl = rtrim($base,'/').'/storage/v1/object/sign/reviewusers/'.rawurlencode($objectName);
  $signBody = json_encode(['expiresIn'=>300]);
  $ch2 = curl_init();
  curl_setopt_array($ch2,[
    CURLOPT_URL => $signUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [ 'apikey: '.$key, 'Authorization: Bearer '.$key, 'Content-Type: application/json' ],
    CURLOPT_POSTFIELDS => $signBody
  ]);
  $signRaw = curl_exec($ch2);
  $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
  curl_close($ch2);
  if (!($http2>=200 && $http2<300)) { echo json_encode(['ok'=>false,'error'=>'sign http '.$http2]); exit; }
  $sign = json_decode($signRaw, true);
  $signedUrl = isset($sign['signedURL']) ? $sign['signedURL'] : (isset($sign['signedUrl'])?$sign['signedUrl']:null);
  if (!$signedUrl){ echo json_encode(['ok'=>false,'error'=>'no signed url']); exit; }
  echo json_encode(['ok'=>true,'url'=> rtrim($base,'/').$signedUrl, 'name'=>$objectName]);
  exit;
}

$buyers = []; $sellers = []; $bats = [];
[$br,$bs,$be] = sb_rest('GET','reviewbuyer',['select'=>'*']); if ($bs>=200 && $bs<300 && is_array($br)) $buyers=$br;
[$sr,$ss,$se] = sb_rest('GET','reviewseller',['select'=>'*']); if ($ss>=200 && $ss<300 && is_array($sr)) $sellers=$sr;
[$tr,$ts,$te] = sb_rest('GET','reviewbat',['select'=>'*']); if ($ts>=200 && $ts<300 && is_array($tr)) $bats=$tr;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management</title>
  <link rel="stylesheet" href="style/usermanagement.css">
</head>
<body>
  <div class="wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
      <h2 style="margin:0">User Management</h2>
      <a href="../dashboard.php" style="text-decoration:none;background:#4a5568;color:#fff;padding:8px 12px;border-radius:8px">Back to Dashboard</a>
    </div>
    <div class="tabs">
      <button data-tab="buyer" class="active">Buyer</button>
      <button data-tab="seller">Seller</button>
      <button data-tab="bat">BAT</button>
    </div>
    <div id="tab-buyer" class="card">
      <table aria-label="Buyers">
        <thead><tr><th>Full name</th><th>Email</th><th>Contact</th><th>Address</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($buyers as $b): $full=trim(($b['user_fname']??'').' '.($b['user_mname']??'').' '.($b['user_lname']??'')); ?>
          <tr>
            <td><?php echo htmlspecialchars($full,ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($b['email']??'',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($b['contact']??'',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars(($b['address']??'').', '.($b['barangay']??'').', '.($b['municipality']??'').', '.($b['province']??''),ENT_QUOTES,'UTF-8'); ?></td>
            <td class="actions"><button class="btn show" data-role="buyer" data-fname="<?php echo htmlspecialchars($b['user_fname']??'',ENT_QUOTES,'UTF-8');?>" data-mname="<?php echo htmlspecialchars($b['user_mname']??'',ENT_QUOTES,'UTF-8');?>" data-lname="<?php echo htmlspecialchars($b['user_lname']??'',ENT_QUOTES,'UTF-8');?>" data-created="<?php echo htmlspecialchars($b['created']??'',ENT_QUOTES,'UTF-8');?>" data-email="<?php echo htmlspecialchars($b['email']??'',ENT_QUOTES,'UTF-8');?>" data-json='<?php echo json_encode($b, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'>Show</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="tab-seller" class="card" style="display:none">
      <table aria-label="Sellers">
        <thead><tr><th>Full name</th><th>Email</th><th>Contact</th><th>Address</th><th>RSBSA No.</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($sellers as $s): $full=trim(($s['user_fname']??'').' '.($s['user_mname']??'').' '.($s['user_lname']??'')); ?>
          <tr>
            <td><?php echo htmlspecialchars($full,ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($s['email']??'',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($s['contact']??'',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars(($s['address']??'').', '.($s['barangay']??'').', '.($s['municipality']??'').', '.($s['province']??''),ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($s['rsbsanum']??'',ENT_QUOTES,'UTF-8'); ?></td>
            <td class="actions"><button class="btn show" data-role="seller" data-fname="<?php echo htmlspecialchars($s['user_fname']??'',ENT_QUOTES,'UTF-8');?>" data-mname="<?php echo htmlspecialchars($s['user_mname']??'',ENT_QUOTES,'UTF-8');?>" data-lname="<?php echo htmlspecialchars($s['user_lname']??'',ENT_QUOTES,'UTF-8');?>" data-created="<?php echo htmlspecialchars($s['created']??'',ENT_QUOTES,'UTF-8');?>" data-email="<?php echo htmlspecialchars($s['email']??'',ENT_QUOTES,'UTF-8');?>" data-json='<?php echo json_encode($s, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'>Show</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="tab-bat" class="card" style="display:none">
      <table aria-label="BAT">
        <thead><tr><th>Full name</th><th>Email</th><th>Contact</th><th>Address</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($bats as $t): $full=trim(($t['user_fname']??'').' '.($t['user_mname']??'').' '.($t['user_lname']??'')); ?>
          <tr>
            <td><?php echo htmlspecialchars($full,ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($t['email']??'',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($t['contact']??'',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($t['address']??'',ENT_QUOTES,'UTF-8'); ?></td>
            <td class="actions"><button class="btn show" data-role="bat" data-fname="<?php echo htmlspecialchars($t['user_fname']??'',ENT_QUOTES,'UTF-8');?>" data-mname="<?php echo htmlspecialchars($t['user_mname']??'',ENT_QUOTES,'UTF-8');?>" data-lname="<?php echo htmlspecialchars($t['user_lname']??'',ENT_QUOTES,'UTF-8');?>" data-created="<?php echo htmlspecialchars($t['created']??'',ENT_QUOTES,'UTF-8');?>" data-email="<?php echo htmlspecialchars($t['email']??'',ENT_QUOTES,'UTF-8');?>" data-json='<?php echo json_encode($t, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>'>Show</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div id="detailModal" class="modal" role="dialog" aria-modal="true" aria-label="User details">
    <div class="panel">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h3 id="modalTitle" style="margin:0">Details</h3>
        <button id="closeModal" style="border:0;background:#e5e7eb;border-radius:8px;padding:6px 10px;cursor:pointer">Close</button>
      </div>
      <div id="detailBody"></div>
      <div class="doc">
        <div id="docStatus" style="color:#6b7280;font-size:14px">Loading document...</div>
        <div id="docPreview" style="margin-top:8px"></div>
      </div>
    </div>
  </div>

  <script>
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
    close.addEventListener('click', function(){ modal.style.display='none'; });
    modal.addEventListener('click', function(e){ if (e.target===modal) modal.style.display='none'; });

    function renderDetails(obj, role){
      var html = '';
      var fields = Object.keys(obj);
      var hide = { username:1, password:1 };
      fields.forEach(function(k){ if (hide[k]) return; html += row(k, esc(obj[k])); });
      document.getElementById('detailBody').innerHTML = '<div class="grid">'+html+'</div>';
    }

    function loadDoc(role, fname, mname, lname, created, email){
      var s = document.getElementById('docStatus');
      var box = document.getElementById('docPreview');
      s.textContent = 'Loading document...';
      box.innerHTML = '';
      var url = 'usermanagement.php?doc=1&role='+encodeURIComponent(role)+'&fname='+encodeURIComponent(fname)+'&mname='+encodeURIComponent(mname||'')+'&lname='+encodeURIComponent(lname)+'&created='+encodeURIComponent(created||'')+'&email='+encodeURIComponent(email||'');
      fetch(url, {credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
        if (!j.ok){ s.textContent = 'Document not available ('+(j.error||'unknown')+').'; return; }
        s.textContent = '';
        var u = j.url || '';
        if (u.match(/\.(pdf)(\?|$)/i)){
          var a = document.createElement('a'); a.href=u; a.textContent='Open document'; a.target='_blank'; box.appendChild(a);
        } else {
          var img = document.createElement('img'); img.src = u; img.alt='Supporting document'; img.style.maxWidth='100%'; img.style.border='1px solid #e5e7eb'; img.style.borderRadius='8px'; box.appendChild(img);
        }
      }).catch(function(){ s.textContent='Failed to load document.'; });
    }

    document.querySelectorAll('.show').forEach(function(btn){ btn.addEventListener('click', function(){
      var role = btn.dataset.role;
      var data = {};
      try { data = JSON.parse(btn.dataset.json); } catch(e){}
      document.getElementById('modalTitle').textContent = (data.user_fname||'')+' '+(data.user_lname||'');
      renderDetails(data, role);
      loadDoc(role, btn.dataset.fname||'', btn.dataset.mname||'', btn.dataset.lname||'', btn.dataset.created||'', btn.dataset.email||'');
      modal.style.display='flex';
    }); });
  })();
  </script>
</body>
</html>

