<?php

namespace Spatie\EloquentSortable;

use Illuminate\Database\Eloquent\Builder;

interface Sortable
{
    /**
     * Modify the order column value.
     */
    public function setHighestOrderNumber(): void;

    public function setHighestOrderNumberAsRelationship(): void;

    public function getHighestOrderNumber(): int;

    /**
     * Let's be nice and provide an ordered scope.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function scopeOrdered(Builder $query);

    /**
     * This function reorders the records: the record with the first id in the array
     * will get order 1, the record with the second it will get order 2,...
     *
     * @param array|\ArrayAccess $ids
     * @param int $startOrder
     */
    public static function setNewOrder($ids, int $startOrder = 1): void;

    /**
     * Determine if the order column should be set when saving a new model instance.
     */
    public function shouldSortWhenCreating(): bool;

    public function moveOrderDown(): static;

    public function moveOrderUp(): static;

    public function moveToStart(): static;

    public function moveToEnd(): static;

    public function isLastInOrder(): bool;

    public function isFirstInOrder(): bool;

    public function getActualRank(): int;

    public function isRank(int $rank): bool;

    public function saveNewOrder(int $newOrder): void;

    public function swapOrderWithModel(Sortable $otherModel): static;

    public static function swapOrder(Sortable $model, Sortable $otherModel): void;
}
