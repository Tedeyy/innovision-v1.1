<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$action = $input['action'];

if ($action === 'block_ip') {
    $ip_address = $input['ip_address'] ?? '';
    $reason = $input['reason'] ?? '';
    $admin_id = $input['admin_id'] ?? '';
    
    if (empty($ip_address) || empty($reason) || empty($admin_id)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    // Check if IP is already blacklisted
    [$existingRows, $existingStatus] = sb_rest('GET', 'blacklist', [
        'select' => '*',
        'ip_address' => 'eq.' . $ip_address
    ]);
    
    if ($existingStatus >= 200 && $existingStatus < 300 && !empty($existingRows)) {
        echo json_encode(['success' => false, 'error' => 'IP address is already blacklisted']);
        exit;
    }
    
    // Add to blacklist table
    $blacklistData = [
        'ip_address' => $ip_address,
        'reason' => $reason,
        'admin_id' => $admin_id
    ];
    
    [$result, $status, $error] = sb_rest('POST', 'blacklist', [], [$blacklistData]);
    
    if ($status >= 200 && $status < 300) {
        // Log the action
        $purpose = "IP Address Blocked: " . $ip_address . " | Reason: " . $reason;
        
        // Create a simple log function if use_case_logger is not available
        if (function_exists('log_use_case')) {
            log_use_case($purpose);
        } else {
            error_log("Security Action: " . $purpose);
        }
        
        echo json_encode(['success' => true, 'message' => 'IP address blocked successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $error ?? 'Failed to block IP address']);
    }
    
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
?>
