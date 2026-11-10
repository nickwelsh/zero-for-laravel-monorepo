<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use ZeroForLaravel\Support\ApplicationClasses;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    /**
     * @param  list<class-string<Model>>  $classes
     * @return list<class-string<Model>>
     */
    public function models(array $classes = []): array
    {
        return $classes ?: [Task::class, TaskList::class, Tag::class, Comment::class];
    }

    /** @param list<class-string> $classes */
    public function discover(array $classes): void
    {
        app()->instance(ApplicationClasses::class, new Fixtures\DiscoveredClasses($classes));
    }

    public function createTables(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id');
            $table->uuid('list_id')->nullable();
            $table->string('title');
            $table->boolean('done')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->string('secret')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id');
            $table->string('name');
        });
        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id');
            $table->string('name');
        });
        Schema::create('task_tag', function (Blueprint $table): void {
            $table->uuid('task_id');
            $table->uuid('tag_id');
            $table->primary(['task_id', 'tag_id']);
        });
        Schema::create('comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->string('user_id');
            $table->string('body');
        });
    }

    protected function defineEnvironment($app): void
    {
        $app->instance(ApplicationClasses::class, new Fixtures\DiscoveredClasses($this->models()));
        $app['config']->set('zero.context', Fixtures\ZeroContext::class);
        $app['config']->set('zero.context_resolver', Fixtures\ContextResolver::class);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }
}
