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

        if ($error = $this->validate($input, $data)) {
            return ['ok' => false, 'message' => $error];
        }

        try {
            if ($data['sku'] === '') {
                // SKU tự sinh: sinh + ghi trong 1 transaction có khóa → không trùng khi thêm đồng thời.
                $created = $this->repo->createWithAutoSku($data);
                $newId   = $created['id'];
                $data['sku'] = $created['sku'];
            } else {
                if ($msg = $this->skuTakenMessage($data['sku'])) {
                    return ['ok' => false, 'message' => $msg];
                }
                $newId = $this->repo->create($data);
            }
        } catch (\PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                return ['ok' => false, 'message' => 'SKU đã tồn tại. Vui lòng dùng SKU khác.'];
            }
            throw $e;
        }

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

        if ($error = $this->validate($input, $data)) {
            return ['ok' => false, 'message' => $error];
        }
        if ($data['sku'] === '') {
            return ['ok' => false, 'message' => 'SKU không được để trống khi cập nhật.'];
        }
        if (!$this->repo->findById($id)) {
            return ['ok' => false, 'message' => 'Không tìm thấy sản phẩm.'];
        }
        if ($msg = $this->skuTakenMessage($data['sku'], $id)) {
            return ['ok' => false, 'message' => $msg];
        }

        try {
            $this->repo->update($id, $data);
        } catch (\PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                return ['ok' => false, 'message' => 'SKU đã tồn tại. Vui lòng dùng SKU khác.'];
            }
            throw $e;
        }

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

        // Business rule: active → inactive; inactive + không tồn kho/lịch sử → xóa (xóa mềm, dữ liệu vẫn còn trong DB)
        if ($product['status'] === 'active') {
            $this->repo->setStatus($id, 'inactive');
            return ['ok' => true, 'message' => 'Sản phẩm đã được đánh dấu ngừng kinh doanh.'];
        }

        if ($this->repo->hasStockHistory($id)) {
            return ['ok' => false, 'message' => 'Không thể xóa — sản phẩm đang có tồn kho hoặc đã có lịch sử nhập/xuất. Hãy giữ ở trạng thái Ngừng kinh doanh.'];
        }

        $this->repo->softDelete($id);
        return ['ok' => true, 'message' => 'Đã xóa sản phẩm.'];
    }

    // ── Kiểm tra SKU (AJAX) ───────────────────────────────────────────────────
    public function checkSku(string $sku, int $excludeId = 0): bool
    {
        if ($sku === '') return false;
        return (bool) $this->repo->findBySku(trim($sku), $excludeId, true);
    }

    // ── Thêm danh mục nhanh (từ context sản phẩm) ────────────────────────────
    public function addCategory(string $name, string $description = '', bool $needExpiry = true): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'success' => false, 'message' => 'Tên danh mục không được để trống.'];
        }
        if ($this->categoryRepo->existsByName($name)) {
            return ['ok' => false, 'success' => false, 'message' => "Danh mục \"{$name}\" đã tồn tại."];
        }

        $newId = $this->categoryRepo->create([
            'name'                 => $name,
            'description'          => trim($description),
            'need_expiry_tracking' => $needExpiry,
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
    /**
     * Trả về thông báo lỗi nếu SKU đã bị chiếm (kể cả bởi sản phẩm đã xóa mềm, vì DB có thể
     * vẫn giữ ràng buộc unique với dòng đã xóa), ngược lại trả null.
     */
    private function skuTakenMessage(string $sku, int $excludeId = 0): ?string
    {
        $found = $this->repo->findBySku($sku, $excludeId, true);
        if (!$found) {
            return null;
        }
        return empty($found['deleted_at'])
            ? 'SKU đã tồn tại. Vui lòng dùng SKU khác.'
            : 'SKU này thuộc một sản phẩm đã xóa. Vui lòng dùng SKU khác.';
    }

    private function isUniqueViolation(\PDOException $e): bool
    {
        return ($e->errorInfo[0] ?? $e->getCode()) === '23505';
    }

    /**
     * Kiểm tra dữ liệu phía server (không tin vào validate của JS).
     * Trả về chuỗi lỗi, hoặc null nếu hợp lệ.
     */
    private function validate(array $input, array $data): ?string
    {
        if ($data['name'] === '') {
            return 'Tên sản phẩm không được để trống.';
        }

        $numericFields = [
            'price'      => 'Giá bán',
            'cost_price' => 'Giá vốn',
            'min_stock'  => 'Tồn kho tối thiểu',
            'max_stock'  => 'Tồn kho tối đa',
        ];
        foreach ($numericFields as $field => $label) {
            $raw = trim((string) ($input[$field] ?? ''));
            if ($raw !== '' && !is_numeric($raw)) {
                return "{$label} không hợp lệ.";
            }
        }

        if ($data['price'] === null) {
            return 'Vui lòng nhập giá bán.';
        }
        if ($data['price'] < 0) {
            return 'Giá bán không được âm.';
        }
        if ($data['cost_price'] < 0) {
            return 'Giá vốn không được âm.';
        }
        if ($data['min_stock'] < 0 || $data['max_stock'] < 0) {
            return 'Tồn kho tối thiểu/tối đa không được âm.';
        }
        if ($data['max_stock'] > 0 && $data['min_stock'] > $data['max_stock']) {
            return 'Tồn kho tối thiểu không được lớn hơn tồn kho tối đa.';
        }

        if ($data['category_id'] !== null && !$this->categoryRepo->findById($data['category_id'])) {
            return 'Danh mục không tồn tại.';
        }
        if ($data['supplier_id'] !== null && !$this->supplierRepo->findById($data['supplier_id'])) {
            return 'Nhà cung cấp không tồn tại.';
        }

        return null;
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
            'unit'        => trim($input['unit']        ?? '') ?: 'cái',
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