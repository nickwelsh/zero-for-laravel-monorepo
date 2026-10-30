<?php

declare(strict_types=1);

namespace ZeroForLaravel\Mutation;

use LogicException;
use stdClass;
use ZeroForLaravel\Metadata\ModelMetadata;

/** A declared equality key must be backed by an unconditional database unique index. */
final class UniqueMutationKey
{
    /** @param list<string> $fields */
    public static function validate(ModelMetadata $meta, array $fields, bool $write = false): void
    {
        if ($fields === [] || $meta->deletedAt !== null) {
            throw new LogicException('Unique mutation reads require a nonempty key and a model without soft deletes.');
        }

        foreach ($fields as $field) {
            if (! isset($meta->columns[$field]) || $meta->columns[$field]['nullable'] || ! in_array($meta->columns[$field]['type'], ['string', 'number', 'boolean'], true) || ($write && ! in_array($field, $meta->writable, true))) {
                throw new LogicException('Unique mutation keys require nonnullable scalar columns, writable for inserts.');
            }
        }

        $connection = (new $meta->class)->getConnection();
        $eligible = null;
        if ($connection->getDriverName() === 'pgsql') {
            $eligible = [];
            foreach ($connection->select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = ?::regclass and i.indisunique and i.indisvalid and i.indimmediate and i.indpred is null and i.indexprs is null', [$meta->table]) as $row) {
                if (! $row instanceof stdClass || ! is_string($row->name)) {
                    throw new LogicException('Invalid database index metadata.');
                }

                $eligible[] = $row->name;
            }
        }

        sort($fields);
        foreach ($connection->getSchemaBuilder()->getIndexes($meta->table) as $index) {
            $columns = $index['columns'];
            sort($columns);
            if (! $index['unique'] || $columns !== $fields || ($eligible !== null && ! in_array($index['name'], $eligible, true))) {
                continue;
            }

            if ($connection->getDriverName() === 'sqlite') {
                $row = $connection->selectOne('select sql from sqlite_master where type = ? and name = ?', ['index', $index['name']]);
                if ($row instanceof stdClass && is_string($row->sql) && preg_match('/\bwhere\b/i', $row->sql)) {
                    continue;
                }
            } elseif ($connection->getDriverName() !== 'pgsql') {
                throw new LogicException('Unique mutation writes currently require PostgreSQL or SQLite.');
            }

            return;
        }

        throw new LogicException('Unique mutation key requires an unconditional, immediate unique database constraint.');
    }
}
