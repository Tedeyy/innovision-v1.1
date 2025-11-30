<?php

namespace App\Services;

use App\Models\ContactVerificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SmsVerificationService
{
    protected $apiKey;
    protected $baseUrl = 'https://www.iprogsms.com/api/v1/otp';

    public function __construct()
    {
        $this->apiKey = env('IPROGSMS_API_KEY');
    }

    public function sendOtp($phoneNumber, $userId, $userRole)
    {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("$this->baseUrl/send_otp", [
            'api_token' => $this->apiKey,
            'phone_number' => $phoneNumber,
            'message' => "InnoVision: Your verification code is :otp. Valid for 5 minutes only. Never share this code with anyone."
        ]);

        if ($response->successful()) {
            // Store the verification log
            ContactVerificationLog::create([
                'user_id' => $userId,
                'userrole' => $userRole,
                'contact' => $phoneNumber,
            ]);

            return [
                'success' => true,
                'message' => 'OTP sent successfully',
                'otp' => $otp // In production, you might want to store this in cache with expiration
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to send OTP',
            'error' => $response->json()
        ];
    }
}
