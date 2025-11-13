<?php
header('Content-Type: application/json');

function loadEnvValue($key){
    $dir = __DIR__;
    for ($i=0; $i<6; $i++) {
        $candidate = $dir.DIRECTORY_SEPARATOR.'.env';
        if (is_file($candidate)) {
            $lines = @file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                foreach ($lines as $line) {
                    if (strpos(ltrim($line), '#') === 0) continue;
                    $pos = strpos($line, '=');
                    if ($pos === false) continue;
                    $k = trim(substr($line, 0, $pos));
                    if ($k === $key) {
                        $v = trim(substr($line, $pos+1));
                        $v = trim($v, "\"' ");
                        return $v;
                    }
                }
            }
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return null;
}

$username = isset($_GET['username']) ? trim($_GET['username']) : '';
if ($username === ''){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid username']);
    exit;
}

$SUPABASE_URL = getenv('SUPABASE_URL') ?: loadEnvValue('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: loadEnvValue('SUPABASE_SERVICE_ROLE_KEY');
if (!$SUPABASE_URL || !$SUPABASE_KEY){
    http_response_code(500);
    echo json_encode(['error' => 'Supabase config missing']);
    exit;
}

$tables = [
    'reviewbuyer','buyer',
    'reviewseller','seller',
    'reviewbat','preapprovalbat',
    'reviewadmin','admin',
    'superadmin'
];

$found = null;
foreach ($tables as $t) {
    $url = rtrim($SUPABASE_URL, '/').'/rest/v1/'.rawurlencode($t).'?select=username&username=eq.'.rawurlencode($username).'&limit=1';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'apikey: '.$SUPABASE_KEY,
            'Authorization: Bearer '.$SUPABASE_KEY,
            'Accept: application/json'
        ],
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        http_response_code(500);
        echo json_encode(['error' => 'Supabase error', 'detail' => $err]);
        exit;
    }
    if ($http >= 400) {
        http_response_code($http);
        echo json_encode(['error' => 'Supabase HTTP '.$http, 'detail' => $res]);
        exit;
    }
    $data = json_decode($res, true);
    if (is_array($data) && count($data) > 0) {
        $found = $t;
        break;
    }
}

echo json_encode(['exists' => $found !== null, 'table' => $found]);
