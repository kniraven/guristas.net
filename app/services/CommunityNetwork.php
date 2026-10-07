<?php
declare(strict_types=1);
/** Runtime ledger, never shipped in an application patch. All mutations hold one stable lock. */
final class CommunityNetwork
{
    public const TYPES=['fleet','supply','report','art','comic','broadcast'];
    public function __construct(private string $directory) {}
    public function read(): array {return $this->transaction(null);}
    private function transaction(?callable $change): array
    {
        if (!is_dir($this->directory)) {
            if (!$change) return ['revision'=>0,'entries'=>[],'claims'=>[]];
            if (!mkdir($this->directory,02750,true) && !is_dir($this->directory)) throw new RuntimeException('Cannot create the community ledger.');
        }
        $lock=fopen($this->directory.'/ledger.lock','c+b');
        if (!$lock || !flock($lock,$change?LOCK_EX:LOCK_SH)) throw new RuntimeException('Community ledger unavailable.');
        try {
            $path=$this->directory.'/ledger.json';
            $data=is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):['revision'=>0,'entries'=>[],'claims'=>[]];
            if (!isset($data['revision'],$data['entries'],$data['claims'])) throw new RuntimeException('Invalid community ledger.');
            if ($change) {
                $data=$change($data);++$data['revision'];$temp=$path.'.'.bin2hex(random_bytes(8));
                try {
                    $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
                    if(file_put_contents($temp,$json)!==strlen($json) || !chmod($temp,0640) || !rename($temp,$path)) throw new RuntimeException('Cannot save community ledger.');
                } finally {if(is_file($temp))unlink($temp);}
            }
            return $data;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function text(mixed $value,int $max,bool $required=true): string
    {
        if(!is_string($value))throw new InvalidArgumentException('Invalid text field.');
        $value=trim($value);
        if(($required && $value==='') || strlen($value)>$max)throw new InvalidArgumentException('A required field is missing or too long.');
        return $value;
    }
    public static function number(mixed $value,int $min=1,int $max=1000000000): int
    {
        if(!is_int($value) && !is_string($value))throw new InvalidArgumentException('Enter a whole quantity.');
        if(!preg_match('/^[0-9]{1,10}$/D',(string)$value) || (int)$value<$min || (int)$value>$max)throw new InvalidArgumentException('Quantity is outside the allowed range.');
        return (int)$value;
    }
    public static function link(mixed $url): string
    {
        $url=self::text($url,2000,false);
        if($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https' || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS)))throw new InvalidArgumentException('Use a full HTTPS link without credentials.');
        return $url;
    }
    public static function visible(array $entry): bool
    {
        return ($entry['status']??'')==='published' || (($entry['status']??'')==='archived' && !empty($entry['public_archive']) && in_array($entry['type']??'', ['fleet','supply','report'],true));
    }
    public static function progress(array $data,string $entryId,?int $now=null): array
    {
        $now??=time();$verified=0;$held=0;$deliveries=0;
        foreach($data['claims'] as $claim) {
            if($claim['entry']!==$entryId)continue;
            if($claim['status']==='approved') {++$deliveries;$verified+=(int)($claim['quantity']??0);}
            if($claim['status']==='pending' || ($claim['status']==='reserved' && (int)($claim['expires']??0)>$now))$held+=(int)($claim['quantity']??0);
        }
        $target=(int)($data['entries'][$entryId]['quantity']??0);
        return ['target'=>$target,'verified'=>$verified,'held'=>$held,'available'=>max(0,$target-$verified-$held),'deliveries'=>$deliveries];
    }
    public function apply(array $input,int $actor,string $name,bool $staff,array $images=[],?int $now=null): array
    {
        if($actor<1)throw new InvalidArgumentException('Sign in first.');$now??=time();
        return $this->transaction(function(array $data)use($input,$actor,$name,$staff,$images,$now):array {
            if(self::number($input['revision']??'',0,2147483647)!==$data['revision'])throw new RuntimeException('The board changed. Reload before saving.');
            $action=self::text($input['action']??'',30);$id=self::text($input['id']??'',48,false);
            if($action==='media_submit') {
                $type=$input['type']??'';
                if(!in_array($type,['art','comic'],true) || !$images || count($images)>($type==='art'?1:10))throw new InvalidArgumentException('Choose art or comic and upload its images.');
                if(($input['rights']??'')!=='yes')throw new InvalidArgumentException('Confirm that you have permission to share these images.');
                $pending=0;$today=0;
                foreach($data['entries'] as $entry)if(($entry['author']??0)===$actor){if($entry['status']==='pending')++$pending;if(substr($entry['created']??'',0,10)===gmdate('Y-m-d',$now))++$today;}
                if(!$staff && ($pending>=5 || $today>=10))throw new InvalidArgumentException('You have reached the submission limit. Wait for review before adding more.');
                $category=$type==='art'?self::text($input['category']??'fan-art',20):'comic';
                if(!in_array($category,['fan-art','propaganda','comic'],true))throw new InvalidArgumentException('Choose a valid release category.');
                $id=bin2hex(random_bytes(12));
                $data['entries'][$id]=['id'=>$id,'type'=>$type,'status'=>$staff?'draft':'pending','title'=>self::text($input['title']??'',160),'body'=>self::text($input['body']??'',6000),'credit'=>self::text($input['credit']??'',300),'url'=>'','when'=>'','location'=>'','contact'=>'','category'=>$category,'author'=>$actor,'author_name'=>$name,'images'=>$images,'permission_at'=>gmdate('c',$now),'created'=>gmdate('c',$now),'updated'=>gmdate('c',$now),'editor'=>$actor];
            } elseif($action==='save') {
                if(!$staff)throw new InvalidArgumentException('Staff access required.');
                $type=$input['type']??'';$status=$input['status']??'';
                if(!in_array($type,self::TYPES,true) || !in_array($status,['draft','published','archived','rejected'],true))throw new InvalidArgumentException('Invalid entry type or status.');
                $old=$id!==''?($data['entries'][$id]??null):null;
                if($id!=='' && !$old)throw new InvalidArgumentException('Entry no longer exists.');
                if($old && $old['type']!==$type)throw new InvalidArgumentException('An existing entry cannot change type.');
                $when=self::text($input['when']??'',40,false);
                if($when!=='') {$dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$when,new DateTimeZone('UTC'));if(!$dt || $dt->format('Y-m-d\TH:i')!==$when)throw new InvalidArgumentException('Use a valid UTC date and time.');}
                $url=self::link($input['url']??'');$credit=self::text($input['credit']??'',300,false);
                $storedImages=$old['images']??[];
                if($storedImages && isset($input['positions'])) {
                    $positions=$input['positions'];$alts=$input['alternatives']??[];$seen=[];
                    if(!is_array($positions) || !is_array($alts) || count($positions)!==count($storedImages))throw new InvalidArgumentException('Supply an order and description for every page.');
                    foreach($storedImages as &$image){$position=self::number($positions[$image['id']]??'',1,count($storedImages));if(isset($seen[$position]))throw new InvalidArgumentException('Page positions must be unique.');$seen[$position]=true;$image['position']=$position;$image['alt']=self::text($alts[$image['id']]??'',3000);}unset($image);
                    usort($storedImages,fn($a,$b)=>$a['position']<=>$b['position']);
                }
                if($status==='published' && in_array($type,['art','comic','broadcast'],true) && ((!$storedImages && $url==='') || $credit==='' || ($input['rights']??'')!=='yes'))throw new InvalidArgumentException('Media needs images or an approved link, creator credit and verified permission.');
                $location=self::text($input['location']??'',200,false);$contact=self::text($input['contact']??'',200,false);
                if($status==='published' && in_array($type,['fleet','supply'],true) && ($location==='' || $contact===''))throw new InvalidArgumentException('Operations need a destination and responsible contact.');
                if($type==='fleet' && $status==='published' && $when==='')throw new InvalidArgumentException('A fleet needs its departure time in UTC.');
                $id=$id?:bin2hex(random_bytes(12));
                $entry=($old??[])+['created'=>gmdate('c',$now)];
                $entry=array_replace($entry,['id'=>$id,'type'=>$type,'status'=>$status,'title'=>self::text($input['title']??'',160),'body'=>self::text($input['body']??'',6000),'location'=>$location,'contact'=>$contact,'credit'=>$credit,'url'=>$url,'when'=>$when,'images'=>$storedImages,'updated'=>gmdate('c',$now),'editor'=>$actor,'review_note'=>self::text($input['review_note']??'',1000,false),'public_archive'=>($input['public_archive']??'')==='yes' && in_array($type,['fleet','supply','report'],true)]);
                if($status==='published'){$entry['published_by']=$actor;$entry['published_at']=gmdate('c',$now);}
                if($type==='art') {$category=self::text($input['category']??($old['category']??'fan-art'),20);if(!in_array($category,['fan-art','propaganda'],true))throw new InvalidArgumentException('Choose art or propaganda.');$entry['category']=$category;}
                if($type==='supply') {
                    $quantity=isset($input['quantity']) && $input['quantity']!==''?self::number($input['quantity']):0;
                    $item=self::text($input['item']??'',160,false);$terms=self::text($input['terms']??'',2000,false);
                    $reward=self::text($input['reward']??'0',30);if(!preg_match('/^[0-9]{1,12}(\.[0-9]{1,2})?$/D',$reward))throw new InvalidArgumentException('Enter an ISK reward per unit, using up to two decimals.');
                    if($status==='published' && ($quantity<1 || $item==='' || $terms===''))throw new InvalidArgumentException('Published supply jobs need an item, whole target quantity and payment terms.');
                    foreach($data['claims'] as $claim)if($claim['entry']===$id && isset($claim['quantity'])) {
                        foreach(['item'=>$item,'terms'=>$terms,'reward'=>$reward,'location'=>$location] as $field=>$value)if(($old[$field]??'')!==$value)throw new InvalidArgumentException('This job already has reservations. Keep its item, destination and payment terms; create a separate job for changes.');
                    }
                    $progress=self::progress($data,$id,$now);if($quantity<$progress['verified']+$progress['held'])throw new InvalidArgumentException('The target cannot be lower than verified and reserved quantities.');
                    $entry=array_replace($entry,['item'=>$item,'quantity'=>$quantity,'reward'=>$reward,'terms'=>$terms]);
                }
                if($type==='report') {
                    $fleet=self::text($input['fleet']??'',48,false);
                    if($fleet!=='' && (!isset($data['entries'][$fleet]) || $data['entries'][$fleet]['type']!=='fleet'))throw new InvalidArgumentException('Select an existing fleet.');
                    $entry['fleet']=$fleet;$entry['campaign']=self::text($input['campaign']??'',200,false);$entry['campaign_url']=self::link($input['campaign_url']??'');
                }
                $data['entries'][$id]=$entry;
            } elseif($action==='reserve') {
                $entry=$data['entries'][$id]??null;
                if(!$entry || $entry['type']!=='supply' || $entry['status']!=='published' || empty($entry['quantity']) || ($entry['when']!=='' && $entry['when']<=gmdate('Y-m-d\TH:i',$now)))throw new InvalidArgumentException('This job is not accepting reservations.');
                foreach($data['claims'] as $claim)if($claim['entry']===$id && $claim['actor']===$actor && ($claim['status']==='pending' || ($claim['status']==='reserved' && $claim['expires']>$now)))throw new InvalidArgumentException('You already have a reservation or delivery awaiting review for this job.');
                $quantity=self::number($input['quantity']??'');$progress=self::progress($data,$id,$now);
                if($quantity>$progress['available'])throw new InvalidArgumentException('That quantity is no longer available. Reload the board.');
                $expires=$now+48*3600;if($entry['when']!=='')$expires=min($expires,(new DateTimeImmutable($entry['when'],new DateTimeZone('UTC')))->getTimestamp());
                $key=bin2hex(random_bytes(12));
                $data['claims'][$key]=['id'=>$key,'entry'=>$id,'actor'=>$actor,'name'=>$name,'quantity'=>$quantity,'status'=>'reserved','expires'=>$expires,'submitted'=>'','reserved_at'=>gmdate('c',$now),'evidence'=>'','note'=>''];
            } elseif($action==='cancel') {
                $claim=$data['claims'][$id]??null;
                if(!$claim || $claim['actor']!==$actor || $claim['status']!=='reserved')throw new InvalidArgumentException('Only your unsubmitted reservation can be cancelled.');
                $data['claims'][$id]['status']='cancelled';$data['claims'][$id]['cancelled_at']=gmdate('c',$now);
            } elseif($action==='submit') {
                $reservation=self::text($input['reservation']??'',48,false);
                if($reservation!=='') {
                    $claim=$data['claims'][$reservation]??null;
                    if(!$claim || $claim['actor']!==$actor || $claim['status']!=='reserved' || $claim['expires']<=$now)throw new InvalidArgumentException('Your reservation has expired or is unavailable. Contact the organizer before delivering.');
                    $data['claims'][$reservation]['evidence']=self::text($input['evidence']??'',3000);$data['claims'][$reservation]['status']='pending';$data['claims'][$reservation]['submitted']=gmdate('c',$now);
                } else {
                    // Preserve existing unstructured jobs without retroactively awarding units.
                    $entry=$data['entries'][$id]??null;
                    if(!$entry || $entry['type']!=='supply' || $entry['status']!=='published' || !empty($entry['quantity']) || ($entry['when']!=='' && $entry['when']<gmdate('Y-m-d\TH:i',$now)))throw new InvalidArgumentException('Reserve a quantity before submitting delivery evidence.');
                    foreach($data['claims'] as $claim)if($claim['entry']===$id && $claim['actor']===$actor && $claim['status']==='pending')throw new InvalidArgumentException('You already have a delivery awaiting review for this job.');
                    $key=bin2hex(random_bytes(12));$data['claims'][$key]=['id'=>$key,'entry'=>$id,'actor'=>$actor,'name'=>$name,'evidence'=>self::text($input['evidence']??'',3000),'status'=>'pending','submitted'=>gmdate('c',$now),'note'=>''];
                }
            } elseif($action==='review') {
                if(!$staff || !isset($data['claims'][$id]))throw new InvalidArgumentException('Staff access and a valid delivery are required.');
                $claim=$data['claims'][$id];$status=$input['status']??'';
                if(!in_array($status,['approved','rejected'],true) || $claim['status']!=='pending')throw new InvalidArgumentException('This delivery has already been reviewed.');
                if($claim['actor']===$actor)throw new InvalidArgumentException('Another officer must review your own delivery.');
                $data['claims'][$id]=array_replace($claim,['status'=>$status,'reviewer'=>$actor,'reviewed'=>gmdate('c',$now),'note'=>self::text($input['note']??'',1000)]);
            } else throw new InvalidArgumentException('Unknown action.');
            $data['audit'][]=['action'=>$action,'actor'=>$actor,'subject'=>$id,'at'=>gmdate('c',$now)];
            $data['audit']=array_slice($data['audit'],-1000);
            return $data;
        });
    }
    public static function published(array $data,array $types): array
    {
        $entries=array_filter($data['entries'],fn($e)=>self::visible($e) && in_array($e['type'],$types,true));
        usort($entries,fn($a,$b)=>strcmp($b['updated'],$a['updated']));return $entries;
    }
}
function community_network(): CommunityNetwork {return new CommunityNetwork(dirname(__DIR__,2).'/storage/community');}
