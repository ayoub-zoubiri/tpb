<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/register', [\App\Http\Controllers\AuthController::class, 'register']);
Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout']);
    Route::get('/user', [\App\Http\Controllers\AuthController::class, 'me']);
    
    // Trips
    Route::get('/trips', [\App\Http\Controllers\TripController::class, 'index']);
    Route::get('/trips/{id}', [\App\Http\Controllers\TripController::class, 'show']);
    Route::put('/trips/{id}', [\App\Http\Controllers\TripController::class, 'update']);
    Route::delete('/trips/{id}', [\App\Http\Controllers\TripController::class, 'destroy']);
    
    // Admin Dashboard Routes
    Route::middleware('admin')->group(function () {
        Route::get('/admin/stats', [\App\Http\Controllers\AdminController::class, 'stats']);
        
        // Users
        Route::get('/admin/users', [\App\Http\Controllers\AdminController::class, 'getUsers']);
        Route::post('/admin/users', [\App\Http\Controllers\AdminController::class, 'createUser']);
        Route::put('/admin/users/{id}', [\App\Http\Controllers\AdminController::class, 'updateUser']);
        Route::delete('/admin/users/{id}', [\App\Http\Controllers\AdminController::class, 'deleteUser']);
        
        // Trips (Admin view)
        Route::get('/admin/trips', [\App\Http\Controllers\AdminController::class, 'getTrips']);
        Route::put('/admin/trips/{id}', [\App\Http\Controllers\AdminController::class, 'updateTrip']);
        Route::delete('/admin/trips/{id}', [\App\Http\Controllers\AdminController::class, 'deleteTrip']);
        
        // Blogs (Admin view)
        Route::get('/admin/blogs', [\App\Http\Controllers\AdminController::class, 'getBlogs']);
        Route::post('/admin/blogs', [\App\Http\Controllers\AdminController::class, 'createBlog']);
        Route::put('/admin/blogs/{id}', [\App\Http\Controllers\AdminController::class, 'updateBlog']);
        Route::delete('/admin/blogs/{id}', [\App\Http\Controllers\AdminController::class, 'deleteBlog']);
    });
});

// Public Routes
Route::post('/plan', [\App\Http\Controllers\TripController::class, 'generatePlan']); // Can be public or protected
Route::get('/blog', [\App\Http\Controllers\BlogController::class, 'index']);
Route::get('/blogs/{id}', [\App\Http\Controllers\BlogController::class, 'show']);

