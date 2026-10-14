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
$file=is_file($live)?$live:$seed;
$catalog=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
$types=guri_industry_reference()['types']??[];
$messages=[];$error='';
$statuses=['eligible','disputed','disabled'];$categories=['ore','gas','ice'];
if($_SERVER['REQUEST_METHOD']==='POST'){
    eve_require_csrf(is_string($_POST['csrf']??null)?$_POST['csrf']:null);
    try{
        $action=(string)($_POST['action']??'');
        $id=filter_var($_POST['type_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id||!isset($types[(string)$id]))throw new InvalidArgumentException('Choose an item from the SDE reference.');
        $ref=$types[(string)$id];
        if(!isset($ref['volume'])||(float)$ref['volume']<=0)throw new InvalidArgumentException('Selected item has no valid volume.');
        $resources=$catalog['resources'];$found=null;
        foreach($resources as $idx=>$row)if((int)$row['type_id']===$id){$found=$idx;break;}
        if($action==='delete'){
            if($found===null)throw new InvalidArgumentException('Resource is not in the catalog.');
            array_splice($resources,$found,1);
        }elseif($action==='save'){
            $category=(string)($_POST['category']??'');$status=(string)($_POST['status']??'');
            if(!in_array($category,$categories,true)||!in_array($status,$statuses,true))throw new InvalidArgumentException('Invalid category or status.');
            $site=trim((string)($_POST['site']??''));$source=trim((string)($_POST['source']??''));$basis=trim((string)($_POST['basis']??''));
            $skill=filter_var($_POST['skill_id']??'0',FILTER_VALIDATE_INT);
            if($skill===false||$skill<0||strlen($site)>180||strlen($source)>400||strlen($basis)>800||$site===''||$basis===''||!filter_var($source,FILTER_VALIDATE_URL)||!in_array(parse_url($source,PHP_URL_SCHEME),['https'],true))throw new InvalidArgumentException('Supply a site, HTTPS evidence source, explanation, and valid skill.');
            $row=['type_id'=>$id,'name'=>(string)$ref['name'],'category'=>$category,'site'=>$site,'status'=>$status,'source'=>$source,'basis'=>$basis,'skill_id'=>$skill];
            if($found===null)$resources[]=$row;else $resources[$found]=$row;
        }else throw new InvalidArgumentException('Unknown action.');
        if(count($resources)>250)throw new InvalidArgumentException('Catalog limit reached.');
        $catalog['resources']=$resources;
        // Validate the complete saved catalog against BUILD's existing rules.
        require_once $root.'/app/services/BuildIntelligence.php';
        $serialized=json_encode($catalog,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $target=$root.'/storage';if(!is_dir($target))throw new RuntimeException('Runtime storage unavailable');
        $temporary=tempnam($target,'venal-');if(!$temporary)throw new RuntimeException('Runtime storage not writable');
        try{
            if(file_put_contents($temporary,$serialized,LOCK_EX)===false)throw new RuntimeException('Could not write temporary catalog');
            GuristasBuildIntelligence::venalMiningCatalog($temporary,$types);
            if(!chmod($temporary,0644)||!rename($temporary,$live))throw new RuntimeException('Catalog save failed');
        }finally{if(is_file($temporary))unlink($temporary);}
        header('Location: /admin/mining/?saved=1',true,303);exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$catalog=json_decode((string)file_get_contents(is_file($live)?$live:$seed),true);
$rows=$catalog['resources']??[];
// Server-rendered searchable selection: only types available in our existing CCP SDE reference.
$options=[];foreach($types as $id=>$record){if(isset($record['name'],$record['volume'])&&(float)$record['volume']>0)$options[(int)$id]=(string)$record['name'];}
asort($options,SORT_NATURAL|SORT_FLAG_CASE);
function mining_h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Venal Mining Catalog // Staff</title>
<link rel="stylesheet" href="/assets/css/structure.css"><link rel="stylesheet" href="/assets/css/themes.css"><link rel="stylesheet" href="/assets/css/tickets.css">
<style>main{max-width:1050px;margin:2rem auto;padding:1rem}form{display:grid;gap:.65rem;padding:1rem;border:1px solid #956f8d;margin:1rem 0}label{display:grid;gap:.25rem}input,select,textarea{max-width:100%;padding:.5rem}table{width:100%;border-collapse:collapse}td,th{padding:.5rem;border-bottom:1px solid #777;text-align:left}button{padding:.55rem;cursor:pointer}</style></head><body><main><p><a href="/admin/">← Administration</a></p><h1>Venal mining resources</h1><p>Staff-maintained catalog. Only <strong>eligible</strong> entries compete for BUILD recommendations. Edits persist in runtime storage across deployments. Saving does not immediately republish BUILD.</p>
<?php if(isset($_GET['saved'])):?><p role="status">Catalog saved.</p><?php endif;?>
<?php if($error!==''):?><p role="alert"><?= mining_h($error) ?></p><?php endif;?>
<h2>Current resources</h2><table><thead><tr><th>Resource</th><th>Category</th><th>Status</th><th>Change</th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><td><?= mining_h((string)$row['name']) ?></td><td><?= mining_h((string)$row['category']) ?></td><td><?= mining_h((string)$row['status']) ?></td><td><button type="button" class="choose" data-resource="<?= mining_h(json_encode($row,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>">Edit</button></td></tr><?php endforeach;?></tbody></table>
<h2>Add or edit resource</h2><form method="post" id="catalog-form"><input type="hidden" name="csrf" value="<?= mining_h(eve_csrf()) ?>"><input type="hidden" name="action" id="action" value="save">
<label>Find an EVE item <input type="search" id="find" placeholder="Search item names to narrow the menu"></label>
<label>Item <select name="type_id" id="type_id" required><option value="">Select an item</option><?php foreach($options as $id=>$name):?><option value="<?= (int)$id ?>"><?= mining_h($name) ?> (<?= (int)$id ?>)</option><?php endforeach;?></select></label>
<label>Category <select name="category" id="category"><?php foreach($categories as $category):?><option><?= mining_h($category) ?></option><?php endforeach;?></select></label>
<label>Eligibility <select name="status" id="status"><?php foreach($statuses as $status):?><option><?= mining_h($status) ?></option><?php endforeach;?></select></label>
<label>Site type <input name="site" id="site" maxlength="180" required></label><label>Evidence URL (HTTPS) <input name="source" id="source" type="url" maxlength="400" required></label>
<label>Why this can spawn in Venal <textarea name="basis" id="basis" maxlength="800" required rows="3"></textarea></label>
<label>Required skill ID (0 if unknown) <input name="skill_id" id="skill_id" type="number" min="0" value="0" required></label>
<div><button type="submit" onclick="document.getElementById('action').value='save'">Save resource</button> <button type="submit" formnovalidate onclick="return confirm('Remove resource from Venal catalog?') && (document.getElementById('action').value='delete')">Remove selected resource</button></div></form>
<script>
const select=document.getElementById('type_id');const original=Array.from(select.options).map(o=>({value:o.value,text:o.textContent}));
document.getElementById('find').addEventListener('input',e=>{const term=e.target.value.toLowerCase().trim();const selected=select.value;select.replaceChildren();for(const o of original){if(o.value===selected||!term||o.text.toLowerCase().includes(term)){const option=new Option(o.text,o.value);select.add(option);}}select.value=selected;});
document.querySelectorAll('.choose').forEach(b=>b.addEventListener('click',()=>{const r=JSON.parse(b.dataset.resource);document.getElementById('find').value='';document.getElementById('find').dispatchEvent(new Event('input'));for(const key of ['type_id','category','status','site','source','basis','skill_id'])document.getElementById(key).value=r[key]??'';document.getElementById('catalog-form').scrollIntoView({behavior:'smooth'});}));
</script></main></body></html>
