<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Order;


class DashboardController extends Controller
{

    public function managerData(Request $request)
    {
        $user = Auth()->user();
        $fromDate = $request->input('fromdate');
        $toDate = $request->input('todate');

        // dd($fromDate, $toDate);

        $fromDateCarbon = Carbon::createFromFormat('d M, Y', $fromDate)->startOfDay();
        $toDateCarbon   = Carbon::createFromFormat('d M, Y', $toDate)->endOfDay();

        // $fromDateCarbon = Carbon::createFromFormat('J M,Y - H:i', $fromDate)->startOfDay();
        // $toDateCarbon = Carbon::createFromFormat('J M,Y - H:i', $toDate)->startOfDay();

        if ($user->user_role === 'admin') {
            $roleData = ['users.user_role', '=', 'manager'];
        } elseif ($user->user_role === 'manager') {
            $roleData = ['users.user_role', '=', 'seller'];
        }

        $report = DB::table('users')
            ->where('users.status', 1)
            ->where([$roleData])
            ->leftJoin('orders', function ($join) use ($fromDateCarbon, $toDateCarbon) {
                $join->on('users.user_id', '=', 'orders.user_id')
                    ->whereBetween('orders.adddatetime', [$fromDateCarbon, $toDateCarbon]);
            })
            ->leftJoin('winning_numbers', function ($join) use ($fromDateCarbon, $toDateCarbon) {
                $join->on('users.user_id', '=', 'winning_numbers.lot_id')
                    ->whereBetween('winning_numbers.adddatetime', [$fromDateCarbon, $toDateCarbon]);
            })
            ->select(
                'users.user_id',
                'users.username',
                DB::raw('COALESCE(SUM(orders.grand_total), 0) as total_sales'),
                DB::raw('COALESCE(SUM(winning_numbers.number_win), 0) as total_winnings')
                // DB::raw('COALESCE(SUM(orders.grand_total), 0) - COALESCE(SUM(winning_numbers.number_win), 0) as net_profit')
            )
            ->groupBy('users.user_id', 'users.username')
            ->orderByDesc('total_sales')
            ->get();

        $lotterySales = DB::table('orders')
            ->join('lotteries', 'orders.user_id', '=', 'lotteries.lot_id')
            ->whereBetween('orders.adddatetime', [$fromDateCarbon, $toDateCarbon])
            ->select(
                'lotteries.lot_id',
                'lotteries.lot_name',
                'lotteries.lot_colorcode',
                DB::raw('SUM(orders.grand_total) as total_sales')
            )
            ->groupBy('lotteries.lot_id', 'lotteries.lot_name', 'lotteries.lot_colorcode')
            ->orderByDesc('total_sales')
            ->get();

        $topLottery = $lotterySales->take(5);

        $topNumber = DB::table('order_item')
            ->whereBetween('order_item.adddatetime', [$fromDateCarbon, $toDateCarbon])
            ->select(
                'order_item.lot_number',
                DB::raw('SUM(order_item.lot_number) as total_sales')
            )
            ->groupBy('order_item.lot_number')
            ->orderByDesc('total_sales')
            ->limit(5)
            ->get();

        $topLotteryAndNumber = $topLottery->map(function ($lottery, $index) use ($topNumber) {
            $number = $topNumber[$index] ?? null;
            return [
                'lot_id'       => $lottery->lot_id,
                'lot_name'     => $lottery->lot_name,
                'total_sales'  => $lottery->total_sales,
                'lot_number'   => $number->lot_number ?? null,
                'number_sales' => $number->total_sales ?? 0,
            ];
        });
        return response()->json([
            'managersTopsale'    => $report,
            'TopLotteries'   => $lotterySales,
            'TopLottery&Number' => $topLotteryAndNumber,
        ]);
    }

    public function dashboard(Request $request)
    {
        $user = auth()->user();
        $languageCode = $request->input('languageCode');
        //dd($user->user_role);
        switch ($user->user_role) {
            case 'admin':
                return $this->adminDashboard($user, $languageCode);
                break;
            case 'manager':
                return $this->managerDashboard($user, $languageCode);
                break;
            case 'seller':
                return $this->sellerDashboard($user, $languageCode);
                break;
            case 'superadmin':
                return $this->superAdminDashboard($user, $languageCode);
                break;
            case 'customer':
                return $this->customerDashboard($request, $user, $languageCode);
                break;
            default:
                return response()->json(['error' => 'User Role not defined']);
        }
    }

    public function getdashboard(Request $request, $user_id)
    {

        //$user = auth()->user();
        $languageCode = $request->input('languageCode');
        $UserID = DB::table('users')
            ->where('user_id', $user_id)
            ->where('is_deleted', '<>', '1')

            ->get();
        $user = $UserID[0];

        //dd($user->user_role);
        switch ($user->user_role) {
            case 'admin':
                return $this->adminDashboard($user, $languageCode);
                break;
            case 'manager':
                return $this->managerDashboard($user, $languageCode);
                break;
            case 'seller':
                return $this->sellerDashboard($user, $languageCode);
                break;
            case 'superadmin':
                return $this->superAdminDashboard($user, $languageCode);
                break;
            default:
                return response()->json(['error' => 'User Role not defined']);
        }
    }

