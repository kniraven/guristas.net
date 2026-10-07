<?php
declare(strict_types=1);
/** Images remain outside the web root and are served only after ledger authorization. */
final class CommunityImages
{
    public const MAX_FILE = 8 * 1024 * 1024;
    public const MAX_TOTAL = 24 * 1024 * 1024;
    public function __construct(private string $directory) {}
    public static function inspect(string $path): array
    {
        $size=filesize($path);
        if ($size===false || $size<1 || $size>self::MAX_FILE) throw new InvalidArgumentException('Each image must be nonempty and no larger than 8 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        $extensions=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        $image=@getimagesize($path);
        if (!isset($extensions[$mime]) || !$image || ($image['mime']??'')!==$mime) throw new InvalidArgumentException('Use a valid PNG, JPEG or WebP image. SVG and executable files are not accepted.');
        if ($image[0]<1 || $image[1]<1 || $image[0]>12000 || $image[1]>12000 || $image[0]*$image[1]>40000000) throw new InvalidArgumentException('Images must be at most 12,000 pixels on either side and 40 million pixels total.');
        return ['mime'=>$mime,'extension'=>$extensions[$mime],'size'=>$size,'width'=>$image[0],'height'=>$image[1]];
    }
    public function receive(array $files, array $alternatives, string $type): array
    {
        if (!in_array($type,['art','comic'],true)) throw new InvalidArgumentException('Choose art or comic.');
        if (!isset($files['error']) || !is_array($files['error'])) throw new InvalidArgumentException('Choose at least one image.');
        $accepted=[];$total=0;
        foreach($files['error'] as $i=>$error) {
            if ($error===UPLOAD_ERR_NO_FILE) continue;
            if ($error!==UPLOAD_ERR_OK) throw new InvalidArgumentException('An image exceeds the server upload limit or failed to upload.');
            if (count($accepted)>=($type==='art'?1:10)) throw new InvalidArgumentException('Art uses one image; comics support up to ten pages per episode.');
            $path=$files['tmp_name'][$i]??'';
            if (!is_string($path) || !is_uploaded_file($path)) throw new InvalidArgumentException('Invalid image upload.');
            $info=self::inspect($path);$total+=$info['size'];
            if ($total>self::MAX_TOTAL) throw new InvalidArgumentException('Images must total 24 MB or less. Your server may have a lower limit.');
            $alt=CommunityNetwork::text($alternatives[$i]??'',3000);
            $accepted[]=$info+['tmp'=>$path,'alt'=>$alt,'id'=>bin2hex(random_bytes(24))];
        }
        if (!$accepted) throw new InvalidArgumentException('Choose at least one image.');
        if (!is_dir($this->directory) && !mkdir($this->directory,02750,true) && !is_dir($this->directory)) throw new RuntimeException('Cannot create image storage.');
        $stored=[];
        try {
            foreach($accepted as $image) {
                $path=$this->directory.'/'.$image['id'].'.'.$image['extension'];
                if (!move_uploaded_file($image['tmp'],$path)) throw new RuntimeException('Cannot store image.');
                $stored[]=$image;
                if (!chmod($path,0640)) throw new RuntimeException('Cannot protect image.');
            }
            return array_map(static function($image){unset($image['tmp']);return $image;},$stored);
        } catch(Throwable $e) {$this->cleanup($stored);throw $e;}
    }
    public function path(array $image): string
    {
        if (!preg_match('/^[a-f0-9]{48}$/D',(string)($image['id']??'')) || !in_array($image['extension']??'', ['png','jpg','webp'],true)) throw new RuntimeException('Invalid image reference.');
        return $this->directory.'/'.$image['id'].'.'.$image['extension'];
    }
    public function cleanup(array $images): void { foreach($images as $image){$path=$this->path($image);if(is_file($path))unlink($path);} }
}
function community_images(): CommunityImages { return new CommunityImages(dirname(__DIR__,2).'/storage/community/images'); }
