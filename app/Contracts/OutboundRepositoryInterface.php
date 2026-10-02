<?php
// app/Contracts/OutboundRepositoryInterface.php

namespace App\Contracts;

interface OutboundRepositoryInterface
{
    // ── Danh sách & đếm ──────────────────────────────────────────────────────
    public function list(array $filters, int $limit, int $offset): array;
    public function count(array $filters): int;

    // ── Tìm phiếu ────────────────────────────────────────────────────────────
    public function findById(int $id): array|false;

    // ── Items ─────────────────────────────────────────────────────────────────
    public function getItems(int $outbound_id): array;

    // ── Tạo phiếu ────────────────────────────────────────────────────────────
    public function create(array $data): int;
    public function createItem(int $outbound_id, array $item): void;

    // ── Cập nhật phiếu ───────────────────────────────────────────────────────
    public function update(int $id, array $data): bool;
    public function deleteItems(int $outbound_id): void;

    // ── Xóa phiếu ────────────────────────────────────────────────────────────
    public function softDelete(int $id): void;

    // ── Mã tham chiếu ────────────────────────────────────────────────────────
    public function generateRefNo(): string;

    // ── Lô hàng ──────────────────────────────────────────────────────────────
    public function getBatchesForProduct(int $product_id): array;

    // ── Autocomplete sản phẩm ─────────────────────────────────────────────────
    public function searchProducts(string $term): array;

    // ── Inventory: trừ / hoàn tồn kho ────────────────────────────────────────
    public function reduceInventoryBatch(
        int    $product_id,
        string $batch_no,
        int    $quantity
    ): void;

    public function restoreInventoryBatch(
        int    $product_id,
        string $batch_no,
        int    $quantity
    ): void;

    // ── Inventory history ─────────────────────────────────────────────────────
    public function writeInventoryHistory(
        int    $product_id,
        string $batch_no,
        int    $quantity,
        string $reference_no,
        int    $reference_id,
        int    $user_id,
        string $type
    ): void;

    public function deleteInventoryHistory(
        int    $product_id,
        string $batch_no,
        int    $reference_id
    ): void;

    // ── Kiểm tra tồn kho đủ trước khi xuất ───────────────────────────────────
    public function getAvailableStock(int $product_id, string $batch_no): int;
}