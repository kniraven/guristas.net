<?php
declare(strict_types=1);
/** Persistent editorial and review ledger. Runtime data is never part of a patch. */
final class CommunityNetwork
{
    public const TYPES = ['fleet', 'supply', 'report', 'art', 'comic', 'broadcast'];
    public function __construct(private string $directory) {}
    public function read(): array { return $this->transaction(null); }
    private function transaction(?callable $change): array
    {
        if (!is_dir($this->directory)) {
            if (!$change) return ['revision'=>0, 'entries'=>[], 'claims'=>[]];
            if (!mkdir($this->directory, 02750, true) && !is_dir($this->directory)) throw new RuntimeException('Cannot create the community ledger.');
        }
        $lock = fopen($this->directory.'/ledger.lock', 'c+b');
        if (!$lock || !flock($lock, $change ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Community ledger unavailable.');
        try {
            $path = $this->directory.'/ledger.json';
            $data = is_file($path) ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : ['revision'=>0,'entries'=>[],'claims'=>[]];
            if (!isset($data['revision'],$data['entries'],$data['claims'])) throw new RuntimeException('Invalid community ledger.');
            if ($change) {
                $data = $change($data); ++$data['revision'];
                $temp = $path.'.'.bin2hex(random_bytes(8));
                try {
                    $json = json_encode($data, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
                    if (file_put_contents($temp,$json)!==strlen($json) || !chmod($temp,0640) || !rename($temp,$path)) throw new RuntimeException('Cannot save community ledger.');
                } finally { if (is_file($temp)) unlink($temp); }
            }
            return $data;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function text(mixed $value, int $max, bool $required = true): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('Invalid text field.');
        $value = trim($value);
        if (($required && $value === '') || strlen($value)>$max) throw new InvalidArgumentException('A required field is missing or too long.');
        return $value;
    }
    public static function link(string $url): string
    {
        $url = self::text($url,2000,false);
        if ($url !== '' && (!filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)parse_url($url,PHP_URL_SCHEME)) !== 'https' || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS))) throw new InvalidArgumentException('Use a full HTTPS link without credentials.');
        return $url;
    }
    public function apply(array $input, int $actor, string $name, bool $staff): array
    {
        if ($actor < 1) throw new InvalidArgumentException('Sign in first.');
        return $this->transaction(function(array $data) use ($input,$actor,$name,$staff): array {
            if ((string)($input['revision']??'') !== (string)$data['revision']) throw new RuntimeException('The board changed. Reload before saving.');
            $action = $input['action']??''; $id = $input['id']??'';
            if ($action === 'save') {
                if (!$staff) throw new InvalidArgumentException('Staff access required.');
                $type = $input['type']??''; $status=$input['status']??'';
                if (!in_array($type,self::TYPES,true) || !in_array($status,['draft','published','archived'],true)) throw new InvalidArgumentException('Invalid entry type or status.');
                if ($id !== '' && !isset($data['entries'][$id])) throw new InvalidArgumentException('Entry no longer exists.');
                if ($id !== '' && $data['entries'][$id]['type'] !== $type) throw new InvalidArgumentException('An existing entry cannot change type.');
                $when = self::text($input['when']??'',40,false);
                if ($when !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$when)) throw new InvalidArgumentException('Use a UTC date and time.');
                if ($when !== '') { $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$when,new DateTimeZone('UTC')); if (!$dt || $dt->format('Y-m-d\TH:i')!==$when) throw new InvalidArgumentException('Invalid date.'); }
                $url=self::link($input['url']??'');
                if ($status==='published' && in_array($type,['art','comic','broadcast'],true) && ($url==='' || trim($input['credit']??'')==='' || ($input['rights']??'')!=='yes')) throw new InvalidArgumentException('Media needs an approved link and permission to publish.');
                if ($status==='published' && in_array($type,['fleet','supply'],true) && (trim($input['location']??'')==='' || trim($input['contact']??'')==='')) throw new InvalidArgumentException('Operations need a destination and responsible contact.');
                if ($type==='fleet' && $status==='published' && $when==='') throw new InvalidArgumentException('A fleet needs its departure time in UTC.');
                $id = $id ?: bin2hex(random_bytes(12));
                $data['entries'][$id]=['id'=>$id,'type'=>$type,'status'=>$status,'title'=>self::text($input['title']??'',160),'body'=>self::text($input['body']??'',6000),'location'=>self::text($input['location']??'',200,false),'contact'=>self::text($input['contact']??'',200,false),'credit'=>self::text($input['credit']??'',300,false),'url'=>$url,'when'=>$when,'updated'=>gmdate('c'),'editor'=>$actor];
            } elseif ($action==='submit') {
                $entry=$data['entries'][$id]??null;
                if (!$entry || $entry['type']!=='supply' || $entry['status']!=='published' || ($entry['when']!=='' && $entry['when']<gmdate('Y-m-d\TH:i'))) throw new InvalidArgumentException('This supply job is no longer accepting submissions.');
                foreach($data['claims'] as $claim) if($claim['entry']===$id && $claim['actor']===$actor && $claim['status']==='pending') throw new InvalidArgumentException('You already have a delivery awaiting review for this job.');
                $key=bin2hex(random_bytes(12));
                $data['claims'][$key]=['id'=>$key,'entry'=>$id,'actor'=>$actor,'name'=>$name,'evidence'=>self::text($input['evidence']??'',3000),'status'=>'pending','submitted'=>gmdate('c'),'note'=>''];
            } elseif ($action==='review') {
                if (!$staff || !isset($data['claims'][$id])) throw new InvalidArgumentException('Staff access and a valid delivery are required.');
                $status=$input['status']??'';
                if(!in_array($status,['approved','rejected'],true) || $data['claims'][$id]['status']!=='pending') throw new InvalidArgumentException('This delivery has already been reviewed.');
                if($data['claims'][$id]['actor']===$actor) throw new InvalidArgumentException('Another officer must review your own delivery.');
                $data['claims'][$id]['status']=$status; $data['claims'][$id]['reviewer']=$actor; $data['claims'][$id]['reviewed']=gmdate('c'); $data['claims'][$id]['note']=self::text($input['note']??'',1000);
            } else throw new InvalidArgumentException('Unknown action.');
            return $data;
        });
    }
    public static function published(array $data, array $types): array
    {
        $entries=array_filter($data['entries'],fn($e)=>$e['status']==='published' && in_array($e['type'],$types,true));
        usort($entries,fn($a,$b)=>strcmp($b['updated'],$a['updated']));
        return $entries;
    }
}
function community_network(): CommunityNetwork { return new CommunityNetwork(dirname(__DIR__,2).'/storage/community'); }
