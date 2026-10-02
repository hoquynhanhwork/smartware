<?php
// app/Contracts/CategoryRepositoryInterface.php

namespace App\Contracts;

interface CategoryRepositoryInterface
{
    public function listWithCount(): array;
    public function listActive(): array;
    public function findById(int $id): array|false;

    /** @return int ID bản ghi mới */
    public function create(array $data): int;
    public function update(int $id, array $data): bool;
    public function softDelete(int $id): void;

    public function hasProducts(int $id): bool;
    public function countProducts(int $id): int;
}