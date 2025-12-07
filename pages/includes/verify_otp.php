<?php
session_start();
require_once __DIR__ . '/../authentication/lib/supabase_client.php';
header('Content-Type: application/json');

$contact = isset($_POST['contact']) ? trim((string)$_POST['contact']) : '';
$otp = isset($_POST['otp']) ? trim((string)$_POST['otp']) : '';
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
if (!$otp || !preg_match('/^[0-9]{6}$/', $otp)) {
    echo json_encode(['success' => false, 'message' => 'Invalid verification code']);
    exit;
}

$stored = isset($_SESSION['contact_otp']) ? $_SESSION['contact_otp'] : null;
if (!$stored) {
    echo json_encode(['success' => false, 'message' => 'No verification request found']);
    exit;
}

if ($stored['user_id'] !== $user_id || $stored['user_role'] !== $user_role || $stored['contact'] !== $contact) {
    echo json_encode(['success' => false, 'message' => 'Verification data mismatch']);
    exit;
}

if (time() > (int)$stored['expires_at']) {
    unset($_SESSION['contact_otp']);
    echo json_encode(['success' => false, 'message' => 'Verification code has expired']);
    exit;
}

if ($stored['code'] !== $otp) {
    echo json_encode(['success' => false, 'message' => 'Incorrect verification code']);
    exit;
}

unset($_SESSION['contact_otp']);

$payload = [[
    'user_id' => $user_id,
    'user_role' => $user_role,
    'contact_number' => $contact,
    'is_verified' => true,
    'verified_at' => gmdate('c'),
]];

sb_rest('DELETE', 'contact_verifications', [
    'user_id' => 'eq.' . $user_id,
    'user_role' => 'eq.' . $user_role,
]);

sb_rest('POST', 'contact_verifications', [], $payload, ['Prefer: return=minimal']);

echo json_encode(['success' => true, 'contact_number' => $contact]);
