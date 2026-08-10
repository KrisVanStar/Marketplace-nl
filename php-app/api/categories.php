<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/discovery.php';

$cats = array_keys(categories());
sort($cats);
json_response($cats);
