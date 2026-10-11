<?php
declare(strict_types=1);
/** Bounded-memory ESI market pages. Never caches decoded order arrays. */
final class GuristasMarketPageClient {
    private array $config;
    private string $dir;
    public function __construct(array $config,string $dir) {
        if(($config['base_url']??'')!=='https://esi.evetech.net')throw new RuntimeException('Untrusted ESI origin');
        if(!extension_loaded('curl'))throw new RuntimeException('Market pages require cURL');
        $this->config=$config;$this->dir=$dir;
    }
    public function getPage(int $region,int $page): array {
        if($region<10000001||$region>11000000||$page<1||$page>750)throw new InvalidArgumentException('Invalid market request');
        if(!is_dir($this->dir)&&!mkdir($this->dir,02770,true)&&!is_dir($this->dir))throw new RuntimeException('Market page directory unavailable');
        $file=$this->dir.'/'.$region.'-'.$page.'.json.gz';
        $cached=$this->readCache($file);
        $now=time();
        if($cached!==null && $cached['expires_at']>$now)return $this->decode($cached,'HIT');
        $headers=['Accept: application/json','X-Compatibility-Date: '.$this->config['compatibility_date'],'User-Agent: '.$this->config['user_agent']];
        if($cached!==null && ($cached['etag']??'')!=='')$headers[]='If-None-Match: '.$cached['etag'];
        $responseHeaders=[];
        $url='https://esi.evetech.net/markets/'.$region.'/orders/?'.http_build_query(['datasource'=>$this->config['datasource'],'order_type'=>'all','page'=>$page],'','&',PHP_QUERY_RFC3986);
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>(int)$this->config['connect_timeout_seconds'],CURLOPT_TIMEOUT=>(int)$this->config['request_timeout_seconds'],CURLOPT_HTTPHEADER=>$headers,CURLOPT_ENCODING=>'',CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$responseHeaders):int{
            $length=strlen($line);if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$responseHeaders[strtolower(trim($key))]=trim($value);}return $length;
        }]);
        $body=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($status===304 && $cached!==null){
            $cached['expires_at']=$this->expiry($responseHeaders,$now);
            $cached['fetched_at']=$now;
            $this->writeCache($file,$cached);
            return $this->decode($cached,'REVALIDATED');
        }
        if($status!==200||!is_string($body))throw new RuntimeException('Market ESI HTTP '.$status.' region '.$region.' page '.$page.($error!==''?' '.$error:''));
        $pages=(int)($responseHeaders['x-pages']??0);
        if($pages<1||$pages>750)throw new RuntimeException('Invalid ESI page count for '.$region);
        // Validate JSON before publishing cached response. json_decode only one page at a time.
        $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data)||!array_is_list($data))throw new RuntimeException('Invalid ESI order page');
        $record=['body'=>base64_encode(gzencode($body,4)),'pages'=>$pages,'etag'=>$responseHeaders['etag']??'', 'fetched_at'=>$now,'expires_at'=>$this->expiry($responseHeaders,$now)];
        $this->writeCache($file,$record);
        unset($body);
        return ['data'=>$data,'meta'=>['pages'=>$pages,'stale'=>false,'fetched_at'=>gmdate('c',$now),'cache'=>'MISS']];
    }
    private function decode(array $cache,string $state):array{
        $body=gzdecode(base64_decode($cache['body'],true));
        if(!is_string($body))throw new RuntimeException('Invalid compressed market cache');
        $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Invalid cached market page');
        return ['data'=>$data,'meta'=>['pages'=>(int)$cache['pages'],'stale'=>false,'fetched_at'=>gmdate('c',(int)$cache['fetched_at']),'cache'=>$state]];
    }
    private function expiry(array $headers,int $now):int{
        if(isset($headers['cache-control'])&&preg_match('/(?:^|,)\s*max-age=(\d+)/i',$headers['cache-control'],$m))return $now+max(1,(int)$m[1]);
        return $now+300;
    }
    private function readCache(string $file):?array{
        if(!is_file($file))return null;
        $packed=file_get_contents($file);
        if(!is_string($packed))return null;
        $json=gzdecode($packed);$value=is_string($json)?json_decode($json,true):null;
        return is_array($value)&&isset($value['body'],$value['pages'],$value['expires_at'],$value['fetched_at'])?$value:null;
    }
    private function writeCache(string $file,array $data):void{
        $tmp=tempnam($this->dir,'page-');
        if($tmp===false||dirname($tmp)!==$this->dir)throw new RuntimeException('Market page cache directory is not writable');
        try{
            $encoded=gzencode(json_encode($data,JSON_THROW_ON_ERROR),4);
            if(file_put_contents($tmp,$encoded,LOCK_EX)===false||!chmod($tmp,0640)||!rename($tmp,$file))throw new RuntimeException('Cannot publish market page cache');
        }finally{if(is_file($tmp))unlink($tmp);}
    }
}
