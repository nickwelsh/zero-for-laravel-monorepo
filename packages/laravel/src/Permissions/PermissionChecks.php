<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LogicException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\Condition;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** Permission bindings share AccessQueries' bounded leases and isolated status rows. */
final readonly class PermissionChecks
{
    public function __construct(private ModelRegistry $registry) {}

    /** @return array{class: class-string<Model>, ability: string, id?: string} */
    public function parse(mixed $value): array
    {
        $input = Input::object($value, 'permission');
        Input::keys($input, ['model', 'ability', 'id'], 'permission');
        $name = $input['model'] ?? null;
        $ability = $input['ability'] ?? null;
        if (! is_string($name) || $name === '' || strlen($name) > 200
            || ! is_string($ability) || $ability === '' || strlen($ability) > 200) {
            Input::fail('permission', 'A registered model and allowlisted ability are required.');
        }

        $model = $this->registry->all()[$name] ?? null;
        $approved = config()->array('permissions.access.abilities');
        if ($model === null || ! is_array($approved[$model->class] ?? null) || ! in_array($ability, $approved[$model->class], true)) {
            Input::fail('permission', 'A registered model and allowlisted ability are required.');
        }

        if (array_key_exists('id', $input) && (! is_string($input['id']) || $input['id'] === '' || strlen($input['id']) > 200 || str_contains($input['id'], "\0"))) {
            Input::fail('permission.id', 'Omit id for class permissions or provide a nonempty string instance identity.');
        }

        return ['class' => $model->class, 'ability' => $ability, ...(isset($input['id']) ? ['id' => $input['id']] : [])];
    }

    /**
     * Snapshot actual decisions so a relevant grant, denial, subject or resource
     * change hides the old status row, using the existing native access monitor.
     *
     * @param  array{class: class-string<Model>, ability: string, id?: string}  $permission
     * @return array<string, mixed>
     */
    public function dependencies(array $permission, ResolvedContext $context, Authenticatable $actor): array
    {
        $this->assertActor($context, $actor);
        $projector = new ProjectionManager;
        $projector->assertReady();

        $db = $projector->connection();
        $subjectId = Binding::id($context->userId());
        $subject = config()->string('permissions.subject.prefix').$subjectId;
        $conditions = [$this->exists('permission_subjects', $this->equal('id', $subject),
            $db->table('permission_subjects')->where('id', $subject)->exists())];
        $compiler = new PolicyCompiler;
        if (! isset($permission['id'])) {
            $plan = $compiler->compileClass($permission['class'], $permission['ability']);
            $decision = new ClassPermission($plan, $subjectId, $plan->binding->classDomain($actor, $context));
            $conditions[] = $decision->condition($decision->query()->exists());
        } else {
            $model = new $permission['class'];
            $abilities = [$permission['ability']];
            if (VisibleToCurrentUser::names($model) !== []) {
                $abilities[] = 'view';
            }

            foreach (array_unique($abilities) as $ability) {
                $plan = $compiler->compile($permission['class'], $ability);
                $id = $permission['id'];
                $conditions[] = (new ZeroVisibility)->guard($plan, $subjectId, $id,
                    (new EloquentVisibility)->query($plan, $subjectId)->where('object_id', $id)->exists());
                $conditions[] = $this->exists($plan->binding->projectionTable(), $this->equal('object_id', $id),
                    $db->table($plan->binding->projectionTable())->where('object_id', $id)->exists());
            }
        }

        $fingerprint = $db->table('permission_installation')->value('fingerprint');
        if (! is_string($fingerprint)) {
            throw new LogicException('Permission installation is missing.');
        }

        $conditions[] = $this->exists('permission_installation', $this->equal('fingerprint', $fingerprint));

        return (new Condition('and', conditions: $conditions))->toArray();
    }

    /**
     * Called inside AccessQueries' fresh-actor Request/Auth scope and projection lock.
     * Laravel Gate remains the authority; no controller or navigation executes.
     *
     * @param  array{class: class-string<Model>, ability: string, id?: string}  $permission
     */
    public function authorize(array $permission, ResolvedContext $context, Authenticatable $actor): void
    {
        $this->assertActor($context, $actor);
        $class = $permission['class'];
        $projector = new ProjectionManager;
        $model = new $class;
        $projector->assertConnection($model);

        // Use exactly the same text identity as dependency snapshots. The native
        // key comes from the maintained projection, never a browser-supplied cast.
        $resource = isset($permission['id'])
            ? $class::query()->whereIn($model->getQualifiedKeyName(), $projector->connection()
                ->table(Binding::resource($class)->projectionTable())->select('resource_id')->where('object_id', $permission['id']))->firstOrFail()
            : $class;
        if (is_string($resource)) {
            Binding::resource($class)->classDomain($actor, $context);
        }

        Gate::forUser($actor)->authorize($permission['ability'], $resource);
    }

    private function assertActor(ResolvedContext $context, Authenticatable $actor): void
    {
        if ($context->userId() !== Binding::id($actor->getAuthIdentifier()) || ! $actor instanceof Model
            || ! is_a($actor, config()->string('permissions.subject.class'))) {
            throw new AuthorizationException('Permission actor differs from the validated context identity.');
        }

        (new ProjectionManager)->assertConnection($actor);
    }

    private function equal(string $column, string $value): Condition
    {
        return new Condition('simple', ['op' => '=', 'left' => ['type' => 'column', 'name' => $column], 'right' => ['type' => 'literal', 'value' => $value]]);
    }

    private function exists(string $table, Condition $condition, bool $present = true): Condition
    {
        return new Condition('correlatedSubquery', ['op' => $present ? 'EXISTS' : 'NOT EXISTS', 'related' => [
            'system' => 'permissions', 'hidden' => true,
            'correlation' => ['parentField' => ['global_key'], 'childField' => ['global_key']],
            'subquery' => ['table' => $table, 'alias' => 'zsubq_permission_check_'.$table, 'where' => $condition->toArray()],
        ]]);
    }
}
