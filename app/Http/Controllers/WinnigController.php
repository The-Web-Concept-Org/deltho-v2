<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
class WinnigController extends Controller
{
    public function addWinningNumber(Request $request)
{
    if (!empty($request->lot_id)) {
        $lot  = $request->lot_id;
        $calculate = $request->input('calculate', 0); // Default to 0 if not provided
        $user = auth()->user();
        $user_id = $user->user_id;
        $date = $request->input('win_date'); // "31 Oct, 2024"
        $formattedDate = \DateTime::createFromFormat('d M, Y', $date)->format('Y-m-d');

        // $date = date('y-m-d');
        
        $managers = User::where('added_user_id', $user_id)->where('user_role', 'manager')->get();
        $sellers = User::where('added_user_id', $user_id)->where('user_role', 'seller')->pluck('user_id')->toArray();
        
        // Loop through each manager and get the sellers' user IDs
        foreach ($managers as $manager) {
            $managerSellers = User::where('added_user_id', $manager->user_id)->where('user_role', 'seller')->pluck('user_id')->toArray();
            $sellers = array_merge($sellers, $managerSellers);
        }
        
        
        // Remove duplicate IDs if necessary
        $sellers = array_unique($sellers);
        
        $customers = User::where('user_role', 'customer')->where('status', 1)->pluck('user_id')->toArray();
        
        $sellers = array_merge($sellers, $customers);
        
        $addedOrders = DB::table('orders')
        ->whereIn('user_id', $sellers)
        ->where('order_date', $formattedDate)
        ->pluck('order_id')
        ->toArray();

        // If calculate is 1, get the distinct count of orders with the same lot_id
        if ($calculate == 1) {
            // Get the distinct order_ids for the lot_id
            $distinctOrders = DB::table('order_item')
                ->where('product_id', $lot)
                ->whereIn('order_id', $addedOrders)
                ->where('lottery_gone', '0')
                ->distinct()
                ->pluck('order_id'); // Get unique order_ids

            // Count the number of distinct orders
            $orderCount = $distinctOrders->count();
            
            $orderAmount = DB::table('order_item')
            ->where('product_id', $lot)
            ->whereIn('order_id', $addedOrders)
            ->where('lottery_gone', '0')
            ->sum('lot_amount'); // Sum the lot_amount

            // Return the count of distinct orders
            return response()->json([
                'msg' => 'The following numbers of Orders will be affected after the confirmation',
                'order_count' => $orderCount,
                'order_amount' => $orderAmount,
                'success' => true,
            ]);
        }else{
            $distinctOrders = DB::table('order_item')
                ->where('product_id', $lot)
                ->whereIn('order_id', $addedOrders)
                ->where('lottery_gone', '0')
                ->distinct()
                ->pluck('order_id')
                ->toArray();
        }
        
        
        
        $win  = $request->win_number;
        $firstWin = $request->first_win_number;
        $secondWin = $request->second_win_number;
        $thirdWin = $request->third_win_number;
        $customMulNumber = $request->input('multiply_number');

        $winNumbers = [$firstWin, $secondWin, $thirdWin];
        
        DB::beginTransaction();

        try {
            $inserted = DB::table('winning_numbers')->insert([
                'add_date' => $formattedDate,
                'lot_id' => $lot,
                'number_win' => $win,
                'first_win_number' => $firstWin,
                'second_win_number' => $secondWin,
                'third_win_number' => $thirdWin,
                'added_by' => $user_id
            ]);

            if ($inserted) {
                $totalwinadded = 0;
                
                // Extract the last two digits of the first winning number
                $lastTwoDigitsOfFirstWin = substr($firstWin, -2);
                
                array_push($winNumbers, $lastTwoDigitsOfFirstWin);
                // Find BOR order items
                $getwinorder = DB::table('order_item')
                    ->select('order_item_id', 'order_id', 'product_id', 'product_name', 'lot_number', 'lot_frac', 'lot_amount', 'lottery_gone', 'transaction_paid_id', 'order_item_status', 'winning_amount', 'lotterycollected', DB::raw('cast(adddatetime as date)'))
                    ->where('lottery_gone', '0')
                    ->where('product_id', $lot)
                    ->whereIn('order_id', $distinctOrders)
                    ->whereIn('lot_number', $winNumbers)
                    // ->where(DB::raw('cast(adddatetime as date)'), $date)
                    ->where('lot_type', 'BOR')
                    ->get();
                
                $lotDetails = DB::table('lotteries')->where('lot_id', $lot)->first();
                
                foreach ($getwinorder as $rowq) {
                    $winAmount = 0;
                    // Check each condition separately
                    if ($lastTwoDigitsOfFirstWin == $rowq->lot_number) {
                        if ($customMulNumber != null) {
                            $winAmount += $rowq->lot_amount * $customMulNumber;
                        } else {
                            $winAmount += $rowq->lot_amount * 50;
                        }
                    }
                
                    if ($secondWin == $rowq->lot_number) {
                        $winAmount += $rowq->lot_amount * 20;
                    }
                
                    if ($thirdWin == $rowq->lot_number) {
                        $winAmount += $rowq->lot_amount * 10;
                    }
                
                    // Add the current winAmount to the totalwinadded
                    $totalwinadded += $winAmount;
                
                    // Update the winning amount for the order item
                    DB::table('order_item')
                        ->where('order_item_id', $rowq->order_item_id)
                        ->update(['winning_amount' => $winAmount]);
                
                    // ✅ Fetch user of the order
                    $user_idNow = DB::table('orders')->where('order_id', $rowq->order_id)->value('user_id');
                    $userRole = DB::table('users')->where('user_id', $user_idNow)->value('user_role');
                
                    // ✅ If user is customer
                    if ($userRole == 'customer' && $winAmount > 0) {
                        // Insert transaction and get ID
                        $transactionId = DB::table('transactions')->insertGetId([
                            'customer_id' => $user_idNow,
                            'debit' => 0,
                            'credit' => $winAmount,
                            'transaction_remarks' => 'Lottery Winning',
                            'transaction_add_date' => now(),
                        ]);
                
                        // ✅ Update order_item: verify status and transaction ID
                        DB::table('order_item')
                            ->where('order_item_id', $rowq->order_item_id)
                            ->update([
                                'verify_status' => 'verified',
                                'transaction_paid_id' => $transactionId,
                            ]);
                
                        // ✅ Optional: send notification (simplified)
                        DB::table('notifications')->insert([
                            'added_user_id' => $user_idNow,
                            'seller_id' => $user_idNow,
                            'notification_message' => 'Congratulations! You won a lottery worth ' . number_format($winAmount) . '. Lottery Number: '. $rowq->lot_number,
                            'add_datetime' => now(),
                        ]);
                    }
                }

                $getMARorder = DB::table('order_item')
                ->select('order_item_id', 'order_id', 'product_id', 'is_free', 'product_name', 'lot_number', 'lot_frac', 'lot_amount', 'lottery_gone', 'transaction_paid_id', 'order_item_status', 'winning_amount', 'lotterycollected', DB::raw('cast(adddatetime as date)'))
                ->where('lottery_gone', '0')
                ->where('product_id', $lot)
                ->whereIn('order_id', $distinctOrders)
                // ->where(DB::raw('cast(adddatetime as date)'), $date)
                ->where('lot_type', 'MAR')
                ->get();

                $MarWinAmount = 0;
                
                // Concatenate the required combinations
                $combined1 = $lastTwoDigitsOfFirstWin . 'x' . $secondWin;
                $combined2 = $lastTwoDigitsOfFirstWin . 'x' . $thirdWin;
                $combined3 = $secondWin . 'x' . $thirdWin;
                $combined4 = $secondWin . 'x' . $lastTwoDigitsOfFirstWin;
                $combined5 = $thirdWin . 'x' . $lastTwoDigitsOfFirstWin;
                $combined6 = $thirdWin . 'x' . $secondWin;
                
                foreach ($getMARorder as $rowq) {
                    $currentWinAmount = 0; // Reset currentWinAmount for each order item
                
                    // Check each combination and update the win amount
                    if ($combined1 == $rowq->lot_number) {
                        if ($rowq->is_free == 1) {
                            $currentWinAmount += 3000;
                        } else {
                            $currentWinAmount += $rowq->lot_amount * 1000;
                        }
                    }
                
                    if ($combined2 == $rowq->lot_number) {
                        if ($rowq->is_free == 1) {
                            $currentWinAmount += 3000;
                        } else {
                            $currentWinAmount += $rowq->lot_amount * 1000;
                        }
                    }
                
                    if ($combined3 == $rowq->lot_number) {
                        if ($rowq->is_free == 1) {
                            $currentWinAmount += 3000;
                        } else {
                            $currentWinAmount += $rowq->lot_amount * 1000;
                        }
                    }
                    
                    if ($combined4 == $rowq->lot_number) {
                        if ($rowq->is_free == 1) {
                            $currentWinAmount += 3000;
                        } else {
                            $currentWinAmount += $rowq->lot_amount * 1000;
                        }
                    }
                    
                    if ($combined5 == $rowq->lot_number) {
                        if ($rowq->is_free == 1) {
                            $currentWinAmount += 3000;
                        } else {
                            $currentWinAmount += $rowq->lot_amount * 1000;
                        }
                    }
                    
                    if ($combined6 == $rowq->lot_number) {
                        if ($rowq->is_free == 1) {
                            $currentWinAmount += 3000;
                        } else {
                            $currentWinAmount += $rowq->lot_amount * 1000;
                        }
                    }
                
                    // Add the current win amount to the total
                    $MarWinAmount += $currentWinAmount;
                
                    // Update the winning amount for the order item
                    if ($currentWinAmount > 0) {
                        DB::table('order_item')
                            ->where('order_item_id', $rowq->order_item_id)
                            ->update(['winning_amount' => $currentWinAmount]);
                        
                        // ✅ Fetch user of the order
                        $user_idNow = DB::table('orders')->where('order_id', $rowq->order_id)->value('user_id');
                        $userRole = DB::table('users')->where('user_id', $user_idNow)->value('user_role');
                    
                        // ✅ If user is customer
                        if ($userRole == 'customer' && $currentWinAmount > 0) {
                            // Insert transaction and get ID
                            $transactionId = DB::table('transactions')->insertGetId([
                                'customer_id' => $user_idNow,
                                'debit' => 0,
                                'credit' => $currentWinAmount,
                                'transaction_remarks' => 'Lottery Winning',
                                'transaction_add_date' => now(),
                            ]);
                    
                            // ✅ Update order_item: verify status and transaction ID
                            DB::table('order_item')
                                ->where('order_item_id', $rowq->order_item_id)
                                ->update([
                                    'verify_status' => 'verified',
                                    'transaction_paid_id' => $transactionId,
                                ]);
                    
                            // ✅ Optional: send notification (simplified)
                            DB::table('notifications')->insert([
                                'added_user_id' => $user_idNow,
                                'seller_id' => $user_idNow,
                                'notification_message' => 'Congratulations! You won a lottery worth ' . number_format($currentWinAmount) . '. Lottery Number: '. $rowq->lot_number,
                                'add_datetime' => now(),
                            ]);
                        }
                    }
                }
                
                // Find LOT3 order items
                $getLOT3order = DB::table('order_item')
                    ->select('order_item_id', 'order_id', 'product_id', 'product_name', 'lot_number', 'lot_frac', 'lot_amount', 'lottery_gone', 'transaction_paid_id', 'order_item_status', 'winning_amount', 'lotterycollected', DB::raw('cast(adddatetime as date)'))
                    ->where('lottery_gone', '0')
                    ->where('product_id', $lot)
                    ->whereIn('order_id', $distinctOrders)
                    ->whereIn('lot_number', $winNumbers)
                    // ->where(DB::raw('cast(adddatetime as date)'), $date)
                    ->where('lot_type', 'LOT3')
                    ->get();
                
                foreach ($getLOT3order as $rowq) {
                    $LOT3WinAmount = 0;
                        if ($firstWin == $rowq->lot_number) {
                            $LOT3WinAmount = $rowq->lot_amount * 500;
                        }
                        
                        DB::table('order_item')
                            ->where('order_item_id', $rowq->order_item_id)
                            ->update(['winning_amount' => $LOT3WinAmount]);
                        
                        // ✅ Fetch user of the order
                        $user_idNow = DB::table('orders')->where('order_id', $rowq->order_id)->value('user_id');
                        $userRole = DB::table('users')->where('user_id', $user_idNow)->value('user_role');
                    
                        // ✅ If user is customer
                        if ($userRole == 'customer' && $LOT3WinAmount > 0) {
                            // Insert transaction and get ID
                            $transactionId = DB::table('transactions')->insertGetId([
                                'customer_id' => $user_idNow,
                                'debit' => 0,
                                'credit' => $LOT3WinAmount,
                                'transaction_remarks' => 'Lottery Winning',
                                'transaction_add_date' => now(),
                            ]);
                    
                            // ✅ Update order_item: verify status and transaction ID
                            DB::table('order_item')
                                ->where('order_item_id', $rowq->order_item_id)
                                ->update([
                                    'verify_status' => 'verified',
                                    'transaction_paid_id' => $transactionId,
                                ]);
                    
                            // ✅ Optional: send notification (simplified)
                            DB::table('notifications')->insert([
                                'added_user_id' => $user_idNow,
                                'seller_id' => $user_idNow,
                                'notification_message' => 'Congratulations! You won a lottery worth ' . number_format($LOT3WinAmount) . '. Lottery Number: '. $rowq->lot_number,
                                'add_datetime' => now(),
                            ]);
                        }
                }
                
                // Find LOT4 order items
                
                $secondThirdWin = $secondWin . $thirdWin;
                $firstSecondWin = $lastTwoDigitsOfFirstWin . $secondWin;
                $firstThirdWin = $lastTwoDigitsOfFirstWin . $thirdWin;
                $getLOT4order = DB::table('order_item')
                    ->select('order_item_id', 'order_id', 'product_id', 'lot_type', 'product_name', 'lot_number', 'lot_frac', 'lot_amount', 'lottery_gone', 'transaction_paid_id', 'order_item_status', 'winning_amount', 'lotterycollected', DB::raw('cast(adddatetime as date)'))
                    ->where('lottery_gone', '0')
                    ->where('product_id', $lot)
                    ->whereIn('order_id', $distinctOrders)
                    ->whereIn('lot_number', [$secondThirdWin, $firstSecondWin, $firstThirdWin])
                    // ->where(DB::raw('cast(adddatetime as date)'), $date)
                    ->whereIn('lot_type', ['LOT4.1', 'LOT4.2', 'LOT4.3'])
                    ->get();
                    
                foreach ($getLOT4order as $rowq) {
                    $LOT4WinAmount = 0;
                        if ($secondThirdWin == $rowq->lot_number && $rowq->lot_type == 'LOT4.1') {
                            $LOT4WinAmount += $rowq->lot_amount * 5000;
                        }
                        if($firstSecondWin == $rowq->lot_number && $rowq->lot_type == 'LOT4.2'){
                            $LOT4WinAmount += $rowq->lot_amount * 5000;
                        }
                        if($firstThirdWin == $rowq->lot_number && $rowq->lot_type == 'LOT4.3'){
                            $LOT4WinAmount += $rowq->lot_amount * 5000;
                        }
                        
                        DB::table('order_item')
                            ->where('order_item_id', $rowq->order_item_id)
                            ->update(['winning_amount' => $LOT4WinAmount]);
                        
                        // ✅ Fetch user of the order
                        $user_idNow = DB::table('orders')->where('order_id', $rowq->order_id)->value('user_id');
                        $userRole = DB::table('users')->where('user_id', $user_idNow)->value('user_role');
                    
                        // ✅ If user is customer
                        if ($userRole == 'customer' && $LOT4WinAmount > 0) {
                            // Insert transaction and get ID
                            $transactionId = DB::table('transactions')->insertGetId([
                                'customer_id' => $user_idNow,
                                'debit' => 0,
                                'credit' => $LOT4WinAmount,
                                'transaction_remarks' => 'Lottery Winning',
                                'transaction_add_date' => now(),
                            ]);
                    
                            // ✅ Update order_item: verify status and transaction ID
                            DB::table('order_item')
                                ->where('order_item_id', $rowq->order_item_id)
                                ->update([
                                    'verify_status' => 'verified',
                                    'transaction_paid_id' => $transactionId,
                                ]);
                    
                            // ✅ Optional: send notification (simplified)
                            DB::table('notifications')->insert([
                                'added_user_id' => $user_idNow,
                                'seller_id' => $user_idNow,
                                'notification_message' => 'Congratulations! You won a lottery worth ' . number_format($LOT4WinAmount) . '. Lottery Number: '. $rowq->lot_number,
                                'add_datetime' => now(),
                            ]);
                        }
                }
                
                // Find LOT5 order items
                
                $LOT5firstSecondWin = $firstWin . $secondWin;
                $LOT5firstThirdWin = $firstWin . $thirdWin;
                $getLOT5order = DB::table('order_item')
                    ->select('order_item_id', 'order_id', 'product_id', 'lot_type', 'product_name', 'lot_number', 'lot_frac', 'lot_amount', 'lottery_gone', 'transaction_paid_id', 'order_item_status', 'winning_amount', 'lotterycollected', DB::raw('cast(adddatetime as date)'))
                    ->where('lottery_gone', '0')
                    ->where('product_id', $lot)
                    ->whereIn('order_id', $distinctOrders)
                    ->where('lot_number', [$LOT5firstSecondWin, $LOT5firstThirdWin])
                    // ->where(DB::raw('cast(adddatetime as date)'), $date)
                    ->whereIn('lot_type', ['LOT5.1', 'LOT5.2'])
                    ->get();
                    
                foreach ($getLOT5order as $rowq) {
                    $LOT5WinAmount = 0;
                        if ($LOT5firstSecondWin == $rowq->lot_number && $rowq->lot_type == 'LOT5.1') {
                            $LOT5WinAmount += $rowq->lot_amount * 25000;
                        }
                        if($LOT5firstThirdWin == $rowq->lot_number && $rowq->lot_type == 'LOT5.2'){
                            $LOT5WinAmount += $rowq->lot_amount * 25000;
                        }
                        
                        DB::table('order_item')
                            ->where('order_item_id', $rowq->order_item_id)
                            ->update(['winning_amount' => $LOT5WinAmount]);
                        
                        // ✅ Fetch user of the order
                        $user_idNow = DB::table('orders')->where('order_id', $rowq->order_id)->value('user_id');
                        $userRole = DB::table('users')->where('user_id', $user_idNow)->value('user_role');
                    
                        // ✅ If user is customer
                        if ($userRole == 'customer' && $LOT5WinAmount > 0) {
                            // Insert transaction and get ID
                            $transactionId = DB::table('transactions')->insertGetId([
                                'customer_id' => $user_idNow,
                                // 'frbit' => 0,
                                'credit' => $LOT5WinAmount,
                                'transaction_remarks' => 'Lottery Winning',
                                'transaction_add_date' => now(),
                            ]);
                    
                            // ✅ Update order_item: verify status and transaction ID
                            DB::table('order_item')
                                ->where('order_item_id', $rowq->order_item_id)
                                ->update([
                                    'verify_status' => 'verified',
                                    'transaction_paid_id' => $transactionId,
                                ]);
                    
                            // ✅ Optional: send notification (simplified)
                            DB::table('notifications')->insert([
                                'added_user_id' => $user_idNow,
                                'seller_id' => $user_idNow,
                                'notification_message' => 'Congratulations! You won a lottery worth ' . number_format($LOT5WinAmount) . '. Lottery Number: '. $rowq->lot_number,
                                'add_datetime' => now(),
                            ]);
                        }
                }

                $getgoneorder = DB::table('order_item')
                    ->where('lottery_gone', '0')
                    ->where('product_id', $lot)
                    ->whereIn('order_id', $distinctOrders)
                    ->get();

                foreach ($getgoneorder as $goneupdate) {
                    DB::table('order_item')
                        ->where('order_item_id', $goneupdate->order_item_id)
                        ->update(['lottery_gone' => '1']);
                }

                // Add your transaction insertion here

                DB::commit();

                $arr = [
                    'msg' => 'Winning Number Added..!',
                    'success' => true,
                ];
            } else {
                $arr = [
                    'msg' => 'Failed to add winning number.',
                    'success' => false,
                ];
            }
        } catch (\Exception $e) {
            DB::rollback();
            $arr = [
                'msg' => $e->getMessage(),
                'success' => false,
            ];
        }

        return response()->json($arr);
    } else {
        return response()->json([
            'msg' => 'Missing required parameters.',
            'success' => false,
        ]);
    }
}

