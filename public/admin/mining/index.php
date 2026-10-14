<?php
declare(strict_types=1);
$root=dirname(__DIR__,3);
require_once $root.'/app/services/TicketService.php';
require_once $root.'/app/services/PublicIndustry.php';
$viewer=eve_require_user();
if(!tickets_staff((int)$viewer['character_id'])){http_response_code(403);exit('Staff access required');}
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$live=$root.'/storage/venal-mining-resources.json';
$seed=$root.'/config/venal-mining-resources.json';
$types=guri_industry_reference()['types']??[];
function vm_read(string $live,string $seed):array{
    $value=json_decode((string)file_get_contents(is_file($live)?$live:$seed),true);
    if(!is_array($value)||($value['region_id']??null)!==10000015||!is_array($value['resources']??null))throw new RuntimeException('Venal catalog unavailable');
    return $value;
}
function vm_h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function vm_response(array $payload,int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function vm_esi(string $path):?array{
    $cache=dirname(__DIR__,3).'/storage';
    $cached=$cache.'/mining-esi-'.sha1($path).'.json';
    if(is_file($cached)&&filemtime($cached)>time()-86400){$decoded=json_decode((string)file_get_contents($cached),true);if(is_array($decoded))return $decoded;}
    $context=stream_context_create(['http'=>['timeout'=>4,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nUser-Agent: GuristasNet-VenalMiningAdmin/1.0\r\n"]]);
    $raw=@file_get_contents('https://esi.evetech.net/latest/'.$path.'?datasource=tranquility',false,$context);
    if(!is_string($raw)||!is_array($value=json_decode($raw,true)))return null;
    if(is_dir($cache)&&is_writable($cache))@file_put_contents($cached,json_encode($value,JSON_UNESCAPED_SLASHES),LOCK_EX);
    return $value;
}
/** Verify the game item's real classification, not the staff-supplied category. */
function vm_classify(int $typeId,array $reference):?string{
    if(!isset($reference['name'],$reference['volume'])||(float)$reference['volume']<=0)return null;
    $name=(string)$reference['name'];
    if(preg_match('/\b(compressed|batch|blueprint)\b/i',$name))return null;
    $item=vm_esi('universe/types/'.$typeId.'/');
    if(!$item||empty($item['published'])||($item['name']??'')!==$name||!isset($item['group_id']))return null;
    $group=vm_esi('universe/groups/'.(int)$item['group_id'].'/');
    if(!$group)return null;
    $category=(int)($group['category_id']??-1);
    $groupName=(string)($group['name']??'');
    if((int)$item['group_id']===711||strcasecmp($groupName,'Harvestable Cloud')===0)return 'gas';
    if($category!==25)return null;
    if(stripos($groupName,'ice')!==false)return 'ice';
    // Other published asteroid types belong to ore groups. Never accept arbitrary items.
    return 'ore';
}
try{$catalog=vm_read($live,$seed);}catch(Throwable $e){http_response_code(503);exit('Catalog unavailable');}
$revision=hash('sha256',json_encode($catalog,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
if(isset($_GET['api'])){
    try{
        if($_GET['api']==='catalog')vm_response(['ok'=>true,'resources'=>$catalog['resources'],'revision'=>$revision]);
        if($_GET['api']==='search'){
            $query=trim((string)($_GET['q']??''));
            if(strlen($query)<2||strlen($query)>70)vm_response(['ok'=>true,'items'=>[]]);
            // Search the local SDE names, then validate *each result* using CCP's authoritative ESI classification.
            $matches=[];
            foreach($types as $id=>$record){
                if(isset($record['name'])&&stripos((string)$record['name'],$query)!==false&&isset($record['volume'])&&(float)$record['volume']>0)$matches[(int)$id]=$record;
            }
            uasort($matches,fn($a,$b)=>strnatcasecmp((string)$a['name'],(string)$b['name']));
            $items=[];$checked=0;
            foreach($matches as $id=>$record){
                if(++$checked>35||count($items)>=15)break;
                $category=vm_classify($id,$record);
                if($category!==null)$items[]=['type_id'=>$id,'name'=>(string)$record['name'],'category'=>$category];
            }
            vm_response(['ok'=>true,'items'=>$items,'limited'=>count($matches)>$checked]);
        }
        vm_response(['ok'=>false,'error'=>'Unknown request'],404);
    }catch(Throwable $e){vm_response(['ok'=>false,'error'=>'Resource lookup temporarily unavailable. Try again.'],503);}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        eve_require_csrf(is_string($_POST['csrf']??null)?$_POST['csrf']:null);
        if(!hash_equals($revision,(string)($_POST['revision']??'')))vm_response(['ok'=>false,'error'=>'The catalog changed since you opened it. Reload the catalog before saving.'],409);
        $action=(string)($_POST['action']??'');
        $id=filter_var($_POST['type_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id||!isset($types[(string)$id]))throw new InvalidArgumentException('Select a valid SDE item.');
        $reference=$types[(string)$id];
        $resources=$catalog['resources'];$found=null;
        foreach($resources as $index=>$entry)if((int)$entry['type_id']===$id){$found=$index;break;}
        if($action==='delete'){
            if($found===null)throw new InvalidArgumentException('Resource not found.');
            if((string)($_POST['confirm_name']??'')!==(string)$resources[$found]['name'])throw new InvalidArgumentException('Removal confirmation did not match.');
            array_splice($resources,$found,1);
        }elseif($action==='save'){
            $category=vm_classify($id,$reference);
            if($category===null)throw new InvalidArgumentException('CCP ESI cannot verify that this is a mineable ore, gas, or ice item. Nothing saved.');
            $claimed=(string)($_POST['category']??'');
            if($claimed!==$category)throw new InvalidArgumentException('Item category does not match CCP data.');
            $status=(string)($_POST['status']??'');
            if(!in_array($status,['eligible','disputed','disabled'],true))throw new InvalidArgumentException('Invalid eligibility.');
            $site=trim((string)($_POST['site']??''));$source=trim((string)($_POST['source']??''));$basis=trim((string)($_POST['basis']??''));
            $skill=filter_var($_POST['skill_id']??'0',FILTER_VALIDATE_INT);
            if($skill===false||$skill<0||strlen($site)>180||strlen($source)>400||strlen($basis)>800||$site===''||$basis===''||!filter_var($source,FILTER_VALIDATE_URL)||parse_url($source,PHP_URL_SCHEME)!=='https')throw new InvalidArgumentException('Provide a site, HTTPS evidence URL, explanation, and valid skill ID.');
            $entry=['type_id'=>$id,'name'=>(string)$reference['name'],'category'=>$category,'site'=>$site,'status'=>$status,'source'=>$source,'basis'=>$basis,'skill_id'=>$skill];
            if($found===null)$resources[]=$entry;else $resources[$found]=$entry;
        }else throw new InvalidArgumentException('Unknown action.');
        if(count($resources)>250)throw new InvalidArgumentException('Catalog limit reached.');
        $catalog['resources']=$resources;
        require_once $root.'/app/services/BuildIntelligence.php';
        $serialized=json_encode($catalog,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $temporary=tempnam($root.'/storage','venal-');
        if(!$temporary)throw new RuntimeException('Runtime storage is unavailable.');
        try{
            if(file_put_contents($temporary,$serialized,LOCK_EX)===false)throw new RuntimeException('Write failed.');
            GuristasBuildIntelligence::venalMiningCatalog($temporary,$types);
            if(!chmod($temporary,0644)||!rename($temporary,$live))throw new RuntimeException('Catalog save failed.');
        }finally{if(is_file($temporary))unlink($temporary);}
        $newRevision=hash('sha256',json_encode($catalog,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        vm_response(['ok'=>true,'resources'=>$resources,'revision'=>$newRevision,'message'=>'Saved. BUILD will use this catalog on its next publication.']);
    }catch(InvalidArgumentException $e){vm_response(['ok'=>false,'error'=>$e->getMessage()],422);}
    catch(Throwable $e){vm_response(['ok'=>false,'error'=>'Save failed. No catalog change confirmed.'],500);}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Venal Mining Catalog // Guristas.net</title>
<link rel="stylesheet" href="/assets/css/structure.css"><link rel="stylesheet" href="/assets/css/themes.css"><link rel="stylesheet" href="/assets/css/tickets.css"><link rel="stylesheet" href="/assets/css/site-usability.css"><link rel="stylesheet" href="/assets/css/mining-admin.css"></head><body>
<main class="mining-admin"><a href="/admin/">← Administration</a><p class="eyebrow">GURISTAS.NET // STAFF OPERATIONS</p><h1>Venal mining resources</h1><p>Manage the ore, gas, and ice that BUILD may recommend in Venal. Only eligible resources compete. Saves persist across deployments and are applied at the next BUILD publication.</p>
<section class="mine-panel" aria-labelledby="catalog-label"><div class="mine-heading"><h2 id="catalog-label">Resource catalog</h2><span id="catalog-count"></span></div>
<div class="mine-toolbar"><label>Find resources<input type="search" id="resource-filter" placeholder="Search resource or site"></label><label>Category<select id="category-filter"><option value="">All</option><option value="ore">Ore</option><option value="gas">Gas</option><option value="ice">Ice</option></select></label><label>Eligibility<select id="status-filter"><option value="">All</option><option value="eligible">Eligible</option><option value="disputed">Disputed</option><option value="disabled">Disabled</option></select></label><label>Sort<select id="sort"><option value="name">Name A–Z</option><option value="reverse">Name Z–A</option><option value="category">Category</option><option value="status">Eligibility</option></select></label></div>
<div class="mine-table-wrap"><table><thead><tr><th>Resource</th><th>Category</th><th>Eligibility</th><th>Action</th></tr></thead><tbody id="resource-rows"></tbody></table></div>
<div class="mine-actions"><button type="button" id="add-new">Add resource</button><button type="button" id="reload-catalog">Reload catalog</button></div></section>
<section class="mine-panel" aria-labelledby="editor-label"><h2 id="editor-label">Add or edit resource</h2><p id="form-message" role="status" aria-live="polite"></p>
<form id="catalog-form" novalidate><input type="hidden" name="csrf" value="<?=vm_h(eve_csrf())?>"><input type="hidden" name="revision" id="revision" value="<?=vm_h($revision)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="type_id" id="type_id">
<label>Search ore, gas, or ice<input type="search" id="find" autocomplete="off" placeholder="Enter at least 2 characters"></label><label>Verified EVE resource<select id="item-picker" aria-label="CCP-verified mineable items"><option value="">Search to see matching resources</option></select></label><p class="mine-hint" id="search-status">Results are checked against CCP ESI before they appear.</p>
<div class="mine-fields"><label>Category<input name="category" id="category" readonly required></label><label>Eligibility<select name="status" id="status"><option value="eligible">Eligible</option><option value="disputed">Disputed</option><option value="disabled">Disabled</option></select></label></div>
<label>Site type<input name="site" id="site" maxlength="180" required></label><label>Evidence URL (HTTPS)<input name="source" id="source" type="url" maxlength="400" required></label><label>Why it can spawn in Venal<textarea name="basis" id="basis" maxlength="800" rows="3" required></textarea></label><label>Required skill ID (0 if unknown)<input name="skill_id" id="skill_id" type="number" min="0" value="0" required></label>
<div class="mine-actions"><button type="submit" id="save">Save resource</button><button type="button" id="delete" disabled>Remove selected resource</button><button type="button" id="cancel">Cancel edits</button></div></form></section></main>
<script id="initial-catalog" type="application/json"><?=json_encode(['resources'=>$catalog['resources'],'revision'=>$revision],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script><script defer src="/assets/js/mining-admin.js"></script></body></html>
