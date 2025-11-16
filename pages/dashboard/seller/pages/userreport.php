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
  <title>Seller • User Report</title>
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
    <div class="nav-left"><div class="brand">User Report</div></div>
    <div class="nav-right"><a class="btn" href="../dashboard.php">Back to Dashboard</a></div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h1>Your Reports</h1>
      <div class="table-scroll">
        <table class="table-simple">
          <thead>
            <tr>
              <th>Date</th>
              <th>Report</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <tr><td colspan="3" style="color:#6b7280">No reports yet.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
