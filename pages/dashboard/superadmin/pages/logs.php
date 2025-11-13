<?php
session_start();
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
$logs = [];
[$rows,$st,$err] = sb_rest('GET','livestocklisting_logs',['select'=>'*']);
if ($st>=200 && $st<300 && is_array($rows)) { $logs = $rows; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Livestock Listing Logs</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Logs</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h3>Livestock Listing Logs</h3>
      <div style="overflow:auto;">
        <table aria-label="Livestock Listing Logs" style="width:100%;border-collapse:collapse;">
          <thead>
            <tr>
              <?php
              $headers = [];
              if (is_array($logs) && count($logs)>0) { $headers = array_keys($logs[0]); }
              foreach ($headers as $h) {
                echo '<th style="text-align:left;border-bottom:1px solid #e5e7eb;padding:8px 6px;">'.htmlspecialchars($h, ENT_QUOTES, 'UTF-8').'</th>';
              }
              ?>
            </tr>
          </thead>
          <tbody>
            <?php if (is_array($logs)):
              foreach ($logs as $r): ?>
                <tr>
                  <?php foreach ($headers as $h): $v = isset($r[$h]) ? $r[$h] : ''; ?>
                    <td style="border-bottom:1px solid #f3f4f6;padding:8px 6px;"><?php echo htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
