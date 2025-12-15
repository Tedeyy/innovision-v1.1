<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

header('Content-Type: application/json');

// Get action and limit parameters
$action = $_GET['action'] ?? '';
$limit = (int)($_GET['limit'] ?? 20);

// Fetch data from Supabase
function sa_get($table, $params) {
    [$rows, $st, $err] = sb_rest('GET', $table, $params);
    return ($st >= 200 && $st < 300 && is_array($rows)) ? $rows : [];
}

switch ($action) {
    case 'suspicious':
        $suspicious = sa_get('suspicious_login_attempts', [
            'select' => 'slogin_id,username,ip_address,incident_report,log_time',
            'order' => 'log_time.desc',
            'limit' => $limit
        ]);
        echo json_encode($suspicious);
        break;
        
    case 'blacklist':
        $blacklist = sa_get('blacklist', [
            'select' => 'block_id,ip_address,reason,admin_id,blocked_at',
            'order' => 'blocked_at.desc',
            'limit' => $limit
        ]);
        echo json_encode($blacklist);
        break;
        
    case 'events':
        $loginlogs = sa_get('login_logs', [
            'select' => 'login_id,user_id,ip_address,log_time',
            'order' => 'log_time.desc',
            'limit' => $limit
        ]);
        echo json_encode($loginlogs);
        break;
        
    default:
        echo json_encode([]);
        break;
}
?>
