<?php
// Enable output buffering for faster response
define('LOGIN_ATTEMPT_LIMIT', 5);
define('LOGIN_LOCKOUT_TIME', 300); // 5 minutes

error_reporting(0); // Disable error display in production
session_start();

// Supabase auth helper (stores JWTs in $_SESSION for server-to-Supabase requests)
require_once __DIR__ . '/lib/supabase_client.php';
require_once __DIR__ . '/../common/notify.php';

// Cache environment variables
$envCache = [];

function loadEnvValue($key) {
    global $envCache;
    if (isset($envCache[$key])) {
        return $envCache[$key];
    }
    
    $value = getenv($key);
    if ($value !== false) {
        $envCache[$key] = $value;
        return $value;
    }
    
    static $envLoaded = false;
    static $envVars = [];
    
    if (!$envLoaded) {
        $dir = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . '.env';
            if (is_file($candidate)) {
                $lines = @file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line) || strpos($line, '#') === 0) continue;
                        
                        $parts = explode('=', $line, 2);
                        if (count($parts) !== 2) continue;
                        
                        $k = trim($parts[0]);
                        $v = trim($parts[1]);
                        $v = trim($v, "\"' ");
                        $envVars[$k] = $v;
                    }
                    break;
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        $envLoaded = true;
    }
    
    $value = $envVars[$key] ?? null;
    $envCache[$key] = $value;
    return $value;
}

function redirect_with_error($msg) {
    http_response_code(302);
    header('Location: loginpage.php?error=' . urlencode($msg));
    exit;
}

function redirect_with_error_and_cooldown($msg, $seconds) {
    $seconds = max(0, (int)$seconds);
    http_response_code(302);
    header('Location: loginpage.php?error=' . urlencode($msg) . '&cooldown=' . $seconds);
    exit;
}

function client_ip() {
    static $ip = null;
    if ($ip !== null) return $ip;
    
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] 
        ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
        ?? $_SERVER['REMOTE_ADDR'] 
        ?? 'unknown';
        
    $ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) ?: 'unknown';
    return substr($ip, 0, 100);
}

// Check request method and validate input
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Basic input validation
$username = trim($_POST['username'] ?? '');
$password = (string)($_POST['password'] ?? '');

if (empty($username) || empty($password)) {
    redirect_with_error('Please enter both username/email and password');
}

// Load configuration
$SUPABASE_URL = loadEnvValue('SUPABASE_URL');
$SUPABASE_KEY = loadEnvValue('SUPABASE_SERVICE_ROLE_KEY') ?: loadEnvValue('SUPABASE_KEY');

if (!$SUPABASE_URL || !$SUPABASE_KEY) {
    error_log('Missing Supabase configuration');
    redirect_with_error('Server configuration error');
}

// Initialize rate limiting
$ip = client_ip();
$_SESSION['login_fail'] = $_SESSION['login_fail'] ?? [];
$key = hash('sha256', $username . '|' . $ip); // Use hashed key for security
$now = time();
$entry = $_SESSION['login_fail'][$key] ?? ['fails' => 0, 'lock_until' => 0];

// Check if account is locked
if ($entry['lock_until'] > $now) {
    $seconds = $entry['lock_until'] - $now;
    redirect_with_error_and_cooldown('Too many failed attempts. Please try again in ' . $seconds . ' seconds.', $seconds);
}

/**
 * Fetches a user by username or email using parallel cURL requests
 */
