<?php
// app/Services/ReportService.php

namespace App\Services;

use App\Contracts\ReportRepositoryInterface;

class ReportService
{
    public function __construct(
        private readonly ReportRepositoryInterface $repo,
    ) {}

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Parse và validate khoảng thời gian từ GET params */
    public function parsePeriod(array $params, string $defaultFrom = null, string $defaultTo = null): array
    {
        $from = $params['from'] ?? $defaultFrom ?? date('Y-m-01');
        $to   = $params['to']   ?? $defaultTo   ?? date('Y-m-d');

        // Validate format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
        if ($from > $to) [$from, $to] = [$to, $from];

        return [$from, $to];
    }

    /** Tính % thay đổi so với kỳ trước */
    public function pctChange(float $current, float $prev): ?float
    {
        if ($prev == 0) return null;
        return round(($current - $prev) / $prev * 100, 1);
    }

    // =========================================================================
    // OVERVIEW
    // =========================================================================

    public function getOverviewData(string $from, string $to): array
    {
        $kpi     = $this->repo->getOverviewKpi($from, $to);
        $kpiPrev = $this->repo->getOverviewKpiPrev($from, $to);

        // Chart data: nhập xuất theo tháng
        $monthly  = $this->repo->getMonthlyFlow($from, $to);
        $labels   = array_column($monthly, 'label');
        $inbounds = array_map(fn($r) => (float) $r['total_inbound'],  $monthly);
        $outbounds = array_map(fn($r) => (float) $r['total_outbound'], $monthly);

        return [
            'kpi' => [
                'inbound_amount'    => $kpi['inbound_amount'],
                'inbound_orders'    => $kpi['inbound_orders'],
                'inbound_suppliers' => $kpi['inbound_suppliers'],
                'outbound_amount'   => $kpi['outbound_amount'],
                'outbound_orders'   => $kpi['outbound_orders'],
                'inventory_value'   => $kpi['inventory_value'],
                // % so kỳ trước
                'inbound_pct'   => $this->pctChange($kpi['inbound_amount'],  $kpiPrev['inbound_amount']),
                'outbound_pct'  => $this->pctChange($kpi['outbound_amount'], $kpiPrev['outbound_amount']),
            ],
            'chart' => [
                'labels'    => $labels,
                'inbounds'  => $inbounds,
                'outbounds' => $outbounds,
            ],
            'top_products'  => $this->repo->getTopProducts($from, $to),
            'top_suppliers' => $this->repo->getTopSuppliers($from, $to),
        ];
    }

    // =========================================================================
    // INVENTORY
    // =========================================================================

    public function getInventoryData(int $expiryDays = 30): array
    {
        $distribution = $this->repo->getStockDistribution();
        $byCategory   = $this->repo->getInventoryValueByCategory();
        $lowStock     = $this->repo->getLowStockRisk();
        $nearExpiry   = $this->repo->getNearExpiryBatches($expiryDays);

        // Chart: phân bố theo trạng thái
        $statusLabels = [
            'OUT_OF_STOCK' => 'Hết hàng',
            'LOW_STOCK'    => 'Sắp hết',
            'NEAR_EXPIRY'  => 'Sắp hết hạn',
            'OVERSTOCK'    => 'Tồn nhiều',
            'NORMAL'       => 'Bình thường',
        ];
        $distChart = [
            'labels' => [],
            'counts' => [],
            'values' => [],
            'colors' => ['#ef4444','#f59e0b','#f97316','#3b82f6','#22c55e'],
        ];
        foreach ($distribution as $d) {
            $distChart['labels'][] = $statusLabels[$d['stock_status']] ?? $d['stock_status'];
            $distChart['counts'][] = (int)   $d['cnt'];
            $distChart['values'][] = (float) $d['value'];
        }

        // Chart: giá trị theo danh mục
        $catChart = [
            'labels' => array_column($byCategory, 'category_name'),
            'values' => array_map(fn($r) => (float) $r['total_value'], $byCategory),
        ];

        // KPI tổng
        $totalValue = array_sum(array_column($byCategory, 'total_value'));
        $atRiskValue = array_sum(array_column($nearExpiry, 'at_risk_value'));

        return [
            'kpi' => [
                'total_value'    => $totalValue,
                'at_risk_value'  => $atRiskValue,
                'low_stock_cnt'  => array_sum(array_map(
                    fn($d) => in_array($d['stock_status'], ['OUT_OF_STOCK','LOW_STOCK']) ? (int)$d['cnt'] : 0,
                    $distribution
                )),
                'near_expiry_cnt' => count($nearExpiry),
            ],
            'dist_chart' => $distChart,
            'cat_chart'  => $catChart,
            'low_stock'  => $lowStock,
            'near_expiry'=> $nearExpiry,
        ];
    }

    // =========================================================================
    // PRODUCTS
    // =========================================================================

    public function getProductData(string $from, string $to, array $params = []): array
    {
        $sort   = in_array($params['sort'] ?? '', ['revenue','qty','orders']) ? $params['sort'] : 'revenue';
        $page   = max(1, (int)($params['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $items = $this->repo->getProductPerformance($from, $to, $sort, $limit, $offset);
        $total = $this->repo->countProductPerformance($from, $to);

        // Chart: doanh thu theo danh mục
        $byCategory = $this->repo->getRevenueByCategory($from, $to);
        $catChart = [
            'labels' => array_column($byCategory, 'category_name'),
            'values' => array_map(fn($r) => (float) $r['total_revenue'], $byCategory),
        ];

        return [
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
            'page'        => $page,
            'sort'        => $sort,
            'cat_chart'   => $catChart,
        ];
    }

    // =========================================================================
    // SUPPLIERS
    // =========================================================================

    public function getSupplierData(string $from, string $to, array $params = []): array
    {
        $page   = max(1, (int)($params['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $items   = $this->repo->getSupplierPerformance($from, $to, $limit, $offset);
        $monthly = $this->repo->getMonthlyInboundBySupplier($from, $to);

        // Flatten monthly data thành format Chart.js stacked bar
        $chartData = $this->buildStackedBarData($monthly);

        return [
            'items'      => $items,
            'chart'      => $chartData,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function buildStackedBarData(array $monthly): array
    {
        if (empty($monthly) || empty($monthly['rows'])) {
            return ['labels' => [], 'datasets' => []];
        }

        $suppliers = $monthly['suppliers'];
        $rows      = $monthly['rows'];

        // Collect tất cả labels tháng
        $labels = array_values(array_unique(array_column($rows, 'month_label')));

        // Build dataset cho từng NCC
        $datasets = [];
        $colors   = ['#3b82f6','#22c55e','#f59e0b','#ef4444','#8b5cf6'];

        foreach ($suppliers as $i => $supplierName) {
            $data = [];
            foreach ($labels as $label) {
                $found = array_filter($rows, fn($r) => $r['month_label'] === $label && $r['supplier_name'] === $supplierName);
                $data[] = $found ? (float) array_values($found)[0]['total_amount'] : 0;
            }
            $datasets[] = [
                'label'           => $supplierName,
                'data'            => $data,
                'backgroundColor' => $colors[$i % count($colors)],
            ];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }
}