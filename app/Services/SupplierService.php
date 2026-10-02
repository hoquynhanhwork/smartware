<?php
// app/Services/SupplierService.php

namespace App\Services;

use App\Contracts\SupplierRepositoryInterface;

class SupplierService
{
    public function __construct(
        private readonly SupplierRepositoryInterface $repo,
    ) {}

    // ── Danh sách có lọc ─────────────────────────────────────────────────────
    public function list(array $filters = []): array
    {
        $page  = max(1, (int) ($filters['page']  ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 15)));

        $filterParams = [
            'from_date'        => $filters['from_date']        ?? '',
            'to_date'          => $filters['to_date']          ?? '',
            'statuses'         => $filters['statuses']         ?? [],
            'entity_origins'   => $filters['entity_origins']   ?? [],
            'has_transactions' => $filters['has_transactions'] ?? [],
            'province_code'    => $filters['province_code']    ?? '',
            'ward_code'        => $filters['ward_code']        ?? '',
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

    // ── Thống kê ─────────────────────────────────────────────────────────────
    public function stats(): array
    {
        return [
            'total'    => $this->repo->countAll(),
            'active'   => $this->repo->countByStatus('active'),
            'inactive' => $this->repo->countByStatus('inactive'),
        ];
    }

    // ── Thêm đối tác
    public function add(array $input): array
    {
        $data = $this->sanitize($input);

        if ($data['name'] === '') {
            return ['ok' => false, 'message' => 'Tên đối tác không được để trống.'];
        }
        if ($data['tax_code'] !== '' && $this->repo->existsByField('tax_code', $data['tax_code'])) {
            return ['ok' => false, 'message' => 'Mã số thuế đã tồn tại.'];
        }
        if ($this->repo->existsByContactInfo($data['name'], $data['phone'], $data['email'])) {
            return ['ok' => false, 'message' => 'Tên, số điện thoại hoặc email đã tồn tại.'];
        }

        $newId = $this->repo->create($data);

        return [
            'ok'      => true,
            'message' => "Thêm đối tác \"{$data['name']}\" thành công.",
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
            return ['ok' => false, 'message' => 'Tên đối tác không được để trống.'];
        }
        if (!$this->repo->findById($id)) {
            return ['ok' => false, 'message' => 'Không tìm thấy đối tác.'];
        }
        if ($data['tax_code'] !== '' && $this->repo->existsByField('tax_code', $data['tax_code'], $id)) {
            return ['ok' => false, 'message' => 'Mã số thuế đã tồn tại.'];
        }
        if ($this->repo->existsByContactInfo($data['name'], $data['phone'], $data['email'], $id)) {
            return ['ok' => false, 'message' => 'Tên, số điện thoại hoặc email đã tồn tại.'];
        }

        $this->repo->update($id, $data);

        return ['ok' => true, 'message' => "Cập nhật đối tác \"{$data['name']}\" thành công."];
    }

    // ── Xóa / Ngừng hợp tác ──────────────────────────────────────────────────
    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'ID không hợp lệ.'];
        }

        $supplier = $this->repo->findById($id);
        if (!$supplier) {
            return ['ok' => false, 'message' => 'Không tìm thấy đối tác.'];
        }

        if ($supplier['status'] === 'active') {
            $this->repo->setStatus($id, 'inactive');
            return ['ok' => true, 'message' => 'Đối tác đã được đánh dấu ngừng hợp tác.'];
        }

        if ($this->repo->hasLinkedProducts($id) || $this->repo->hasLinkedInbounds($id)) {
            return ['ok' => false, 'message' => 'Không thể xóa vĩnh viễn — đối tác này có dữ liệu liên quan.'];
        }

        $this->repo->softDelete($id);
        return ['ok' => true, 'message' => 'Đã xóa đối tác vĩnh viễn.'];
    }

    // ── Kiểm tra trùng lặp (AJAX) ────────────────────────────────────────────
    public function checkUnique(string $field, string $value, int $excludeId = 0): bool
    {
        if ($value === '') return false;
        return $this->repo->existsByField($field, trim($value), $excludeId);
    }

    // ── AJAX helpers ─────────────────────────────────────────────────────────
    public function getProducts(int $supplierId): array
    {
        if (!$this->verifyOwnership($supplierId)) return [];
        return $this->repo->getProducts($supplierId);
    }

    public function getInboundHistory(int $supplierId, string $range = 'all'): array
    {
        if (!$this->verifyOwnership($supplierId)) return [];
        return $this->repo->getInboundHistory($supplierId, $range);
    }

    public function getTotalImport(int $supplierId): float
    {
        if (!$this->verifyOwnership($supplierId)) return 0.0;
        return $this->repo->getTotalImport($supplierId);
    }

    public function getDetail(int $supplierId): array|false
    {
        return $this->repo->findByIdWithDebt($supplierId);
    }

    // ── Helpers nội bộ ───────────────────────────────────────────────────────
    private function verifyOwnership(int $supplierId): bool
    {
        return (bool) $this->repo->findById($supplierId);
    }

    private function sanitize(array $input): array
    {
        $status = in_array($input['status'] ?? '', ['active', 'inactive'], true)
            ? $input['status']
            : 'active';

        $entityOrigin = in_array($input['entity_origin'] ?? '', ['domestic', 'fdi'], true)
            ? $input['entity_origin']
            : 'domestic';

        $countryCode = trim((string) ($input['country_code'] ?? 'VN'));
        if ($countryCode === '') {
            $countryCode = 'VN';
        }
        $isVietnam = strtoupper($countryCode) === 'VN';

        return [
            'name'           => trim($input['name']      ?? ''),
            'phone'          => trim($input['phone']     ?? ''),
            'email'          => trim($input['email']     ?? ''),
            'tax_code'       => trim($input['tax_code']  ?? ''),
            'address'        => trim($input['address']   ?? ''),
            'status'         => $status,
            'entity_origin'  => $entityOrigin,
            'country_code'   => $countryCode,
            // Tỉnh/phường (danh mục VN) chỉ có ý nghĩa khi địa chỉ ở VN
            'province_code'  => $isVietnam && isset($input['province_code']) && trim((string) $input['province_code']) !== ''
                                 ? trim((string) $input['province_code']) : null,
            'ward_code'      => $isVietnam && isset($input['ward_code']) && trim((string) $input['ward_code']) !== ''
                                 ? trim((string) $input['ward_code'])     : null,
            // Các trường tự do chỉ áp dụng khi địa chỉ ở nước ngoài
            'city'           => !$isVietnam ? (trim((string) ($input['city']           ?? '')) ?: null) : null,
            'state_province' => !$isVietnam ? (trim((string) ($input['state_province'] ?? '')) ?: null) : null,
            'postal_code'    => !$isVietnam ? (trim((string) ($input['postal_code']    ?? '')) ?: null) : null,
        ];
    }
}