<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Bước 1: PHP chạy được<br>";

require_once 'C:/xampp/htdocs/smartware/ai-service/php_client/AiService.php';

echo "Bước 2: Load AiService thành công<br>";

$ai = new AiService();

echo "Bước 3: Khởi tạo AiService thành công<br>";

$result = $ai->predictInventory(
    productId: 1,
    productName: 'Laptop Dell XPS',
    currentStock: 5,
    minStock: 10,
    salesHistory: [20, 25, 18, 30, 22, 28]
);

echo "Bước 4: Gọi API xong<br>";

echo '<pre>';
print_r($result);
echo '</pre>';