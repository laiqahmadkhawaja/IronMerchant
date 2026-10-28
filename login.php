<?php
// login.php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sign in · Zafar Brothers Iron Merchants POS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=Noto+Nastaliq+Urdu:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    #login-page {
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, var(--nv2), var(--nv) 60%, #2a4fb0);
      padding: 16px;
      position: relative;
      overflow: hidden;
    }
    #login-page::before {
      content: "";
      position: absolute;
      width: 400px; height: 400px;
      background: var(--am);
      border-radius: 50%;
      filter: blur(80px);
      opacity: 0.15;
      top: -100px; left: -100px;
    }
    #login-page::after {
      content: "";
      position: absolute;
      width: 350px; height: 350px;
      background: #5b8def;
      border-radius: 50%;
      filter: blur(80px);
      opacity: 0.15;
      bottom: -80px; right: -80px;
    }
    .login-card {
      background: var(--cd);
      border-radius: 20px;
      padding: 32px 28px;
      width: min(400px, 100%);
      text-align: center;
      border-top: 6px solid var(--am);
      box-shadow: 0 20px 50px rgba(0,0,0,0.35);
      position: relative;
      z-index: 10;
      animation: fadeIn .4s ease-out;
    }
    .login-card img { width: 84px; height: 84px; object-fit: contain; margin-bottom: 8px; }
    .login-card h1 { margin: 4px 0 0; color: var(--rd); font-size: 26px; }
    .login-card b { display: block; font-size: 15px; color: var(--nv); margin-top: 2px; }
    .login-card small { color: var(--mu); font-size: 12px; }
    .form-group { text-align: left; margin: 14px 0; }
    .form-group label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 12px; color: var(--mu); }
    .shake { animation: shake 0.4s ease-in-out; }
    @keyframes shake {
      20%, 60% { transform: translateX(-8px); }
      40%, 80% { transform: translateX(8px); }
    }
  </style>
</head>
<body id="login-page">
  <div class="login-card" id="lc">
    <img src="assets/images/logo.png" alt="Zafar Brothers Logo">
    <h1 class="ur">ظفر برادرز</h1>
    <b>Zafar Brothers Iron Merchants</b>
    <small>Gujranwala Steel Group of Industry</small>
    
    <div id="login-msg" style="display:none;margin-top:14px;padding:8px;border-radius:8px;font-size:12px;font-weight:600;"></div>

    <form id="login-form" onsubmit="doLogin(event)">
      <div class="form-group">
        <label>Select User</label>
        <select id="user-select" required></select>
      </div>

      <div class="form-group">
        <label>Enter PIN / Password</label>
        <input type="password" id="user-pin" placeholder="Enter PIN (e.g. 1234)" inputmode="numeric" autocomplete="off" required>
      </div>

      <button type="submit" class="b a" id="btn-login" style="width:100%;padding:11px;font-size:15px;margin-top:8px;">
        Sign in · لاگ ان
      </button>
    </form>
    
    <div style="margin-top:20px;font-size:11px;color:var(--mu);">
      Production-Ready Database POS &amp; Accounting<br>
      <span style="color:var(--gn);font-weight:600;">● MySQL Connected · Server Active</span>
    </div>
  </div>

  <script>
    async function loadUsers() {
      try {
        const res = await fetch('ajax/auth.php?action=list_users');
        const json = await res.json();
        if (json.success) {
          const sel = document.getElementById('user-select');
          sel.innerHTML = json.data.map(u => `<option value="${u.id}">${u.name} (${u.role})</option>`).join('');
        }
      } catch (err) {
        console.error('Failed to load users:', err);
      }
    }

    async function doLogin(e) {
      e.preventDefault();
      const userId = document.getElementById('user-select').value;
      const pin = document.getElementById('user-pin').value;
      const btn = document.getElementById('btn-login');
      const msg = document.getElementById('login-msg');
      const card = document.getElementById('lc');

      btn.disabled = true;
      btn.innerText = 'Signing in...';
      msg.style.display = 'none';

      const formData = new FormData();
      formData.append('user_id', userId);
      formData.append('pin', pin);

      try {
        const res = await fetch('ajax/auth.php?action=login', {
          method: 'POST',
          body: formData
        });
        const json = await res.json();
        if (json.success) {
          msg.style.display = 'block';
          msg.style.background = '#e6f9f0';
          msg.style.color = '#12a05a';
          msg.innerText = '✓ Login successful! Redirecting...';
          setTimeout(() => { window.location.href = 'index.php'; }, 400);
        } else {
          card.classList.remove('shake');
          void card.offsetWidth;
          card.classList.add('shake');
          msg.style.display = 'block';
          msg.style.background = '#fdebee';
          msg.style.color = '#d9262c';
          msg.innerText = json.message || 'Invalid PIN or password.';
          document.getElementById('user-pin').value = '';
          document.getElementById('user-pin').focus();
          btn.disabled = false;
          btn.innerText = 'Sign in · لاگ ان';
        }
      } catch (err) {
        msg.style.display = 'block';
        msg.style.background = '#fdebee';
        msg.style.color = '#d9262c';
        msg.innerText = 'Server error. Please check database connection.';
        btn.disabled = false;
        btn.innerText = 'Sign in · لاگ ان';
      }
    }

    loadUsers();
  </script>
</body>
</html>
