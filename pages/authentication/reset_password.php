<?php
session_start();
require_once __DIR__ . '/lib/supabase_client.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f7fafc;margin:0;padding:0}
    .wrap{max-width:420px;margin:10vh auto;padding:20px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:18px}
    .title{margin:0 0 8px 0}
    .desc{color:#4a5568;font-size:14px;margin-bottom:12px}
    .field{display:flex;flex-direction:column;gap:6px;margin:10px 0}
    input{border:1px solid #e5e7eb;border-radius:8px;padding:10px;font-size:14px}
    .actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
    button{background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer}
    .ghost{background:#718096}
    .error{color:#b91c1c;font-size:14px;margin-top:8px}
    .success{color:#166534;font-size:14px;margin-top:8px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h2 class="title">Set a new password</h2>
      <div class="desc">Enter and confirm your new password.</div>
      <div id="msg" class="error" style="display:none"></div>
      <div class="field"><label>New password</label><input type="password" id="pw1" /></div>
      <div class="field"><label>Confirm password</label><input type="password" id="pw2" /></div>
      <div class="actions">
        <button class="ghost" id="btn-cancel" type="button">Cancel</button>
        <button id="btn-save" type="button">Update Password</button>
      </div>
      <div id="ok" class="success" style="display:none">Password updated. You can close this window.</div>
    </div>
  </div>
  <script>
    (function(){
      function getTokenFromHash(){
        var h = window.location.hash || '';
        if (h.startsWith('#')) h = h.slice(1);
        var params = new URLSearchParams(h);
        var type = params.get('type');
        var token = params.get('access_token');
        return (type === 'recovery' && token) ? token : null;
      }
      var token = getTokenFromHash();
      var msg = document.getElementById('msg');
      function showError(t){ if(!msg) return; msg.style.display='block'; msg.textContent=t; }
      document.getElementById('btn-cancel').addEventListener('click', function(){ window.close(); });
      document.getElementById('btn-save').addEventListener('click', async function(){
        var p1 = document.getElementById('pw1').value;
        var p2 = document.getElementById('pw2').value;
        if (!p1 || p1.length < 6) { return showError('Password must be at least 6 characters'); }
        if (p1 !== p2) { return showError('Passwords do not match'); }
        if (!token){ return showError('Missing or invalid recovery token. Open the link from your email again.'); }
        try{
          const res = await fetch('reset_password_submit.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: token, new_password: p1 })});
          const data = await res.json();
          if (!data.ok) { return showError(data.error || 'Failed to update password'); }
          document.getElementById('ok').style.display = 'block';
        }catch(e){ showError('Network error'); }
      });
    })();
  </script>
</body>
</html>
