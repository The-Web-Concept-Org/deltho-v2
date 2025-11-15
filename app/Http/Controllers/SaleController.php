<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
    use Carbon\Carbon;
class SaleController extends Controller
{
    

public function getCreditHistory(Request $request)
{
    try {
        $user = auth()->user();
// dd($user);
        // Retrieve optional date filters
        $fromDate = $request->input('fromDate');
        $toDate = $request->input('toDate');
// echo $fromDate;
// exit;
        // Build query
        $query = Transaction::with('seller:user_id,username')
            ->where('customer_id', $user->user_id);

        // Apply date filters if provided
        if ($fromDate) {
            $query->whereDate('transaction_add_date', '>=', Carbon::parse($fromDate)->startOfDay());
        }
        if ($toDate) {
            $query->whereDate('transaction_add_date', '<=', Carbon::parse($toDate)->endOfDay());
        }

        // Get and map the results
        $transactions = $query
            ->orderBy('transaction_id', 'DESC')
            ->get()
            ->map(function ($transaction) {
                $transaction->type = $transaction->credit > 0 ? true : false;
                return $transaction;
            });

        return response()->json(['success' => true, 'transactions' => $transactions]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
}

    
    public function getVouchersList(Request $request)
{
    try {
        $authUser = auth()->user();
        $userId = $authUser->user_id;
        $userRole = $authUser->user_role;

        // Get filter parameters
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $sellerIds = $request->input('seller_ids', []);

        // Base query for vouchers with transaction and user details
        $query = DB::table('vouchers')
            ->join('transactions', 'vouchers.transaction_id', '=', 'transactions.transaction_id');

        if ($userRole === 'customer') {
            // customer → seller
            $query->leftJoin('users', 'vouchers.added_user_id', '=', 'users.user_id')
                  ->where('vouchers.customer_id', $userId)
                  ->select(
                      'vouchers.voucher_id as voucher_id',
                      'vouchers.voucher_type',
                      'vouchers.givin_amount as amount',
                      'vouchers.voucher_hint as note',
                      'vouchers.add_datetime as created_at',
                      'transactions.transaction_id as transaction_id',
                      'users.username as seller'
                  );
        } elseif ($userRole === 'seller') {
            // seller → customer
            $query->leftJoin('users', 'vouchers.customer_id', '=', 'users.user_id')
                  ->where('vouchers.added_user_id', $userId)
                  ->select(
                      'vouchers.voucher_id as voucher_id',
                      'vouchers.voucher_type',
                      'vouchers.givin_amount as amount',
                      'vouchers.voucher_hint as note',
                      'vouchers.add_datetime as created_at',
                      'transactions.transaction_id as transaction_id',
                      'users.username as customer'
                  );
        } elseif (!empty($sellerIds)) {
            $query->leftJoin('users', 'vouchers.customer_id', '=', 'users.user_id')
                  ->leftJoin('users as seller', 'vouchers.added_user_id', '=', 'seller.user_id')
                  ->leftJoin('users as manager', 'seller.added_user_id', '=', 'manager.user_id')
                  ->whereIn('vouchers.added_user_id', $sellerIds)
                  ->select(
                      'vouchers.voucher_id as voucher_id',
                      'vouchers.voucher_type',
                      'vouchers.givin_amount as amount',
                      'vouchers.voucher_hint as note',
                      'vouchers.add_datetime as created_at',
                      'transactions.transaction_id as transaction_id',
                      'users.username as customer',
                      'seller.username as seller',
                      'manager.username as manager'
                  );
        } else{
            return response()->json(['success' => false, 'message' => 'Invalid Data.']);
        }

        // Date filters
        if ($startDate) {
            $startDate = date('Y-m-d', strtotime($startDate));
            $query->whereDate('vouchers.add_datetime', '>=', $startDate);
        }

        if ($endDate) {
            $endDate = date('Y-m-d', strtotime($endDate));
            $query->whereDate('vouchers.add_datetime', '<=', $endDate);
        }

        // Get deposit vouchers
        $depositVouchers = (clone $query)
            ->where('vouchers.voucher_type', 'deposit')
            ->orderBy('vouchers.add_datetime', 'desc')
            ->get();

        // Get withdraw vouchers
        $withdrawVouchers = (clone $query)
            ->where('vouchers.voucher_type', 'withdraw')
            ->orderBy('vouchers.add_datetime', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'deposit_vouchers' => $depositVouchers,
                'withdraw_vouchers' => $withdrawVouchers
            ]
        ], 200);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

    
    public function loanList(Request $request) {
    try {
        $sellerId = $request->input('seller_id');

        // Ensure seller_id is provided
        if (!$sellerId) {
            return response()->json(['success' => false, 'message' => 'Seller ID is required'], 400);
        }

        // Build the query to get loans for the specific seller
        $loanReport = DB::table('loans')
            ->where('loans.seller_id', $sellerId)
            ->leftJoin('users as added_user', 'loans.added_user_id', '=', 'added_user.user_id')
            ->leftJoin('users as seller_user', 'loans.seller_id', '=', 'seller_user.user_id')
            ->select('loans.*', 'added_user.username as added_username', 'seller_user.username as sellername')
            ->get();

        if ($loanReport->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No loan report found for this seller', 'data' => [], 'total_balance' => '0'], 200);
        }

        // Initialize total balance as a float and running total
        $totalBalance = 0;

        // Calculate cumulative balance for each transaction and cast fields to string
        $loanReport = $loanReport->map(function($loan) use (&$totalBalance) {
            $credit = (float)$loan->credit;
            $debit = (float)$loan->debit;

            // Update the running total balance
            $totalBalance += $credit;
            $totalBalance -= $debit;

            // Set the cumulative balance for this transaction and convert all values to strings
            $loan->credit = (string) $credit;
            $loan->debit = (string) $debit;
            $loan->balance = (int) $totalBalance;
            $loan->added_username = (string) $loan->added_username;
            $loan->sellername = (string) $loan->sellername;

            return $loan;
        });

        // Include total balance in the response as a string
        return response()->json([
            'success' => true, 
            'data' => $loanReport, 
            'total_balance' => (int) $totalBalance
        ], 200);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}






public function addLoan(Request $request)
{
    try {
        DB::beginTransaction();
        $user = Auth()->user();
        
        $sellerId = $request->input('seller_id');
        $creditAmount = $request->input('credit_amount');
        $debitAmount = $request->input('debit_amount');
        $transactionRemarks = $request->input('transaction_remarks');
        
        if($user->user_role == 'admin' || $user->user_role == 'manager') {
            $loanData = [
                'added_user_id' => $user->user_id,
                'seller_id' => $sellerId,
                'transaction_remarks' => $transactionRemarks,
            ];
            
            if (!empty($creditAmount)) {
                $loanData['credit'] = $creditAmount;
                $message = 'Loan added successfully';
                
                $notification = DB::table('notifications')->insert([
                    'added_user_id' => $user->user_id,
                    'seller_id' => $sellerId,
                    'notification_message' => 'The amount '. $creditAmount .' has been credited to your account'
                    ]);
                
            } elseif (!empty($debitAmount)) {
                $loanData['debit'] = $debitAmount;
                $message = 'Loan collected successfully';
                
                $notification = DB::table('notifications')->insert([
                    'added_user_id' => $user->user_id,
                    'seller_id' => $sellerId,
                    'notification_message' => 'The amount '. $debitAmount .' has been debited on your account'
                    ]);
                
            } else {
                return response()->json(['success' => false, 'message' => 'Either credit or debit amount must be provided'], 400);
            }
            
            DB::table('loans')->insert($loanData);
            
            DB::commit();
            
            return response()->json(['success' => true, 'message' => $message], 200);
        } else {
            return response()->json(['success' => false, 'message' => 'User not authorized'], 400);
        }
        
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}


public function addVoucher(Request $request)
{
    try {
        DB::beginTransaction();
        $authUser = auth()->user();

        $userId       = $request->input('user_id');  // customer
        $amount       = floatval($request->input('amount'));
        $type         = $request->input('type');
        $tokenNo      = $request->input('token_no');
        $validateType = $request->input('validateType'); // new param
        $now          = now();

        if (!in_array($type, ['deposit', 'withdraw'])) {
            return response()->json(['success' => false, 'message' => 'Invalid type.'], 400);
        }

        // ðŸ”¹ Check duplicate token_no
        $exists = DB::table('vouchers')->where('token_no', $tokenNo)->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Token already used.'], 400);
        }

        $sellerId   = $authUser->user_id;
        $customerId = $userId;

        // ðŸ”¹ If manual validation, skip balance check & set seller_id null
        if ($validateType === "manual") {
            DB::table('vouchers')->insert([
                'added_user_id'  => null,          // seller id null
                'givin_amount'   => $amount,
                'voucher_type'   => $type,
                'add_datetime'   => $now,
                'customer_id'    => $customerId,
                'token_no'       => $tokenNo,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Voucher added successfully (manual)'], 200);
        }

        // Otherwise proceed with existing logic (balance check etc.)
        $date       = now()->setTimezone('America/Port-au-Prince');
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay   = $date->copy()->endOfDay();

        $lastCutEntry = DB::table('cut_history')
            ->where('user_id', $sellerId)
            ->whereBetween('add_datetime', [$startOfDay, $endOfDay])
            ->latest('add_datetime')
            ->first();

        $finaltime = $lastCutEntry ? $lastCutEntry->add_datetime : $startOfDay;
        $seller    = DB::table('users')->where('user_id', $sellerId)->first();

        $totalCollected = DB::table('orders')
            ->where('user_id', $seller->user_id)
            ->where('lotterycollected', 0)
            ->where('adddatetime', '>', $finaltime)
            ->sum('grand_total');

        $sellerAdvance = DB::table('loans')
            ->where('seller_id', $seller->user_id)
            ->select(DB::raw('SUM(credit) - SUM(debit) as balance'))
            ->where('adddatetime', '>', $finaltime)
            ->first();

        $totalPaid = DB::table('order_item')
            ->whereIn('order_id', DB::table('orders')
                ->where('user_id', $seller->user_id)
                ->where('adddatetime', '>', $finaltime)
                ->pluck('order_id'))
            ->whereNotNull('transaction_paid_id')
            ->sum('winning_amount');

        $totalWin = DB::table('order_item')
            ->whereIn('order_id', DB::table('orders')
                ->where('user_id', $seller->user_id)
                ->where('lotterycollected', 0)
                ->where('adddatetime', '>', $finaltime)
                ->pluck('order_id'))
            ->sum('winning_amount');

        $deposit = DB::table('transactions')
            ->where('seller_id', $seller->user_id)
            ->where('transaction_add_date', '>', $finaltime)
            ->where('transaction_remarks', 'Deposit to customer')
            ->sum('debit');

        $withdraw = DB::table('transactions')
            ->where('seller_id', $seller->user_id)
            ->where('transaction_add_date', '>', $finaltime)
            ->where('transaction_remarks', 'Withdraw from customer')
            ->sum('credit');

        $comissionFromCustomers = DB::table('transactions')
            ->where('seller_id', $seller->user_id)
            ->where('transaction_add_date', '>', $finaltime)
            ->where('transaction_remarks', 'commission')
            ->sum('credit');

        $totalCommission = ($totalCollected * $seller->commission / 100) + $comissionFromCustomers;

        $balance = ($totalCollected + $deposit + $sellerAdvance->balance) - ($withdraw + $totalCommission + $totalPaid);

        // dd($balance);
        if ($type == 'withdraw') {
            if ($balance > 0 && $balance >= $amount) {
                DB::table('vouchers')->insert([
                    'added_user_id'  => $sellerId,
                    'givin_amount'   => $amount,
                    'voucher_type'   => $type,
                    'add_datetime'   => $now,
                    'customer_id'    => $customerId,
                    'token_no'       => $tokenNo,
                ]);

                DB::commit();
                return response()->json(['success' => true, 'message' => 'Voucher added successfully'], 200);
            } else {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Insufficient balance to add voucher.'], 200);
            }
        } else {
            // Deposit (no balance check)
            DB::table('vouchers')->insert([
                'added_user_id'  => $sellerId,
                'givin_amount'   => $amount,
                'voucher_type'   => $type,
                'add_datetime'   => $now,
                'customer_id'    => $customerId,
                'token_no'       => $tokenNo,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Voucher added successfully'], 200);
        }

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}



public function validateVoucher(Request $request, $tokenNo)
{
    try {
        DB::beginTransaction();

        $voucher = DB::table('vouchers')->where('token_no', $tokenNo)->first();
        if (!$voucher) {
            return response()->json(['success' => false, 'message' => 'invalid token number']);
        }

        $now = now()->setTimezone('America/Port-au-Prince');

        //  Check seller_id from request if voucher has null seller
        $sellerId = $voucher->added_user_id;
        if (is_null($sellerId)) {
            if ($request->has('seller_id')) {
                $sellerId = $request->input('seller_id');
        
                // ✅ Balance check only if withdraw voucher
                if ($voucher->voucher_type == 'withdraw') {
                    $date       = now()->setTimezone('America/Port-au-Prince');
                    $startOfDay = $date->copy()->startOfDay();
                    $endOfDay   = $date->copy()->endOfDay();
        
                    $lastCutEntry = DB::table('cut_history')
                        ->where('user_id', $sellerId)
                        ->whereBetween('add_datetime', [$startOfDay, $endOfDay])
                        ->latest('add_datetime')
                        ->first();
        
                    $finaltime = $lastCutEntry ? $lastCutEntry->add_datetime : $startOfDay;
                    $seller    = DB::table('users')->where('user_id', $sellerId)->first();
        
                    $totalCollected = DB::table('orders')
                        ->where('user_id', $seller->user_id)
                        ->where('lotterycollected', 0)
                        ->where('adddatetime', '>', $finaltime)
                        ->sum('grand_total');
        
                    $sellerAdvance = DB::table('loans')
                        ->where('seller_id', $seller->user_id)
                        ->select(DB::raw('SUM(credit) - SUM(debit) as balance'))
                        ->where('adddatetime', '>', $finaltime)
                        ->first();
        
                    $totalPaid = DB::table('order_item')
                        ->whereIn('order_id', DB::table('orders')
                            ->where('user_id', $seller->user_id)
                            ->where('adddatetime', '>', $finaltime)
                            ->pluck('order_id'))
                        ->whereNotNull('transaction_paid_id')
                        ->sum('winning_amount');
        
                    $totalWin = DB::table('order_item')
                        ->whereIn('order_id', DB::table('orders')
                            ->where('user_id', $seller->user_id)
                            ->where('lotterycollected', 0)
                            ->where('adddatetime', '>', $finaltime)
                            ->pluck('order_id'))
                        ->sum('winning_amount');
        
                    $deposit = DB::table('transactions')
                        ->where('seller_id', $seller->user_id)
                        ->where('transaction_add_date', '>', $finaltime)
                        ->where('transaction_remarks', 'Deposit to customer')
                        ->sum('debit');
        
                    $withdraw = DB::table('transactions')
                        ->where('seller_id', $seller->user_id)
                        ->where('transaction_add_date', '>', $finaltime)
                        ->where('transaction_remarks', 'Withdraw from customer')
                        ->sum('credit');
        
                    $comissionFromCustomers = DB::table('transactions')
                        ->where('seller_id', $seller->user_id)
                        ->where('transaction_add_date', '>', $finaltime)
                        ->where('transaction_remarks', 'commission')
                        ->sum('credit');
        
                    $totalCommission = ($totalCollected * $seller->commission / 100) + $comissionFromCustomers;
        
                    $balance = ($totalCollected + $deposit + $sellerAdvance->balance) - ($withdraw + $totalCommission + $totalPaid);
        
                    if (!($balance > 0 && $balance >= $voucher->givin_amount)) {
                        return response()->json(['success' => false, 'message' => 'Insufficient balance.'], 200);
                    }
                }
        
                // ✅ If balance ok (or voucher is deposit), assign seller
                DB::table('vouchers')
                    ->where('voucher_id', $voucher->voucher_id)
                    ->update(['added_user_id' => $sellerId]);
        
                $voucher->added_user_id = $sellerId;
            } else {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Seller ID is required'
                ], 422);
            }
        }



        $seller = DB::table('users')->where('user_id', $voucher->added_user_id)->first();

        if ($voucher->voucher_type == 'deposit') {
            // Seller -> Debit
            DB::table('transactions')->insert([
                'debit' => $voucher->givin_amount,
                'credit' => 0,
                'balance' => 0,
                'order_item_id' => null,
                'depositOrWithdraw_amount' => 1,
                'seller_id' => $sellerId,
                'transaction_remarks' => 'Deposit to customer',
                'transaction_add_date' => $now
            ]);

            // Customer -> Credit
            $customerTransactionId = DB::table('transactions')->insertGetId([
                'debit' => 0,
                'credit' => $voucher->givin_amount,
                'balance' => 0,
                'order_item_id' => null,
                'depositOrWithdraw_amount' => 1,
                'seller_id' => $sellerId,
                'customer_id' => $voucher->customer_id,
                'transaction_remarks' => 'Deposit received',
                'transaction_add_date' => $now
            ]);

            // Seller -> Commission
            $commission = $seller->commission;
            $commissionAmount = ($voucher->givin_amount * $seller->commission) / 100;
            DB::table('transactions')->insert([
                'debit' => 0,
                'credit' => $commissionAmount,
                'balance' => 0,
                'order_item_id' => null,
                'seller_id' => $sellerId,
                'transaction_remarks' => 'commission',
                'transaction_add_date' => $now
            ]);

        } elseif ($voucher->voucher_type == 'withdraw') {
            // Seller -> Credit
            DB::table('transactions')->insert([
                'debit' => 0,
                'credit' => $voucher->givin_amount,
                'balance' => 0,
                'order_item_id' => null,
                'depositOrWithdraw_amount' => 1,
                'seller_id' => $sellerId,
                'transaction_remarks' => 'Withdraw from customer',
                'transaction_add_date' => $now
            ]);

            // Customer -> Debit
            $customerTransactionId = DB::table('transactions')->insertGetId([
                'debit' => $voucher->givin_amount,
                'credit' => 0,
                'balance' => 0,
                'order_item_id' => null,
                'depositOrWithdraw_amount' => 1,
                'seller_id' => $sellerId,
                'customer_id' => $voucher->customer_id,
                'transaction_remarks' => 'Withdraw processed',
                'transaction_add_date' => $now
            ]);

        } else {
            return response()->json(['success' => false, 'message' => 'Invalid type']);
        }

        $verifiedVoucher = DB::table('vouchers')
            ->where('voucher_id', $voucher->voucher_id)
            ->update(['transaction_id' => $customerTransactionId]);

        DB::commit();
        return response()->json(['success' => true, 'message' => 'Transaction verified!', 'data' => $verifiedVoucher], 200);

    } catch (\Exception $e) {
        DB::rollback();
        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
}


public function voucherList(Request $request)
{
    try {
        $user = Auth()->user();
        
        if ($user->user_role == 'admin') {
            $vouchers = DB::table('vouchers')
                ->join('users', 'vouchers.user_id', '=', 'users.user_id')
                ->where('vouchers.added_user_id', $user->user_id)
                ->select('vouchers.*', 'users.*')
                ->get();
        } elseif ($user->user_role == 'manager') {
            // Assuming managers should see all vouchers added by users they manage
            $vouchers = DB::table('vouchers')
                ->join('users', 'vouchers.user_id', '=', 'users.user_id')
                ->where('users.manager_id', $user->user_id)
                ->select('vouchers.*', 'users.*')
                ->get();
        } elseif ($user->user_role == 'seller') {
            // Assuming you meant a different condition here, such as 'superadmin'
            // Modify as needed for the actual role and logic
            $vouchers = DB::table('vouchers')
                ->join('users', 'vouchers.user_id', '=', 'users.user_id')
                ->where('user_id', $user->user_id)
                ->select('vouchers.*', 'users.*')
                ->get();
        }
        
        return response()->json(['success' => true, 'data' => $vouchers], 200);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 200);
    }
}

public function limitlist(Request $request, $user)
{
    $response = [];

    try {
        $queryResult = DB::table('limit_game')
            ->join('lotteries', 'lotteries.lot_id', '=', 'limit_game.lottery_id')
            ->where('limit_game.user_id', $user)
            ->get();
    } catch (\Exception $e) {
        return response()->json([
            'data' => [],
            'success' => false,
            'msg' => $e->getMessage()
        ]);
    }

    foreach ($queryResult as $r) {
        $lotteryName = $r->lot_name;
        $lotteryColor = $r->lot_colorcode;
        $lotteryType = $r->lot_type;

        $limitData = [
            'limit_ball' => $r->limit_ball,
            'limit_frac' => $r->limit_frac,
            'limit_id' => $r->limit_id,
        ];

        // Create a unique key for each lottery_name + lottery_type combination
        $key = $lotteryName . '_' . $lotteryType;

        if (!isset($response[$key])) {
            $response[$key] = [
                'lottery_name' => $lotteryName,
                'lottery_color' => $lotteryColor,
                'lottery_type' => $lotteryType,
                'limits' => [],
            ];
        }

        $response[$key]['limits'][] = $limitData;
    }

    $finalResponse = array_values($response); // Convert associative array to indexed array

    if (empty($finalResponse)) {
        $re = [
            'data' => [],
            'success' => false,
            'msg' => 'Nothing Found'
        ];
    } else {
        $re = [
            'data' => $finalResponse,
            'success' => true,
            'msg' => 'Get Data'
        ];
    }

    return response()->json($re);
}




    public function checkLimit(Request $request)
{
    $entityBody = $request->getContent();
    $user = auth()->user();
    
    
   
     

    $obj = json_decode($entityBody);
    $size = count(@$obj->cartDataList);
    date_default_timezone_set("America/Guatemala");
    $servertimewithgutemala = now()->format('H:i:s');

    $testArr = [];
    for ($i = 0; $i < $size; $i++) {
        $lotteryid = ($obj->cartDataList[$i]->loteryId);
        $frac = (int)($obj->cartDataList[$i]->frac);
        $number = ($obj->cartDataList[$i]->number);
        $quator = ($obj->cartDataList[$i]->quator);
        $loteryName = ($obj->cartDataList[$i]->loteryName);
        $colorcode = ($obj->cartDataList[$i]->lotColor);
        //dd($user->user_id);
        $currentDateTime = now()->format('H:i:s');
//               $d = DB::select("SELECT lg.*, l.*
//             FROM lotteries l
//             LEFT JOIN limit_game lg ON l.lot_id = lg.lottery_id AND lg.status = '1'
//             WHERE l.lot_id = '$lotteryid'
//                 AND '$currentDateTime' >= l.lot_opentime
//                 AND '$currentDateTime' <= l.lot_closetime
//                 AND lg.limit_ball = '$number'
//                 AND lg.limit_frac <= '$frac'
//                 AND (lg.user_id = '$user->user_id' OR lg.user_id = '$added_user_id->user_id')
// ");
        
       $d = DB::select("SELECT * FROM limit_game WHERE 
    lottery_id = '$lotteryid'
    AND limit_ball = '$number'
    AND limit_frac >= '$frac'
    AND (user_id = '$user->user_id' OR user_id = '$user->added_user_id')
");

        


        // $lotery = DB::select("SELECT lot_id,lot_name AS name,is_open,multiply_number,img_url,winning_type,lot_opentime,lot_closetime,
        //     CASE
        //         WHEN lot_colorcode = '' THEN 'Color(0xff1cff19)'
        //         WHEN lot_colorcode IS NULL THEN  'Color(0xffEAF8A3)'
        //         ELSE lot_colorcode
        //     END
        //     AS colorcode FROM lotteries WHERE lot_id = '$lotteryid'
        // ");

        // $colorcode = collect($lotery)->first();

        $d1 = collect($d)->first();
        //dd($d1->limit_frac);

        if ($d1) {
            //dd($d1);
            $sts = "false";
        } else {
            $sts = "true";
        }
        //dd($sts);

        $testArr[] = [
            'number' => $number,
            'quator' => $quator,
            'frac'   => "$frac",
            'loteryId' => $lotteryid,
            'limit'   => $sts,
            'loteryName' => $loteryName,
            'lotColor' => $colorcode,
        ];
    }

    $finalArr = [
        'success' => true,
        'msg' => 'Lottery List',
        'cartDataList' => $testArr,

    ];

    return response()->json($finalArr);
}




    public function addLimit(Request $request)
        {
            $user = $request->input('user_id');
            $frac = $request->input('limit_amount');
            $limit_ball = $request->input('limit_number');
            $lotType = $request->input('lot_type');
            $added_user_id = auth()->user()->user_id;
        
            // Explode lottery_id into an array
            $lotteryIds = explode(',', $request->input('lottery_id'));
        
            // Initialize response array
            $responses = [];
        
            try {
                DB::beginTransaction();
        
                // Loop through each lottery ID and insert/update data
                foreach ($lotteryIds as $lotid) {
                    // Trim and validate each lottery ID
                    $lotid = trim($lotid);
                    if (!is_numeric($lotid)) {
                        throw new \Exception("Invalid lottery_id value: $lotid");
                    }
        
                    // Check if record already exists for the given lottery_id and lot_type
                    $existingRecord = DB::table('limit_game')
                        ->where('lottery_id', $lotid)
                        ->where('lot_type', $lotType)
                        ->where('limit_type', 0)
                        ->where('limit_ball', $limit_ball)
                        ->where('user_id',$user)
                        ->first();
        
                    if ($existingRecord) {
                        // Update existing record
                        DB::table('limit_game')
                            ->where('limit_id', $existingRecord->limit_id)
                            ->update([
                                'limit_frac' => $frac,
                                'limit_ball' => $limit_ball,
                            ]);
        
                        $responses = "Limit Updated for Lottery ID: $lotid";
                    } else {
                        // Insert new record
                        DB::table('limit_game')->insert([
                            'lottery_id' => (int)$lotid,
                            'limit_frac' => $frac,
                            'user_id' => $user,
                            'limit_ball' => $limit_ball,
                            'limit_type' => 0,
                            'added_user_id' => $added_user_id,
                            'lot_type' => $lotType,
                        ]);
        
                        $responses = "Limit Added for Lottery ID: $lotid";
                    }
                }
        
                DB::commit();
        
                return response()->json([
                    'success' => true,
                    'msg' => $responses ?: ['No changes were made'], // Ensure response is never empty
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
        
                return response()->json([
                    'success' => false,
                    'msg' => $e->getMessage(),
                ]);
            }
        }





public function deleteLimitsingle(Request $request, $limitID)
{
    try {
        // Start a database transaction
        DB::beginTransaction();

        // Delete the limit using the DB facade
        DB::table('limit_game')->where('limit_id', $limitID)->delete();

        // Commit the transaction if the deletion is successful
        DB::commit();

        return response()->json([
            'success' => true,
            'msg' => 'Limit Deleted successfully for numbers ',
        ], 200);
    } catch (\Exception $e) {
        // Rollback the transaction if an exception occurs
        DB::rollback();

        // Handle exceptions (e.g., database error)
        return response()->json([
            'success' => false,
            'msg' => 'Failed to delete limit. ' . $e->getMessage()

        ], 500);
    }
}


public function deleteLimitlottery(Request $request)
{
    try {
        // Start a database transaction
        DB::beginTransaction();
        $lotteryID = $request->input('lottery_id');
        $userID = $request->input('user_id');
        // Delete the limit using the DB facade
        DB::table('limit_game')
        ->where('lottery_id', $lotteryID)
        ->where('user_id', $userID)
        ->delete();

        // Commit the transaction if the deletion is successful
        DB::commit();

        return response()->json([
            'success' => true,
            'msg' => 'Limit Deleted successfully ',
        ], 200);
    } catch (\Exception $e) {
        // Rollback the transaction if an exception occurs
        DB::rollback();

        // Handle exceptions (e.g., database error)
        return response()->json([
            'success' => false,
            'msg' => 'Failed to delete limit. ' . $e->getMessage()

        ], 500);
    }
}




}
