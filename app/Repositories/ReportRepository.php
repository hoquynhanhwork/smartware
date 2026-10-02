<?php
// app/Repositories/ReportRepository.php

namespace App\Repositories;

use App\Contracts\ReportRepositoryInterface;
use PDO;

class ReportRepository extends BaseRepository implements ReportRepositoryInterface
{
    // =========================================================================
    // OVERVIEW REPORT
    // =========================================================================

    /**
     * Tổng nhập/xuất/doanh thu theo từng tháng trong khoảng thời gian.
     * Dùng cho biểu đồ đường xu hướng.
     */
    public function getMonthlyFlow(string $from, string $to): array
    {
        return $this->fetchAll("
            SELECT
                TO_CHAR(DATE_TRUNC('month', d.month), 'MM/YYYY') AS label,
                DATE_TRUNC('month', d.month)                      AS month_date,
                COALESCE(SUM(si.total_amount), 0)                 AS total_inbound,
                COALESCE(SUM(so.total_amount), 0)                 AS total_outbound
            FROM generate_series(
                DATE_TRUNC('month', :from::date),
                DATE_TRUNC('month', :to::date),
                '1 month'::interval
            ) AS d(month)
            LEFT JOIN stock_inbounds si
                ON DATE_TRUNC('month', si.created) = d.month
                AND si.status = 'completed' AND si.deleted_at IS NULL
            LEFT JOIN stock_outbounds so
                ON DATE_TRUNC('month', so.created) = d.month
                AND so.status = 'completed'
                AND so.outbound_type = 'sale' AND so.deleted_at IS NULL
            GROUP BY d.month
            ORDER BY d.month ASC
        ", [':from' => $from, ':to' => $to]);
    }

    /**
     * KPI tổng hợp trong kỳ: tổng nhập, tổng xuất, số phiếu, số NCC.
     */
    public function getOverviewKpi(string $from, string $to): array
    {
        $inbound = $this->fetchOne("
            SELECT
                COUNT(*)                    AS total_orders,
                COALESCE(SUM(total_amount), 0) AS total_amount,
                COUNT(DISTINCT supplier_id) AS total_suppliers
            FROM stock_inbounds
            WHERE status = 'completed'
              AND deleted_at IS NULL
              AND DATE(created) BETWEEN :from AND :to
        ", [':from' => $from, ':to' => $to]);

        $outbound = $this->fetchOne("
            SELECT
                COUNT(*)                    AS total_orders,
                COALESCE(SUM(total_amount), 0) AS total_amount
            FROM stock_outbounds
            WHERE status = 'completed'
              AND outbound_type = 'sale' AND deleted_at IS NULL
              AND DATE(created) BETWEEN :from AND :to
        ", [':from' => $from, ':to' => $to]);

        $inventory = $this->fetchOne("
            SELECT COALESCE(SUM(ib.quantity * ib.cost_price), 0) AS inventory_value
            FROM inventory_batches ib
            JOIN products p ON p.id = ib.product_id
            WHERE ib.quantity > 0 AND p.deleted_at IS NULL
        ", []);

        return [
            'inbound_amount'    => (float) ($inbound['total_amount']   ?? 0),
            'inbound_orders'    => (int)   ($inbound['total_orders']   ?? 0),
            'inbound_suppliers' => (int)   ($inbound['total_suppliers'] ?? 0),
            'outbound_amount'   => (float) ($outbound['total_amount']  ?? 0),
            'outbound_orders'   => (int)   ($outbound['total_orders']  ?? 0),
            'inventory_value'   => (float) ($inventory['inventory_value'] ?? 0),
        ];
    }

    /**
     * So sánh KPI kỳ hiện tại vs kỳ trước (cùng độ dài).
     */
    public function getOverviewKpiPrev(string $from, string $to): array
    {
        // Tính kỳ trước có cùng số ngày
        $days    = (strtotime($to) - strtotime($from)) / 86400;
        $prevTo  = date('Y-m-d', strtotime($from) - 86400);
        $prevFrom = date('Y-m-d', strtotime($prevTo) - $days * 86400);
        return $this->getOverviewKpi($prevFrom, $prevTo);
    }

    /**
     * Top 5 sản phẩm xuất nhiều nhất trong kỳ (theo doanh thu).
     */
    public function getTopProducts(string $from, string $to, int $limit = 5): array
    {
        return $this->fetchAll("
            SELECT
                p.name AS product_name, p.sku, p.unit,
                SUM(soi.quantity)                           AS total_qty,
                SUM(soi.total)                              AS total_revenue,
                COUNT(DISTINCT so.id)                       AS order_count
            FROM stock_outbound_items soi
            JOIN stock_outbounds so ON so.id = soi.outbound_id
            JOIN products p         ON p.id  = soi.product_id
            WHERE so.status = 'completed'
              AND so.outbound_type = 'sale'
              AND so.deleted_at IS NULL
              AND DATE(so.created) BETWEEN :from AND :to
            GROUP BY p.id, p.name, p.sku, p.unit
            ORDER BY total_revenue DESC
            LIMIT :lim
        ", [':from' => $from, ':to' => $to, ':lim' => $limit]);
    }

    /**
     * Top 5 nhà cung cấp theo giá trị nhập trong kỳ.
     */
    public function getTopSuppliers(string $from, string $to, int $limit = 5): array
    {
        return $this->fetchAll("
            SELECT
                s.name AS supplier_name,
                COUNT(si.id)           AS order_count,
                SUM(si.total_amount)   AS total_amount
            FROM stock_inbounds si
            JOIN suppliers s ON s.id = si.supplier_id
            WHERE si.status = 'completed'
              AND si.deleted_at IS NULL
              AND DATE(si.created) BETWEEN :from AND :to
            GROUP BY s.id, s.name
            ORDER BY total_amount DESC
            LIMIT :lim
        ", [':from' => $from, ':to' => $to, ':lim' => $limit]);
    }

    // =========================================================================
    // INVENTORY REPORT
    // =========================================================================

    /**
     * Phân bố tồn kho theo trạng thái — dùng cho biểu đồ tròn.
     */
    public function getStockDistribution(): array
    {
        return $this->fetchAll("
            SELECT stock_status, COUNT(*) AS cnt,
                   COALESCE(SUM(current_stock * cost_price), 0) AS value
            FROM vw_stock_summary_for_ai
            GROUP BY stock_status
            ORDER BY cnt DESC
        ", []);
    }

    /**
     * Giá trị tồn kho theo danh mục.
     */
    public function getInventoryValueByCategory(): array
    {
        return $this->fetchAll("
            SELECT
                COALESCE(c.name, 'Chưa phân loại') AS category_name,
                SUM(ib.quantity)                    AS total_qty,
                SUM(ib.quantity * ib.cost_price)    AS total_value
            FROM inventory_batches ib
            JOIN products p        ON p.id = ib.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE ib.quantity > 0 AND p.deleted_at IS NULL
            GROUP BY c.name
            ORDER BY total_value DESC
            LIMIT 10
        ", []);
    }

    /**
     * Sản phẩm sắp cạn hàng — dựa trên estimated_days_left từ view.
     */
    public function getLowStockRisk(int $limit = 10): array
    {
        return $this->fetchAll("
            SELECT product_name, sku, unit, current_stock, min_stock,
                   avg_daily_out, estimated_days_left, stock_status,
                   nearest_exp_date, out_qty_30d
            FROM vw_stock_summary_for_ai
            WHERE stock_status IN ('OUT_OF_STOCK', 'LOW_STOCK')
            ORDER BY
                CASE stock_status WHEN 'OUT_OF_STOCK' THEN 0 ELSE 1 END,
                estimated_days_left ASC NULLS LAST
            LIMIT :lim
        ", [':lim' => $limit]);
    }

    /**
     * Lô hàng hết hạn trong N ngày tới.
     */
    public function getNearExpiryBatches(int $days = 30, int $limit = 10): array
    {
        return $this->fetchAll("
            SELECT p.name AS product_name, p.sku, p.unit,
                   ib.batch_no, ib.quantity, ib.exp_date,
                   (ib.exp_date - public.app_today()) AS days_left,
                   ib.quantity * ib.cost_price   AS at_risk_value
            FROM inventory_batches ib
            JOIN products p ON p.id = ib.product_id
            WHERE ib.quantity > 0
              AND ib.exp_date BETWEEN public.app_today() AND (public.app_today() + :days * INTERVAL '1 day')
              AND p.deleted_at IS NULL
            ORDER BY ib.exp_date ASC
            LIMIT :lim
        ", [':days' => $days, ':lim' => $limit]);
    }

    // =========================================================================
    // PRODUCT REPORT
    // =========================================================================

    /**
     * Hiệu suất sản phẩm trong kỳ: doanh số, tần suất, tốc độ bán.
     */
    public function getProductPerformance(
        string $from,
        string $to,
        string $sort   = 'revenue', // revenue | qty | orders
        int    $limit  = 20,
        int    $offset = 0
    ): array {
        $orderBy = match($sort) {
            'qty'    => 'total_qty DESC',
            'orders' => 'order_count DESC',
            default  => 'total_revenue DESC',
        };

        $stmt = $this->pdo->prepare("
            SELECT
                p.id AS product_id, p.name AS product_name, p.sku, p.unit,
                c.name AS category_name,
                SUM(soi.quantity)  AS total_qty,
                SUM(soi.total)     AS total_revenue,
                COUNT(DISTINCT so.id) AS order_count,
                AVG(soi.unit_price)   AS avg_price,
                v.current_stock, v.avg_daily_out, v.estimated_days_left,
                v.stock_status
            FROM stock_outbound_items soi
            JOIN stock_outbounds so ON so.id = soi.outbound_id
            JOIN products p         ON p.id  = soi.product_id
            LEFT JOIN categories c  ON c.id  = p.category_id
            LEFT JOIN vw_stock_summary_for_ai v
                ON v.product_id = p.id
            WHERE so.status = 'completed'
              AND so.outbound_type = 'sale'
              AND so.deleted_at IS NULL
              AND DATE(so.created) BETWEEN :from AND :to
            GROUP BY p.id, p.name, p.sku, p.unit, c.name,
                     v.current_stock, v.avg_daily_out, v.estimated_days_left, v.stock_status
            ORDER BY $orderBy
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to',   $to);
        $stmt->bindValue(':lim',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off',  $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countProductPerformance(string $from, string $to): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(DISTINCT soi.product_id)
            FROM stock_outbound_items soi
            JOIN stock_outbounds so ON so.id = soi.outbound_id
            WHERE so.status = 'completed'
              AND so.outbound_type = 'sale' AND so.deleted_at IS NULL
              AND DATE(so.created) BETWEEN :from AND :to
        ", [':from' => $from, ':to' => $to]);
    }

    /**
     * Doanh số theo danh mục trong kỳ — dùng cho biểu đồ tròn/cột.
     */
    public function getRevenueByCategory(string $from, string $to): array
    {
        return $this->fetchAll("
            SELECT
                COALESCE(c.name, 'Chưa phân loại') AS category_name,
                SUM(soi.quantity) AS total_qty,
                SUM(soi.total)    AS total_revenue
            FROM stock_outbound_items soi
            JOIN stock_outbounds so ON so.id = soi.outbound_id
            JOIN products p         ON p.id  = soi.product_id
            LEFT JOIN categories c  ON c.id  = p.category_id
            WHERE so.status = 'completed'
              AND so.outbound_type = 'sale'
              AND so.deleted_at IS NULL
              AND DATE(so.created) BETWEEN :from AND :to
            GROUP BY c.name
            ORDER BY total_revenue DESC
            LIMIT 10
        ", [':from' => $from, ':to' => $to]);
    }

    // =========================================================================
    // SUPPLIER REPORT
    // =========================================================================

    /**
     * Hiệu suất nhà cung cấp trong kỳ.
     */
    public function getSupplierPerformance(
        string $from,
        string $to,
        int    $limit  = 20,
        int    $offset = 0
    ): array {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id AS supplier_id, s.name AS supplier_name,
                s.phone, s.email, s.status,
                COUNT(DISTINCT si.id)       AS order_count,
                SUM(si.total_amount)        AS total_amount,
                AVG(si.total_amount)        AS avg_order_value,
                COUNT(DISTINCT sii.product_id) AS product_count
            FROM suppliers s
            JOIN stock_inbounds si      ON si.supplier_id = s.id
            JOIN stock_inbound_items sii ON sii.inbound_id = si.id
            WHERE si.status = 'completed'
              AND si.deleted_at IS NULL
              AND DATE(si.created) BETWEEN :from AND :to
            GROUP BY s.id, s.name, s.phone, s.email, s.status
            ORDER BY total_amount DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to',   $to);
        $stmt->bindValue(':lim',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off',  $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Nhập hàng theo tháng từng NCC — cho biểu đồ cột chồng.
     */
    public function getMonthlyInboundBySupplier(
        string $from,
        string $to,
        int    $top = 5
    ): array {
        // Lấy top N NCC trước
        $topSuppliers = $this->fetchAll("
            SELECT s.id, s.name
            FROM stock_inbounds si
            JOIN suppliers s ON s.id = si.supplier_id
            WHERE si.status = 'completed'
              AND si.deleted_at IS NULL
              AND DATE(si.created) BETWEEN :from AND :to
            GROUP BY s.id, s.name
            ORDER BY SUM(si.total_amount) DESC
            LIMIT :top
        ", [':from' => $from, ':to' => $to, ':top' => $top]);

        if (empty($topSuppliers)) return [];

        $supplierIds = array_column($topSuppliers, 'id');
        $ph = implode(',', array_fill(0, count($supplierIds), '?'));

        $stmt = $this->pdo->prepare("
            SELECT
                TO_CHAR(DATE_TRUNC('month', si.created), 'MM/YYYY') AS month_label,
                s.name AS supplier_name,
                SUM(si.total_amount) AS total_amount
            FROM stock_inbounds si
            JOIN suppliers s ON s.id = si.supplier_id
            WHERE si.status = 'completed'
              AND si.deleted_at IS NULL
              AND DATE(si.created) BETWEEN ? AND ?
              AND s.id IN ($ph)
            GROUP BY DATE_TRUNC('month', si.created), s.name
            ORDER BY DATE_TRUNC('month', si.created), s.name
        ");
        $params = [$from, $to, ...$supplierIds];
        $stmt->execute($params);

        return [
            'suppliers' => array_column($topSuppliers, 'name'),
            'rows'      => $stmt->fetchAll(),
        ];
    }
}