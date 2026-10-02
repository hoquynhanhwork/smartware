<?php
// app/Repositories/CategoryRepository.php

namespace App\Repositories;

use App\Contracts\CategoryRepositoryInterface;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    public function listWithCount(): array
    {
        return $this->fetchAll("
            SELECT c.id, c.name, c.description,
                   COALESCE(COUNT(p.id), 0) AS product_count
            FROM   categories c
            LEFT JOIN products p
                ON  p.category_id = c.id
                AND p.deleted_at  IS NULL
            WHERE  c.deleted_at IS NULL
            GROUP BY c.id, c.name, c.description
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

    public function create(array $data): int
    {
        return $this->insertReturningId("
            INSERT INTO categories
                (name, description, created, updated)
            VALUES
                (:name, :description, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ", [
            ':name'        => $data['name'],
            ':description' => $data['description'] ?? '',
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->execute("
            UPDATE categories
            SET    name        = :name,
                   description = :description,
                   updated     = CURRENT_TIMESTAMP
            WHERE  id         = :id
              AND  deleted_at IS NULL
        ", [
            ':name'        => $data['name'],
            ':description' => $data['description'] ?? '',
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