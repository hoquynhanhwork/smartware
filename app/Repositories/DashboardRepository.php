<?php
// app/Repositories/DashboardRepository.php

namespace App\Repositories;

use App\Contracts\DashboardRepositoryInterface;

class DashboardRepository extends BaseRepository implements DashboardRepositoryInterface
{
    public function countActiveProducts(): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM products
            WHERE  status     = 'active'
              AND  deleted_at IS NULL
        ");
    }

    public function countInactiveProducts(): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM products
            WHERE  status     = 'inactive'
              AND  deleted_at IS NULL
        ");
    }

    public function countInboundThisMonth(): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM stock_inbounds
            WHERE  status      = 'completed'
              AND  deleted_at  IS NULL
              AND  DATE_TRUNC('month', created) = DATE_TRUNC('month', public.app_today())
        ");
    }

    public function countOutboundThisMonth(): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM stock_outbounds
            WHERE  status      = 'completed'
              AND  deleted_at  IS NULL
              AND  DATE_TRUNC('month', created) = DATE_TRUNC('month', public.app_today())
        ");
    }

    public function countUnreadAlerts(): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM alerts
            WHERE  is_read = false
        ");
    }

    public function getTotalInventoryValue(): float
    {
        return (float) $this->fetchColumn("
            SELECT COALESCE(SUM(ib.quantity * ib.cost_price), 0)
            FROM   inventory_batches ib
            JOIN   products p ON p.id = ib.product_id
            WHERE  ib.quantity  > 0
              AND  p.deleted_at IS NULL
        ");
    }

    public function getLowStockProducts(int $limit = 5): array
    {
        $lim = (int) $limit;
        return $this->fetchAll("
            SELECT name, sku, min_stock, current_stock
            FROM (
                SELECT p.name, p.sku, p.min_stock,
                       COALESCE(SUM(ib.quantity), 0) AS current_stock
                FROM   products p
                LEFT JOIN inventory_batches ib ON ib.product_id = p.id
                WHERE  p.status     = 'active'
                  AND  p.deleted_at IS NULL
                  AND  p.min_stock  > 0
                GROUP BY p.id, p.name, p.sku, p.min_stock
            ) sub
            WHERE  current_stock <= min_stock
            ORDER BY current_stock ASC
            LIMIT  {$lim}
        ");
    }

    public function getNearExpiryBatches(int $days = 30, int $limit = 5): array
    {
        $days = (int) $days;
        $lim  = (int) $limit;

        return $this->fetchAll("
            SELECT p.name,
                   ib.batch_no,
                   TO_CHAR(ib.exp_date, 'YYYY-MM-DD') AS exp_date,
                   ib.quantity,
                   (ib.exp_date - public.app_today())::int   AS days_left
            FROM   inventory_batches ib
            JOIN   products p ON p.id = ib.product_id
            WHERE  ib.quantity   > 0
              AND  ib.exp_date BETWEEN public.app_today()
                                   AND (public.app_today() + INTERVAL '{$days} days')
            ORDER BY ib.exp_date ASC
            LIMIT  {$lim}
        ");
    }

    public function getRecentInbounds(int $limit = 5): array
    {
        $lim = (int) $limit;
        return $this->fetchAll("
            SELECT si.ref_no,
                   si.created::text  AS created,
                   si.total_amount,
                   s.name            AS supplier_name,
                   u.full_name       AS created_by
            FROM   stock_inbounds si
            JOIN   suppliers s ON s.id = si.supplier_id
            JOIN   users u     ON u.id = si.user_id
            WHERE  si.deleted_at IS NULL
            ORDER BY si.created DESC
            LIMIT  {$lim}
        ");
    }

    public function getRecentOutbounds(int $limit = 5): array
    {
        $lim = (int) $limit;
        return $this->fetchAll("
            SELECT so.ref_no,
                   so.created::text AS created,
                   COALESCE(SUM(soi.quantity * ib.cost_price), 0) AS total_amount,
                   u.full_name      AS created_by
            FROM   stock_outbounds so
            JOIN   users u ON u.id = so.user_id
            LEFT JOIN stock_outbound_items soi ON soi.outbound_id = so.id
            LEFT JOIN inventory_batches ib
                   ON ib.product_id = soi.product_id
                  AND ib.batch_no   = soi.batch_no
            WHERE  so.deleted_at IS NULL
              AND  so.status     = 'completed'
            GROUP BY so.id, so.ref_no, so.created, u.full_name
            ORDER BY so.created DESC
            LIMIT  {$lim}
        ");
    }
}