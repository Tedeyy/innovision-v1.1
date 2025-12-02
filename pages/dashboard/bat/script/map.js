// Map functionality
document.addEventListener('DOMContentLoaded', function() {
    const mapEl = document.getElementById('batMap');
    if (!mapEl) return;
    
    // Initialize the map
    const map = L.map('batMap').setView([14.5995, 120.9842], 12); // Default to Manila
    
    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);
    
    // Add user's location marker if available
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
            const userLatLng = [position.coords.latitude, position.coords.longitude];
            L.marker(userLatLng, {
                icon: L.divIcon({
                    html: '<div style="background: #4a90e2; width: 12px; height: 12px; border-radius: 50%; border: 2px solid white; box-shadow: 0 0 10px rgba(0,0,0,0.2);"></div>',
                    className: 'user-location-marker',
                    iconSize: [16, 16],
                    iconAnchor: [8, 8]
                })
            }).addTo(map).bindPopup('Your Location').openPopup();
            
            // Center map on user's location
            map.setView(userLatLng, 14);
            
            // Update location in database
            const fd = new FormData();
            fd.append('lat', position.coords.latitude);
            fd.append('lng', position.coords.longitude);
            fetch('update_location.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        }, function() {
            // Handle location error
            console.warn('Could not get user location');
        }, { enableHighAccuracy: true });
    }
    
    // Add markers for active listings
    fetch('get_active_listings.php')
        .then(response => response.json())
        .then(data => {
            if (data && Array.isArray(data)) {
                data.forEach(listing => {
                    if (listing.lat && listing.lng) {
                        const marker = L.marker([listing.lat, listing.lng]).addTo(map);
                        let popupContent = `<strong>${listing.livestock_type || 'Livestock'}</strong>`;
                        if (listing.breed) popupContent += `<br>Breed: ${listing.breed}`;
                        if (listing.price) popupContent += `<br>Price: ₱${parseFloat(listing.price).toLocaleString()}`;
                        if (listing.address) popupContent += `<br><small>${listing.address}</small>`;
                        marker.bindPopup(popupContent);
                    }
                });
            }
        })
        .catch(error => console.error('Error loading listings:', error));
});