function fetch_user_by_credential($tables, $input, $baseUrl, $apiKey) {
    $mh = curl_multi_init();
    $handles = [];
    $results = [];
    $baseUrl = rtrim($baseUrl, '/');
    
    // Prepare all possible queries
    foreach ($tables as $table) {
        // Query for username
        $url = "$baseUrl/rest/v1/" . rawurlencode($table) . 
               "?select=*&or=(username.eq." . rawurlencode($input) . 
               ",email.eq." . rawurlencode($input) . ")&limit=1";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $apiKey,
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
                'Prefer: return=representation',
            ],
            CURLOPT_HTTPGET => true,
        ]);
        
        $handles[] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    
    // Execute all queries in parallel
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh);
        }
    } while ($running && $status == CURLM_OK);
    
    // Process responses
    foreach ($handles as $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 200 && $httpCode < 300) {
            $response = curl_multi_getcontent($ch);
            $data = json_decode($response, true);
            if (is_array($data) && !empty($data)) {
                $result = $data[0];
                $result['_table'] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $result['_table'] = preg_replace('/.*\/rest\/v1\/([^?]+).*/', '$1', $result['_table']);
                $results[] = $result;
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($mh);
    
    // Return the first valid result (prioritize by role order)
    return $results[0] ?? null;
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

function insert_suspicious_log($baseUrl, $apiKey, $username, $ip, $reason = '') {
    $ch = curl_init("$baseUrl/rest/v1/suspicious_login_attempts");
    $payload = [
        'username' => $username,
        'ip_address' => $ip,
        'incident_report' => $reason ?: 'Suspicious login attempt',
        'log_time' => date('Y-m-d H:i:s'),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'http_referer' => $_SERVER['HTTP_REFERER'] ?? ''
    ];
    
    $headers = [
        'Authorization: Bearer '.$apiKey,
        'Content-Type: application/json',
        'Prefer: return=minimal'
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 3, // 3 second timeout
        CURLOPT_FAILONERROR => false // Don't fail on HTTP error status codes
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Log any errors to PHP error log
    if ($error || ($httpCode >= 400 && $httpCode <= 599)) {
        error_log(sprintf(
            'Failed to log suspicious login attempt. HTTP %d: %s. Error: %s',
            $httpCode,
            $response ?: 'No response',
            $error ?: 'None'
        ));
    }
}

// Check login attempts and apply rate limiting
$ip = client_ip();
$loginAttempts = $_SESSION['login_attempts'][$username] ?? 0;
$lastAttemptTime = $_SESSION['last_attempt_time'][$username] ?? 0;
$currentTime = time();

// Check if user is in cooldown
if ($loginAttempts >= 3) {
    $timeSinceLastAttempt = $currentTime - $lastAttemptTime;
    $cooldownPeriod = 180; // 3 minutes in seconds
    
    if ($timeSinceLastAttempt < $cooldownPeriod) {
        $remainingTime = $cooldownPeriod - $timeSinceLastAttempt;
        // Log suspicious attempt and notify superadmin
        insert_suspicious_log($SUPABASE_URL, $SUPABASE_KEY, $username, $ip, 'Too many failed login attempts');
        notify_role('superadmin','Suspicious Login Attempt', 'User '.$username.' from IP '.$ip.' exceeded login attempts','', 'alert');
        redirect_with_error_and_cooldown('Too many failed attempts. Please try again later.', $remainingTime);
    } else {
        // Reset attempts if cooldown period has passed
        $loginAttempts = 0;
        $_SESSION['login_attempts'][$username] = 0;
    }
}

// Check blacklist IP restriction
function is_ip_blacklisted($baseUrl, $apiKey, $ip) {
    $url = "$baseUrl/rest/v1/blacklist?select=*&ip_address=eq." . urlencode($ip) . "&limit=1";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $apiKey,
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode($response, true);
        return is_array($data) && !empty($data);
    }
    return false;
}

// Check penalty restriction for user
function check_user_penalty($baseUrl, $apiKey, $userId, $userRole) {
    $url = "$baseUrl/rest/v1/penalty?select=*&user_id=eq." . urlencode($userId) . "&order=penaltytime.desc&limit=1";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $apiKey,
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode($response, true);
        if (is_array($data) && !empty($data)) {
            $penalty = $data[0];
            $penaltyTime = strtotime($penalty['penaltytime']);
            $currentTime = time();
            
            if ($penaltyTime > $currentTime) {
                $remainingTime = $penaltyTime - $currentTime;
                $days = floor($remainingTime / 86400);
                $hours = floor(($remainingTime % 86400) / 3600);
                $minutes = floor(($remainingTime % 3600) / 60);
                
                $timeString = '';
                if ($days > 0) $timeString .= $days . ' days ';
                if ($hours > 0) $timeString .= $hours . ' hours ';
                if ($minutes > 0 || $timeString === '') $timeString .= $minutes . ' minutes';
                
                return [
                    'blocked' => true,
                    'message' => "Your account is penalized. You still have $timeString remaining.",
                    'penalty' => $penalty
                ];
            }
        }
    }
    return ['blocked' => false];
}

// Check if IP is blacklisted
if (is_ip_blacklisted($SUPABASE_URL, $SUPABASE_KEY, $ip)) {
    insert_suspicious_log($SUPABASE_URL, $SUPABASE_KEY, $username, $ip, 'Login attempt from blacklisted IP');
    redirect_with_error('Access from this IP address is blocked. Please contact support if you believe this is an error.');
}

// Define user groups with priority order
$groups = [
    'superadmin' => ['superadmin'],
    'admin'      => ['admin', 'reviewadmin'],
    'bat'        => ['bat', 'preapprovalbat', 'reviewbat'],
    'seller'     => ['seller', 'reviewseller'],
    'buyer'      => ['buyer', 'reviewbuyer'],
];

// Fetch user data across all tables in parallel
$allTables = [];
foreach ($groups as $tables) {
    $allTables = array_merge($allTables, $tables);
}

$found = fetch_user_by_credential($allTables, $username, $SUPABASE_URL, $SUPABASE_KEY);
$role = null;
$table_found = $found['_table'] ?? null;
unset($found['_table']);

// Determine role based on the table found
if ($table_found) {
    foreach ($groups as $roleKey => $tables) {
        if (in_array($table_found, $tables)) {
            $role = $roleKey;
            break;
        }
    }
}

// Handle failed login attempts
if (!$found || !isset($found['password'])) {
    $entry['fails'] = ($entry['fails'] ?? 0) + 1;
    $entry['last_attempt'] = $now;
    
    if ($entry['fails'] >= LOGIN_ATTEMPT_LIMIT) {
        $entry['lock_until'] = $now + LOGIN_LOCKOUT_TIME;
        insert_suspicious_log($SUPABASE_URL, $SUPABASE_KEY, $username, $ip);
        notify_role('superadmin','Suspicious Login Attempt', 'User '+$username+' from IP '+$ip+' exceeded login attempts','', 'alert');
        $_SESSION['login_fail'][$key] = $entry;
        
        $remaining = LOGIN_LOCKOUT_TIME;
        $minutes = ceil($remaining / 60);
        redirect_with_error_and_cooldown(
            'Too many failed attempts. Please try again in ' . $minutes . ' minutes.',
            $remaining
        );
    } else {
        $_SESSION['login_fail'][$key] = $entry;
        redirect_with_error('Invalid username or password');
    }
}

// Verify password
$storedHash = (string)($found['password'] ?? '');
if (empty($storedHash) || !password_verify($password, $storedHash)) {
    $entry['fails'] = ($entry['fails'] ?? 0) + 1;
    $entry['last_attempt'] = $now;
    
    if ($entry['fails'] >= LOGIN_ATTEMPT_LIMIT) {
        $entry['lock_until'] = $now + LOGIN_LOCKOUT_TIME;
        insert_suspicious_log($SUPABASE_URL, $SUPABASE_KEY, $username, $ip);
        
        $remaining = LOGIN_LOCKOUT_TIME;
        $minutes = ceil($remaining / 60);
        $_SESSION['login_fail'][$key] = $entry;
        
        redirect_with_error_and_cooldown(
            'Too many failed attempts. Please try again in ' . $minutes . ' minutes.',
            $remaining
        );
    } else {
        $_SESSION['login_fail'][$key] = $entry;
        redirect_with_error('Invalid username or password');
    }
}

// Check penalty after successful authentication
$userId = isset($found['user_id']) ? (int)$found['user_id'] : null;
if ($userId !== null) {
    $penaltyCheck = check_user_penalty($SUPABASE_URL, $SUPABASE_KEY, $userId, $role);
    if ($penaltyCheck['blocked']) {
        insert_suspicious_log($SUPABASE_URL, $SUPABASE_KEY, $username, $ip, 'Login attempt by penalized user');
        redirect_with_error($penaltyCheck['message']);
    }
}

// Successful login - prepare session
// Clear any existing session data to prevent session fixation
$_SESSION = [];

// Regenerate session ID for security
if (function_exists('session_regenerate_id')) {
    session_regenerate_id(true);
}

// Set basic session data
if ($user) {
    // Reset login attempts on successful login
    unset($_SESSION['login_attempts'][$username]);
    unset($_SESSION['last_attempt_time'][$username]);
    
    $userRole = $user['role'] ?? 'user';
    $userId = $user['id'] ?? null;
    $_SESSION = [
        'user_id' => $userId,
        'username' => $found['username'] ?? $username,
        'role' => $role,
        'source_table' => $table_found,
        'last_activity' => $now,
        'ip_address' => $ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
} else {
    $_SESSION = [
        'user_id' => $userId,
        'username' => $found['username'] ?? $username,
        'role' => $role,
        'source_table' => $table_found,
        'last_activity' => $now,
        'ip_address' => $ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
}

// Add user details
$fname = $found['user_fname'] ?? '';
$lname = $found['user_lname'] ?? '';
$name = trim("$fname $lname");

if (!empty($name)) {
    $_SESSION['name'] = $name;
    $_SESSION['firstname'] = !empty($fname) ? $fname : $name;
} elseif (isset($found['email'])) {
    $_SESSION['name'] = $found['email'];
    $_SESSION['firstname'] = !empty($fname) ? $fname : 'User';
}

// Clear failed login attempts
unset($_SESSION['login_fail'][$key]);

// Log successful login in background
if ($userId !== null) {
    // Use fastcgi_finish_request() if available to send response to client immediately
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    // Log login in background
    register_shutdown_function(function() use ($SUPABASE_URL, $SUPABASE_KEY, $userId, $ip, $role) {
        insert_login_log($SUPABASE_URL, $SUPABASE_KEY, $userId, $ip, $role);
    });
}

// Initialize Supabase Auth in background if available
if (function_exists('sb_has_auth_config') && sb_has_auth_config() && isset($found['email'])) {
    register_shutdown_function(function() use ($found, $password, $username) {
        $emailForAuth = $found['email'] ?? $username;
        @sb_auth_sign_in($emailForAuth, $password);
    });
}

// Define role-based redirects
$roleRedirects = [
    'superadmin' => '../dashboard/superadmin/dashboard.php',
    'admin'      => '../dashboard/admin/dashboard.php',
    'bat'        => '../dashboard/bat/dashboard.php',
    'seller'     => '../dashboard/seller/dashboard.php',
    'buyer'      => '../dashboard/buyer/dashboard.php',
];

// Get destination URL
$dest = $roleRedirects[$role] ?? null;

if (empty($dest)) {
    error_log("No destination found for role: " . ($role ?? 'NULL'));
    redirect_with_error('Unable to determine your dashboard. Please contact support.');
}

// Clear output buffer and redirect
while (ob_get_level()) ob_end_clean();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Location: $dest", true, 303);
exit;
