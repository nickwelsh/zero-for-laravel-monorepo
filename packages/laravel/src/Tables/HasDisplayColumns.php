<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Support\Str;
use InvalidArgumentException;

trait HasDisplayColumns
{
    /** @var array<int|string, string>|null */
    private ?array $display = null;

    /** Ordered field names, or field => heading. Use the relation path for a declared lookup.
     * @param  array<int|string, string>  $fields
     */
    public function displayColumns(array $fields): self
    {
        $this->display = $fields;

        return $this;
    }

    public function version(): int
    {
        return $this->display === null && $this->relations === [] ? 2 : 3;
    }

    /** @return list<array{field: string, label: string}> */
    public function displayCapabilities(): array
    {
        $allowed = [];
        foreach ($this->base->model->columns as $field => $column) {
            if ($this->display !== null || $column['type'] !== 'json') {
                $allowed[$field] = Str::headline($field);
            }
        }

        foreach ($this->relations as $name => $relation) {
            if ($relation['declaration']->lookup !== null) {
                $allowed[$name] = $relation['declaration']->label;
            }
        }

        $columns = [];
        foreach ($this->display ?? array_keys($allowed) as $key => $value) {
            $field = is_int($key) ? $value : $key;
            if (! isset($allowed[$field]) || isset($columns[$field]) || $value === '') {
                throw new InvalidArgumentException('Unknown, unlabeled or duplicate display column: '.$field.'.');
            }

            $columns[$field] = ['field' => $field, 'label' => is_int($key) ? $allowed[$field] : $value];
        }

        return array_values($columns);
    }
}
