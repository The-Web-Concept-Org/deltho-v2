<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Transaction;
use Carbon\Carbon;
use App\Models\User;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class OrderController extends Controller
{

    public function getOrderHistory(Request $request)
    {
        try {
            $authUser = auth()->user();
            $userIds = $request->input('user_ids', []);
            $lotteryIds = $request->input('lottery', []);
            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');
            $filterBy = $request->input('filter_by');

            // ✅ Step 1: Compute date range
            if (!$fromDate || !$toDate) {
                $toDate = Carbon::now();

                switch ($filterBy) {
                    case 'today':
                        $fromDate = Carbon::today();
                        break;
                    case 'yesterday':
                        $fromDate = Carbon::yesterday();
                        $toDate = Carbon::yesterday()->endOfDay();
                        break;
                    case 'thisWeek':
                        $fromDate = Carbon::now()->startOfWeek();
                        break;
                    case 'lastWeek':
                        $fromDate = Carbon::now()->subWeek()->startOfWeek();
                        $toDate = Carbon::now()->subWeek()->endOfWeek();
                        break;
                    case 'thisMonth':
                        $fromDate = Carbon::now()->startOfMonth();
                        break;
                    default:
                        $fromDate = Carbon::now()->subMonth();
                        break;
                }
            } else {
                $fromDate = Carbon::createFromFormat('d M, Y', $fromDate)->startOfDay();
                $toDate = Carbon::createFromFormat('d M, Y', $toDate)->endOfDay();
            }

            // ✅ Step 2: Preload all users in one query
            $allUsers = DB::table('users')
                ->select('user_id', 'username', 'user_role', 'added_user_id', 'status')
                ->whereIn('user_id', $userIds)
                ->orWhereIn('added_user_id', $userIds) // get sellers under managers
                ->where('status', 1)
                ->get();

            if ($allUsers->isEmpty()) {
                return response()->json([
                    'msg' => 'No users found',
                    'success' => false,
                    'orders' => []
                ]);
            }

            // ✅ Step 3: Extract all relevant user IDs (managers + sellers)
            $targetUserIds = $allUsers->pluck('user_id')->unique()->values()->toArray();

            // ✅ Step 4: Query all orders in ONE query
            $query = DB::table('orders')
                ->join('order_item', 'orders.order_id', '=', 'order_item.order_id')
                ->join('users', 'orders.user_id', '=', 'users.user_id')
                ->select(
                    DB::raw('CAST(orders.order_id AS UNSIGNED) AS order_id'),
                    DB::raw("DATE_FORMAT(orders.order_date, '%m/%d/%Y %H:%i:%s') AS order_date"),
                    DB::raw("CONCAT(users.username, ' (', users.user_role, ')') AS user_name"),
                    'orders.grand_total',
                    DB::raw('SUM(CASE WHEN order_item.winning_amount != 0 THEN order_item.winning_amount ELSE 0 END) AS winning_amount'),
                    'orders.adddatetime'
                )
                ->whereIn('orders.user_id', $targetUserIds)
                ->whereBetween('orders.order_date', [$fromDate, $toDate])
                ->groupBy('orders.order_id', 'orders.order_date', 'user_name', 'orders.grand_total', 'orders.adddatetime')
                ->orderByDesc('orders.order_id');

            if (!empty($lotteryIds)) {
                $query->whereIn('order_item.product_id', $lotteryIds);
            }

            // ✅ Step 5: Execute once
            $orders = $query->get();

            // ✅ Step 6: Transform results efficiently
            $result = $orders->map(function ($order) {
                return [
                    'order_id' => (int) $order->order_id,
                    'nine_order_id' => str_pad((int) $order->order_id, 9, '0', STR_PAD_LEFT),
                    'order_date' => (string) $order->order_date,
                    'user_name' => (string) $order->user_name,
                    'grand_total' => (string) $order->grand_total,
                    'winning_amount' => (string) $order->winning_amount,
                    'adddatetime' => (string) $order->adddatetime,
                ];
            });

            return response()->json([
                'msg' => 'Order history fetched successfully',
                'success' => true,
                'orders' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'msg' => 'Error fetching order history',
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }





    // public function createOrder(Request $request)
    // {
    //     $url = 'abvcd';
    //     $today = now()->toDateString();
    //     $data = $request->input('data');

    //     // Check if the user is authenticated
    //     if (auth()->check()) {
    //         $user = auth()->user();
    //         $userId = $user->user_id;

    //         if (!empty($data)) {
    //             // Rest of your code...

    //             $order = new Order([
    //                 'order_date' => $today,
    //                 'client_name' => $request->input('name'),
    //                 'client_contact' => $request->input('number'),
    //                 'user_id' => $userId,
    //                 'sub_total' => 0,
    //             ]);

    //             // Rest of your code...

    //             $response = [
    //                 'url' => $url,

    //                 'msg' => 'Lottery Sold Successfully',
    //                 'is_Status' => 1,
    //             ];
    //         } else {
    //             $response = [
    //                 'url' => '',
    //                 'orderID' => 0,
    //                 'msg' => 'Error: No data provided.',
    //                 'is_Status' => 0,
    //             ];
    //         }
    //     } else {
    //         $response = [
    //             'url' => '',
    //             'orderID' => 0,
    //             'msg' => 'Error: User not authenticated.',
    //             'is_Status' => 0,
    //         ];
    //     }

    //     return response()->json($response);
    // }

    public function checkLotteryLimit(Request $request)
    {
        try {
            $user = auth()->user();
            $userId = (string) $user->user_id;
            $lotteryId = $request->input('lottery_id');
            $lotteryNumber = $request->input('lottery_number');
            $lotteryFraction = $request->input('lottery_fraction');
            $manager = User::where('user_id', $user->added_user_id)->first();

            $today = now()->toDateString();
            date_default_timezone_set("America/Port-au-Prince");

            $serverTimeWithHaiti = new DateTime(now()->format('H:i:s'));

            $lottery = DB::table('lotteries')
                ->where('lot_id', $lotteryId)
                ->first();

            // === Original Manager-based Limit Logic ===
            $lotteryLimit = DB::table('limit_game')
                ->where('lottery_id', $lottery->lot_id)
                ->where('limit_type', 1)
                ->where('limit_ball', $lotteryNumber)
                ->whereJsonContains('user_id', (string) $manager->user_id)
                ->first();

            if (!$lotteryLimit) {
                $lotteryLimit = DB::table('limit_game')
                    ->where('lottery_id', $lottery->lot_id)
                    ->where('limit_type', 1)
                    ->whereNull('limit_ball')
                    ->whereJsonContains('user_id', (string) $manager->user_id)
                    ->orderBy('limit_id', 'DESC')
                    ->first();
            }

            if ($lotteryLimit) {
                $managerIds = json_decode($lotteryLimit->user_id, true);

                $sellers = User::whereIn('added_user_id', (array) $managerIds)
                    ->where('status', 1)
                    ->where('is_deleted', 0)
                    ->pluck('user_id');

                $sellerOrders = Order::whereIn('user_id', $sellers)
                    ->whereDate('order_date', $today)
                    ->pluck('order_id');

                if ($lotteryLimit->limit_ball == null) {
                    $order_items = OrderItem::whereIn('order_id', $sellerOrders)
                        ->where('product_id', $lottery->lot_id)
                        ->sum('lot_frac');
                } else {
                    $order_items = OrderItem::whereIn('order_id', $sellerOrders)
                        ->where('product_id', $lottery->lot_id)
                        ->where('lot_number', $lotteryLimit->limit_ball)
                        ->sum('lot_frac');
                }

                $limit = $lotteryLimit->limit_frac - $order_items;

                if ($lotteryFraction > $limit) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The amount of lottery exceeds the limit (' . $lottery->lot_name . ' ' . $lotteryLimit->limit_ball . ').'
                    ]);
                }
            }

            // === New: Customer-level limit check ===
            $customerLimit = DB::table('limit_game')
                ->where('lottery_id', $lottery->lot_id)
                ->where('limit_type', 1)
                ->where(function ($query) use ($lotteryNumber) {
                    $query->where('limit_ball', $lotteryNumber)
                        ->orWhereNull('limit_ball');
                })
                ->whereJsonContains('user_id', 'customer')
                ->orderByRaw("CASE WHEN limit_ball IS NOT NULL THEN 1 ELSE 2 END") // Prefer specific over general
                ->first();

            if ($customerLimit) {
                $customerOrders = Order::where('user_id', $userId)
                    ->whereDate('order_date', $today)
                    ->pluck('order_id');

                $orderItemsQuery = OrderItem::whereIn('order_id', $customerOrders)
                    ->where('product_id', $lottery->lot_id);

                if (!is_null($customerLimit->limit_ball)) {
                    $orderItemsQuery->where('lot_number', $customerLimit->limit_ball);
                }

                $customerUsed = $orderItemsQuery->sum('lot_frac');
                $customerRemaining = $customerLimit->limit_frac - $customerUsed;

                if ($lotteryFraction > $customerRemaining) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The amount of lottery exceeds the customer limit (' . $lottery->lot_name . ' ' . $customerLimit->limit_ball . ').'
                    ]);
                }
            }

            return response()->json(['success' => true, 'message' => 'lottery can be played'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'msg' => $e->getMessage()]);
        }
    }


    public function createOrder(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'msg' => 'Error: User not authenticated.',
                'orderID' => '',
                'lotteryData' => '',
            ]);
        }

        $userId = (string) $user->user_id;
        $manager = null;

        if ($user->user_role !== 'customer') {
            $manager = User::select('user_id')
                ->where('user_id', $user->added_user_id)
                ->first();
        }

        date_default_timezone_set("America/Port-au-Prince");
        $today = now()->toDateString();
        $serverTimeWithHaiti = new DateTime(now()->format('H:i:s'));
        $data = collect($request->input('data', []));

        if ($data->isEmpty()) {
            return response()->json([
                'success' => false,
                'msg' => 'Error: No data provided.',
                'orderID' => '',
                'lotteryData' => '',
            ]);
        }

        // === PRELOAD LOTTERIES ===
        $lotteryIds = $data->pluck('loteryId')->unique();
        $lotteries = DB::table('lotteries')
            ->whereIn('lot_id', $lotteryIds)
            ->get()
            ->keyBy('lot_id');

        // === PRELOAD LIMITS FOR ALL LOTTERIES ===
        $limitGames = DB::table('limit_game')
            ->whereIn('lottery_id', $lotteryIds)
            ->where('limit_type', 1)
            ->get()
            ->groupBy('lottery_id');

        $groupedData = $data->groupBy(fn($item) => $item['loteryId'] . '_' . $item['type']);

        foreach ($groupedData as $groupKey => $items) {
            $first = $items->first();
            $loteryId = $first['loteryId'];
            $type = $first['type'];
            $lottery = $lotteries->get($loteryId);

            if (!$lottery) {
                return response()->json(['success' => false, 'msg' => 'Invalid lottery ID.'], 400);
            }

            $lotteryOpenTime = DateTime::createFromFormat('H:i:s', $lottery->lot_opentime);
            $lotteryCloseTime = DateTime::createFromFormat('H:i:s', $lottery->lot_closetime);
            if ($serverTimeWithHaiti < $lotteryOpenTime || $serverTimeWithHaiti > $lotteryCloseTime) {
                return response()->json([
                    'success' => false,
                    'msg' => $lottery->lot_name . ' Lottery is closed.',
                ], 400);
            }

            // === GET RELEVANT LIMITS FROM PRELOADED DATA ===
            $lotteryLimits = $limitGames->get($loteryId, collect());
            $generalLimit = $lotteryLimits
                ->where('lot_type', $type)
                ->whereNull('limit_ball')
                ->sortByDesc('limit_id')
                ->first();

            if ($generalLimit) {
                $managerIds = array_filter(json_decode($generalLimit->user_id, true) ?: [], fn($id) => is_numeric($id));
                $sellers = User::whereIn('added_user_id', $managerIds)
                    ->where('status', 1)
                    ->where('is_deleted', 0)
                    ->pluck('user_id');

                // Preload today's sold items for all sellers
                $todaySellerOrders = Order::whereIn('user_id', $sellers)
                    ->whereDate('order_date', $today)
                    ->pluck('order_id');

                $soldItems = OrderItem::whereIn('order_id', $todaySellerOrders)
                    ->where('product_id', $loteryId)
                    ->where('lot_type', $type)
                    ->get(['lot_number', 'lot_frac']);

                $soldMap = $soldItems->groupBy('lot_number')->map(fn($g) => $g->sum('lot_frac'));

                foreach ($items as $item) {
                    $number = $item['number'];
                    $frac = $item['frac'];
                    $numbersToCheck = [$number];
                    if ($type === 'MAR') {
                        $parts = explode('x', $number);
                        if (count($parts) === 2) {
                            $numbersToCheck[] = $parts[1] . 'x' . $parts[0];
                        }
                    }

                    $soldFrac = collect($numbersToCheck)->sum(fn($n) => $soldMap->get($n, 0));
                    $remaining = $generalLimit->limit_frac - $soldFrac;

                    if ($frac > $remaining) {
                        $numberDisplay = ($type === 'MAR') ? implode(' or ', $numbersToCheck) : $number;
                        return response()->json([
                            'success' => false,
                            'message' => "You can't sell more than {$remaining} for lottery {$lottery->lot_name} type {$type}, number {$numberDisplay}.",
                        ], 400);
                    }
                }
            }

            // === SPECIFIC LIMIT CHECK ===
            foreach ($items as $item) {
                $number = $item['number'];
                $type = $item['type'];
                $frac = $item['frac'];

                $numbersToCheck = [$number];
                if ($type === 'MAR') {
                    $parts = explode('x', $number);
                    if (count($parts) === 2) {
                        $numbersToCheck[] = $parts[1] . 'x' . $parts[0];
                    }
                }

                foreach ($numbersToCheck as $numberPattern) {
                    $specificLimit = $lotteryLimits
                        ->where('lot_type', $type)
                        ->where('limit_ball', $numberPattern)
                        ->first();

                    if ($specificLimit) {
                        $managerIds = array_filter(json_decode($specificLimit->user_id, true) ?: [], fn($id) => is_numeric($id));
                        $sellers = User::whereIn('added_user_id', $managerIds)
                            ->where('status', 1)
                            ->where('is_deleted', 0)
                            ->pluck('user_id');

                        $todaySellerOrders = Order::whereIn('user_id', $sellers)
                            ->whereDate('order_date', $today)
                            ->pluck('order_id');

                        $soldForBall = OrderItem::whereIn('order_id', $todaySellerOrders)
                            ->where('product_id', $loteryId)
                            ->where('lot_type', $type)
                            ->whereIn('lot_number', $numbersToCheck)
                            ->sum('lot_frac');

                        $remaining = $specificLimit->limit_frac - $soldForBall;
                        if ($frac > $remaining) {
                            $numberDisplay = ($type === 'MAR') ? implode(' or ', $numbersToCheck) : $number;
                            return response()->json([
                                'success' => false,
                                'message' => "You can't sell more than {$remaining} for lottery {$lottery->lot_name}, number pattern {$numberDisplay}, type {$type}.",
                            ], 400);
                        }
                    }
                }
            }
        }

        // === BALANCE CHECK (CUSTOMER ONLY) ===
        if ($user->user_role === 'customer') {
            $summary = DB::table('transactions')
                ->where('customer_id', $userId)
                ->select(
                    DB::raw('COALESCE(SUM(credit), 0) as total_credit'),
                    DB::raw('COALESCE(SUM(debit), 0) as total_debit')
                )
                ->first();

            $balance = $summary->total_credit - $summary->total_debit;
            $purchaseTotal = $data->sum(fn($item) => is_numeric($item['quator']) ? $item['quator'] : 0);

            if ($purchaseTotal > $balance) {
                return response()->json([
                    'success' => false,
                    'msg' => "Insufficient balance. Your available balance is "
                        . number_format($balance, 2)
                        . " and the total purchase amount is "
                        . number_format($purchaseTotal, 2) . ".",
                ]);
            }
        }

        // === CREATE ORDER ===
        $order = Order::create([
            'order_date' => $today,
            'client_name' => (string) $request->input('name'),
            'client_contact' => (string) $request->input('number'),
            'user_id' => $userId,
            'sub_total' => '0',
        ]);

        $orderId = $order->order_id;
        $grandTotal = 0;

        $orderItems = [];
        foreach ($data as $item) {
            $calculated = (string) $item['frac'];
            $grandTotal += is_numeric($item['quator']) ? $item['quator'] : 0;

            $orderItems[] = [
                'order_id' => $orderId,
                'product_id' => (string) $item['loteryId'],
                'product_name' => (string) $item['loteryName'],
                'lot_number' => (string) $item['number'],
                'lot_frac' => $calculated,
                'lot_amount' => (string) $item['quator'],
                'lot_type' => (string) $item['type'],
                'is_free' => (string) $item['isFree'],
            ];
        }

        // Bulk insert for performance
        OrderItem::insert($orderItems);

        // === CREATE TRANSACTION ===
        $transaction = Transaction::create([
            'debit' => $user->user_role === 'customer' ? (string) $grandTotal : '0',
            'credit' => $user->user_role !== 'customer' ? (string) $grandTotal : '0',
            'balance' => '0',
            'customer_id' => $user->user_role === 'customer' ? $userId : null,
            'seller_id' => $user->user_role !== 'customer' ? $userId : null,
            'transaction_remarks' => $user->user_role === 'customer'
                ? 'Lottery Bought.' . $orderId
                : 'Lottery sold.' . $orderId,
        ]);

        $order->update([
            'sub_total' => (string) $grandTotal,
            'grand_total' => (string) $grandTotal,
            'transaction_id' => (string) $transaction->transaction_id,
        ]);

        // === Fetch Order for Printing ===
        $orderDetails = Order::select(
            'order_id',
            DB::raw("LPAD(orders.order_id, 9, '0') AS nine_order_id"),
            'order_date',
            'adddatetime',
            'client_name',
            'client_contact',
            'sub_total',
            'grand_total'
        )
            ->with(['orderItems' => function ($q) {
                $q->select('order_item_id', 'order_id', 'product_id', 'product_name', 'lot_number', 'lot_frac', 'lot_amount', 'lot_type', 'winning_amount', 'is_free');
            }])
            ->find($orderId);

        // === Determine Admin User ===
        $addedUser = User::find($order->user_id);
        $adminUser = $addedUser;

        if ($addedUser->user_role == 'customer') {
            $adminUser = User::find(1);
        } elseif ($addedUser->user_role != 'admin') {
            $parent = User::find($addedUser->added_user_id);
            if ($parent && $parent->user_role != 'admin') {
                $adminUser = User::find($parent->added_user_id);
            } else {
                $adminUser = $parent;
            }
        }

        // === Generate QR Code ===
        $url = "http://app.deltho.info/api/printOrder/" . $orderId . "/web";
        $qrCode = Builder::create()
            ->writer(new PngWriter())
            ->data($url)
            ->size(300)
            ->margin(10)
            ->build();
        $qrCodeDataUri = $qrCode->getDataUri();

        $lang = $request->input('languageCode', 'en');

        return response()->json([
            'success' => true,
            'msg' => 'Lottery Added Successfully',
            'win_msg' => 'The colored lotteries are won!',
            'orderID' => $orderId,
            'lotteryData' => $orderDetails,
            'qrCode' => $qrCodeDataUri,
            'adminUser' => $adminUser,
            'seller' => $addedUser,
            'lang' => $lang,
        ]);
    }


    public function printOrder(Request $request, $id, $orderItem = null)
    {
        $lang = $request->input('languageCode');
        // dd($lang);
        // Check if the id is provided in the URL
        if ($orderItem !== null && $orderItem != 'web') {

            // Retrieve the order items based on order_id using DB facade
            $orderItems = OrderItem::where('order_id', $id)
                ->where('winning_amount', '>', 0)
                ->get();

            if ($orderItems->isEmpty()) {
                return response()->json(['success' => false, 'msg' => 'Order item not found'], 200);
            }

            // Loop through each item and update the verify_status to 'verified'
            foreach ($orderItems as $item) {
                DB::table('order_item')
                    ->where('order_item_id', $item->order_item_id)
                    ->update(['verify_status' => 'verified']);
            }

            return response()->json(['success' => true, 'msg' => 'Order item(s) verified'], 200);
        } else {
            if ($orderItem !== null && $orderItem == 'web') {

                $currentOrderId = $id;

                // dd($currentOrderId);

                $orderDetails = Order::select('order_id', DB::raw("LPAD(orders.order_id, 9, '0') AS nine_order_id"), 'order_date', 'adddatetime', 'client_name', 'client_contact', 'sub_total', 'grand_total')
                    ->with(['orderItems' => function ($query) {
                        $query->select('order_item_id', 'order_id', 'product_id', 'product_name', 'lot_number', 'lot_frac', 'lot_amount', 'lot_type', 'winning_amount', 'is_free');
                    }])
                    ->where('order_id', $currentOrderId)
                    ->first();

                if ($orderDetails) {

                    $groupedOrderItems = [];
                    foreach ($orderDetails->orderItems as $orderItem) {
                        $lotteryId = $orderItem->product_id;

                        // Check if the lottery ID already exists in the groupedOrderItems array

                        if (!isset($groupedOrderItems[$lotteryId])) {
                            $groupedOrderItems[$lotteryId] = [];
                        }

                        // Add the current order item details to the corresponding lottery ID array
                        $groupedOrderItems[$lotteryId][] = [
                            'lot_number' => $orderItem->lot_number,
                            'lot_frac' => $orderItem->lot_frac,
                            'lot_amount' => $orderItem->lot_amount
                        ];
                    }
                    //$orderDetails->groupedOrderItems = $groupedOrderItems;
                }

                $order = Order::where('order_id', $currentOrderId)->first();
                $adminUser;
                $addedUser = User::where('user_id', $order->user_id)->first();
                $adminUser = $addedUser;
                if ($addedUser->user_role == 'customer') {
                    $adminUser = User::where('user_id', 1)->first();
                } elseif ($addedUser->user_role != 'admin') {
                    $addedUserAdmin = User::where('user_id', $addedUser->added_user_id)->first();
                    $adminUser = $addedUserAdmin;
                    if ($addedUserAdmin->user_role != 'admin') {
                        $admin = User::where('user_id', $addedUserAdmin->added_user_id)->first();
                        $adminUser = $admin;
                    }
                }
                // Generate QR Code
                $url = "http://app.deltho.info/api/printOrder/" . $currentOrderId . "/web";
                $qrCode = Builder::create()
                    ->writer(new PngWriter())
                    ->data($url)
                    ->size(300)
                    ->margin(10)
                    ->build();

                $qrCodeDataUri = $qrCode->getDataUri();

                // Construct the response
                $response = [
                    'success' => true,
                    'msg' => 'Lottery get Successfully',
                    'win_msg' => 'The colored lotteries are won!',
                    'orderID' => $currentOrderId,
                    'lotteryData' => $orderDetails,
                    'qrCode' => $qrCodeDataUri,
                    'adminUser' => $adminUser,
                    'seller' => $addedUser,
                    'lang' => $lang,
                ];
                return view('print', ['data' => $response, 'adminUser' => $adminUser, 'seller' => $addedUser]);
            } else {

                // Regular print order logic when id is present
                $currentOrderId = $id;

                // dd($currentOrderId);

                $orderDetails = Order::select('order_id', DB::raw("LPAD(orders.order_id, 9, '0') AS nine_order_id"), 'order_date', 'adddatetime', 'client_name', 'client_contact', 'sub_total', 'grand_total')
                    ->with(['orderItems' => function ($query) {
                        $query->select('order_item_id', 'order_id', 'product_id', 'product_name', 'lot_number', 'lot_frac', 'lot_amount', 'lot_type', 'winning_amount', 'is_free');
                    }])
                    ->where('order_id', $currentOrderId)
                    ->first();

                if ($orderDetails) {

                    $groupedOrderItems = [];
                    foreach ($orderDetails->orderItems as $orderItem) {
                        $lotteryId = $orderItem->product_id;

                        // Check if the lottery ID already exists in the groupedOrderItems array

                        if (!isset($groupedOrderItems[$lotteryId])) {
                            $groupedOrderItems[$lotteryId] = [];
                        }

                        // Add the current order item details to the corresponding lottery ID array
                        $groupedOrderItems[$lotteryId][] = [
                            'lot_number' => $orderItem->lot_number,
                            'lot_frac' => $orderItem->lot_frac,
                            'lot_amount' => $orderItem->lot_amount
                        ];
                    }
                    //$orderDetails->groupedOrderItems = $groupedOrderItems;
                }

                $order = Order::where('order_id', $currentOrderId)->first();
                $adminUser;
                $addedUser = User::where('user_id', $order->user_id)->first();
                $adminUser = $addedUser;
                if ($addedUser->user_role == 'customer') {
                    $adminUser = User::where('user_id', 1)->first();
                } elseif ($addedUser->user_role != 'admin') {
                    $addedUserAdmin = User::where('user_id', $addedUser->added_user_id)->first();
                    $adminUser = $addedUserAdmin;
                    if ($addedUserAdmin->user_role != 'admin') {
                        $admin = User::where('user_id', $addedUserAdmin->added_user_id)->first();
                        $adminUser = $admin;
                    }
                }
                // Generate QR Code
                $url = "http://app.deltho.info/api/printOrder/" . $currentOrderId . "/web";
                $qrCode = Builder::create()
                    ->writer(new PngWriter())
                    ->data($url)
                    ->size(300)
                    ->margin(10)
                    ->build();

                $qrCodeDataUri = $qrCode->getDataUri();

                // Construct the response
                $response = [
                    'success' => true,
                    'msg' => 'Lottery get Successfully',
                    'win_msg' => 'The colored lotteries are won!',
                    'orderID' => $currentOrderId,
                    'lotteryData' => $orderDetails,
                    'qrCode' => $qrCodeDataUri,
                    'adminUser' => $adminUser,
                    'seller' => $addedUser,
                    'lang' => $lang,
                ];

                // Return the response
                return response()->json([
                    'success' => true,
                    'msg' => 'Lottery get Successfully',
                    'win_msg' => 'The colored lotteries are won!',
                    'orderID' => (int) $currentOrderId,
                    'lotteryData' => $orderDetails,
                    'qrCode' => $qrCodeDataUri,
                    'adminUser' => $adminUser,
                    'seller' => $addedUser,
                    'lang' => $lang,
                ]);
                // return view('print', ['data' => $response, 'adminUser' => $adminUser, 'seller' => $addedUser]);

            }
        }
    }

    // public function printOrder(Request $request, $id, $orderItem = null)
    // {
    //     $lang = $request->input('languageCode');

    //     // 1️⃣ Case: Verify winnings
    //     if ($orderItem !== null && $orderItem != 'web') {
    //         $orderItems = OrderItem::where('order_id', $id)
    //             ->where('winning_amount', '>', 0)
    //             ->get();

    //         if ($orderItems->isEmpty()) {
    //             return response()->json(['success' => false, 'msg' => 'Order item not found'], 200);
    //         }

    //         foreach ($orderItems as $item) {
    //             DB::table('order_item')
    //                 ->where('order_item_id', $item->order_item_id)
    //                 ->update(['verify_status' => 'verified']);
    //         }

    //         return response()->json(['success' => true, 'msg' => 'Order item(s) verified'], 200);
    //     }

    //     // 2️⃣ Case: Fetch order details
    //     $currentOrderId = $id;

    //     if ($orderItem !== null && $orderItem == 'web') {
    //         // Use your existing getOrderDetails() method
    //         $orderDetails = $this->getOrderDetails($currentOrderId);
    //     } else {
    //         // Direct query for order + items
    //         $orderDetails = Order::select(
    //                 'order_id',
    //                 DB::raw("LPAD(orders.order_id, 9, '0') AS nine_order_id"),
    //                 'order_date',
    //                 'adddatetime',
    //                 'client_name',
    //                 'client_contact',
    //                 'sub_total',
    //                 'grand_total'
    //             )
    //             ->with(['orderItems' => function ($query) {
    //                 $query->select(
    //                     'order_item_id',
    //                     'order_id',
    //                     'product_id',
    //                     'product_name',
    //                     'lot_number',
    //                     'lot_frac',
    //                     'lot_amount',
    //                     'lot_type',
    //                     'winning_amount',
    //                     'is_free'
    //                 );
    //             }])
    //             ->where('order_id', $currentOrderId)
    //             ->first();
    //     }

    //     if (!$orderDetails) {
    //         return response()->json(['success' => false, 'msg' => 'Order not found'], 404);
    //     }

    //     // 3️⃣ Find admin user
    //     $order = Order::where('order_id', $currentOrderId)->first();
    //     $addedUser = User::where('user_id', $order->user_id)->first();
    //     $adminUser = $addedUser;

    //     if ($addedUser->user_role == 'customer') {
    //         $adminUser = User::where('user_id', 1)->first();
    //     } elseif ($addedUser->user_role != 'admin') {
    //         $addedUserAdmin = User::where('user_id', $addedUser->added_user_id)->first();
    //         $adminUser = $addedUserAdmin;
    //         if ($addedUserAdmin->user_role != 'admin') {
    //             $adminUser = User::where('user_id', $addedUserAdmin->added_user_id)->first();
    //         }
    //     }

    //     // 4️⃣ Generate QR Code
    //     $url = "http://app.deltho.info/api/printOrder/" . $currentOrderId;
    //     $qrCode = Builder::create()
    //         ->writer(new PngWriter())
    //         ->data($url)
    //         ->size(300)
    //         ->margin(10)
    //         ->build();

    //     $qrCodeDataUri = $qrCode->getDataUri();

    //     // 5️⃣ Build JSON response
    //     $response = [
    //         'success' => true,
    //         'msg' => 'Lottery fetched successfully',
    //         'orderID' => $currentOrderId,
    //         'lotteryData' => $orderDetails,
    //         'qrCode' => $qrCodeDataUri,
    //         'lang' => $lang,
    //         'adminUser' => $adminUser,
    //         'seller' => $addedUser,
    //     ];

    //     if ($orderItem === null) {
    //         $response['win_msg'] = 'The colored lotteries are won!';
    //     }

    //     return response()->json($response, 200);
    // }

    public function orderList(Request $request)
    {
        // Retrieve user ID
        $user_id = auth()->user();

        // Parse input dates and set default values if not provided
        $fromDate = Carbon::createFromFormat('d M, Y', $request->input('from_date'))->startOfDay();
        $toDate = Carbon::createFromFormat('d M, Y', $request->input('to_date'))->endOfDay();
        $userIds = $request->input('user_ids', []);
        $lotteryIds = $request->input('lottery', []);

        $currentDateTime = Carbon::now();

        // Build base query
        $query = Order::select(
            'order_id',
            DB::raw("LPAD(orders.order_id, 9, '0') AS nine_order_id"),
            'order_date',
            'adddatetime',
            'client_name',
            'client_contact',
            'sub_total',
            'grand_total',
            'user_id',
            DB::raw("CASE WHEN TIMESTAMPDIFF(MINUTE, adddatetime, '$currentDateTime') <= 5 THEN 1 ELSE 0 END as is_deleted")
        )
            ->with(['orderItems' => function ($query) {
                $query->select('order_id', 'product_id', 'product_name', 'lot_number', 'lot_type', 'lot_frac', 'lot_amount', 'is_free', 'winning_amount')
                    ->with(['lottery' => function ($lotteryQuery) {
                        $lotteryQuery->select('lot_id', 'lot_closetime', 'lot_opentime');
                    }]);
            }])
            ->with(['addedUser' => function ($userQuery) {
                $userQuery->select('user_id', 'username', 'email'); // Add other user fields as needed
            }])
            ->whereBetween('order_date', [$fromDate, $toDate])
            ->orderBy('orders.order_id', 'DESC')
            ->limit(100);

        // Apply user filter
        if (!empty($userIds)) {
            $query->whereIn('user_id', $userIds);
        } else {
            $query->where('user_id', $user_id->user_id);
        }

        // ✅ Filter by lottery IDs (via order_items.product_id)
        if (!empty($lotteryIds)) {
            $query->whereHas('orderItems', function ($q) use ($lotteryIds) {
                $q->whereIn('product_id', $lotteryIds);
            });
        }

        $orders = $query->get();

        // Check if no orders found
        if ($orders->isEmpty()) {
            return response()->json([
                'success' => false,
                'msg' => 'No orders found for the specified date range.',
                'orders' => [],
            ]);
        }

        // Map and transform the data
        $orders = $orders->map(function ($order) {
            // Calculate sum of winning_amount from order items
            $totalWinningAmount = $order->orderItems->sum('winning_amount');

            return [
                'order_id' => (int) $order->order_id,
                'nine_order_id' => (string) $order->nine_order_id,
                'order_date' => (string) $order->order_date,
                'adddatetime' => (string) $order->adddatetime,
                'client_name' => (string) $order->client_name,
                'client_contact' => (string) $order->client_contact,
                'sub_total' => (string) $order->sub_total,
                'grand_total' => (string) $order->grand_total,
                'is_deleted' => (string) $order->is_deleted,
                'total_winning_amount' => (string) $totalWinningAmount,
                'user' => $order->addedUser ? [
                    'user_id' => (int) $order->addedUser->user_id,
                    'name' => (string) $order->addedUser->username,
                    'email' => (string) $order->addedUser->email,
                ] : null,
                'orderItems' => $order->orderItems->map(function ($item) {
                    return [
                        'order_id' => (int) $item->order_id,
                        'product_id' => (string) $item->product_id,
                        'product_name' => (string) $item->product_name,
                        'lot_number' => (string) $item->lot_number,
                        'lot_type' => (string) $item->lot_type,
                        'lot_frac' => (string) $item->lot_frac,
                        'lot_amount' => (string) $item->lot_amount,
                        'is_free' => (string) $item->is_free,
                        'winning_amount' => (string) $item->winning_amount,
                        'lot_opentime' => optional($item->lottery)->lot_opentime,
                        'lot_closetime' => optional($item->lottery)->lot_closetime,
                    ];
                })->toArray(),
            ];
        });

        // Return JSON response
        return response()->json([
            'success' => true,
            'msg' => 'order get',
            'orders' => $orders,
        ]);
    }

    public function orderprint(Request $request, $id)
    {
        // Assuming $currentOrderId is available
        $currentOrderId = $id; // Replace with your actual logic to get the current order ID

        // Assuming $orderId is available and contains the ID of the current order
        $orderId = $currentOrderId; // Replace with your actual logic to get the order ID

        // Assuming $orderDetails is available and contains the details of the current order
        // Replace this line with your logic to get order details
        $orderDetails = $this->getOrderDetails($currentOrderId);

        // Construct the response
        $response = [
            'success' => true,
            'msg' => 'Lottery get Successfully',
            'orderID' => $orderId,
            'lotteryData' => $orderDetails,
        ];

        // Return the response
        return response()->json($response);
    }


    public function deleteOrder(Request $request, $id)
    {


        // Retrieve order_id from the request
        $order_id = $id;

        // Delete related records
        try {
            OrderItem::where('order_id', $order_id)->delete();
            $order = Order::find($order_id);
            if ($order) {
                Transaction::where('transaction_id', $order->transaction_id)->delete();
                $order->delete();
            }
        } catch (\Exception $e) {
            // If an error occurs during deletion, return an error response
            return response()->json([
                'success' => false,
                'msg' => 'Error: User not authenticated.',
                'error' => 'Failed to delete order'
            ], 500);
        }

        // Return a success message
        return response()->json([
            'success' => true,

            'msg' => 'Order and related records deleted successfully'
        ]);
    }

    function getOrderDetails($orderId)
    {
        $orderDetails = Order::select(
            'order_id',
            DB::raw("LPAD(orders.order_id, 9, '0') AS nine_order_id"),
            'order_date',
            'adddatetime',
            'client_name',
            'client_contact',
            'sub_total',
            'grand_total'
        )
            ->with(['orderItems' => function ($query) {
                $query->select(
                    'order_item_id',
                    'order_id',
                    'product_id',
                    'product_name',
                    'lot_number',
                    'lot_frac',
                    'lot_amount',
                    'lot_type',
                    'is_free'
                );
            }])
            ->where('order_id', $orderId)
            ->first();

        if ($orderDetails) {
            // Convert specific order detail fields to strings
            $orderDetails->order_id = (int) $orderDetails->order_id; // Keep as integer
            $orderDetails->nine_order_id = strval($orderDetails->nine_order_id);
            $orderDetails->sub_total = strval($orderDetails->sub_total);
            $orderDetails->grand_total = strval($orderDetails->grand_total);

            $groupedOrderItems = [];
            foreach ($orderDetails->orderItems as $orderItem) {
                $lotteryId = strval($orderItem->product_id); // Convert lottery ID to string

                if (!isset($groupedOrderItems[$lotteryId])) {
                    $groupedOrderItems[$lotteryId] = [];
                }

                // Convert the order item details to strings
                $groupedOrderItems[$lotteryId][] = [
                    'lot_number' => strval($orderItem->lot_number),
                    'lot_frac' => strval($orderItem->lot_frac),
                    'lot_amount' => strval($orderItem->lot_amount),
                    'lot_type' => strval($orderItem->lot_type),
                    'is_free' => strval($orderItem->is_free),
                ];
            }

            // Attach grouped items to order details if needed in the response
            $orderDetails->groupedOrderItems = $groupedOrderItems;

            // Convert all orderItems fields to strings
            foreach ($orderDetails->orderItems as $orderItem) {
                $orderItem->order_item_id = strval($orderItem->order_item_id);
                $orderItem->order_id = strval($orderItem->order_id);
                $orderItem->product_id = strval($orderItem->product_id);
                $orderItem->product_name = strval($orderItem->product_name);
                $orderItem->lot_number = strval($orderItem->lot_number);
                $orderItem->lot_frac = strval($orderItem->lot_frac);
                $orderItem->lot_amount = strval($orderItem->lot_amount);
                $orderItem->lot_type = strval($orderItem->lot_type);
                $orderItem->is_free = strval($orderItem->is_free);
            }
        }

        return $orderDetails;
    }
}
