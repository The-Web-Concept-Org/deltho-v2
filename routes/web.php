<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SaleReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', function () {
    return view('index');
});


Route::get('/login', function () {
    return view('login');
});
Route::get('/admin', function () {
    return redirect('/login');
});


Route::middleware('custom')->group(function () {
    Route::post('/saleReport', [SaleReportController::class, 'saleReport']);

    Route::get('/logout', [LoginController::class, 'logout']);

    Route::get('/dashboard', [WebController::class, 'dashboard']);

    Route::post('/saleReport', [WebController::class, 'saleReport']);
    Route::get('/getManagers/{id}', [WebController::class, 'getManagers']);

});

Route::get('/print', function () {
    return view('print');
});

Route::get('/printOrder/{id}', [OrderController::class, 'printOrder']);
Route::post('/login', [LoginController::class, 'webLogin']);
Route::match(['post', 'get'], '/logout', [LoginController::class, 'logout']);
