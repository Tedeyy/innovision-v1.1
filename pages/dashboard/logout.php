<?php
session_start();

// Attempt Supabase logout (revokes refresh token) if configured
require_once __DIR__ . '/../authentication/lib/supabase_client.php';
if (function_exists('sb_auth_logout')) { @sb_auth_logout(); }

// Clear all session variables
$_SESSION = [];

// If using cookies, invalidate the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// Destroy the session
@session_destroy();

// Redirect to site home
header('Location: ../../index.html');
exit;
?>
