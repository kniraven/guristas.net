<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/services/CommunityNetwork.php';
function check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
function rejected(callable $fn): void {try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Expected rejection');}
$dir=sys_get_temp_dir().'/guri-community-'.bin2hex(random_bytes(8));$store=new CommunityNetwork($dir);$now=1801872000;
try {
    check($store->read()['revision']===0,'Empty board');
    $form=['action'=>'save','revision'=>0,'type'=>'supply','status'=>'published','title'=>'Fuel order','body'=>'Confirm payment before hauling.','location'=>'The Fulcrum','contact'=>'Officer','credit'=>'','url'=>'','when'=>'','item'=>'Fuel Blocks','quantity'=>10,'reward'=>'100.00','terms'=>'Officer pays after contract verification.'];
    rejected(fn()=> $store->apply($form,10,'Pilot',false,[],$now));
    $data=$store->apply($form,20,'Officer',true,[],$now);$id=array_key_first($data['entries']);
    $data=$store->apply(['action'=>'reserve','revision'=>1,'id'=>$id,'quantity'=>7],10,'Pilot',false,[],$now);$claim=array_key_first($data['claims']);
    check(CommunityNetwork::progress($data,$id,$now)['available']===3,'Reserved quantity deducted');
    rejected(fn()=> $store->apply(['action'=>'reserve','revision'=>2,'id'=>$id,'quantity'=>4],11,'Other',false,[],$now));
    rejected(fn()=> $store->apply(['action'=>'reserve','revision'=>1,'id'=>$id,'quantity'=>3],11,'Other',false,[],$now));
    rejected(fn()=> $store->apply(['action'=>'cancel','revision'=>2,'id'=>$claim],11,'Other',false,[],$now));
    rejected(fn()=> $store->apply(['action'=>'submit','revision'=>2,'reservation'=>$claim,'evidence'=>'Forged'],11,'Other',false,[],$now));
    rejected(fn()=> $store->apply(['action'=>'submit','revision'=>2,'id'=>$id,'evidence'=>'Skip reservation'],11,'Other',false,[],$now));
    $changed=$form;$changed['revision']=2;$changed['id']=$id;$changed['reward']='1000';rejected(fn()=> $store->apply($changed,20,'Officer',true,[],$now));
    $data=$store->apply(['action'=>'submit','revision'=>2,'reservation'=>$claim,'quantity'=>999,'evidence'=>'Contract 123: seven units'],10,'Pilot',false,[],$now);
    check($data['claims'][$claim]['quantity']===7,'Posted quantity cannot inflate reservation');
    check(CommunityNetwork::progress($data,$id,$now)['verified']===0,'Evidence is not verified progress');
    rejected(fn()=> $store->apply(['action'=>'review','revision'=>3,'id'=>$claim,'status'=>'approved','note'=>'Self review'],10,'Pilot',true,[],$now));
    $data=$store->apply(['action'=>'review','revision'=>3,'id'=>$claim,'status'=>'approved','note'=>'Verified in EVE'],20,'Officer',true,[],$now);
    check(CommunityNetwork::progress($data,$id,$now)['verified']===7,'Reviewed quantity counted');
    rejected(fn()=> $store->apply(['action'=>'review','revision'=>4,'id'=>$claim,'status'=>'approved','note'=>'Again'],20,'Officer',true,[],$now));
    $data=$store->apply(['action'=>'reserve','revision'=>4,'id'=>$id,'quantity'=>3],11,'Other',false,[],$now);$second=array_key_last($data['claims']);
    check(CommunityNetwork::progress($data,$id,$now+48*3600)['available']===3,'Expired reservation releases units');
    rejected(fn()=> $store->apply(['action'=>'submit','revision'=>5,'reservation'=>$second,'evidence'=>'Late'],11,'Other',false,[],$now+48*3600));
    $data=$store->apply(['action'=>'cancel','revision'=>5,'id'=>$second],11,'Other',false,[],$now);
    check(CommunityNetwork::progress($data,$id,$now)['available']===3,'Cancel releases units');
    $closed=$form;$closed['revision']=6;$closed['id']=$id;$closed['status']='archived';$closed['public_archive']='yes';$data=$store->apply($closed,20,'Officer',true,[],$now);
    check(count(CommunityNetwork::published($data,['supply']))===1,'Explicit public archive preserves progress');
    rejected(fn()=> $store->apply(['action'=>'reserve','revision'=>7,'id'=>$id,'quantity'=>1],12,'Other',false,[],$now));
    $public=CommunityNetwork::published($data,['supply']);check(!isset($public[0]['evidence']) && !isset($public[0]['claims']),'Public entries exclude delivery evidence');
    rejected(fn()=> CommunityNetwork::link('javascript:alert(1)'));rejected(fn()=> CommunityNetwork::link('https://user:password@example.com'));
    $image=['id'=>str_repeat('a',48),'extension'=>'png','mime'=>'image/png','size'=>70,'width'=>1,'height'=>1,'alt'=>'First page transcript'];$image2=$image;$image2['id']=str_repeat('b',48);$image2['alt']='Second page transcript';
    $media=['action'=>'media_submit','revision'=>7,'type'=>'comic','title'=>'Episode one','credit'=>'Creator','body'=>'A comic','rights'=>'yes'];
    $data=$store->apply($media,10,'Pilot',false,[$image,$image2],$now);$comic=array_key_last($data['entries']);
    check(!CommunityNetwork::published($data,['comic']),'Member submission stays private');
    $publish=['action'=>'save','revision'=>8,'id'=>$comic,'type'=>'comic','status'=>'published','title'=>'Episode one','body'=>'A comic','credit'=>'Creator','rights'=>'yes','positions'=>[$image['id']=>2,$image2['id']=>1],'alternatives'=>[$image['id']=>'First page transcript',$image2['id']=>'Second page transcript']];
    $bad=$publish;$bad['positions'][$image['id']]=1;rejected(fn()=> $store->apply($bad,20,'Officer',true,[],$now));
    $data=$store->apply($publish,20,'Officer',true,[],$now);check($data['entries'][$comic]['images'][0]['id']===$image2['id'],'Staff can order comic pages');
    $publish['revision']=9;$publish['status']='archived';$publish['public_archive']='yes';$data=$store->apply($publish,20,'Officer',true,[],$now);check(!CommunityNetwork::published($data,['comic']),'Archived media remains private');
    check($store->read()['revision']===10,'Persistence');
    // A previous batch's unstructured job is retained without assigning invented quantities.
    $legacy=$data;$legacy['entries'][$id]['status']='published';unset($legacy['entries'][$id]['quantity']);file_put_contents($dir.'/ledger.json',json_encode($legacy));
    $data=$store->apply(['action'=>'submit','revision'=>10,'id'=>$id,'evidence'=>'Legacy contract'],12,'Old pilot',false,[],$now);
    check(!isset($data['claims'][array_key_last($data['claims'])]['quantity']),'Legacy evidence has no inferred units');
    echo "Community permissions, reservations, expiry, review, media order and legacy compatibility passed.\n";
} finally {foreach(glob($dir.'/*')?:[] as $file)unlink($file);if(is_dir($dir))rmdir($dir);}
