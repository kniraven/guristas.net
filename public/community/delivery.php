<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/services/EveAuth.php';
require_once dirname(__DIR__,2).'/app/services/CommunityNetwork.php';
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); header('Allow: POST'); exit('Use the delivery form.'); }
$user=eve_require_user(); eve_require_csrf($_POST['csrf']??null);
try { community_network()->apply($_POST+['action'=>'submit'],(int)$user['character_id'],(string)$user['character_name'],false); header('Location: /operations/#supply',true,303); }
catch(Throwable $e) { http_response_code(400); echo eve_e($e instanceof InvalidArgumentException || $e->getMessage()==='The board changed. Reload before saving.' ? $e->getMessage() : 'Unable to save. Reload the board and try again.'); }
