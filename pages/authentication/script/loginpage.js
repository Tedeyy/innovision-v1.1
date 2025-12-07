document.addEventListener('DOMContentLoaded', function() {
  // Handle login cooldown timer
  const btn = document.getElementById('login-btn');
  const dataEl = document.getElementById('login-data');
  let cd = 0;
  
  if (dataEl) {
    const val = dataEl.getAttribute('data-cooldown');
    cd = parseInt(val || '0', 10) || 0;
  }
  
  if (btn && cd > 0) {
    btn.disabled = true;
    const span = document.getElementById('cooldown-secs');
    let left = cd;
    const timer = setInterval(function() {
      left -= 1;
      if (span) span.textContent = Math.max(0, left);
      if (left <= 0) {
        clearInterval(timer);
        btn.disabled = false;
      }
    }, 1000);
  }

  // Password toggle functionality
  const passwordInput = document.getElementById('password');
  const toggleButton = document.getElementById('toggle-password');
  const eyeIcon = document.getElementById('eye-icon');
  const eyeOffIcon = document.getElementById('eye-off-icon');

  if (toggleButton && passwordInput && eyeIcon && eyeOffIcon) {
    // Toggle password visibility
    const togglePasswordVisibility = () => {
      const isPassword = passwordInput.type === 'password';
      
      // Toggle input type
      passwordInput.type = isPassword ? 'text' : 'password';
      
      // Toggle icon visibility
      eyeIcon.style.display = isPassword ? 'none' : 'block';
      eyeOffIcon.style.display = isPassword ? 'block' : 'none';
      
      // Update ARIA label
      const label = isPassword ? 'Hide password' : 'Show password';
      toggleButton.setAttribute('aria-label', label);
    };

    // Click handler
    toggleButton.addEventListener('click', togglePasswordVisibility);

    // Keyboard navigation (Enter/Space)
    toggleButton.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        togglePasswordVisibility();
      }
    });

    // Toggle on/off when input has focus and user presses Alt+Shift+P
    passwordInput.addEventListener('keydown', function(e) {
      if (e.altKey && e.shiftKey && e.key.toLowerCase() === 'p') {
        e.preventDefault();
        togglePasswordVisibility();
      }
    });
  }
});
