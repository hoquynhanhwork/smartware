<?php
// app/Services/InboundService.php

namespace App\Services;

use App\Contracts\InboundRepositoryInterface;
use App\Contracts\ProductRepositoryInterface;
use App\Repositories\CategoryRepository;
use App\Repositories\InboundRepository;
use App\Repositories\SupplierRepository;
use PDO;

class InboundService
{
    public function __construct(
        private readonly InboundRepositoryInterface  $repo,
        private readonly CategoryRepository          $categoryRepo,
        private readonly SupplierRepository          $supplierRepo,
        private readonly PDO                         $pdo,
        private readonly ?ProductRepositoryInterface $productRepo = null,
    ) {}

    public function list(array $filters = []): array
    {
        $page  = max(1, (int) ($filters['page']  ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 15)));

        $filterParams = [
            'keyword'      => trim((string) ($filters['keyword'] ?? '')),   // trước đây bị bỏ → ô tìm kiếm không có tác dụng
            'supplier_ids' => array_values(array_filter(array_map('intval', (array) ($filters['supplier_ids'] ?? [])))),
            'statuses'     => array_values(array_filter(
                (array) ($filters['statuses'] ?? []),
                fn($v) => in_array($v, ['pending', 'completed', 'cancelled'], true)
            )),
            'from_date'    => $filters['from_date'] ?? '',
            'to_date'      => $filters['to_date']   ?? '',
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

    public function getDetail(int $id): array|false
    {
        $header = $this->repo->findById($id);
        if (!$header) return false;

        $items = $this->repo->getItems($id);
        return array_merge(['success' => true, 'items' => $items], $header);
    }

    public function searchProducts(string $term): array
    {
        return $this->repo->searchProducts(trim($term));
    }

    public function add(int $user_id, array $input): array
    {
        $supplier_id  = (int) ($input['supplier_id']  ?? 0);
        $ref_no       = trim($input['ref_no']         ?? '');
        $note         = trim($input['note']           ?? '');
        $status       = $this->sanitizeStatus($input['status'] ?? 'completed');
        $total_amount = $this->parseNumber($input['total_amount'] ?? 0);

        if (!$supplier_id) {
            return ['ok' => false, 'message' => 'Vui lòng chọn nhà cung cấp.', 'redirect' => 'create.php'];
        }

        [$rows, $err] = $this->parseItems($input);
        if ($err) {
            return ['ok' => false, 'message' => $err, 'redirect' => 'create.php'];
        }

        if ($ref_no === '') {
            $ref_no = $this->repo->generateRefNo();
        } elseif ($this->repo->refNoExists($ref_no)) {
            return ['ok' => false, 'message' => 'Số tham chiếu đã tồn tại!', 'redirect' => 'create.php'];
        }

        try {
            $this->pdo->beginTransaction();

            $inbound_id = $this->repo->create([
                'supplier_id'  => $supplier_id,
                'user_id'      => $user_id,
                'ref_no'       => $ref_no,
                'total_amount' => $total_amount,
                'note'         => $note,
                'status'       => $status,
            ]);

            $ref = "INB-$inbound_id";

            foreach ($rows as $row) {
                $item_id = $this->repo->createItem($inbound_id, $row);

                if ($status === 'completed') {
                    $this->repo->upsertInventoryBatch(
                        $row['product_id'], $supplier_id,
                        $row['batch_no'], $row['exp_date'],
                        $row['quantity'], $row['unit_price'], $item_id
                    );
                    $this->repo->writeInventoryHistory(
                        $row['product_id'],
                        $row['batch_no'], $row['quantity'],
                        $ref, $inbound_id, $user_id, InboundRepository::HISTORY_TYPE
                    );
                }
            }

            $this->pdo->commit();
            return ['ok' => true, 'message' => "Nhập kho thành công. Phiếu: $ref_no", 'redirect' => 'index.php'];

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log('[INBOUND ADD] ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Lỗi hệ thống, vui lòng thử lại.', 'redirect' => 'create.php'];
        }
    }

    public function edit(int $user_id, int $inbound_id, array $input): array
    {
        if ($inbound_id <= 0) {
            return ['ok' => false, 'message' => 'Dữ liệu không hợp lệ.', 'redirect' => 'index.php'];
        }

        $existing = $this->repo->findById($inbound_id);
        if (!$existing) {
            return ['ok' => false, 'message' => 'Không tìm thấy phiếu nhập.', 'redirect' => 'index.php'];
        }
        if ($existing['status'] === 'completed') {
            return ['ok' => false, 'message' => 'Không thể sửa phiếu đã hoàn thành.', 'redirect' => 'index.php'];
        }
        if ($existing['status'] === 'cancelled') {
            return ['ok' => false, 'message' => 'Không thể sửa phiếu đã hủy.', 'redirect' => 'index.php'];
        }

        $supplier_id  = (int) ($input['supplier_id']  ?? 0);
        $ref_no       = trim($input['ref_no']         ?? '');
        $note         = trim($input['note']           ?? '');
        $new_status   = $this->sanitizeStatus($input['status'] ?? 'pending');
        $total_amount = $this->parseNumber($input['total_amount'] ?? 0);

        if (!$supplier_id) {
            return ['ok' => false, 'message' => 'Vui lòng chọn nhà cung cấp.', 'redirect' => 'index.php'];
        }

        [$rows, $err] = $this->parseItems($input, skipInvalid: true);
        if (empty($rows)) {
            return ['ok' => false, 'message' => 'Phiếu phải có ít nhất một dòng sản phẩm hợp lệ.', 'redirect' => 'index.php'];
        }

        try {
            $this->pdo->beginTransaction();

            $this->repo->deleteItems($inbound_id);
            $this->repo->update($inbound_id, [
                'supplier_id'  => $supplier_id,
                'ref_no'       => $ref_no,
                'note'         => $note,
                'total_amount' => $total_amount,
                'status'       => $new_status,
            ]);

            $ref = "INB-$inbound_id";

            foreach ($rows as $row) {
                $item_id = $this->repo->createItem($inbound_id, $row);

                if ($new_status === 'completed') {
                    $this->repo->upsertInventoryBatch(
                        $row['product_id'], $supplier_id,
                        $row['batch_no'], $row['exp_date'],
                        $row['quantity'], $row['unit_price'], $item_id
                    );
                    $this->repo->writeInventoryHistory(
                        $row['product_id'],
                        $row['batch_no'], $row['quantity'],
                        $ref, $inbound_id, $user_id, InboundRepository::HISTORY_TYPE
                    );
                }
            }

            $this->pdo->commit();
            return ['ok' => true, 'message' => 'Cập nhật phiếu nhập thành công.', 'redirect' => 'index.php'];

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log('[INBOUND EDIT] ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Lỗi hệ thống, vui lòng thử lại.', 'redirect' => 'index.php'];
        }
    }

    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'ID không hợp lệ.'];
        }

        $inbound = $this->repo->findById($id);
        if (!$inbound) {
            return ['ok' => false, 'message' => 'Không tìm thấy phiếu nhập hoặc đã bị xóa.'];
        }
        if ($inbound['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'Chỉ được xóa phiếu ở trạng thái Tạm thời.'];
        }

        try {
            $this->pdo->beginTransaction();
            $this->repo->softDelete($id);
            $this->pdo->commit();
            return ['ok' => true, 'message' => 'Đã xóa phiếu nhập thành công.'];

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log('[INBOUND DELETE] ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Lỗi hệ thống khi xóa phiếu.'];
        }
    }

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

        if ($this->productRepo !== null) {
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

            $newId = $this->productRepo->create($data);
            return [
                'success'     => true, 'id' => $newId, 'name' => $name, 'unit' => $unit, 'price' => $price,
                'need_expiry' => $this->repo->categoryNeedsExpiry($category_id),
            ];
        }

        if ($sku === '') {
            $sku = 'SP' . time() . rand(100, 999);
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO products
                    (category_id, supplier_id, sku, name, unit,
                     cost_price, price, description, status)
                VALUES
                    (:category_id, :supplier_id, :sku, :name, :unit,
                     :cost_price, :price, :description, :status)
                RETURNING id
            ");
            $stmt->execute([
                ':category_id' => $category_id, ':supplier_id' => $supplier_id,
                ':sku'         => $sku,          ':name'        => $name,
                ':unit'        => $unit,         ':cost_price'  => $cost_price,
                ':price'       => $price,        ':description' => $description,
                ':status'      => $status,
            ]);
            $newId = $stmt->fetchColumn();
            if ($newId) {
                return [
                    'success'     => true, 'id' => (int) $newId, 'name' => $name, 'unit' => $unit, 'price' => $price,
                    'need_expiry' => $this->repo->categoryNeedsExpiry($category_id),
                ];
            }
            return ['success' => false, 'message' => 'Lỗi hệ thống'];
        } catch (\Exception $e) {
            error_log('[INBOUND ADD_PRODUCT] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Lỗi hệ thống, vui lòng thử lại.'];
        }
    }

    public function addCategory(string $name, string $description = '', bool $needExpiry = true): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Tên danh mục không được để trống'];
        }
        $newId = $this->categoryRepo->create([
            'name'                 => $name,
            'description'          => trim($description),
            'need_expiry_tracking' => $needExpiry,
        ]);
        return ['success' => true, 'id' => $newId, 'name' => $name];
    }

    public function addSupplier(array $input): array
    {
        $name  = trim($input['name']  ?? '');
        $phone = trim($input['phone'] ?? '');

        if ($name === '' || $phone === '') {
            return ['success' => false, 'message' => 'Vui lòng nhập tên và số điện thoại'];
        }

        $newId = $this->supplierRepo->create([
            'name'          => $name,
            'phone'         => $phone,
            'email'         => trim($input['email']    ?? ''),
            'tax_code'      => trim($input['tax_code'] ?? ''),
            'address'       => trim($input['address']  ?? ''),
            'province_code' => $input['province']      ?? null,
            'ward_code'     => $input['ward']          ?? null,
            'status'        => $input['status']        ?? 'active',
        ]);
        return ['success' => true, 'id' => $newId, 'name' => $name];
    }

    public function checkSupplierUnique(string $field, string $value, int $excludeId = 0): bool
    {
        if ($value === '') return false;
        return $this->supplierRepo->existsByField($field, trim($value), $excludeId);
    }

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

    private function parseItems(array $input, bool $skipInvalid = false): array
    {
        $product_ids = $input['product_id']  ?? [];
        $batch_nos   = $input['batch_no']    ?? [];
        $mfg_dates   = $input['mfg_date']    ?? [];
        $exp_dates   = $input['exp_date']    ?? [];
        $quantities  = $input['quantity']    ?? [];
        $unit_prices = $input['unit_price']  ?? [];

        if (empty($product_ids)) {
            return [[], 'Chưa có sản phẩm nào được thêm vào phiếu.'];
        }

        // Hạn dùng chỉ bắt buộc với sản phẩm thuộc danh mục có need_expiry_tracking = true.
        $needExpiry = $this->repo->getExpiryRequirements(array_map('intval', $product_ids));

        $rows = [];
        foreach ($product_ids as $i => $pid) {
            $product_id = (int) $pid;
            $batch_no   = trim($batch_nos[$i]  ?? '');
            $exp_raw    = trim($exp_dates[$i]  ?? '');
            $quantity   = (int) ($quantities[$i] ?? 0);
            $unit_price = $this->parseNumber($unit_prices[$i] ?? 0);
            $mfg_date   = !empty($mfg_dates[$i]) ? $mfg_dates[$i] : null;

            // Mặc định coi là cần theo dõi hạn nếu không tra được (sản phẩm không tồn tại sẽ bị chặn ngay dưới).
            $requiresExp = $needExpiry[$product_id] ?? true;
            // Danh mục không theo dõi hạn: bỏ qua mọi giá trị hạn dùng client gửi lên.
            $exp_date    = $requiresExp ? $exp_raw : '';

            $row    = $i + 1;
            $errMsg = null;
            if ($product_id <= 0 || !isset($needExpiry[$product_id])) {
                $errMsg = "Dòng $row: Sản phẩm không hợp lệ.";
            } elseif ($batch_no === '') {
                $errMsg = "Dòng $row: Số lô không được để trống.";
            } elseif ($requiresExp && $exp_date === '') {
                $errMsg = "Dòng $row: Hạn sử dụng không được để trống (danh mục này có theo dõi hạn dùng).";
            } elseif ($requiresExp && !$this->isValidDate($exp_date)) {
                $errMsg = "Dòng $row: Hạn sử dụng không đúng định dạng ngày.";
            } elseif ($quantity <= 0) {
                $errMsg = "Dòng $row: Số lượng phải lớn hơn 0.";
            } elseif ($unit_price <= 0) {
                $errMsg = "Dòng $row: Đơn giá phải lớn hơn 0.";
            }

            if ($errMsg !== null) {
                if ($skipInvalid) continue;
                return [[], $errMsg];
            }

            $rows[] = [
                'product_id' => $product_id,
                'batch_no'   => $batch_no,
                'mfg_date'   => $mfg_date,
                'exp_date'   => $exp_date !== '' ? $exp_date : null,
                'quantity'   => $quantity,
                'unit_price' => $unit_price,
                'total'      => $quantity * $unit_price,
            ];
        }

        return [$rows, null];
    }

    private function isValidDate(string $value): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
