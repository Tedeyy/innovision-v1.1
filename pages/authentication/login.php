<?php
session_start();

function loadEnvValue($key){
    $dir = __DIR__;
    for ($i=0; $i<6; $i++) {
        $candidate = $dir.DIRECTORY_SEPARATOR.'.env';
        if (is_file($candidate)) {
            $lines = @file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                foreach ($lines as $line) {
                    if (strpos(ltrim($line), '#') === 0) continue;
                    $pos = strpos($line, '=');
                    if ($pos === false) continue;
                    $k = trim(substr($line, 0, $pos));
                    if ($k === $key) {
                        $v = trim(substr($line, $pos+1));
                        $v = trim($v, "\"' ");
                        return $v;
                    }
                }
            }
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return null;
}

function redirect_with_error($msg){
    $loc = 'loginpage.php?error='.urlencode($msg);
    header('Location: '.$loc);
    exit;
}

function redirect_with_error_and_cooldown($msg, $seconds){
    if ($seconds < 0) { $seconds = 0; }
    $loc = 'loginpage.php?error='.urlencode($msg).'&cooldown='.(int)$seconds;
    header('Location: '.$loc);
    exit;
}

function client_ip(){
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return substr($ip, 0, 100);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_error('Invalid request');
}

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? (string)$_POST['password'] : '';
if ($username === '' || $password === ''){
    redirect_with_error('Missing username or password');
}

$SUPABASE_URL = getenv('SUPABASE_URL') ?: loadEnvValue('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: loadEnvValue('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: loadEnvValue('SUPABASE_KEY');
if (!$SUPABASE_URL || !$SUPABASE_KEY){
    redirect_with_error('Server configuration error');
}

// Simple in-session throttle per username+IP
$ip = client_ip();
$_SESSION['login_fail'] = $_SESSION['login_fail'] ?? [];
$key = $username.'|'.$ip;
$now = time();
$entry = $_SESSION['login_fail'][$key] ?? ['fails'=>0,'lock_until'=>0];
if ($entry['lock_until'] > $now){
    $seconds = $entry['lock_until'] - $now;
    redirect_with_error_and_cooldown('Too many failed attempts. Try again later.', $seconds);
}

function fetch_user_by_username($table, $username, $baseUrl, $apiKey){
    $url = rtrim($baseUrl,'/').'/rest/v1/'.rawurlencode($table).'?select=*&username=eq.'.rawurlencode($username).'&limit=1';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: '.$apiKey,
            'Authorization: Bearer '.$apiKey,
            'Accept: application/json',
        ],
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $http >= 400) {
        return null;
    }
    $data = json_decode($res, true);
    if (is_array($data) && isset($data[0])) return $data[0];
    return null;
}

function insert_login_log($baseUrl, $apiKey, $userId, $ip, $userRole){
    $payload = [[
        'user_id' => (int)$userId,
        'ip_address' => $ip,
        'user_role' => (string)$userRole
    ]];
    $url = rtrim($baseUrl,'/').'/rest/v1/login_logs';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: '.$apiKey,
            'Authorization: Bearer '.$apiKey,
            'Content-Type: application/json',
            'Prefer: return=minimal'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function insert_suspicious_log($baseUrl, $apiKey, $username, $ip){
    $payload = [[
        'username' => $username,
        'ip_address' => $ip,
        'incident_report' => 'Unsucessful Login Attempts'
    ]];
    $url = rtrim($baseUrl,'/').'/rest/v1/suspicious_login_attempts';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: '.$apiKey,
            'Authorization: Bearer '.$apiKey,
            'Content-Type: application/json',
            'Prefer: return=minimal'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// Priority: prefer approved tables over review/preapproval when both exist
$groups = [
    'superadmin' => ['superadmin'],
    'buyer'      => ['buyer','reviewbuyer'],
    'seller'     => ['seller','reviewseller'],
    'admin'      => ['admin','reviewadmin'],
    'bat'        => ['bat','preapprovalbat','reviewbat'],
];

$found = null;
$role = null;
$table_found = null;
foreach ($groups as $roleKey => $tables){
    foreach ($tables as $t){
        $row = fetch_user_by_username($t, $username, $SUPABASE_URL, $SUPABASE_KEY);
        if ($row){
            $found = $row;
            $role = $roleKey;
            $table_found = $t;
            break 2;
        }
    }
}

if (!$found){
    // failed attempt handling
    $entry['fails'] = ($entry['fails'] ?? 0) + 1;
    if ($entry['fails'] >= 3){
        $entry['lock_until'] = $now + 60; // 1 minute
        $_SESSION['login_fail'][$key] = $entry;
        // log suspicious
        insert_suspicious_log($SUPABASE_URL, $SUPABASE_KEY, $username, $ip);
        redirect_with_error_and_cooldown('Too many failed attempts. Try again later.', 60);
    } else {
        $_SESSION['login_fail'][$key] = $entry;
        redirect_with_error('Invalid username or password');
    }
}

$stored = isset($found['password']) ? (string)$found['password'] : '';
if ($stored === '' || !password_verify($password, $stored)){
    $entry['fails'] = ($entry['fails'] ?? 0) + 1;
    if ($entry['fails'] >= 3){
        $entry['lock_until'] = $now + 60;
        $_SESSION['login_fail'][$key] = $entry;
        insert_suspicious_log($SUPABASE_URL, $SUPABASE_KEY, $username, $ip);
        redirect_with_error_and_cooldown('Too many failed attempts. Try again later.', 60);
    } else {
        $_SESSION['login_fail'][$key] = $entry;
        redirect_with_error('Invalid username or password');
    }
}

// Set session
$_SESSION['username'] = $found['username'] ?? $username;
$_SESSION['role'] = $role;
// Build a display name if available
if (isset($found['user_fname']) || isset($found['user_lname'])){
    $fname = isset($found['user_fname']) ? $found['user_fname'] : '';
    $lname = isset($found['user_lname']) ? $found['user_lname'] : '';
    $name = trim($fname.' '.$lname);
    if ($name !== '') $_SESSION['name'] = $name;
}
if (!isset($_SESSION['name']) && isset($found['email'])){
    $_SESSION['name'] = $found['email'];
}

// Successful login: reset fail counter and log
unset($_SESSION['login_fail'][$key]);
$userId = isset($found['user_id']) ? (int)$found['user_id'] : null;
if ($userId !== null){
    insert_login_log($SUPABASE_URL, $SUPABASE_KEY, $userId, $ip, $role);
}

// Also store the source table name for use after login
$_SESSION['source_table'] = $table_found;

// Redirect per role/table
$dest = null;
if ($role === 'buyer') {
    $dest = '../dashboard/buyer/dashboard.php';
} elseif ($role === 'seller') {
    $dest = '../dashboard/seller/dashboard.php';
} elseif ($role === 'admin') {
    $dest = '../dashboard/admin/dashboard.php';
} elseif ($role === 'bat') {
    $dest = '../dashboard/bat/dashboard.php';
} elseif ($role === 'superadmin') {
    $dest = '../dashboard/superadmin/dashboard.php';
}

if (!$dest){
    redirect_with_error('Unable to route login');
}

header('Location: '.$dest);
exit;
