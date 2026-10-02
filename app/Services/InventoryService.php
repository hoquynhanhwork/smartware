<?php
// app/Services/InventoryService.php

namespace App\Services;

use App\Contracts\InventoryRepositoryInterface;
use PDO;

class InventoryService
{
    public function __construct(
        private readonly InventoryRepositoryInterface $repo,
        private readonly PDO                          $pdo,
    ) {}

    // ── Lô hàng ───────────────────────────────────────────────────────────
    public function getBatchPage(array $filters): array
    {
        $keyword     = trim($filters['keyword']       ?? '');
        $category_id = (int) ($filters['category_id'] ?? 0);
        $expiry      = trim($filters['expiry_status'] ?? '');
        $page        = max(1, (int) ($filters['page']  ?? 1));
        $limit       = 25;
        $offset      = ($page - 1) * $limit;

        $total = $this->repo->countBatches($keyword, $category_id, $expiry);
        $items = $this->repo->getBatches($keyword, $category_id, $expiry, $limit, $offset);
        $kpi   = $this->repo->getBatchKpi();

        return [
            'items'       => $items,
            'kpi'         => $kpi,
            'total'       => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
            'page'        => $page,
            'limit'       => $limit,
        ];
    }

    public function getBatchesForProduct(int $product_id): array
    {
        if ($product_id <= 0) return ['success' => false, 'batches' => []];
        $batches = $this->repo->getBatchesForProduct($product_id);
        return ['success' => true, 'batches' => $batches];
    }

    // ── Lịch sử xuất nhập ────────────────────────────────────────────────
    public function getHistoryPage(array $filters): array
    {
        $page   = max(1, (int) ($filters['page']  ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $kpi   = $this->repo->getHistoryKpi($filters);
        $total = (int) ($kpi['total_rows'] ?? 0);
        $items = $this->repo->getHistory($filters, $limit, $offset);

        return [
            'items'       => $items,
            'kpi'         => $kpi,
            'total'       => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
            'page'        => $page,
            'limit'       => $limit,
        ];
    }

    // ── Điều chỉnh tồn kho ───────────────────────────────────────────────
    public function adjustStock(
        int    $user_id,
        int    $product_id,
        string $batch_no,
        int    $actual_qty,
        string $reason
    ): array {
        if ($product_id <= 0 || $batch_no === '' || $actual_qty < 0 || $reason === '') {
            return ['success' => false, 'message' => 'Dữ liệu không hợp lệ.'];
        }

        try {
            $this->pdo->beginTransaction();

            $batch = $this->repo->findBatch($product_id, $batch_no);
            if (!$batch) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Không tìm thấy lô hàng.'];
            }

            $diff = $actual_qty - (int) $batch['quantity'];
            if ($diff === 0) {
                $this->pdo->rollBack();
                return ['success' => true, 'message' => 'Không có thay đổi.'];
            }

            // Chặn tăng tồn từ 0 qua điều chỉnh — phải dùng phiếu nhập
            if ((int) $batch['quantity'] === 0 && $diff > 0) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Lô này đã hết hàng (tồn = 0). Vui lòng tạo phiếu nhập kho thay vì điều chỉnh.'];
            }

            $this->repo->updateBatchQuantity($batch['id'], $actual_qty);

            $ref = 'ADJ-' . date('Ymd') . '-' . strtoupper(substr($batch_no, 0, 6)) . '-' . substr(uniqid(), -5);

            // diff có dấu: dương = tăng tồn, âm = giảm tồn
            $this->repo->writeAdjustHistory(
                $product_id, $batch_no,
                $diff, $ref, $user_id
            );

            $this->pdo->commit();
            return [
                'success' => true,
                'message' => 'Điều chỉnh thành công. Chênh lệch: ' . ($diff > 0 ? '+' : '') . $diff,
                'diff'    => $diff,
                'new_qty' => $actual_qty,
            ];

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log('[ADJUST] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Lỗi hệ thống, vui lòng thử lại.'];
        }
    }

    // ── AJAX helpers ─────────────────────────────────────────────────────
    public function searchProducts(string $term): array
    {
        return $this->repo->searchProducts(trim($term));
    }

    public function getActiveUsers(): array
    {
        return $this->repo->getActiveUsers();
    }

    public function getCategories(): array
    {
        return $this->repo->getCategories();
    }
}