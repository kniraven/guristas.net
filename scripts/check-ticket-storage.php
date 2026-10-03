<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/TicketStorageBridge.php';
$fields=['operation'=>'put','actor'=>'123','key'=>str_repeat('a',48),'id'=>'0','time'=>'123','nonce'=>str_repeat('b',32),'name'=>'test.txt','digest'=>str_repeat('c',64)];
$secret=str_repeat('d',64);
$signature=tickets_storage_signature($fields,$secret);
$reordered=array_reverse($fields,true);
if (!hash_equals($signature,tickets_storage_signature($reordered,$secret))) throw new RuntimeException('Signature ordering failed.');
foreach ($fields as $name=>$value) {
    $changed=$fields;$changed[$name]=$value.'x';
    if (hash_equals($signature,tickets_storage_signature($changed,$secret))) throw new RuntimeException('Signature tamper check failed.');
}
echo "Attachment request signing checks passed.\n";
