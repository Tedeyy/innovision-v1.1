<?php
session_start();
require_once __DIR__ . '/lib/supabase_client.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$email = isset($input['email']) && $input['email'] !== '' ? $input['email'] : ($_SESSION['email'] ?? null);
if (!$email) { echo json_encode(['ok'=>false,'error'=>'Missing email']); exit; }
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseDir = rtrim(dirname($scheme.'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']), '/');
$redirect = (isset($_GET['redirect']) && $_GET['redirect']!=='') ? $_GET['redirect'] : ($baseDir.'/reset_password.php');

$url = rtrim(sb_base_url(),'/').'/auth/v1/recover';
$ch = curl_init();
$payload = json_encode(['email'=>$email,'redirect_to'=>$redirect]);
curl_setopt_array($ch,[
  CURLOPT_URL => $url,
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    'apikey: '.sb_anon_key(),
    'Content-Type: application/json'
  ],
  CURLOPT_POSTFIELDS => $payload,
]);
$res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
if ($err || $http>=400) {
  echo json_encode(['ok'=>false,'error'=>'Failed to send reset email']);
  exit;
}
echo json_encode(['ok'=>true]);
