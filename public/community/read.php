<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/services/CommunityNetwork.php';require_once dirname(__DIR__,2).'/app/services/EveAuth.php';
header('Cache-Control: private, no-store');
$id=is_string($_GET['id']??null)?$_GET['id']:'';
try{$ledger=community_network()->read();}catch(Throwable $e){http_response_code(503);exit('The archive is unavailable.');}
$entry=$ledger['entries'][$id]??null;
if(!$entry || !in_array($entry['type'],['art','comic'],true)){http_response_code(404);exit('Release not found.');}
$private=!CommunityNetwork::visible($entry);
if($private){require_once dirname(__DIR__,2).'/app/services/TicketService.php';$user=eve_current_user();if(($_GET['preview']??'')!=='1' || !$user || (($entry['author']??0)!==(int)$user['character_id'] && !tickets_staff((int)$user['character_id']))){http_response_code(404);exit('Release not found.');}}
$images=$entry['images']??[];$number=$_GET['page']??'1';
if(!is_string($number) || !preg_match('/^[0-9]{1,2}$/D',$number) || (int)$number<1 || (int)$number>max(1,count($images))){http_response_code(404);exit('Page not found.');}
$number=(int)$number;$image=$images[$number-1]??null;
$pageTitle=$entry['title'];$pageDescription=$entry['type']==='comic'?'A Guristas community comic episode.':'Art from the Guristas community.';$navActive='community';
require dirname(__DIR__,2).'/app/views/partials/public-tool-header.php';
$base='/community/read.php?id='.$entry['id'].($private?'&preview=1':'');
?>
<p><a href="/community/#<?= escape(($entry['category']??'')==='propaganda'?'propaganda':$entry['type']) ?>">Back to <?= $entry['type']==='comic'?'comics':'art' ?></a></p>
<?php if($private): ?><p role="status">Private preview · <?= escape($entry['status']) ?> · <?= escape($entry['review_note']??'') ?></p><?php endif; ?>
<?php if(($entry['category']??'')==='propaganda'): ?><p class="eyebrow">FAN PROPAGANDA // ROLEPLAY, NOT OFFICIAL EVE LORE</p><?php endif; ?>
<section class="tool-card"><p class="eyebrow">CREATOR // <?= escape($entry['credit']) ?></p><p class="community-prose"><?= escape($entry['body']) ?></p>
<?php if($image): ?><nav class="field-tabs" aria-label="Comic page navigation"><?php if($number>1): ?><a rel="prev" href="<?= escape($base.'&page='.($number-1)) ?>">Previous page</a><?php endif; ?><span>Page <?= $number ?> of <?= count($images) ?></span><?php if($number<count($images)): ?><a rel="next" href="<?= escape($base.'&page='.($number+1)) ?>">Next page</a><?php endif; ?></nav>
<figure class="community-reader"><img src="/community/media.php?id=<?= escape($image['id']) ?>" width="<?= (int)$image['width'] ?>" height="<?= (int)$image['height'] ?>" alt="<?= escape($entry['title'].' · page '.$number.'. Full description follows the image.') ?>"><figcaption><h2><?= $entry['type']==='comic'?'Page transcript':'Image description' ?></h2><p class="community-prose"><?= escape($image['alt']) ?></p></figcaption></figure>
<?php if($number<count($images)): ?><a class="primary-button" href="<?= escape($base.'&page='.($number+1)) ?>">Next page</a><?php endif; ?>
<p><a href="/community/media.php?id=<?= escape($image['id']) ?>&amp;download=1">Download this image</a>. Downloading does not grant permission to reuse it.</p>
<details><summary>Jump to a page</summary><nav class="field-tabs" aria-label="All episode pages"><?php foreach($images as $i=>$page): ?><a href="<?= escape($base.'&page='.($i+1)) ?>" <?= $number===$i+1?'aria-current="page"':'' ?>>Page <?= $i+1 ?></a><?php endforeach; ?></nav></details>
<?php elseif($entry['url']): ?><a class="secondary-button" href="<?= escape($entry['url']) ?>" target="_blank" rel="noopener noreferrer">Open the approved external release</a><?php endif; ?>
</section>
<?php require dirname(__DIR__,2).'/app/views/partials/public-tool-footer.php'; ?>
