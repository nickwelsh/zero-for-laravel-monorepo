<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use LogicException;
use ZeroForLaravel\Domain\DomainMutation;
use ZeroForLaravel\Domain\OperationException;
use ZeroForLaravel\ZeroContext;

class CompleteTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->getAuthIdentifier() === 'alice';
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['ids' => ['required', 'array', 'min:1', 'max:3'], 'ids.*' => ['required', 'uuid', 'distinct']];
    }
}

class CompleteTasks extends DomainMutation
{
    /** @var list<string> */
    public static array $committed = [];

    public static function input(): array
    {
        return ['ids' => ['type' => 'string', 'array' => true, 'maxItems' => 3]];
    }

    public static function request(): string
    {
        return CompleteTasksRequest::class;
    }

    public function handle(array $input, ZeroContext $context): void
    {
        $ids = $input['ids'];
        if (! is_array($ids)) {
            throw new LogicException('Expected validated IDs.');
        }

        $tasks = Task::whereIn('id', $ids)->where('user_id', $context->get('user_id'))->lockForUpdate()->get();
        if ($tasks->count() !== count($ids)) {
            throw new AuthorizationException;
        }

        foreach ($tasks as $task) {
            $task->forceFill(['done' => true])->saveOrFail();
            if ($task->title === 'reject') {
                throw new OperationException('blocked', 'This task cannot be completed.');
            }

            $id = $task->getKey();
            if (! is_string($id)) {
                throw new LogicException('Expected string ID.');
            }

            DB::afterCommit(function () use ($id): void {
                self::$committed[] = $id;
            });
        }
    }
}
