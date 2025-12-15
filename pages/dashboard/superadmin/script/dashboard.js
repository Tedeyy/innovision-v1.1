document.addEventListener('DOMContentLoaded', function(){
  (function(){
    if (!window.L) return;
    var mapEl = document.getElementById('map');
    if (!mapEl) return;
    var map = L.map(mapEl).setView([8.314209, 124.859425], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    window.superadminMap = map;
  })();

  (function(){
    if (!('geolocation' in navigator)) { return; }
    navigator.geolocation.getCurrentPosition(function(pos){
      var lat = pos.coords.latitude; var lng = pos.coords.longitude;
      try {
        if (window.superadminMap && window.L){
          window.superadminMap.setView([lat, lng], 15);
          L.marker([lat, lng]).addTo(window.superadminMap);
        }
      } catch(e){}
    }, function(err){}, { enableHighAccuracy:true, timeout:10000, maximumAge:300000 });
  })();
});
