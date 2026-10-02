<?php
// app/Repositories/SupplierRepository.php

namespace App\Repositories;

use PDO;
use App\Contracts\SupplierRepositoryInterface;

class SupplierRepository extends BaseRepository implements SupplierRepositoryInterface
{
    private const DEFAULT_COUNTRY_CODE = 'VN';

    private const ADDRESS_COLUMNS = "
        a.address_line1  AS address,
        a.province_code,
        a.ward_code,
        a.city,
        a.state_province,
        a.postal_code,
        a.country_code
    ";

    private const ADDRESS_JOIN = "
        LEFT JOIN (
            SELECT supplier_id, address_line1, province_code, ward_code,
                   city, state_province, postal_code, country_code
            FROM   addresses
            WHERE  is_default = TRUE
        ) a ON a.supplier_id = s.id
    ";

    // ── Danh sách + phân trang ────────────────────────────────────────────────
    public function list(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $lim = (int) $limit;
        $off = (int) $offset;

        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   " . self::ADDRESS_COLUMNS . ",
                   COALESCE(si_count.total, 0)  AS inbound_count,
                   COALESCE(si_count.amount, 0) AS total_import
            FROM   suppliers s
            " . self::ADDRESS_JOIN . "
            LEFT JOIN (
                SELECT supplier_id,
                       COUNT(*)          AS total,
                       SUM(total_amount) AS amount
                FROM   stock_inbounds
                WHERE  deleted_at IS NULL
                GROUP BY supplier_id
            ) si_count ON si_count.supplier_id = s.id
            WHERE  {$where}
            ORDER BY s.name ASC
            LIMIT  {$lim} OFFSET {$off}
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        return (int) $this->fetchColumn("
            SELECT COUNT(*)
            FROM   suppliers s
            " . self::ADDRESS_JOIN . "
            WHERE  {$where}
        ", $params);
    }

    public function listActive(): array
    {
        return $this->fetchAll("
            SELECT id, name FROM suppliers
            WHERE  status     = 'active'
              AND  deleted_at IS NULL
            ORDER BY name ASC
        ");
    }

    // ── Thống kê ──────────────────────────────────────────────────────────────
    public function countAll(): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM suppliers
            WHERE  deleted_at IS NULL
        ");
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM suppliers
            WHERE  status = :status AND deleted_at IS NULL
        ", [':status' => $status]);
    }

    // ── Tìm 1 bản ghi ─────────────────────────────────────────────────────────
    public function findById(int $id): array|false
    {
        return $this->fetchOne("
            SELECT * FROM suppliers
            WHERE  id = :id AND deleted_at IS NULL
        ", [':id' => $id]);
    }

    public function findByIdWithDebt(int $id): array|false
    {
        return $this->fetchOne("
            SELECT s.*,
                   " . self::ADDRESS_COLUMNS . ",
                   COALESCE(si.total_amount, 0)  AS total_import,
                   COALESCE(si.inbound_count, 0) AS inbound_count
            FROM   suppliers s
            " . self::ADDRESS_JOIN . "
            LEFT JOIN (
                SELECT supplier_id,
                       SUM(total_amount) AS total_amount,
                       COUNT(*)          AS inbound_count
                FROM   stock_inbounds
                WHERE  deleted_at IS NULL
                GROUP BY supplier_id
            ) si ON si.supplier_id = s.id
            WHERE  s.id = :id AND s.deleted_at IS NULL
        ", [':id' => $id]);
    }

    // ── Địa chỉ ───────────────────────────────────────────────────────────────
    public function getDefaultAddress(int $supplierId): array|false
    {
        return $this->fetchOne("
            SELECT address_line1 AS address, province_code, ward_code,
                   city, state_province, postal_code, country_code
            FROM   addresses
            WHERE  supplier_id = :sid AND is_default = TRUE
            LIMIT  1
        ", [':sid' => $supplierId]);
    }

    // ── Ghi ───────────────────────────────────────────────────────────────────
    public function create(array $data): int
    {
        $this->pdo->beginTransaction();
        try {
            $id = $this->insertReturningId("
                INSERT INTO suppliers
                    (name, phone, email, tax_code, status, entity_origin)
                VALUES
                    (:name, :phone, :email, :tax_code, :status, :entity_origin)
                RETURNING id
            ", [
                ':name'          => $data['name'],
                ':phone'         => $data['phone']         ?? null,
                ':email'         => $data['email']         ?? null,
                ':tax_code'      => $data['tax_code']      ?: null,
                ':status'        => $data['status']        ?? 'active',
                ':entity_origin' => $data['entity_origin'] ?? 'domestic',
            ]);

            $this->upsertDefaultAddress($id, $data);

            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->execute("
                UPDATE suppliers
                SET    name          = :name,
                       phone         = :phone,
                       email         = :email,
                       tax_code      = :tax_code,
                       status        = :status,
                       entity_origin = :entity_origin,
                       updated       = CURRENT_TIMESTAMP
                WHERE  id = :id AND deleted_at IS NULL
            ", [
                ':name'          => $data['name'],
                ':phone'         => $data['phone']         ?? null,
                ':email'         => $data['email']         ?? null,
                ':tax_code'      => $data['tax_code']      ?: null,
                ':status'        => $data['status'],
                ':entity_origin' => $data['entity_origin'] ?? 'domestic',
                ':id'            => $id,
            ]);

            $this->upsertDefaultAddress($id, $data);

            $this->pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function setStatus(int $id, string $status): void
    {
        $this->execute("
            UPDATE suppliers SET status = :status, updated = CURRENT_TIMESTAMP
            WHERE  id = :id
        ", [':status' => $status, ':id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->execute("
            UPDATE suppliers SET deleted_at = CURRENT_TIMESTAMP
            WHERE  id = :id
        ", [':id' => $id]);
    }

    // ── Kiểm tra trùng lặp ────────────────────────────────────────────────────
    public function existsByField(string $field, string $value, int $excludeId = 0): bool
    {
        $allowed = ['tax_code', 'email', 'phone'];
        if (!in_array($field, $allowed, true)) {
            return false;
        }

        $sql    = "SELECT 1 FROM suppliers WHERE {$field} = :val AND deleted_at IS NULL";
        $params = [':val' => $value];

        if ($excludeId > 0) {
            $sql               .= " AND id != :exclude";
            $params[':exclude']  = $excludeId;
        }

        return (bool) $this->fetchColumn($sql . " LIMIT 1", $params);
    }

    public function existsByContactInfo(
        string $name,
        string $phone,
        string $email,
        int    $excludeId = 0
    ): bool {
        // Chỉ kiểm tra trùng với các trường KHÔNG rỗng, tránh '' = ''
        // khớp nhầm với các NCC khác cũng để trống phone/email.
        $conditions = ["name = :name"];
        $params     = [':name' => $name];

        if ($phone !== '') {
            $conditions[]     = "phone = :phone";
            $params[':phone'] = $phone;
        }
        if ($email !== '') {
            $conditions[]     = "email = :email";
            $params[':email'] = $email;
        }

        $sql = "SELECT 1 FROM suppliers
                WHERE  (" . implode(' OR ', $conditions) . ")
                  AND  deleted_at IS NULL";

        if ($excludeId > 0) {
            $sql               .= " AND id != :exclude";
            $params[':exclude']  = $excludeId;
        }

        return (bool) $this->fetchColumn($sql . " LIMIT 1", $params);
    }

    // ── Ràng buộc xóa ─────────────────────────────────────────────────────────
    public function hasLinkedProducts(int $supplierId): bool
    {
        return (bool) $this->fetchColumn("
            SELECT 1 FROM products
            WHERE  supplier_id = :sid AND deleted_at IS NULL LIMIT 1
        ", [':sid' => $supplierId]);
    }

    public function hasLinkedInbounds(int $supplierId): bool
    {
        return (bool) $this->fetchColumn("
            SELECT 1 FROM stock_inbounds
            WHERE  supplier_id = :sid AND deleted_at IS NULL LIMIT 1
        ", [':sid' => $supplierId]);
    }

    // ── AJAX helpers ──────────────────────────────────────────────────────────
    public function getProducts(int $supplierId): array
    {
        return $this->fetchAll("
            SELECT p.id, p.name, p.sku, p.unit, p.cost_price, p.status,
                   COALESCE(SUM(ib.quantity), 0) AS total_stock
            FROM   products p
            LEFT JOIN inventory_batches ib ON ib.product_id = p.id
            WHERE  p.supplier_id  = :sid
              AND  p.deleted_at   IS NULL
            GROUP BY p.id, p.name, p.sku, p.unit, p.cost_price, p.status
            ORDER BY p.name ASC
        ", [':sid' => $supplierId]);
    }

    public function getInboundHistory(int $supplierId, string $range = 'all'): array
    {
        $params     = [':sid' => $supplierId];
        $dateFilter = '';

        $intervals = ['7d' => '7 days', '30d' => '30 days', '90d' => '90 days', '1y' => '1 year'];
        if (isset($intervals[$range])) {
            $days       = $intervals[$range];
            $dateFilter = "AND si.created >= public.app_now() - INTERVAL '{$days}'";
        }

        return $this->fetchAll("
            SELECT si.id, si.ref_no, si.created::text AS created,
                   si.total_amount, si.status
            FROM   stock_inbounds si
            WHERE  si.supplier_id = :sid
              AND  si.deleted_at  IS NULL
              {$dateFilter}
            ORDER BY si.created DESC
            LIMIT 50
        ", $params);
    }

    public function getTotalImport(int $supplierId): float
    {
        return (float) $this->fetchColumn("
            SELECT COALESCE(SUM(total_amount), 0)
            FROM   stock_inbounds
            WHERE  supplier_id = :sid
              AND  deleted_at  IS NULL
        ", [':sid' => $supplierId]);
    }

    // ── Helpers nội bộ ────────────────────────────────────────────────────────
    private function buildWhere(array $filters): array
    {
        $cond   = ["s.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['statuses'])) {
            $ph = [];
            foreach ($filters['statuses'] as $i => $val) {
                $key          = ":st_{$i}";
                $ph[]         = $key;
                $params[$key] = $val;
            }
            $cond[] = "s.status IN (" . implode(',', $ph) . ")";
        }

        if (!empty($filters['entity_origins'])) {
            $ph = [];
            foreach ($filters['entity_origins'] as $i => $val) {
                $key          = ":eo_{$i}";
                $ph[]         = $key;
                $params[$key] = $val;
            }
            $cond[] = "s.entity_origin IN (" . implode(',', $ph) . ")";
        }

        if (!empty($filters['province_code'])) {
            $cond[]              = "a.province_code = :province";
            $params[':province'] = $filters['province_code'];
        }

        if (!empty($filters['ward_code'])) {
            $cond[]           = "a.ward_code = :ward";
            $params[':ward']  = $filters['ward_code'];
        }

        if (!empty($filters['from_date'])) {
            $cond[]                = "s.created >= :from_date";
            $params[':from_date']  = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $cond[]              = "s.created <= :to_date";
            $params[':to_date']  = $filters['to_date'];
        }

        if (!empty($filters['has_transactions'])) {
            if (in_array('yes', $filters['has_transactions'], true)) {
                $cond[] = "EXISTS (SELECT 1 FROM stock_inbounds si WHERE si.supplier_id = s.id AND si.deleted_at IS NULL)";
            } elseif (in_array('no', $filters['has_transactions'], true)) {
                $cond[] = "NOT EXISTS (SELECT 1 FROM stock_inbounds si WHERE si.supplier_id = s.id AND si.deleted_at IS NULL)";
            }
        }

        return [implode(' AND ', $cond), $params];
    }

    /**
     * Tạo mới / cập nhật địa chỉ mặc định của supplier.
     * Nếu không có dữ liệu địa chỉ nào được nhập → xóa địa chỉ mặc định cũ
     * (nếu có), tránh lưu dòng rỗng vì address_line1 là NOT NULL.
     */
    private function upsertDefaultAddress(int $supplierId, array $data): void
    {
        $addressLine1  = trim($data['address'] ?? '');
        $provinceCode  = $data['province_code']  ?? null;
        $wardCode      = $data['ward_code']      ?? null;
        $countryCode   = $data['country_code']   ?? self::DEFAULT_COUNTRY_CODE;
        $city          = $data['city']           ?? null;
        $stateProvince = $data['state_province'] ?? null;
        $postalCode    = $data['postal_code']    ?? null;

        $existing = $this->fetchOne("
            SELECT id FROM addresses
            WHERE  supplier_id = :sid AND is_default = TRUE
            LIMIT  1
        ", [':sid' => $supplierId]);

        $hasAnyAddressData = $addressLine1 !== ''
            || $provinceCode || $wardCode || $city || $stateProvince || $postalCode;

        if (!$hasAnyAddressData) {
            if ($existing) {
                $this->execute("DELETE FROM addresses WHERE id = :id", [':id' => $existing['id']]);
            }
            return;
        }

        $params = [
            ':line1'    => $addressLine1,
            ':province' => $provinceCode,
            ':ward'     => $wardCode,
            ':country'  => $countryCode,
            ':city'     => $city,
            ':state'    => $stateProvince,
            ':postal'   => $postalCode,
        ];

        if ($existing) {
            $this->execute("
                UPDATE addresses
                SET    address_line1  = :line1,
                       province_code  = :province,
                       ward_code      = :ward,
                       country_code   = :country,
                       city           = :city,
                       state_province = :state,
                       postal_code    = :postal,
                       updated        = CURRENT_TIMESTAMP
                WHERE  id = :id
            ", $params + [':id' => $existing['id']]);
            return;
        }

        $this->execute("
            INSERT INTO addresses
                (supplier_id, address_type, address_line1, province_code, ward_code,
                 country_code, city, state_province, postal_code, is_default)
            VALUES
                (:sid, 'default', :line1, :province, :ward, :country, :city, :state, :postal, TRUE)
        ", $params + [':sid' => $supplierId]);
    }
}