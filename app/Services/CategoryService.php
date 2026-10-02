<?php
// app/Services/CategoryService.php

namespace App\Services;

use App\Contracts\CategoryRepositoryInterface;

class CategoryService
{
    public function __construct(
        private readonly CategoryRepositoryInterface $repo,
    ) {}

    public function listWithCount(): array
    {
        return $this->repo->listWithCount();
    }

    public function listActive(): array
    {
        return $this->repo->listActive();
    }

    public function findById(int $id): array|false
    {
        return $this->repo->findById($id);
    }

    public function create(array $data): int
    {
        return $this->repo->create($data);
    }

    public function update(int $id, array $data): bool
    {
        return $this->repo->update($id, $data);
    }

    public function softDelete(int $id): void
    {
        $this->repo->softDelete($id);
    }

    public function hasProducts(int $id): bool
    {
        return $this->repo->hasProducts($id);
    }
}