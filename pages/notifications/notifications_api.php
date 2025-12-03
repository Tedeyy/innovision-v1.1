<?php
session_start();
require_once __DIR__ . '/../authentication/lib/supabase_client.php';
header('Content-Type: application/json');
$uid = $_SESSION['user_id'] ?? null; $role = $_SESSION['role'] ?? null;
if (!$uid || !$role){ echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method==='GET'){
    [$rows,$st,$err] = sb_rest('GET','notifications',[ 'select'=>'notif_id,title,message,is_read,created_at,type,related_entity_id', 'recipient_id'=>'eq.'.$uid, 'recipient_role'=>'eq.'.$role, 'order'=>'created_at.desc', 'limit'=>'50' ]);
    $items = (is_array($rows)? $rows: []);
    $unread = 0; foreach($items as $it){ if(!$it['is_read']) $unread++; }
    echo json_encode(['ok'=>true,'items'=>$items,'unread'=>$unread]); exit;
}
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
if ($action==='mark_read'){
    $id = (int)($input['notif_id'] ?? 0);
    if ($id<=0){ echo json_encode(['ok'=>false,'error'=>'Invalid notif_id']); exit; }
    [$res,$st,$err] = sb_rest('PATCH','notifications',[ 'is_read'=>true ], [ 'notif_id'=>'eq.'.$id, 'recipient_id'=>'eq.'.$uid, 'recipient_role'=>'eq.'.$role ]);
    echo json_encode(['ok'=>($st>=200 && $st<300)]); exit;
}
if ($action==='mark_all_read'){
    [$res,$st,$err] = sb_rest('PATCH','notifications',[ 'is_read'=>true ], [ 'recipient_id'=>'eq.'.$uid, 'recipient_role'=>'eq.'.$role ]);
    echo json_encode(['ok'=>($st>=200 && $st<300)]); exit;
}
echo json_encode(['ok'=>false,'error'=>'Unknown action']);