    public function deleteWinningNumber(Request $request)
    {
        DB::beginTransaction();
        try{
            
            $user = auth()->user();
            $winId = $request->input('win_id');
            
            $winningNumber = DB::table('winning_numbers')
                ->where('win_id', $winId)
                ->first();
            
            $orderItems = DB::table('order_item')
                ->where('product_id', $winningNumber->lot_id)
                ->whereDate('adddatetime', $winningNumber->add_date)
                ->get();
                
            DB::table('order_item')
            ->where('product_id', $winningNumber->lot_id)
            ->whereDate('adddatetime', $winningNumber->add_date)
            ->update([
                'lottery_gone' => 0,
                'winning_amount' => 0
            ]);
            
            foreach($orderItems as $item){
                $orderUserId = DB::table('orders')->where('order_id', $item->order_id)->value('user_id');
                $userRole = DB::table('users')->where('user_id', $orderUserId)->value('user_role');
                
                if($userRole == 'customer'){
                    
                    $transaction = DB::table('transactions')->where('transaction_id', $item->transaction_paid_id)->first();
                    if($transaction){
                        
                        DB::table('transactions')->insert([
                            'customer_id' => $orderUserId,
                            'debit' => $transaction->credit,
                            'transaction_remarks' => 'Transaction Reversed',
                            'transaction_add_date' => now(),
                        ]);
                        
                        DB::table('notifications')->insert([
                            'added_user_id' => $orderUserId,
                            'seller_id' => $orderUserId,
                            'notification_message' => 'Your winnings for order number: '. $item->order_id . ' have been reversed. Please contact support for more info.',
                            'add_datetime' => now(),
                        ]);
                        
                    }
                    
                }
            }
                
            DB::table('winning_numbers')
                ->where('win_id', $winId)
                ->delete();
                
            DB::commit();
            return response()->json(['success' => true, 'msg' => 'Winning number deleted'], 200);
            
        }catch(\Exception $e){
            DB::rollback();
            return response()->json(['success' => false, 'msg' => $e->getMessage()], 400);
        }
    }

