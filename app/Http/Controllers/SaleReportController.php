<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Lottery;
use App\Models\User;
use Carbon\Carbon;
use DatePeriod;
use DateTime;
use DateInterval;

class SaleReportController extends Controller
{
    protected $preAggregates = [
        'rangeKey' => null,
        'byDay' => [
            'sold' => [],            // [userId][lotId][Y-m-d] => int
            'winnings' => [],        // [userId][lotId][Y-m-d] => int
            'winning_orders' => [],  // [userId][lotId][Y-m-d] => int
            'orders' => [],          // [userId][Y-m-d] => ['count' => int, 'sum' => int]
            'loans' => [],           // [userId][Y-m-d] => int (credit)
            'transactions' => [],    // [userId][Y-m-d] => ['deposit' => int, 'withdraw' => i7nt, 'commission' => int]
        ],
        'totals' => [
            'sold' => [],            // [userId][lotId] => int
            'winnings' => [],        // [userId][lotId] => int
            'winning_orders' => [],  // [userId][lotId] => int
            'orders' => [],          // [userId] => ['count' => int, 'sum' => int]
            'loans' => [],           // [userId] => int
            'transactions' => [],    // [userId] => ['deposit' => int, 'withdraw' => int, 'commission' => int]
        ],
    ];

    private function prepareAggregates(array $sellerIds, array $lotIds, string $fromDate, string $toDate): void
    {
        // Build a stable key to avoid recomputing
        $rangeKey = md5(json_encode([$sellerIds, $lotIds, $fromDate, $toDate]));
        if ($this->preAggregates['rangeKey'] === $rangeKey) {
            return;
        }

        $this->preAggregates = [
            'rangeKey' => $rangeKey,
            'byDay' => [
                'sold' => [],
                'winnings' => [],
                'winning_orders' => [],
                'orders' => [],
                'loans' => [],
                'transactions' => [],
            ],
            'totals' => [
                'sold' => [],
                'winnings' => [],
                'winning_orders' => [],
                'orders' => [],
                'loans' => [],
                'transactions' => [],
            ],
        ];

        if (empty($sellerIds) || empty($lotIds)) {
            return;
        }

        $fromDateTime = $fromDate . ' 00:00:00';
        $toDateTime = $toDate . ' 23:59:59';

        // Aggregate order_item joined with orders for sold, winnings, and winning order counts grouped by day
        $orderItemRows = DB::table('order_item as oi')
            ->join('orders as o', 'o.order_id', '=', 'oi.order_id')
            ->select(
                'o.user_id as user_id',
                'oi.product_id as lot_id',
                DB::raw('DATE(o.order_date) as day'),
                DB::raw('SUM(oi.lot_amount) as sold_sum'),
                // winnings only when oi.adddatetime within range
                DB::raw("SUM(CASE WHEN DATE(oi.adddatetime) >= '$fromDate' AND DATE(oi.adddatetime) <= '$toDate' THEN oi.winning_amount ELSE 0 END) as win_sum"),
                DB::raw("COUNT(DISTINCT CASE WHEN oi.winning_amount > 0 AND DATE(oi.adddatetime) >= '$fromDate' AND DATE(oi.adddatetime) <= '$toDate' THEN oi.order_id END) as winning_orders_cnt")
            )
            ->whereIn('o.user_id', $sellerIds)
            ->whereBetween('o.order_date', [$fromDateTime, $toDateTime])
            ->whereIn('oi.product_id', $lotIds)
            ->groupBy('o.user_id', 'oi.product_id', DB::raw('DATE(o.order_date)'))
            ->get();

        foreach ($orderItemRows as $row) {
            $uid = (int)$row->user_id;
            $lid = (int)$row->lot_id;
            $day = $row->day;
            $sold = (int)$row->sold_sum;
            $win = (int)$row->win_sum;
            $wcnt = (int)$row->winning_orders_cnt;

            $this->preAggregates['byDay']['sold'][$uid][$lid][$day] = ($this->preAggregates['byDay']['sold'][$uid][$lid][$day] ?? 0) + $sold;
            $this->preAggregates['byDay']['winnings'][$uid][$lid][$day] = ($this->preAggregates['byDay']['winnings'][$uid][$lid][$day] ?? 0) + $win;
            $this->preAggregates['byDay']['winning_orders'][$uid][$lid][$day] = ($this->preAggregates['byDay']['winning_orders'][$uid][$lid][$day] ?? 0) + $wcnt;

            $this->preAggregates['totals']['sold'][$uid][$lid] = ($this->preAggregates['totals']['sold'][$uid][$lid] ?? 0) + $sold;
            $this->preAggregates['totals']['winnings'][$uid][$lid] = ($this->preAggregates['totals']['winnings'][$uid][$lid] ?? 0) + $win;
            $this->preAggregates['totals']['winning_orders'][$uid][$lid] = ($this->preAggregates['totals']['winning_orders'][$uid][$lid] ?? 0) + $wcnt;
        }

        // Aggregate orders by day: count and sum
        $orderRows = DB::table('orders')
            ->select(
                'user_id',
                DB::raw('DATE(order_date) as day'),
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(grand_total) as sum_total')
            )
            ->whereIn('user_id', $sellerIds)
            ->whereBetween('order_date', [$fromDateTime, $toDateTime])
            ->groupBy('user_id', DB::raw('DATE(order_date)'))
            ->get();

        foreach ($orderRows as $row) {
            $uid = (int)$row->user_id;
            $day = $row->day;
            $cnt = (int)$row->cnt;
            $sum = (int)$row->sum_total;
            $this->preAggregates['byDay']['orders'][$uid][$day] = ['count' => $cnt, 'sum' => $sum];
            $agg = $this->preAggregates['totals']['orders'][$uid] ?? ['count' => 0, 'sum' => 0];
            $agg['count'] += $cnt;
            $agg['sum'] += $sum;
            $this->preAggregates['totals']['orders'][$uid] = $agg;
        }

        // Aggregate loans (credits) by day
        $loanRows = DB::table('loans')
            ->select('seller_id as user_id', DB::raw('DATE(adddatetime) as day'), DB::raw('SUM(credit) as credit_sum'))
            ->whereIn('seller_id', $sellerIds)
            ->whereBetween('adddatetime', [$fromDateTime, $toDateTime])
            ->groupBy('seller_id', DB::raw('DATE(adddatetime)'))
            ->get();

        foreach ($loanRows as $row) {
            $uid = (int)$row->user_id;
            $day = $row->day;
            $sum = (int)$row->credit_sum;
            $this->preAggregates['byDay']['loans'][$uid][$day] = ($this->preAggregates['byDay']['loans'][$uid][$day] ?? 0) + $sum;
            $this->preAggregates['totals']['loans'][$uid] = ($this->preAggregates['totals']['loans'][$uid] ?? 0) + $sum;
        }

        // --- Sellers transactions ---
        $sellerTx = DB::table('transactions')
            ->select(
                'seller_id as user_id',
                DB::raw("'seller' as user_type"),
                DB::raw('DATE(transaction_add_date) as day'),
                DB::raw("SUM(CASE WHEN transaction_remarks = 'Deposit to customer' THEN debit ELSE 0 END) as deposit_sum"),
                DB::raw("SUM(CASE WHEN transaction_remarks = 'Withdraw from customer' THEN credit ELSE 0 END) as withdraw_sum"),
                DB::raw("SUM(CASE WHEN transaction_remarks = 'commission' THEN credit ELSE 0 END) as commission_sum")
            )
            ->whereIn('seller_id', $sellerIds)
            ->whereBetween('transaction_add_date', [$fromDateTime, $toDateTime])
            ->groupBy('seller_id', DB::raw('DATE(transaction_add_date)'));

        // --- Customers transactions ---
        $customerTx = DB::table('transactions')
            ->select(
                'customer_id as user_id',
                DB::raw("'customer' as user_type"),
                DB::raw('DATE(transaction_add_date) as day'),
                DB::raw("SUM(CASE WHEN transaction_remarks = 'Deposit received' THEN credit ELSE 0 END) as deposit_sum"),
                DB::raw("SUM(CASE WHEN transaction_remarks = 'Withdraw processed' THEN debit ELSE 0 END) as withdraw_sum"),
                DB::raw("SUM(CASE WHEN transaction_remarks = 'commission' THEN credit ELSE 0 END) as commission_sum")
            )
            ->whereIn('customer_id', $sellerIds)
            ->whereBetween('transaction_add_date', [$fromDateTime, $toDateTime])
            ->groupBy('customer_id', DB::raw('DATE(transaction_add_date)'));

        // --- Combine both results ---
        $txRows = $sellerTx->unionAll($customerTx)->get();


        foreach ($txRows as $row) {
            $uid = (int)$row->user_id;
            $day = $row->day;
            $deposit = (int)$row->deposit_sum;
            $withdraw = (int)$row->withdraw_sum;
            $commission = (int)$row->commission_sum;
            $this->preAggregates['byDay']['transactions'][$uid][$day] = [
                'deposit' => $deposit,
                'withdraw' => $withdraw,
                'commission' => $commission,
            ];
            $agg = $this->preAggregates['totals']['transactions'][$uid] ?? ['deposit' => 0, 'withdraw' => 0, 'commission' => 0];
            $agg['deposit'] += $deposit;
            $agg['withdraw'] += $withdraw;
            $agg['commission'] += $commission;
            $this->preAggregates['totals']['transactions'][$uid] = $agg;
        }
    }

