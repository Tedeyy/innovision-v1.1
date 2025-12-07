<?php

namespace App\Http\Controllers;

use App\Services\SmsVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContactVerificationController extends Controller
{
    protected $smsService;

    public function __construct(SmsVerificationService $smsService)
    {
        $this->middleware('auth');
        $this->smsService = $smsService;
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'contact_number' => 'required|string|max:20',
        ]);

        $user = Auth::user();
        $userRole = $this->getUserRole($user);

        $response = $this->smsService->sendOtp(
            $request->contact_number,
            $user->id,
            $userRole
        );

        return response()->json($response);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|string|size:6',
            'contact_number' => 'required|string',
        ]);

        // In a real implementation, you would verify the OTP here
        // For now, we'll just return success
        // You should implement proper OTP verification logic
        
        return response()->json([
            'success' => true,
            'message' => 'Contact number verified successfully',
        ]);
    }

    protected function getUserRole($user)
    {
        // Implement logic to determine user role
        // This is a placeholder - adjust according to your user roles implementation
        if ($user->hasRole('admin')) return 'admin';
        if ($user->hasRole('superadmin')) return 'superadmin';
        if ($user->hasRole('bat')) return 'bat';
        if ($user->hasRole('buyer')) return 'buyer';
        if ($user->hasRole('seller')) return 'seller';
        
        return 'user';
    }
}
