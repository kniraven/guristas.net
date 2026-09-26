<?php

// Guristas.net stream overlay configuration - PHP 8.0 compatible

declare(strict_types=1);

$config = [
    // For production remote administration, set an environment variable or
    // create config/stream.local.php (ignored by the existing .gitignore).
    'admin_key' => (string) (getenv('GURISTAS_STREAM_ADMIN_KEY') ?: ''),
    'allow_local_without_key' => true,
];

$localFile = __DIR__ . '/stream.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $config = array_replace($config, $local);
    }
}

return $config;
