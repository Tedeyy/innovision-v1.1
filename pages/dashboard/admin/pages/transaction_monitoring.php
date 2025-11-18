<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin','superadmin'], true)){
  header('Location: ../dashboard.php');
  exit;
}

[$logs,$st,$err] = sb_rest('GET','transactions_logs',[
  'select'=>'listing_id,seller_id,buyer_id,status,created_at',
  'order'=>'created_at.desc'
]);
if (!($st>=200 && $st<300) || !is_array($logs)) $logs = [];

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Transaction Monitoring</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <div class="wrap">
    <div class="top" style="margin-bottom:8px;">
      <div><h1>Transaction Monitoring</h1></div>
      <div>
        <a class="btn" href="../dashboard.php">Back to Dashboard</a>
      </div>
    </div>

    <div class="card">
      <table class="table" style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th>Listing ID</th>
            <th>Seller ID</th>
            <th>Buyer ID</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$logs || count($logs)===0): ?>
            <tr><td colspan="5" style="padding:10px;color:#4a5568;">No logs found.</td></tr>
          <?php else: ?>
            <?php foreach ($logs as $row): ?>
              <tr>
                <td><?php echo esc($row['listing_id'] ?? ''); ?></td>
                <td><?php echo esc($row['seller_id'] ?? ''); ?></td>
                <td><?php echo esc($row['buyer_id'] ?? ''); ?></td>
                <td><?php echo esc($row['status'] ?? ''); ?></td>
                <td><?php echo esc($row['created_at'] ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
