<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Support\Facades\Route;
use LogicException;

/** Inert policy metadata. Neither construction nor inspection performs navigation. */
final readonly class Revocation
{
    private function __construct(public string $route, public ?string $message = null) {}

    public static function redirectToRoute(string $route): self
    {
        if ($route === '' || strlen($route) > 200) {
            throw new LogicException('Revocation destinations require a nonempty registered route name.');
        }

        return new self($route);
    }

    public function message(string $message): self
    {
        return new self($this->route, $message);
    }

    /** Generation-time validation only: initial server decisions do not depend on routing. */
    public function assertDestination(): void
    {
        $routes = Route::getRoutes();
        $routes->refreshNameLookups();

        $route = $routes->getByName($this->route);
        if ($route === null || ! in_array('GET', $route->methods(), true)) {
            throw new LogicException('Revocation destinations must name a registered GET route: '.$this->route.'.');
        }
    }

    /** @return array{route: string, message?: string} */
    public function toArray(): array
    {
        return ['route' => $this->route,
            ...($this->message === null ? [] : ['message' => $this->message])];
    }
}
