<?php
// app/Repositories/InboundRepository.php

namespace App\Repositories;

use App\Contracts\InboundRepositoryInterface;
use PDO;

class InboundRepository extends BaseRepository implements InboundRepositoryInterface
{
    public const HISTORY_TYPE = 'purchase_in';

    // Khóa cố định dùng cho pg_advisory_lock khi sinh ref_no (hệ thống single-tenant,
    // không có company_id để phân biệt khóa theo từng công ty).
    private const REF_NO_LOCK_KEY = 778899;

    public function list(array $filters = [], int $limit = 15, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->pdo->prepare("
            SELECT si.*, s.name AS supplier_name, u.full_name AS user_name
            FROM stock_inbounds si
            LEFT JOIN suppliers s ON s.id = si.supplier_id
            LEFT JOIN users u     ON u.id = si.user_id
            WHERE $where
            ORDER BY si.created DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $val) $stmt->bindValue($key, $val);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM stock_inbounds si WHERE $where",
            $params
        );
    }

    public function findById(int $id): array|false
    {
        return $this->fetchOne("
            SELECT si.*, s.name AS supplier_name, u.full_name AS user_name
            FROM stock_inbounds si
            LEFT JOIN suppliers s ON s.id = si.supplier_id
            LEFT JOIN users u     ON u.id = si.user_id
            WHERE si.id = :id AND si.deleted_at IS NULL
        ", [':id' => $id]);
    }

    public function getItems(int $inbound_id): array
    {
        return $this->fetchAll("
            SELECT ii.product_id, ii.batch_no, ii.mfg_date, ii.exp_date,
                   ii.quantity, ii.unit_price, ii.total,
                   p.name AS product_name, p.unit, c.name AS category_name
            FROM stock_inbound_items ii
            JOIN products p        ON p.id = ii.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE ii.inbound_id = :inbound_id
            ORDER BY ii.id ASC
        ", [':inbound_id' => $inbound_id]);
    }

    public function create(array $data): int
    {
        $stmt = $this->execute("
            INSERT INTO stock_inbounds
                (supplier_id, user_id, ref_no, total_amount, note, status)
            VALUES
                (:supplier_id, :user_id, :ref_no, :total_amount, :note, :status)
            RETURNING id
        ", [
            ':supplier_id'  => $data['supplier_id'],
            ':user_id'      => $data['user_id'],
            ':ref_no'       => $data['ref_no'],
            ':total_amount' => $data['total_amount'],
            ':note'         => $data['note'],
            ':status'       => $data['status'],
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function createItem(int $inbound_id, array $item): int
    {
        $stmt = $this->execute("
            INSERT INTO stock_inbound_items
                (inbound_id, product_id, batch_no, mfg_date, exp_date, quantity, unit_price, total)
            VALUES
                (:inbound_id, :product_id, :batch_no, :mfg_date, :exp_date, :quantity, :unit_price, :total)
            RETURNING id
        ", [
            ':inbound_id' => $inbound_id,
            ':product_id' => $item['product_id'],
            ':batch_no'   => $item['batch_no'],
            ':mfg_date'   => $item['mfg_date'],
            ':exp_date'   => $item['exp_date'],
            ':quantity'   => $item['quantity'],
            ':unit_price' => $item['unit_price'],
            ':total'      => $item['total'],
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, array $data): void
    {
        $this->execute("
            UPDATE stock_inbounds
            SET supplier_id = :supplier_id, ref_no = :ref_no, note = :note,
                total_amount = :total_amount, status = :status,
                updated = CURRENT_TIMESTAMP
            WHERE id = :id
        ", [
            ':supplier_id'  => $data['supplier_id'],
            ':ref_no'       => $data['ref_no'],
            ':note'         => $data['note'],
            ':total_amount' => $data['total_amount'],
            ':status'       => $data['status'],
            ':id'           => $id,
        ]);
    }

    public function deleteItems(int $inbound_id): void
    {
        $this->execute(
            "DELETE FROM stock_inbound_items WHERE inbound_id = :id",
            [':id' => $inbound_id]
        );
    }

    public function softDelete(int $id): void
    {
        $this->execute(
            "UPDATE stock_inbounds SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id",
            [':id' => $id]
        );
    }

    public function generateRefNo(): string
    {
        $this->pdo->exec('SELECT pg_advisory_lock(' . self::REF_NO_LOCK_KEY . ')');
        try {
            $row = $this->fetchOne("
                SELECT MAX(CAST(REGEXP_REPLACE(ref_no, '[^0-9]', '', 'g') AS INTEGER)) AS max_num
                FROM stock_inbounds
                WHERE ref_no LIKE 'PN%'
            ");
            $next = (((int) ($row['max_num'] ?? 0)) + 1);
            return 'PN' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        } finally {
            $this->pdo->exec('SELECT pg_advisory_unlock(' . self::REF_NO_LOCK_KEY . ')');
        }
    }

    public function refNoExists(string $ref_no): bool
    {
        return (bool) $this->fetchOne(
            "SELECT id FROM stock_inbounds WHERE ref_no = :ref_no",
            [':ref_no' => $ref_no]
        );
    }

    public function upsertInventoryBatch(
        int    $product_id,
        int    $supplier_id,
        string $batch_no,
        string $exp_date,
        int    $quantity,
        float  $unit_price,
        int    $inbound_item_id
    ): void {
        // Constraint thật trong schema: inventory_batches_product_id_batch_no_key UNIQUE (product_id, batch_no)
        $this->execute("
            INSERT INTO inventory_batches
                (product_id, supplier_id, batch_no, exp_date,
                 quantity, cost_price, inbound_item_id)
            VALUES
                (:product_id, :supplier_id, :batch_no, :exp_date,
                 :quantity, :cost_price, :inbound_item_id)
            ON CONFLICT (product_id, batch_no)
            DO UPDATE SET
                quantity        = inventory_batches.quantity + EXCLUDED.quantity,
                cost_price      = EXCLUDED.cost_price,
                inbound_item_id = EXCLUDED.inbound_item_id,
                updated         = CURRENT_TIMESTAMP
        ", [
            ':product_id'      => $product_id,
            ':supplier_id'     => $supplier_id,
            ':batch_no'        => $batch_no,
            ':exp_date'        => $exp_date,
            ':quantity'        => $quantity,
            ':cost_price'      => $unit_price,
            ':inbound_item_id' => $inbound_item_id,
        ]);
    }

    public function reverseInventoryBatch(
        int    $product_id,
        string $batch_no,
        string $exp_date,
        int    $quantity
    ): void {
        $row = $this->fetchOne("
            SELECT id, quantity FROM inventory_batches
            WHERE product_id = :pid
              AND batch_no = :batch_no AND exp_date = :exp_date
            FOR UPDATE
        ", [
            ':pid'      => $product_id,
            ':batch_no' => $batch_no,
            ':exp_date' => $exp_date,
        ]);

        if (!$row) {
            throw new \RuntimeException(
                "Không tìm thấy lô hàng batch_no={$batch_no} để reverse."
            );
        }

        if ($row['quantity'] < $quantity) {
            throw new \RuntimeException(
                "Không thể reverse: tồn kho lô {$batch_no} ({$row['quantity']}) < số lượng cần trừ ({$quantity})."
            );
        }

        $this->execute("
            UPDATE inventory_batches
            SET quantity = quantity - :qty, updated = CURRENT_TIMESTAMP
            WHERE id = :id
        ", [':qty' => $quantity, ':id' => $row['id']]);
    }

    public function writeInventoryHistory(
        int    $product_id,
        string $batch_no,
        int    $quantity,
        string $reference_no,
        int    $reference_id,
        int    $user_id,
        string $type = self::HISTORY_TYPE
    ): void {
        $quantity_after = (int) $this->fetchColumn("
            SELECT COALESCE(quantity, 0) FROM inventory_batches
            WHERE product_id = :pid AND batch_no = :batch_no
            LIMIT 1
        ", [':pid' => $product_id, ':batch_no' => $batch_no]);

        $this->execute("
            INSERT INTO inventory_history
                (product_id, batch_no, type, quantity,
                 reference_no, reference_id, user_id,
                 reference_type, quantity_after)
            VALUES
                (:product_id, :batch_no, :type, :quantity,
                 :reference_no, :reference_id, :user_id,
                 'inbound', :quantity_after)
        ", [
            ':product_id'     => $product_id,
            ':batch_no'       => $batch_no,
            ':type'           => $type,
            ':quantity'       => $quantity,
            ':reference_no'   => $reference_no,
            ':reference_id'   => $reference_id,
            ':user_id'        => $user_id,
            ':quantity_after' => $quantity_after,
        ]);
    }

    public function deleteInventoryHistory(
        int    $product_id,
        string $batch_no,
        int    $reference_id
    ): void {
        $this->execute("
            DELETE FROM inventory_history
            WHERE product_id = :pid
              AND batch_no = :batch_no AND reference_id = :ref_id
              AND type = :type AND reference_type = 'inbound'
        ", [
            ':pid'      => $product_id,
            ':batch_no' => $batch_no,
            ':ref_id'   => $reference_id,
            ':type'     => self::HISTORY_TYPE,
        ]);
    }

    public function searchProducts(string $term): array
    {
        return $this->fetchAll("
            SELECT p.id, p.name, p.unit, p.price, p.cost_price,
                   COALESCE(SUM(ib.quantity), 0) AS current_stock,
                   p.min_stock, p.max_stock
            FROM products p
            LEFT JOIN inventory_batches ib
                ON ib.product_id = p.id
            WHERE p.deleted_at IS NULL AND p.status = 'active'
              AND (p.name ILIKE :term OR p.sku ILIKE :term)
            GROUP BY p.id, p.name, p.unit, p.price, p.cost_price, p.min_stock, p.max_stock
            LIMIT 10
        ", [':term' => '%' . $term . '%']);
    }

    private function buildWhere(array $filters): array
    {
        $cond   = ["si.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['supplier_ids'])) {
            $ph = [];
            foreach ($filters['supplier_ids'] as $i => $id) {
                $key = ":sup_$i"; $ph[] = $key; $params[$key] = $id;
            }
            $cond[] = "si.supplier_id IN (" . implode(',', $ph) . ")";
        }

        if (!empty($filters['statuses'])) {
            $ph = [];
            foreach ($filters['statuses'] as $i => $st) {
                $key = ":st_$i"; $ph[] = $key; $params[$key] = $st;
            }
            $cond[] = "si.status IN (" . implode(',', $ph) . ")";
        }

        if (!empty($filters['from_date'])) {
            $cond[]               = "DATE(si.created) >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $cond[]             = "DATE(si.created) <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }

        return [implode(' AND ', $cond), $params];
    }
}