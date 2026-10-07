<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/services/CommunityNetwork.php';
function check(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); }
function rejected(callable $fn): void { try{$fn();}catch(Throwable $e){return;} throw new RuntimeException('Expected rejection'); }
$dir=sys_get_temp_dir().'/guri-community-'.bin2hex(random_bytes(8)); $store=new CommunityNetwork($dir);
try {
 check($store->read()['revision']===0,'Empty board');
 $form=['action'=>'save','revision'=>'0','type'=>'supply','status'=>'published','title'=>'Fuel order','body'=>'Deliver 20 units. Confirm payment with the officer first.','location'=>'The Fulcrum','contact'=>'Officer','credit'=>'','url'=>'','when'=>''];
 rejected(fn()=> $store->apply($form,10,'Pilot',false));
 $data=$store->apply($form,20,'Officer',true); $id=array_key_first($data['entries']);
 rejected(fn()=> $store->apply($form,20,'Officer',true));
 $data=$store->apply(['action'=>'submit','revision'=>1,'id'=>$id,'evidence'=>'Contract 123, twenty units'],10,'Pilot',false);
 $claim=array_key_first($data['claims']);
 rejected(fn()=> $store->apply(['action'=>'submit','revision'=>2,'id'=>$id,'evidence'=>'Duplicate'],10,'Pilot',false));
 rejected(fn()=> $store->apply(['action'=>'review','revision'=>2,'id'=>$claim,'status'=>'approved','note'=>'Self'],10,'Pilot',true));
 rejected(fn()=> $store->apply(['action'=>'review','revision'=>2,'id'=>$claim,'status'=>'approved','note'=>'Forged'],11,'Pilot',false));
 $data=$store->apply(['action'=>'review','revision'=>2,'id'=>$claim,'status'=>'approved','note'=>'Verified contract in EVE'],20,'Officer',true);
 rejected(fn()=> $store->apply(['action'=>'review','revision'=>3,'id'=>$claim,'status'=>'approved','note'=>'Again'],20,'Officer',true));
 check($store->read()['claims'][$claim]['status']==='approved','Persistent review');
 $public=CommunityNetwork::published($data,['supply']); check(count($public)===1 && !isset($public[0]['evidence']),'Public excludes evidence');
 $form['revision']=3; $form['id']=$id;$form['status']='archived';$data=$store->apply($form,20,'Officer',true);
 check(!CommunityNetwork::published($data,['supply']) && count($data['claims'])===1,'Archive retains evidence');
 rejected(fn()=> CommunityNetwork::link('javascript:alert(1)')); rejected(fn()=> CommunityNetwork::link('https://user:password@example.com'));
 check(CommunityNetwork::link('https://example.com/episode')==='https://example.com/episode','Valid media');
 $form['revision']=4;unset($form['id']);$form['type']='comic';$form['status']='published';$form['url']='https://example.com/comic';
 rejected(fn()=> $store->apply($form,20,'Officer',true));
 $form['rights']='yes';$form['credit']='Creator';$data=$store->apply($form,20,'Officer',true);check(count(CommunityNetwork::published($data,['comic']))===1,'Approved media');
 echo "Community publishing and review checks passed.\n";
} finally { foreach(glob($dir.'/*')?:[] as $file)unlink($file);if(is_dir($dir))rmdir($dir); }
