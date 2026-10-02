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

    public function findBySku(string $sku, int $excludeId = 0): array|false
    {
        $sql    = "SELECT id FROM products WHERE sku = :sku AND deleted_at IS NULL";
        $params = [':sku' => $sku];

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
    public function nextSkuNumber(): int
    {
        $this->pdo->beginTransaction();

        try {
            $max = $this->fetchColumn("
                SELECT COALESCE(
                    MAX(CAST(REGEXP_REPLACE(sku, '[^0-9]', '', 'g') AS INTEGER)),
                    0
                )
                FROM   products
                WHERE  sku LIKE 'SP%'
                AND    deleted_at IS NULL
            ");

            $this->pdo->commit();
            return ((int) $max) + 1;

        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function hasStockHistory(int $id): bool
    {
        $inbound  = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM stock_inbound_items  WHERE product_id = :id LIMIT 1",
            [':id' => $id]
        );
        $outbound = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM stock_outbound_items WHERE product_id = :id LIMIT 1",
            [':id' => $id]
        );
        return $inbound > 0 || $outbound > 0;
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