    public function getWinningNumbers()
{
    $user = auth()->user();

    $winningNumbers = DB::table('winning_numbers')
        ->where('added_by', $user->user_id)
        ->orderBy('win_id', 'DESC')
        ->limit(20)
        ->get()
        ->map(function ($winningNumber) {
            $lotteryData = DB::table('lotteries')
                ->where('lot_id', $winningNumber->lot_id)
                ->select('lot_name', 'lot_colorcode')
                ->first();

            // Add lot_name and lot_colorcode to the winning number details
            $winningNumber->lot_name = $lotteryData->lot_name ?? null;
            $winningNumber->lot_colorcode = $lotteryData->lot_colorcode ?? null;

            // Cast win_id, lot_id, and added_by to integers
            $winningNumber->win_id = (int) $winningNumber->win_id;
            $winningNumber->lot_id = (int) $winningNumber->lot_id;
            $winningNumber->added_by = (int) $winningNumber->added_by;
            
            $winningNumber->first_win_number = $winningNumber->first_win_number ?? '0';
            $winningNumber->second_win_number = $winningNumber->second_win_number ?? '0';
            $winningNumber->third_win_number = $winningNumber->third_win_number ?? '0';

            return $winningNumber;
        });

    return response()->json(['success' => true, 'msg' => 'winning numbers', 'data' => $winningNumbers], 200);
}




