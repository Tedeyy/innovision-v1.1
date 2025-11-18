<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

if (($_SESSION['role'] ?? '') !== 'admin'){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}
$admin_id = $_SESSION['user_id'] ?? null;
if (!$admin_id){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}

$action = $_POST['action'] ?? '';
$listing_id = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
if (!$listing_id || !in_array($action, ['approve','deny'], true)){
  http_response_code(302);
  header('Location: listingmanagement.php');
  exit;
}

[$ires,$istatus,$ierr] = sb_rest('GET','livestocklisting',[
  'select'=>'listing_id,seller_id,livestock_type,breed,address,age,weight,price,status,bat_id,created',
  'listing_id'=>'eq.'.$listing_id,
  'limit'=>1
]);
if (!($istatus>=200 && $istatus<300) || !is_array($ires) || !isset($ires[0])){
  $_SESSION['flash_error'] = 'Listing not found or cannot load.';
  header('Location: listingmanagement.php');
  exit;
}
$rec = $ires[0];
$seller_id = (int)$rec['seller_id'];
$folder = $seller_id.'_'.((int)$rec['listing_id']);

$base = function_exists('sb_base_url') ? sb_base_url() : (getenv('SUPABASE_URL') ?: '');
$service = function_exists('sb_env') ? (sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '') : (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '');
$auth = $_SESSION['supa_access_token'] ?? ($service ?: (getenv('SUPABASE_KEY') ?: ''));

