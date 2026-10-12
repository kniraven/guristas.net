<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
$path=dirname(__DIR__).'/storage/cache/economic-intelligence/trade-shadow-audit.json';
if(!is_file($path)){fwrite(STDERR,"No audit observations yet. Run scheduled publish first.\n");exit(2);}
$d=json_decode((string)file_get_contents($path),true);
if(!is_array($d)){fwrite(STDERR,"Audit data is invalid.\n");exit(1);}
$summary=$d['summary']??[];
if(($argv[1]??'')==='json'){
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit(0);
}
echo "GURISTAS TRADE SHADOW AUDIT (rolling 7 days)\n";
foreach($summary as $key=>$value){if(is_scalar($value)||$value===null)printf("%-38s %s\n",$key,($value===null?'N/A':(string)$value));}
if(($argv[1]??'')==='recent'){
    foreach(array_slice($d['events']??[],-5) as $e){
        echo "\n",gmdate('c',(int)$e['timestamp']),"\n";
        foreach($e['rows']??[] as $r)printf("  %s | %s | profit %.2fM ISK | existing orders checked %d, missing %d, changed %d\n",
            $r['item']??'Unknown',$r['strategy']??'Unknown',($r['net_isk']??0)/1000000,
            $r['verification']['orders_checked']??0,$r['verification']['orders_unverified']??0,$r['verification']['orders_changed']??0);
    }
}