    public function winListAll(Request $request)
    {

            $user = auth()->user()->user_id;

            $getuserde = DB::table('users')->where('user_id', $user)->first();

            if ($getuserde->user_role == 'admin') {
                $thisadmin = $getuserde->user_id;
            } else {
                $getuser1 = DB::table('users')->where('user_id', $getuserde->added_user_id)->first();

                if ($getuser1->user_role == 'admin') {
                    $thisadmin = $getuser1->user_id;
                } else {
                    $getuser3 = DB::table('users')->where('user_id', $getuserde->added_user_id)->first();
                    $getuser31 = DB::table('users')->where('user_id', $getuser3->added_user_id)->first();
                    $thisadmin = $getuser31->user_id;
                }
            }

            $winningList = DB::table('winning_numbers')
                ->select(
                    DB::raw("DATE_FORMAT(winning_numbers.add_date, '%d-%m-%Y') AS add_date"),
                    'lotteries.lot_name',
                    'winning_numbers.number_win',
                    'users.username',
                    'lotteries.lot_colorcode'
                )
                ->join('lotteries', 'winning_numbers.lot_id', '=', 'lotteries.lot_id')
                ->join('users', 'users.user_id', '=', 'winning_numbers.added_by')
                ->where('lotteries.user_added_id', $thisadmin)
                ->orderBy('winning_numbers.win_id', 'DESC')
                ->limit(50)
                ->get();



            return response()->json([
                'msg' => 'Seller-specific action performed',
                'success' => true,
                'data' => $winningList

            ]);

    }





