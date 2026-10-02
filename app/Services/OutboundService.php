<?php
// app/Services/OutboundService.php

namespace App\Services;

use App\Repositories\OutboundRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CategoryRepository;
use PDO;

class OutboundService
{
    public function __construct(
        private readonly OutboundRepository $repo,
        private readonly ProductRepository  $productRepo,
        private readonly CategoryRepository $categoryRepo,
        private readonly PDO               $pdo,
    ) {}

    // ── Danh sách có lọc + phân trang ────────────────────────────────────
    public function list(array $filters = []): array
    {
        $page  = max(1, (int) ($filters['page']  ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 15)));

        $filterParams = [
            'keyword'  => trim($filters['keyword'] ?? ''),
            'statuses' => array_filter((array) ($filters['status'] ?? [])),
            'from'     => $filters['from'] ?? '',
            'to'       => $filters['to']   ?? '',
        ];

        $total = $this->repo->count($filterParams);
        $items = $this->repo->list($filterParams, $limit, ($page - 1) * $limit);

        return [
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
            'page'        => $page,
            'limit'       => $limit,
        ];
    }

    // ── Chi tiết phiếu xuất ───────────────────────────────────────────────
    public function getDetail(int $id): array|false
    {
        $header = $this->repo->findById($id);
        if (!$header) return false;

        $items = $this->repo->getItems($id);
        return array_merge($header, ['items' => $items]);
    }

    // ── Tìm sản phẩm autocomplete ────────────────────────────────────────
    public function searchProducts(string $term): array
    {
        return $this->repo->searchProducts(trim($term));
    }

    // ── Lấy lô hàng theo sản phẩm (FEFO) ─────────────────────────────────
    public function getBatchesForProduct(int $product_id): array
    {
        if ($product_id <= 0) {
            return ['success' => false, 'batches' => []];
        }

        $batches = $this->repo->getBatchesForProduct($product_id);
        return ['success' => true, 'batches' => $batches];
    }

    // ── Thêm phiếu xuất ──────────────────────────────────────────────────
    public function add(int $user_id, array $input): array
    {
        $ref_no = trim($input['ref_no'] ?? '');
        $note   = trim($input['note']   ?? '');
        $status = $this->sanitizeStatus($input['status'] ?? 'completed');

        [$rows, $err] = $this->parseItems($input);
        if ($err) {
            return ['ok' => false, 'message' => $err, 'redirect' => 'create.php'];
        }

        if ($ref_no === '') {
            $ref_no = $this->repo->generateRefNo();
        }

        try {
            $this->pdo->beginTransaction();

            $outbound_id = $this->repo->create([
                'user_id' => $user_id,
                'ref_no'  => $ref_no,
                'note'    => $note,
                'status'  => $status,
            ]);

            $ref = "OUT-$outbound_id";

            foreach ($rows as $row) {
                $this->repo->createItem($outbound_id, $row);

                if ($status === 'completed') {
                    // reduceInventoryBatch có validate → throw nếu không đủ hàng
                    $this->repo->reduceInventoryBatch(
                        $row['product_id'],
                        $row['batch_no'],
                        $row['quantity']
                    );
                    $this->repo->writeInventoryHistory(
                        $row['product_id'],
                        $row['batch_no'], $row['quantity'],
                        $ref, $outbound_id, $user_id, 'out'
                    );
                }
            }

            $this->pdo->commit();
            return ['ok' => true, 'message' => "Xuất kho thành công. Phiếu: $ref_no", 'redirect' => 'index.php'];

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log('[OUTBOUND ADD] ' . $e->getMessage());
            $msg = str_contains($e->getMessage(), 'Không đủ tồn kho')
                ? $e->getMessage()
                : 'Lỗi hệ thống, vui lòng thử lại.';
            return ['ok' => false, 'message' => $msg, 'redirect' => 'create.php'];
        }
    }

    // ── Sửa phiếu xuất ───────────────────────────────────────────────────
    public function edit(int $user_id, int $outbound_id, array $input): array
    {
        if ($outbound_id <= 0) {
            return ['ok' => false, 'message' => 'Dữ liệu không hợp lệ.', 'redirect' => 'index.php'];
        }

        $existing = $this->repo->findById($outbound_id);
        if (!$existing) {
            return ['ok' => false, 'message' => 'Không tìm thấy phiếu xuất.', 'redirect' => 'index.php'];
        }
        // Chỉ cho sửa phiếu pending
        if ($existing['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'Chỉ được sửa phiếu ở trạng thái Tạm thời.', 'redirect' => 'index.php'];
        }

        $ref_no     = trim($input['ref_no'] ?? '');
        $note       = trim($input['note']   ?? '');
        $new_status = $this->sanitizeStatus($input['status'] ?? 'pending');

        [$rows, $err] = $this->parseItems($input, skipInvalid: true);
        if (empty($rows)) {
            return ['ok' => false, 'message' => 'Phiếu phải có ít nhất một dòng sản phẩm hợp lệ.', 'redirect' => 'index.php'];
        }

        try {
            $this->pdo->beginTransaction();

            $this->repo->deleteItems($outbound_id);
            $this->repo->update($outbound_id, [
                'ref_no' => $ref_no,
                'note'   => $note,
                'status' => $new_status,
            ]);

            $ref = "OUT-$outbound_id";

            foreach ($rows as $row) {
                $this->repo->createItem($outbound_id, $row);

                // Chỉ trừ kho khi chuyển từ pending → completed
                if ($new_status === 'completed') {
                    $this->repo->reduceInventoryBatch(
                        $row['product_id'],
                        $row['batch_no'],
                        $row['quantity']
                    );
                    $this->repo->writeInventoryHistory(
                        $row['product_id'],
                        $row['batch_no'], $row['quantity'],
                        $ref, $outbound_id, $user_id, 'out'
                    );
                }
            }

            $this->pdo->commit();
            return ['ok' => true, 'message' => 'Cập nhật phiếu xuất thành công.', 'redirect' => 'index.php'];

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log('[OUTBOUND EDIT] ' . $e->getMessage());
            $msg = str_contains($e->getMessage(), 'Không đủ tồn kho')
                ? $e->getMessage()
                : 'Lỗi hệ thống, vui lòng thử lại.';
            return ['ok' => false, 'message' => $msg, 'redirect' => 'index.php'];
        }
    }

    // ── Xóa phiếu xuất ───────────────────────────────────────────────────
    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'ID không hợp lệ.'];
        }

        $outbound = $this->repo->findById($id);
        if (!$outbound) {
            return ['ok' => false, 'message' => 'Không tìm thấy phiếu xuất hoặc đã bị xóa.'];
        }
        if ($outbound['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'Chỉ được xóa phiếu ở trạng thái Tạm thời.'];
        }

        try {
            $this->pdo->beginTransaction();
            // Phiếu pending chưa trừ kho → chỉ cần softDelete, không cần restore
            $this->repo->softDelete($id);
            $this->pdo->commit();
            return ['ok' => true, 'message' => 'Đã xóa phiếu xuất thành công.'];

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log('[OUTBOUND DELETE] ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Lỗi hệ thống khi xóa phiếu.'];
        }
    }

    // ── Thêm sản phẩm nhanh ───────────────────────────────────────────────
    public function addProduct(array $input): array
    {
        $name        = trim($input['name']        ?? '');
        $sku         = trim($input['sku']         ?? '');
        $unit        = trim($input['unit']        ?? '');
        $description = trim($input['description'] ?? '');
        $category_id = !empty($input['category_id']) ? (int) $input['category_id'] : null;
        $supplier_id = !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null;
        $cost_price  = $this->parseNumber($input['cost_price'] ?? 0);
        $price       = $this->parseNumber($input['price']      ?? 0);
        $status      = in_array($input['status'] ?? '', ['active', 'inactive'])
                       ? $input['status'] : 'active';

        if ($name === '' || $unit === '' || $price <= 0) {
            return ['success' => false, 'message' => 'Vui lòng nhập đầy đủ: tên, đơn vị, giá bán > 0'];
        }

        if ($sku !== '' && $this->productRepo->findBySku($sku)) {
            return ['success' => false, 'message' => 'SKU đã tồn tại.'];
        }

        $data = [
            'category_id' => $category_id,
            'supplier_id' => $supplier_id,
            'sku'         => $sku !== '' ? $sku : 'SP' . str_pad(
                (string) $this->productRepo->nextSkuNumber(),
                5, '0', STR_PAD_LEFT
            ),
            'name'        => $name,
            'unit'        => $unit,
            'cost_price'  => $cost_price,
            'price'       => $price,
            'description' => $description,
            'status'      => $status,
            'min_stock'   => 0,
            'max_stock'   => 0,
        ];

        try {
            $newId = $this->productRepo->create($data);
            return ['success' => true, 'id' => $newId, 'name' => $name, 'unit' => $unit, 'price' => $price];
        } catch (\Exception $e) {
            error_log('[OUTBOUND ADD_PRODUCT] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Lỗi hệ thống, vui lòng thử lại.'];
        }
    }

    // ── Thêm danh mục nhanh ───────────────────────────────────────────────
    public function addCategory(string $name, string $description = ''): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Tên danh mục không được để trống'];
        }
        $newId = $this->categoryRepo->create([
            'name'        => $name,
            'description' => trim($description),
            'parent_id'   => null,
        ]);
        return ['success' => true, 'id' => $newId, 'name' => $name];
    }

    // ── Helpers nội bộ ────────────────────────────────────────────────────

    private function sanitizeStatus(string $status): string
    {
        return in_array($status, ['pending', 'completed', 'cancelled']) ? $status : 'completed';
    }

    private function parseNumber(mixed $str): float
    {
        $str = str_replace('.', '', (string) $str);
        $str = str_replace(',', '.', $str);
        return is_numeric($str) ? (float) $str : 0.0;
    }

    // Lưu ý: schema hiện tại của stock_outbound_items không có unit_price/total,
    // nên phiếu xuất chỉ theo dõi số lượng, không còn theo dõi đơn giá/thành tiền.
    private function parseItems(array $input, bool $skipInvalid = false): array
    {
        $product_ids = $input['product_id'] ?? [];
        $batch_nos   = $input['batch_no']   ?? [];
        $quantities  = $input['quantity']   ?? [];

        if (empty($product_ids)) {
            return [[], 'Chưa có sản phẩm nào được thêm vào phiếu.'];
        }

        $rows = [];
        foreach ($product_ids as $i => $pid) {
            $product_id = (int) $pid;
            $batch_no   = trim($batch_nos[$i] ?? '');
            $quantity   = (int) $this->parseNumber($quantities[$i] ?? 0);

            if ($product_id <= 0 || $batch_no === '' || $quantity <= 0) {
                if ($skipInvalid) continue;
                $row = $i + 1;
                if ($product_id <= 0)  return [[], "Dòng $row: Sản phẩm không hợp lệ."];
                if ($batch_no === '')  return [[], "Dòng $row: Số lô không được để trống."];
                if ($quantity <= 0)    return [[], "Dòng $row: Số lượng phải lớn hơn 0."];
            }

            $rows[] = [
                'product_id' => $product_id,
                'batch_no'   => $batch_no,
                'quantity'   => $quantity,
            ];
        }

        return [$rows, null];
    }
}