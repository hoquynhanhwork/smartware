<?php
// app/Repositories/ProductRepository.php
namespace App\Repositories;

use PDO;
use App\Contracts\ProductRepositoryInterface;

class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    // ── Đọc danh sách có lọc + phân trang ────────────────────────────────────
    public function list(
        string $keyword     = '',
        array  $categoryIds = [],
        array  $statuses    = [],
        array  $supplierIds = [],
        float  $stockMin    = 0,
        float  $stockMax    = 999999999,
        int    $limit       = 15,
        int    $offset      = 0,
    ): array {
        [$where, $params] = $this->buildWhere(
            $keyword, $categoryIds, $statuses, $supplierIds,
            $stockMin, $stockMax,
        );

        $sql = "
            SELECT p.*, c.name AS category_name, s.name AS supplier_name,
                   {$this->stockSubquery()} AS total_stock
            FROM   products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN suppliers  s ON s.id = p.supplier_id
            WHERE  {$where}
            ORDER BY p.name ASC
            LIMIT  :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(
        string $keyword     = '',
        array  $categoryIds = [],
        array  $statuses    = [],
        array  $supplierIds = [],
        float  $stockMin    = 0,
        float  $stockMax    = 999999999,
    ): int {
        [$where, $params] = $this->buildWhere(
            $keyword, $categoryIds, $statuses, $supplierIds,
            $stockMin, $stockMax,
        );

        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM products p WHERE {$where}",
            $params,
        );
    }

    public function countAll(): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM products
            WHERE  deleted_at IS NULL
        ");
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM products
            WHERE  status = :status AND deleted_at IS NULL
        ", [':status' => $status]);
    }

    // ── Tìm 1 bản ghi ────────────────────────────────────────────────────────
    public function findById(int $id): array|false
    {
        return $this->fetchOne("
            SELECT * FROM products
            WHERE  id = :id AND deleted_at IS NULL
        ", [':id' => $id]);
    }

    public function findBySku(string $sku, int $excludeId = 0, bool $includeDeleted = false): array|false
    {
        $sql    = "SELECT id, deleted_at FROM products WHERE sku = :sku";
        $params = [':sku' => $sku];

        if (!$includeDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }

        if ($excludeId > 0) {
            $sql              .= " AND id != :exclude";
            $params[':exclude'] = $excludeId;
        }

        return $this->fetchOne($sql, $params);
    }

    // ── Tạo mới ───────────────────────────────────────────────────────────────
    public function create(array $data): int
    {
        return $this->insertReturningId("
            INSERT INTO products
                (category_id, supplier_id, sku, name, unit,
                 price, cost_price, description, status,
                 min_stock, max_stock)
            VALUES
                (:category_id, :supplier_id, :sku, :name, :unit,
                 :price, :cost_price, :description, :status,
                 :min_stock, :max_stock)
            RETURNING id
        ", [
            ':category_id' => $data['category_id'],
            ':supplier_id' => $data['supplier_id'],
            ':sku'         => $data['sku'],
            ':name'        => $data['name'],
            ':unit'        => $data['unit'],
            ':price'       => $data['price'],
            ':cost_price'  => $data['cost_price'],
            ':description' => $data['description'],
            ':status'      => $data['status'],
            ':min_stock'   => $data['min_stock']   ?? 0,
            ':max_stock'   => $data['max_stock']   ?? 0,
        ]);
    }

    // Khóa tư vấn (advisory lock) dùng riêng cho việc sinh SKU; số bất kỳ nhưng phải cố định.
    private const SKU_LOCK_KEY = 7305001;

    public function createWithAutoSku(array $data): array
    {
        $ownsTx = !$this->pdo->inTransaction();
        if ($ownsTx) {
            $this->pdo->beginTransaction();
        }

        try {
            // Các request sinh SKU song song xếp hàng ở đây; khóa tự nhả khi commit/rollback.
            $this->execute("SELECT pg_advisory_xact_lock(" . self::SKU_LOCK_KEY . ")");

            $data['sku'] = 'SP' . str_pad((string) ($this->maxSkuNumber() + 1), 5, '0', STR_PAD_LEFT);
            $id = $this->create($data);

            if ($ownsTx) {
                $this->pdo->commit();
            }
            return ['id' => $id, 'sku' => $data['sku']];

        } catch (\Throwable $e) {
            if ($ownsTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ── Cập nhật ──────────────────────────────────────────────────────────────
    public function update(int $id, array $data): bool
    {
        $stmt = $this->execute("
            UPDATE products
            SET    name        = :name,
                   sku         = :sku,
                   category_id = :category_id,
                   supplier_id = :supplier_id,
                   unit        = :unit,
                   price       = :price,
                   cost_price  = :cost_price,
                   description = :description,
                   status      = :status,
                   min_stock   = :min_stock,
                   max_stock   = :max_stock,
                   updated     = CURRENT_TIMESTAMP
            WHERE  id         = :id
              AND  deleted_at IS NULL
        ", [
            ':name'        => $data['name'],
            ':sku'         => $data['sku'],
            ':category_id' => $data['category_id'],
            ':supplier_id' => $data['supplier_id'],
            ':unit'        => $data['unit'],
            ':price'       => $data['price'],
            ':cost_price'  => $data['cost_price'],
            ':description' => $data['description'],
            ':status'      => $data['status'],
            ':min_stock'   => $data['min_stock']   ?? 0,
            ':max_stock'   => $data['max_stock']   ?? 0,
            ':id'          => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    // ── Soft-delete / đổi trạng thái ─────────────────────────────────────────
    public function setStatus(int $id, string $status): void
    {
        $this->execute("
            UPDATE products SET status = :status
            WHERE  id = :id
        ", [':status' => $status, ':id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->execute("
            UPDATE products SET deleted_at = CURRENT_TIMESTAMP
            WHERE  id = :id
        ", [':id' => $id]);
    }

    // ── AI helpers ────────────────────────────────────────────────────────────
    public function getSalesHistory(string $from, string $to): array
    {
        return $this->fetchAll("
            SELECT p.id, p.name, p.sku,
                   DATE_TRUNC('day', so.created) AS sale_date,
                   SUM(oi.quantity)               AS qty_sold
            FROM   stock_outbounds so
            JOIN   stock_outbound_items oi ON oi.outbound_id = so.id
            JOIN   products p              ON p.id           = oi.product_id
            WHERE  so.created   BETWEEN :from AND :to
              AND  p.deleted_at IS NULL
            GROUP BY p.id, p.name, p.sku, sale_date
            ORDER BY p.id, sale_date
        ", [':from' => $from, ':to' => $to]);
    }

    public function updateAiScores(int $id, string $direction, float $pctChange, float $volatility): void
    {
        $this->execute("
            INSERT INTO trends (product_id, direction, pct_change, created)
            VALUES (:pid, :direction, :pct_change, CURRENT_TIMESTAMP)
        ", [
            ':pid'        => $id,
            ':direction'  => $direction,
            ':pct_change' => $pctChange,
        ]);

        $riskLevel = match(true) {
            $volatility > 0.7 => 'high',
            $volatility > 0.4 => 'medium',
            default           => 'low',
        };

        $this->execute("
            INSERT INTO risk_analysis
                (product_id, risk_level, risk_score, description, created)
            VALUES
                (:pid, :risk_level, :risk_score, 'AI auto-scored', CURRENT_TIMESTAMP)
        ", [
            ':pid'        => $id,
            ':risk_level' => $riskLevel,
            ':risk_score' => round($volatility * 100, 2),
        ]);
    }

    // ── Misc ──────────────────────────────────────────────────────────────────
    /**
     * Số SKU kế tiếp. Lưu ý: chỉ an toàn khi đồng thời nếu dùng trong createWithAutoSku();
     * tự gọi hàm này rồi create() riêng vẫn có thể trùng khi 2 người thêm cùng lúc.
     */
    public function nextSkuNumber(): int
    {
        return $this->maxSkuNumber() + 1;
    }

    /** Lớn nhất trong các SKU dạng SP + 1–9 chữ số. Tính cả sản phẩm đã xóa mềm để không tái dùng SKU cũ. */
    private function maxSkuNumber(): int
    {
        return (int) $this->fetchColumn("
            SELECT COALESCE(MAX(CAST(SUBSTRING(sku FROM 3) AS BIGINT)), 0)
            FROM   products
            WHERE  sku ~ '^SP[0-9]{1,9}$'
        ");
    }

    /** Đã phát sinh nhập/xuất, có lô tồn, hoặc có lịch sử kho → không được xóa. */
    public function hasStockHistory(int $id): bool
    {
        return (bool) $this->fetchColumn("
            SELECT EXISTS (SELECT 1 FROM stock_inbound_items  WHERE product_id = :id_in)
                OR EXISTS (SELECT 1 FROM stock_outbound_items WHERE product_id = :id_out)
                OR EXISTS (SELECT 1 FROM inventory_batches    WHERE product_id = :id_batch)
                OR EXISTS (SELECT 1 FROM inventory_history    WHERE product_id = :id_hist)
        ", [
            ':id_in'    => $id,
            ':id_out'   => $id,
            ':id_batch' => $id,
            ':id_hist'  => $id,
        ]);
    }

    // ── Helpers nội bộ ───────────────────────────────────────────────────────
    private function buildWhere(
        string $keyword,
        array  $categoryIds,
        array  $statuses,
        array  $supplierIds,
        float  $stockMin = 0,
        float  $stockMax = 999999999,
    ): array {
        $stockSub = $this->stockSubquery();

        $cond   = [
            "p.deleted_at IS NULL",
            "({$stockSub}) >= :stock_min",
            "({$stockSub}) <= :stock_max",
        ];
        $params = [
            ':stock_min' => $stockMin,
            ':stock_max' => $stockMax,
        ];

        if ($keyword !== '') {
            $cond[]             = "(p.name ILIKE :keyword OR p.sku ILIKE :keyword)";
            $params[':keyword'] = '%' . $keyword . '%';
        }

        foreach ([
            [$categoryIds, 'cat', 'p.category_id'],
            [$statuses,    'st',  'p.status'],
            [$supplierIds, 'sup', 'p.supplier_id'],
        ] as [$arr, $prefix, $col]) {
            if (empty($arr)) continue;
            $ph = [];
            foreach ($arr as $i => $val) {
                $key          = ":{$prefix}_{$i}";
                $ph[]         = $key;
                $params[$key] = $val;
            }
            $cond[] = "{$col} IN (" . implode(',', $ph) . ")";
        }

        return [implode(' AND ', $cond), $params];
    }

    private function stockSubquery(): string
    {
        return "(SELECT COALESCE(SUM(ib.quantity), 0) FROM inventory_batches ib WHERE ib.product_id = p.id)";
    }
}