    public function getWinningOrders(Request $request){

        $user = auth()->user();
        $userIds = $request->input('user_ids');
        $fromdate = $request->input('fromdate');
        $todate = $request->input('todate');
        // dd($user->user_role);
        switch ($user->user_role) {
            case 'seller':
                return $this->sellerWinningList($user, $fromdate, $todate);
                break;
            case 'manager':
                return $this->managerWinningList($user, $userIds, $fromdate, $todate);
                break;
            case 'admin':
                return $this->adminWinningList($user, $userIds, $fromdate, $todate);
                break;
            case 'customer':
                return $this->customerWinningList($user, $fromdate, $todate);
                break;

            default:
                return response()->json(['success' => false, 'msg' => 'User Role not defined']);
        }




    }


    protected function customerWinningList($user, $fromdate, $todate)
{
    // Implement seller-specific logic here
    $userId = $user->user_id;
    $user = DB::table('users')->where('user_id', $userId)->first();
    
    // Convert fromdate and todate to MySQL-friendly format (Y-m-d)
    $fromDateFormatted = Carbon::createFromFormat('d M, Y', $fromdate)->format('Y-m-d');
    $toDateFormatted = Carbon::createFromFormat('d M, Y', $todate)->format('Y-m-d');

    if ($user) {
        $adminUserId = 1;

        $lotteries = DB::table('lotteries')->where('user_added_id', $adminUserId)->pluck('lot_id')->toArray();
// dd($lotteries);
        $query = DB::table('order_item')
            ->select(
                'lotteries.lot_name',
                DB::raw("CAST(LPAD(orders.order_id, 9, '0') AS CHAR) AS ticket_id"),
                'orders.client_name',
                'orders.client_contact',
                'order_item.lot_number',
                DB::raw("CAST(order_item.winning_amount AS CHAR) AS winning_amount"),
                DB::raw("CAST(order_item.order_item_id AS CHAR) AS order_item_id"),
                'order_item.lot_type',
                DB::raw("CAST(order_item.verify_status AS CHAR) AS verify_status"),
                DB::raw("DATE_FORMAT(orders.adddatetime, '%d-%m-%Y %H:%i:%s') AS adddatetime"),
                'users.username AS sellername',
                DB::raw("CAST(users.added_user_id AS CHAR) AS useraddedID"),
                'lotteries.lot_colorcode',
                DB::raw("CAST(CASE WHEN order_item.transaction_paid_id IS NULL THEN '0' ELSE 1 END AS CHAR) AS paidthis")
            )
            ->join('orders', 'order_item.order_id', '=', 'orders.order_id')
            ->join('lotteries', 'lotteries.lot_id', '=', 'order_item.product_id')
            ->join('users', 'users.user_id', '=', 'orders.user_id')
            ->where('order_item.lottery_gone', 1)
            ->where('order_item.winning_amount', '>', 0)
            ->where('orders.user_id', '=', $userId)
            ->whereIn('lotteries.lot_id', $lotteries)
            // ->whereBetween(DB::raw('DATE(orders.adddatetime)'), [$fromDateFormatted, $toDateFormatted]) // Date filter
            ->orderBy('orders.order_id', 'DESC');

        $sql = $query->get();
        // dd($sql);

        if ($sql->isEmpty()) {
            return response()->json([
                'msg' => 'No data found for this seller',
                'success' => false,
                'data' => []
            ]);
        }

        $data = $sql->map(function ($item) {
            return [
                'lot_name' => (string) $item->lot_name,
                'ticket_id' => (string) $item->ticket_id,
                'client_name' => (string) $item->client_name,
                'client_contact' => (string) $item->client_contact,
                'lot_number' => (string) $item->lot_number,
                'winning_amount' => (string) $item->winning_amount,
                'order_item_id' => (string) $item->order_item_id,
                'lot_type' => (string) $item->lot_type,
                'verify_status' => (string) $item->verify_status,
                'adddatetime' => (string) $item->adddatetime,
                'sellername' => (string) $item->sellername,
                'useraddedID' => (string) $item->useraddedID,
                'lot_colorcode' => (string) $item->lot_colorcode,
                'managername' => "",
                'paidthis' => (string) $item->paidthis
            ];
        });

        return response()->json([
            'msg' => 'Seller-specific action performed',
            'success' => true,
            'data' => $data
        ]);
    }

    return response()->json(['msg' => 'Seller-specific action performed']);
}

