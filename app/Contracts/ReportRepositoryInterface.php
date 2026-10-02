<?php
// app/Contracts/ReportRepositoryInterface.php

namespace App\Contracts;

interface ReportRepositoryInterface
{
    public function getMonthlyFlow(string $from, string $to): array;
    public function getOverviewKpi(string $from, string $to): array;
    public function getOverviewKpiPrev(string $from, string $to): array;
    public function getTopProducts(string $from, string $to, int $limit = 5): array;
    public function getTopSuppliers(string $from, string $to, int $limit = 5): array;

    public function getStockDistribution(): array;
    public function getInventoryValueByCategory(): array;
    public function getLowStockRisk(int $limit = 10): array;
    public function getNearExpiryBatches(int $days = 30, int $limit = 10): array;

    public function getProductPerformance(
        string $from,
        string $to,
        string $sort   = 'revenue',
        int    $limit  = 20,
        int    $offset = 0
    ): array;
    public function countProductPerformance(string $from, string $to): int;
    public function getRevenueByCategory(string $from, string $to): array;

    public function getSupplierPerformance(
        string $from,
        string $to,
        int    $limit  = 20,
        int    $offset = 0
    ): array;
    public function getMonthlyInboundBySupplier(
        string $from,
        string $to,
        int    $top = 5
    ): array;
}