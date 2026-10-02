<?php
// pages/outbound/get_batches.php

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repositories\OutboundRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CategoryRepository;
use App\Services\OutboundService;

requireLogin();
header('Content-Type: application/json');

$service = new OutboundService(
    new OutboundRepository($pdo),
    new ProductRepository($pdo),
    new CategoryRepository($pdo),
    $pdo
);

echo json_encode(
    $service->getBatchesForProduct(
        (int) ($_GET['product_id'] ?? 0)
    )
);