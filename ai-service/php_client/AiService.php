<?php

/**
 * AiService.php
 */
class AiService {

    private string $baseUrl;
    private string $apiKey;

    public function __construct() {
        // Ưu tiên: biến môi trường → fallback localhost (dev)
        $this->baseUrl = rtrim(
            getenv('AI_SERVICE_URL') ?: 'http://localhost:8000/api/v1',
            '/'
        );
        $this->apiKey = getenv('AI_API_KEY') ?: 'smartware';
    }

    /**
     * Gọi POST tới FastAPI.
     * ensure_ascii=false tương đương: json_encode với JSON_UNESCAPED_UNICODE.
     *
     * @return array  Kết quả decode hoặc ['error' => '...']
     */
    private function call(string $endpoint, array $data, int $timeout = 120): array {
        $url  = $this->baseUrl . $endpoint;
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'X-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => 'cURL: ' . $error];
        }
        if ($httpCode === 400) {
            $detail = json_decode($response, true);
            return ['error' => $detail['detail'] ?? 'Bad Request (400)'];
        }
        if ($httpCode !== 200) {
            $detail = json_decode($response, true);
            return ['error' => "HTTP $httpCode", 'detail' => $detail];
        }

        $result = json_decode($response, true);
        return $result ?? ['error' => 'Invalid JSON from AI service'];
    }
    public function predictInventory(
        int     $productId,
        string  $productName,
        int     $currentStock,
        int     $minStock,
        array   $salesHistory,
        int     $supplierId        = 1,
        int     $maxStock          = 0,
        float   $avgDailyOut       = 0.0,
        ?float  $estimatedDays     = null,
        ?string $nearestExpDate    = null,
    ): array {
        return $this->call('/predict', [
            'product_id'          => $productId,
            'product_name'        => $productName,
            'current_stock'       => $currentStock,
            'min_stock'           => $minStock,
            'max_stock'           => $maxStock,
            'sales_history'       => $salesHistory,
            'avg_daily_out'       => $avgDailyOut,
            'estimated_days_left' => $estimatedDays,
            'nearest_exp_date'    => $nearestExpDate,
            'supplier_id'         => $supplierId,
        ]);
    }

    public function forecastInventory(
        int    $productId,
        string $productName,
        int    $currentStock,
        int    $minStock,
        array  $salesHistory,
        int    $supplierId     = 1,
        int    $maxStock       = 0,
        float  $avgDailyOut    = 0.0,
        ?float $estimatedDays  = null,
    ): array {
        return $this->call('/forecast', [
            'product_id'          => $productId,
            'product_name'        => $productName,
            'current_stock'       => $currentStock,
            'min_stock'           => $minStock,
            'max_stock'           => $maxStock,
            'sales_history'       => $salesHistory,
            'avg_daily_out'       => $avgDailyOut,
            'estimated_days_left' => $estimatedDays,
            'supplier_id'         => $supplierId,
        ]);
    }

    public function forecastAdvanced(
        int    $productId,
        string $productName,
        int    $currentStock,
        int    $minStock,
        int    $supplierId = 1,
        int    $maxStock   = 0,
    ): array {
        return $this->call('/forecast/advanced', [
            'product_id'    => $productId,
            'product_name'  => $productName,
            'current_stock' => $currentStock,
            'min_stock'     => $minStock,
            'max_stock'     => $maxStock,
            'supplier_id'   => $supplierId,
        ], timeout: 180);
    }

    public function analyzeRisk(
        int     $productId,
        string  $productName,
        int     $currentStock,
        int     $minStock,
        int     $supplierId          = 1,
        int     $maxStock            = 0,
        float   $avgDailyOut         = 0.0,
        ?float  $estimatedDays       = null,
        ?int    $daysToNearestExp    = null,
        ?string $nearestExpDate      = null,
        array   $salesHistory        = [],
    ): array {
        return $this->call('/risks', [
            'product_id'          => $productId,
            'product_name'        => $productName,
            'current_stock'       => $currentStock,
            'min_stock'           => $minStock,
            'max_stock'           => $maxStock,
            'avg_daily_out'       => $avgDailyOut,
            'estimated_days_left' => $estimatedDays,
            'days_to_nearest_exp' => $daysToNearestExp,
            'nearest_exp_date'    => $nearestExpDate,
            'sales_history'       => $salesHistory,
            'supplier_id'         => $supplierId,
        ]);
    }

    public function analyzeTrend(
        int    $productId,
        string $productName,
        int    $supplierId = 1,
    ): array {
        return $this->call('/trends', [
            'product_id'   => $productId,
            'product_name' => $productName,
            'supplier_id'  => $supplierId,
        ]);
    }

    public function getReplenishment(
        int     $productId,
        string  $productName,
        int     $currentStock,
        int     $minStock,
        int     $supplierId              = 1,
        int     $maxStock                = 0,
        float   $avgDailyOut             = 0.0,
        ?float  $estimatedDays           = null,
        ?string $nearestExpDate          = null,
        ?float  $costPrice               = null,
        ?int    $preferredSupplierId     = null,
    ): array {
        return $this->call('/replenishment', [
            'product_id'            => $productId,
            'product_name'          => $productName,
            'current_stock'         => $currentStock,
            'min_stock'             => $minStock,
            'max_stock'             => $maxStock,
            'avg_daily_out'         => $avgDailyOut,
            'estimated_days_left'   => $estimatedDays,
            'nearest_exp_date'      => $nearestExpDate,
            'cost_price'            => $costPrice,
            'preferred_supplier_id' => $preferredSupplierId,
            'supplier_id'           => $supplierId,
        ]);
    }

    public function batchAnalyze(
        int     $supplierId  = 1,
        int     $limit       = 100,
        ?string $stockStatus = null, // lọc: LOW_STOCK, NEAR_EXPIRY, OUT_OF_STOCK, OVERSTOCK
    ): array {
        return $this->call('/analyze/batch', array_filter([
            'supplier_id'  => $supplierId,
            'limit'        => $limit,
            'stock_status' => $stockStatus,
        ], fn($v) => $v !== null), timeout: 180);
    }

    public function assessProduct(
        int $productId,
        int $supplierId = 1,
    ): array {
        return $this->call('/assess', [
            'product_id'  => $productId,
            'supplier_id' => $supplierId,
        ]);
    }

    public function batchAssess(
        int $supplierId = 1,
        int $limit      = 50,
    ): array {
        return $this->call('/assess/batch', [
            'supplier_id' => $supplierId,
            'limit'       => $limit,
        ]);
    }

    public function generateAlerts(): array {
        return $this->call('/alerts/generate', []);
    }

    /**
     * @param string      $message    Câu hỏi của người dùng
     * @param int         $supplierId
     * @param string|null $context    JSON string từ vw_stock_summary_for_ai (tùy chọn)
     */
    public function chat(
        string  $message,
        int     $supplierId = 1,
        ?string $context    = null,
    ): array {
        return $this->call('/chat', [
            'message'     => $message,
            'supplier_id' => $supplierId,
            'context'     => $context,
        ], timeout: 90);
    }
    public function queryDatabase(string $question, int $supplierId = 1): array {
        return $this->call('/query', [
            'question' => $question,
            'supplier_id' => $supplierId,
        ], timeout: 60);
    }

    /**
     * Query PostgreSQL lấy lịch sử xuất kho 6 tháng (mảng 6 phần tử, cũ → mới).
     * Dùng function fn_sales_history_6m(p_product_id, p_supplier_id) đã tạo trong schema.sql.
     *
     * @param PDO $pdo
     * @param int $productId
     * @param int $supplierId
     * @return int[]  Mảng 6 phần tử, phần tử 0 = 5 tháng trước, phần tử 5 = tháng này
     */
    public static function getSalesHistory(PDO $pdo, int $productId, int $supplierId): array {
        $stmt = $pdo->prepare("SELECT fn_sales_history_6m(:pid, :supplier_id)");
        $stmt->execute([':pid' => $productId, ':supplier_id' => $supplierId]);
        $raw = $stmt->fetchColumn(); // "{10,20,15,30,25,18}" dạng PG array string

        if (!$raw) return array_fill(0, 6, 0);

        // Parse PostgreSQL array string → PHP array
        $raw = trim($raw, '{}');
        if ($raw === '') return array_fill(0, 6, 0);
        return array_map('intval', explode(',', $raw));
    }

    public function healthCheck(): bool {
        $url = str_replace('/api/v1', '/health', $this->baseUrl);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

}