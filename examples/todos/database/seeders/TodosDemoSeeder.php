<?php

namespace Database\Seeders;

use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Permissions\ListPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use ZeroForLaravel\Permissions\ProjectionManager;

class TodosDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            throw new LogicException('Demo accounts are only available in the local environment.');
        }

        DB::transaction(function (): void {
            (new ProjectionManager)->lock();
            $alice = User::firstOrCreate(['email' => 'alice@example.com'], [
                'name' => 'Alice', 'password' => Hash::make('todos-demo-password'), 'email_verified_at' => now(),
            ]);
            $bob = User::firstOrCreate(['email' => 'bob@example.com'], [
                'name' => 'Bob', 'password' => Hash::make('todos-demo-password'), 'email_verified_at' => now(),
            ]);
            $shared = TodoList::firstOrCreate(['user_id' => $alice->id, 'name' => 'Household']);
            ListPermissions::grantOwner($shared);
            ListPermissions::grantReader($shared, $bob->id);
            Todo::firstOrCreate(['list_id' => $shared->id, 'title' => 'Pick up groceries']);
            Todo::firstOrCreate(['list_id' => $shared->id, 'title' => 'Water the plants']);
            $private = TodoList::firstOrCreate(['user_id' => $alice->id, 'name' => 'Personal']);
            ListPermissions::grantOwner($private);
            Todo::firstOrCreate(['list_id' => $private->id, 'title' => 'Plan the weekend']);
        });
    }
}
