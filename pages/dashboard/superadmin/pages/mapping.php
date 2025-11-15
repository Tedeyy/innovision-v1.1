<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Interactive Mapping</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../style/dashboard.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <div class="brand">Interactive Mapping</div>
    </div>
    <div class="nav-right">
      <a class="btn" href="../dashboard.php">Back to Dashboard</a>
    </div>
  </nav>
  <div class="wrap">
    <div class="card">
      <h3>Map</h3>
      <div id="map" class="mapbox"></div>
    </div>
    <div class="card">
      <h3>Filters</h3>
      <div style="display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:8px">
        <select id="role">
          <option value="">All</option>
          <option value="buyer">Buyer</option>
          <option value="seller">Seller</option>
        </select>
        <select id="livestock_type">
          <option value="">All Types</option>
        </select>
        <input id="q" type="search" placeholder="Search" />
        <button id="apply" class="btn">Apply</button>
      </div>
    </div>
    <div class="card">
      <h3>List</h3>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse" id="list">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #e2e8f0">
              <th style="padding:8px">ID</th>
              <th style="padding:8px">Name</th>
              <th style="padding:8px">Role</th>
              <th style="padding:8px">Lat</th>
              <th style="padding:8px">Lng</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var mapEl = document.getElementById('map');
      if (!mapEl || !window.L) return;
      var map = L.map(mapEl).setView([8.314209 , 124.859425], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(map);
      window.saMap = map;
    })();
  </script>
</body>
</html>
