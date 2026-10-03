<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$directory=$argv[1]??($root.'/storage/stream');
$names=['message-types.json','messages.json','settings.json'];
try {
    $defaults=[];
    foreach ($names as $name) {
        $raw=file_get_contents($root.'/config/stream-defaults/'.$name);
        if ($raw===false || !is_array(json_decode($raw,true,512,JSON_THROW_ON_ERROR))) throw new RuntimeException('Invalid stream defaults: '.$name);
        $defaults[$name]=$raw;
    }
    if (!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)) throw new RuntimeException('Cannot create stream storage.');
    $lock=fopen($directory.'/.initialize.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock stream initialization.');
    try {
        // Validate existing files first; corrupt data must not be silently reset.
        foreach ($names as $name) {
            $path=$directory.'/'.$name;
            if (file_exists($path)) {
                $raw=file_get_contents($path);
                if ($raw===false || !is_array(json_decode($raw,true,512,JSON_THROW_ON_ERROR))) throw new RuntimeException('Invalid existing stream data: '.$name);
            }
        }
        foreach ($defaults as $name=>$raw) {
            $path=$directory.'/'.$name;
            if (file_exists($path)) {echo 'Preserved: '.$name.PHP_EOL;continue;}
            $tmp=tempnam($directory,'.initialize-');
            if ($tmp===false) throw new RuntimeException('Cannot create temporary stream file.');
            try {
                if (file_put_contents($tmp,$raw)!==strlen($raw) || !chmod($tmp,0640) || !rename($tmp,$path)) throw new RuntimeException('Cannot initialize '.$name);
            } finally {if (is_file($tmp)) unlink($tmp);}
            echo 'Initialized: '.$name.PHP_EOL;
        }
    } finally {flock($lock,LOCK_UN);fclose($lock);}
} catch (Throwable $error) {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
