<?php

declare(strict_types=1);

namespace ZeroForLaravel\Inertia;

use Inertia\ProvidesInertiaProperties;
use Inertia\Response;
use LogicException;
use ZeroForLaravel\Query\Query;

/** Uses Inertia's property-provider extension before serialization and partial filtering. */
final class ResponseFactory extends \Inertia\ResponseFactory
{
    public function render($component, $props = []): Response
    {
        if (array_key_exists('__zero', $this->sharedProps)) {
            throw new LogicException('The __zero Inertia metadata prop is reserved.');
        }

        foreach ($this->sharedProps as $value) {
            if ($value instanceof Query) {
                throw new LogicException('Live queries must be controller props, not shared props.');
            }
        }

        if ($props instanceof ProvidesInertiaProperties) {
            $props = [$props];
        }

        return parent::render($component, is_array($props) ? new LiveProps($props, $this->sharedProps) : $props);
    }
}
