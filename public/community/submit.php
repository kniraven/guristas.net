<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/services/TicketService.php';
require_once dirname(__DIR__,2).'/app/services/CommunityWeb.php';
header('Cache-Control: private, no-store');community_post_limit();
$user=eve_require_user();$staff=tickets_staff((int)$user['character_id']);$message='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    eve_require_csrf($_POST['csrf']??null);
    try {community_receive($user,$staff);header('Location: /community/submissions.php',true,303);exit;}catch(Throwable $e){$message=community_message($e);}
}
try{$ledger=community_network()->read();}catch(Throwable $e){http_response_code(503);exit('The submission desk is unavailable. Try again later.');}
$uploadLimits=community_upload_limits();
$pageTitle='Send a Pirate Transmission';$pageDescription='Share your art or comic with the network. An officer checks every submission before publication.';$navActive='community';
$pageAccess='PIRATE TRANSMISSIONS // PRIVATE SUBMISSION';
require dirname(__DIR__,2).'/app/views/partials/public-tool-header.php';
?>
<p><a href="/community/">Public archive</a> · <a href="/community/submissions.php">Your submissions</a><?php if($staff): ?> · <a href="/admin/community/">Publishing desk</a><?php endif; ?></p>
<?php if($message): ?><p role="alert"><?= escape($message) ?> Select the images again before retrying.</p><?php endif; ?>
<section class="tool-card"><h2>Prepare your release</h2><p>PNG, JPEG or WebP. On this server, keep each image under <?= number_format($uploadLimits['file']/1048576, 2) ?> MiB and all selected images under <?= number_format($uploadLimits['total']/1048576, 2) ?> MiB. These limits leave room for your descriptions. Art uses one image. Comics support up to ten pages per episode, in upload-slot order.</p><p>Creator credit, title, description and approved images become public. Your submission stays private until staff approval. Remove personal image metadata before uploading. Only share work you own or have permission to publish.</p>
<form method="post" enctype="multipart/form-data" data-community-upload data-file-limit="<?= $uploadLimits['file'] ?>" data-total-limit="<?= $uploadLimits['total'] ?>"><input type="hidden" name="csrf" value="<?= escape(eve_csrf()) ?>"><input type="hidden" name="revision" value="<?= (int)$ledger['revision'] ?>">
<label>Release type<select name="type"><option value="art" <?= ($_POST['type']??'art')==='art'?'selected':'' ?>>Art</option><option value="comic" <?= ($_POST['type']??'')==='comic'?'selected':'' ?>>Comic episode</option></select></label>
<label data-art-category>Art category<select name="category"><option value="fan-art">Fan art</option><option value="propaganda" <?= ($_POST['category']??'')==='propaganda'?'selected':'' ?>>Fan propaganda</option></select></label>
<label>Title<input name="title" required maxlength="160" value="<?= escape(is_string($_POST['title']??null)?$_POST['title']:'') ?>"></label><label>Public creator credit<input name="credit" required maxlength="300" value="<?= escape(is_string($_POST['credit']??null)?$_POST['credit']:'') ?>"></label><label>Public description<textarea name="body" required maxlength="6000"><?= escape(is_string($_POST['body']??null)?$_POST['body']:'') ?></textarea></label>
<?php for($i=0;$i<10;++$i): ?><fieldset <?= $i>0?'data-comic-page':'' ?>><legend><?= $i===0?'Artwork or comic page 1':'Optional comic page '.($i+1) ?></legend><label>Image<input type="file" name="images[<?= $i ?>]" accept="image/png,image/jpeg,image/webp" <?= $i===0?'required':'' ?>></label><label>Image description or comic transcript<textarea name="alternatives[<?= $i ?>]" maxlength="3000" <?= $i===0?'required':'' ?> placeholder="Describe the image. For comics, include the dialogue and important action."><?= escape(is_string($_POST['alternatives'][$i]??null)?$_POST['alternatives'][$i]:'') ?></textarea></label></fieldset><?php endfor; ?>
<p class="upload-summary" role="status" data-upload-summary>Select your images to check the upload size.</p><label><input type="checkbox" name="rights" value="yes" required> I own this work or have permission to publish it with the creator credit provided.</label><button class="primary-button">Send for review</button></form></section>
<script src="/assets/js/community-forms.js?v=<?= filemtime(dirname(__DIR__).'/assets/js/community-forms.js') ?>" defer></script>
<?php require dirname(__DIR__,2).'/app/views/partials/public-tool-footer.php'; ?>
