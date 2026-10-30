<?php

namespace Database\Seeders;

use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

class TodosDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            throw new LogicException('Demo accounts are only available in the local environment.');
        }

        $alice = User::firstOrCreate(['email' => 'alice@example.com'], [
            'name' => 'Alice', 'password' => Hash::make('todos-demo-password'), 'email_verified_at' => now(),
        ]);
        $bob = User::firstOrCreate(['email' => 'bob@example.com'], [
            'name' => 'Bob', 'password' => Hash::make('todos-demo-password'), 'email_verified_at' => now(),
        ]);
        $shared = TodoList::firstOrCreate(['user_id' => $alice->id, 'name' => 'Household']);
        $shared->memberships()->firstOrCreate(['user_id' => $alice->id]);
        $shared->memberships()->firstOrCreate(['user_id' => $bob->id]);
        Todo::firstOrCreate(['list_id' => $shared->id, 'title' => 'Pick up groceries']);
        Todo::firstOrCreate(['list_id' => $shared->id, 'title' => 'Water the plants']);
        $private = TodoList::firstOrCreate(['user_id' => $alice->id, 'name' => 'Personal']);
        $private->memberships()->firstOrCreate(['user_id' => $alice->id]);
        Todo::firstOrCreate(['list_id' => $private->id, 'title' => 'Plan the weekend']);
    }
}
