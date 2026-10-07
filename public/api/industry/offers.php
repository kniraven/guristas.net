<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
require_once dirname(__DIR__, 3) . '/app/services/PublicIndustry.php';
try { echo json_encode(['ok'=>true,'data'=>(new GuristasPublicIndustry())->offers()], JSON_THROW_ON_ERROR); }
catch (Throwable $error) { http_response_code(503); echo json_encode(['ok'=>false,'message'=>'Offer relay unavailable. The dated reference remains available.']); }
