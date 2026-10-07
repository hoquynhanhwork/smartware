<?php
// app/Repositories/CategoryRepository.php

namespace App\Repositories;

use App\Contracts\CategoryRepositoryInterface;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    public function listWithCount(): array
    {
        return $this->fetchAll("
            SELECT c.id, c.name, c.description, c.need_expiry_tracking,
                   COALESCE(COUNT(p.id), 0) AS product_count
            FROM   categories c
            LEFT JOIN products p
                ON  p.category_id = c.id
                AND p.deleted_at  IS NULL
            WHERE  c.deleted_at IS NULL
            GROUP BY c.id, c.name, c.description, c.need_expiry_tracking
            ORDER BY c.name ASC
        ");
    }

    public function listActive(): array
    {
        return $this->fetchAll("
            SELECT id, name FROM categories
            WHERE  deleted_at IS NULL
            ORDER BY name ASC
        ");
    }

    public function findById(int $id): array|false
    {
        return $this->fetchOne("
            SELECT * FROM categories
            WHERE  id = :id AND deleted_at IS NULL
        ", [':id' => $id]);
    }

    /** Trùng tên (không phân biệt hoa thường, bỏ khoảng trắng đầu/cuối), chỉ xét danh mục chưa xóa. */
    public function existsByName(string $name, int $excludeId = 0): bool
    {
        return (bool) $this->fetchColumn("
            SELECT EXISTS (
                SELECT 1 FROM categories
                WHERE  deleted_at IS NULL
                  AND  LOWER(TRIM(name)) = LOWER(TRIM(:name))
                  AND  id <> :exclude
            )
        ", [':name' => $name, ':exclude' => $excludeId]);
    }

    public function create(array $data): int
    {
        return $this->insertReturningId("
            INSERT INTO categories
                (name, description, need_expiry_tracking, created, updated)
            VALUES
                (:name, :description, :need_expiry, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ", [
            ':name'        => $data['name'],
            ':description' => $data['description'] ?? '',
            // Mặc định có theo dõi hạn dùng để không bỏ sót hàng có hạn.
            ':need_expiry' => ($data['need_expiry_tracking'] ?? true) ? 'true' : 'false',
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->execute("
            UPDATE categories
            SET    name        = :name,
                   description = :description,
                   need_expiry_tracking = COALESCE(CAST(:need_expiry AS boolean), need_expiry_tracking),
                   updated     = CURRENT_TIMESTAMP
            WHERE  id         = :id
              AND  deleted_at IS NULL
        ", [
            ':name'        => $data['name'],
            ':description' => $data['description'] ?? '',
            // Không truyền cờ thì giữ nguyên giá trị cũ.
            ':need_expiry' => array_key_exists('need_expiry_tracking', $data)
                                ? ($data['need_expiry_tracking'] ? 'true' : 'false')
                                : null,
            ':id'          => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function softDelete(int $id): void
    {
        $this->execute("
            UPDATE categories
            SET    deleted_at = CURRENT_TIMESTAMP
            WHERE  id = :id
        ", [':id' => $id]);
    }

    public function hasProducts(int $id): bool
    {
        return $this->countProducts($id) > 0;
    }

    public function countProducts(int $id): int
    {
        return (int) $this->fetchColumn("
            SELECT COUNT(*) FROM products
            WHERE  category_id = :id AND deleted_at IS NULL
        ", [':id' => $id]);
    }
}