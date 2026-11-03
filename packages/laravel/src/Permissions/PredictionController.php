<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use ZeroForLaravel\Support\Input;

/** The application selects abilities; the browser cannot select a subject. */
final class PredictionController
{
    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof Authenticatable, 401);
        Input::keys(Input::object($request->query(), 'permissions'), [], 'permissions');
        $abilities = [];
        foreach (config()->array('permissions.predictions.abilities') as $class => $names) {
            if (! is_string($class) || ! is_subclass_of($class, Model::class) || ! is_array($names)) {
                throw new LogicException('Prediction abilities must map resource model classes to ability names.');
            }

            foreach ($names as $ability) {
                if (! is_string($ability) || $ability === '') {
                    throw new LogicException('Prediction abilities must be nonempty strings.');
                }

                $abilities[] = ['resource' => $class, 'ability' => $ability];
            }
        }

        return response()->json(Predictions::forUser($actor, $abilities))
            ->header('Cache-Control', 'private, no-store')->header('Vary', 'Cookie, Authorization');
    }
}
