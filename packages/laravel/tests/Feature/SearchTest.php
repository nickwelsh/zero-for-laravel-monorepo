<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;
use ZeroForLaravel\Search\ScoutSearch;
use ZeroForLaravel\Search\TypesenseFilter;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);
class SearchProjection extends Model
{
    public function searchableAs(): string
    {
        return 'scoped_records';
    }

    /** @return array<string, mixed> */
    public function typesenseCollectionSchema(): array
    {
        return ['fields' => [['name' => 'id', 'type' => 'string'], ['name' => 'title', 'type' => 'string'], ['name' => 'tags', 'type' => 'string[]', 'facet' => true], ['name' => 'created_at', 'type' => 'int64']]];
    }
}

test('Scout model schema overrides config and public metadata excludes credentials', function (): void {
    config(['scout.typesense.model-settings.'.SearchProjection::class => ['collection-schema' => ['fields' => []], 'search-parameters' => ['query_by' => 'title']], 'scout.typesense.client-settings.api_key' => 'private-key']);
    $search = ScoutSearch::for(SearchProjection::class)->sorts('created_at:desc');
    $metadata = $search->metadata();
    expect($metadata['facets'])->toBe(['tags'])->and($metadata['index'])->toBe('scoped_records')->and(json_encode($metadata))->not->toContain('private-key');
    expect(fn (): string => $search->filter(new ZeroContext(['user_id' => 'alice'])))->toThrow(LogicException::class);
});
test('search filter grammar permits facet groups and rejects server scope escape', function (): void {
    $parser = new TypesenseFilter;
    $fields = ['tags' => 'string[]', 'created_at' => 'int64'];
    expect($parser->validate('(tags:=[`work`,`reading`] || tags:=`other`) && created_at:>=1', $fields))->toContain('created_at:>=1');
    foreach (['tags:=`x`) || tags:!=`none`', 'tags:=`x` ||', 'user_id:=alice', '(tags:=`x`', 'tags:=`unclosed'] as $input) {
        expect(fn (): string => $parser->validate($input, $fields))->toThrow(ValidationException::class);
    }
});
