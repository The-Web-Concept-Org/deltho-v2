<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Lottery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WebController extends Controller
{
    public function dashboard()
    {
        $user = session('user_details');
        if ($user['user_role'] == 'superadmin') {
            $users = User::where('user_role', 'admin')->where('user_id', '<>', $user['user_id'])->where('status', 1)->get();
        } elseif ($user['user_role'] == 'admin') {
            $users = User::where(function ($query) use ($user) {
                $query->where('user_role', 'manager')
                    ->where('added_user_id', $user['user_id'])
                    ->where('status', 1);
            })->orWhere('user_id', $user['user_id'])
                ->get();
        } elseif ($user['user_role'] == 'manager') {
            $users = User::where('user_id', $user['user_id'])->get();
        }
        $lotteries = Lottery::get();

        return view('dashboard', ['users' => $users, 'lotteries' => $lotteries]);
    }

    public function getManagers($adminId)
    {
        // Fetch managers and sellers based on the admin ID
        $managers = User::where('added_user_id', $adminId)
            ->whereIn('user_role', ['manager', 'seller'])
            ->where('status', '1')
            ->get(['user_id as id', 'username', 'user_role as role']);

        return response()->json($managers);
    }

    public function saleReport(Request $request)
    {
        $lotIds = $request->input('lottery', []);
        $managerIds = $request->input('manager_ids', []);
        $adminId = $request->input('admin_id');
        $user = session('user_details');
        $userId = $user['user_id'];
        $userRole = $user['user_role'];
        $fromDate = $request->input('fromdate');
        $toDate = $request->input('todate');

        try {
            $fromDateCarbon = Carbon::createFromFormat('Y-m-d', $fromDate);
            $toDateCarbon = Carbon::createFromFormat('Y-m-d', $toDate);
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            return response()->json(['error' => 'Invalid date format.'], 400);
        }

        $fromDate = $fromDateCarbon->startOfDay()->format('Y-m-d H:i:s');
        $toDate = $toDateCarbon->endOfDay()->format('Y-m-d H:i:s');

        $lotteries = DB::table('lotteries')->get();
        if ($lotteries->isEmpty()) {
            return response()->json(['error' => 'Invalid lottery.'], 404);
        }

        $sellerIds = [];
        $users = [];
        if ($userRole == 'superadmin' || $userRole == 'manager' || $userRole == 'admin') {
            if (!empty($managerIds) && !(count($managerIds) === 1 && $managerIds[0] == null)) {
                // Filter out any null values from the array
                $managerIds = array_filter($managerIds, function ($id) {
                    return !is_null($id);
                });

                // Proceed only if there are valid manager IDs after filtering
                if (!empty($managerIds)) {
                    foreach ($managerIds as $id) {
                        $role = DB::table('users')->where('user_id', $id)->value('user_role');
                        if ($role == 'manager') {
                            $managerIdsFiltered[] = $id;
                        } elseif ($role == 'seller') {
                            $sellerIds[] = $id;
                        }
                    }

                    if (!empty($managerIdsFiltered)) {
                        $sellersFromManagers = DB::table('users')
                            ->whereIn('added_user_id', $managerIdsFiltered)
                            ->where('status', 1)
                            ->pluck('user_id')
                            ->toArray();
                        $sellerIds = array_merge($sellerIds, $sellersFromManagers);
                    }
                }
            } else {
                $adminManagersAndSellers = DB::table('users')->where('added_user_id', $adminId)->where('status', 1)->pluck('user_id');
                foreach ($adminManagersAndSellers as $id) {
                    $role = DB::table('users')->where('user_id', $id)->value('user_role');
                    if ($role == 'manager') {
                        $managerIdsFiltered[] = $id;
                    } elseif ($role == 'seller') {
                        $sellerIds[] = $id;
                    }
                }
                if (!empty($managerIdsFiltered)) {
                    $sellersFromManagers = DB::table('users')
                        ->whereIn('added_user_id', $managerIdsFiltered)
                        ->where('status', 1)
                        ->pluck('user_id')
                        ->toArray();
                    $sellerIds = array_merge($sellerIds, $sellersFromManagers);
                }
            }
            $sellers = DB::table('users')
                ->whereIn('user_id', $sellerIds)
                ->where('status', 1)
                ->get();
        } else {
            if ($userRole == 'admin' || $userRole == 'manager') {
                if (!empty($managerIds)) {
                    foreach ($managerIds as $managerId) {
                        if ($userRole === 'admin') {
                            if (empty($managerIds)) {
                                return response()->json(['error' => 'Manager IDs are required for admin role.'], 400);
                            }

                            $sellers = [];
                            $sellerIds = [];

                            // Loop through each manager ID
                            foreach ($managerIds as $managerId) {
                                // Fetch the user role for the provided managerId
                                $userRoleCheck = DB::table('users')
                                    ->where('user_id', $managerId)
                                    ->value('user_role');

                                if ($userRoleCheck === 'manager') {
                                    // If the user is a manager, get the sellers under that manager
                                    $sellerIdsForManager = DB::table('users')
                                        ->where('added_user_id', $managerId)
                                        ->where('status', 1)
                                        ->pluck('user_id')
                                        ->toArray();

                                    // Merge seller IDs
                                    $sellerIds = array_merge($sellerIds, $sellerIdsForManager);

                                    // Also, add these sellers to the $sellers array
                                    $managerallsellers = DB::table('users')
                                        ->whereIn('user_id', $sellerIdsForManager)
                                        ->where('status', 1)
                                        ->get()
                                        ->toArray();

                                    $sellers = array_merge($sellers, $managerallsellers);
                                } elseif ($userRoleCheck === 'seller') {
                                    // If the user is a seller, directly add the seller ID to $sellerIds
                                    $sellerIds[] = $managerId;

                                    $admineachseller = DB::table('users')
                                        ->where('user_id', $managerId)
                                        ->first();

                                    // Handle adding $admineachseller to $sellers
                                    if (!empty($admineachseller)) {
                                        $sellers[] = $admineachseller;
                                    }
                                }
                            }
                        } elseif ($userRole === 'manager') {
                            $sellers = DB::table('users')
                                ->where('user_id', $managerId)
                                ->where('status', 1)
                                ->pluck('user_id')
                                ->toArray();
                            $sellerIds = array_merge($sellerIds, $sellers);
                        } else {
                            $sellerIds[] = $userId;
                        }
                        $managers = DB::table('users')->where('user_id', $managerId)->first();
                        $users[$managerId] = $managers;
                    }
                } else {
                    if ($userRole === 'manager') {
                        $sellers = DB::table('users')
                            ->where('added_user_id', $userId)
                            ->where('status', 1)
                            ->get();
                    } elseif ($userRole === 'admin') {
                        $managers = DB::table('users')
                            ->where('added_user_id', $userId)
                            ->where('status', 1)
                            ->where('user_role', 'manager')
                            ->pluck('user_id')
                            ->toArray();
                        // Get sellers directly under the admin
                        $adminSellers = DB::table('users')
                            ->where('added_user_id', $userId)
                            ->where('status', 1)
                            ->where('user_role', 'seller')
                            ->pluck('user_id')
                            ->toArray();

                        // Get sellers under the managers
                        $sellersFromManagers = DB::table('users')
                            ->whereIn('added_user_id', $managers)
                            ->where('status', 1)
                            ->where('user_role', 'seller')
                            ->pluck('user_id')
                            ->toArray();

                        // Combine both arrays of seller IDs
                        $combinedSellers = array_merge($adminSellers, $sellersFromManagers);

                        // If you want to get detailed seller information, you can query using the combined seller IDs
                        $sellers = DB::table('users')
                            ->whereIn('user_id', $combinedSellers)
                            ->where('status', 1)
                            ->where('user_role', 'seller')
                            ->get();
                    }
                    $combinedUserData = [
                        'lotteryName' => [],
                        'totalSold' => 0,
                        'commission' => 0,
                        'winnings' => 0,
                        'balance' => 0,
                        'winningNumbersTotal' => 0,
                        'totalReceipts' => 0,
                        'orderTotalAmount' => 0,
                        'advance' => 0,
                        'date' => $fromDate . ' - ' . $toDate,
                    ];
                    foreach ($sellers as $user) {
                        $username = $user->username;
                        $userId = $user->user_id;
                        $userData = [
                            'lotteryName' => [],
                            'totalSold' => 0,
                            'commission' => 0,
                            'winnings' => 0,
                            'balance' => 0,
                            'winningNumbersTotal' => 0,
                            'totalReceipts' => 0,
                            'orderTotalAmount' => 0,
                            'advance' => 0,
                            'date' => $fromDate . ' - ' . $toDate,
                        ];

                        foreach ($lotteries as $lottery) {
                            $lotteryId = $lottery->lot_id;
                            $userData['lotteryName'][$lotteryId] = $lottery->lot_name;
                            $userData['totalSold'] += $this->getTotalSold($userId, $lotteryId, $fromDate, $toDate);
                            // $userData['commission'] += (int) str_replace(',', '', $this->getCommission($userId, $lotteryId, $fromDate, $toDate, $user->commission));
                            $userData['commission'] += (($this->getTotalSold($userId, $lotteryId, $fromDate, $toDate) / 100) * $user->commission);
                            $userData['winnings'] += $this->getWinnings($userId, $lotteryId, $fromDate, $toDate);
                            $userData['balance'] += (int) str_replace(',', '', $this->getBalance($userId, $lotteryId, $fromDate, $toDate, $user->commission));
                            $userData['winningNumbersTotal'] += $this->getWinningNumbersTotal($userId, $lotteryId, $fromDate, $toDate);
                        }

                        $orders = DB::table('orders')
                            ->where('user_id', $userId)
                            ->whereBetween('order_date', [$fromDate, $toDate])
                            ->get();
                        $advance = DB::table('loans')->where('seller_id', $userId)->whereBetween('adddatetime', [$fromDate, $toDate])->sum('credit');
                        $userData['totalReceipts'] += $orders->count();
                        $userData['orderTotalAmount'] += $orders->sum('grand_total');
                        $userData['advance'] = $advance;

                        $salesData[$username] = $userData;
                    }
                    // foreach ($sellers as $seller) {
                    //     foreach ($lotteries as $lottery) {
                    //         $combinedUserData['lotteryName'][$lottery->lot_id] = $lottery->lot_name;
                    //         $combinedUserData['totalSold'] += $this->getTotalSold($seller->user_id, $lottery->lot_id, $fromDate, $toDate);
                    //         $combinedUserData['commission'] += (int) str_replace(',', '', $this->getCommission($seller->user_id, $lottery->lot_id, $fromDate, $toDate, $seller->commission));
                    //         $combinedUserData['winnings'] += $this->getWinnings($seller->user_id, $lottery->lot_id, $fromDate, $toDate);
                    //         $combinedUserData['balance'] += (int) str_replace(',', '', $this->getBalance($seller->user_id, $lottery->lot_id, $fromDate, $toDate, $seller->commission));
                    //         $combinedUserData['winningNumbersTotal'] += $this->getWinningNumbersTotal($seller->user_id, $lottery->lot_id, $fromDate, $toDate);
                    //     }

                    //     $orders = DB::table('orders')
                    //         ->where('user_id', $seller->user_id)
                    //         ->whereBetween('order_date', [$fromDate, $toDate])
                    //         ->get();

                    //     $combinedUserData['totalReceipts'] += $orders->count();
                    //     $combinedUserData['orderTotalAmount'] += $orders->sum('grand_total');
                    // }

                    // $salesData[$user->username] = $combinedUserData;

                }
            } else {
                $sellerIds[] = $userId;
                $users[$userId] = $user;
            }
        }
        if (!empty($sellerIds)) {
            if ($user['user_role'] == 'admin' || $user['user_role'] == 'superadmin' || $user['user_role'] == 'manager') {
                foreach ($sellers as $user) {
                    $username = $user->username;
                    $userId = $user->user_id;
                    $userData = [
                        'lotteryName' => [],
                        'totalSold' => 0,
                        'commission' => 0,
                        'sellerCommission' => 0,
                        'winnings' => 0,
                        'balance' => 0,
                        'winningNumbersTotal' => 0,
                        'totalReceipts' => 0,
                        'managername' => '',
                        'orderTotalAmount' => 0,
                        'advance' => 0,
                        'date' => $fromDate . ' - ' . $toDate,
                        'managerData' => [],
                    ];

                    $managername = User::where('user_id', $user->added_user_id)->first();

                    foreach ($lotteries as $lottery) {
                        $lotteryId = $lottery->lot_id;
                        $userData['lotteryName'][$lotteryId] = $lottery->lot_name;
                        $userData['totalSold'] += $this->getTotalSold($userId, $lotteryId, $fromDate, $toDate);
                        // $userData['commission'] += (int) str_replace(',', '', $this->getCommission($userId, $lotteryId, $fromDate, $toDate, $user->commission));
                        $userData['commission'] += (($this->getTotalSold($userId, $lotteryId, $fromDate, $toDate) / 100) * $user->commission);
                        $userData['winnings'] += $this->getWinnings($userId, $lotteryId, $fromDate, $toDate);
                        $userData['balance'] += (int) str_replace(',', '', $this->getBalance($userId, $lotteryId, $fromDate, $toDate, $user->commission));
                        $userData['winningNumbersTotal'] += $this->getWinningNumbersTotal($userId, $lotteryId, $fromDate, $toDate);
                    }

                    $orders = DB::table('orders')
                        ->where('user_id', $userId)
                        ->whereBetween('order_date', [$fromDate, $toDate])
                        ->get();

                    $advance = DB::table('loans')->where('seller_id', $userId)->whereBetween('adddatetime', [$fromDate, $toDate])->sum('credit');

                    $userData['totalReceipts'] += $orders->count();
                    $userData['orderTotalAmount'] += $orders->sum('grand_total');
                    $userData['advance'] = $advance;
                    $userData['managername'] = $managername->username;
                    $userData['managerData'] = $managername;
                    $userData['sellerCommission'] = $user->commission;

                    $salesData[$username] = $userData;
                }
            } else {
                foreach ($users as $user) {
                    $username = $user->username;
                    $userId = $user->user_id;
                    $userData = [
                        'lotteryName' => [],
                        'totalSold' => 0,
                        'commission' => 0,
                        'winnings' => 0,
                        'balance' => 0,
                        'winningNumbersTotal' => 0,
                        'totalReceipts' => 0,
                        'orderTotalAmount' => 0,
                        'advance' => 0,
                        'date' => $fromDate . ' - ' . $toDate,
                    ];

                    foreach ($lotteries as $lottery) {
                        $lotteryId = $lottery->lot_id;
                        $userData['lotteryName'][$lotteryId] = $lottery->lot_name;
                        $userData['totalSold'] += $this->getTotalSold($userId, $lotteryId, $fromDate, $toDate);
                        // $userData['commission'] += (int) str_replace(',', '', $this->getCommission($userId, $lotteryId, $fromDate, $toDate, $user->commission));
                        $userData['commission'] += (($this->getTotalSold($userId, $lotteryId, $fromDate, $toDate) / 100) * $user->commission);
                        $userData['winnings'] += $this->getWinnings($userId, $lotteryId, $fromDate, $toDate);
                        $userData['balance'] += (int) str_replace(',', '', $this->getBalance($userId, $lotteryId, $fromDate, $toDate, $user->commission));
                        $userData['winningNumbersTotal'] += $this->getWinningNumbersTotal($userId, $lotteryId, $fromDate, $toDate);
                    }

                    $advance = DB::table('loans')->where('seller_id', $userId)->whereBetween('adddatetime', [$fromDate, $toDate])->sum('credit');

                    $orders = DB::table('orders')
                        ->where('user_id', $userId)
                        ->whereBetween('order_date', [$fromDate, $toDate])
                        ->get();

                    $userData['totalReceipts'] += $orders->count();
                    $userData['orderTotalAmount'] += $orders->sum('grand_total');
                    $userData['advance'] = $advance;
                    $salesData[$username] = $userData;
                }
            }
        }
        return response()->json(['success' => true, 'data' => $salesData], 200);
        // return view('saleReport', ['data' => $salesData]);
    }
    // The private helper functions would remain the same as you provided earlier


    private function getAmount($userId, $lotId, $numberN, $fromDate, $toDate)
    {
        $total = 0;
        $ordersList = Order::where('user_id', $userId)
            ->whereBetween('order_date', [$fromDate, $toDate])
            ->pluck('order_id')
            ->toArray();



        $totalSold = OrderItem::where('product_id', $lotId)
            ->whereIn('order_id', $ordersList)
            ->where('lot_number', $numberN)
            ->sum('lot_amount');

        return number_format($totalSold * 20);
    }

    private function getTotalSold($userId, $lotId, $fromDate, $toDate)
    {
        $ordersList = Order::where('user_id', $userId)
            ->whereBetween('order_date', [$fromDate, $toDate])
            ->pluck('order_id')
            ->toArray();

        $totalSold = OrderItem::where('product_id', $lotId)
            ->whereIn('order_id', $ordersList)
            ->sum('lot_amount');

        return $totalSold;
    }

    private function getCommission($userId, $lotId, $fromDate, $toDate, $commission)
    {
        $totalSold = $this->getTotalSold($userId, $lotId, $fromDate, $toDate);

        // Convert to integer (in cents) before returning
        return intval(($totalSold * $commission) / 100);
    }

    private function getWinnings($userId, $lotId, $fromDate, $toDate)
    {
        $ordersList = Order::where('user_id', $userId)
            ->whereBetween('order_date', [$fromDate, $toDate])
            ->pluck('order_id')
            ->toArray();

        $winnings = OrderItem::whereIn('order_id', $ordersList)
            ->where('product_id', $lotId)
            ->whereDate('adddatetime', '>=', $fromDate)
            ->whereDate('adddatetime', '<=', $toDate)
            ->sum('winning_amount');

        // Return as integer
        return intval($winnings);
    }

    private function getBalance($userId, $lotId, $fromDate, $toDate, $commission)
    {
        $totalSold = $this->getTotalSold($userId, $lotId, $fromDate, $toDate);
        $commission = $this->getCommission($userId, $lotId, $fromDate, $toDate, $commission);
        $winnings = $this->getWinnings($userId, $lotId, $fromDate, $toDate);

        // Perform calculations with integers and return as integer
        $balance = $totalSold - $commission - $winnings;

        return intval($balance);
    }


    private function getWinningNumbersTotal($userId, $lotId, $fromDate, $toDate)
    {
        $ordersList = Order::where('user_id', $userId)
            ->whereBetween('order_date', [$fromDate, $toDate])
            ->pluck('order_id')
            ->toArray();

        $winningNumbersTotal = OrderItem::whereIn('order_id', $ordersList)
            ->where('product_id', $lotId)
            ->whereDate('adddatetime', '>=', $fromDate)
            ->whereDate('adddatetime', '<=', $toDate)
            ->where('winning_amount', '>', 0)
            ->distinct('order_id')
            ->count('order_id');

        return $winningNumbersTotal;
    }
}
