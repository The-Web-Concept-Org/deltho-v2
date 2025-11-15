<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LotteriesController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RiddlesController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SaleReportController;
use App\Http\Controllers\WinnigController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });


Route::middleware('auth:sanctum')->group(function () {
    
    Route::get('/customerList', [UserController::class, 'customerList']);
    
    Route::post('/deleteUser', [UserController::class, 'deleteUser']);
    
    Route::post('/addLotteryLimit', [LotteriesController::class, 'addLotteryLimit']);
    
    Route::post('/mostPlayedNumber', [LotteriesController::class, 'mostPlayedNumber']);
    
    Route::POST('/getLotteryLimits', [LotteriesController::class, 'getLotteryLimits']);
    Route::get('/getCustomerLimits', [LotteriesController::class, 'getCustomerLimits']);
    
    Route::post('/deleteLotteryLimit', [LotteriesController::class, 'deleteLotteryLimit']);
    
    Route::post('/dashboard', [DashboardController::class, 'dashboard']);
    
    Route::post('/adminDashboardForCustomer', [DashboardController::class, 'adminDashboardForCustomer']);
    //for view details
    Route::post('/getdashboard/{user_id}', [DashboardController::class, 'getdashboard']);
    //collect balance cut
    Route::post('/collectamount', [DashboardController::class, 'collectBalance']);
    //lotteries
    //Route::post('/lotteries', [LotteriesController::class, 'addLottery']);
    Route::post('/lotteries/{lottery?}', [LotteriesController::class, 'addLottery']);
    Route::delete('/lotteries/{lottery}', [LotteriesController::class, 'deleteLottery']);
    
    //add seller or other users
    Route::post('/addUsers/{user_id?}', [UserController::class, 'addusers']);
    //edit user only status or commission
    Route::post('/edituser/{user_id}', [UserController::class, 'edituser']);
    
    Route::post('/editProfile', [UserController::class, 'editProfile']);


    Route::get('/requestuserlist' , [UserController::class, 'requestUserList']);
    //user list based on role
    Route::get('/userList/{all?}' , [UserController::class, 'userList']);
    //add Riddles
    Route::post('/changePassword' , [UserController::class, 'changePassword']);
    Route::post('/changePin' , [UserController::class, 'changePin']);
    Route::post('/addRiddles/{rid_id?}' , [RiddlesController::class, 'store']);

    Route::get('/deleteriddle/{rid_id?}' , [RiddlesController::class, 'destroy']);


    //Sale related controllers
    //limit routes
    Route::post('/addLimit' , [SaleController::class, 'addLimit']);
    Route::get('/limitlist/{user_id}' , [SaleController::class, 'limitlist']);
    Route::delete('/deleteLimitsingle/{limit_id}' , [SaleController::class, 'deleteLimitsingle']);
    Route::delete('/deleteLimitlottery' , [SaleController::class, 'deleteLimitlottery']);
    
    Route::POST('/creditHistory', [SaleController::class, 'getCreditHistory']);

    //Limit routes end
    Route::post('/checkLimit' , [SaleController::class, 'checkLimit']);
    Route::post('/checkLotteryLimit', [OrderController::class, 'checkLotteryLimit']);
    //orders ticket
    Route::post('/createOrder' , [OrderController::class, 'createOrder']);
    Route::post('/orderList' , [OrderController::class, 'orderList']);

    Route::get('/orderprint/{id}' , [OrderController::class, 'orderprint']);
    
    
    Route::get('/deleteorder/{id}' , [OrderController::class, 'deleteorder']);



    Route::post('/saleReport' , [SaleReportController::class, 'saleReport']);
    Route::post('/jsonSaleReport' , [SaleReportController::class, 'jsonSaleReport']);

    //winning number add
    Route::post('/winadd' , [WinnigController::class , 'addWinningNumber']);
    Route::get('/winningcustomer' , [WinnigController::class , 'winListAll']);
    Route::get('/getCustomers', [DashboardController::class, 'getCustomers']);
    //seller wining orders
    Route::get('/add_winnigamountbyseller' , [DashboardController::class , 'addWinningamountbySeller']);
    
    Route::match(['post', 'get'], '/winnigorderslist', [WinnigController::class , 'getWinningOrders']);
    
    Route::get('/managerData', [DashboardController::class, 'managerData']);
    
    // Route::get('/winnigorderslist' , [WinnigController::class , 'getWinningOrders']);
    //winning amout paid by seller

    Route::post('/getOrderHistory', [OrderController::class, 'getOrderHistory']);
    
    Route::post('/addVoucher', [SaleController::class, 'addVoucher']);
    Route::get('/validateVoucher/{tokenNo}', [SaleController::class, 'validateVoucher']);
    Route::post('/voucherList', [SaleController::class, 'getVouchersList']);
    
    Route::post('/verifyUser', [UserController::class, 'verifyUser']);
    
    Route::post('/approveUser', [UserController::class, 'approveUser']);
    
    Route::post('/addLoan', [SaleController::class, 'addLoan']);
    Route::post('/loanList', [SaleController::class, 'loanList']);
    
    Route::get('/getNotifications', [UserController::class, 'getNotifications']);
    Route::post('/readNotification', [UserController::class, 'readNotification']);
    
    Route::post('/addCompanyDetails', [UserController::class, 'addCompanyDetails']);
    
    // check if open orr not
    Route::get('/lotteryListWithTime/{lot_id?}', [LotteriesController::class, 'getLotteriesListAllWithTime']);
    // all lottery list
    Route::get('/lotteryList/{lot_id?}', [LotteriesController::class, 'getLotteriesListAll']);
    
    Route::get('/getWinningNumbers', [WinnigController::class, 'getWinningNumbers']);
    Route::post('/deleteWinningNumber', [WinnigController::class, 'deleteWinningNumber']);

});



Route::post('/login', [LoginController::class, 'login']);
//Route::post('/admin', [DashboardController::class, 'admin']);
Route::post('/requestAccess' , [UserController::class, 'requestUser']);

Route::match(['get', 'post'], '/printOrder/{id}/{orderItem?}', [OrderController::class, 'printOrder']);

// Route::get('/printOrder/{id}/{orderItem?}' , [OrderController::class, 'printOrder']);

//Riddles list
Route::get('/riddleList' , [RiddlesController::class, 'index']);

//winning mamagement

Route::get('/winingList/{id?}' , [RiddlesController::class, 'winingList']);
