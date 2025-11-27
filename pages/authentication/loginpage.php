<?php
// Map error and cooldown from query to variables used in the template
$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
$cooldown = isset($_GET['cooldown']) ? max(0, (int)$_GET['cooldown']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login to InnoVision</title>
    <link rel="stylesheet" href="style/loginpage.css">
</head>
<body>
    <div class="login-container">
        <form action="login.php" method="post">
            <h2>Login to InnoVision</h2>
            <?php if (isset($_GET['submitted']) && $_GET['submitted'] === '1'): ?>
            <div class="register-link" style="color:#16a34a; font-weight:600; margin:8px 0;">
                Registration submitted. Please check your email to confirm your account.
            </div>
            <?php endif; ?>
            <?php if ($cooldown > 0): ?>
            <div class="register-link" style="color:#e53e3e; font-weight:600; margin:8px 0;">
                Too many failed attempts. Please wait <span id="cooldown-secs"><?php echo (int)$cooldown; ?></span> seconds.
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['confirmed']) && $_GET['confirmed'] === '1'): ?>
            <div class="register-link" style="color:#16a34a; font-weight:600; margin:8px 0;">
                Registration confirmed. You can now log in.
            </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password:</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required>
                    <button type="button" id="toggle-password" class="toggle-password" aria-label="Show password">
                        <svg id="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg id="eye-off-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </button>
                </div>
            </div>
            
            <button type="submit" id="login-btn" name="login" value="login">Login</button>
            
            <?php if (!empty($error)): ?>
            <div class="register-link" style="color:#e53e3e; font-weight:600;">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            <div class="register-link">
                <p>Don't have an account? <a href="registrationpage.html">Register here</a></p>
            </div>
        </form>
    </div>
    <div id="login-data" data-cooldown="<?php echo (int)$cooldown; ?>" hidden></div>
    <script src="script/loginpage.js"></script>
</body>
</html>