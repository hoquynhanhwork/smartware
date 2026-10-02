<?php
// app/Contracts/InboundRepositoryInterface.php

namespace App\Contracts;

interface InboundRepositoryInterface
{
    // ── Danh sách & đếm ──────────────────────────────────────────────────────
    public function list(array $filters, int $limit, int $offset): array;
    public function count(array $filters): int;

    // ── Tìm phiếu ────────────────────────────────────────────────────────────
    public function findById(int $id): array|false;

    // ── Items ─────────────────────────────────────────────────────────────────
    public function getItems(int $inbound_id): array;

    // ── Tạo phiếu ────────────────────────────────────────────────────────────
    public function create(array $data): int;
    public function createItem(int $inbound_id, array $item): int;

    // ── Cập nhật phiếu ───────────────────────────────────────────────────────
    public function update(int $id, array $data): void;
    public function deleteItems(int $inbound_id): void;

    // ── Xóa phiếu ────────────────────────────────────────────────────────────
    public function softDelete(int $id): void;

    // ── Mã tham chiếu ────────────────────────────────────────────────────────
    public function generateRefNo(): string;
    public function refNoExists(string $ref_no): bool;

    // ── Inventory batch ───────────────────────────────────────────────────────
    public function upsertInventoryBatch(
        int    $product_id,
        int    $supplier_id,
        string $batch_no,
        string $exp_date,
        int    $quantity,
        float  $unit_price,
        int    $inbound_item_id
    ): void;

    public function reverseInventoryBatch(
        int    $product_id,
        string $batch_no,
        string $exp_date,
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

    // ── Autocomplete sản phẩm ─────────────────────────────────────────────────
    public function searchProducts(string $term): array;
}