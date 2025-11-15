<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../authentication/lib/supabase_client.php';

function json_fail($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function json_ok($extra=[]){ echo json_encode(array_merge(['ok'=>true], $extra)); exit; }

$role = $_SESSION['role'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;
if (!$role || !$user_id){ json_fail('Not authenticated', 401); }

$allowed = ['buyer'=>['buyer','reviewbuyer'], 'seller'=>['seller','reviewseller'], 'bat'=>['bat','preapprovalbat','reviewbat']];
if (!isset($allowed[$role])){ json_fail('Role not allowed for location'); }

$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
if ($lat === null || $lng === null){ json_fail('Missing coordinates'); }

// Determine target table: prefer session source_table if matches allowed, else find by user_id
$tables = $allowed[$role];
$table = null;
$source = $_SESSION['source_table'] ?? null;
if ($source && in_array($source, $tables, true)){
  $table = $source;
} else {
  foreach ($tables as $t){
    [$res, $status, $err] = sb_rest('GET', $t, ['select'=>'user_id','user_id'=>'eq.'.$user_id,'limit'=>1]);
    if ($status>=200 && $status<300 && is_array($res) && count($res)>0){ $table=$t; break; }
  }
}
if (!$table){ json_fail('User row not found for role', 404); }

// Try updating using common column variants.
$candidates = [
  ['lat'=>$lat,'lng'=>$lng],
  ['latitude'=>$lat,'longitude'=>$lng],
  ['location_lat'=>$lat,'location_lng'=>$lng],
  ['location'=>json_encode(['lat'=>$lat,'lng'=>$lng])],
];
$updated = false; $lastErr = null; $lastStatus = null;
foreach ($candidates as $body){
  [$r,$s,$e] = sb_rest('PATCH', $table, ['user_id'=>'eq.'.$user_id], [$body], ['Prefer: return=representation']);
  if ($s>=200 && $s<300){ $updated=true; break; }
  $lastErr = $e; $lastStatus = $s;
}
if (!$updated){ json_fail('Update failed', $lastStatus ?: 400); }

json_ok(['table'=>$table]);
