<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/services/EveAuth.php';require_once dirname(__DIR__,2).'/app/services/CommunityWeb.php';
header('Cache-Control: private, no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('Use the supply form.');}
$user=eve_require_user();eve_require_csrf($_POST['csrf']??null);
try {
    $action=$_POST['action']??'submit';if(!in_array($action,['reserve','submit','cancel'],true))throw new InvalidArgumentException('Use a valid supply action.');
    community_network()->apply(array_replace($_POST,['action'=>$action]),(int)$user['character_id'],(string)$user['character_name'],false);
    $_SESSION['community_status']=match($action){'reserve'=>'Quantity reserved. Read your expiry time below.','cancel'=>'Reservation cancelled. Its quantity is available again.',default=>'Evidence submitted. An officer will review the delivery.'};
    header('Location: /operations/#record',true,303);
} catch(Throwable $e){http_response_code(400);echo eve_e(community_message($e)).' <a href="/operations/">Return to the board</a>';}
