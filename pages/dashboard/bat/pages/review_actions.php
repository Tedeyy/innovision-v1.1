<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
require_once __DIR__ . '/../../../authentication/lib/use_case_logger.php';

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
if (($_SESSION['role'] ?? '') !== 'bat'){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}
$bat_id = $_SESSION['user_id'] ?? null;
if (!$bat_id){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}

$action = $_POST['action'] ?? '';
$listing_id = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
if (!$listing_id || !in_array($action, ['approve','deny'], true)){
  http_response_code(302);
  header('Location: review_listings.php');
  exit;
}

[$ires,$istatus,$ierr] = sb_rest('GET','reviewlivestocklisting',[
  'select'=>'listing_id,seller_id,livestock_type,breed,address,age,weight,price,created',
  'listing_id'=>'eq.'.$listing_id,
  'limit'=>1
]);
if (!($istatus>=200 && $istatus<300) || !is_array($ires) || !isset($ires[0])){
  $_SESSION['flash_error'] = 'Listing not found or cannot load.';
  header('Location: review_listings.php');
  exit;
}
$rec = $ires[0];

if ($action === 'approve'){
  $payload = [[
    'seller_id'=>(int)$rec['seller_id'],
    'livestock_type'=>$rec['livestock_type'],
    'breed'=>$rec['breed'],
    'address'=>$rec['address'],
    'age'=>(int)$rec['age'],
    'weight'=>(float)$rec['weight'],
    'price'=>(float)$rec['price'],
    'bat_id'=>(int)$bat_id,
    'status'=>'Pending',
    'created'=> $rec['created'] ?? null
  ]];
  [$ar,$as,$ae] = sb_rest('POST','livestocklisting',[], $payload, ['Prefer: return=representation']);
  if (!($as>=200 && $as<300)){
    $_SESSION['flash_error'] = 'Approve failed.';
  } else {
    // Log use case: BAT approved listing
    $purpose = format_use_case_description('Listing Approved by BAT', [
      'listing_id' => $listing_id,
      'seller_id' => $rec['seller_id'],
      'livestock_type' => $rec['livestock_type'],
      'breed' => $rec['breed'],
      'price' => '₱' . number_format((float)$rec['price'], 2),
      'address' => substr($rec['address'], 0, 100) . (strlen($rec['address']) > 100 ? '...' : '')
    ]);
    log_use_case($purpose);
    
    // log action: status Pending with bat_id
    $logPayload = [[
      'seller_id'=>(int)$rec['seller_id'],
      'livestock_type'=>$rec['livestock_type'],
      'breed'=>$rec['breed'],
      'address'=>$rec['address'],
      'age'=>(int)$rec['age'],
      'weight'=>(float)$rec['weight'],
      'price'=>(float)$rec['price'],
      'status'=>'Pending',
      'bat_id'=>(int)$bat_id
    ]];
    sb_rest('POST','livestocklisting_logs',[], $logPayload, ['Prefer: return=representation']);
    // remove from review table
    [$rr,$rs,$re] = sb_rest('DELETE','reviewlivestocklisting',[ 'listing_id'=>'eq.'.$listing_id ]);
    $_SESSION['flash_message'] = 'Listing approved.';
  }
} else if ($action === 'deny'){
  $payload = [[
    'seller_id'=>(int)$rec['seller_id'],
    'livestock_type'=>$rec['livestock_type'],
    'breed'=>$rec['breed'],
    'address'=>$rec['address'],
    'age'=>(int)$rec['age'],
    'weight'=>(float)$rec['weight'],
    'price'=>(float)$rec['price'],
    'bat_id'=>(int)$bat_id,
    'status'=>'Denied'
  ]];
  [$dr,$ds,$de] = sb_rest('POST','deniedlivestocklisting',[], $payload, ['Prefer: return=representation']);
  if (!($ds>=200 && $ds<300)){
    $_SESSION['flash_error'] = 'Deny failed.';
  } else {
    // Log use case: BAT denied listing
    $purpose = format_use_case_description('Listing Denied by BAT', [
      'listing_id' => $listing_id,
      'seller_id' => $rec['seller_id'],
      'livestock_type' => $rec['livestock_type'],
      'breed' => $rec['breed'],
      'price' => '₱' . number_format((float)$rec['price'], 2),
      'address' => substr($rec['address'], 0, 100) . (strlen($rec['address']) > 100 ? '...' : '')
    ]);
    log_use_case($purpose);
    
    // log action: status Denied with bat_id
    $logPayload = [[
      'seller_id'=>(int)$rec['seller_id'],
      'livestock_type'=>$rec['livestock_type'],
      'breed'=>$rec['breed'],
      'address'=>$rec['address'],
      'age'=>(int)$rec['age'],
      'weight'=>(float)$rec['weight'],
      'price'=>(float)$rec['price'],
      'status'=>'Denied',
      'bat_id'=>(int)$bat_id
    ]];
    sb_rest('POST','livestocklisting_logs',[], $logPayload, ['Prefer: return=representation']);
    $seller_id = (int)$rec['seller_id'];
    // Build new-style folder <seller_id>_<fullname_sanitized>
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
    $legacyFolder = $seller_id.'_'.((int)$rec['listing_id']);
    $createdKey = isset($rec['created']) ? date('YmdHis', strtotime($rec['created'])) : '';
    $base = function_exists('sb_base_url') ? sb_base_url() : (getenv('SUPABASE_URL') ?: '');
    $service = function_exists('sb_env') ? (sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '') : (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '');
    $auth = $_SESSION['supa_access_token'] ?? ($service ?: (getenv('SUPABASE_KEY') ?: ''));
    $okImages = true;
    $apikey = function_exists('sb_anon_key')? sb_anon_key() : (getenv('SUPABASE_KEY') ?: '');
    for ($i=1; $i<=3; $i++){
      // Try new scheme first
      $src = 'listings/underreview/'.$newFolder.'/'.($createdKey!==''? ($createdKey.'_'.$i.'img.jpg') : ('image'.$i));
      $dst = 'listings/denied/'.$newFolder.'/'.basename($src);
      $getUrl = rtrim($base,'/').'/storage/v1/object/'.ltrim($src,'/');
      $ch = curl_init();
      curl_setopt_array($ch,[
        CURLOPT_URL=>$getUrl,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>[
          'apikey: '.$apikey,
          'Authorization: Bearer '.$auth,
        ]
      ]);
      $bytes = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
      curl_close($ch);
      if (!($code>=200 && $code<300) || $bytes===false){
        // fallback to legacy path
        $src = 'listings/underreview/'.$legacyFolder.'/image'.$i;
        $dst = 'listings/denied/'.$legacyFolder.'/image'.$i;
        $getUrl = rtrim($base,'/').'/storage/v1/object/'.ltrim($src,'/');
        $ch = curl_init();
        curl_setopt_array($ch,[
          CURLOPT_URL=>$getUrl,
          CURLOPT_RETURNTRANSFER=>true,
          CURLOPT_HTTPHEADER=>[
            'apikey: '.$apikey,
            'Authorization: Bearer '.$auth,
          ]
        ]);
        $bytes = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
      }
      if ($code>=200 && $code<300 && $bytes!==false){
        $putUrl = rtrim($base,'/').'/storage/v1/object/'.ltrim($dst,'/');
        $ch2 = curl_init();
        curl_setopt_array($ch2,[
          CURLOPT_URL=>$putUrl,
          CURLOPT_RETURNTRANSFER=>true,
          CURLOPT_CUSTOMREQUEST=>'POST',
          CURLOPT_HTTPHEADER=>[
            'apikey: '.$apikey,
            'Authorization: Bearer '.$auth,
            'Content-Type: '.($ct ?: 'application/octet-stream'),
            'x-upsert: true'
          ],
          CURLOPT_POSTFIELDS=>$bytes
        ]);
        $res2 = curl_exec($ch2);
        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($code2>=200 && $code2<300){
          $delUrl = rtrim($base,'/').'/storage/v1/object/'.ltrim($src,'/');
          $ch3 = curl_init();
          curl_setopt_array($ch3,[
            CURLOPT_URL=>$delUrl,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CUSTOMREQUEST=>'DELETE',
            CURLOPT_HTTPHEADER=>[
              'apikey: '.$apikey,
              'Authorization: Bearer '.$auth,
            ]
          ]);
          curl_exec($ch3);
          curl_close($ch3);
        } else {
          $okImages = false;
        }
      }
    }
    // remove from review table
    [$rr2,$rs2,$re2] = sb_rest('DELETE','reviewlivestocklisting',[ 'listing_id'=>'eq.'.$listing_id ]);
    $_SESSION['flash_message'] = 'Listing denied.'.($okImages?'' : ' Some images failed to move.');
  }
}

header('Location: review_listings.php');
