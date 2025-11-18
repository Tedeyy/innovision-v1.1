<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

// Check if user is logged in and is superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['userrole'] !== 'Superadmin') {
    header('Location: ../../../authentication/login.php');
    exit;
}

function safe($v) { 
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); 
}

// Fetch livestock listing logs
function fetchLivestockListingLogs() {
    [$logs, $status] = sb_rest('GET', 'livestocklisting_logs', [
        'select' => '*',
        'order' => 'created.desc'
    ]);
    
    if ($status >= 200 && $status < 300 && is_array($logs)) {
        return $logs;
    }
    return [];
}

// Fetch user names for IDs
function fetchUserNames($userIds, $role) {
    if (empty($userIds)) return [];
    
    $table = $role === 'admin' ? 'admin' : ($role === 'bat' ? 'bat' : 'seller');
    [$users, $status] = sb_rest('GET', $table, [
        'select' => 'user_id,user_fname,user_lname',
        'user_id' => 'in.(' . implode(',', $userIds) . ')'
    ]);
    
    if ($status >= 200 && $status < 300 && is_array($users)) {
        $names = [];
        foreach ($users as $user) {
            $names[$user['user_id']] = ($user['user_fname'] ?? '') . ' ' . ($user['user_lname'] ?? '');
        }
        return $names;
    }
    return [];
}

$logs = fetchLivestockListingLogs();

// Extract unique user IDs for name lookup
$sellerIds = array_unique(array_column($logs, 'seller_id'));
$adminIds = array_unique(array_filter(array_column($logs, 'admin_id')));
$batIds = array_unique(array_filter(array_column($logs, 'bat_id')));

$sellerNames = fetchUserNames($sellerIds, 'seller');
$adminNames = fetchUserNames($adminIds, 'admin');
$batNames = fetchUserNames($batIds, 'bat');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Livestock Listing Logs</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../style/dashboard.css">
    <style>
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }
        td {
            font-size: 14px;
            color: #4b5563;
        }
        tr:hover {
            background: #f9fafb;
        }
        .status-denied {
            background: #fef2f2;
            color: #dc2626;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }
        .pagination button {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 4px;
            cursor: pointer;
        }
        .pagination button:hover {
            background: #f3f4f6;
        }
        .pagination button.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="brand">Livestock Listing Logs</div>
        </div>
        <div class="nav-right">
            <a class="btn" href="../dashboard.php">Back to Dashboard</a>
        </div>
    </nav>

    <div class="wrap">
        <div class="card">
            <h1>Livestock Listing Logs</h1>
            <p style="color: #6b7280; margin-bottom: 20px;">
                View all livestock listing entries and their status history.
            </p>

            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 40px; color: #6b7280;">
                    No livestock listing logs found.
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Listing ID</th>
                                <th>Seller</th>
                                <th>Livestock Type</th>
                                <th>Breed</th>
                                <th>Address</th>
                                <th>Age</th>
                                <th>Weight (kg)</th>
                                <th>Price (₱)</th>
                                <th>Status</th>
                                <th>Admin</th>
                                <th>BAT</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo safe($log['listing_id'] ?? ''); ?></td>
                                    <td><?php echo safe($sellerNames[$log['seller_id']] ?? 'Unknown Seller'); ?></td>
                                    <td><?php echo safe($log['livestock_type'] ?? ''); ?></td>
                                    <td><?php echo safe($log['breed'] ?? ''); ?></td>
                                    <td><?php echo safe($log['address'] ?? ''); ?></td>
                                    <td><?php echo safe($log['age'] ?? ''); ?></td>
                                    <td><?php echo safe($log['weight'] ?? ''); ?></td>
                                    <td><?php echo safe($log['price'] ?? ''); ?></td>
                                    <td>
                                        <span class="status-denied">
                                            <?php echo safe($log['status'] ?? 'Denied'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo safe($adminNames[$log['admin_id']] ?? 'N/A'); ?></td>
                                    <td><?php echo safe($batNames[$log['bat_id']] ?? 'N/A'); ?></td>
                                    <td><?php echo safe($log['created'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <button>Previous</button>
                    <button class="active">1</button>
                    <button>Next</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Add any interactive functionality here
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Livestock Listing Logs page loaded');
        });
    </script>
</body>
</html>