<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

final readonly class PolicyPlan
{
    public function __construct(
        public Binding $binding,
        public PortableModel $model,
        public string $action,
        public int $deniedStatus,
        public ?string $adminAttribute,
        public ?bool $constant,
        public string $fingerprint,
    ) {}
}
