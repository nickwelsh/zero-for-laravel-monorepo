<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Laravel\Ranger\Components\InertiaResponse;
use Laravel\Ranger\Components\Route;
use Laravel\Wayfinder\Converters\InertiaData;
use Laravel\Wayfinder\Langs\TypeScript;
use ZeroForLaravel\Query\RegisteredQueries;

/** Replace only registered live props before Wayfinder writes its original declaration. */
final class WayfinderInertiaData extends InertiaData
{
    /** @var array<string, string> */
    private array $liveTypes = [];

    public function __construct(private readonly RegisteredQueries $queries) {}

    public function convert(InertiaResponse $response, Route $route): ?string
    {
        $this->liveTypes = [];
        if ($route->hasController()) {
            $controller = ltrim($route->controller(), '\\');
            $method = $route->method();
            foreach ($this->queries->forAction($controller.'@'.$method) as $query) {
                if ($query->component !== $response->component) {
                    continue;
                }

                $module = rtrim(config()->string('zero.wayfinder_import'), '/').'/'.str_replace('\\', '/', $controller);
                $reference = 'typeof import('.json_encode($module, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).')['.json_encode($method, JSON_THROW_ON_ERROR).']['.json_encode($query->prop, JSON_THROW_ON_ERROR).']';
                $this->liveTypes[$query->prop] = 'import("@nickwelsh/zero-for-laravel/react").QueryData<'.$reference.'>';
            }
        }

        return parent::convert($response, $route);
    }

    protected function getType(InertiaResponse $response): string
    {
        if ($this->liveTypes === []) {
            return parent::getType($response);
        }

        return 'Inertia.SharedData & '.TypeScript::objectToTypeObject(array_replace($response->data, $this->liveTypes), false);
    }
}
