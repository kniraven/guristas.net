<?php
declare(strict_types=1);
function tickets_storage_config(): array {
    static $config;
    if ($config === null) {
        $path=dirname(__DIR__,2).'/config/ticket-storage.local.php';
        $config=is_file($path) ? require $path : [];
        if (!is_array($config)) throw new RuntimeException('Invalid attachment storage configuration.');
    }
    return $config;
}
function tickets_storage_remote(): bool {
    $remote=!empty(tickets_storage_config()['remote_url']);
    if (!$remote && (eve_config()['token_environment']??'production')==='local') {
        throw new RuntimeException('Configure EC2 attachment storage before using attachments locally.');
    }
    return $remote;
}
function tickets_storage_signature(array $fields,string $secret): string {
    ksort($fields,SORT_STRING);
    return hash_hmac('sha256',json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$secret);
}
function tickets_storage_secret(): string {
    $secret=tickets_storage_config()['bridge_secret']??'';
    if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D',$secret)) throw new RuntimeException('Configure a 64-character attachment bridge secret.');
    return $secret;
}
function tickets_storage_request(string $operation,int $actor,string $key='',int $id=0,?array $file=null): string {
    $url=tickets_storage_config()['remote_url']??'';
    if (!is_string($url) || !preg_match('~^https://[^/?#]+/admin/tickets/storage\.php$~D',$url)) throw new RuntimeException('Invalid EC2 attachment endpoint.');
    if (!function_exists('curl_init')) throw new RuntimeException('Remote attachments require PHP cURL.');
    $fields=['operation'=>$operation,'actor'=>(string)$actor,'key'=>$key,'id'=>(string)$id,'time'=>(string)time(),'nonce'=>bin2hex(random_bytes(16)),'name'=>$file['name']??'','digest'=>$file ? hash_file('sha256',$file['tmp']) : ''];
    $post=$fields;
    $post['signature']=tickets_storage_signature($fields,tickets_storage_secret());
    if ($file) $post['attachments[0]']=new CURLFile($file['tmp'],$file['mime'],$file['name']);
    $body='';$curl=curl_init($url);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body): int {
        if (strlen($body)+strlen($chunk)>10*1024*1024+4096) return 0;
        $body.=$chunk;return strlen($chunk);
    }]);
    try {
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        if ($ok===false || $status!==200) throw new RuntimeException('EC2 attachment transfer failed. Check storage configuration and the server error log.');
        return $body;
    } finally {curl_close($curl);}
}