    private function getSoldCached(int $userId, int $lotteryId, string $fromDate, string $toDate): int
    {
        if ($fromDate === $toDate) {
            return $this->preAggregates['byDay']['sold'][$userId][$lotteryId][$fromDate] ?? 0;
        }
        return $this->preAggregates['totals']['sold'][$userId][$lotteryId] ?? 0;
    }

    private function getWinningsCached(int $userId, int $lotteryId, string $fromDate, string $toDate): int
    {
        if ($fromDate === $toDate) {
            return $this->preAggregates['byDay']['winnings'][$userId][$lotteryId][$fromDate] ?? 0;
        }
        return $this->preAggregates['totals']['winnings'][$userId][$lotteryId] ?? 0;
    }

    private function getWinningOrdersCountCached(int $userId, int $lotteryId, string $fromDate, string $toDate): int
    {
        if ($fromDate === $toDate) {
            return $this->preAggregates['byDay']['winning_orders'][$userId][$lotteryId][$fromDate] ?? 0;
        }
        return $this->preAggregates['totals']['winning_orders'][$userId][$lotteryId] ?? 0;
    }

    private function getOrderStatsCached(int $userId, string $fromDate, string $toDate): array
    {
        if ($fromDate === $toDate) {
            return $this->preAggregates['byDay']['orders'][$userId][$fromDate] ?? ['count' => 0, 'sum' => 0];
        }
        return $this->preAggregates['totals']['orders'][$userId] ?? ['count' => 0, 'sum' => 0];
    }

