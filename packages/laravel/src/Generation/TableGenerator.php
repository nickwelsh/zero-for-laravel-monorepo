<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use ZeroForLaravel\Tables\TableDefinition;

/** Small public descriptors; native queries still use the normal Zero transport. */
final class TableGenerator
{
    /** @param list<TableDefinition> $tables
     * @return array<string, string>
     */
    public function typescript(array $tables, string $schemaImport): array
    {
        $files = [];
        foreach ($tables as $definition) {
            $metadata = json_encode($definition->capabilities(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $name = json_encode($definition->queryName(), JSON_THROW_ON_ERROR);
            $model = json_encode($definition->table->base->model->name, JSON_THROW_ON_ERROR);
            $base = $definition->expression->emit(true);
            $files['tables/'.$definition->name.'.ts'] = PublicApiGenerator::HEADER."\n"
                ."import {createBuilder, defineQueriesWithType, defineQueryWithType} from '@rocicorp/zero';\n"
                ."import {liveQuery, encodeDate} from '@nickwelsh/zero-for-laravel';\n"
                ."import {createTable, applyTableQuery, type TableQueryInput} from '@nickwelsh/zero-table';\n"
                .'import {schema, definitions, type Context} from '.json_encode($schemaImport, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).";\n"
                ."const capabilities = {$metadata} as const;\n"
                ."const zql = createBuilder(schema);\n"
                ."const defineQuery = defineQueryWithType<typeof schema, Context>();\n"
                ."const defineQueries = defineQueriesWithType<typeof schema>();\n"
                ."export const queries = defineQueries({[{$name}]: defineQuery(({args, ctx}: {args: TableQueryInput<typeof capabilities>; ctx: Context}) => applyTableQuery({$base}, args, capabilities))});\n"
                ."export default createTable(capabilities, args => liveQuery(queries[{$name}], args, definitions, {$model}, false, capabilities.pageSize));\n";
        }

        return $files;
    }
}
