<?php
session_start();
$firstname = isset($_SESSION['firstname']) && $_SESSION['firstname'] !== '' ? $_SESSION['firstname'] : 'User';
// Compute verification status from role and source table
$role = $_SESSION['role'] ?? '';
$src  = $_SESSION['source_table'] ?? '';
$isVerified = false;
if ($role === 'bat') {
    $isVerified = ($src === 'bat');
} elseif ($role === 'buyer') {
    $isVerified = ($src === 'buyer');
} elseif ($role === 'seller') {
    $isVerified = ($src === 'seller');
} elseif ($role === 'admin') {
    $isVerified = ($src === 'admin');
} elseif ($role === 'superadmin') {
    $isVerified = ($src === 'superadmin');
}
$statusLabel = $isVerified ? 'Verified' : 'Under review';

require_once __DIR__ . '/../../authentication/lib/supabase_client.php';
$sellerId = $_SESSION['user_id'] ?? null;
$pendingCount = 0; $activeCount = 0; $soldCount = 0; $deniedCount = 0;
if ($sellerId){
    function table_count_for_seller($tables, $sellerId){
        foreach ($tables as $t){
            [$res,$st,$err] = sb_rest('GET', $t, ['select'=>'listing_id','seller_id'=>'eq.'.$sellerId]);
            if ($st>=200 && $st<300 && is_array($res)) return count($res);
        }
        return 0;
    }
    $pendingCount = table_count_for_seller(['reviewlivestocklisting','reviewlivestocklistings'], $sellerId);
    $activeCount  = table_count_for_seller(['livestocklisting','livestocklistings'], $sellerId);
    $soldCount    = table_count_for_seller(['soldlivestocklisting','soldlivestocklistings'], $sellerId);
    $deniedCount  = table_count_for_seller(['deniedlivestocklisting','deniedlivestocklistings'], $sellerId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/dashboard.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    </head>
<body>
    <nav class="navbar">
        <div class="nav-left">
            <div class="brand">Dashboard</div>
            <form class="search" method="get" action="#">
                <input type="search" name="q" placeholder="Search" />
            </form>
        </div>
        <div class="nav-center" style="display:flex;gap:16px;align-items:center;">
            <a class="btn" href="pages/pricewatch.php">Price Watch</a>
            <a class="btn" href="pages/userreport.php">User Report</a>
        </div>
        <div class="nav-right">
            <div class="greeting">hello <?php echo htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            <a class="btn" href="../logout.php">Logout</a>
            <a class="notify" href="#" aria-label="Notifications" title="Notifications">
                <span class="avatar">🔔</span>
            </a>
            <a class="profile" href="pages/profile.php" aria-label="Profile">
                <span class="avatar">👤</span>
            </a>
        </div>
    </nav>
    <div class="wrap">
        <div class="top">
            <div>
                <h1>Seller Dashboard</h1>
            </div>
            <div>
                <a class="btn" href="pages/createlisting.php">Create Listing</a>
            </div>
        </div>
        <div class="card">
            <p>Your Listings</p>
            <div class="listingstatus">
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:12px 0;">
                    <div class="card" style="padding:12px;">
                        <div style="color:#4a5568;font-size:12px;">Pending Review</div>
                        <div style="font-size:20px;font-weight:600;"><?php echo (int)$pendingCount; ?></div>
                    </div>
                    <div class="card" style="padding:12px;">
                        <div style="color:#4a5568;font-size:12px;">Active</div>
                        <div style="font-size:20px;font-weight:600;"><?php echo (int)$activeCount; ?></div>
                    </div>
                    <div class="card" style="padding:12px;">
                        <div style="color:#4a5568;font-size:12px;">Sold</div>
                        <div style="font-size:20px;font-weight:600;"><?php echo (int)$soldCount; ?></div>
                    </div>
                    <div class="card" style="padding:12px;">
                        <div style="color:#4a5568;font-size:12px;">Denied</div>
                        <div style="font-size:20px;font-weight:600;"><?php echo (int)$deniedCount; ?></div>
                    </div>
                </div>
                
            </div>
        </div>
        <div class="card">
            <h3 style="margin-top:0">Manage Listings</h3>
            <iframe src="pages/managelistings.php" style="width:100%;height:80vh;border:1px solid #e2e8f0;border-radius:8px;" loading="lazy"></iframe>
        </div>

        <div class="card">
            <h3>Service Coverage Map</h3>
            <div id="map" class="mapbox" aria-label="Map placeholder"></div>
            <div id="geoStatus" style="margin-top:8px;color:#4a5568;font-size:14px"></div>
        </div>

        <div class="card">
            <h3>Livestock Aid (Sales Projection)</h3>
            <div class="chartbox"><canvas id="salesLineChart"></canvas></div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="script/dashboard.js"></script>
</body>
</html>