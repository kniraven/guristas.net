<?php
declare(strict_types=1);
$root=dirname(__DIR__,3);
require_once $root.'/app/services/TicketService.php';
require_once $root.'/app/services/PublicIndustry.php';
require_once $root.'/app/services/SiteTheme.php';
$viewer=eve_require_user();
if(!tickets_staff((int)$viewer['character_id'])){http_response_code(403);exit('Staff access required');}
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$live=$root.'/storage/venal-mining-resources.json';
$seed=$root.'/config/venal-mining-resources.json';
$types=guri_industry_reference()['types']??[];
$allowedFile=$root.'/app/data/mining-resource-types.json';
$allowedData=json_decode((string)@file_get_contents($allowedFile),true);
$allow=[];
foreach(($allowedData['items']??[]) as $item){
    $id=(int)($item['type_id']??0);$category=(string)($item['category']??'');
    if($id>0&&in_array($category,['ore','gas','ice'],true)&&isset($types[(string)$id])&&($types[(string)$id]['name']??'')===($item['name']??null))$allow[$id]=['type_id'=>$id,'name'=>$item['name'],'category'=>$category];
}
$systemsData=json_decode((string)@file_get_contents($root.'/app/data/venal-systems.json'),true);
$venalSystems=[];
foreach(($systemsData['systems']??[]) as $row)if(isset($row['name']))$venalSystems[]=(string)$row['name'];
sort($venalSystems,SORT_STRING);
$siteOptionsByCategory=['ore'=>['Asteroid belt','Ore anomaly'],'ice'=>['Ice anomaly'],'gas'=>['Gas cosmic signature']];
$siteOptions=array_merge(...array_values($siteOptionsByCategory));
$skillOptions=[0=>'Not specified',3386=>'Mining',16281=>'Ice Harvesting',25544=>'Gas Cloud Harvesting',11395=>'Deep Core Mining',12189=>'Mercoxit Processing'];

