<?php
require_once __DIR__ . '/../authentication/lib/supabase_client.php';

function _env_val($key){
    $v = getenv($key);
    if ($v !== false) return $v;
    static $loaded=false,$map=[];
    if(!$loaded){
        $dir=__DIR__;
        for($i=0;$i<6;$i++){
            $f=$dir.DIRECTORY_SEPARATOR.'.env';
            if (is_file($f)){
                $lines=@file($f, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
                if($lines){foreach($lines as $line){
                    $line=trim($line); if($line==''||$line[0]=='#') continue;
                    $p=explode('=',$line,2); if(count($p)!=2) continue; $map[trim($p[0])]=trim(trim($p[1]),"'\"");
                }}
                break;
            }
            $par=dirname($dir); if($par===$dir) break; $dir=$par;
        }
        $loaded=true;
    }
    return $map[$key] ?? null;
}

function notify_send($recipient_id, $recipient_role, $title, $message, $related_entity_id=null, $type='system'){
    $payload = [[
        'recipient_id'=>(int)$recipient_id,
        'recipient_role'=>(string)$recipient_role,
        'title'=>(string)$title,
        'message'=>(string)$message,
        'related_entity_id'=> $related_entity_id!==null ? (int)$related_entity_id : null,
        'type'=>(string)$type
    ]];
    sb_rest('POST','notifications',[], $payload);
    // try SMS if verified
    $phone = null;
    [$rows,$st,$err] = sb_rest('GET','contact_verifications',[ 'select'=>'contact_number,is_verified','user_id'=>'eq.'.(int)$recipient_id,'user_role'=>'eq.'.$recipient_role,'is_verified'=>'eq.true','limit'=>'1' ]);
    if ($st>=200 && $st<300 && is_array($rows) && count($rows)>0){ $r=$rows[0]; if(!empty($r['contact_number'])) $phone=$r['contact_number']; }
    if ($phone){
        $apiKey = _env_val('IPROGSMS_API_KEY');
        $apiUrl = _env_val('IPROGSMS_API_URL') ?: 'https://api.iprogsms.example.com/v1/messages';
        if ($apiKey){
            $ch = curl_init($apiUrl);
            $body = json_encode(['to'=>$phone,'text'=>$title.': '.$message,'senderId'=>'INNOVISION']);
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$body,CURLOPT_TIMEOUT=>5]);
            @curl_exec($ch); @curl_close($ch);
        }
    }
}

function notify_role($role, $title, $message, $related_entity_id=null, $type='system'){
    $table = $role; // tables named by role (seller,buyer,admin,bat,superadmin)
    [$rows,$st,$err] = sb_rest('GET',$table,['select'=>'user_id']);
    if ($st>=200 && $st<300 && is_array($rows)){
        foreach($rows as $r){ if(isset($r['user_id'])) notify_send($r['user_id'],$role,$title,$message,$related_entity_id,$type); }
    }
}
