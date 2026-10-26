<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
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
        foreach (['lists', 'tags'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->uuid('id')->primary(); $table->string('user_id'); $table->string('name');
            });
        }
        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('task_id'); $table->string('user_id'); $table->string('body');
        });
        Schema::create('task_tag', function (Blueprint $table) {
            $table->uuid('task_id'); $table->uuid('tag_id'); $table->primary(['task_id', 'tag_id']);
        });
    }
};