    private function getLoanSumCached(int $userId, string $fromDate, string $toDate): int
    {
        if ($fromDate === $toDate) {
            return $this->preAggregates['byDay']['loans'][$userId][$fromDate] ?? 0;
        }
        return $this->preAggregates['totals']['loans'][$userId] ?? 0;
    }

    private function getTxSumsCached(int $userId, string $fromDate, string $toDate): array
    {
        if ($fromDate === $toDate) {
            return $this->preAggregates['byDay']['transactions'][$userId][$fromDate] ?? ['deposit' => 0, 'withdraw' => 0, 'commission' => 0];
        }
        return $this->preAggregates['totals']['transactions'][$userId] ?? ['deposit' => 0, 'withdraw' => 0, 'commission' => 0];
    }
    public function saleReport(Request $request)
    {
        $lotIds = $request->input('lottery', []);
        $managerIds = $request->input('manager_ids', []);
        $user = auth()->user();
        $userId = auth()->user()->user_id;
        $userRole = auth()->user()->user_role;
        $fromDate = $request->input('fromdate');
        $toDate = $request->input('todate');
        $lang = $request->input('languageCode');
        $reportType = $request->input('reportType');
        try {
            $fromDateCarbon = Carbon::createFromFormat('j M, Y', $fromDate);
            $toDateCarbon = Carbon::createFromFormat('j M, Y', $toDate);
        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            return response()->json(['error' => 'Invalid date format.'], 400);
        }

        $fromDate = $fromDateCarbon->format('Y-m-d');
        $toDate = $toDateCarbon->format('Y-m-d');

        $lotteries = DB::table('lotteries');
        if ($lotIds !== 'all') {
            $lotteries = $lotteries->whereIn('lot_id', $lotIds);
        }
        $lotteries = $lotteries->get();
        if ($lotteries->isEmpty()) {
            return response()->json(['error' => 'Invalid lottery.'], 404);
        }

        $sellerIds = [];
        $users = [];
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
                            } elseif ($userRoleCheck === 'seller' || $userRoleCheck === 'customer') {
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
                        // dd($sellerIds);
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

                    // Get sellers directly under admin
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

                    // Combine seller IDs
                    $combinedSellers = array_merge($adminSellers, $sellersFromManagers);

                    if ($reportType == 'customer') {
                        // Report for customers: get customers directly
                        $sellers = DB::table('users')
                            ->where('user_role', 'customer')
                            ->where('status', 1)
                            ->get();
                    } else {
                        // Report for sellers
                        $sellers = DB::table('users')
                            ->whereIn('user_id', $combinedSellers)
                            ->where('status', 1)
                            ->where('user_role', 'seller')
                            ->get();
                    }
                }

