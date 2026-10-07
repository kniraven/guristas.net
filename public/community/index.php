<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/services/CommunityNetwork.php';
$pageTitle='Pirate Transmissions'; $pageDescription='Art, comics and recorded broadcasts from the Guristas community. Open to every capsuleer.'; $navActive='community';
$types=['art','propaganda','comic','broadcast'];
require dirname(__DIR__,2).'/app/views/partials/community-board.php';
