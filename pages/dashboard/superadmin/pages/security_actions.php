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
    
    error_log("Block IP: IP=$ip_address, Reason=$reason, AdminID=$admin_id");
    
    if (empty($ip_address) || empty($reason) || empty($admin_id)) {
        error_log("Missing fields: IP=" . (empty($ip_address) ? 'yes' : 'no') . ", Reason=" . (empty($reason) ? 'yes' : 'no') . ", Admin=" . (empty($admin_id) ? 'yes' : 'no'));
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    // Check if IP is already blacklisted
    [$existingRows, $existingStatus] = sb_rest('GET', 'blacklist', [
        'select' => '*',
        'ip_address' => 'eq.' . $ip_address
    ]);
    
    error_log("Existing check status: $existingStatus");
    
    // Check for existing IP or handle 409 conflict
    if (($existingStatus >= 200 && $existingStatus < 300 && !empty($existingRows)) || $existingStatus === 409) {
        echo json_encode(['success' => false, 'error' => 'IP address is already blacklisted']);
        exit;
    }
    
    // Add to blacklist table
    $blacklistData = [
        'ip_address' => $ip_address,
        'reason' => $reason,
        'admin_id' => (int)$admin_id
    ];
    
    error_log("Sending: " . json_encode($blacklistData));
    
    [$result, $status, $error] = sb_rest('POST', 'blacklist', [], $blacklistData);
    
    error_log("Response: status=$status, error=$error");
    
    if ($status >= 200 && $status < 300) {
        echo json_encode(['success' => true, 'message' => 'IP address blocked successfully']);
    } elseif ($status === 409) {
        echo json_encode(['success' => false, 'error' => 'IP address is already blacklisted']);
    } else {
        echo json_encode(['success' => false, 'error' => "Status: $status, Error: $error"]);
    }
    
} elseif ($action === 'unblock_ip') {
    $blockId = $input['block_id'] ?? 0;
    $ipAddress = $input['ip_address'] ?? '';
    
    if (empty($blockId) || empty($ipAddress)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    // Delete from blacklist table
    [$result, $status, $error] = sb_rest('DELETE', 'blacklist', ['block_id' => 'eq.' . $blockId]);
    
    if ($status >= 200 && $status < 300) {
        // Log the action
        $purpose = "IP Address Unblocked: " . $ipAddress;
        
        if (function_exists('log_use_case')) {
            log_use_case($purpose);
        } else {
            error_log("Security Action: " . $purpose);
        }
        
        echo json_encode(['success' => true, 'message' => 'IP address unblocked successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $error ?? 'Failed to unblock IP address']);
    }
    
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
?>
