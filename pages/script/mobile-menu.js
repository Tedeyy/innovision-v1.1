document.addEventListener('DOMContentLoaded', function() {
    // Get elements
    const hamburger = document.querySelector('.hamburger');
    const mobileMenu = document.querySelector('.mobile-menu');
    const menuOverlay = document.querySelector('.menu-overlay');
    const body = document.body;
    
    // Touch variables for swipe detection
    let touchStartX = 0;
    let touchEndX = 0;
    let touchStartY = 0;
    let touchEndY = 0;
    const swipeThreshold = 50; // Minimum distance for a swipe
    const edgeThreshold = 30;  // Distance from edge to detect swipe
    let isSwiping = false;
    
    // Toggle menu function
    function toggleMenu() {
        hamburger.classList.toggle('active');
        mobileMenu.classList.toggle('active');
        menuOverlay.classList.toggle('active');
        body.classList.toggle('menu-open');
    }
    
    // Close menu function
    function closeMenu() {
        hamburger.classList.remove('active');
        mobileMenu.classList.remove('active');
        menuOverlay.classList.remove('active');
        body.classList.remove('menu-open');
    }
    
    // Hamburger click event
    if (hamburger) {
        hamburger.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleMenu();
        });
    }
    
    // Close menu when clicking on overlay or outside menu
    document.addEventListener('click', function(e) {
        if (mobileMenu.classList.contains('active') && 
            !mobileMenu.contains(e.target) && 
            !hamburger.contains(e.target)) {
            closeMenu();
        }
    });
    
    // Close menu when clicking on a menu item
    const menuItems = document.querySelectorAll('.mobile-menu a');
    menuItems.forEach(item => {
        item.addEventListener('click', function() {
            closeMenu();
        });
    });
    
    // Touch start handler
    document.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        isSwiping = true;
    }, { passive: true });
    
    // Touch move handler
    document.addEventListener('touchmove', function(e) {
        if (!isSwiping) return;
        
        touchEndX = e.touches[0].clientX;
        touchEndY = e.touches[0].clientY;
        
        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;
        
        // Only handle horizontal swipes
        if (Math.abs(deltaX) < Math.abs(deltaY)) {
            isSwiping = false;
            return;
        }
        
        // Prevent scrolling when swiping horizontally
        if (Math.abs(deltaX) > 10) {
            e.preventDefault();
        }
    }, { passive: false });
    
    // Touch end handler
    document.addEventListener('touchend', function() {
        if (!isSwiping) return;
        
        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;
        
        // Reset swiping flag
        isSwiping = false;
        
        // Check if it's a horizontal swipe
        if (Math.abs(deltaX) < Math.abs(deltaY)) return;
        
        // Swipe right from left edge to open
        if (touchStartX <= edgeThreshold && deltaX > swipeThreshold) {
            if (!mobileMenu.classList.contains('active')) {
                toggleMenu();
            }
        } 
        // Swipe left to close
        else if (deltaX < -swipeThreshold && mobileMenu.classList.contains('active')) {
            closeMenu();
        }
    }, { passive: true });
    
    // Close menu when window is resized to desktop size
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            closeMenu();
        }
    });
    
    // Close menu when pressing Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && mobileMenu.classList.contains('active')) {
            closeMenu();
        }
    });
});