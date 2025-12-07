<?php
session_start();
require_once __DIR__ . '/lib/supabase_client.php';
header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$newPassword = isset($body['new_password']) ? (string)$body['new_password'] : '';
if (!$newPassword || strlen($newPassword) < 6) { echo json_encode(['ok'=>false,'error'=>'Password must be at least 6 characters']); exit; }

$accessToken = $_SESSION['supa_access_token'] ?? '';
if (!$accessToken) { echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit; }

$url = rtrim(sb_base_url(),'/').'/auth/v1/user';
$ch = curl_init();
$payload = json_encode(['password'=>$newPassword]);
curl_setopt_array($ch,[
  CURLOPT_URL => $url,
  CURLOPT_CUSTOMREQUEST => 'PUT',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    'apikey: '.sb_anon_key(),
    'Authorization: Bearer '.$accessToken,
    'Content-Type: application/json'
  ],
  CURLOPT_POSTFIELDS => $payload,
]);
$res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
if ($err || $http>=400) { echo json_encode(['ok'=>false,'error'=>'Failed to update password']); exit; }

// Optional: attempt to mark last_password_reset in user tables
$user = $_SESSION['supa_user'] ?? null;
$user_id = is_array($user) ? ($user['id'] ?? null) : null;
if ($user_id) {
  $now = gmdate('c');
  $tables = ['buyer','reviewbuyer','seller','reviewseller','bat','reviewbat','preapprovalbat','admin','reviewadmin','superadmins'];
  foreach ($tables as $t) {
    @sb_rest('PATCH', $t, ['user_id'=>'eq.'.$user_id], ['last_password_reset'=>$now], ['Prefer: return=minimal']);
  }
}

echo json_encode(['ok'=>true]);
