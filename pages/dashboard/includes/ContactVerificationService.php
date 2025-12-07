<?php

class ContactVerificationService {
    private $apiKey;
    private $baseUrl = 'https://www.iprogsms.com/api/v1/otp';
    
    public function __construct() {
        $this->apiKey = getenv('IPROGSMS_API_KEY') ?: '';
    }
    
    /**
     * Check if a contact number is already in use
     */
    public function isContactNumberTaken($contactNumber, $excludeUserId = null) {
        $query = ['contact_number' => 'eq.' . $contactNumber];
        
        if ($excludeUserId) {
            $query['user_id'] = 'neq.' . $excludeUserId;
        }
        
        [$result, $status] = sb_rest('GET', 'contact_verifications', [
            'select' => 'id',
            'contact_number' => 'eq.' . $contactNumber,
            'is_verified' => 'eq.true',
            'or' => '(user_id.neq.' . $excludeUserId . ',is_verified.eq.true)'
        ]);
        
        return $status === 200 && !empty($result);
    }
    
    /**
     * Send OTP to the provided contact number
     */
    public function sendOtp($userId, $userRole, $contactNumber) {
        // Check if number is already in use by another user
        if ($this->isContactNumberTaken($contactNumber, $userId)) {
            return [
                'success' => false,
                'message' => 'This contact number is already in use by another account.'
            ];
        }
        
        // Generate OTP (6 digits)
        $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        
        // Save OTP to database
        $data = [
            'user_id' => $userId,
            'user_role' => $userRole,
            'contact_number' => $contactNumber,
            'otp_code' => $otp,
            'otp_expires_at' => $expiresAt,
            'is_verified' => false
        ];
        
        // Check if verification record already exists
        [$existing, $status] = sb_rest('GET', 'contact_verifications', [
            'select' => 'id',
            'user_id' => 'eq.' . $userId,
            'user_role' => 'eq.' . $userRole,
            'limit' => 1
        ]);
        
        if ($status === 200 && !empty($existing)) {
            // Update existing record
            $id = $existing[0]['id'];
            [$result, $status] = sb_rest('PATCH', 'contact_verifications?id=eq.' . $id, $data);
        } else {
            // Create new record
            [$result, $status] = sb_rest('POST', 'contact_verifications', $data);
        }
        
        if ($status < 200 || $status >= 300) {
            return [
                'success' => false,
                'message' => 'Failed to save verification data.'
            ];
        }
        
        // Send OTP via IPROGSMS
        $message = "InnoVision: Your verification code is $otp. Valid for 5 minutes. Do not share this code with anyone.";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/send_otp',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'api_token' => $this->apiKey,
                'phone_number' => $contactNumber,
                'message' => $message
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'message' => 'Verification code sent successfully.'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to send verification code. Please try again.'
            ];
        }
    }
    
    /**
     * Verify OTP code
     */
    public function verifyOtp($userId, $userRole, $otp) {
        // Find the verification record
        [$verification, $status] = sb_rest('GET', 'contact_verifications', [
            'select' => '*',
            'user_id' => 'eq.' . $userId,
            'user_role' => 'eq.' . $userRole,
            'limit' => 1
        ]);
        
        if ($status !== 200 || empty($verification)) {
            return [
                'success' => false,
                'message' => 'No verification request found. Please request a new code.'
            ];
        }
        
        $verification = $verification[0];
        $now = new DateTime();
        $expiresAt = new DateTime($verification['otp_expires_at']);
        
        // Check if OTP is expired
        if ($now > $expiresAt) {
            return [
                'success' => false,
                'message' => 'Verification code has expired. Please request a new one.'
            ];
        }
        
        // Check if OTP matches
        if ($verification['otp_code'] !== $otp) {
            return [
                'success' => false,
                'message' => 'Invalid verification code. Please try again.'
            ];
        }
        
        // Mark as verified and set verification timestamp
        $now = (new DateTime())->format('Y-m-d H:i:s');
        [$result, $status] = sb_rest('PATCH', 'contact_verifications?id=eq.' . $verification['id'], [
            'is_verified' => true,
            'otp_code' => null,
            'otp_expires_at' => null,
            'verified_at' => $now
        ]);
        
        if ($status < 200 || $status >= 300) {
            return [
                'success' => false,
                'message' => 'Failed to verify code. Please try again.'
            ];
        }
        
        // Update user's contact number in their profile
        $this->updateUserContactNumber($userId, $userRole, $verification['contact_number']);
        
        return [
            'success' => true,
            'message' => 'Contact number verified successfully!',
            'contact_number' => $verification['contact_number'],
            'verified_at' => $now
        ];
    }
    
    /**
     * Update user's contact number in their profile
     */
    private function updateUserContactNumber($userId, $userRole, $contactNumber) {
        $table = $this->getUserTable($userRole);
        if (!$table) return false;
        
        [$result, $status] = sb_rest('PATCH', $table . '?id=eq.' . $userId, [
            'contact_number' => $contactNumber
        ]);
        
        return $status >= 200 && $status < 300;
    }
    
    /**
     * Get the verification status for a user
     */
    public function getVerificationStatus($userId, $userRole) {
        [$verification, $status] = sb_rest('GET', 'contact_verifications', [
            'select' => 'contact_number,is_verified',
            'user_id' => 'eq.' . $userId,
            'user_role' => 'eq.' . $userRole,
            'limit' => 1
        ]);
        
        if ($status !== 200 || empty($verification)) {
            return [
                'is_verified' => false,
                'contact_number' => ''
            ];
        }
        
        return [
            'is_verified' => $verification[0]['is_verified'] === true,
            'contact_number' => $verification[0]['contact_number']
        ];
    }
    
    /**
     * Get the appropriate user table based on role
     */
    private function getUserTable($userRole) {
        $tables = [
            'buyer' => 'buyers',
            'seller' => 'sellers',
            'bat' => 'bats',
            'admin' => 'admins',
            'superadmin' => 'superadmins'
        ];
        
        return $tables[strtolower($userRole)] ?? null;
    }
}
?>
