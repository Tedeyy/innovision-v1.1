<?php
// Lightweight Supabase helper for PHP servers using cURL
// Stores tokens in $_SESSION and exposes sign-in, refresh and REST request helpers.

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function sb_load_env($key){
    $dir = __DIR__;
    for ($i=0; $i<6; $i++){
        $candidate = $dir.DIRECTORY_SEPARATOR.'.env';
        if (is_file($candidate)){
            $lines = @file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines){
                foreach ($lines as $line){
                    if (strpos(ltrim($line), '#') === 0) continue;
                    $pos = strpos($line, '='); if ($pos === false) continue;
                    $k = trim(substr($line, 0, $pos));
                    if ($k === $key){ $v = trim(substr($line, $pos+1)); return trim($v, "\"' "); }
                }
            }
        }
        $parent = dirname($dir); if ($parent === $dir) break; $dir = $parent;
    }
    return null;
}

function sb_env($k){ return getenv($k) ?: sb_load_env($k); }

function sb_base_url(){ return rtrim(sb_env('SUPABASE_URL') ?: '', '/'); }
function sb_anon_key(){ return sb_env('SUPABASE_ANON_KEY') ?: (sb_env('SUPABASE_KEY') ?: ''); }

function sb_has_auth_config(){ return sb_base_url() && sb_anon_key(); }

function sb_auth_sign_in($email, $password){
    $url = sb_base_url().'/auth/v1/token?grant_type=password';
    $payload = ['email'=>$email, 'password'=>$password];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'apikey: '.sb_anon_key(),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err || $http >= 400) return [null, 'Sign-in failed'];
    $data = json_decode($res, true);
    if (!isset($data['access_token'])) return [null, 'Invalid auth response'];
    // Persist in session
    $_SESSION['supa_access_token'] = $data['access_token'];
    $_SESSION['supa_refresh_token'] = $data['refresh_token'] ?? null;
    $_SESSION['supa_expires_at'] = time() + (int)($data['expires_in'] ?? 3600) - 60; // refresh 60s early
    $_SESSION['supa_user'] = $data['user'] ?? null; // contains id, email, etc.
    return [$data, null];
}

function sb_auth_refresh(){
    $rt = $_SESSION['supa_refresh_token'] ?? null; if (!$rt) return false;
    $url = sb_base_url().'/auth/v1/token?grant_type=refresh_token';
    $payload = ['refresh_token'=>$rt];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'apikey: '.sb_anon_key(),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http >= 400 || !$res) return false;
    $data = json_decode($res, true);
    if (!isset($data['access_token'])) return false;
    $_SESSION['supa_access_token'] = $data['access_token'];
    $_SESSION['supa_refresh_token'] = $data['refresh_token'] ?? $rt;
    $_SESSION['supa_expires_at'] = time() + (int)($data['expires_in'] ?? 3600) - 60;
    $_SESSION['supa_user'] = $data['user'] ?? ($_SESSION['supa_user'] ?? null);
    return true;
}

function sb_auth_logout(){
    $at = $_SESSION['supa_access_token'] ?? null; if (!$at) return true;
    $url = sb_base_url().'/auth/v1/logout';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'apikey: '.sb_anon_key(),
            'Authorization: Bearer '.$at,
        ],
    ]);
    curl_exec($ch); curl_close($ch);
    // Clear tokens regardless of API result
    unset($_SESSION['supa_access_token'], $_SESSION['supa_refresh_token'], $_SESSION['supa_expires_at'], $_SESSION['supa_user']);
    return true;
}

function sb_auth_require(){
    if (!sb_has_auth_config()) return false;
    $exp = $_SESSION['supa_expires_at'] ?? 0;
    if ($exp && time() > $exp) { sb_auth_refresh(); }
    return !empty($_SESSION['supa_access_token']);
}

function sb_rest($method, $path, $query = [], $body = null, $headers = []){
    $base = sb_base_url(); if (!$base) return [null, 500, 'No base URL'];
    $qs = $query ? ('?'.http_build_query($query)) : '';
    $url = rtrim($base,'/').'/rest/v1/'.ltrim($path,'/').$qs;
    $at = $_SESSION['supa_access_token'] ?? null;
    // Auto refresh if near expiry
    $exp = $_SESSION['supa_expires_at'] ?? 0; if ($exp && time()>$exp) sb_auth_refresh();
    // Choose best Authorization: user token -> service role (if set) -> anon key
    $service = sb_env('SUPABASE_SERVICE_ROLE_KEY') ?: '';
    $authKey = $at ?: ($service ?: sb_anon_key());
    $hdrs = array_merge([
        'apikey: '.sb_anon_key(),
        'Authorization: Bearer '.$authKey,
        'Accept: application/json',
    ], $headers);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    if ($body !== null){
        $json = is_string($body) ? $body : json_encode($body);
        $hdrs[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err) return [null, 500, $err];
    $data = json_decode($res, true);
    return [$data, $http, null];
}
