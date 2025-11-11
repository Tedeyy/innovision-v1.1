<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'buyer') {
    $isVerified = ($src === 'buyer');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/dashboard.css">
    </head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="brand">Dashboard</div>
            <form class="search" method="get" action="#">
                <input type="search" name="q" placeholder="Search" />
            </form>
        </div>
        <div class="nav-right">
            <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <a class="btn" href="../logout.php">Logout</a>
            <a class="profile" href="pages/profile.php" aria-label="Profile">
                <span class="avatar">👤</span>
            </a>
        </div>
    </nav>
    <div class="wrap">
        <div class="top">
            <div>
                <h1>Buyer Dashboard</h1>
            </div>
        </div>
        <div class="card">
            <p>Discover products and manage your orders here.</p>
        </div>
        <div id="geoStatus" style="margin-top:8px;color:#4a5568;font-size:14px"></div>
    </div>
    <script>
    (function(){
      if (!('geolocation' in navigator)) { return; }
      var statusEl = document.getElementById('geoStatus');
      function setStatus(msg){ if (statusEl) statusEl.textContent = msg; }
      navigator.geolocation.getCurrentPosition(function(pos){
        var lat = pos.coords.latitude; var lng = pos.coords.longitude;
        setStatus('Your location: ' + lat.toFixed(6) + ', ' + lng.toFixed(6));
        var fd = new FormData(); fd.append('lat', lat); fd.append('lng', lng);
        fetch('../update_location.php', { method:'POST', body: fd, credentials:'same-origin' }).catch(function(){});
      }, function(){
        setStatus('Location access denied or unavailable.');
      }, { enableHighAccuracy:true, timeout:10000, maximumAge:300000 });
    })();
    </script>
</body>
</html>