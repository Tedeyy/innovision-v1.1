// Notification functionality
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.querySelector('.notify');
    const pane = document.getElementById('notifPane');
    const badge = document.getElementById('notifBadge');
    let isOpen = false;
    
    if (!btn || !pane) return;
    
    function updateBadge(count) {
        if (badge) {
            badge.textContent = count > 0 ? count : '';
            badge.style.display = count > 0 ? 'flex' : 'none';
        }
    }
    
    function togglePane() {
        isOpen = !isOpen;
        pane.style.display = isOpen ? 'block' : 'none';
        
        if (isOpen) {
            // Mark notifications as read when opened
            fetch('mark_notifications_read.php', {
                method: 'POST',
                credentials: 'same-origin'
            }).then(() => {
                updateBadge(0);
            });
        }
    }
    
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        togglePane();
    });
    
    // Close when clicking outside
    document.addEventListener('click', function() {
        if (isOpen) {
            isOpen = false;
            pane.style.display = 'none';
        }
    });
    
    // Initial badge update
    const initialCount = document.querySelectorAll('.notif-item').length;
    updateBadge(initialCount);
});
