<?php

namespace Spatie\EloquentSortable;

use ArrayAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

trait SortableTrait
{
    public static function bootSortableTrait()
    {
        static::creating(function ($model) {
            if ($model instanceof Sortable && $model->shouldSortWhenCreating()) {
                $model->setHighestOrderNumber();
            }
        });
        static::created(function ($model) {
            if ($model instanceof Sortable && $model->shouldSortWhenCreating()) {
                $model->setHighestOrderNumberAsRelationship();
            }
        });
    }

    public function setHighestOrderNumber(): void
    {
        $relationship = $this->determineOrderRelationshipName();
        if ($relationship !== null) {
            return;
        }

        $orderColumnName = $this->determineOrderColumnName();

        $this->{$orderColumnName} = $this->getHighestOrderNumber() + 1;
    }

    public function setHighestOrderNumberAsRelationship(): void
    {
        $relationship = $this->determineOrderRelationshipName();
        if ($relationship === null) {
            return;
        }
        $orderColumnName = $this->determineOrderColumnName();

        $newValue = $this->getHighestOrderNumber() + 1;

        $this->{$relationship}()->create([$orderColumnName => $newValue]);
    }

    public function getHighestOrderNumber(): int
    {
        return (int)$this->buildSortQuery()->max($this->determineOrderColumnName());
    }

    public function getLowestOrderNumber(): int
    {
        return (int)$this->buildSortQuery()->min($this->determineOrderColumnName());
    }

    public function scopeOrdered(Builder $query, string $direction = 'asc')
    {
        $relationship = $this->determineOrderRelationshipName();

        $query->orderBy($this->determineOrderColumnName(), $direction);

        return $relationship === null
            ? $query
            : $query->joinRelationship($relationship)
                ->addSelect([
                    $this->{$relationship}()->qualifyColumn($this->determineOrderColumnName())
                ]);
    }

    public static function setNewOrder(
        $ids,
        int $startOrder = 1,
        ?string $primaryKeyColumn = null,
        ?callable $modifyQuery = null
    ): void {
        if (!is_array($ids) && !$ids instanceof \ArrayAccess) {
            throw new \InvalidArgumentException('You must pass an array or ArrayAccess object to setNewOrder');
        }

        $model = new static();

        $orderColumnName = $model->determineOrderColumnName();

        if (is_null($primaryKeyColumn)) {
            $primaryKeyColumn = $model->getQualifiedKeyName();
        }

        if (config('eloquent-sortable.ignore_timestamps', false)) {
            static::$ignoreTimestampsOn = array_values(array_merge(static::$ignoreTimestampsOn, [static::class]));
        }

        foreach ($ids as $id) {
            $model->buildSortQuery()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->when(is_callable($modifyQuery), function ($query) use ($modifyQuery) {
                    return $modifyQuery($query);
                })
                ->where($primaryKeyColumn, $id)
                ->update([$orderColumnName => $startOrder++]);
        }

        Event::dispatch(new EloquentModelSortedEvent(static::class));

        if (config('eloquent-sortable.ignore_timestamps', false)) {
            static::$ignoreTimestampsOn = array_values(array_diff(static::$ignoreTimestampsOn, [static::class]));
        }
    }

    public static function setNewOrderByCustomColumn(string $primaryKeyColumn, $ids, int $startOrder = 1)
    {
        self::setNewOrder($ids, $startOrder, $primaryKeyColumn);
    }

    public function determineOrderColumnName(): string
    {
        return $this->sortable['order_column_name'] ?? config('eloquent-sortable.order_column_name', 'order_column');
    }

    /**
     * Determine if the order column should be set when saving a new model instance.
     */
    public function shouldSortWhenCreating(): bool
    {
        return $this->sortable['sort_when_creating'] ?? config('eloquent-sortable.sort_when_creating', true);
    }

    public function moveOrderDown(): static
    {
        $orderColumnName = $this->determineOrderColumnName();

        $swapWithModel = $this->buildSortQuery()->limit(1)
            ->ordered()
            ->where($orderColumnName, '>', $this->getActualRank())
            ->first();

        if (!$swapWithModel) {
            return $this;
        }

        return $this->swapOrderWithModel($swapWithModel);
    }

    public function moveOrderUp(): static
    {
        $orderColumnName = $this->determineOrderColumnName();

        $swapWithModel = $this->buildSortQuery()->limit(1)
            ->ordered('desc')
            ->where($orderColumnName, '<', $this->getActualRank())
            ->first();

        if (!$swapWithModel) {
            return $this;
        }

        return $this->swapOrderWithModel($swapWithModel);
    }

    public function swapOrderWithModel(Sortable $otherModel): static
    {
        $oldOrderOfOtherModel = $otherModel->getActualRank();

        $otherModel->saveNewOrder($this->getActualRank());

        $this->saveNewOrder($oldOrderOfOtherModel);

        return $this;
    }

    public static function swapOrder(Sortable $model, Sortable $otherModel): void
    {
        $model->swapOrderWithModel($otherModel);
    }

    public function moveToStart(): static
    {
        if ($this->isFirstInOrder()) {
            return $this;
        }

        $orderColumnName = $this->determineOrderColumnName();
        $oldRank         = $this->getActualRank();

        $this->saveNewOrder($this->getLowestOrderNumber());

        $this->buildSortQuery()
            ->where($this->getQualifiedKeyName(), '!=', $this->getKey())
            ->where($orderColumnName, '<=', $oldRank)
            ->increment($orderColumnName);

        return $this;
    }

    public function moveToEnd(): static
    {
        if ($this->isLastInOrder()) {
            return $this;
        }

        $orderColumnName = $this->determineOrderColumnName();
        $oldOrder        = $this->getActualRank();

        $this->saveNewOrder($this->getHighestOrderNumber());

        $this->buildSortQuery()
            ->where($this->getQualifiedKeyName(), '!=', $this->getKey())
            ->where($orderColumnName, '>', $oldOrder)
            ->decrement($orderColumnName);

        return $this;
    }

    public function isLastInOrder(): bool
    {
        return $this->isRank($this->getHighestOrderNumber());
    }

    public function isFirstInOrder(): bool
    {
        return $this->isRank($this->getLowestOrderNumber());
    }

    public function buildSortQuery(): Builder
    {
        $relationship = $this->determineOrderRelationshipName();
        $query        = static::query();

        return $relationship === null ? $query : $query->joinRelationship($relationship);
    }

    public function determineOrderRelationshipName(): ?string
    {
        return $this->sortable['order_relationship'] ?? config('eloquent-sortable.order_relationship', null);
    }

    public function getActualRank(): int
    {
        $orderColumnName = $this->determineOrderColumnName();
        $relationship    = $this->determineOrderRelationshipName();

        return $relationship === null ? $this->{$orderColumnName} : $this->{$relationship}->{$orderColumnName};
    }

    public function isRank(int $rank): bool
    {
        return (int) $this->getActualRank() === $rank;
    }

    public function saveNewOrder(int $newOrder): void
    {
        $relationship    = $this->determineOrderRelationshipName();
        $orderColumnName = $this->determineOrderColumnName();

        if ($relationship === null) {
            $this->{$orderColumnName} = $newOrder;
            $this->save();
        } else {
            $this->{$relationship}->update([$orderColumnName => $newOrder]);
        }
    }
}