    protected function sellerWinningList($user, $fromdate, $todate)
{
    // Implement seller-specific logic here
    $userId = $user->user_id;
    $user = DB::table('users')->where('user_id', $userId)->first();
    
    $fromDateFormatted = Carbon::createFromFormat('d M, Y', $fromdate)->format('Y-m-d');
    $toDateFormatted = Carbon::createFromFormat('d M, Y', $todate)->format('Y-m-d');

    if ($user) {
        $adminUserId = $this->getAdminUserId($user);

        $lotteries = DB::table('lotteries')->where('user_added_id', $adminUserId)->pluck('lot_id')->toArray();

        $query = DB::table('order_item')
            ->select(
                'lotteries.lot_name',
                DB::raw("CAST(LPAD(orders.order_id, 9, '0') AS CHAR) AS ticket_id"),
                'orders.client_name',
                'orders.client_contact',
                'order_item.lot_number',
                DB::raw("CAST(order_item.winning_amount AS CHAR) AS winning_amount"),
                DB::raw("CAST(order_item.order_item_id AS CHAR) AS order_item_id"),
                'order_item.lot_type',
                DB::raw("CAST(order_item.verify_status AS CHAR) AS verify_status"),
                DB::raw("DATE_FORMAT(orders.adddatetime, '%d-%m-%Y %H:%i:%s') AS adddatetime"),
                'users.username AS sellername',
                DB::raw("CAST(users.added_user_id AS CHAR) AS useraddedID"),
                'lotteries.lot_colorcode',
                'm.username AS managername',
                DB::raw("CAST(CASE WHEN order_item.transaction_paid_id IS NULL THEN '0' ELSE 1 END AS CHAR) AS paidthis")
            )
            ->join('orders', 'order_item.order_id', '=', 'orders.order_id')
            ->join('lotteries', 'lotteries.lot_id', '=', 'order_item.product_id')
            ->join('users', 'users.user_id', '=', 'orders.user_id')
            ->join('users as m', 'm.user_id', '=', 'users.added_user_id')
            ->where('order_item.lottery_gone', 1)
            ->where('order_item.winning_amount', '>', 0)
            ->where('orders.user_id', '=', $userId)
            ->whereIn('lotteries.lot_id', $lotteries)
            ->whereBetween(DB::raw('DATE(orders.adddatetime)'), [$fromDateFormatted, $toDateFormatted]) // Date filter
            ->orderBy('orders.order_id', 'DESC');

        $sql = $query->get();

        if ($sql->isEmpty()) {
            return response()->json([
                'msg' => 'No data found for this seller',
                'success' => false,
                'data' => []
            ]);
        }

        $data = $sql->map(function ($item) {
            return [
                'lot_name' => (string) $item->lot_name,
                'ticket_id' => (string) $item->ticket_id,
                'client_name' => (string) $item->client_name,
                'client_contact' => (string) $item->client_contact,
                'lot_number' => (string) $item->lot_number,
                'winning_amount' => (string) $item->winning_amount,
                'order_item_id' => (string) $item->order_item_id,
                'lot_type' => (string) $item->lot_type,
                'verify_status' => (string) $item->verify_status,
                'adddatetime' => (string) $item->adddatetime,
                'sellername' => (string) $item->sellername,
                'useraddedID' => (string) $item->useraddedID,
                'lot_colorcode' => (string) $item->lot_colorcode,
                'managername' => (string) $item->managername,
                'paidthis' => (string) $item->paidthis
            ];
        });

        return response()->json([
            'msg' => 'Seller-specific action performed',
            'success' => true,
            'data' => $data
        ]);
    }

    return response()->json(['msg' => 'Seller-specific action performed']);
}


protected function managerWinningList($user, $userIds, $fromdate, $todate)
{
    $managerId = $user->user_id;

    // Get the admin user ID associated with the manager
    $adminUserId = $this->getAdminUserId($user);

    // Get lotteries added by the admin
    $lotteries = DB::table('lotteries')
        ->where('user_added_id', $adminUserId)
        ->pluck('lot_id')
        ->toArray();

    // If userIds are provided, filter based on those seller IDs
    if (!empty($userIds)) {
        $sellerIds = $userIds;
    } else {
        // If no userIds are provided, get sellers added by the manager
        $sellerIds = DB::table('users')
            ->where('added_user_id', $managerId)
            ->where('user_role', 'seller')
            ->pluck('user_id')
            ->toArray();
    }

    // Convert fromdate and todate to MySQL-friendly format (Y-m-d)
    $fromDateFormatted = Carbon::createFromFormat('d M, Y', $fromdate)->format('Y-m-d');
    $toDateFormatted = Carbon::createFromFormat('d M, Y', $todate)->format('Y-m-d');

    // Build the query
    $query = DB::table('order_item')
        ->select(
            'lotteries.lot_name',
            DB::raw("CAST(LPAD(orders.order_id, 9, '0') AS CHAR) AS ticket_id"),
            'orders.client_name',
            'orders.client_contact',
            'order_item.lot_number',
            DB::raw("CAST(order_item.winning_amount AS CHAR) AS winning_amount"),
            DB::raw("CAST(order_item.order_item_id AS CHAR) AS order_item_id"),
            'order_item.lot_type',
            DB::raw("CAST(order_item.verify_status AS CHAR) AS verify_status"),
            DB::raw("DATE_FORMAT(orders.adddatetime, '%d-%m-%Y %H:%i:%s') AS adddatetime"),
            'users.username AS sellername',
            DB::raw("CAST(users.added_user_id AS CHAR) AS useraddedID"),
            'lotteries.lot_colorcode',
            'm.username AS managername',
            DB::raw("CAST(CASE WHEN order_item.transaction_paid_id IS NULL THEN '0' ELSE 1 END AS CHAR) AS paidthis")
        )
        ->join('orders', 'order_item.order_id', '=', 'orders.order_id')
        ->join('lotteries', 'lotteries.lot_id', '=', 'order_item.product_id')
        ->join('users', 'users.user_id', '=', 'orders.user_id')
        ->join('users as m', 'm.user_id', '=', 'users.added_user_id')
        ->where('order_item.lottery_gone', 1)
        ->where('order_item.winning_amount', '>', 0)
        ->whereIn('orders.user_id', $sellerIds) // Filter based on seller IDs
        ->whereIn('lotteries.lot_id', $lotteries) // Only lotteries added by the admin
        ->whereBetween(DB::raw('DATE(orders.adddatetime)'), [$fromDateFormatted, $toDateFormatted]) // Date filter
        ->orderBy('orders.order_id', 'DESC');

    $sql = $query->get();

    if ($sql->isEmpty()) {
        return response()->json([
            'msg' => 'No data found for this manager',
            'success' => false,
            'data' => []
        ]);
    }

    // Convert each item in the result to strings
    $data = $sql->map(function ($item) {
        return [
            'lot_name' => (string) $item->lot_name,
            'ticket_id' => (string) $item->ticket_id,
            'client_name' => (string) $item->client_name,
            'client_contact' => (string) $item->client_contact,
            'lot_number' => (string) $item->lot_number,
            'winning_amount' => (string) $item->winning_amount,
            'order_item_id' => (string) $item->order_item_id,
            'lot_type' => (string) $item->lot_type,
            'verify_status' => (string) $item->verify_status,
            'adddatetime' => (string) $item->adddatetime,
            'sellername' => (string) $item->sellername,
            'useraddedID' => (string) $item->useraddedID,
            'lot_colorcode' => (string) $item->lot_colorcode,
            'managername' => (string) $item->managername,
            'paidthis' => (string) $item->paidthis
        ];
    });

    return response()->json([
        'msg' => 'Manager-specific action performed',
        'success' => true,
        'data' => $data
    ]);
}



protected function adminWinningList($user, $userIds, $fromdate, $todate)
{
    $adminUserId = $user->user_id;
    
    // Separate manager and seller ids from userIds
    $managerIds = [];
    $sellerIds = [];

    if (!empty($userIds)) {
        foreach ($userIds as $id) {
            $role = DB::table('users')->where('user_id', $id)->value('user_role');
            if ($role == 'manager') {
                $managerIds[] = $id;
            } else {
                $sellerIds[] = $id;
            }
        }

        // Find sellers for each manager
        if (!empty($managerIds)) {
            $managerSellerIds = DB::table('users')
                ->whereIn('added_user_id', $managerIds)
                ->where('user_role', 'seller')
                ->pluck('user_id')
                ->toArray();

            // Merge sellerIds with managerSellerIds
            $sellerIds = array_merge($sellerIds, $managerSellerIds);
        }
    }
    
    // Convert fromdate and todate to MySQL-friendly format (Y-m-d H:i:s)
    $fromDate = Carbon::createFromFormat('d M, Y', $fromdate)->startOfDay()->format('Y-m-d H:i:s');
    $toDate = Carbon::createFromFormat('d M, Y', $todate)->endOfDay()->format('Y-m-d H:i:s');
    
    // Build the query
    $query = DB::table('order_item')
        ->select(
            'lotteries.lot_name',
            DB::raw("CAST(LPAD(orders.order_id, 9, '0') AS CHAR) AS ticket_id"),
            'orders.client_name',
            'orders.client_contact',
            'order_item.lot_number',
            DB::raw("CAST(order_item.winning_amount AS CHAR) AS winning_amount"),
            DB::raw("CAST(order_item.order_item_id AS CHAR) AS order_item_id"),
            'order_item.lot_type',
            DB::raw("CAST(order_item.verify_status AS CHAR) AS verify_status"),
            DB::raw("DATE_FORMAT(orders.adddatetime, '%d-%m-%Y %H:%i:%s') AS adddatetime"),
            'users.username AS sellername',
            DB::raw("CAST(users.added_user_id AS CHAR) AS useraddedID"),
            'lotteries.lot_colorcode',
            'm.username AS managername',
            DB::raw("CAST(CASE WHEN order_item.transaction_paid_id IS NULL THEN '0' ELSE 1 END AS CHAR) AS paidthis")
        )
        ->join('orders', 'order_item.order_id', '=', 'orders.order_id')
        ->join('lotteries', 'lotteries.lot_id', '=', 'order_item.product_id')
        ->join('users', 'users.user_id', '=', 'orders.user_id')
        ->leftJoin('users as m', 'm.user_id', '=', 'users.added_user_id')
        ->where('order_item.lottery_gone', 1)
        ->where('order_item.winning_amount', '>', 0)
        ->where('lotteries.user_added_id', $adminUserId);

    // Apply seller filtering if sellerIds are provided
    if (!empty($sellerIds)) {
        $query->whereIn('orders.user_id', $sellerIds);
    }
    
    $query->whereBetween(DB::raw('DATE(orders.adddatetime)'), [$fromDate, $toDate]);
    $query->orderBy('orders.order_id', 'DESC');

    $sql = $query->get();

    if ($sql->isEmpty()) {
        return response()->json([
            'msg' => 'No data found for this admin',
            'success' => false,
            'data' => []
        ]);
    }

    // Convert each item in the result to strings
    $data = $sql->map(function ($item) {
        return [
            'lot_name' => (string) $item->lot_name,
            'ticket_id' => (string) $item->ticket_id,
            'client_name' => (string) $item->client_name,
            'client_contact' => (string) $item->client_contact,
            'lot_number' => (string) $item->lot_number,
            'winning_amount' => (string) $item->winning_amount,
            'order_item_id' => (string) $item->order_item_id,
            'lot_type' => (string) $item->lot_type,
            'verify_status' => (string) $item->verify_status,
            'adddatetime' => (string) $item->adddatetime,
            'sellername' => (string) $item->sellername,
            'useraddedID' => (string) $item->useraddedID,
            'lot_colorcode' => (string) $item->lot_colorcode,
            'managername' => (string) $item->managername,
            'paidthis' => (string) $item->paidthis
        ];
    });

    return response()->json([
        'msg' => 'admin-specific action performed',
        'success' => true,
        'data' => $data
    ]);
}



private function getAdminUserId($user)
    {
        if ($user->user_role == 'admin') {
            return $user->user_id;
        }

        $addedUser = DB::table('users')->where('user_id', $user->added_user_id)->first();

        if ($addedUser->user_role == 'admin') {
            return $addedUser->user_id;
        }

        $addedUser2 = DB::table('users')->where('user_id', $addedUser->added_user_id)->first();
        return $addedUser2->user_id;
    }



}
