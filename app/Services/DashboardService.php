<?php
// app/Services/DashboardService.php

namespace App\Services;

use App\Contracts\DashboardRepositoryInterface;

class DashboardService
{
    public function __construct(
        private readonly DashboardRepositoryInterface $repo,
    ) {}

    /**
     * @return array{
     *   kpis: array,
     *   stats: array,
     *   low_stock: array,
     *   near_expiry: array,
     *   recent_inbounds: array,
     *   recent_outbounds: array
     * }
     */
    public function getDashboardData(): array
    {
        $inboundCount  = $this->repo->countInboundThisMonth();
        $outboundCount = $this->repo->countOutboundThisMonth();
        return [
            'kpis'             => $this->buildKpis($inboundCount, $outboundCount),
            'stats'            => $this->buildStats($inboundCount, $outboundCount),
            'low_stock'        => $this->repo->getLowStockProducts(),
            'near_expiry'      => $this->buildNearExpiry(),
            'recent_inbounds'  => $this->repo->getRecentInbounds(),
            'recent_outbounds' => $this->repo->getRecentOutbounds(),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildKpis(int $in, int $out): array
    {
        $alertCount = $this->repo->countUnreadAlerts();
        return [
            'total_products'      => $this->repo->countActiveProducts(),
            'inbound_this_month'  => $in,
            'outbound_this_month' => $out,
            'alert_count'         => $alertCount,
            'alert_has_danger'    => $alertCount > 0,
        ];
    }

    private function buildStats(int $in, int $out): array
    {
        $inventoryValue = $this->repo->getTotalInventoryValue();
        return [
            'inventory_value_formatted' => number_format($inventoryValue, 0, ',', '.'),
            'inventory_value'    => $inventoryValue,
            'inactive_products'  => $this->repo->countInactiveProducts(),
            'inbound_this_month' => $in,
            'outbound_this_month'=> $out,
        ];
    }

    private function buildNearExpiry(): array
    {
        $rows = $this->repo->getNearExpiryBatches();

        foreach ($rows as &$row) {
            $row['severity']         = $row['days_left'] <= 7 ? 'danger' : 'warning';
            $row['exp_date_display'] = date('d/m/Y', strtotime($row['exp_date']));
        }
        unset($row);

        return $rows;
    }
}