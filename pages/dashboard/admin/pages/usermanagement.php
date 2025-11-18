<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
require_once __DIR__ . '/../../../authentication/lib/use_case_logger.php';

function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
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
  $expectedNameBase = ($san !== '' ? $san : 'user'); // newest pattern: fullname only
  $expectedEmailBase = ($san !== '' ? $san : 'user').'_'.$esan; // fallback pattern
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
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
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
  // Prefer exact fullname (without extension) match
  foreach ($items as $it){
    $name = $it['name'] ?? '';
    if ($name==='') continue;
    $noext = pathinfo($name, PATHINFO_FILENAME);
    if ($noext === $expectedNameBase){ $objectName = $listPrefix.$name; break; }
  }
  // Fallback: email-based filenames
  if (!$objectName && $esan !== ''){
    foreach ($items as $it){
      $name = $it['name'] ?? '';
      if ($name!=='' && strpos($name, $expectedEmailBase) === 0){ $objectName = $listPrefix.$name; break; }
    }
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
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
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

// Inline endpoint to approve or deny a user
if (isset($_GET['decide'])){
  header('Content-Type: application/json');
  $action = $_GET['decide'];
  if (!in_array($action, ['approve','deny'], true)) { echo json_encode(['ok'=>false,'error'=>'invalid action']); exit; }
  $raw = file_get_contents('php://input');
  $req = json_decode($raw, true);
  if (!is_array($req)) { echo json_encode(['ok'=>false,'error'=>'invalid body']); exit; }
  $role = $req['role'] ?? '';
  $id = $req['id'] ?? null;
  $fname = $req['fname'] ?? '';
  $mname = $req['mname'] ?? '';
  $lname = $req['lname'] ?? '';
  $email = $req['email'] ?? '';
  $created = $req['created'] ?? '';
  if (!in_array($role, ['buyer','seller','bat'], true)) { echo json_encode(['ok'=>false,'error'=>'invalid role']); exit; }
  if (!is_numeric($id)) { echo json_encode(['ok'=>false,'error'=>'missing id']); exit; }
  $admin_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : (isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : null);
  if (!$admin_id) { echo json_encode(['ok'=>false,'error'=>'No admin id in session']); exit; }
  // Ensure admin_id exists in admin table (FK safety)
  [$ar,$ah,$ae] = sb_rest('GET','admin',['select'=>'user_id','user_id'=>'eq.'.$admin_id,'limit'=>'1']);
  if (!($ah>=200 && $ah<300 && is_array($ar) && count($ar)===1)){
    echo json_encode(['ok'=>false,'error'=>'Admin account not recognized or not verified.']); exit;
  }

  $srcTable = $role === 'buyer' ? 'reviewbuyer' : ($role === 'seller' ? 'reviewseller' : 'reviewbat');
  $destTable = null;
  if ($action === 'approve'){
    $destTable = $role === 'buyer' ? 'buyer' : ($role === 'seller' ? 'seller' : 'preapprovalbat');
  } else {
    $destTable = 'denieduser';
  }

  // Fetch source row
  [$rows,$st,$er] = sb_rest('GET', $srcTable, ['select'=>'*','user_id'=>'eq.'.$id]);
  if (!($st>=200 && $st<300 && is_array($rows) && count($rows)===1)){
    $detail = is_array($rows)? json_encode($rows) : (string)$rows;
    echo json_encode(['ok'=>false,'error'=>'source fetch failed (http '.$st.')','detail'=>$detail]); exit;
  }
  $row = $rows[0];

  // Preflight uniqueness checks in destination table on approve
  if ($action === 'approve'){
    $usernameVal = $row['username'] ?? '';
    if ($usernameVal !== ''){
      [$ur,$uh,$ue] = sb_rest('GET', $destTable, ['select'=>'user_id','username'=>'eq.'.$usernameVal,'limit'=>'1']);
      if ($uh>=200 && $uh<300 && is_array($ur) && count($ur)>0){ echo json_encode(['ok'=>false,'error'=>'Username already exists in '.$role.'.']); exit; }
    }
    $emailVal = $row['email'] ?? '';
    if ($emailVal !== ''){
      [$erows,$eh,$ee] = sb_rest('GET', $destTable, ['select'=>'user_id','email'=>'eq.'.$emailVal,'limit'=>'1']);
      if ($eh>=200 && $eh<300 && is_array($erows) && count($erows)>0){ echo json_encode(['ok'=>false,'error'=>'Email already exists in '.$role.'.']); exit; }
    }
    // Role-specific identifiers
    if ($role === 'seller'){
      $rsb = $row['rsbsanum'] ?? '';
      if ($rsb !== ''){
        [$rr,$rh,$re] = sb_rest('GET', $destTable, ['select'=>'user_id','rsbsanum'=>'eq.'.$rsb,'limit'=>'1']);
        if ($rh>=200 && $rh<300 && is_array($rr) && count($rr)>0){ echo json_encode(['ok'=>false,'error'=>'RSBSA number already exists in seller.']); exit; }
      }
    }
    $doc = $row['docnum'] ?? '';
    if ($doc !== ''){
      [$drw,$dhh,$dee] = sb_rest('GET', $destTable, ['select'=>'user_id','docnum'=>'eq.'.$doc,'limit'=>'1']);
      if ($dhh>=200 && $dhh<300 && is_array($drw) && count($drw)>0){ echo json_encode(['ok'=>false,'error'=>'Document number already exists in '.$role.'.']); exit; }
    }
    // Firstname+Lastname uniqueness per role
    $firstVal = $row['user_fname'] ?? '';
    $lastVal  = $row['user_lname'] ?? '';
    if ($firstVal !== '' && $lastVal !== ''){
      [$nr,$nh,$ne] = sb_rest('GET', $destTable, ['select'=>'user_id','user_fname'=>'eq.'.$firstVal,'user_lname'=>'eq.'.$lastVal,'limit'=>'1']);
      if ($nh>=200 && $nh<300 && is_array($nr) && count($nr)>0){ echo json_encode(['ok'=>false,'error'=>'A user with the same first and last name already exists in '.$role.'.']); exit; }
    }
  }

  // Build destination payload
  $payload = [];
  if ($action === 'approve'){
    if ($role === 'buyer'){
      $payload = [[
        'user_fname' => $row['user_fname'] ?? '',
        'user_mname' => $row['user_mname'] ?? '',
        'user_lname' => $row['user_lname'] ?? '',
        'bdate' => $row['bdate'] ?? '',
        'contact' => $row['contact'] ?? '',
        'address' => $row['address'] ?? '',
        'barangay' => $row['barangay'] ?? '',
        'municipality' => $row['municipality'] ?? '',
        'province' => $row['province'] ?? '',
        'email' => $row['email'] ?? '',
        'doctype' => $row['doctype'] ?? '',
        'docnum' => $row['docnum'] ?? '',
        'username' => $row['username'] ?? '',
        'password' => $row['password'] ?? '',
        'admin_id' => $admin_id
      ]];
    } elseif ($role === 'seller') {
      $payload = [[
        'user_fname' => $row['user_fname'] ?? '',
        'user_mname' => $row['user_mname'] ?? '',
        'user_lname' => $row['user_lname'] ?? '',
        'bdate' => $row['bdate'] ?? '',
        'contact' => $row['contact'] ?? '',
        'address' => $row['address'] ?? '',
        'barangay' => $row['barangay'] ?? '',
        'municipality' => $row['municipality'] ?? '',
        'province' => $row['province'] ?? '',
        'email' => $row['email'] ?? '',
        'rsbsanum' => $row['rsbsanum'] ?? '',
        'doctype' => $row['doctype'] ?? '',
        'docnum' => $row['docnum'] ?? '',
        'username' => $row['username'] ?? '',
        'password' => $row['password'] ?? '',
        'admin_id' => $admin_id
      ]];
    } else { // bat -> preapprovalbat
      $payload = [[
        'user_fname' => $row['user_fname'] ?? '',
        'user_mname' => $row['user_mname'] ?? '',
        'user_lname' => $row['user_lname'] ?? '',
        'bdate' => $row['bdate'] ?? '',
        'contact' => $row['contact'] ?? '',
        'address' => $row['address'] ?? '',
        'email' => $row['email'] ?? '',
        'assigned_barangay' => $row['assigned_barangay'] ?? ($row['barangay'] ?? ''),
        'doctype' => $row['doctype'] ?? '',
        'docnum' => $row['docnum'] ?? '',
        'username' => $row['username'] ?? '',
        'password' => $row['password'] ?? '',
        'admin_id' => $admin_id
      ]];
    }
  } else { // deny -> denieduser
    $payload = [[
      'user_fname' => $row['user_fname'] ?? '',
      'user_mname' => $row['user_mname'] ?? '',
      'user_lname' => $row['user_lname'] ?? '',
      'bdate' => $row['bdate'] ?? '',
      'contact' => $row['contact'] ?? '',
      'address' => $row['address'] ?? '',
      'barangay' => $row['barangay'] ?? ($row['assigned_barangay'] ?? ''),
      'municipality' => $row['municipality'] ?? '',
      'province' => $row['province'] ?? '',
      'email' => $row['email'] ?? '',
      'doctype' => $row['doctype'] ?? '',
      'docnum' => $row['docnum'] ?? '',
      'username' => $row['username'] ?? '',
      'password' => $row['password'] ?? '',
      'role' => $role,
      'admin_id' => $admin_id
    ]];
  }

  [$ires,$ist,$ier] = sb_rest('POST', $destTable, [], $payload, ['Prefer: return=representation']);
  if (!($ist>=200 && $ist<300)){
    $detail = is_array($ires)? json_encode($ires) : (string)$ires;
    echo json_encode(['ok'=>false,'error'=>'insert failed (http '.$ist.')','detail'=>$detail]); exit;
  }
  
  // Log use case: Admin approved/denied user
  $action_desc = $action === 'approve' ? 'User Approved by Admin' : 'User Denied by Admin';
  $purpose = format_use_case_description($action_desc, [
    'user_id' => $id,
    'role' => $role,
    'name' => trim(($fname ?: '') . ' ' . ($mname ?: '') . ' ' . ($lname ?: '')),
    'email' => $email,
    'username' => $row['username'] ?? ''
  ]);
  log_use_case($purpose);

  // Try to locate and move/copy image
  $base = function_exists('sb_base_url') ? sb_base_url() : (getenv('SUPABASE_URL') ?: '');
  $key = function_exists('sb_env') ? (sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '') : (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: (getenv('SUPABASE_KEY') ?: ''));
  $listPrefix = $role.'/';
  $listUrl = rtrim($base,'/').'/storage/v1/object/list/reviewusers';
  $fullname = trim(($fname).' '.($mname?:'').' '.($lname));
  $san = strtolower(preg_replace('/[^a-z0-9]+/i','_', $fullname));
  $san = trim($san, '_');
  $esan = strtolower(preg_replace('/[^a-z0-9]+/i','_', $email));
  $esan = trim($esan, '_');
  $expectedNameBase = ($san !== '' ? $san : 'user');
  $expectedEmailBase = ($san !== '' ? $san : 'user').'_'.$esan;
  $createdStr = preg_replace('/[^0-9]/','', $created);
  if ($createdStr==='') { $t=strtotime($created); $createdStr = $t? date('YmdHis',$t) : ''; }
  $expectedCreatedBase = ($san !== '' ? $san : 'user').'_'.$createdStr;
  $objectName = null;
  $ch = curl_init();
  curl_setopt_array($ch,[
    CURLOPT_URL => $listUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => [ 'apikey: '.$key, 'Authorization: Bearer '.$key, 'Content-Type: application/json' ],
    CURLOPT_POSTFIELDS => json_encode(['prefix'=>$listPrefix])
  ]);
  $listRaw = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($http>=200 && $http<300){
    $items = json_decode($listRaw, true);
    if (is_array($items)){
      // 1) fullname-only
      foreach ($items as $it){ $name = $it['name'] ?? ''; if ($name==='') continue; $noext = pathinfo($name, PATHINFO_FILENAME); if ($noext === $expectedNameBase){ $objectName = $listPrefix.$name; break; } }
      // 2) email-based fallback
      if (!$objectName && $esan!==''){
        foreach ($items as $it){ $name = $it['name'] ?? ''; if ($name!=='' && strpos($name, $expectedEmailBase)===0){ $objectName = $listPrefix.$name; break; } }
      }
      // 3) created-based legacy fallback
      if (!$objectName && $createdStr!==''){
        foreach ($items as $it){ $name = $it['name'] ?? ''; if ($name!=='' && strpos($name, $expectedCreatedBase)===0){ $objectName = $listPrefix.$name; break; } }
      }
    }
  }

  $moved = false;
  if ($objectName){
    // Download from reviewusers/<objectName>
    $srcUrl = rtrim($base,'/').'/storage/v1/object/reviewusers/'.rawurlencode($objectName);
    $chd = curl_init();
    curl_setopt_array($chd,[
      CURLOPT_URL => $srcUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 25,
      CURLOPT_HTTPHEADER => [ 'apikey: '.$key, 'Authorization: Bearer '.$key ]
    ]);
    $bytes = curl_exec($chd);
    $httpd = curl_getinfo($chd, CURLINFO_HTTP_CODE);
    curl_close($chd);
    if ($httpd>=200 && $httpd<300 && $bytes !== false){
      $destBucket = $action === 'approve' ? 'users' : 'deniedusers';
      $destPath = $role.'/'.basename($objectName);
      $upUrl = rtrim($base,'/').'/storage/v1/object/'.$destBucket.'/'.$destPath;
      $chu = curl_init();
      curl_setopt_array($chu,[
        CURLOPT_URL => $upUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [ 'apikey: '.$key, 'Authorization: Bearer '.$key, 'Content-Type: application/octet-stream', 'x-upsert: true' ],
        CURLOPT_POSTFIELDS => $bytes
      ]);
      $upr = curl_exec($chu);
      $uph = curl_getinfo($chu, CURLINFO_HTTP_CODE);
      curl_close($chu);
      if ($uph>=200 && $uph<300){
        // Remove source file to emulate move
        $rmUrl = rtrim($base,'/').'/storage/v1/object/reviewusers/'.rawurlencode($objectName);
        $chr = curl_init();
        curl_setopt_array($chr,[
          CURLOPT_URL => $rmUrl,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_CUSTOMREQUEST => 'DELETE',
          CURLOPT_CONNECTTIMEOUT => 10,
          CURLOPT_TIMEOUT => 20,
          CURLOPT_HTTPHEADER => [ 'apikey: '.$key, 'Authorization: Bearer '.$key ]
        ]);
        $rmr = curl_exec($chr);
        $rmh = curl_getinfo($chr, CURLINFO_HTTP_CODE);
        curl_close($chr);
        $moved = ($rmh>=200 && $rmh<300);
      }
    }
  }

  // Delete source review row
  [$dr,$dh,$de] = sb_rest('DELETE', $srcTable, ['user_id'=>'eq.'.$id]);
  if (!($dh>=200 && $dh<300)){
    echo json_encode(['ok'=>false,'error'=>'cleanup failed (http '.$dh.')']); exit;
  }

  echo json_encode(['ok'=>true,'moved'=>$moved]);
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
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:12px">
        <div style="display:flex;gap:8px">
          <button id="approveBtn" class="btn" style="background:#10b981;color:#fff;border:0;border-radius:8px;padding:8px 12px;cursor:pointer">Approve</button>
          <button id="denyBtn" class="btn" style="background:#ef4444;color:#fff;border:0;border-radius:8px;padding:8px 12px;cursor:pointer">Deny</button>
        </div>
        <div id="actionStatus" style="font-size:14px;color:#374151"></div>
      </div>
    </div>
  </div>

  <script src="script/usermanagement.js"></script>
  </body>
  </html>