function vm_read(string $live,string $seed):array{
    $value=json_decode((string)file_get_contents(is_file($live)?$live:$seed),true);
    if(!is_array($value)||($value['region_id']??null)!==10000015||!is_array($value['resources']??null))throw new RuntimeException('Venal catalog unavailable');
    return $value;
}
function vm_h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function vm_response(array $payload,int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
/** Local CCP-ID allowlist; never classify arbitrary items from staff form values. */
function vm_classify(int $typeId,array $reference):?string{
    global $allow;
    return isset($allow[$typeId]) && ($allow[$typeId]['name']==($reference['name']??null)) ? $allow[$typeId]['category'] : null;
}
try{$catalog=vm_read($live,$seed);}catch(Throwable $e){http_response_code(503);exit('Catalog unavailable');}
$revision=hash('sha256',json_encode($catalog,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
if(isset($_GET['api'])){
    try{
        if($_GET['api']==='catalog')vm_response(['ok'=>true,'resources'=>$catalog['resources'],'revision'=>$revision]);
        if($_GET['api']==='search'){
            $q=strtolower(trim((string)($_GET['q']??'')));
            $items=array_values(array_filter($allow,static fn($r)=>$q===''||str_contains(strtolower($r['name']),$q)));
            usort($items,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
            vm_response(['ok'=>true,'items'=>$items]);
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
        if(!$id||!isset($types[(string)$id])||!isset($allow[$id]))throw new InvalidArgumentException('Select an approved ore, gas, or ice resource.');
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
            $level=filter_var($_POST['skill_level']??'0',FILTER_VALIDATE_INT);
            $systemInput=json_decode((string)($_POST['confirmed_systems']??'[]'),true);
            if(!is_array($systemInput)||count($systemInput)>count($venalSystems))throw new InvalidArgumentException('Invalid system selections.');
            $confirmed=array_values(array_unique(array_map('strval',$systemInput)));
            foreach($confirmed as $system)if(!in_array($system,$venalSystems,true))throw new InvalidArgumentException('Only Venal systems may be selected.');
            if(!isset($skillOptions[$skill])||$level===false||$level<0||$level>5||($skill===0&&$level!==0))throw new InvalidArgumentException('Choose a supported mining skill and level.');
            if(!in_array($site,$siteOptionsByCategory[$category]??[],true))throw new InvalidArgumentException('Site type is incompatible with the selected resource category.');
            if($skill===false||$skill<0||strlen($site)>180||strlen($source)>400||strlen($basis)>800||$site===''||$basis===''||!filter_var($source,FILTER_VALIDATE_URL)||parse_url($source,PHP_URL_SCHEME)!=='https')throw new InvalidArgumentException('Provide a site, HTTPS evidence URL, explanation, and valid skill ID.');
            $entry=['type_id'=>$id,'name'=>(string)$reference['name'],'category'=>$category,'site'=>$site,'status'=>$status,'source'=>$source,'basis'=>$basis,'skill_id'=>$skill,'skill_level'=>$level,'confirmed_systems'=>$confirmed];
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
<!doctype html><html lang="en" data-theme="<?=vm_h(SiteTheme::initial())?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Venal Mining Resources // Guristas.net</title>
<?php foreach(['structure','themes','auth','tickets','site-usability','mining-admin'] as $asset): ?><link rel="stylesheet" href="/assets/css/<?=$asset?>.css?v=<?=filemtime($root.'/public/assets/css/'.$asset.'.css')?>"><?php endforeach; ?>
<?php foreach(['themes','site','auth'] as $asset): ?><script defer src="/assets/js/<?=$asset?>.js?v=<?=filemtime($root.'/public/assets/js/'.$asset.'.js')?>"></script><?php endforeach; ?>
<script>window.guristasAccount=<?=json_encode(['signedIn'=>true,'csrf'=>eve_csrf()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script>
</head><body><a class="skip-link" href="#admin">Skip to mining administration</a><div class="ambient-grid" aria-hidden="true"></div><div class="screen-noise" aria-hidden="true"></div><div class="scanlines" aria-hidden="true"></div>
<?php $navActive='admin';$headerClass='site-header';$headerSubtitle='Pirate Command Network';require $root.'/app/views/partials/site-header.php'; ?>
<main class="shell ticket-page mining-admin" id="admin"><a href="/admin/" class="mine-back">← Administration</a><div class="ticket-heading"><div><p class="eyebrow">GURISTAS.NET // STAFF OPERATIONS</p><h1>Venal mining resources</h1><p>Maintain resource eligibility, site types, skill requirements and confirmed Venal sightings. BUILD updates on its next scheduled publication.</p></div></div>
<section class="ticket-panel mine-panel" aria-labelledby="catalog-label"><div class="mine-heading"><h2 id="catalog-label">Resource catalog</h2><span id="catalog-count"></span></div>
<div class="mine-toolbar"><label>Find resources<input type="search" id="resource-filter" placeholder="Name or site"></label><label>Category<select id="category-filter"><option value="">All types</option><option value="ore">Ore</option><option value="gas">Gas</option><option value="ice">Ice</option></select></label><label>Eligibility<select id="status-filter"><option value="">All</option><option value="eligible">Eligible</option><option value="disputed">Disputed</option><option value="disabled">Disabled</option></select></label><label>Sort<select id="sort"><option value="name">Name A–Z</option><option value="reverse">Name Z–A</option><option value="category">Category</option><option value="status">Eligibility</option></select></label></div>
<div class="mine-table-wrap"><table><thead><tr><th>Resource</th><th>Type</th><th>Eligibility</th><th>Confirmed systems</th><th>Action</th></tr></thead><tbody id="resource-rows"></tbody></table></div><div class="mine-actions"><button type="button" id="add-new">Add resource</button><button type="button" id="reload-catalog">Reload catalog</button></div></section>
<section class="ticket-panel mine-panel" aria-labelledby="editor-label" id="editor"><h2 id="editor-label">Add or edit resource</h2><p id="form-message" role="status" aria-live="polite"></p>
<form id="catalog-form" novalidate><input type="hidden" name="csrf" value="<?=vm_h(eve_csrf())?>"><input type="hidden" name="revision" id="revision" value="<?=vm_h($revision)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="type_id" id="type_id"><input type="hidden" name="confirmed_systems" id="confirmed_systems" value="[]">
<label>Resource <input type="search" id="find" placeholder="Filter ore, gas, and ice (all shown by default)" autocomplete="off"><select id="item-picker" aria-label="Ore, gas, and ice resources"><option value="">Select a resource</option></select></label><p class="mine-hint" id="search-status"></p>
<div class="mine-fields"><label>Category<input name="category" id="category" readonly required></label><label>Eligibility<select name="status" id="status"><option value="eligible">Eligible</option><option value="disputed">Disputed</option><option value="disabled">Disabled</option></select></label></div>
<label>Site type<select name="site" id="site" required><option value="">Select site type</option><?php foreach($siteOptions as $option): ?><option value="<?=vm_h($option)?>"><?=vm_h($option)?></option><?php endforeach; ?></select></label>
<div class="mine-fields"><label>Required skill<select name="skill_id" id="skill_id"><?php foreach($skillOptions as $id=>$name): ?><option value="<?=$id?>"><?=vm_h($name)?></option><?php endforeach; ?></select></label><label>Required skill level<select name="skill_level" id="skill_level"><option value="0">Not specified</option><?php foreach(range(1,5) as $level): ?><option value="<?=$level?>"><?=str_repeat('I',$level)?></option><?php endforeach; ?></select></label></div>
<fieldset class="mine-systems"><legend>Confirmed Venal systems <small>(optional; does not affect regional eligibility)</small></legend><label>Find system<input id="system-filter" type="search" placeholder="Filter Venal systems"></label><div id="system-list" class="mine-system-list" role="group" aria-label="Confirmed Venal systems"></div><p id="system-count" class="mine-hint"></p></fieldset>
<label>Evidence URL (HTTPS)<input name="source" id="source" type="url" maxlength="400" required></label><label>Why it can spawn in Venal<textarea name="basis" id="basis" maxlength="800" rows="3" required></textarea></label>
<div class="mine-actions"><button type="submit" id="save">Save resource</button><button type="button" id="delete" disabled>Remove selected resource</button><button type="button" id="cancel">Cancel edits</button></div></form></section></main>
<script id="initial-catalog" type="application/json"><?=json_encode(['resources'=>$catalog['resources'],'revision'=>$revision,'allowed'=>array_values($allow),'systems'=>$venalSystems],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script><script defer src="/assets/js/mining-admin.js?v=<?=filemtime($root.'/public/assets/js/mining-admin.js')?>"></script></body></html>