    public function getCustomers()
    {
        try {

            $user = auth()->user();

            if ($user->user_id != 1 && $user->user_role != 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized access!']);
            }

            $customers = User::where('user_role', 'customer')->where('is_deleted', '<>', '1')->get();

            foreach ($customers as $customer) {
                $transactionSummary = DB::table('transactions')
                    ->where('customer_id', $customer->user_id)
                    ->select(
                        DB::raw('COALESCE(SUM(credit), 0) as total_credit'),
                        DB::raw('COALESCE(SUM(debit), 0) as total_debit')
                    )
                    ->first();
                $balance = $transactionSummary->total_credit - $transactionSummary->total_debit;
                $customer['balance'] = $balance;

                $voucherSummary = DB::table('vouchers')
                    ->where('customer_id', $customer->user_id)
                    ->select(
                        DB::raw("COALESCE(SUM(CASE WHEN voucher_type = 'deposit' THEN givin_amount ELSE 0 END), 0) as total_deposit"),
                        DB::raw("COALESCE(SUM(CASE WHEN voucher_type = 'withdraw' THEN givin_amount ELSE 0 END), 0) as total_withdraw")
                    )
                    ->first();

                $customer['totalDeposit'] = $voucherSummary->total_deposit;
                $customer['totalWithdraw'] = $voucherSummary->total_withdraw;

                $customer['totalWinAmount'] = 0.00;
            }

            return response()->json(['success' => true, 'data' => $customers], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function customerDashboard(Request $request, $user, $languageCode)
    {
        $userId = $user->user_id;
        $languageCode = $request->input('languageCode');

        // Step 1: Get start and end dates from request, else default to today
        $date = now()->setTimezone('America/Port-au-Prince'); // Haiti timezone
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if ($startDate && $endDate) {
            $startOfDay = \Carbon\Carbon::parse($startDate)->setTimezone('America/Port-au-Prince')->startOfDay();
            $endOfDay = \Carbon\Carbon::parse($endDate)->setTimezone('America/Port-au-Prince')->endOfDay();
        } else {
            $startOfDay = $date->copy()->startOfDay();
            $endOfDay = $date->copy()->endOfDay();
        }

        // 1️⃣ Get total current balance (no date filter)
        $totalTransactionSummary = DB::table('transactions')
            ->where('customer_id', $userId)
            ->select(
                DB::raw('COALESCE(SUM(credit), 0) as total_credit'),
                DB::raw('COALESCE(SUM(debit), 0) as total_debit')
            )
            ->first();

        $totalBalanceAmount = $totalTransactionSummary->total_credit - $totalTransactionSummary->total_debit;

        // 2️⃣ Get date-filtered transactions for deposits/withdrawals
        $transactionSummary = DB::table('transactions')
            ->where('customer_id', $userId)
            ->whereBetween('transaction_add_date', [$startOfDay, $endOfDay])
            ->select(
                DB::raw('COALESCE(SUM(credit), 0) as total_credit'),
                DB::raw('COALESCE(SUM(debit), 0) as total_debit')
            )
            ->first();

        $balance = $transactionSummary->total_credit - $transactionSummary->total_debit;

        // Vouchers (filtered by add_datetime)
        $voucherSummary = DB::table('vouchers')
            ->where('customer_id', $userId)
            ->where('transaction_id', "!=", NULL)
            ->whereBetween('add_datetime', [$startOfDay, $endOfDay])
            ->select(
                DB::raw("COALESCE(SUM(CASE WHEN voucher_type = 'deposit' THEN givin_amount ELSE 0 END), 0) as total_deposit"),
                DB::raw("COALESCE(SUM(CASE WHEN voucher_type = 'withdraw' THEN givin_amount ELSE 0 END), 0) as total_withdraw")
            )
            ->first();

        // Orders within date range
        $orderIds = DB::table('orders')
            ->where('user_id', $userId)
            ->whereBetween('adddatetime', [$startOfDay, $endOfDay])
            ->pluck('order_id');

        $winAmount = DB::table('order_item')
            ->whereIn('order_id', $orderIds)
            ->sum('winning_amount');

        // Translations
        $translations = [
            'en' => [
                'currentBalance' => 'Current Balance',
                'totalDeposit'   => 'Total Deposit',
                'totalWithdraw'  => 'Total Withdraw',
                'totalWinAmount' => 'Total Win Amount',
            ],
            'es' => [
                'currentBalance' => 'Saldo actual',
                'totalDeposit'   => 'Depósito total',
                'totalWithdraw'  => 'Retiro total',
                'totalWinAmount' => 'Cantidad ganada',
            ],
            'fr' => [
                'currentBalance' => 'Solde actuel',
                'totalDeposit'   => 'Dépôt total',
                'totalWithdraw'  => 'Retrait total',
                'totalWinAmount' => 'Montant gagné',
            ],
            'ht' => [
                'currentBalance' => 'Balans aktyèl',
                'totalDeposit'   => 'Depo total',
                'totalWithdraw'  => 'Retrè total',
                'totalWinAmount' => 'Montan genyen',
            ],
        ];

        $lang = $translations[$languageCode] ?? $translations['en'];

        // Response formatting
        $totalBalance = [
            'name'   => $lang['currentBalance'],
            'amount' => number_format($totalBalanceAmount, 2),
        ];

        $dashboard = [
            [
                'img'    => asset('assets/images/deposit.png'),
                'name'   => $lang['totalDeposit'],
                'amount' => number_format($voucherSummary->total_deposit, 2),
            ],
            [
                'img'    => asset('assets/images/withdraw.png'),
                'name'   => $lang['totalWithdraw'],
                'amount' => number_format($voucherSummary->total_withdraw, 2),
            ],
            [
                'img'    => asset('assets/images/money-bag.png'),
                'name'   => $lang['totalWinAmount'],
                'amount' => number_format($winAmount, 2),
            ],
        ];

        return response()->json([
            'success'              => true,
            'totalBalance'         => $totalBalance,
            'data'                 => $dashboard,
            'unread_notifications' => 0,
            'msg'                  => 'Dashboard fetched successfully',
        ], 200);
    }



    public function SuperAdminDashboard($user, $languageCode)

    {

        if ($user->user_role == 'superadmin') {
            $date = now();
            $userId = $user->user_id;

            $lastCutHistory = DB::table('cut_history')
                ->where('user_id', $userId)
                ->orderByDesc('cut_id')
                ->first();

            if ($lastCutHistory !== null) {
                $showFromDate = $lastCutHistory->add_datetime;
            } else {
                $showFromDate = date('d-m-y');
            }

            $userDetails = $user->user_role;


            $totalCollected = DB::table('orders')->where('adddatetime', '>', $showFromDate)->sum('grand_total');
            $userCounts = DB::table('users')
                ->selectRaw('
                COUNT(CASE WHEN user_role = "seller" THEN 1 END) as totalSeller,
                COUNT(CASE WHEN user_role = "manager" THEN 1 END) as totalManager,
                COUNT(CASE WHEN user_role = "admin" THEN 1 END) as totalAdmin
            ')
                ->where('status', 1)
                ->where('is_deleted', '<>', '1')
                ->first();


            $totalLot = DB::table('lotteries')->count();

            $totalPaid = DB::table('transactions')
                ->whereNotNull('order_item_id')
                ->where('transaction_add_date', '>', $showFromDate)
                ->sum('debit');

            $totalCashInHand = DB::table('transactions')->sum(DB::raw('(credit - debit)'));

            // Translation arrays
            $translations = [
                'en' => [
                    'totalAdmin' => 'Total Admin',
                    'totalManager' => 'Total Manager',
                    'totalSeller' => 'Total Sellers',
                    'totalLotteries' => 'Total Lotteries',
                    'app' => 'App',
                ],
                'es' => [
                    'totalAdmin' => 'Administrateur total',
                    'totalManager' => 'Gestionnaire total',
                    'totalSeller' => 'Nombre total de vendeurs',
                    'totalLotteries' => 'Loteries totales',
                    'app' => 'Application',
                ],
                'fr' => [
                    'totalAdmin' => 'Administrateur total',
                    'totalManager' => 'Gestionnaire total',
                    'totalSeller' => 'Vendeurs totaux',
                    'totalLotteries' => 'Loteries totales',
                    'app' => 'Application',
                ],
                'ht' => [
                    'totalAdmin' => 'Administratè total',
                    'totalManager' => 'Jesyon total',
                    'totalSeller' => 'Total vandè',
                    'totalLotteries' => 'Total lotri',
                    'app' => 'Aplikasyon',
                ],
            ];

            // Fetch the appropriate language translation
            $lang = $translations[$languageCode] ?? $translations['en']; // Default to English if not found


            $data = [
                [
                    'img' => asset('assets/images/2.png'),
                    'name' => $lang['totalAdmin'],
                    'amount' => number_format($userCounts->totalAdmin),
                ],
                [
                    'img' => asset('assets/images/2.png'),
                    'name' => $lang['totalManager'],
                    'amount' => number_format($userCounts->totalManager),
                ],
                [
                    'img' => asset('assets/images/2.png'),
                    'name' => $lang['totalSeller'],
                    'amount' => number_format($userCounts->totalSeller),
                ],
                [
                    'img' => 'https://cdn-icons-png.flaticon.com/512/5525/5525335.png',
                    'name' => $lang['totalLotteries'],
                    'amount' => number_format($totalLot),
                ],
                [
                    'img' => asset('assets/images/2.png'),
                    'name' => $lang['app'],
                    'amount' => number_format($totalCollected * 0.005, 2),
                ],
            ];

            $jsonResponse = [
                'data' => $data,
                'cutList' =>  [],
                'unread_notifications' => 0,
                'success' => true,
                'msg'       => 'Get Successfully',
            ];

            return response()->json($jsonResponse);
        }

        return response()->json(['msg' => 'Invalid request', 'success' => false], 401);
    }





    public function adminDashboardForCustomer(Request $request)
    {
        $user = auth()->user();

        if ($user->user_role == 'admin') {
            $languageCode = $request->input('languageCode');

            // Step 1: Get start and end dates from request, else default to today
            $date = now()->setTimezone('America/Port-au-Prince'); // Set timezone to Haiti
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            if ($startDate && $endDate) {
                // Parse dates and keep them in correct timezone
                $startOfDay = \Carbon\Carbon::parse($startDate)->setTimezone('America/Port-au-Prince')->startOfDay();
                $endOfDay = \Carbon\Carbon::parse($endDate)->setTimezone('America/Port-au-Prince')->endOfDay();
            } else {
                // Default to current day
                $startOfDay = $date->copy()->startOfDay();
                $endOfDay = $date->copy()->endOfDay();
            }

            // Step 2: Get customer IDs where added_user_id = 0
            $customerIds = DB::table('users')
                ->where('user_role', 'customer')
                ->where('added_user_id', 0)
                ->where('is_deleted', '<>', '1')
                ->pluck('user_id');

            // Step 3: Fetch order IDs within date range
            $orderIds = DB::table('orders')
                ->whereIn('user_id', $customerIds)
                ->whereBetween('adddatetime', [$startOfDay, $endOfDay])
                ->pluck('order_id');

            // Step 4: Fetch their order stats
            $totalCustomerCollected = DB::table('orders')
                ->whereIn('user_id', $customerIds)
                ->whereBetween('adddatetime', [$startOfDay, $endOfDay])
                ->sum('grand_total');

            $totalCustomerWinning = DB::table('order_item')
                ->whereIn('order_id', $orderIds)
                ->sum('winning_amount');

            $totalCustomerWinPaid = DB::table('order_item')
                ->whereIn('order_id', $orderIds)
                ->whereNotNull('transaction_paid_id')
                ->sum('winning_amount');

            $customerDeposit = DB::table('transactions')
                ->where('transaction_remarks', 'Deposit received')
                ->whereNotNull('customer_id')
                ->whereBetween('transaction_add_date', [$startOfDay, $endOfDay])
                ->sum('credit');

            $customerWithdrawal = DB::table('transactions')
                ->where('transaction_remarks', 'Withdraw processed')
                ->whereNotNull('customer_id')
                ->whereBetween('transaction_add_date', [$startOfDay, $endOfDay])
                ->sum('debit');

            // Step 5: Calculate balance
            $customerBalance = $totalCustomerCollected - $totalCustomerWinning;

            // Step 6: Translation arrays
            $translations = [
                'en' => [
                    'lotteriesAmount' => 'Lotteries amount (Customer)',
                    'paidWinningNumber' => 'Paid winning amount',
                    'totalPaid' => 'Total Paid',
                    'balance' => 'Balance',
                    'customerDeposit' => 'Customer Deposit',
                    'customerWithdrawal' => 'Customer Withdrawal',
                ],
                'es' => [
                    'lotteriesAmount' => 'Montant des loteries (Cliente)',
                    'paidWinningNumber' => 'Monto total ganado',
                    'totalPaid' => 'Total payé',
                    'balance' => 'Équilibre',
                    'customerDeposit' => 'Dépôt du client',
                    'customerWithdrawal' => 'Retiro del cliente',
                ],
                'fr' => [
                    'lotteriesAmount' => 'Montant des loteries (Client)',
                    'paidWinningNumber' => 'Montant total gagné',
                    'totalPaid' => 'Total payé',
                    'balance' => 'Équilibre',
                    'customerDeposit' => 'Dépôt du client',
                    'customerWithdrawal' => 'Retrait du client',
                ],
                'ht' => [
                    'lotteriesAmount' => 'Montant lotri yo (Kliyan)',
                    'paidWinningNumber' => 'Kantite total genyen',
                    'totalPaid' => 'Total Peye',
                    'balance' => 'Balans',
                    'customerDeposit' => 'Depo Kliyan',
                    'customerWithdrawal' => 'Retrè Kliyan',
                ],
            ];



            // Use requested language or default to English
            $lang = $translations[$languageCode] ?? $translations['en'];

            // Step 7: Build response
            $emparray2 = [
                [
                    'img' => asset('assets/images/1.png'),
                    'name' => $lang['lotteriesAmount'],
                    'amount' => number_format($totalCustomerCollected, 2),
                ],
                [
                    'img' => asset('assets/images/4.png'),
                    'name' => $lang['paidWinningNumber'],
                    'amount' => number_format($totalCustomerWinning, 2),
                ],
                [
                    'img' => asset('assets/images/4.png'),
                    'name' => $lang['totalPaid'],
                    'amount' => number_format($totalCustomerWinPaid, 2),
                ],
                [
                    'img' => asset('assets/images/5.png'),
                    'name' => $lang['balance'],
                    'amount' => number_format($customerBalance, 2),
                ],
                [
                    'img' => asset('assets/images/5.png'),
                    'name' => $lang['customerDeposit'],
                    'amount' => number_format($customerDeposit, 2),
                ],
                [
                    'img' => asset('assets/images/5.png'),
                    'name' => $lang['customerWithdrawal'],
                    'amount' => number_format($customerWithdrawal, 2),
                ],
            ];

            return response()->json([
                'data' => $emparray2,
                'cutList' => [],
                'unread_notifications' => 0,
                'success' => true,
                'msg' => 'Get Successfully',
                'date' => $date,
                'day_start' => $startOfDay,
                'day_end' => $endOfDay,
            ]);
        }

        return response()->json(['msg' => 'Invalid request'], 401);
    }


    public function adminDashboard($user, $languageCode)
    {
        if ($user->user_role == 'admin') {
            $date = now()->setTimezone('America/Port-au-Prince'); // Set timezone to Haiti
            $startOfDay = $date->copy()->startOfDay(); // Start of the current day
            $endOfDay = $date->copy()->endOfDay();     // End of the current day

            $userId = $user->user_id;

            $managerIds = DB::table('users')
                ->where('added_user_id', $userId)
                ->where('user_role', 'manager')
                ->where('is_deleted', '<>', '1')
                ->pluck('user_id');

            $sellerIds = DB::table('users')
                ->whereIn('added_user_id', $managerIds)
                ->where('user_role', 'seller')
                ->where('is_deleted', '<>', '1')
                ->pluck('user_id');

            $sellerIds = array_merge($sellerIds->toArray(), DB::table('users')
                ->where('added_user_id', $userId)
                ->where('user_role', 'seller')
                ->where('is_deleted', '<>', '1')
                ->pluck('user_id')->toArray());

            $orderIds = DB::table('orders')
                ->whereIn('user_id', $sellerIds)
                ->whereBetween('adddatetime', [$startOfDay, $endOfDay]) // Filter for the current day
                ->pluck('order_id');

            $totalSold = DB::table('orders')
                ->whereIn('user_id', $sellerIds)
                ->whereBetween('adddatetime', [$startOfDay, $endOfDay]) // Filter for the current day
                ->count();

            $totalCollected = DB::table('orders')
                ->whereIn('user_id', $sellerIds)
                ->whereBetween('adddatetime', [$startOfDay, $endOfDay]) // Filter for the current day
                ->sum('grand_total');

            $totalLot = DB::table('lotteries')
                ->where('user_added_id', $userId)
                ->count();

            $totalPaid = DB::table('transactions')
                ->whereIn('seller_id', $sellerIds)
                ->whereBetween('transaction_add_date', [$startOfDay, $endOfDay]) // Filter for the current day
                ->whereNotNull('order_item_id')
                ->where('balance', '1')
                ->sum('debit');

            $totalWin = DB::table('order_item')
                ->whereIn('order_id', $orderIds)
                ->sum('winning_amount');

            $winTotalPaid = DB::table('order_item')
                ->whereIn('order_id', DB::table('orders')
                    ->whereIn('user_id', $sellerIds)
                    ->whereBetween('adddatetime', [$startOfDay, $endOfDay])
                    ->pluck('order_id'))
                ->whereNotNull('transaction_paid_id')
                ->sum('winning_amount');


            $totalManagerCommission = DB::table('users as mu')
                ->leftJoin('users as su', 'su.added_user_id', '=', 'mu.user_id')
                ->leftJoin('orders as o', 'o.user_id', '=', 'su.user_id')
                ->where('mu.user_role', 'manager')
                ->where('mu.added_user_id', $userId)
                ->whereBetween('o.adddatetime', [$startOfDay, $endOfDay]) // Filter for the current day
                ->groupBy('mu.user_id')
                ->sum(DB::raw('o.grand_total * mu.commission / 100'));

            $advance = DB::table('loans')
                ->where('added_user_id', $userId)
                ->whereBetween('adddatetime', [$startOfDay, $endOfDay])
                ->sum('credit');

            $totalAppCommission = $totalCollected * 0.005;


            $balance = ($totalCollected + $advance) - $totalWin - $totalManagerCommission;

            // Translation arrays
            $translations = [
                'en' => [
                    'lotteriesAmount' => 'Lotteries amount (Sellers)',
                    'paidWinningNumber' => 'Paid winning amount',
                    'totalPaid' => 'Total Paid',
                    'commission' => 'Commission',
                    'advance' => 'Advance',
                    'balance' => 'Balance',
                    'app' => 'App',
                ],
                'es' => [
                    'lotteriesAmount' => 'Montant des loteries (vendeurs)',
                    'paidWinningNumber' => 'Monto total ganado',
                    'totalPaid' => 'Total payé',
                    'commission' => 'Commission',
                    'advance' => 'Avance',
                    'balance' => 'Équilibre',
                    'app' => 'Application',
                ],
                'fr' => [
                    'lotteriesAmount' => 'Montant des loteries (vendeurs)',
                    'paidWinningNumber' => 'Montant total gagné',
                    'totalPaid' => 'Total payé',
                    'commission' => 'Commission',
                    'advance' => 'Avance',
                    'balance' => 'Équilibre',
                    'app' => 'Application',
                ],
                'ht' => [
                    'lotteriesAmount' => 'Montant lotri yo (vandè)',
                    'paidWinningNumber' => 'Kantite total genyen',
                    'totalPaid' => 'Total Peye',
                    'commission' => 'Komisyon',
                    'advance' => 'Avans',
                    'balance' => 'Balans',
                    'app' => 'Aplikasyon',
                ],
            ];

            // Fetch the appropriate language translation
            $lang = $translations[$languageCode] ?? $translations['en']; // Default to English if not found

            $emparray = [
                [
                    'img' => asset('assets/images/1.png'),
                    'name' => $lang['lotteriesAmount'],
                    'amount' => number_format($totalCollected, 2),
                ],
                [
                    'img' => asset('assets/images/4.png'),
                    'name' => $lang['paidWinningNumber'],
                    'amount' => number_format($totalWin, 2),
                ],
                [
                    'img' => asset('assets/images/4.png'),
                    'name' => $lang['totalPaid'],
                    'amount' => number_format($winTotalPaid, 2),
                ],
                [
                    'img' => asset('assets/images/3.png'),
                    'name' => $lang['commission'],
                    'amount' => number_format($totalManagerCommission, 2),
                ],
                [
                    'img' => asset('assets/images/3.png'),
                    'name' => $lang['advance'],
                    'amount' => number_format($advance, 2),
                ],
                [
                    'img' => asset('assets/images/5.png'),
                    'name' => $lang['balance'],
                    'amount' => number_format($balance, 2),
                ],
                [
                    'img' => asset('assets/images/2.png'),
                    'name' => $lang['app'],
                    'amount' => number_format($totalAppCommission, 2),
                ],
            ];

            // $cutHistory = DB::table('cut_history')
            //     ->select('cut_sale', 'cut_commision', 'cut_winners', 'cut_balance', 'add_datetime')
            //     ->where('user_id',  $userId)
            //     ->orderByDesc('cut_id')
            //     ->limit(3)
            //     ->get();

            $jsonResponse = [
                'data' => $emparray,
                'cutList' => [],
                'unread_notifications' => 0,
                'success' => true,
                'msg' => 'Get Successfully',
                'date' => $date,
                'day_start' => $startOfDay,
                'day_end' => $endOfDay,
            ];

            return response()->json($jsonResponse);
        }

        return response()->json(['msg' => 'Invalid request'], 401);
    }



    // ...

    public function SellerDashboard($user, $languageCode)
    {
        if ($user->user_role == 'seller') {
            $date = now()->setTimezone('America/Port-au-Prince'); // Set timezone to Haiti
            $startOfDay = $date->copy()->startOfDay(); // Start of the current day
            $endOfDay = $date->copy()->endOfDay();     // End of the current day

            $userId = $user->user_id;

            // Fetch the last entry from cut_history for the usery
            $lastCutEntry = DB::table('cut_history')
                ->where('user_id', $userId)
                ->whereBetween('add_datetime', [$startOfDay, $endOfDay])
                ->latest('add_datetime') // Get the latest entry based on add_datetime
                ->first();

            $finaltime = $lastCutEntry ? $lastCutEntry->add_datetime : $startOfDay;

            // Use $finaltime in other queries
            $totalSold = DB::table('orders')
                ->where('user_id', $userId)
                ->where('lotterycollected', 0)
                ->where('adddatetime', '>', $finaltime) // Apply the condition on created_at or the appropriate datetime column
                ->count();

            $totalCollected = DB::table('orders')
                ->where('user_id', $userId)
                ->where('lotterycollected', 0)
                ->where('adddatetime', '>', $finaltime)
                ->sum('grand_total');

            $totalCashInHand = DB::table('transactions')
                ->where('seller_id', $userId)
                ->where('balance', 1)
                ->where('transaction_add_date', '>', $finaltime)
                ->sum('credit') - DB::table('transactions')
                ->where('seller_id', $userId)
                ->where('balance', 1)
                ->where('transaction_add_date', '>', $finaltime)
                ->sum('debit');

            $totalWin = DB::table('order_item')
                ->whereIn('order_id', DB::table('orders')
                    ->where('user_id', $userId)
                    ->where('lotterycollected', 0)
                    ->where('adddatetime', '>', $finaltime)
                    ->pluck('order_id'))
                ->sum('winning_amount');

            $totalPaid = DB::table('order_item')
                ->whereIn('order_id', DB::table('orders')
                    ->where('user_id', $userId)
                    ->where('adddatetime', '>', $finaltime)
                    ->pluck('order_id'))
                ->whereNotNull('transaction_paid_id')
                ->sum('winning_amount');

            $sellerAdvance = DB::table('loans')
                ->where('seller_id', $userId)
                ->select(DB::raw('SUM(credit) - SUM(debit) as balance'))
                ->where('adddatetime', '>', $finaltime)
                ->first();

            // Deposit and Withdraw
            $deposit = DB::table('transactions')
                ->where('seller_id', $userId)
                ->where('transaction_add_date', '>', $finaltime)
                ->where('transaction_remarks', 'Deposit to customer')
                ->sum('debit');
            // dd($deposit);
            $withdraw = DB::table('transactions')
                ->where('seller_id', $userId)
                ->where('transaction_add_date', '>', $finaltime)
                ->where('transaction_remarks', 'Withdraw from customer')
                ->sum('credit');

            // Translation arrays
            $translations = [
                'en' => [
                    'totalSold' => 'Total Sold',
                    'sellerCommission' => 'Seller Commission',
                    'commissionAmount' => 'Commission Amount',
                    'commissionFromCustomers' => 'Commission From Customers',
                    'paidWinningNumber' => 'Total Amount Win',
                    'totalPaid' => 'Total Paid',
                    'advance' => 'Advance',
                    'balance' => 'Balance',
                ],
                'es' => [
                    'totalSold' => 'Total vendido',
                    'sellerCommission' => 'Comisión del vendedor',
                    'commissionAmount' => 'Monto de la comisión',
                    'commissionFromCustomers' => 'Commission From Customers',
                    'paidWinningNumber' => 'Monto total ganado',
                    'totalPaid' => 'Total pagado',
                    'advance' => 'Avance',
                    'balance' => 'Saldo',
                ],
                'fr' => [
                    'totalSold' => 'Total vendu',
                    'sellerCommission' => 'Commission du vendeur',
                    'commissionAmount' => 'Montant des commissions',
                    'commissionFromCustomers' => 'Commission From Customers',
                    'paidWinningNumber' => 'Montant total gagné',
                    'totalPaid' => 'Total payé',
                    'advance' => 'Avance',
                    'balance' => 'Équilibre',
                ],
                'ht' => [
                    'totalSold' => 'Total vann',
                    'sellerCommission' => 'Komisyon vannè',
                    'commissionAmount' => 'Montant komisyon an',
                    'commissionFromCustomers' => 'Commission From Customers',
                    'paidWinningNumber' => 'Kantite total genyen',
                    'totalPaid' => 'Total peye',
                    'advance' => 'Avans',
                    'balance' => 'Balans',
                ],
            ];

            // Fetch the appropriate language translation
            $lang = $translations[$languageCode] ?? $translations['en']; // Default to English if not found

            $data = [
                [
                    'img' => asset('assets/images/1.png'),
                    'name' => $lang['totalSold'],
                    'amount' => number_format($totalCollected, 2)
                ],
            ];
            // Add to $data
            $data[] = [
                'img' => asset('assets/images/1.png'),
                'name' => 'Deposit',
                'amount' => number_format($deposit, 2),
            ];

            $data[] = [
                'img' => asset('assets/images/1.png'),
                'name' => 'Withdraw',
                'amount' => number_format($withdraw, 2),
            ];

            if ($user->commission > 0) {
                $data[] = [
                    'img' => asset('assets/images/2.png'),
                    'name' => $lang['sellerCommission'],
                    'amount' => $user->commission . '%'
                ];
                $comissionFromCustomers = DB::table('transactions')->where('seller_id', $user->user_id)->where('transaction_add_date', '>', $finaltime)->where('transaction_remarks', 'commission')->sum('credit');
                $totalCommission = ($totalCollected * $user->commission / 100) + $comissionFromCustomers;

                $data[] = [
                    'img' => asset('assets/images/3.png'),
                    'name' => $lang['commissionAmount'], // or you can use a combined title like $lang['totalCommission']
                    'amount' => number_format($totalCommission, 2),
                ];
            }

            $data[] = [
                'img' => asset('assets/images/4.png'),
                'name' => $lang['paidWinningNumber'],
                'amount' => number_format($totalWin, 2),
            ];

            $data[] = [
                'img' => asset('assets/images/4.png'),
                'name' => $lang['totalPaid'],
                'amount' => number_format($totalPaid, 2),
            ];

            $saldo = $totalCollected > 0 ? number_format($totalCollected - $user->commission - $totalWin, 2) : "0";

            $data[] = [
                'img' => asset('assets/images/5.png'),
                'name' => $lang['advance'],
                'amount' => $sellerAdvance->balance !== null ? $sellerAdvance->balance : '0.00',
            ];
            // $transactionsBalance = optional(DB::table('transactions')
            // ->where('seller_id', $userId)
            // ->where('depositOrWithdraw_amount', 1)
            // ->where('transaction_add_date', '>', $finaltime)
            // ->whereIn('transaction_remarks', ['Deposit to customer', 'Withdraw from customer'])
            // ->select(DB::raw('SUM(credit) - SUM(debit) as balance'))
            // ->first())->balance ?? 0;

            $balance = ($totalCollected + $deposit + $sellerAdvance->balance)
                - ($withdraw + $totalCommission + $totalWin);

            $data[] = [
                'img' => asset('assets/images/5.png'),
                'name' => $lang['balance'],
                'amount' => number_format($balance, 2),
            ];

            $notifications = DB::table('notifications')->where('seller_id', $userId)->where('notification_status', 'unread')->count();

            $jsonResponse = [
                'data' => $data,
                'cutList' => [],
                'unread_notifications' => $notifications,
                'success' => true,
                'msg' => 'Get Successfully',
            ];

            return response()->json($jsonResponse);
        }

        return response()->json(['msg' => 'Invalid request'], 401);
    }




    public function managerDashboard($user, $languageCode)
    {
        // Ensure the user is a manager
        if ($user->user_role !== 'manager') {
            abort(403, 'Unauthorized');
        }

        // Set timezone to Haiti and get the current day range
        $date = now()->setTimezone('America/Port-au-Prince');
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        // Get the most recent cut from the management history within the current day
        $mostRecentCut = DB::table('cut_history')
            ->where('user_id', $user->user_id)
            ->whereBetween('add_datetime', [$startOfDay, $endOfDay])
            ->orderBy('add_datetime', 'desc')
            ->first();

        // Set the date to show data from (using the most recent cut date or the start of the day)
        $showFromDate = $mostRecentCut ? $mostRecentCut->add_datetime : $startOfDay;

        // Retrieve seller IDs under the manager
        $sellerIds = DB::table('users')
            ->where('added_user_id', $user->user_id)
            ->where('status', 1)
            ->where('is_deleted', '<>', '1')
            ->pluck('user_id')
            ->toArray();

        // Build the data array with more meaningful column names
        $dashboardData = [
            'totalSold' => DB::table('orders')
                ->whereIn('user_id', $sellerIds)
                ->where('adddatetime', '>', $showFromDate)
                ->sum('grand_total'),

            'totalCollected' => DB::table('orders')
                ->whereIn('user_id', $sellerIds)
                ->where('adddatetime', '>', $showFromDate)
                ->sum('grand_total'),

            'totalSellers' => count($sellerIds),

            'totalCommission' => (DB::table('orders')
                ->whereIn('user_id', $sellerIds)
                ->where('adddatetime', '>', $showFromDate)
                ->sum('grand_total') / 100) * $user->commission,

            'totalPaid' => DB::table('transactions')
                ->whereIn('seller_id', $sellerIds)
                ->where('transaction_add_date', '>', $showFromDate)
                ->where('balance', 0)
                ->sum('debit'),

            'totalWin' => DB::table('order_item')
                ->join('orders', 'order_item.order_id', '=', 'orders.order_id')
                ->whereIn('orders.user_id', $sellerIds)
                ->where('orders.adddatetime', '>', $showFromDate)
                ->sum('order_item.winning_amount'),

            'cashInHand' => DB::table('transactions')
                ->whereIn('seller_id', $sellerIds)
                ->where('transaction_add_date', '>', $showFromDate)
                ->sum('credit') -
                DB::table('transactions')
                ->whereIn('seller_id', $sellerIds)
                ->where('transaction_add_date', '>', $showFromDate)
                ->sum('debit'),

            'appCommission' => DB::table('orders')
                ->whereIn('user_id', $sellerIds)
                ->where('adddatetime', '>', $showFromDate)
                ->sum('grand_total') * 0.005,

            // Deposit and Withdraw
            'total_deposit' => DB::table('transactions')
                ->whereIn('seller_id', $sellerIds)
                ->where('transaction_add_date', '>', $showFromDate)
                ->where('transaction_remarks', 'Deposit to customer')
                ->sum('debit'),
            // dd($deposit);
            'total_withdraw' => DB::table('transactions')
                ->whereIn('seller_id', $sellerIds)
                ->where('transaction_add_date', '>', $showFromDate)
                ->where('transaction_remarks', 'Withdraw from customer')
                ->sum('credit'),
        ];

        // Calculate sellers' total commission
        $sellersTotalCommission = 0;

        foreach ($sellerIds as $sellerId) {
            // Get total sales for this seller
            $sellerTotalSales = DB::table('orders')
                ->where('user_id', $sellerId)
                ->where('adddatetime', '>', $showFromDate)
                ->sum('grand_total');

            // Skip if seller has no sales
            if ($sellerTotalSales <= 0) {
                continue;
            }

            $depositOfSellers = $sellerTotalSales + $dashboardData['total_deposit'];

            // Get seller's commission rate
            $sellerCommissionRate = DB::table('users')
                ->where('user_id', $sellerId)
                ->where('is_deleted', '<>', '1')
                ->value('commission');

            // Calculate commission based on seller's total sales
            $sellerCommission = ($depositOfSellers * $sellerCommissionRate) / 100;

            $sellersTotalCommission += $sellerCommission;
        }

        // Add sellers' total commission to dashboard data
        $dashboardData['sellersTotalCommission'] = $sellersTotalCommission;


        // Translation arrays
        $translations = [
            'en' => [
                'totalSold' => 'Total Sold',
                'commission' => 'Commission',
                'totalSellers' => 'Total Sellers',
                'sellersTotalCommission' => 'Total Sellers Commission',
                'totalCommission' => 'Total Commission',
                'paidWinningNumber' => 'Paid winning amount',
                'totalWin' => 'Total Win',
                'balance' => 'Balance',
                'appCommission' => 'App Commission',
                'sellersDeposit' => 'Total Deposit',
                'sellersWithdraw' => 'Total Withdraw',
            ],
            'es' => [
                'totalSold' => 'Total vendido',
                'commission' => 'Comisión',
                'totalSellers' => 'Total de vendedores',
                'sellersTotalCommission' => 'Comisión total de vendedores',
                'totalCommission' => 'Comisión total',
                'paidWinningNumber' => 'Monto ganador pagado',
                'totalWin' => 'Ganancia total',
                'balance' => 'Saldo',
                'appCommission' => 'Comisión de la aplicación',
                'sellersDeposit' => 'Depósito Total',
                'sellersWithdraw' => 'Retiro total',
            ],
            'fr' => [
                'totalSold' => 'Total vendu',
                'commission' => 'Commission',
                'totalSellers' => 'Nombre total de vendeurs',
                'sellersTotalCommission' => 'Commission totale des vendeurs',
                'totalCommission' => 'Commission totale',
                'paidWinningNumber' => 'Montant gagnant payé',
                'totalWin' => 'Total des gains',
                'balance' => 'Équilibre',
                'appCommission' => 'Commission de l\'application',
                'sellersDeposit' => 'Dépôt total',
                'sellersWithdraw' => 'Retrait total',
            ],
            'ht' => [
                'totalSold' => 'Total vann',
                'commission' => 'Komisyon',
                'totalSellers' => 'Total vandè yo',
                'sellersTotalCommission' => 'Komisyon Vandè Total',
                'totalCommission' => 'Komisyon total',
                'paidWinningNumber' => 'Peye montan genyen',
                'totalWin' => 'Total genyen',
                'balance' => 'Balans',
                'appCommission' => 'Komisyon aplikasyon an',
                'sellersDeposit' => 'Depo total',
                'sellersWithdraw' => 'Total Retire',
            ],
        ];

        // Fetch the appropriate language translation
        $lang = $translations[$languageCode] ?? $translations['en']; // Default to English if not found

        // Prepare the dashboard data
        $dashboardArray = [
            [
                'img' => asset('assets/images/1.png'),
                'name' => $lang['totalSold'],
                'amount' => number_format($dashboardData['totalSold'], 2),
            ],
            [
                'img' => asset('assets/images/3.png'),
                'name' => $lang['commission'],
                'amount' =>  $user->commission . "%",
            ],
            [
                'img' => asset('assets/images/3.png'),
                'name' => $lang['totalCommission'],
                'amount' => number_format($dashboardData['totalCommission'], 2),
            ],
            [
                'img' => asset('assets/images/2.png'),
                'name' => $lang['totalSellers'],
                'amount' => number_format($dashboardData['totalSellers'], 2),
            ],
            [
                'img' => asset('assets/images/3.png'),
                'name' => $lang['sellersTotalCommission'],
                'amount' => number_format($dashboardData['sellersTotalCommission'], 2),
            ],
            [
                'img' => asset('assets/images/5.png'),
                'name' => $lang['paidWinningNumber'],
                'amount' => number_format($dashboardData['totalPaid'], 2),
            ],
            [
                'img' => asset('assets/images/5.png'),
                'name' => $lang['totalWin'],
                'amount' => number_format($dashboardData['totalWin'], 2),
            ],
            [
                'img' => asset('assets/images/3.png'),
                'name' => $lang['appCommission'],
                'amount' => number_format($dashboardData['appCommission'], 2),
            ],
            [
                'img' => asset('assets/images/3.png'),
                'name' => $lang['sellersDeposit'],
                'amount' => number_format($dashboardData['total_deposit'], 2),
            ],
            [
                'img' => asset('assets/images/3.png'),
                'name' => $lang['sellersWithdraw'],
                'amount' => number_format($dashboardData['total_withdraw'], 2),
            ],
            [
                'img' => asset('assets/images/3.png'),
                'name' => $lang['balance'],
                'amount' => number_format($dashboardData['totalSold'] - $dashboardData['totalCommission'] - $dashboardData['totalWin'], 2),
            ],
        ];

        // Retrieve cut history for the current day
        // $cutHistory = DB::table('cut_history')
        //     ->select('cut_sale', 'cut_commision', 'cut_winners', 'cut_balance', 'add_datetime')
        //     ->where('user_id', $user->user_id)
        //     ->orderByDesc('cut_id')
        //     ->limit(3)
        //     ->get();

        // Prepare the JSON response
        $jsonResponse = [
            'data' => $dashboardArray,
            'cutList' => [],
            'unread_notifications' => 0,
            'success' => true,
            'msg' => 'Get Successfully',
        ];

        return response()->json($jsonResponse);
    }







    public function collectBalance(Request $request)
    {


        $user = $request->input('user_id');
        $addedUserId = auth()->user()->user_id;
        $balance = intval(str_replace(',', '', $request->input('balance')));
        $commission = $request->input('commission');
        $totalSale = $request->input('total_sale');
        $paidWinning = intval(str_replace(',', '', $request->input('paid_winning')));

        DB::beginTransaction();

        try {
            DB::table('orders')
                ->where('user_id', $user)
                ->where('lotterycollected', 0)
                ->update(['lotterycollected' => 1]);

            DB::table('transactions')->insert([
                'debit' => $commission ? $commission : 0,
                'credit' => 0,
                'balance' => 0,
                'seller_id' => $user,
                'transaction_remarks' => 'commission added'
            ]);

            if ($balance > 0) {
                DB::table('transactions')->insert([
                    'debit' => $balance ? $balance : 0,
                    'credit' => 0,
                    'balance' => 0,
                    'seller_id' => $user,
                    'transaction_remarks' => 'balance Collected'
                ]);
            } else {
                $balance2 = abs($balance);
                DB::table('transactions')->insert([
                    'credit' => $balance2,
                    'debit' => 0,
                    'balance' => 0,
                    'seller_id' => $user,
                    'transaction_remarks' => 'balance given'
                ]);
            }

            DB::table('cut_history')->insert([
                'user_id' => $user,
                'user_added_id' => $addedUserId,
                'cut_sale' => $totalSale,
                'cut_commision' => $commission,
                'cut_winners' => $paidWinning,
                'cut_balance' => $balance
            ]);

            DB::table('transactions')
                ->where('seller_id', $user)
                ->where('debit', '>', 0)
                ->update(['balance' => 1]);

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => 'balance Collected dashboard cleared',
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'msg' => $e->getMessage(),
            ]);
        }
    }



    public function addWinningamountbySeller(Request $request)
    {

        try {
            DB::beginTransaction();

            if (request()->filled(['order_item_id', 'winning_amount'])) {
                $userId = auth()->user()->user_id;
                $orderItemId = request('order_item_id');
                $winningAmount = request('winning_amount');
                $remark = "paid amount to " . $orderItemId;

                // Insert transaction record
                $inserted = DB::table('transactions')->insertGetId([
                    'debit' => $winningAmount,
                    'credit' => 0,
                    'balance' => 0,
                    'transaction_remarks' => $remark,
                    'seller_id' => $userId,
                    'order_item_id' => $orderItemId,
                ]);

                // Update order item
                if ($inserted) {
                    DB::table('order_item')->where('order_item_id', $orderItemId)->update([
                        'transaction_paid_id' => $inserted
                    ]);

                    DB::commit();

                    $response = [
                        'success' => true,
                        'msg' => 'Paid this lottery'
                    ];
                } else {
                    $response = [
                        'success' => false,
                        'msg' => 'Failed to insert transaction'
                    ];
                }

                return response()->json($response);
            }
        } catch (QueryException $e) {
            DB::rollBack();
            $response = [
                'success' => false,
                'msg' => 'Database error: ' . $e->getMessage()
            ];
            return response()->json($response);
        } catch (\Exception $e) {
            DB::rollBack();
            $response = [
                'success' => false,
                'msg' => 'Error: ' . $e->getMessage()
            ];
            return response()->json($response);
        }
    }
}
