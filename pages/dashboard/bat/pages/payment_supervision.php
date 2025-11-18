<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Supervision</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">BAT</div>
    </div>
    <div class="nav-right">
      <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?></div>
      <a class="btn" href="../../logout.php">Logout</a>
      <a class="profile" href="profile.php" aria-label="Profile"><span class="avatar">👤</span></a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h1 style="margin-top:0">Payment Supervision</h1>
      <p>Monitor and verify payments related to inspections and listings. Coming soon.</p>
    </div>
  </div>
</body>
</html>
