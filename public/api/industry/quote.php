<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
require_once dirname(__DIR__, 3) . '/app/services/PublicIndustry.php';
try {
    $hub = $_GET['hub'] ?? null; $type = $_GET['type'] ?? null; $quantity = $_GET['quantity'] ?? '1';
    if (!is_string($hub) || !is_string($type) || !ctype_digit($type) || !is_string($quantity) || !ctype_digit($quantity)) throw new InvalidArgumentException('Choose a supported item, hub and positive quantity.');
    $result = (new GuristasPublicIndustry())->quote($hub, (int)$type, (int)$quantity, ($_GET['history'] ?? '') === '1');
    echo json_encode(['ok'=>true,'data'=>$result], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>$error->getMessage()]); }
catch (Throwable $error) { http_response_code(503); echo json_encode(['ok'=>false,'message'=>'Market relay unavailable. Retry later or check the market in EVE.']); }
