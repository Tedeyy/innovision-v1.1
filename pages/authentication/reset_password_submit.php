<?php
session_start();
require_once __DIR__ . '/lib/supabase_client.php';
header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);
$token = $body['token'] ?? '';
$newPassword = $body['new_password'] ?? '';
if (!$token || !$newPassword) { echo json_encode(['ok'=>false,'error'=>'Missing token or new password']); exit; }

// Update password using recovery token
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
    'Authorization: Bearer '.$token,
    'Content-Type: application/json'
  ],
  CURLOPT_POSTFIELDS => $payload,
]);
$res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
if ($err || $http>=400) { echo json_encode(['ok'=>false,'error'=>'Failed to update password']); exit; }

// Try to decode token to get user id (sub)
function jwt_payload($jwt){
  $parts = explode('.', $jwt);
  if (count($parts) < 2) return null;
  $payload = $parts[1];
  $payload = strtr($payload, '-_', '+/');
  $payload .= str_repeat('=', 3 - (3 + strlen($payload)) % 4);
  $json = base64_decode($payload);
  if (!$json) return null;
  $data = json_decode($json, true);
  return $data ?: null;
}
$payload = jwt_payload($token);
$user_id = $payload['sub'] ?? null;

// Best-effort account table sync: set last_password_reset timestamp if column exists
if ($user_id) {
  $now = gmdate('c');
  $tables = ['buyer','reviewbuyer','seller','reviewseller','bat','reviewbat','preapprovalbat','admin','reviewadmin','superadmins'];
  foreach ($tables as $t) {
    // Ignore errors; some tables/columns may not exist
    @sb_rest('PATCH', $t, ['user_id'=>'eq.'.$user_id], ['last_password_reset'=>$now], ['Prefer: return=minimal']);
  }
}

echo json_encode(['ok'=>true]);
