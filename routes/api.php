<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;

Route::middleware('auth:sanctum')->group(function () {
    Route::put('/user', [UserController::class, 'updateProfile']);
    Route::delete('/user', [UserController::class, 'deleteAccount']);
    Route::get('/user', [UserController::class, 'getUser']);
});

Route::apiResource('wallets', WalletController::class)->middleware('auth');
Route::resource('categories', CategoryController::class)->middleware('auth');
Route::resource('transactions', TransactionController::class)->middleware('auth');
Route::get('/wallets/{wallet}', [WalletController::class, 'show'])->middleware('auth');


Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy')->middleware('auth', 'admin');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::put('/transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
});
