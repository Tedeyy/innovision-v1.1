<?php
session_start();

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

function respond($code, $msg){
    http_response_code($code);
    echo $msg;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, 'Method Not Allowed');
}

$required = ['firstname','middlename','lastname','bdate','contact','address','email','assigned_barangay','doctype','docnum','username','password'];
foreach ($required as $k){
    if (!isset($_POST[$k]) || $_POST[$k] === '') {
        respond(400, 'Missing field: '.$k);
    }
}
if (!isset($_FILES['supporting_doc']) || $_FILES['supporting_doc']['error'] !== UPLOAD_ERR_OK) {
    respond(400, 'Supporting document upload missing or failed');
}
if ($_FILES['supporting_doc']['size'] > 20 * 1024 * 1024) {
    respond(400, 'File too large');
}

$SUPABASE_URL = getenv('SUPABASE_URL') ?: loadEnvValue('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: loadEnvValue('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_KEY') ?: loadEnvValue('SUPABASE_KEY');
if (!$SUPABASE_URL || !$SUPABASE_KEY){
    respond(500, 'Supabase config missing');
}

// Supabase Auth signup to trigger email confirmation
$APP_URL = getenv('APP_URL') ?: loadEnvValue('APP_URL');
$redirectTo = ($APP_URL ? rtrim($APP_URL, '/') : '') . '/pages/authentication/loginpage.php?confirmed=1';
$authUrl = rtrim($SUPABASE_URL, '/') . '/auth/v1/signup?redirect_to=' . urlencode($redirectTo);
$authPayload = json_encode([
    'email' => $_POST['email'],
    'password' => $_POST['password'],
    'data' => [
        'username' => $_POST['username']
    ]
]);
$chAuth = curl_init();
curl_setopt($chAuth, CURLOPT_URL, $authUrl);
curl_setopt($chAuth, CURLOPT_POST, true);
curl_setopt($chAuth, CURLOPT_HTTPHEADER, [
    'apikey: ' . $SUPABASE_KEY,
    'Authorization: Bearer ' . $SUPABASE_KEY,
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($chAuth, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chAuth, CURLOPT_POSTFIELDS, $authPayload);
$authRes = curl_exec($chAuth);
$authHttp = curl_getinfo($chAuth, CURLINFO_HTTP_CODE);
$authErr = curl_error($chAuth);
curl_close($chAuth);
if ($authErr) {
    respond(500, 'Auth signup error: ' . $authErr);
}
if ($authHttp < 200 || $authHttp >= 300) {
    respond($authHttp, 'Auth signup HTTP ' . $authHttp . ': ' . $authRes);
}

$payload = [
    'user_fname' => $_POST['firstname'],
    'user_mname' => $_POST['middlename'],
    'user_lname' => $_POST['lastname'],
    'bdate' => $_POST['bdate'],
    'contact' => $_POST['contact'],
    'address' => $_POST['address'],
    'email' => $_POST['email'],
    'assigned_barangay' => $_POST['assigned_barangay'],
    'doctype' => $_POST['doctype'],
    'docnum' => $_POST['docnum'],
    'username' => $_POST['username'],
    'password' => password_hash($_POST['password'], PASSWORD_DEFAULT)
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, rtrim($SUPABASE_URL,'/').'/rest/v1/reviewbat');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: '.$SUPABASE_KEY,
    'Authorization: Bearer '.$SUPABASE_KEY,
    'Content-Type: application/json',
    'Prefer: return=representation'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([$payload]));
$res = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
if ($err) {
    respond(500, 'Supabase REST error: '.$err);
}
if ($http < 200 || $http >= 300){
    respond($http, 'Supabase REST HTTP '.$http.': '.$res);
}
$data = json_decode($res, true);
if (!is_array($data) || !isset($data[0])){
    respond(500, 'Unexpected Supabase REST response');
}
$row = $data[0];
$created = isset($row['created']) ? $row['created'] : null;
if (!$created) {
    respond(500, 'No created timestamp returned');
}
$createdTs = strtotime($created);
$createdStr = $createdTs ? date('YmdHis', $createdTs) : preg_replace('/[^0-9]/','',$created);

$fname = isset($_POST['firstname']) ? $_POST['firstname'] : '';
$mname = isset($_POST['middlename']) ? $_POST['middlename'] : '';
$lname = isset($_POST['lastname']) ? $_POST['lastname'] : '';
$fullname = trim($fname.' '.($mname?:'').' '.$lname);
$sanitized = strtolower(preg_replace('/[^a-z0-9]+/i','_', $fullname));
$sanitized = trim($sanitized, '_');
$email = isset($_POST['email']) ? $_POST['email'] : '';
$esan = strtolower(preg_replace('/[^a-z0-9]+/i','_', $email));
$esan = trim($esan, '_');
$orig = $_FILES['supporting_doc']['name'];
$ext = '';
$pos = strrpos($orig, '.');
if ($pos !== false) { $ext = substr($orig, $pos); }
$finalName = (($sanitized !== '') ? $sanitized : 'bat').'_' . $esan . $ext;

// Upload to bucket reviewusers under bat folder
$path = 'bat/'.$finalName;

$tmp = $_FILES['supporting_doc']['tmp_name'];
$mime = mime_content_type($tmp);
if (!$mime) { $mime = 'application/octet-stream'; }

$fp = fopen($tmp, 'rb');
if (!$fp) {
    respond(500, 'Failed to read uploaded file');
}
$ch2 = curl_init();
$contentLength = filesize($tmp);
curl_setopt($ch2, CURLOPT_URL, rtrim($SUPABASE_URL,'/').'/storage/v1/object/reviewusers/'.$path);
curl_setopt($ch2, CURLOPT_UPLOAD, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'apikey: '.$SUPABASE_KEY,
    'Authorization: Bearer '.$SUPABASE_KEY,
    'Content-Type: '.$mime,
    'Content-Length: '.$contentLength,
    'x-upsert: true'
]);
curl_setopt($ch2, CURLOPT_INFILE, $fp);
curl_setopt($ch2, CURLOPT_INFILESIZE, $contentLength);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
$res2 = curl_exec($ch2);
$http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$err2 = curl_error($ch2);
curl_close($ch2);
fclose($fp);
if ($err2) {
    respond(500, 'Storage upload error: '.$err2);
}
if ($http2 < 200 || $http2 >= 300){
    respond($http2, 'Storage HTTP '.$http2.': '.$res2);
}

// Redirect user to login page with submitted message
header('Location: ' . (($APP_URL ? rtrim($APP_URL, '/') : '') . '/pages/authentication/loginpage.php?submitted=1'));
exit;
