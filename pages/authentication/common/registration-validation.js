(function(){
  function $(sel, root){ return (root||document).querySelector(sel); }
  function createMsg(el){
    let m = el.parentElement.querySelector('.field-msg');
    if (!m){
      m = document.createElement('div');
      m.className = 'field-msg';
      m.style.fontSize = '0.9rem';
      m.style.margin = '6px 0';
      el.parentElement.insertBefore(m, el.nextSibling);
    }
    return m;
  }
  function debounce(fn, wait){ let t; return function(){ clearTimeout(t); t=setTimeout(()=>fn.apply(this, arguments), wait); } }

  function passwordCheck(pw){
    const re = /^(?=.*[A-Za-z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/;
    return re.test(pw);
  }

  function init(form){
    if (!form) return;
    const email = form.querySelector('input[name="email"]');
    const password = form.querySelector('input[name="password"]');
    const contact = form.querySelector('input[name="contact"]');
    const bdate = form.querySelector('input[name="bdate"]');
    const confirm = form.querySelector('input[name="confirm_password"]');
    const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');

    // Add Show/Hide toggle for password if one doesn't already exist
    if (password){
      const hasExistingToggle = !!form.querySelector('#toggle-password') || password.dataset.toggleAdded === '1';
      if (!hasExistingToggle){
        const wrapper = document.createElement('div');
        wrapper.style.display = 'flex';
        wrapper.style.gap = '8px';
        wrapper.style.alignItems = 'center';
        password.parentElement.insertBefore(wrapper, password);
        wrapper.appendChild(password);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'Show';
        btn.addEventListener('click', function(){
          if (password.type === 'password'){
            password.type = 'text';
            btn.textContent = 'Hide';
          } else {
            password.type = 'password';
            btn.textContent = 'Show';
          }
        });
        wrapper.appendChild(btn);
        password.dataset.toggleAdded = '1';
      }
    }

    // Email uniqueness
    if (email) {
      const msg = createMsg(email);
      const check = debounce(async function(){
        const val = email.value.trim();
        if (!val) { msg.textContent=''; email.setCustomValidity(''); return; }
        try {
          const api = window.location.origin + '/pages/authentication/api/check_email.php?email=' + encodeURIComponent(val);
          const r = await fetch(api, {headers:{'Accept':'application/json'}});
          const j = await r.json();
          if (j && j.exists){
            msg.textContent = 'Email already exists. Please use a different email.';
            msg.style.color = '#e53e3e';
            email.setCustomValidity('Email already exists');
          } else {
            msg.textContent = 'Email is available.';
            msg.style.color = '#16a34a';
            email.setCustomValidity('');
          }
        } catch(e){
          msg.textContent = 'Could not verify email right now.';
          msg.style.color = '#eab308';
          email.setCustomValidity('');
        }
      }, 500);
      email.addEventListener('input', check);
      email.addEventListener('blur', check);
    }

    // Username uniqueness
    const username = form.querySelector('input[name="username"]');
    if (username){
      const msgU = createMsg(username);
      const checkU = debounce(async function(){
        const val = username.value.trim();
        if (!val){ msgU.textContent=''; username.setCustomValidity(''); return; }
        try {
          const api = window.location.origin + '/pages/authentication/api/check_username.php?username=' + encodeURIComponent(val);
          const r = await fetch(api, {headers:{'Accept':'application/json'}});
          const j = await r.json();
          if (j && j.exists){
            msgU.textContent = 'Username already exists. Please choose another.';
            msgU.style.color = '#e53e3e';
            username.setCustomValidity('Username already exists');
          } else {
            msgU.textContent = 'Username is available.';
            msgU.style.color = '#16a34a';
            username.setCustomValidity('');
          }
        } catch(e){
          msgU.textContent = 'Could not verify username right now.';
          msgU.style.color = '#eab308';
          username.setCustomValidity('');
        }
      }, 500);
      username.addEventListener('input', checkU);
      username.addEventListener('blur', checkU);
    }

    // Password strength and confirm match
    if (password){
      const msg = createMsg(password);
      const onpw = function(){
        const ok = passwordCheck(password.value);
        if (!ok){
          msg.textContent = 'Password must be ≥ 8 chars with letters, numbers, and symbols.';
          msg.style.color = '#e53e3e';
          password.setCustomValidity('Weak password');
        } else {
          msg.textContent = 'Password looks good.';
          msg.style.color = '#16a34a';
          password.setCustomValidity('');
        }
        if (confirm){
          if (confirm.value && confirm.value !== password.value){
            confirm.setCustomValidity('Passwords do not match');
          } else {
            confirm.setCustomValidity('');
          }
        }
      };
      password.addEventListener('input', onpw);
      password.addEventListener('blur', onpw);
    }

    if (confirm && password){
      const msgc = createMsg(confirm);
      const oncf = function(){
        if (confirm.value !== password.value){
          msgc.textContent = 'Passwords do not match.';
          msgc.style.color = '#e53e3e';
          confirm.setCustomValidity('Passwords do not match');
        } else {
          msgc.textContent = '';
          confirm.setCustomValidity('');
        }
      };
      confirm.addEventListener('input', oncf);
      confirm.addEventListener('blur', oncf);
    }

    // Contact: limit to 11 digits
    if (contact){
      contact.setAttribute('maxlength','11');
      contact.addEventListener('input', function(){
        this.value = this.value.replace(/\D+/g,'').slice(0,11);
      });
    }

    // Birthdate: enforce YYYY-MM-DD (2d month/day, 4d year)
    if (bdate){
      const msg = createMsg(bdate);
      const validate = function(){
        const v = bdate.value.trim();
        // For type=date browsers, value is yyyy-mm-dd
        const ok = /^\d{4}-\d{2}-\d{2}$/.test(v);
        if (!ok){
          msg.textContent = 'Use format YYYY-MM-DD (year-4, month-2, day-2).';
          msg.style.color = '#e53e3e';
          bdate.setCustomValidity('Invalid date format');
        } else {
          msg.textContent = '';
          bdate.setCustomValidity('');
        }
      };
      bdate.addEventListener('input', validate);
      bdate.addEventListener('change', validate);
      bdate.addEventListener('blur', validate);
    }

    // Prevent submit if invalid
    form.addEventListener('submit', function(e){
      if (!form.checkValidity()){
        e.preventDefault();
        // trigger HTML5 validation messages
        const tmp = document.createElement('input');
        tmp.type = 'submit';
        tmp.style.display = 'none';
        form.appendChild(tmp);
        tmp.click();
        form.removeChild(tmp);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('form').forEach(init);
  });
})();
