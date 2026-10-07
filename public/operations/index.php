<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/services/CommunityNetwork.php';
$pageTitle='Operations Board'; $pageDescription='Find a fleet, deliver a posted supply order, or read a report from the field.'; $navActive='operations';
$types=['fleet','supply','report'];
require dirname(__DIR__,2).'/app/views/partials/community-board.php';
