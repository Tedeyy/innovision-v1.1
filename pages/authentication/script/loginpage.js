document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('login-btn');
  var dataEl = document.getElementById('login-data');
  var cd = 0;
  if (dataEl) {
    var val = dataEl.getAttribute('data-cooldown');
    cd = parseInt(val || '0', 10) || 0;
  }
  if (btn && cd > 0){
    btn.disabled = true;
    var span = document.getElementById('cooldown-secs');
    var left = cd;
    var timer = setInterval(function(){
      left -= 1;
      if (span) span.textContent = Math.max(0,left);
      if (left <= 0){
        clearInterval(timer);
        btn.disabled = false;
      }
    }, 1000);
  }

  // Password toggle functionality
  var togglePasswordBtn = document.getElementById('toggle-password');
  var passwordInput = document.getElementById('password');
  
  if (togglePasswordBtn && passwordInput) {
    togglePasswordBtn.addEventListener('click', function() {
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        togglePasswordBtn.textContent = 'Hide';
      } else {
        passwordInput.type = 'password';
        togglePasswordBtn.textContent = 'Show';
      }
    });
  }
});