                // Initialize data
                $key = 'multiple';
                $salesData = [];
                $managerData = []; // Will only be built if needed

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
                        'totalDeposit' => 0,
                        'totalWithdraw' => 0,
                        'date' => $fromDate . ' - ' . $toDate,
                        'sellerCommission' => $user->commission,
                        'user_role' => $user->user_role,  // ✅ added here
                    ];

                    // Only get manager info if report type is NOT customer
                    $managername = null;
                    if ($reportType != 'customer') {
                        $managername = User::where('user_id', $user->added_user_id)->first();
                        $userData['managername'] = optional($managername)->username;
                        $userData['managerData'] = $managername;
                    }

                    // Loop through lotteries
                    // Prepare aggregates once for all sellers for this range
                    $allSellerIdsForAgg = array_map(function ($u) {
                        return $u->user_id;
                    }, $sellers->all());
                    $lotteryIdsForAgg = $lotteries->pluck('lot_id')->toArray();
                    $this->prepareAggregates($allSellerIdsForAgg, $lotteryIdsForAgg, $fromDate, $toDate);

                    foreach ($lotteries as $lottery) {
                        $lotteryId = $lottery->lot_id;
                        $totalSold = $this->getTotalSold($userId, $lotteryId, $fromDate, $toDate);
                        $userData['lotteryName'][$lotteryId] = $lottery->lot_name;
                        $userData['totalSold'] += $totalSold;
                        $userData['commission'] += ($totalSold / 100) * $user->commission;
                        $userData['winnings'] += $this->getWinnings($userId, $lotteryId, $fromDate, $toDate);
                        // $userData['balance'] += (int) str_replace(',', '', $this->getBalance($userId, $lotteryId, $fromDate, $toDate, $user->commission,$currentDate));
                        $userData['balance'] = $this->getBalance(
                            $userId,
                            $lotteryId,
                            $fromDate, // range start
                            $toDate,   // range end
                            $user->commission,
                            $toDate    // or maybe null if your function doesn’t need single-day
                        );
                        $userData['winningNumbersTotal'] += $this->getWinningNumbersTotal($userId, $lotteryId, $fromDate, $toDate);
                    }

                    // Orders & advances
                    $orderStats = $this->getOrderStatsCached($userId, $fromDate, $toDate);

                    $advance = $this->getLoanSumCached($userId, $fromDate, $toDate);
                    // $userData['totalDeposit'] = DB::table('transactions')
                    //     ->where('seller_id', $userId)
                    //     ->whereBetween('transaction_add_date', [$fromDate, $toDate]) 
                    //     ->where('transaction_remarks', 'Deposit to customer')
                    //     ->sum('debit');

                    // $userData['totalWithdraw'] = DB::table('transactions')
                    //     ->where('seller_id', $userId)
                    //     ->whereBetween('transaction_add_date', [$fromDate, $toDate])
                    //     ->where('transaction_remarks', 'Withdraw from customer')
                    //     ->sum('credit');

                    // deposits (debit) only for currentDate
                    $tx = $this->getTxSumsCached($userId, $fromDate, $toDate);
                    $userData['totalDeposit'] = $tx['deposit'];
                    $userData['totalWithdraw'] = $tx['withdraw'];

                    $deposit = (int)$tx['deposit'];
                    $withdraw = (int)$tx['withdraw'];


                    $userData['balance'] += $deposit - $withdraw;
                    $userData['totalReceipts'] = $orderStats['count'];
                    $userData['orderTotalAmount'] = $orderStats['sum'];
                    $userData['advance'] = $advance;

                    // Store per-user data
                    $salesData[$username] = $userData;

                    // Only build managerData if report type != 'customer' and manager exists
                    if ($reportType != 'customer' && $managername) {
                        if (!isset($managerData[$managername->user_id])) {
                            $managerData[$managername->user_id] = [
                                'managerName' => $managername->username,
                                'totalSold' => 0,
                                'totalCommission' => 0,
                            ];
                        }
                        $managerData[$managername->user_id]['totalSold'] += $userData['totalSold'];
                        $managerData[$managername->user_id]['totalCommission'] += $userData['commission'];
                    }
                }

                // // Prepare final response
                // $response = [
                //     'salesData' => $salesData,
                //     'dateRange' => $fromDate . ' - ' . $toDate,
                // ];

                // // Only add managerData if report type != 'customer'
                // if ($reportType != 'customer') {
                //     $response['managerData'] = $managerData;
                // }

                // Example: return response()->json($response);
                // dd($salesData);


            }
        } else {
            $sellerIds[] = $userId;
            $users[$userId] = $user;
            // dd($sellerIds);
        }
        if (!empty($sellerIds)) {
            // dd($sellerIds);
            if ($user->user_role == 'admin') {
                // dd(21312);


                if (count($sellerIds) == 1) {
                    $period = new DatePeriod(
                        new DateTime($fromDate),
                        new DateInterval('P1D'),
                        (new DateTime($toDate))->modify('+1 day')
                    );
                    $key = 'single';
                    foreach ($users as $user) {
                        $username = $user->username;
                        $userId = $user->user_id;
                        $salesData[$username] = []; // Initialize array to store daily data for the user
                        // Prepare per-day aggregates once
                        $this->prepareAggregates([$userId], $lotteries->pluck('lot_id')->toArray(), $fromDate, $toDate);
                        foreach ($period as $date) {

                            $currentDate = $date->format('Y-m-d');
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
                                'totalDeposit' => 0,
                                'totalWithdraw' => 0,
                                'date' => $currentDate,
                                'user_role' => $user->user_role,  // ✅ added here
                            ];


                            foreach ($lotteries as $lottery) {
                                $lotteryId = $lottery->lot_id;
                                $userData['lotteryName'][$lotteryId] = $lottery->lot_name;
                                $userData['totalSold'] += $this->getTotalSold($userId, $lotteryId, $currentDate, $currentDate);
                                // $userData['commission'] += (($this->getTotalSold($userId, $lotteryId, $currentDate, $currentDate) / 100) * $user->commission);
                                $userData['commission'] += $this->getCommission(
                                    $userId,
                                    $lotteryId,
                                    $currentDate,
                                    $currentDate,
                                    $user->commission
                                );
                                $userData['winnings'] += $this->getWinnings($userId, $lotteryId, $currentDate, $currentDate);
                                // $userData['balance'] += (int) str_replace(',', '', $this->getBalance($userId, $lotteryId, $currentDate, $currentDate, $user->commission,$currentDate));
                                // ✅ Fix: balance should be per day, not whole range
                                $userData['balance'] += (int) str_replace(
                                    ',',
                                    '',
                                    $this->getBalance(
                                        $userId,
                                        $lotteryId,
                                        $currentDate, // per-day start
                                        $currentDate, // per-day end
                                        $user->commission,
                                        $currentDate
                                    )
                                );
                                $userData['winningNumbersTotal'] += $this->getWinningNumbersTotal($userId, $lotteryId, $currentDate, $currentDate);
                            }

                            // Get advance for the current day
                            $advance = $this->getLoanSumCached($userId, $currentDate, $currentDate);

                            // Get orders for the current day
                            $orderStats = $this->getOrderStatsCached($userId, $currentDate, $currentDate);

                            // Deposits
                            $txDay = $this->getTxSumsCached($userId, $currentDate, $currentDate);
                            $userData['totalDeposit'] = (int)$txDay['deposit'];
                            $userData['totalWithdraw'] = (int)$txDay['withdraw'];

                            $userData['totalReceipts'] += $orderStats['count'];
                            $userData['orderTotalAmount'] += $orderStats['sum'];
                            $userData['advance'] = $advance;

                            // ✅ Fix: commission from customers per day
                            $commissionFromCustomers = (int)$txDay['commission'];

                            // deposits (debit) only for currentDate
                            $deposit = (int)$txDay['deposit'];
                            $withdraw = (int)$txDay['withdraw'];

                            $userData['balance'] += $deposit - $withdraw;

                            $userData['commission'] += $commissionFromCustomers;

                            // dd($userData['commission']);
                            // Store the data for the current day
                            $salesData[$username][$currentDate] = $userData;
                        }
                    }
                } else {

                    $key = 'multiple';
                    // Prepare range aggregates once for all sellers
                    $allSellerIdsForAgg = array_map(function ($u) {
                        return $u->user_id;
                    }, $sellers);
                    $lotteryIdsForAgg = $lotteries->pluck('lot_id')->toArray();
                    $this->prepareAggregates($allSellerIdsForAgg, $lotteryIdsForAgg, $fromDate, $toDate);
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
                            'totalDeposit' => 0,
                            'totalWithdraw' => 0,
                            'date' => $fromDate . ' - ' . $toDate,
                            'user_role' => $user->user_role,  // ✅ added here
                        ];

                        $managername = User::where('user_id', $user->added_user_id)->first();

                        foreach ($lotteries as $lottery) {
                            $lotteryId = $lottery->lot_id;
                            $userData['lotteryName'][$lotteryId] = $lottery->lot_name;
                            $userData['totalSold'] += $this->getTotalSold($userId, $lotteryId, $fromDate, $toDate);
                            // $userData['commission'] += (int) str_replace(',', '', $this->getCommission($userId, $lotteryId, $fromDate, $toDate, $user->commission));
                            // $userData['commission'] += (($this->getTotalSold($userId, $lotteryId, $fromDate, $toDate) / 100) * $user->commission);
                            $userData['commission'] = $this->getCommission(
                                $userId,
                                $lotteryId,
                                $fromDate,
                                $toDate,
                                $user->commission
                            );
                            $userData['winnings'] += $this->getWinnings($userId, $lotteryId, $fromDate, $toDate);
                            $userData['balance'] = $this->getBalance(
                                $userId,
                                $lotteryId,
                                $fromDate, // range start
                                $toDate,   // range end
                                $user->commission,
                                $toDate    // or maybe null if your function doesn’t need single-day
                            );
                            $userData['winningNumbersTotal'] += $this->getWinningNumbersTotal($userId, $lotteryId, $fromDate, $toDate);
                        }

                        $orderStats = $this->getOrderStatsCached($userId, $fromDate, $toDate);

                        $advance = $this->getLoanSumCached($userId, $fromDate, $toDate);

                        $tx = $this->getTxSumsCached($userId, $fromDate, $toDate);
                        $userData['totalDeposit'] = $tx['deposit'];
                        $userData['totalWithdraw'] = $tx['withdraw'];

                        $userData['totalReceipts'] += $orderStats['count'];
                        $userData['orderTotalAmount'] += $orderStats['sum'];
                        $userData['advance'] = $advance;
                        // ✅ Fix: commission from customers per day
                        $commissionFromCustomers = (int)$tx['commission'];

                        // deposits (debit) only for currentDate
                        $userData['totalDeposit'] = $tx['deposit'];
                        $userData['totalWithdraw'] = $tx['withdraw'];

                        $deposit = (int)$tx['deposit'];
                        $withdraw = (int)$tx['withdraw'];


                        $userData['balance'] += $deposit - $withdraw;

                        $userData['commission'] += $commissionFromCustomers;

                        $userData['managername'] = $managername->username;
                        $userData['managerData'] = $managername;
                        $userData['sellerCommission'] = $user->commission;
                        // dd($userData['totalDeposit']);
                        // dd($userData['commission']);
                        // Store user data
                        $salesData[$username] = $userData;

                        // Aggregate data for managers
                        if (!isset($managerData[$managername->user_id])) {
                            $managerData[$managername->user_id] = [
                                'managerName' => $managername->username,
                                'totalSold' => 0,
                                'totalCommission' => 0,
                            ];
                        }
                        $managerData[$managername->user_id]['totalSold'] += $userData['totalSold'];
                        $managerData[$managername->user_id]['totalCommission'] += $userData['commission'];
                    }
                }
            } elseif ($user->user_role == 'seller' || $user->user_role == 'customer') {
                $period = new DatePeriod(
                    new DateTime($fromDate),
                    new DateInterval('P1D'),
                    (new DateTime($toDate))->modify('+1 day')
                );

                $key = 'single';
                foreach ($users as $user) {
                    $username = $user->username;
                    $userId   = $user->user_id;
                    $salesData[$username] = []; // Initialize array to store daily data for the user

                    // Prepare per-day aggregates once
                    $this->prepareAggregates([$userId], $lotteries->pluck('lot_id')->toArray(), $fromDate, $toDate);
                    foreach ($period as $date) {
                        $currentDate = $date->format('Y-m-d');

                        $userData = [
                            'lotteryName'        => [],
                            'totalSold'          => 0,
                            'commission'         => 0,
                            'winnings'           => 0,
                            'balance'            => 0,
                            'winningNumbersTotal' => 0,
                            'totalReceipts'      => 0,
                            'orderTotalAmount'   => 0,
                            'advance'            => 0,
                            'date'               => $currentDate,
                            'totalDeposit'       => 0,
                            'totalWithdraw'      => 0,
                            'user_role'          => $user->user_role,
                        ];

                        // Loop through lotteries
                        foreach ($lotteries as $lottery) {
                            $lotteryId = $lottery->lot_id;
                            $userData['lotteryName'][$lotteryId] = $lottery->lot_name;

                            // Daily totals (per currentDate)
                            $userData['totalSold'] += $this->getTotalSold($userId, $lotteryId, $currentDate, $currentDate);

                            $userData['commission'] += $this->getCommission(
                                $userId,
                                $lotteryId,
                                $currentDate,
                                $currentDate,
                                $user->commission
                            );

                            $userData['winnings'] += $this->getWinnings($userId, $lotteryId, $currentDate, $currentDate);


                            $userData['winningNumbersTotal'] += $this->getWinningNumbersTotal($userId, $lotteryId, $currentDate, $currentDate);
                            // ✅ Fix: balance should be per day, not whole range
                            $userData['balance'] += (int) str_replace(
                                ',',
                                '',
                                $this->getBalance(
                                    $userId,
                                    $lotteryId,
                                    $currentDate, // per-day start
                                    $currentDate, // per-day end
                                    $user->commission,
                                    $currentDate
                                )
                            );
                        }
                        // dd($userData['totalSold']);

                        // Get advance for the current day
                        $advance = $this->getLoanSumCached($userId, $currentDate, $currentDate);

                        // Get orders for the current day
                        $orderStats = $this->getOrderStatsCached($userId, $currentDate, $currentDate);

                        // Deposits
                        $txDay = $this->getTxSumsCached($userId, $currentDate, $currentDate);
                        $userData['totalDeposit'] = (int)$txDay['deposit'];
                        $userData['totalWithdraw'] = (int)$txDay['withdraw'];

                        // Orders & advances
                        $userData['totalReceipts']   += $orderStats['count'];
                        $userData['orderTotalAmount'] += $orderStats['sum'];
                        $userData['advance']          = $advance;

                        // ✅ Fix: commission from customers per day
                        $commissionFromCustomers = (int)$txDay['commission'];
                        // deposits (debit) only for currentDate
                        $deposit = (int)$txDay['deposit'];
                        $withdraw = (int)$txDay['withdraw'];

                        $userData['balance'] += $deposit - $withdraw;

                        $userData['commission'] += $commissionFromCustomers;
                        // dd($userData['commission']);
                        // ✅ Now store the final data
                        $salesData[$username][$currentDate] = $userData;
                    }
                }
            } else {
                if (count($users) == 1) {
                    $period = new DatePeriod(
                        new DateTime($fromDate),
                        new DateInterval('P1D'),
                        (new DateTime($toDate))->modify('+1 day')
                    );

                    $key = 'single';
                    foreach ($users as $user) {
                        $username = $user->username;
                        $userId = $user->user_id;
                        $salesData[$username] = []; // Initialize array to store daily data for the user
                        // Prepare per-day aggregates once
                        $this->prepareAggregates([$userId], $lotteries->pluck('lot_id')->toArray(), $fromDate, $toDate);
                        foreach ($period as $date) {
                            $currentDate = $date->format('Y-m-d');
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
                                'totalDeposit' => 0,
                                'totalWithdraw' => 0,
                                'date' => $currentDate,
                            ];

                            foreach ($lotteries as $lottery) {
                                $lotteryId = $lottery->lot_id;
                                $userData['lotteryName'][$lotteryId] = $lottery->lot_name;
                                $userData['totalSold'] += $this->getTotalSold($userId, $lotteryId, $currentDate, $currentDate);
                                $userData['commission'] += (($this->getTotalSold($userId, $lotteryId, $currentDate, $currentDate) / 100) * $user->commission);
                                $userData['winnings'] += $this->getWinnings($userId, $lotteryId, $currentDate, $currentDate);
                                $userData['balance'] += (int) str_replace(',', '', $this->getBalance($userId, $lotteryId, $currentDate, $currentDate, $user->commission, $currentDate));
                                $userData['winningNumbersTotal'] += $this->getWinningNumbersTotal($userId, $lotteryId, $currentDate, $currentDate);
                            }

                            // Get advance for the current day
                            $advance = $this->getLoanSumCached($userId, $currentDate, $currentDate);

                            // Get orders for the current day
                            $orders = DB::table('orders')
                                ->where('user_id', $userId)
                                ->whereDate('order_date', '=', $currentDate)
                                ->get();
                            $userData['totalDeposit'] = DB::table('transactions')
                                ->where('seller_id', $userId)
                                ->whereDate('transaction_add_date', '=', $currentDate)
                                ->where('transaction_remarks', 'Deposit to customer')
                                ->sum('debit');

                            $userData['totalWithdraw'] = DB::table('transactions')
                                ->where('seller_id', $userId)
                                ->whereDate('transaction_add_date', '=', $currentDate)
                                ->where('transaction_remarks', 'Withdraw from customer')
                                ->sum('credit');
                            $userData['totalReceipts'] += $orders->count();
                            $userData['orderTotalAmount'] += $orders->sum('grand_total');
                            $userData['advance'] = $advance;

                            // Store the data for the current day
                            $salesData[$username][$currentDate] = $userData;
                        }
                    }
                } else {
                    $key = 'multiple';
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
                            'totalDeposit' => 0,
                            'totalWithdraw' => 0,
                            'date' => $fromDate . ' - ' . $toDate,
                        ];

                        $managername = User::where('user_id', $user->added_user_id)->first();

                        foreach ($lotteries as $lottery) {
                            $lotteryId = $lottery->lot_id;
                            $userData['lotteryName'][$lotteryId] = $lottery->lot_name;
                            $userData['totalSold'] += $this->getTotalSold($userId, $lotteryId, $fromDate, $toDate);
                            // $userData['commission'] += (int) str_replace(',', '', $this->getCommission($userId, $lotteryId, $fromDate, $toDate, $user->commission));
                            $userData['commission'] += (($this->getTotalSold($userId, $lotteryId, $fromDate, $toDate) / 100) * $user->commission);
                            $userData['winnings'] += $this->getWinnings($userId, $lotteryId, $fromDate, $toDate);
                            $userData['balance'] += (int) str_replace(',', '', $this->getBalance($userId, $lotteryId, $fromDate, $toDate, $user->commission, $toDate));
                            $userData['winningNumbersTotal'] += $this->getWinningNumbersTotal($userId, $lotteryId, $fromDate, $toDate);
                        }

                        $orderStats = $this->getOrderStatsCached($userId, $fromDate, $toDate);

                        $advance = $this->getLoanSumCached($userId, $fromDate, $toDate);

                        $txRange = $this->getTxSumsCached($userId, $fromDate, $toDate);
                        $userData['totalDeposit'] = $txRange['deposit'];
                        $userData['totalWithdraw'] = $txRange['withdraw'];
                        $userData['totalReceipts'] += $orderStats['count'];
                        $userData['orderTotalAmount'] += $orderStats['sum'];
                        $userData['advance'] = $advance;
                        $userData['managername'] = $managername->username;
                        $userData['managerData'] = $managername;
                        $userData['sellerCommission'] = $user->commission;

                        // Store user data
                        $salesData[$username] = $userData;

                        // Aggregate data for managers
                        if (!isset($managerData[$managername->user_id])) {
                            $managerData[$managername->user_id] = [
                                'managerName' => $managername->username,
                                'totalSold' => 0,
                                'totalCommission' => 0,
                            ];
                        }
                        $managerData[$managername->user_id]['totalSold'] += $userData['totalSold'];
                        $managerData[$managername->user_id]['totalCommission'] += $userData['commission'];
                    }
                }
            }
        }
        // dd($salesData);
        // return response()->json([
        //     'data' => $salesData,
        //     'lang' => $lang,
        //     'key'  => $key
        // ]);
        return view('saleReport', ['data' => $salesData, 'lang' => $lang, 'key' => $key]);
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

    private function getTotalSold($userId, $lotIds, $fromDate, $toDate)
    {
        // Use aggregates if present
        if ($this->preAggregates['rangeKey']) {
            $lotIdsArr = (array)$lotIds;
            $sum = 0;
            foreach ($lotIdsArr as $lid) {
                $sum += $this->getSoldCached((int)$userId, (int)$lid, $fromDate, $toDate);
            }
            return (int)$sum;
        }

        $ordersList = Order::where('user_id', $userId)
            ->whereBetween('order_date', [
                $fromDate . ' 00:00:00',
                $toDate . ' 23:59:59'
            ])
            ->pluck('order_id')
            ->toArray();

        $totalSold = OrderItem::whereIn('product_id', (array)$lotIds)
            ->whereIn('order_id', $ordersList)
            ->sum('lot_amount');
        return (int)$totalSold;
    }

    private function getCommission($userId, $lotId, $fromDate, $toDate, $commission)
    {
        // 1) Commission from sold tickets (based on date range)
        $totalSold = $this->getTotalSold($userId, $lotId, $fromDate, $toDate);
        // dd($totalSold);
        $commissionFromSales = intval(($totalSold * $commission) / 100);
        // dd($commissionFromSales);
        return $commissionFromSales;
    }


    private function getWinnings($userId, $lotId, $fromDate, $toDate)
    {
        // Use aggregates if available
        if ($this->preAggregates['rangeKey']) {
            return (int)$this->getWinningsCached((int)$userId, (int)$lotId, $fromDate, $toDate);
        }

        $ordersList = Order::where('user_id', $userId)
            ->whereBetween('order_date', [$fromDate, $toDate])
            ->pluck('order_id')
            ->toArray();

        $winnings = OrderItem::whereIn('order_id', $ordersList)
            ->where('product_id', $lotId)
            ->whereDate('adddatetime', '>=', $fromDate)
            ->whereDate('adddatetime', '<=', $toDate)
            ->sum('winning_amount');

        return intval($winnings);
    }

    private function getBalance($userId, $lotId, $fromDate, $toDate, $commission, $currentDate)
    {
        $totalSold   = (int)$this->getTotalSold($userId, $lotId, $fromDate, $toDate);
        $commission  = $this->getCommission($userId, $lotId, $fromDate, $toDate, $commission);
        $winnings    = $this->getWinnings($userId, $lotId, $fromDate, $toDate);

        $balance = $totalSold - $winnings;


        //     $finaldata = ([
        //     'totalSold'  => $totalSold,
        //     //  'deposit'    => $deposit,
        //     'commission' => $commission,
        //     'winnings'   => $winnings,

        //     // 'withdraw'   => $withdraw,
        //     // 'balance'    => ($totalSold + $deposit )-($winnings + $withdraw),
        // ]);

        // dd($finaldata);
        return $balance;
    }


    private function getWinningNumbersTotal($userId, $lotId, $fromDate, $toDate)
    {
        if ($this->preAggregates['rangeKey']) {
            return (int)$this->getWinningOrdersCountCached((int)$userId, (int)$lotId, $fromDate, $toDate);
        }

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
