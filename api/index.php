<?php

declare(strict_types=1);

$tmpDirectories = [
    '/tmp/views',
    '/tmp/storage/app',
    '/tmp/storage/framework/cache/data',
    '/tmp/storage/framework/sessions',
    '/tmp/storage/framework/views',
    '/tmp/storage/logs',
];

foreach ($tmpDirectories as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

require __DIR__.'/../public/index.php';
