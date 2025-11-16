<?php
session_start();
if (($_SESSION['role'] ?? '') !== 'seller'){
  http_response_code(302);
  header('Location: ../dashboard.php');
  exit;
}
require_once __DIR__ . '/../../../authentication/lib/supabase_client.php';
function safe($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seller • Price Watch</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <style>
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}
    th{font-weight:600;color:#374151}
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left"><div class="brand">Price Watch</div></div>
    <div class="nav-right"><a class="btn" href="../dashboard.php">Back to Dashboard</a></div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h1>Market Prices</h1>
      <div class="table-scroll">
        <table class="table-simple">
          <thead>
            <tr>
              <th>Type</th>
              <th>Breed</th>
              <th>Avg Price</th>
              <th>Min</th>
              <th>Max</th>
              <th>Last Updated</th>
            </tr>
          </thead>
          <tbody id="rows">
            <tr><td colspan="6" style="color:#6b7280">No data yet.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <script>
    // Placeholder: wire to your price source when ready
  </script>
</body>
</html>
