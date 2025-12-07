<?php
session_start();
require_once __DIR__ . '/../authentication/lib/supabase_client.php';
require_once __DIR__ . '/../common/notify.php';
header('Content-Type: application/json');

$contact = isset($_POST['contact']) ? trim((string)$_POST['contact']) : '';
$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$user_role = isset($_POST['user_role']) ? trim((string)$_POST['user_role']) : '';

if (!$contact || !preg_match('/^[0-9]{11}$/', $contact)) {
    echo json_encode(['success' => false, 'message' => 'Invalid contact number']);
    exit;
}
if ($user_id <= 0 || $user_role === '') {
    echo json_encode(['success' => false, 'message' => 'Missing user information']);
    exit;
}

$code = random_int(100000, 999999);
$_SESSION['contact_otp'] = [
    'user_id' => $user_id,
    'user_role' => $user_role,
    'contact' => $contact,
    'code' => (string)$code,
    'expires_at' => time() + 300,
];

$apiKey = _env_val('IPROGSMS_API_KEY');
$apiUrl = _env_val('IPROGSMS_API_URL') ?: 'https://api.iprogsms.example.com/v1/messages';

if (!$apiKey) {
    echo json_encode(['success' => false, 'message' => 'SMS gateway not configured']);
    exit;
}

$ch = curl_init($apiUrl);
$body = json_encode([
    'to' => $contact,
    'text' => 'Your Innovision verification code is ' . $code,
    'senderId' => 'INNOVISION',
]);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_TIMEOUT => 10,
]);
$res = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err || $http >= 400) {
    echo json_encode(['success' => false, 'message' => 'Failed to send verification code']);
    exit;
}

echo json_encode(['success' => true]);
