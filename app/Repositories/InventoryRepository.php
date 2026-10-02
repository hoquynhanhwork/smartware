<?php
// app/Repositories/InventoryRepository.php

namespace App\Repositories;

use App\Contracts\InventoryRepositoryInterface;
use PDO;

class InventoryRepository extends BaseRepository implements InventoryRepositoryInterface
{
    // ── Lô hàng ───────────────────────────────────────────────────────────
    public function getBatches(
        string $keyword     = '',
        int    $category_id = 0,
        string $expiry      = '',
        int    $limit       = 25,
        int    $offset      = 0
    ): array {
        [$where, $params] = $this->buildBatchWhere($keyword, $category_id, $expiry);

        $stmt = $this->pdo->prepare("
            SELECT ib.id, ib.batch_no, ib.quantity, ib.exp_date,
                   (ib.exp_date - public.app_today()) AS days_to_exp,
                   ib.updated AS created_at,
                   p.id AS product_id, p.name AS product_name,
                   p.sku, p.unit, p.min_stock,
                   c.name AS category_name
            FROM inventory_batches ib
            JOIN products p        ON p.id = ib.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE $where AND ib.quantity > 0 AND p.deleted_at IS NULL
            ORDER BY
                CASE
                    WHEN ib.exp_date < public.app_today()              THEN 0
                    WHEN (ib.exp_date - public.app_today()) <= 7       THEN 1
                    WHEN (ib.exp_date - public.app_today()) <= 30      THEN 2
                    ELSE 3
                END, ib.exp_date ASC, p.name ASC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countBatches(
        string $keyword     = '',
        int    $category_id = 0,
        string $expiry      = ''
    ): int {
        [$where, $params] = $this->buildBatchWhere($keyword, $category_id, $expiry);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM inventory_batches ib
             JOIN products p ON p.id = ib.product_id
             WHERE $where AND ib.quantity > 0 AND p.deleted_at IS NULL",
            $params
        );
    }

    public function getBatchKpi(): array
    {
        return $this->fetchOne("
            SELECT
                COUNT(*)                                                                                       AS total_batches,
                COALESCE(SUM(ib.quantity), 0)                                                                  AS total_qty,
                SUM(CASE WHEN ib.exp_date < public.app_today()                                     THEN 1 ELSE 0 END) AS cnt_expired,
                SUM(CASE WHEN ib.exp_date >= public.app_today() AND (ib.exp_date-public.app_today()) <= 7 THEN 1 ELSE 0 END) AS cnt_critical,
                SUM(CASE WHEN (ib.exp_date-public.app_today()) BETWEEN 8 AND 30                    THEN 1 ELSE 0 END) AS cnt_warning,
                COUNT(DISTINCT ib.product_id)                                                                  AS total_products
            FROM inventory_batches ib
            JOIN products p ON p.id = ib.product_id
            WHERE ib.quantity > 0 AND p.deleted_at IS NULL
        ", []) ?: [];
    }

    public function getBatchesForProduct(int $product_id): array
    {
        // Không filter quantity > 0 ở đây vì modal điều chỉnh cần thấy cả lô đã về 0
        // Guard chống bypass nhập kho được xử lý ở Service (adjustStock)
        return $this->fetchAll("
            SELECT ib.*, (ib.exp_date - public.app_today()) AS days_to_exp
            FROM inventory_batches ib
            WHERE ib.product_id = :pid
            ORDER BY ib.exp_date ASC NULLS LAST
        ", [':pid' => $product_id]);
    }

    // ── Lịch sử xuất nhập ────────────────────────────────────────────────
    public function getHistory(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildHistoryWhere($filters);

        $stmt = $this->pdo->prepare("
            SELECT ih.id, ih.type, ih.batch_no, ih.quantity,
                   ih.reference_no, ih.reference_id, ih.created,
                   p.name AS product_name, p.unit, p.sku,
                   c.name AS category_name,
                   u.full_name AS user_name
            FROM inventory_history ih
            JOIN products p        ON p.id = ih.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN users u      ON u.id = ih.user_id
            WHERE $where
            ORDER BY ih.created DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v)
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getHistoryKpi(array $filters): array
    {
        [$where, $params] = $this->buildHistoryWhere($filters);
        return $this->fetchOne("
            SELECT
                COUNT(*)                                                                                        AS total_rows,
                COALESCE(SUM(CASE WHEN ih.type = 'in'
                                   AND (ih.reference_no IS NULL OR ih.reference_no NOT LIKE 'ADJ-%')
                                  THEN ih.quantity END), 0)                                                     AS total_in,
                COALESCE(SUM(CASE WHEN ih.type = 'out'
                                   AND (ih.reference_no IS NULL OR ih.reference_no NOT LIKE 'ADJ-%')
                                  THEN ih.quantity END), 0)                                                     AS total_out,
                COALESCE(SUM(CASE WHEN ih.type = 'adjustment'
                                    OR ih.reference_no LIKE 'ADJ-%'
                                  THEN ABS(ih.quantity) END), 0)                                                AS total_adjustment,
                COUNT(DISTINCT ih.product_id)                                                                   AS total_products
            FROM inventory_history ih
            WHERE $where
        ", $params) ?: [];
    }

    // ── Điều chỉnh tồn kho ───────────────────────────────────────────────
    public function findBatch(int $product_id, string $batch_no): array|false
    {
        return $this->fetchOne("
            SELECT id, quantity FROM inventory_batches
            WHERE product_id = :pid AND batch_no = :batch_no
            FOR UPDATE
        ", [':pid' => $product_id, ':batch_no' => $batch_no]);
    }

    public function updateBatchQuantity(int $id, int $quantity): void
    {
        $this->execute("
            UPDATE inventory_batches
            SET quantity = :qty, updated = CURRENT_TIMESTAMP
            WHERE id = :id
        ", [':qty' => $quantity, ':id' => $id]);
    }

    public function writeAdjustHistory(
        int    $product_id,
        string $batch_no,
        int    $quantity,   // có dấu: dương = tăng, âm = giảm
        string $ref,
        int    $user_id
    ): void {
        $this->execute("
            INSERT INTO inventory_history
                (product_id, batch_no, type, quantity, reference_no, user_id)
            VALUES
                (:pid, :batch_no, 'adjustment', :qty, :ref, :user_id)
        ", [
            ':pid'      => $product_id,
            ':batch_no' => $batch_no,
            ':qty'      => $quantity,
            ':ref'      => $ref,
            ':user_id'  => $user_id,
        ]);
    }

    // ── Misc ─────────────────────────────────────────────────────────────
    public function searchProducts(string $term): array
    {
        return $this->fetchAll("
            SELECT id, name, sku, unit FROM products
            WHERE deleted_at IS NULL
              AND (name ILIKE :term OR sku ILIKE :term)
            LIMIT 10
        ", [':term' => '%' . $term . '%']);
    }

    public function getActiveUsers(): array
    {
        return $this->fetchAll("
            SELECT id, full_name FROM users
            WHERE status = 'active'
            ORDER BY full_name
        ", []);
    }

    public function getCategories(): array
    {
        return $this->fetchAll("
            SELECT id, name FROM categories
            WHERE deleted_at IS NULL
            ORDER BY name
        ", []);
    }

    // ── Helpers build WHERE ───────────────────────────────────────────────
    private function buildBatchWhere(
        string $keyword, int $category_id, string $expiry
    ): array {
        $where  = "1=1";
        $params = [];
        if ($keyword !== '') {
            $where .= " AND (p.name ILIKE :kw OR p.sku ILIKE :kw OR ib.batch_no ILIKE :kw)";
            $params[':kw'] = "%$keyword%";
        }
        if ($category_id > 0) {
            $where .= " AND p.category_id = :cat";
            $params[':cat'] = $category_id;
        }
        switch ($expiry) {
            case 'expired':  $where .= " AND ib.exp_date < public.app_today()"; break;
            case 'critical': $where .= " AND ib.exp_date >= public.app_today() AND (ib.exp_date - public.app_today()) <= 7"; break;
            case 'warning':  $where .= " AND (ib.exp_date - public.app_today()) BETWEEN 8 AND 30"; break;
            case 'ok':       $where .= " AND (ib.exp_date IS NULL OR (ib.exp_date - public.app_today()) > 30)"; break;
        }
        return [$where, $params];
    }

    private function buildHistoryWhere(array $f): array
    {
        $where  = "1=1";
        $params = [];

        $type = $f['type'] ?? '';
        if ($type === 'adjustment') {
            // bắt cả data cũ (ADJ- prefix) lẫn data mới (type='adjustment')
            $where .= " AND (ih.type = 'adjustment' OR ih.reference_no LIKE 'ADJ-%')";
        } elseif (in_array($type, ['in', 'out'])) {
            // loại trừ ADJ- khỏi nhập/xuất thật
            $where .= " AND ih.type = :type AND (ih.reference_no IS NULL OR ih.reference_no NOT LIKE 'ADJ-%')";
            $params[':type'] = $type;
        }
        // type='' → tất cả, không filter

        if (!empty($f['from'])) {
            $where .= " AND ih.created::date >= :from";
            $params[':from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where .= " AND ih.created::date <= :to";
            $params[':to'] = $f['to'];
        }
        if (!empty($f['product_id'])) {
            $where .= " AND ih.product_id = :pid";
            $params[':pid'] = (int) $f['product_id'];
        }
        if (!empty($f['batch_no'])) {
            $where .= " AND ih.batch_no ILIKE :batch_no";
            $params[':batch_no'] = '%' . $f['batch_no'] . '%';
        }
        if (!empty($f['user_id'])) {
            $where .= " AND ih.user_id = :uid";
            $params[':uid'] = (int) $f['user_id'];
        }
        return [$where, $params];
    }
}