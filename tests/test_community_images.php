<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/services/CommunityNetwork.php';require dirname(__DIR__).'/app/services/CommunityImages.php';
$dir=sys_get_temp_dir().'/guri-images-'.bin2hex(random_bytes(8));mkdir($dir);$store=new CommunityImages($dir);
try {
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jf1sAAAAASUVORK5CYII=');file_put_contents($dir.'/valid.png',$png);
    $info=CommunityImages::inspect($dir.'/valid.png');if($info['mime']!=='image/png' || $info['width']!==1)throw new RuntimeException('PNG validation failed');
    foreach(['<?php echo "bad";','<svg xmlns="http://www.w3.org/2000/svg"></svg>','not an image'] as $i=>$content){$path=$dir.'/bad'.$i.'.png';file_put_contents($path,$content);$failed=false;try{CommunityImages::inspect($path);}catch(InvalidArgumentException $e){$failed=true;}if(!$failed)throw new RuntimeException('Invalid file accepted');}
    $failed=false;try{$store->path(['id'=>'../secret','extension'=>'png']);}catch(RuntimeException $e){$failed=true;}if(!$failed)throw new RuntimeException('Path traversal accepted');
    $failed=false;try{$store->receive(['error'=>[UPLOAD_ERR_OK],'tmp_name'=>[$dir.'/valid.png']],['Description'],'art');}catch(InvalidArgumentException $e){$failed=true;}if(!$failed)throw new RuntimeException('Non-upload file accepted');
    echo "Image signatures, raster-only policy, path validation and genuine upload checks passed.\n";
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
