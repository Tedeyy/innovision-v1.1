<?php
session_start();
require_once __DIR__ . '/lib/supabase_client.php';
header('Content-Type: application/json');

function client_ip() {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
    $ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) ?: 'unknown';
    return substr($ip, 0, 100);
}

$ip = client_ip();
$blacklisted = false;
if ($ip !== 'unknown'){
    [$rows,$st,$err] = sb_rest('GET','blacklist',[ 'select'=>'ip_address','ip_address'=>'eq.'.$ip, 'limit'=>'1' ]);
    if ($st>=200 && $st<300 && is_array($rows) && count($rows)>0){
        $blacklisted = true;
    }
}

echo json_encode(['blacklisted'=>$blacklisted,'ip'=>$ip]);
