document.addEventListener('DOMContentLoaded', function(){
  // Map init
  (function(){
    if (!window.L) return;
    var mapEl = document.getElementById('map');
    if (!mapEl) return;
    var map = L.map(mapEl).setView([8.314209 , 124.859425], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    window.sellerMap = map;
  })();

  // Chart init
  (function(){
    var ctx = document.getElementById('salesLineChart');
    if (!ctx || !window.Chart) return;
    function monthLabels(){
      const now = new Date();
      const labels = [];
      for (let i = -3; i <= 1; i++) {
        const d = new Date(now.getFullYear(), now.getMonth()+i, 1);
        labels.push(d.toLocaleString('en', { month: 'short' }));
      }
      return labels;
    }
    const labels = monthLabels();
    const empty = labels.map(() => null);
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          { label: 'Cattle', data: empty, borderColor: '#8B4513', backgroundColor: 'transparent', tension: 0.3, spanGaps: true, pointRadius: 0 },
          { label: 'Goat', data: empty, borderColor: '#16a34a', backgroundColor: 'transparent', tension: 0.3, spanGaps: true, pointRadius: 0 },
          { label: 'Pigs', data: empty, borderColor: '#ec4899', backgroundColor: 'transparent', tension: 0.3, spanGaps: true, pointRadius: 0 }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, suggestedMin: 0 } }
      }
    });
  })();

  // Geolocation
  (function(){
    if (!('geolocation' in navigator)) { return; }
    var statusEl = document.getElementById('geoStatus');
    function setStatus(msg){ if (statusEl) statusEl.textContent = msg; }
    navigator.geolocation.getCurrentPosition(function(pos){
      var lat = pos.coords.latitude; var lng = pos.coords.longitude;
      setStatus('Your location: ' + lat.toFixed(6) + ', ' + lng.toFixed(6));
      try {
        if (window.sellerMap && window.L){
          window.sellerMap.setView([lat, lng], 15);
          L.marker([lat, lng]).addTo(window.sellerMap);
        }
      } catch(e){}
      var fd = new FormData(); fd.append('lat', lat); fd.append('lng', lng);
      fetch('../update_location.php', { method:'POST', body: fd, credentials:'same-origin' }).catch(function(){});
    }, function(){
      setStatus('Location access denied or unavailable.');
    }, { enableHighAccuracy:true, timeout:10000, maximumAge:300000 });
  })();
});
