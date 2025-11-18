document.addEventListener('DOMContentLoaded', function(){
  if (!('geolocation' in navigator)) { return; }
  var statusEl = document.getElementById('geoStatus');
  function setStatus(msg){ if (statusEl) statusEl.textContent = msg; }
  navigator.geolocation.getCurrentPosition(function(pos){
    var lat = pos.coords.latitude; var lng = pos.coords.longitude;
    setStatus('Your location: ' + lat.toFixed(6) + ', ' + lng.toFixed(6));
    try {
      if (window.buyerMap && window.L){
        window.buyerMap.setView([lat, lng], 15);
        L.marker([lat, lng]).addTo(window.buyerMap);
      }
    } catch(e){}
    var fd = new FormData(); fd.append('lat', lat); fd.append('lng', lng);
    fetch('../update_location.php', { method:'POST', body: fd, credentials:'same-origin' }).catch(function(){});
  }, function(){
    setStatus('Location access denied or unavailable.');
  }, { enableHighAccuracy:true, timeout:10000, maximumAge:300000 });
});
