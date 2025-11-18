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

if ($action === 'submit_report') {
    $seller_id = $input['seller_id'] ?? '';
    $buyer_id = $input['buyer_id'] ?? '';
    $title = $input['title'] ?? '';
    $description = $input['description'] ?? '';
    
    if (empty($seller_id) || empty($buyer_id) || empty($title) || empty($description)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    // Add to reviewreportuser table
    $reportData = [
        'seller_id' => $seller_id,
        'buyer_id' => $buyer_id,
        'title' => $title,
        'description' => $description,
        'Status' => 'Pending'
    ];
    
    [$result, $status, $error] = sb_rest('POST', 'reviewreportuser', [], [$reportData]);
    
    if ($status >= 200 && $status < 300) {
        // Log to reportuser_log table
        $logData = [
            'seller_id' => $seller_id,
            'buyer_id' => $buyer_id,
            'title' => $title,
            'description' => $description,
            'admin_id' => null, // No admin yet, pending review
            'Status' => 'Pending'
        ];
        
        sb_rest('POST', 'userreport_logs', [], [$logData]);
        
        echo json_encode(['success' => true, 'message' => 'Report submitted successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $error ?? 'Failed to submit report']);
    }
    
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
?>
