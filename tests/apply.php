<?php

require dirname(__DIR__) . '/Services/AdminBridgePatcher.php';

use Plugin\SmartExpiry\Services\AdminBridgePatcher;

$root = $argv[1] ?? dirname(__DIR__, 3);
echo (new AdminBridgePatcher($root))->apply(), PHP_EOL;
