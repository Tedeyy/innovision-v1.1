<?php
/**
 * Use Case Logger
 * Logs major user actions to login_purpose table for audit trail
 */

require_once __DIR__ . '/supabase_client.php';

/**
 * Log a major use case action
 * @param string $purpose - Description of the use case with details
 * @param int|null $user_id - User ID (optional, will use session if not provided)
 * @param string|null $ip_address - IP address (optional, will auto-detect if not provided)
 * @return bool - Success status
 */
function log_use_case($purpose, $user_id = null, $ip_address = null) {
    if (empty($purpose)) {
        return false;
    }
    
    // Get user_id from session if not provided
    if ($user_id === null) {
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
    
    // Get IP address if not provided
    if ($ip_address === null) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    // Validate user_id
    if (!$user_id) {
        return false;
    }
    
    // Prepare log data
    $log_data = [
        'user_id' => $user_id,
        'ip_address' => $ip_address,
        'purpose' => $purpose
    ];
    
    // Insert into login_purpose table
    try {
        [$result, $status, $error] = sb_rest('POST', 'login_purpose', [], [$log_data]);
        
        // Check if insertion was successful
        if ($status >= 200 && $status < 300) {
            return true;
        } else {
            // Log error for debugging (optional)
            error_log("Failed to log use case: " . ($error ?? 'Unknown error'));
            return false;
        }
    } catch (Exception $e) {
        error_log("Exception logging use case: " . $e->getMessage());
        return false;
    }
}

/**
 * Get client IP address more accurately
 * @return string
 */
function get_client_ip() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Format use case description with context
 * @param string $action - Main action
 * @param array $context - Additional context details
 * @return string - Formatted description
 */
function format_use_case_description($action, $context = []) {
    $description = $action;
    
    if (!empty($context)) {
        $details = [];
        foreach ($context as $key => $value) {
            if ($value !== null && $value !== '') {
                $details[] = ucfirst($key) . ': ' . $value;
            }
        }
        if (!empty($details)) {
            $description .= ' | ' . implode(', ', $details);
        }
    }
    
    return $description;
}

?>
