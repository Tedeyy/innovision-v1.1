<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

// Check if user is superadmin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../dashboard.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_penalty') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $userRole = $_POST['user_role'] ?? '';
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $penaltyHours = (int)($_POST['penalty_hours'] ?? 0);
        $adminId = $_SESSION['user_id'] ?? 0;
        
        if ($userId > 0 && !empty($userRole) && !empty($title) && !empty($description) && $penaltyHours > 0) {
            $penaltyTime = date('Y-m-d H:i:s', time() + ($penaltyHours * 3600));
            
            $penaltyData = [
                'user_id' => $userId,
                'user_role' => $userRole,
                'title' => $title,
                'description' => $description,
                'admin_id' => $adminId,
                'penaltytime' => $penaltyTime
            ];
            
            [$result, $status, $error] = sb_rest('POST', 'penalty', [], [$penaltyData]);
            
            if ($status >= 200 && $status < 300) {
                $success = "Penalty added successfully!";
            } else {
                $error_msg = "Failed to add penalty: " . ($error ?? 'Unknown error');
            }
        } else {
            $error_msg = "Please fill all required fields.";
        }
    } elseif ($action === 'remove_penalty') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        
        if ($reportId > 0) {
            [$result, $status, $error] = sb_rest('DELETE', 'penalty', ['report_id' => 'eq.' . $reportId]);
            
            if ($status >= 200 && $status < 300) {
                $success = "Penalty removed successfully!";
            } else {
                $error_msg = "Failed to remove penalty: " . ($error ?? 'Unknown error');
            }
        }
    }
}

// Fetch current penalties
[$penalties, $status, $error] = sb_rest('GET', 'penalty', [
    'select' => '*',
    'order' => 'created.desc',
    'limit' => 50
]);

if (!($status >= 200 && $status < 300) || !is_array($penalties)) {
    $penalties = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Penalty Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../style/dashboard.css">
    <style>
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
        }
        .form-group textarea {
            height: 80px;
            resize: vertical;
        }
        .penalty-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .penalty-table th,
        .penalty-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .penalty-table th {
            background: #f3f4f6;
            font-weight: 600;
        }
        .status-active {
            color: #dc2626;
            font-weight: 500;
        }
        .status-expired {
            color: #6b7280;
            font-weight: 500;
        }
        .btn-remove {
            background: #dc2626;
            color: white;
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }
        .btn-remove:hover {
            background: #b91c1c;
        }
        .alert {
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        @media (max-width:640px){
            .form-group input, .form-group select, .form-group textarea {
                font-size: 16px; /* Prevent zoom on iOS */
            }
            .penalty-table {
                font-size: 12px;
            }
            .penalty-table th, .penalty-table td {
                padding: 6px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="brand">Penalty Management</div>
        </div>
        <div class="nav-right">
            <a class="btn" href="../dashboard.php">Back to Dashboard</a>
        </div>
    </nav>
    
    <div class="wrap">
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_msg)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h3>Add New Penalty</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_penalty">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>User ID:</label>
                        <input type="number" name="user_id" required>
                    </div>
                    
                    <div class="form-group">
                        <label>User Role:</label>
                        <select name="user_role" required>
                            <option value="">Select Role</option>
                            <option value="buyer">Buyer</option>
                            <option value="seller">Seller</option>
                            <option value="bat">BAT</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Title:</label>
                    <input type="text" name="title" required>
                </div>
                
                <div class="form-group">
                    <label>Description:</label>
                    <textarea name="description" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Penalty Duration (hours):</label>
                    <input type="number" name="penalty_hours" min="1" required>
                </div>
                
                <button type="submit" class="btn">Add Penalty</button>
            </form>
        </div>
        
        <div class="card">
            <h3>Current Penalties</h3>
            <?php if (empty($penalties)): ?>
                <p>No penalties found.</p>
            <?php else: ?>
                <table class="penalty-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Role</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Penalty Until</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($penalties as $penalty): ?>
                            <?php 
                                $penaltyTime = strtotime($penalty['penaltytime']);
                                $now = time();
                                $isActive = $penaltyTime > $now;
                                $status = $isActive ? 'Active' : 'Expired';
                                $statusClass = $isActive ? 'status-active' : 'status-expired';
                            ?>
                            <tr>
                                <td><?php echo (int)($penalty['user_id'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars($penalty['user_role'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($penalty['title'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars(substr($penalty['description'] ?? '', 0, 50)) . '...'; ?></td>
                                <td><?php echo htmlspecialchars($penalty['penaltytime'] ?? ''); ?></td>
                                <td><span class="<?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="remove_penalty">
                                            <input type="hidden" name="report_id" value="<?php echo (int)($penalty['report_id'] ?? 0); ?>">
                                            <button type="submit" class="btn-remove" onclick="return confirm('Remove this penalty?')">Remove</button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
