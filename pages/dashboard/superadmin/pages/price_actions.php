<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'superadmin') { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }
$superadmin_id = $_SESSION['user_id'] ?? null; if (!$superadmin_id) { echo json_encode(['ok'=>false,'error'=>'No superadmin']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
$pricing_id = isset($input['pricing_id']) ? (int)$input['pricing_id'] : 0;
if ($pricing_id<=0) { echo json_encode(['ok'=>false,'error'=>'Missing pricing_id']); exit; }

// Load pending row
list($rows,$st,$err) = sb_rest('GET','pendingmarketpricing',[ 'select'=>'*', 'pricing_id'=>'eq.'.$pricing_id, 'limit'=>1 ]);
if ($err || $st>=400 || !is_array($rows) || !isset($rows[0])) { echo json_encode(['ok'=>false,'error'=>'Pending item not found']); exit; }
$it = $rows[0];

if ($action === 'approve') {
  $created = $it['created'] ?? null;
  $now = gmdate('c');
  // Ensure only one approved price per breed (and type): delete previous
  sb_rest('DELETE','approvedmarketpricing',[
    'breed_id'=>'eq.'.((int)$it['breed_id']),
    'livestocktype_id'=>'eq.'.((int)$it['livestocktype_id'])
  ], null, ['Prefer: return=minimal']);
  // Insert into approvedmarketpricing
  list($ires,$ist,$ierr) = sb_rest('POST','approvedmarketpricing',[],[[
    'livestocktype_id'=>$it['livestocktype_id'],
    'breed_id'=>$it['breed_id'],
    'marketprice'=>$it['marketprice'],
    'admin_id'=>$it['admin_id'],
    'superadmin_id'=>$superadmin_id,
    'status'=>'Approved',
    'created'=>$created,
    'approved_at'=>$now
  ]]);
  if ($ierr || ($ist<200 || $ist>=300)) { echo json_encode(['ok'=>false,'error'=>'Approve insert failed']); exit; }
  // Log
  sb_rest('POST','marketpricing_logs',[],[[
    'livestocktype_id'=>$it['livestocktype_id'],
    'breed_id'=>$it['breed_id'],
    'marketprice'=>$it['marketprice'],
    'admin_id'=>$it['admin_id'],
    'superadmin_id'=>$superadmin_id,
    'status'=>'Approved',
    'created'=>$created,
  ]], ['Prefer: return=minimal']);
  // Delete from pending
  sb_rest('DELETE','pendingmarketpricing',[ 'pricing_id'=>'eq.'.$pricing_id ], null, ['Prefer: return=minimal']);
  echo json_encode(['ok'=>true]);
  exit;
}

if ($action === 'deny') {
  $created = $it['created'] ?? null;
  $now = gmdate('c');
  // Insert into deniedmarketpricing
  list($ires,$ist,$ierr) = sb_rest('POST','deniedmarketpricing',[],[[
    'livestocktype_id'=>$it['livestocktype_id'],
    'breed_id'=>$it['breed_id'],
    'marketprice'=>$it['marketprice'],
    'admin_id'=>$it['admin_id'],
    'superadmin_id'=>$superadmin_id,
    'status'=>'Denied',
    'created'=>$created,
    'denied_at'=>$now
  ]]);
  if ($ierr || ($ist<200 || $ist>=300)) { echo json_encode(['ok'=>false,'error'=>'Deny insert failed']); exit; }
  // Log
  sb_rest('POST','marketpricing_logs',[],[[
    'livestocktype_id'=>$it['livestocktype_id'],
    'breed_id'=>$it['breed_id'],
    'marketprice'=>$it['marketprice'],
    'admin_id'=>$it['admin_id'],
    'superadmin_id'=>$superadmin_id,
    'status'=>'Denied',
    'created'=>$created,
    'denied_at'=>$now
  ]], ['Prefer: return=minimal']);
  // Delete from pending
  sb_rest('DELETE','pendingmarketpricing',[ 'pricing_id'=>'eq.'.$pricing_id ], null, ['Prefer: return=minimal']);
  echo json_encode(['ok'=>true]);
  exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
