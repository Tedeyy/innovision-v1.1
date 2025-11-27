document.addEventListener('DOMContentLoaded', function(){
  // Handle login cooldown timer
  var btn = document.getElementById('login-btn');
  var dataEl = document.getElementById('login-data');
  var cd = 0;
  if (dataEl) {
    var val = dataEl.getAttribute('data-cooldown');
    cd = parseInt(val || '0', 10) || 0;
  }
  if (btn && cd > 0) {
    btn.disabled = true;
    var span = document.getElementById('cooldown-secs');
    var left = cd;
    var timer = setInterval(function(){
      left -= 1;
      if (span) span.textContent = Math.max(0, left);
      if (left <= 0) {
        clearInterval(timer);
        btn.disabled = false;
      }
    }, 1000);
  }

  // Password toggle functionality with eye icons
  const passwordInput = document.getElementById('password');
  const toggleButton = document.getElementById('toggle-password');
  const eyeIcon = document.getElementById('eye-icon');
  const eyeOffIcon = document.getElementById('eye-off-icon');

  if (toggleButton && passwordInput && eyeIcon && eyeOffIcon) {
    toggleButton.addEventListener('click', function() {
      const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
      passwordInput.setAttribute('type', type);
      
      if (type === 'text') {
        eyeIcon.style.display = 'none';
        eyeOffIcon.style.display = 'block';
        toggleButton.setAttribute('aria-label', 'Hide password');
      } else {
        eyeIcon.style.display = 'block';
        eyeOffIcon.style.display = 'none';
        toggleButton.setAttribute('aria-label', 'Show password');
      }
    });
  }
});
