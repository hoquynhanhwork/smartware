<?php
// app/Services/ProductService.php

namespace App\Services;

use App\Contracts\ProductRepositoryInterface;
use App\Contracts\CategoryRepositoryInterface;
use App\Contracts\SupplierRepositoryInterface;

class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface  $repo,
        private readonly CategoryRepositoryInterface $categoryRepo,
        private readonly SupplierRepositoryInterface $supplierRepo,
    ) {}

    // ── Danh sách có lọc + phân trang ────────────────────────────────────────
    public function list(array $filters = []): array
    {
        $page     = max(1, (int) ($filters['page']  ?? 1));
        $limit    = min(100, max(1, (int) ($filters['limit'] ?? 15)));
        $stockMin = strlen((string) ($filters['stock_min'] ?? '')) > 0 ? (float) $filters['stock_min'] : 0;
        $stockMax = strlen((string) ($filters['stock_max'] ?? '')) > 0 ? (float) $filters['stock_max'] : 999999999;

        $total = $this->repo->count(
            keyword:     $filters['keyword']      ?? '',
            categoryIds: $filters['category_ids'] ?? [],
            statuses:    $filters['statuses']     ?? [],
            supplierIds: $filters['supplier_ids'] ?? [],
            stockMin:    $stockMin,
            stockMax:    $stockMax,
        );

        $items = $this->repo->list(
            keyword:     $filters['keyword']      ?? '',
            categoryIds: $filters['category_ids'] ?? [],
            statuses:    $filters['statuses']     ?? [],
            supplierIds: $filters['supplier_ids'] ?? [],
            stockMin:    $stockMin,
            stockMax:    $stockMax,
            limit:       $limit,
            offset:      ($page - 1) * $limit,
        );

        return [
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
            'page'        => $page,
            'limit'       => $limit,
        ];
    }

    public function getFormData(): array
    {
        return [
            'categories' => $this->categoryRepo->listWithCount(),
            'suppliers'  => $this->supplierRepo->listActive(),
        ];
    }

    // ── Thống kê stat cards ───────────────────────────────────────────────────
    public function stats(): array
    {
        return [
            'total'    => $this->repo->countAll(),
            'active'   => $this->repo->countByStatus('active'),
            'inactive' => $this->repo->countByStatus('inactive'),
        ];
    }

    // ── Thêm sản phẩm ─────────────────────────────────────────────────────────
    public function add(array $input): array
    {
        $data = $this->sanitize($input);

        if ($data['name'] === '') {
            return ['ok' => false, 'message' => 'Tên sản phẩm không được để trống.'];
        }

        if ($data['sku'] === '') {
            $data['sku'] = $this->generateSku();
        } else {
            if ($this->repo->findBySku($data['sku'])) {
                return ['ok' => false, 'message' => 'SKU đã tồn tại. Vui lòng dùng SKU khác.'];
            }
        }

        $newId = $this->repo->create($data);

        return [
            'ok'      => true,
            'message' => "Thêm sản phẩm \"{$data['name']}\" thành công. SKU: {$data['sku']}",
            'id'      => $newId,
        ];
    }

    // ── Cập nhật ─────────────────────────────────────────────────────────────
    public function edit(int $id, array $input): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Dữ liệu không hợp lệ.'];
        }

        $data = $this->sanitize($input);

        if ($data['name'] === '') {
            return ['ok' => false, 'message' => 'Tên sản phẩm không được để trống.'];
        }
        if ($data['sku'] === '') {
            return ['ok' => false, 'message' => 'SKU không được để trống khi cập nhật.'];
        }
        if (!$this->repo->findById($id)) {
            return ['ok' => false, 'message' => 'Không tìm thấy sản phẩm.'];
        }
        if ($this->repo->findBySku($data['sku'], excludeId: $id)) {
            return ['ok' => false, 'message' => 'SKU đã tồn tại. Vui lòng dùng SKU khác.'];
        }

        $this->repo->update($id, $data);

        return ['ok' => true, 'message' => "Cập nhật sản phẩm \"{$data['name']}\" thành công."];
    }

    // ── Xóa / ngừng kinh doanh ───────────────────────────────────────────────
    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'ID không hợp lệ.'];
        }

        $product = $this->repo->findById($id);
        if (!$product) {
            return ['ok' => false, 'message' => 'Không tìm thấy sản phẩm.'];
        }

        // Business rule: active → inactive (soft), inactive + không có lịch sử → xóa hẳn
        if ($product['status'] === 'active') {
            $this->repo->setStatus($id, 'inactive');
            return ['ok' => true, 'message' => 'Sản phẩm đã được đánh dấu ngừng kinh doanh.'];
        }

        if ($this->repo->hasStockHistory($id)) {
            return ['ok' => false, 'message' => 'Không thể xóa vĩnh viễn — sản phẩm đã có lịch sử nhập/xuất kho.'];
        }

        $this->repo->softDelete($id);
        return ['ok' => true, 'message' => 'Đã xóa sản phẩm vĩnh viễn.'];
    }

    // ── Kiểm tra SKU (AJAX) ───────────────────────────────────────────────────
    public function checkSku(string $sku, int $excludeId = 0): bool
    {
        if ($sku === '') return false;
        return (bool) $this->repo->findBySku(trim($sku), $excludeId);
    }

    // ── Thêm danh mục nhanh (từ context sản phẩm) ────────────────────────────
    public function addCategory(string $name, string $description = ''): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Tên danh mục không được để trống.'];
        }

        $newId = $this->categoryRepo->create([
            'name'        => $name,
            'description' => trim($description),
        ]);

        return [
            'ok'      => true,
            'success' => true,
            'id'      => $newId,
            'name'    => $name,
            'message' => "Thêm danh mục \"{$name}\" thành công.",
        ];
    }

    // ── Helpers nội bộ ───────────────────────────────────────────────────────
    private function generateSku(): string
    {
        $next = $this->repo->nextSkuNumber();
        return 'SP' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function sanitize(array $input): array
    {
        $status = in_array($input['status'] ?? '', ['active', 'inactive'])
            ? $input['status']
            : 'active';

        return [
            'name'        => trim($input['name']        ?? ''),
            'sku'         => trim($input['sku']         ?? ''),
            'category_id' => !empty($input['category_id'])  ? (int)   $input['category_id']  : null,
            'supplier_id' => !empty($input['supplier_id'])  ? (int)   $input['supplier_id']  : null,
            'unit'        => trim($input['unit']        ?? ''),
            'price'       => isset($input['price'])      && $input['price'] !== ''
                                ? (float) $input['price']      : null,
            'cost_price'  => isset($input['cost_price']) && $input['cost_price'] !== ''
                                ? (float) $input['cost_price'] : 0.0,
            'description' => trim($input['description'] ?? ''),
            'status'      => $status,
            'min_stock'   => isset($input['min_stock']) && $input['min_stock'] !== ''
                                ? (int) $input['min_stock']    : 0,
            'max_stock'   => isset($input['max_stock']) && $input['max_stock'] !== ''
                                ? (int) $input['max_stock']    : 0,
        ];
    }
}