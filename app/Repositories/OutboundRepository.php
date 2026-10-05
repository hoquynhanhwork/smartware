<?php
// app/Repositories/OutboundRepository.php

namespace App\Repositories;

use App\Contracts\OutboundRepositoryInterface;
use PDO;

class OutboundRepository extends BaseRepository implements OutboundRepositoryInterface
{
    // ── Danh sách có lọc + phân trang ─────────────────────────────────────
    public function list(array $filters = [], int $limit = 15, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->pdo->prepare("
            SELECT so.*, u.full_name AS user_name, s.name AS supplier_name
            FROM stock_outbounds so
            LEFT JOIN users u     ON u.id = so.user_id
            LEFT JOIN suppliers s ON s.id = so.supplier_id
            WHERE $where
            ORDER BY so.created DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM stock_outbounds so WHERE $where",
            $params
        );
    }

    // ── Chi tiết phiếu ─────────────────────────────────────────────────────
    public function findById(int $id): array|false
    {
        return $this->fetchOne("
            SELECT so.*, u.full_name AS user_name, s.name AS supplier_name
            FROM stock_outbounds so
            LEFT JOIN users u     ON u.id = so.user_id
            LEFT JOIN suppliers s ON s.id = so.supplier_id
            WHERE so.id = :id AND so.deleted_at IS NULL
        ", [':id' => $id]);
    }

    public function getItems(int $outbound_id): array
    {
        return $this->fetchAll("
            SELECT soi.product_id, soi.batch_no, soi.quantity,
                   p.name AS product_name, p.unit
            FROM stock_outbound_items soi
            JOIN products p ON p.id = soi.product_id
            WHERE soi.outbound_id = :outbound_id
            ORDER BY soi.id ASC
        ", [':outbound_id' => $outbound_id]);
    }

    // ── Tạo phiếu ─────────────────────────────────────────────────────────
    public function create(array $data): int
    {
        $stmt = $this->execute("
            INSERT INTO stock_outbounds
                (supplier_id, user_id, ref_no, note, status)
            VALUES
                (:supplier_id, :user_id, :ref_no, :note, :status)
            RETURNING id
        ", [
            ':supplier_id' => $data['supplier_id'],
            ':user_id' => $data['user_id'],
            ':ref_no'  => $data['ref_no'],
            ':note'    => $data['note'] ?? '',
            ':status'  => $data['status'],
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function createItem(int $outbound_id, array $item): void
    {
        $this->execute("
            INSERT INTO stock_outbound_items
                (outbound_id, product_id, batch_no, quantity)
            VALUES
                (:outbound_id, :product_id, :batch_no, :quantity)
        ", [
            ':outbound_id' => $outbound_id,
            ':product_id'  => $item['product_id'],
            ':batch_no'    => $item['batch_no'],
            ':quantity'    => $item['quantity'],
        ]);
    }

    // ── Cập nhật ──────────────────────────────────────────────────────────
    public function update(int $id, array $data): bool
    {
        $stmt = $this->execute("
            UPDATE stock_outbounds
            SET supplier_id = :supplier_id, ref_no = :ref_no, note = :note, status = :status,
                updated = CURRENT_TIMESTAMP
            WHERE id = :id AND deleted_at IS NULL
        ", [
            ':supplier_id' => $data['supplier_id'],
            ':ref_no' => $data['ref_no'],
            ':note'   => $data['note'] ?? '',
            ':status' => $data['status'],
            ':id'     => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function deleteItems(int $outbound_id): void
    {
        $this->execute(
            "DELETE FROM stock_outbound_items WHERE outbound_id = :id",
            [':id' => $outbound_id]
        );
    }

    public function softDelete(int $id): void
    {
        $this->execute(
            "UPDATE stock_outbounds SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id",
            [':id' => $id]
        );
    }

    // ── Sinh mã phiếu tự động ─────────────────────────────────────────────
    // Lock cố định (không còn company_id để phân biệt lock theo tenant)
    private const REF_NO_LOCK_KEY = 851203;

    public function generateRefNo(): string
    {
        $this->pdo->exec("SELECT pg_advisory_lock(" . self::REF_NO_LOCK_KEY . ")");
        try {
            $row = $this->fetchOne("
                SELECT MAX(CAST(REGEXP_REPLACE(ref_no, '[^0-9]', '', 'g') AS INTEGER)) AS max_num
                FROM stock_outbounds WHERE ref_no LIKE 'PX%'
            ", []);
            $next = ((int) ($row['max_num'] ?? 0)) + 1;
            return 'PX' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        } finally {
            $this->pdo->exec("SELECT pg_advisory_unlock(" . self::REF_NO_LOCK_KEY . ")");
        }
    }

    // ── Lô hàng theo sản phẩm (FEFO) ──────────────────────────────────────
    public function getBatchesForProduct(int $product_id): array
    {
        return $this->fetchAll("
            SELECT batch_no, quantity, exp_date,
                   (exp_date - public.app_today()) AS days_to_exp
            FROM inventory_batches
            WHERE product_id = :pid AND quantity > 0
            ORDER BY exp_date ASC
        ", [':pid' => $product_id]);
    }

    // ── Tìm sản phẩm autocomplete ──────────────────────────────────────────
    // Phiếu xuất thuộc đúng 1 NCC nên chỉ gợi ý sản phẩm của NCC đó.
    public function searchProducts(string $term, int $supplier_id = 0): array
    {
        $params = [':term' => '%' . $term . '%'];
        $supplierCond = '';
        if ($supplier_id > 0) {
            $supplierCond = 'AND p.supplier_id = :sid';
            $params[':sid'] = $supplier_id;
        }
        return $this->fetchAll("
            SELECT p.id, p.name, p.sku, p.unit, p.price,
                   COALESCE(SUM(ib.quantity), 0) AS current_stock
            FROM products p
            LEFT JOIN inventory_batches ib ON ib.product_id = p.id
            WHERE p.deleted_at IS NULL AND p.status = 'active'
              AND (p.name ILIKE :term OR p.sku ILIKE :term)
              $supplierCond
            GROUP BY p.id, p.name, p.sku, p.unit, p.price
            LIMIT 10
        ", $params);
    }

    public function supplierExists(int $supplier_id): bool
    {
        return (bool) $this->fetchOne(
            "SELECT id FROM suppliers WHERE id = :id AND deleted_at IS NULL",
            [':id' => $supplier_id]
        );
    }

    /**
     * NCC của từng sản phẩm: [product_id => supplier_id|null].
     * Sản phẩm không tồn tại thì không có key.
     *
     * @param int[] $product_ids
     * @return array<int,int|null>
     */
    public function getProductSuppliers(array $product_ids): array
    {
        $product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
        if (!$product_ids) return [];

        $ph = []; $params = [];
        foreach ($product_ids as $i => $id) {
            $ph[] = ":p$i"; $params[":p$i"] = $id;
        }
        $rows = $this->fetchAll("
            SELECT id, name, supplier_id FROM products WHERE id IN (" . implode(',', $ph) . ")
        ", $params);

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = [
                'supplier_id' => $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null,
                'name'        => $r['name'],
            ];
        }
        return $map;
    }

    // ── Kiểm tra tồn kho đủ trước khi xuất ───────────────────────────────
    public function getAvailableStock(int $product_id, string $batch_no): int
    {
        $result = $this->fetchColumn("
            SELECT COALESCE(quantity, 0)
            FROM inventory_batches
            WHERE product_id = :pid AND batch_no = :batch_no
        ", [
            ':pid'      => $product_id,
            ':batch_no' => $batch_no,
        ]);
        return (int) ($result ?: 0);
    }

    // ── Inventory: trừ tồn kho ─────────────────────────────────────────────
    public function reduceInventoryBatch(
        int $product_id, string $batch_no, int $quantity
    ): void {
        $affected = $this->execute("
            UPDATE inventory_batches
            SET quantity = quantity - :qty, updated = CURRENT_TIMESTAMP
            WHERE product_id = :pid AND batch_no = :batch_no
              AND quantity >= :qty
        ", [
            ':qty'      => $quantity,
            ':pid'      => $product_id,
            ':batch_no' => $batch_no,
        ])->rowCount();

        if ($affected === 0) {
            throw new \RuntimeException(
                "Không đủ tồn kho: product_id=$product_id, batch=$batch_no, cần=$quantity"
            );
        }
    }

    // ── Inventory: hoàn lại tồn kho khi xóa phiếu ─────────────────────────
    public function restoreInventoryBatch(
        int $product_id, string $batch_no, int $quantity
    ): void {
        $this->execute("
            UPDATE inventory_batches
            SET quantity = quantity + :qty, updated = CURRENT_TIMESTAMP
            WHERE product_id = :pid AND batch_no = :batch_no
        ", [
            ':qty'      => $quantity,
            ':pid'      => $product_id,
            ':batch_no' => $batch_no,
        ]);
    }

    // ── Inventory history ──────────────────────────────────────────────────
    public function writeInventoryHistory(
        int    $product_id, string $batch_no, int $quantity,
        string $reference_no, int $reference_id,
        int    $user_id, string $type = 'out'
    ): void {
        $this->execute("
            INSERT INTO inventory_history
                (product_id, batch_no, type, quantity,
                 reference_no, reference_id, user_id, reference_type)
            VALUES
                (:pid, :batch_no, :type, :qty, :ref_no, :ref_id, :user_id, 'outbound')
        ", [
            ':pid'     => $product_id,
            ':batch_no'=> $batch_no,
            ':type'    => $type,
            ':qty'     => $quantity,
            ':ref_no'  => $reference_no,
            ':ref_id'  => $reference_id,
            ':user_id' => $user_id,
        ]);
    }

    public function deleteInventoryHistory(
        int $product_id, string $batch_no, int $reference_id
    ): void {
        $this->execute("
            DELETE FROM inventory_history
            WHERE product_id = :pid
              AND batch_no = :batch_no AND reference_id = :ref_id AND type = 'out'
        ", [
            ':pid'      => $product_id,
            ':batch_no' => $batch_no,
            ':ref_id'   => $reference_id,
        ]);
    }

    // ── Helper: build WHERE ────────────────────────────────────────────────
    private function buildWhere(array $filters): array
    {
        $cond   = ["so.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['keyword'])) {
            $cond[]            = "so.ref_no ILIKE :keyword";
            $params[':keyword'] = '%' . $filters['keyword'] . '%';
        }

        if (!empty($filters['statuses'])) {
            $ph = [];
            foreach ($filters['statuses'] as $i => $st) {
                $key = ":st_$i"; $ph[] = $key; $params[$key] = $st;
            }
            $cond[] = "so.status IN (" . implode(',', $ph) . ")";
        }

        if (!empty($filters['from'])) {
            $cond[]              = "DATE(so.created) >= :from_date";
            $params[':from_date'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $cond[]            = "DATE(so.created) <= :to_date";
            $params[':to_date'] = $filters['to'];
        }

        return [implode(' AND ', $cond), $params];
    }
}