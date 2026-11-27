<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Surveyor\SurveyorServiceProvider;
use LogicException;
use ReflectionMethod;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\ControllerQueryGenerator;
use ZeroForLaravel\Generation\ZodSchemaDriver;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Tests\Fixtures\OwnerUser;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\OwnerTestCase;
use ZeroForLaravel\Zero;

uses(OwnerTestCase::class);

final class RankedList extends TaskList
{
    protected static function booted(): void
    {
        self::addGlobalScope('rank', function (Builder $query): void {
            $query->orderBy('name');
        });
    }
}

final class PageSizeRequest extends FormRequest
{
    /** @return array{count?: int|string, size: int|string, unbounded: int|string} */
    public function validated($key = null, $default = null): array
    {
        $values = parent::validated($key, $default);
        if (! is_array($values) || ! is_int($values['size'] ?? null) && ! is_string($values['size'] ?? null)
            || ! is_int($values['unbounded'] ?? null) && ! is_string($values['unbounded'] ?? null)) {
            throw new LogicException('Missing validated page sizes.');
        }

        $result = ['size' => $values['size'], 'unbounded' => $values['unbounded']];
        if (is_int($values['count'] ?? null) || is_string($values['count'] ?? null)) {
            $result['count'] = $values['count'];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'count' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'size' => ['required', 'integer', Rule::in([2, 4])],
            'unbounded' => ['required', 'integer'],
        ];
    }
}

final class PaginatedLists
{
    public function numbered(PageSizeRequest $input): ZeroQuery
    {
        $values = $input->validated();

        return TaskList::zeroQuery()->visibleTo($input->user())->orderBy('name', 'desc')->simplePaginate($values['count'] ?? 2, pageName: 'lists');
    }

    public function scroll(PageSizeRequest $input): ZeroQuery
    {
        $values = $input->validated();

        return Zero::scroll(TaskList::zeroQuery()->visibleTo($input->user())->paginate(perPage: $values['size']));
    }

    public function cursor(Request $request): ZeroQuery
    {
        return TaskList::zeroQuery()->visibleTo($request->user())->orderBy('name', 'desc')->cursorPaginate(2, cursorName: 'after');
    }

    public function varyingFields(bool $remove): ZeroQuery
    {
        $query = RankedList::zeroQuery();
        if ($remove) {
            $query->withoutGlobalScope('rank');
        }

        return $query->cursorPaginate(2);
    }

    public function varyingDirections(bool $reverse): ZeroQuery
    {
        $query = RankedList::zeroQuery();
        if ($reverse) {
            $query->withoutGlobalScope('rank')->orderBy('name', 'desc');
        }

        return $query->cursorPaginate(2);
    }

    public function defaultSize(Request $request): ZeroQuery
    {
        return TaskList::zeroQuery()->visibleTo($request->user())->paginate(pageName: 'lists');
    }

    public function unbounded(PageSizeRequest $input): ZeroQuery
    {
        $values = $input->validated();

        return TaskList::zeroQuery()->visibleTo($input->user())->paginate($values['unbounded']);
    }

    public function collision(Request $request): Response
    {
        $lists = TaskList::zeroQuery()->visibleTo($request->user());

        return Inertia::render('lists/index', ['lists' => $lists->paginate(2), 'more' => Zero::scroll($lists->simplePaginate(4))]);
    }
}

beforeEach(function (): void {
    app()->register(SurveyorServiceProvider::class);
    app()->instance(ModelRegistry::class, new ModelRegistry([TaskList::class]));
    config(['zero.limits.max_live_window' => 13]);
    request()->setUserResolver(static fn (): OwnerUser => (new OwnerUser)->forceFill(['id' => 'alice']));
});

function paginatorRegistration(string $method): RegisteredQuery
{
    $definition = resolve(ControllerQueryCompiler::class)->compile(PaginatedLists::class, $method)[0];
    $registrations = eval(substr((new ControllerQueryGenerator(new ZodSchemaDriver))->php([$definition]), 5));
    if (! is_array($registrations) || ! ($registrations[$definition->name()] ?? null) instanceof RegisteredQuery) {
        throw new LogicException('Expected a generated query registration.');
    }

    return $registrations[$definition->name()];
}

