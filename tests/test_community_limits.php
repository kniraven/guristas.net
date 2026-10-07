<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/CommunityWeb.php';
foreach (['8M'=>8388608,'2k'=>2048,'1G'=>1073741824,'123'=>123,'0'=>0] as $value=>$expected) {
    if(community_ini_bytes((string)$value)!==$expected)throw new RuntimeException('Bad server limit conversion.');
}
$limits=community_upload_limits();
$post=community_ini_bytes((string)ini_get('post_max_size'));
if($limits['file']>$limits['total'] || $limits['file']>CommunityImages::MAX_FILE || $limits['total']>CommunityImages::MAX_TOTAL || ($post>0&&$limits['total']>$post-131072))throw new RuntimeException('Upload form overstates server capacity.');
echo "Server upload limits and multipart allowance passed.\n";
