<?php

use App\Http\Controllers\UserAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('/signin',[UserAuth::class, "login"]);
Route::post('/register',[UserAuth::class, "register"]);