test('numbered pages resolve a bounded size and reject prefixes beyond the cap before multiplication', function (): void {
    $registered = paginatorRegistration('numbered');
    $args = ['input' => ['size' => 2, 'unbounded' => 1]];
    $page = $registered->declaration([...$args, '__page' => 4]);
    expect($page->pagination())->toBe(['mode' => 'numbered', 'name' => 'lists'])
        ->and($page->ast()['limit'])->toBe(9)
        ->and($page->ast()['orderBy'])->toBe([['name', 'desc'], ['id', 'asc']]);
    $args['input']['count'] = 3;
    expect($registered->declaration([...$args, '__page' => 4])->ast()['limit'])->toBe(13);
    foreach ([['__page' => 5], ['__page' => PHP_INT_MAX], ['__page' => null], ['__page' => 1.5], ['__page' => '2'], ['__window' => 4], ['__cursor' => ['id' => 'a']]] as $bad) {
        expect(fn (): ZeroQuery => $registered->declaration([...$args, ...$bad]))->toThrow(ValidationException::class);
    }
});

test('scroll includes the cold starting page and one probe and cannot exceed the total prefix budget', function (): void {
    $registered = paginatorRegistration('scroll');
    $args = ['input' => ['size' => 4, 'unbounded' => 1], '__page' => 2];
    $page = $registered->declaration($args);
    expect($page->pagination())->toBe(['mode' => 'scroll', 'name' => 'page'])
        ->and($page->ast()['limit'])->toBe(9)
        ->and($registered->declaration([...$args, '__window' => 13])->ast()['limit'])->toBe(13);
    foreach ([8, 14, null, '12'] as $bad) {
        expect(fn (): ZeroQuery => $registered->declaration([...$args, '__window' => $bad]))->toThrow(ValidationException::class);
    }
});

test('reverse cursor boundaries invert every ordering field but preserve raw exclusive cursor values', function (): void {
    $registered = paginatorRegistration('cursor');
    $cursor = ['name' => 'same', 'id' => 'list-b'];
    $page = $registered->declaration(['__cursor' => $cursor, '__reverse' => true]);
    $ast = $page->ast();
    expect($page->pagination())->toBe(['mode' => 'cursor', 'name' => 'after'])
        ->and($ast['orderBy'])->toBe([['name', 'asc'], ['id', 'desc']])
        ->and(data_get($ast, 'start.exclusive'))->toBeTrue()
        ->and((array) data_get($ast, 'start.row'))->toBe($cursor)
        ->and($ast['limit'])->toBe(3);
    foreach ([['__reverse' => true], ['__reverse' => 1, '__cursor' => $cursor], ['__page' => 2], ['__cursor' => ['name' => 'same']]] as $bad) {
        expect(fn (): ZeroQuery => $registered->declaration($bad))->toThrow(ValidationException::class);
    }
});

test('paginators reject collisions and unbounded request fields at generation', function (): void {
    expect(fn (): RegisteredQuery => paginatorRegistration('unbounded'))->toThrow(LogicException::class, 'bounded choices or min/max')
        ->and(fn (): RegisteredQuery => paginatorRegistration('collision'))->toThrow(LogicException::class, 'collides');
});

test('cursor pagination compares the effective order after each conditional scope removal', function (): void {
    app()->instance(ModelRegistry::class, new ModelRegistry([RankedList::class]));
    $cursorLine = (new ReflectionMethod(PaginatedLists::class, 'varyingFields'))->getEndLine() - 1;
    expect(fn (): RegisteredQuery => paginatorRegistration('varyingFields'))->toThrow(LogicException::class,
        __FILE__.':'.$cursorLine.' '.PaginatedLists::class.'@varyingFields prop query: Cursor pagination requires the same ordering fields on every branch.');

    $compiler = resolve(ControllerQueryCompiler::class);
    $definition = $compiler->compile(PaginatedLists::class, 'varyingDirections')[0];
    expect($definition->expression->cursorFields())->toBe(['name', 'id']);
    $registered = paginatorRegistration('varyingDirections');
    foreach ([true, false] as $reverse) {
        $page = $registered->declaration(['reverse' => $reverse, '__cursor' => ['name' => 'same', 'id' => 'a']]);
        expect($page->ast()['orderBy'])->toBe([['name', $reverse ? 'desc' : 'asc'], ['id', 'asc']]);
    }
});

test('omitted size uses the model default and scroll preserves the original numbered declaration', function (): void {
    $size = (new TaskList)->getPerPage();
    config(['zero.limits.max_live_window' => $size + 1]);
    $page = paginatorRegistration('defaultSize')->declaration([]);
    expect(Zero::scroll($page)->pagination())->toBe(['mode' => 'scroll', 'name' => 'lists'])
        ->and($page->pagination())->toBe(['mode' => 'numbered', 'name' => 'lists'])
        ->and($page->pageSize())->toBe($size)
        ->and($page->ast()['limit'])->toBe($size + 1);

    config(['zero.limits.max_live_window' => $size]);
    expect(fn (): RegisteredQuery => paginatorRegistration('defaultSize'))->toThrow(LogicException::class, 'below the live window cap');
});
