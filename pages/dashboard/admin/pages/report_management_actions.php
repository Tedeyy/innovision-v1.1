<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
require_once __DIR__ . '/../../../common/notify.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$action = $input['action'];
$adminId = $_SESSION['user_id'] ?? null;

if ($action === 'approve_report') {
    $report_id = $input['report_id'] ?? '';
    $seller_id = $input['seller_id'] ?? '';
    $buyer_id = $input['buyer_id'] ?? '';
    
    if (empty($report_id) || empty($seller_id) || empty($buyer_id)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    // Get the report details from reviewreportuser
    [$reportRows, $reportStatus] = sb_rest('GET', 'reviewreportuser', [
        'select' => '*',
        'report_id' => 'eq.' . $report_id,
        'limit' => 1
    ]);
    
    if (!($reportStatus >= 200 && $reportStatus < 300) || empty($reportRows)) {
        echo json_encode(['success' => false, 'error' => 'Report not found']);
        exit;
    }
    
    $report = $reportRows[0];
    
    // Transfer to reportuser table
    $verifiedData = [
        'seller_id' => $report['seller_id'],
        'buyer_id' => $report['buyer_id'],
        'title' => $report['title'],
        'description' => $report['description'],
        'admin_id' => $adminId,
        'Status' => 'Verified',
        'created' => $report['created'],
        'verified' => date('Y-m-d H:i:s')
    ];
    
    [$result, $status, $error] = sb_rest('POST', 'reportuser', [], [$verifiedData]);
    
    if ($status >= 200 && $status < 300) {
        // Remove from reviewreportuser
        sb_rest('DELETE', 'reviewreportuser', [
            'report_id' => 'eq.' . $report_id
        ]);
        
        // Update log
        sb_rest('PATCH', 'userreport_logs', [
            'admin_id' => $adminId,
            'Status' => 'Verified',
            'verified' => date('Y-m-d H:i:s')
        ], [
            'report_id' => 'eq.' . $report_id
        ]);
        
        // Notify buyer and seller about verified report
        if (!empty($seller_id)) { notify_send((int)$seller_id,'seller','Report Verified','Your report has been verified by admin.', (int)$report_id,'report'); }
        if (!empty($buyer_id))  { notify_send((int)$buyer_id,'buyer','Report Verified','A report related to you has been verified.', (int)$report_id,'report'); }
        echo json_encode(['success' => true, 'message' => 'Report approved successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $error ?? 'Failed to approve report']);
    }
    
} elseif ($action === 'disregard_report') {
    $report_id = $input['report_id'] ?? '';
    
    if (empty($report_id)) {
        echo json_encode(['success' => false, 'error' => 'Missing report ID']);
        exit;
    }
    
    // Remove from reviewreportuser (do not transfer to reportuser)
    [$result, $status, $error] = sb_rest('DELETE', 'reviewreportuser', [
        'report_id' => 'eq.' . $report_id
    ]);
    
    if ($status >= 200 && $status < 300) {
        // Update log to show disregarded
        sb_rest('PATCH', 'userreport_logs', [
            'admin_id' => $adminId,
            'Status' => 'Disregarded',
            'verified' => date('Y-m-d H:i:s')
        ], [
            'report_id' => 'eq.' . $report_id
        ]);
        // Notify reporter that the report was disregarded (if buyer_id exists)
        if (!empty($buyer_id)) { notify_send((int)$buyer_id,'buyer','Report Disregarded','Your report was disregarded by admin.', (int)$report_id,'report'); }
        echo json_encode(['success' => true, 'message' => 'Report disregarded successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $error ?? 'Failed to disregard report']);
    }
    
} elseif ($action === 'apply_penalty') {
    $report_id = $input['report_id'] ?? '';
    $seller_id = $input['seller_id'] ?? '';
    $buyer_id = $input['buyer_id'] ?? '';
    $penalty_duration = $input['penalty_duration'] ?? '';
    
    if (empty($report_id) || empty($seller_id) || empty($buyer_id) || empty($penalty_duration)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    // Get the report details from reportuser
    [$reportRows, $reportStatus] = sb_rest('GET', 'reportuser', [
        'select' => '*',
        'report_id' => 'eq.' . $report_id,
        'limit' => 1
    ]);
    
    if (!($reportStatus >= 200 && $reportStatus < 300) || empty($reportRows)) {
        echo json_encode(['success' => false, 'error' => 'Verified report not found']);
        exit;
    }
    
    $report = $reportRows[0];
    
    // Calculate penalty end time
    $penaltyEndTime = calculatePenaltyEndTime($penalty_duration);
    
    // Add to penalty_log table
    $penaltyData = [
        'report_id' => $report_id,
        'seller_id' => $seller_id,
        'buyer_id' => $buyer_id,
        'title' => $report['title'],
        'description' => $report['description'],
        'admin_id' => $adminId,
        'penaltytime' => $penaltyEndTime,
        'created' => date('Y-m-d H:i:s'),
        'verified' => date('Y-m-d H:i:s')
    ];
    
    [$result, $status, $error] = sb_rest('POST', 'penalty_log', [], [$penaltyData]);
    
    if ($status >= 200 && $status < 300) {
        // Notify buyer and seller of penalty
        if (!empty($seller_id)) { notify_send((int)$seller_id,'seller','Penalty Applied','A penalty has been applied to your account.', (int)$report_id,'penalty'); }
        if (!empty($buyer_id))  { notify_send((int)$buyer_id,'buyer','Penalty Assigned to Seller','Admin has applied a penalty to the seller.', (int)$report_id,'penalty'); }
        echo json_encode(['success' => true, 'message' => 'Penalty applied successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $error ?? 'Failed to apply penalty']);
    }
    
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

function calculatePenaltyEndTime($duration) {
    $now = new DateTime();
    
    switch($duration) {
        case '3 days':
            $now->add(new DateInterval('P3D'));
            break;
        case '1 week':
            $now->add(new DateInterval('P7D'));
            break;
        case '1 month':
            $now->add(new DateInterval('P1M'));
            break;
        case '6 months':
            $now->add(new DateInterval('P6M'));
            break;
        case '1 year':
            $now->add(new DateInterval('P1Y'));
            break;
    }
    
    return $now->format('Y-m-d H:i:s');
}
?>
