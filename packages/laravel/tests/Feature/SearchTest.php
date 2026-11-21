<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Search\ScoutSearch;
use ZeroForLaravel\Search\SearchRevision;
use ZeroForLaravel\Search\TypesenseFilter;
use ZeroForLaravel\Tests\TestCase;

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
    $search->register();

    expect(ScoutSearch::registered(SearchProjection::class))->toBe($search);
    $metadata = $search->metadata();
    expect($metadata['facets'])->toBe(['tags'])->and($metadata['index'])->toBe('scoped_records')->and(json_encode($metadata))->not->toContain('private-key');
    expect(fn (): string => $search->filter(new ResolvedContext(['user_id' => 'alice'], 'alice')))->toThrow(LogicException::class);
});
test('search filter grammar permits facet groups and rejects server scope escape', function (): void {
    $parser = new TypesenseFilter;
    $fields = ['tags' => 'string[]', 'created_at' => 'int64'];
    expect($parser->validate('(tags:=[`work`,`reading`] || tags:=`other`) && created_at:>=1', $fields))->toContain('created_at:>=1');
    foreach (['tags:=`x`) || tags:!=`none`', 'tags:=`x` ||', 'user_id:=alice', '(tags:=`x`', 'tags:=`unclosed'] as $input) {
        expect(fn (): string => $parser->validate($input, $fields))->toThrow(ValidationException::class);
    }
});
test('search revisions change only with documents in the actor scope', function (): void {
    config(['app.key' => 'test-search-revision-key']);
    $tenantA = "{\"id\":\"a-1\",\"title\":\"Before\"}\n{\"id\":\"a-2\",\"title\":\"Stable\"}";
    $tenantB = '{"id":"b-1","title":"Before"}';
    $tenantARevision = SearchRevision::current('scoped_records', 'tenant_id:=a', $tenantA);
    $tenantBRevision = SearchRevision::current('scoped_records', 'tenant_id:=b', $tenantB);
    $tenantAAfterTenantBUpdate = SearchRevision::current('scoped_records', 'tenant_id:=a', $tenantA);
    $tenantBAfterUpdate = SearchRevision::current('scoped_records', 'tenant_id:=b', str_replace('Before', 'After', $tenantB));
    $tenantAAfterUpdate = SearchRevision::current('scoped_records', 'tenant_id:=a', str_replace('Before', 'After', $tenantA));
    $tenantAReordered = SearchRevision::current('scoped_records', 'tenant_id:=a', implode("\n", array_reverse(explode("\n", $tenantA))));

    expect($tenantAAfterTenantBUpdate)->toBe($tenantARevision)
        ->and($tenantBAfterUpdate)->not->toBe($tenantBRevision)
        ->and($tenantAAfterUpdate)->not->toBe($tenantARevision)
        ->and($tenantAReordered)->toBe($tenantARevision)
        ->and(SearchRevision::current('scoped_records', 'tenant_id:=b', $tenantA))->not->toBe($tenantARevision);
});
