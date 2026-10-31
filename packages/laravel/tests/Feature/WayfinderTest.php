<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Wayfinder\Converters\InertiaData;
use ZeroForLaravel\Tests\Fixtures\QueryController;
use ZeroForLaravel\Tests\HttpTestCase;
use ZeroForLaravel\WayfinderServiceProvider;

uses(HttpTestCase::class);

test('Wayfinder owns page declarations while registered Zero props infer their live query data', function (): void {
    $directory = sys_get_temp_dir().'/zero-wayfinder-'.uniqid();
    app()->register(\Laravel\Wayfinder\WayfinderServiceProvider::class);
    expect(resolve(InertiaData::class)::class)->toBe(InertiaData::class);
    app()->register(WayfinderServiceProvider::class);
    config([
        'zero.controller_output' => $directory.'/queries.php',
        'zero.mutation_output' => $directory.'/mutations.php',
        'zero.wayfinder_import' => '@custom/contracts',
        'wayfinder.cache.enabled' => false,
        'wayfinder.cache.directory' => $directory.'/cache',
        'wayfinder.generate.models' => false,
        'wayfinder.generate.inertia.shared_data' => true,
        'wayfinder.generate.broadcast.channels' => false,
        'wayfinder.generate.broadcast.events' => false,
        'wayfinder.generate.environment_variables' => false,
        'wayfinder.generate.enums' => false,
        'wayfinder.generate.route.actions' => false,
        'wayfinder.generate.route.named' => false,
    ]);
    Route::setRoutes(new RouteCollection);
    Route::get('/index/{title}', [QueryController::class, 'index']);
    Route::get('/show/{task}', [QueryController::class, 'show']);
    Route::get('/relationships/{title}', [QueryController::class, 'relationships']);
    Route::get('/plain', [QueryController::class, 'plain']);
    try {
        expect(Artisan::call('zero:generate', ['--output' => $directory.'/generated.ts']))->toBe(0);
        expect(Artisan::call('wayfinder:generate', [
            '--path' => $directory.'/wayfinder',
            '--base-path' => dirname(__DIR__),
            '--app-path' => __DIR__.'/../Fixtures',
            '--fresh' => true,
        ]))->toBe(0);
        $source = File::get($directory.'/wayfinder/types.d.ts');
        expect($source)->toContain(
            'namespace Inertia',
            'export type Index = Inertia.SharedData &',
            'heading: string',
            'sharedMarker: string',
            'import("@nickwelsh/zero-for-laravel/react").Data<',
            'import("@nickwelsh/zero-for-laravel").InferLiveQueryResult<',
            'typeof import("@custom/contracts/ZeroForLaravel/Tests/Fixtures/QueryController")["index"]["tasks"]',
            '["show"]["task"]',
            '["relationships"]["tasks"]',
            'export type Plain = Inertia.SharedData & { heading: string, count: number }',
        );
        expect($source)->not->toContain('ZeroForLaravel.Query.Query');
        expect(File::exists($directory.'/pages.d.ts'))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});
