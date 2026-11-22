<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use LogicException;
use ZeroForLaravel\Query\QueryLimits;
use ZeroForLaravel\Tables\TableDefinition;
use ZeroForLaravel\Tables\TableRelationPath;

/** Small public descriptors; native queries still use the normal Zero transport. */
final class TableGenerator
{
    /** @param list<TableDefinition> $tables
     * @param  list<ControllerQuery>  $registered
     * @return array<string, string>
     */
    public function typescript(array $tables, string $schemaImport, array $registered = []): array
    {
        $files = [];
        foreach ($tables as $definition) {
            $metadata = json_encode($definition->capabilities(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $name = json_encode($definition->queryName(), JSON_THROW_ON_ERROR);
            $model = json_encode($definition->table->base->model->name, JSON_THROW_ON_ERROR);
            $base = $definition->expression->emit(true);
            $scopes = [];
            $queries = [];
            $cases = [];
            $emitter = new NativeQueryEmitter;
            $page = TableRelationPath::pageSize();
            $batch = TableRelationPath::MAX_SELECTED_IDS;
            $cap = QueryLimits::liveWindow();
            foreach (TableRelationPath::all($definition) as $path) {
                $key = json_encode($path->path, JSON_THROW_ON_ERROR);
                $scope = $emitter->relationMembership($path->parent, $path->relation, new QueryExpression('local', 'q', model: $path->target, type: 'query'));
                $scopes[] = "[{$key}]: q => {$scope}";
                if ($path->choices === null) {
                    continue;
                }

                $target = json_encode($path->target->name, JSON_THROW_ON_ERROR);
                $label = json_encode($path->choices['label'], JSON_THROW_ON_ERROR);
                $id = json_encode($path->target->key, JSON_THROW_ON_ERROR);
                $source = $emitter->relationMembership($path->parent, $path->relation, new QueryExpression('query', model: $path->target, type: 'query'));
                $actions = [];
                foreach (['list', 'resolve'] as $operation) {
                    $bindings = TableChoiceBindings::for($path, $operation, $registered);
                    $query = json_encode($path->queryName($definition, $operation, array_keys($bindings)), JSON_THROW_ON_ERROR);
                    $catalog = $source;
                    foreach ($bindings as $binding) {
                        $catalog .= '.where(eb => '.$emitter->condition($binding->expression).')';
                    }

                    $parameters = $this->parameters($catalog);
                    if ($operation === 'list') {
                        $fields = array_values(array_unique([$path->choices['label'], $path->target->key]));
                        $cursorFields = json_encode($fields, JSON_THROW_ON_ERROR);
                        $order = '';
                        $cursorType = '';
                        foreach ($fields as $field) {
                            $key = json_encode($field, JSON_THROW_ON_ERROR);
                            $order .= ".orderBy({$key}, args.__reverse ? 'desc' : 'asc')";
                            $cursorType .= $key.': '.$path->target->columns[$field]['type'].'; ';
                        }

                        $queries[] = <<<TS
[{$query}]: defineQuery(({{$parameters}}: {args: {search: string; __cursor?: {{$cursorType}}; __reverse?: boolean; __generation?: string}; ctx: Context}) => {
    if (typeof args.search !== 'string' || [...args.search].length > 256) throw new RangeError('Invalid choice listing');
    const page = {$catalog}.where({$label}, 'ILIKE', optionContains(args.search)){$order}.limit({$page} + 1);
    return args.__cursor === undefined ? page : page.start(args.__cursor);
})
TS;
                        $actions[] = "if (input.operation === 'list') return liveQuery(queries[{$query}], {search: input.search}, definitions, {$target}, false, {$page}, {$cursorFields}, {$cap}, {mode: 'cursor', name: 'choices'});";
                    } else {
                        $type = $path->target->columns[$path->target->key]['type'];
                        $valid = $type === 'number' ? "typeof id === 'number' && Number.isFinite(id)" : "typeof id === 'string' && id.length > 0 && [...id].length <= 1000";
                        $queries[] = <<<TS
[{$query}]: defineQuery(({{$parameters}}: {args: {ids: readonly (string | number)[]; __generation?: string}; ctx: Context}) => {
    const ids = args.ids;
    if (!ids.length || ids.length > {$batch} || !ids.every((id): id is {$type} => {$valid})) throw new RangeError('Invalid selected IDs');
    return {$catalog}.where({$id}, 'IN', ids).limit(ids.length);
})
TS;
                        $actions[] = "return liveQuery(queries[{$query}], {ids: input.ids}, definitions, {$target}, false);";
                    }
                }

                $case = json_encode(json_encode(explode('.', $path->path), JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
                $cases[] = "case {$case}: ".implode("\n", $actions);
            }

            $callbacks = $scopes === [] ? '' : ', {'.implode(', ', $scopes).'}';
            $parameters = $this->parameters($base.$callbacks);
            array_unshift($queries, "[{$name}]: defineQuery(({{$parameters}}: {args: TableQueryInput<typeof capabilities>; ctx: Context}) => applyTableQuery({$base}, args, capabilities{$callbacks}))");
            $body = implode(",\n", $queries);
            $dateHelper = str_contains($this->code($body), 'encodeDate(') ? ', encodeDate' : '';
            $optionHelper = $cases === [] ? '' : ', optionContains';
            $options = $cases === [] ? '' : ", (path, input) => {\n switch (JSON.stringify(path)) {\n".implode("\n", $cases)."\n default: throw new Error('Undeclared table choice path');\n }\n}";
            $files['tables/'.$definition->name.'.ts'] = PublicApiGenerator::HEADER."\n"
                ."import {createBuilder, defineQueriesWithType, defineQueryWithType} from '@rocicorp/zero';\n"
                ."import {liveQuery{$dateHelper}{$optionHelper}} from '@nickwelsh/zero-for-laravel';\n"
                ."import {createTable, applyTableQuery, type TableQueryInput} from '@nickwelsh/zero-table';\n"
                .'import {schema, definitions, type Context} from '.json_encode($schemaImport, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).";\n"
                ."const capabilities = {$metadata} as const;\n"
                ."const zql = createBuilder(schema);\n"
                ."const defineQuery = defineQueryWithType<typeof schema, Context>();\n"
                ."const defineQueries = defineQueriesWithType<typeof schema>();\n"
                ."export const queries = defineQueries({{$body}});\n"
                ."export default createTable(capabilities, args => liveQuery(queries[{$name}], args, definitions, {$model}, false, capabilities.pageSize){$options});\n";
        }

        return $files;
    }

    private function code(string $source): string
    {
        return preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $source) ?? throw new LogicException('Cannot inspect generated table helpers.');
    }

    private function parameters(string $expression): string
    {
        return preg_match('/\bctx[.\[]/', $this->code($expression)) === 1 ? 'args, ctx' : 'args';
    }
}
