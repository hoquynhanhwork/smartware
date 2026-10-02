<?php
// app/Contracts/DashboardRepositoryInterface.php

namespace App\Contracts;

interface DashboardRepositoryInterface
{
    /** Số SKU đang active */
    public function countActiveProducts(): int;

    /** Số sản phẩm inactive (ngừng bán) */
    public function countInactiveProducts(): int;

    /** Số phiếu nhập đã hoàn thành trong tháng hiện tại */
    public function countInboundThisMonth(): int;

    /** Số phiếu xuất đã hoàn thành trong tháng hiện tại */
    public function countOutboundThisMonth(): int;

    /** Số cảnh báo chưa đọc */
    public function countUnreadAlerts(): int;

    /** Tổng giá trị tồn kho (tính theo cost_price) */
    public function getTotalInventoryValue(): float;

    /**
     * Danh sách sản phẩm sắp hết hàng (tồn <= min_stock).
     * @return array<int, array{name:string, sku:string, min_stock:int, current_stock:int}>
     */
    public function getLowStockProducts(int $limit = 5): array;

    /**
     * Danh sách lô hàng hết hạn trong $days ngày tới.
     * @return array<int, array{name:string, batch_no:string, exp_date:string, quantity:int, days_left:int}>
     */
    public function getNearExpiryBatches(int $days = 30, int $limit = 5): array;

    /**
     * 5 phiếu nhập kho gần nhất.
     * @return array<int, array{ref_no:string, created:string, total_amount:float, supplier_name:string, created_by:string}>
     */
    public function getRecentInbounds(int $limit = 5): array;

    /**
     * 5 phiếu xuất kho gần nhất.
     * @return array<int, array{ref_no:string, created:string, total_amount:float, created_by:string}>
     */
    public function getRecentOutbounds(int $limit = 5): array;
}