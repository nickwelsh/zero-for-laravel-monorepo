<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ShareListController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\TodoListController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('lists/{list}/share', ShareListController::class)->name('lists.share');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('lists', [TodoListController::class, 'store'])->name('lists.store');
    Route::patch('lists/{list}', [TodoListController::class, 'update'])->name('lists.update');
    Route::delete('lists/{list}', [TodoListController::class, 'destroy'])->name('lists.destroy');
    Route::post('todos', [TodoController::class, 'store'])->name('todos.store');
    Route::patch('todos/{todo}', [TodoController::class, 'update'])->name('todos.update');
    Route::delete('todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy');
});

require __DIR__.'/settings.php';
