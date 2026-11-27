<?php

declare(strict_types=1);

namespace ZeroForLaravel\Metadata;

use LogicException;

/** Two explicit one-hop relationships for a bounded preview. The public Eloquent edge stays unchanged. */
final readonly class JunctionLookup
{
    /** @param array{source: list<string>, dest: list<string>, table: string} $pivotHop
     * @param  array{source: list<string>, dest: list<string>, table: string}  $targetHop
     */
    private function __construct(public string $pivots, public string $target, public array $pivotHop, public array $targetHop) {}

    public static function declare(ModelMetadata $parent, string $name): void
    {
        $edge = $parent->registry->relation($parent, $name);
        [$pivot, $target] = $edge['path'];
        if ($target['dest'] !== [$parent->registry->get($edge['model'])->key]) {
            throw new LogicException("{$parent->name}.{$name}: a junction lookup must reference the target's primary key.");
        }

        // A colon cannot occur in an Eloquent method name. Include the parent table on the
        // pivot side, where different parents may share a pivot table and a relation name.
        $parent->junctionLookups[$name] = new self('zlookup:'.$name, 'ztarget:'.$parent->table.':'.$name, $pivot, $target);
    }

    /** @return array{pivots: string, target: string, order: string} */
    public function capability(): array
    {
        return ['pivots' => $this->pivots, 'target' => $this->target, 'order' => $this->targetHop['source'][0]];
    }
}