function storage_get($path, $auth, $base){
  $url = rtrim($base,'/').'/storage/v1/object/'.ltrim($path,'/');
  $ch = curl_init();
  curl_setopt_array($ch,[
    CURLOPT_URL=>$url,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>[
      'apikey: '.(function_exists('sb_anon_key')? sb_anon_key() : (getenv('SUPABASE_KEY') ?: '')),
      'Authorization: Bearer '.$auth,
    ]
  ]);
  $data = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  if (!($code>=200 && $code<300)) return [null,null,false];
  return [$data,$ct,true];
}
function storage_put($path, $bytes, $ct, $auth, $base){
  $url = rtrim($base,'/').'/storage/v1/object/'.ltrim($path,'/');
  $ch = curl_init();
  curl_setopt_array($ch,[
    CURLOPT_URL=>$url,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CUSTOMREQUEST=>'POST',
    CURLOPT_HTTPHEADER=>[
      'apikey: '.(function_exists('sb_anon_key')? sb_anon_key() : (getenv('SUPABASE_KEY') ?: '')),
      'Authorization: Bearer '.$auth,
      'Content-Type: '.($ct ?: 'application/octet-stream'),
      'x-upsert: true'
    ],
    CURLOPT_POSTFIELDS=>$bytes
  ]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [($code>=200 && $code<300), $code, $res];
}
function storage_delete($path, $auth, $base){
  $url = rtrim($base,'/').'/storage/v1/object/'.ltrim($path,'/');
  $ch = curl_init();
  curl_setopt_array($ch,[
    CURLOPT_URL=>$url,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CUSTOMREQUEST=>'DELETE',
    CURLOPT_HTTPHEADER=>[
      'apikey: '.(function_exists('sb_anon_key')? sb_anon_key() : (getenv('SUPABASE_KEY') ?: '')),
      'Authorization: Bearer '.$auth,
    ]
  ]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ($code>=200 && $code<300);
}

$destFolderRoot = ($action === 'approve') ? 'listings/verified' : 'listings/denied';
$okImages = true; $imageMissCount = 0; $imageMoveFail = 0; $imageMissingIdx = []; $imageFailedIdx = []; $imageFailedCodes = []; $imageMovedCount = 0;
// Build new-style folder and created key
$sfname='';$smname='';$slname='';
[$sinf,$sinfst,$sinfe] = sb_rest('GET','seller',[ 'select'=>'user_fname,user_mname,user_lname','user_id'=>'eq.'.$seller_id, 'limit'=>1 ]);
if ($sinfst>=200 && $sinfst<300 && is_array($sinf) && isset($sinf[0])){
  $sfname = (string)($sinf[0]['user_fname'] ?? '');
  $smname = (string)($sinf[0]['user_mname'] ?? '');
  $slname = (string)($sinf[0]['user_lname'] ?? '');
}
$fullname = trim($sfname.' '.($smname?:'').' '.$slname);
$sanFull = strtolower(preg_replace('/[^a-z0-9]+/i','_', $fullname));
$sanFull = trim($sanFull, '_');
if ($sanFull===''){ $sanFull='user'; }
$newFolder = $seller_id.'_'.$sanFull;
$legacyFolder = $folder; // sellerId_listingId
$createdKey = isset($rec['created']) ? date('YmdHis', strtotime($rec['created'])) : '';

for ($i=1; $i<=3; $i++){
  // Try new scheme first
  $src = 'listings/underreview/'.$newFolder.'/'.($createdKey!==''? ($createdKey.'_'.$i.'img.jpg') : ('image'.$i));
  $dst = $destFolderRoot.'/'.$newFolder.'/'.basename($src);
  list($bytes,$ct,$got) = storage_get($src, $auth, $base);
  if (!($got && $bytes!==null)){
    // fallback to legacy scheme
    $src = 'listings/underreview/'.$legacyFolder.'/image'.$i;
    $dst = $destFolderRoot.'/'.$legacyFolder.'/image'.$i;
    list($bytes,$ct,$got) = storage_get($src, $auth, $base);
  }
  if ($got && $bytes!==null){
    list($ok,$wcode,$wres) = storage_put($dst, $bytes, $ct, $auth, $base);
    if (!$ok){
      // Retry with service role if available and different from $auth
      $serviceRole = function_exists('sb_env') ? (sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '') : (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '');
      if ($serviceRole && $serviceRole !== $auth){
        list($ok2,$wcode2,$wres2) = storage_put($dst, $bytes, $ct, $serviceRole, $base);
        if ($ok2){
          storage_delete($src, $auth, $base);
        } else {
          $okImages = false; $imageMoveFail++; $imageFailedIdx[] = $i; $imageFailedCodes[$i] = $wcode2 ?: $wcode;
        }
      } else {
        $okImages = false; $imageMoveFail++; $imageFailedIdx[] = $i; $imageFailedCodes[$i] = $wcode;
      }
    } else {
      storage_delete($src, $auth, $base);
      $imageMovedCount++;
    }
  } else {
    $imageMissCount++; $imageMissingIdx[] = $i;
  }
}

// If approving and no images were moved at all, block approval with detailed error
if ($action === 'approve' && $imageMovedCount === 0){
  $parts = [];
  if (!empty($imageMissingIdx)) { $parts[] = 'Missing: '.implode(',', $imageMissingIdx); }
  if (!empty($imageFailedIdx)) {
    $failWithCodes = array_map(function($i) use ($imageFailedCodes){
      $code = isset($imageFailedCodes[$i]) ? (string)$imageFailedCodes[$i] : '?';
      return $i.'('.$code.')';
    }, $imageFailedIdx);
    $parts[] = 'Failed: '.implode(',', $failWithCodes);
  }
  $_SESSION['flash_error'] = 'Approval blocked: no images could be moved. '.(!empty($parts)? ('Details: '.implode(' | ', $parts)) : '');
  header('Location: listingmanagement.php');
  exit;
}

if ($action === 'approve'){
  $errors = [];
  $notices = [];
  $payload = [[
    'seller_id'=>(int)$rec['seller_id'],
    'livestock_type'=>$rec['livestock_type'],
    'breed'=>$rec['breed'],
    'address'=>$rec['address'],
    'age'=>(int)$rec['age'],
    'weight'=>(float)$rec['weight'],
    'price'=>(float)$rec['price'],
    'status'=>'Verified',
    'admin_id'=>(int)$admin_id,
    'bat_id'=> isset($rec['bat_id']) ? (int)$rec['bat_id'] : null,
    'created'=> $rec['created'] ?? null
  ]];
  [$ar,$as,$ae] = sb_rest('POST','activelivestocklisting',[], $payload, ['Prefer: return=representation']);
  if (!($as>=200 && $as<300)){
    $detail = '';
    if (is_array($ar) && isset($ar['message'])) { $detail = ' Detail: '.$ar['message']; }
    elseif (is_string($ar) && $ar !== '') { $detail = ' Detail: '.$ar; }
    $_SESSION['flash_error'] = 'Approve failed (code '.(string)$as.').'.$detail;
    header('Location: listingmanagement.php');
    exit;
  }
  // Insert location pin records using seller location and new active listing_id
  $new_listing_id = null;
  if (is_array($ar) && isset($ar[0]['listing_id'])){
    $new_listing_id = (int)$ar[0]['listing_id'];
  }
  if ($new_listing_id){
    // fetch seller location
    [$sres,$sstatus,$serr] = sb_rest('GET','seller',[ 'select'=>'location', 'user_id'=>'eq.'.((int)$rec['seller_id']), 'limit'=>1 ]);
    if ($sstatus>=200 && $sstatus<300 && is_array($sres) && isset($sres[0])){
      $locStr = $sres[0]['location'] ?? null;
      if ($locStr && $locStr!=='' && $locStr!=='null'){
        $pinPayload = [[ 'location'=>$locStr, 'listing_id'=>$new_listing_id ]];
        [$pr,$ps,$pe] = sb_rest('POST','activelocation_pins',[], $pinPayload, ['Prefer: return=representation']);
        if (!($ps>=200 && $ps<300)) {
          $d=''; if (is_array($pr) && isset($pr['message'])){ $d=' Detail: '.$pr['message']; } elseif (is_string($pr) && $pr!==''){ $d=' Detail: '.$pr; }
          $notices[] = 'Map pin insert failed (code '.(string)$ps.').'.$d;
        }
        [$lr,$ls,$le] = sb_rest('POST','location_pin_logs',[], $pinPayload, ['Prefer: return=representation']);
        if (!($ls>=200 && $ls<300)) {
          $d=''; if (is_array($lr) && isset($lr['message'])){ $d=' Detail: '.$lr['message']; } elseif (is_string($lr) && $lr!==''){ $d=' Detail: '.$lr; }
          $notices[] = 'Pin log insert failed (code '.(string)$ls.').'.$d;
        }
      }
    }
  }
  // log action: status Verified with admin_id (preserve bat_id if present)
  $logPayload = [[
    'seller_id'=>(int)$rec['seller_id'],
    'livestock_type'=>$rec['livestock_type'],
    'breed'=>$rec['breed'],
    'address'=>$rec['address'],
    'age'=>(int)$rec['age'],
    'weight'=>(float)$rec['weight'],
    'price'=>(float)$rec['price'],
    'status'=>'Verified',
    'admin_id'=>(int)$admin_id,
    'bat_id'=> isset($rec['bat_id']) ? (int)$rec['bat_id'] : null
  ]];
  [$lg,$lgs,$lge] = sb_rest('POST','livestocklisting_logs',[], $logPayload, ['Prefer: return=representation']);
  if (!($lgs>=200 && $lgs<300)) {
    $d=''; if (is_array($lg) && isset($lg['message'])){ $d=' Detail: '.$lg['message']; } elseif (is_string($lg) && $lg!==''){ $d=' Detail: '.$lg; }
    $notices[] = 'Listing log insert failed (code '.(string)$lgs.').'.$d;
  }
} else {
  $errors = [];
  $notices = [];
  $payload = [[
    'seller_id'=>(int)$rec['seller_id'],
    'livestock_type'=>$rec['livestock_type'],
    'breed'=>$rec['breed'],
    'address'=>$rec['address'],
    'age'=>(int)$rec['age'],
    'weight'=>(float)$rec['weight'],
    'price'=>(float)$rec['price'],
    'status'=>'Denied',
    'admin_id'=>(int)$admin_id,
    'bat_id'=> isset($rec['bat_id']) ? (int)$rec['bat_id'] : null
  ]];
  [$dr,$ds,$de] = sb_rest('POST','deniedlivestocklisting',[], $payload, ['Prefer: return=representation']);
  if (!($ds>=200 && $ds<300)){
    $detail = '';
    if (is_array($dr) && isset($dr['message'])) { $detail = ' Detail: '.$dr['message']; }
    elseif (is_string($dr) && $dr !== '') { $detail = ' Detail: '.$dr; }
    $_SESSION['flash_error'] = 'Deny failed (code '.(string)$ds.').'.$detail;
    header('Location: listingmanagement.php');
    exit;
  }
  // log action: status Denied with admin_id
  $logPayload = [[
    'seller_id'=>(int)$rec['seller_id'],
    'livestock_type'=>$rec['livestock_type'],
    'breed'=>$rec['breed'],
    'address'=>$rec['address'],
    'age'=>(int)$rec['age'],
    'weight'=>(float)$rec['weight'],
    'price'=>(float)$rec['price'],
    'status'=>'Denied',
    'admin_id'=>(int)$admin_id
  ]];
  [$lr,$ls,$le] = sb_rest('POST','livestocklisting_logs',[], $logPayload, ['Prefer: return=representation']);
  if (!($ls>=200 && $ls<300)) {
    $d=''; if (is_array($lr) && isset($lr['message'])){ $d=' Detail: '.$lr['message']; } elseif (is_string($lr) && $lr!==''){ $d=' Detail: '.$lr; }
    $notices[] = 'Listing log insert failed (code '.(string)$ls.').'.$d;
  }
}

[$delr,$dels,$dele] = sb_rest('DELETE','livestocklisting',[ 'listing_id'=>'eq.'.$listing_id ]);
if ($dels>=200 && $dels<300){
  $msg = ($action==='approve'?'Listing approved.':'Listing denied.');
  if (!$okImages){ $notices[] = 'Some images failed to move.'; }
  if ($imageMissCount===3){ $notices[] = 'No images were found in under review folder.'; }
  if (!empty($imageMissingIdx)) { $notices[] = 'Missing images: '.implode(',', $imageMissingIdx).'.'; }
  if (!empty($imageFailedIdx)) {
    $failWithCodes = array_map(function($i) use ($imageFailedCodes){
      $code = isset($imageFailedCodes[$i]) ? (string)$imageFailedCodes[$i] : '?';
      return $i.'('.$code.')';
    }, $imageFailedIdx);
    $notices[] = 'Failed images: '.implode(',', $failWithCodes).'.';
  }
  if (!empty($notices)) { $msg .= ' (' . implode(' ', $notices) . ')'; }
  $_SESSION['flash_message'] = $msg;
} else {
  $detail = '';
  if (is_array($delr) && isset($delr['message'])) { $detail = ' Detail: '.$delr['message']; }
  elseif (is_string($delr) && $delr !== '') { $detail = ' Detail: '.$delr; }
  $_SESSION['flash_error'] = 'Source cleanup failed (code '.(string)$dels.').'.$detail;
}

header('Location: listingmanagement.php');
