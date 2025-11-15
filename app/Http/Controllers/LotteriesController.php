<?php

namespace App\Http\Controllers;


use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\User;

use App\Models\Lottery; // Adjust the namespace and path based on your actual model location

class LotteriesController extends Controller
{
    public function addLottery(Request $request, $lotteryId = null)
    {

        $uniVeriable = $lotteryId;

        //dd($request);
        if ($request->filled(['lot_name', 'mul_num' , 'is_open'])) {
            $user = auth()->user();


            // $weekdays = $request->input('weekdays');
            // //dd($request->input('weekdays'));
            // $decodedData = $weekdays;
             $weekdays = $request->input('weekdays'); // ["Cada dia", "Lunes"]

    // Check if $weekdays is an array
    if (is_array($weekdays)) {
    
        // Convert the array to a comma-separated string
        $weekdaysString = implode(',', $weekdays); // "Cada dia, Lunes"
    }else{
        $weekdaysString = str_replace(["[","]","'"], "", $weekdays); 
// Result: "Cada dia, Lunes, Martes"

        //$weekdaysString = json_decode(json_encode($request->input('weekdays'))); // ["Cada dia", "Lunes"]
        
    }
            $lotData = [
                'lot_name'      => $request->input('lot_name'),
                'multiply_number' => $request->input('mul_num'),
                'winning_type'  => $request->input('winning_type'),
                'user_added_id' =>  $user->user_id,
                'lot_opentime'  => $request->input('fromtime'),
                'lot_closetime' => $request->input('totime'),
                'lot_colorcode' => $request->input('colorcode'),
                'lot_weekday'   => $weekdaysString,
                'is_open'   => $request->input('is_open'),
            ];

            // Handle image upload
            if ($request->hasFile('image')) {


                $imgName = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
                $request->file('image')->storeAs('public/images', $imgName);
                $imgUrlForApi = Storage::url('images/' . $imgName);

                $lotData['img_url'] = $imgUrlForApi;
            }

            // Check if editing an existing lottery or adding a new one
            if ($lotteryId !== null) {
                // Editing an existing lottery

                $lotData['user_edited_id'] = $user->user_id;
                //dd($lotData);
                DB::table('lotteries')->where('lot_id', $lotteryId)->update($lotData);

                 $lotteryDetails = DB::table('lotteries')->where('lot_id', $lotteryId)->first();
            } else {
                // Adding a new lottery
                DB::table('lotteries')->insert($lotData);
                $lotteryId = DB::getPdo()->lastInsertId();
                $lotteryDetails = DB::table('lotteries')->where('lot_id', $lotteryId)->first();
            }

            $response = [
                'data' => [
                    'lottery_details' => $lotteryDetails,
                ],
                'success' => true,
                'msg'       => (empty($uniVeriable)) ? 'Lottery Added Successfully' : 'Lottery Updated Successfully',
            ];
        } else {

            $response = [
                'success' => false,
                'msg'       => 'Invalid request parameters',
            ];
        }

        return response()->json($response);
    }


    public function deleteLottery(Lottery $lotteryId)
{

   // dd($lottery);

    try {
        DB::table('lotteries')->where('lot_id', $lotteryId)->delete();

        $response = [
            'success' => true,
            'msg'       => 'Lottery deleted successfully',
        ];
    } catch (\Exception $e) {
        $response = [
            'success' => false,
            'msg'       => 'Error deleting lottery: ' . $e->getMessage(),
        ];
    }

    return response()->json($response);
}



//lotteries list all
public function getLotteriesListAll($lotteryId = null)
{
    $baseUrl = url('/');
    $userRole = auth()->user()->user_role;
    $userId = auth()->user()->user_id;
    $adminIdThis = $this->getAdminId($userId);
    
    if($userRole == 'customer'){
        $query = DB::table('lotteries')
        ->select(
            'lot_id',
            'lot_name AS name',
            'is_open',
            'multiply_number',
            DB::raw('IFNULL(img_url, "/assets/images/logo2.png") AS img_url'),
            'winning_type',
            'lot_opentime',
            'lot_closetime',
            'lot_colorcode'
        )
        ->where('user_added_id', 1)
        ->get();
    }else{
        $query = DB::table('lotteries')
        ->select(
            'lot_id',
            'lot_name AS name',
            'is_open',
            'multiply_number',
            DB::raw('IFNULL(img_url, "/assets/images/logo2.png") AS img_url'),
            'winning_type',
            'lot_opentime',
            'lot_closetime',
            'lot_colorcode'
        )
        ->when($userRole != 'superadmin', function ($query) use ($adminIdThis) {
            return $query->where('user_added_id', $adminIdThis);
        })
        ->get();
    }

    $lotteries = $query->map(function ($lottery) use ($baseUrl) {
        $days = DB::table('lotteries')->where('lot_id', $lottery->lot_id)->value('lot_weekday');
        $lottery->lot_weekday = $days ? explode(',', $days) : ['Cada dia'];

        // Concatenate base URL with img_url
        $lottery->img_url = $baseUrl . $lottery->img_url;

        // Convert each attribute to a string
        return [
            'lot_id' => (string) $lottery->lot_id,
            'name' => (string) $lottery->name,
            'is_open' => (string) $lottery->is_open,
            'multiply_number' => (string) $lottery->multiply_number,
            'img_url' => (string) $lottery->img_url,
            'winning_type' => (string) $lottery->winning_type,
            'lot_opentime' => (string) $lottery->lot_opentime,
            'lot_closetime' => (string) $lottery->lot_closetime,
            'lot_colorcode' => (string) $lottery->lot_colorcode,
            'lot_weekday' => array_map('strval', $lottery->lot_weekday),
        ];
    });

    if ($lotteryId) {
        $lotId = request('lot_id');
        $lot = DB::table('lotteries')->where('lot_id', $lotId)->first();
        return response()->json($lot);
    }

    return response()->json([
        'success' => true,
        'msg' => 'Lottery List',
        'data' => $lotteries,
    ]);
}




public function getLotteriesListAllWithTime()
{
    $user = auth()->user();
    $userRole = auth()->user()->user_role;
    $userId = auth()->user()->user_id;

    if (!$user) {
        return response()->json([
            'success' => false,
            'msg' => 'Invalid user_id',
            'timenow' => now()->format('H:i:s')
        ]);
    }

    if ($user->user_role === 'admin') {
        $thisAdminId = $userId;
    } elseif ($user->user_role === 'manager') {
        $manager = User::find($user->added_user_id);
        $admin = $manager && $manager->user_role === 'manager' ? User::find($manager->added_user_id) : null;
        $thisAdminId = $admin ? $admin->user_id : null;
    } elseif ($user->user_role === 'seller') {
        $addedByUser = User::find($user->added_user_id);
        if ($addedByUser && $addedByUser->user_role === 'admin') {
            $thisAdminId = $addedByUser->user_id;
        } elseif ($addedByUser && $addedByUser->user_role === 'manager') {
            $admin = User::find($addedByUser->added_user_id);
            $thisAdminId = $admin ? $admin->user_id : null;
        } else {
            $thisAdminId = null;
        }
    }elseif($user->user_role === 'customer'){
          $thisAdminId = '1';
        
    } else {
        $thisAdminId = null;
    }

    if (!$thisAdminId) {
        return response()->json([
            'success' => false,
            'msg' => 'Invalid user role or hierarchy',
            'timenow' => now()->format('H:i:s')
        ]);
    }

    date_default_timezone_set("America/Port-au-Prince");
    $serverTimeWithHaiti = now()->format('H:i:s');
    $daysArr = [
        'everyday' => 'Cada dia',
        'Monday' => 'Lunes',
        'Tuesday' => 'Martes',
        'Wednesday' => 'Miercoles',
        'Thursday' => 'Juevez',
        'Friday' => 'Viernes',
        'Saturday' => 'Sabado',
        'Sunday' => 'Domingo',
    ];
    
    $today = now()->locale('es')->dayName; // e.g. "Lunes", "Martes"
    $today = ucfirst(strtolower($today));  // ensure same format as DB
    
    $query = DB::table('lotteries')
        ->select(
            'lot_id',
            'lot_name AS name',
            'is_open',
            'multiply_number',
            'img_url',
            'winning_type',
            'lot_opentime',
            'lot_closetime',
            'user_added_id',
            'lot_colorcode',
            DB::raw("
                CASE
                    WHEN lot_colorcode = '' THEN 'Color(0xff1cff19)'
                    WHEN lot_colorcode IS NULL THEN 'Color(0xffEAF8A3)'
                    ELSE lot_colorcode
                END AS colorcode
            ")
        )
        ->where('winning_type', 1)
        ->where('lot_opentime', '<', $serverTimeWithHaiti)
        ->where('lot_closetime', '>', $serverTimeWithHaiti)
        ->where('user_added_id', $thisAdminId)
        ->where(function($q) use ($today) {
                $q->where('lot_weekday', 'like', '%Cada dia%') // allow every day
                  ->orWhere('lot_weekday', 'like', "%$today%"); // allow today's day
            })
        ->get();

    if ($query->isNotEmpty()) {
        $lotteries = $query->map(function ($lottery) use ($daysArr) {
            $days = DB::table('lotteries')->where('lot_id', $lottery->lot_id)->value('lot_weekday');
            $baseUrl = url('/');
            $lottery->lot_weekday = $days ? array_map('strval', explode(',', $days)) : ['Cada dia'];
            $lottery->img_url = (string) ($baseUrl . $lottery->img_url);

            $user = auth()->user();
            $limits = DB::table('limit_game')
    ->where('lottery_id', $lottery->lot_id)
    ->where('limit_type', 0)
    ->where(function ($query) use ($user) {
        $query->where('user_id', $user->user_id)
            ->orWhere('user_id', $user->added_user_id)
            ->orWhere(function ($subQuery) use ($user) {
                $subQuery->where(function ($q) {
                    $q->whereNull('limit_ball') // Fetch when limit_ball is NULL
                      ->orWhereRaw('limit_ball IS NOT NULL'); // Fetch when limit_ball is NOT NULL
                })->whereJsonContains('user_id', (string) $user->added_user_id);
            });
    })
    ->get(['lot_type', 'limit_ball', 'limit_frac']);


            $groupedLimits = [
                'BOR' => [],
                'MAR' => [],
                'LOT3' => [],
                'LOT4.1' => [],
                'LOT4.2' => [],
                'LOT4.3' => [],
                'LOT5.1' => [],
                'LOT5.2' => [],
            ];

            foreach ($limits as $limit) {
                $groupedLimits[$limit->lot_type][] = [
                    'limit_ball' => (string) $limit->limit_ball,
                    'limit_frac' => (string) $limit->limit_frac,
                ];
            }

            return [
                'lot_id' => (string) $lottery->lot_id,
                'name' => (string) $lottery->name,
                'is_open' => (string) $lottery->is_open,
                'multiply_number' => (string) $lottery->multiply_number,
                'img_url' => (string) $lottery->img_url,
                'winning_type' => (string) $lottery->winning_type,
                'lot_opentime' => (string) $lottery->lot_opentime,
                'lot_closetime' => (string) $lottery->lot_closetime,
                'user_added_id' => (string) $lottery->user_added_id,
                'lot_colorcode' => (string) $lottery->lot_colorcode,
                'colorcode' => (string) $lottery->colorcode,
                'lot_weekday' => $lottery->lot_weekday,
                'limits' => $groupedLimits,
            ];
        });

        return response()->json([
            'timenow' => (string) now()->format('H:i:s'),
            'success' => true,
            'msg' => 'Lotteries get',
            'data' => $lotteries,
        ]);
    } else {
        return response()->json([
            'timenow' => (string) now()->format('H:i:s'),
            'success' => true,
            'msg' => 'Lotteries not opened yet',
        ]);
    }
}



public function getAdminId($userId)
{
    $user = User::find($userId);

    if (!$user) {
        return null; // User not found
    }

    if ($user->user_role === 'admin') {
        return $user->user_id; // Return the user ID if the user is an admin
    } elseif ($user->user_role === 'manager' || $user->user_role === 'seller') {
        $addedByUser = User::find($user->added_user_id);

        if ($addedByUser) {
            if ($addedByUser->user_role === 'admin') {
                return $addedByUser->user_id; // Return the admin ID if the added user is an admin
            } elseif ($addedByUser->user_role === 'manager') {
                $admin = User::find($addedByUser->added_user_id);
                if ($admin && $admin->user_role === 'admin') {
                    return $admin->user_id; // Return the admin ID if the added user is a manager and their superior is an admin
                }
            }
        }
    }

    return null; // Return null if the admin ID is not found
}



public function addLotteryLimit(Request $request)
{
    try {
        $user = auth()->user();
        
        $validate = $request->validate([
            'lottery_id' => 'required',
            'limit_amount' => 'required',
            'user_id' => 'required|array',
            'lot_type' => 'required',
            // 'limit_number'=>'nullable'
        ]);

        $lot_ids = explode(',', $validate['lottery_id']);
        $responses = []; // Store messages
        $limit_number = $request->input('limit_number');

        foreach ($lot_ids as $lottery_id) {
            // Check if record already exists where limit_ball is NULL
            $existingRecordQuery = DB::table('limit_game')
            ->where('lottery_id', $lottery_id)
            ->where('lot_type', $validate['lot_type'])
            ->where('limit_type', 1);

            if (!empty($limit_number)) {
                $existingRecordQuery->where('limit_ball', $limit_number);
            } else {
                $existingRecordQuery->whereNull('limit_ball'); // Only apply this condition if limit_number is empty
            }
            
            $existingRecord = $existingRecordQuery->first();
            
            if ($existingRecord) {
                // Decode existing user_id and merge new values
                $existingUserIds = json_decode($existingRecord->user_id, true) ?? [];
                $newUserIds = array_values(array_unique(array_merge($existingUserIds, $validate['user_id'])));

                // Update existing record
                DB::table('limit_game')
                    ->where('limit_id', $existingRecord->limit_id)
                    ->update([
                        'user_id' => json_encode($newUserIds),
                        'limit_frac' => $validate['limit_amount'],
                        'limit_ball' => isset($limit_number) ? $limit_number : Null,
                        'added_user_id' => $user->user_id,
                    ]);

                $responses = "Limit Updated for Lottery ID: $lottery_id";
            } else {
                // Insert new record if not exists
                DB::table('limit_game')->insert([
                    'lottery_id' => $lottery_id,
                    'limit_frac' => $validate['limit_amount'],
                    'limit_ball' => isset($limit_number) ? $limit_number : Null,
                    'user_id' => json_encode($validate['user_id']),
                    'added_user_id' => $user->user_id,
                    'limit_type' => 1,
                    'lot_type' => $validate['lot_type'],
                ]);

                $responses = "Limit Added for Lottery ID: $lottery_id";
            }
        }

        return response()->json([
            'success' => true,
            'msg' => $responses, // Send all messages
        ]);

    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'msg' => $e->getMessage(),
        ]);
    }
}


public function getCustomerLimits(Request $request)
{
    try{
        
        $user = auth()->user();
        
        $lotteryIds = DB::table('lotteries')
            ->where('user_added_id', $user->user_id)
            ->pluck('lot_id')
            ->toArray();
            
            $query = DB::table('limit_game')
            ->leftJoin('lotteries', 'limit_game.lottery_id', '=', 'lotteries.lot_id')
            ->select(
                'limit_game.limit_id',
                'limit_game.user_id',
                'limit_game.lottery_id',
                'limit_game.limit_frac',
                'limit_game.status',
                'limit_game.lot_type',
                DB::raw("COALESCE(limit_game.limit_ball, '') as limit_ball"), // Convert NULL to empty string
                'lotteries.lot_name as lottery_name'
            )
            ->whereIn('lottery_id', $lotteryIds)
            ->where('user_id', 'customer')
            ->get();
            
            if(!$query)
            {
                return response()->json(['success' => false, 'message' => 'No data found']);
            }
            
            return response()->json(['success' => true, 'data' => $query], 200);
        
    }catch(\Exception $e){
        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
}



public function getLotteryLimits(Request $request)
{
    try {
        $user = auth()->user();

        $lotteryIds = DB::table('lotteries')
            ->where('user_added_id', $user->user_id)
            ->pluck('lot_id')
            ->toArray();

        $formvariable = $request->input('formvariable');

        // Start building the query
        $query = DB::table('limit_game')
            ->leftJoin('lotteries', 'limit_game.lottery_id', '=', 'lotteries.lot_id')
            ->select(
                'limit_game.limit_id',
                'limit_game.user_id',
                'limit_game.lottery_id',
                'limit_game.limit_frac',
                'limit_game.status',
                'limit_game.lot_type',
                DB::raw("COALESCE(limit_game.limit_ball, '') as limit_ball"), // Convert NULL to empty string
                'lotteries.lot_name as lottery_name'
            )
            ->whereIn('limit_game.lottery_id', $lotteryIds)
            ->where('limit_game.limit_type', 1);

        // Apply condition based on formvariable
        if (!empty($formvariable)) {
            $query->whereNull('limit_ball');
        } else {
            $query->whereNotNull('limit_ball');
        }

        $lotteryLimits = $query->get();

        // Decode the user_id column and fetch usernames
        $lotteryLimits = $lotteryLimits->map(function ($item) {
    $userIdsRaw = json_decode($item->user_id, true);

    // If it's not an array (e.g., a string), make it an array
    $userIds = is_array($userIdsRaw) ? $userIdsRaw : [$userIdsRaw];

    // Separate customer and numeric user IDs
    $actualUserIds = array_filter($userIds, fn($id) => is_numeric($id));
    $hasCustomer = in_array('customer', $userIds);

    // Fetch only numeric user IDs from DB
    $users = DB::table('users')
        ->whereIn('user_id', $actualUserIds)
        ->where('is_deleted', '<>', '1')
        ->pluck('username', 'user_id');

    // Build users array with usernames
    $item->users = collect($userIds)->map(function ($id) use ($users) {
        if ($id === 'customer') {
            return [
                'user_id' => 0,
                'username' => 'customer',
            ];
        }

        return [
            'user_id' => (int) $id,
            'username' => $users[$id] ?? null,
        ];
    });

    return $item;
});


        // Check if data exists
        if ($lotteryLimits->isEmpty()) {
            return response()->json([
                'success' => false,
                'msg' => 'No data found',
                'data' => [],
            ]);
        }

        return response()->json([
            'success' => true,
            'msg' => 'Lottery Limits fetched successfully',
            'data' => $lotteryLimits,
        ]);

    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'msg' => $e->getMessage(),
            'data' => [],
        ]);
    }
}





public function mostPlayedNumber(Request $request)
{
    try {
        $user = auth()->user();
        $baseUrl = url('/');
        $defaultImageUrl = asset('/assets/images/logo2.png');

        // Validate request
        $request->validate([
            'from_date' => 'required',
            'to_date' => 'required',
        ]);

        $lotteryId = $request->input('lottery_id');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // Convert date format
        $fromDateFormatted = Carbon::createFromFormat('d M, Y', $fromDate)->format('Y-m-d');
        $toDateFormatted = Carbon::createFromFormat('d M, Y', $toDate)->format('Y-m-d');

        // Determine lottery IDs
        if (!empty($lotteryId)) {
            $lotteryIds = strpos($lotteryId, ',') !== false ? explode(',', $lotteryId) : [$lotteryId];
        } else {
            $lotteryIds = DB::table('lotteries')
                ->where('user_added_id', $user->user_id)
                ->pluck('lot_id')
                ->toArray();
        }

        $whereBetweenDates = ["$fromDateFormatted 00:00:00", "$toDateFormatted 23:59:59"];

        // ---------------- Most Played Numbers ----------------
        $subQuery = DB::table('order_item as oi')
            ->selectRaw("
                oi.product_id,
                oi.product_name,
                oi.lot_number,
                COUNT(*) AS lot_count,
                SUM(oi.lot_amount) AS total_lot_amount,
                ROW_NUMBER() OVER (PARTITION BY oi.product_id ORDER BY COUNT(*) DESC, oi.lot_number ASC) AS rank
            ")
            ->whereBetween('oi.adddatetime', $whereBetweenDates)
            ->groupBy('oi.product_id', 'oi.product_name', 'oi.lot_number');

        $mostPlayedNumbers = DB::table(DB::raw("({$subQuery->toSql()}) as ranked_lots"))
            ->select(
                'ranked_lots.product_id',
                'ranked_lots.product_name',
                'ranked_lots.lot_number',
                'ranked_lots.lot_count',
                'ranked_lots.total_lot_amount',
                DB::raw("IFNULL(CONCAT('$baseUrl', lotteries.img_url), '$defaultImageUrl') AS img_url")
            )
            ->mergeBindings($subQuery)
            ->leftJoin('lotteries', 'lotteries.lot_id', '=', 'ranked_lots.product_id')
            ->whereIn('ranked_lots.product_id', $lotteryIds)
            ->where('ranked_lots.rank', 1)
            ->limit(5)
            ->get();

        // ---------------- Updated: Top 5 Tickets Per Lottery with Image ----------------
        $orders = DB::table('order_item as oi')
            ->leftJoin('orders as o', 'o.order_id', '=', 'oi.order_id')
            ->select(
                'oi.order_id',
                'oi.product_id',
                'oi.product_name',
                DB::raw("IFNULL(CONCAT('$baseUrl', l.img_url), '$defaultImageUrl') AS img_url"),
                'o.grand_total as grand_total'
            )
            ->leftJoin('lotteries as l', 'l.lot_id', '=', 'oi.product_id')
            ->whereBetween('oi.adddatetime', $whereBetweenDates)
            ->whereIn('oi.product_id', $lotteryIds)
            ->groupBy('oi.order_id', 'oi.product_id', 'oi.product_name', 'l.img_url', 'grand_total')
            ->orderByDesc('grand_total')
            ->get();

$mostplayed_highest_amounts = [];
$groupedByProduct = $orders->groupBy('product_id');

foreach ($groupedByProduct as $productId => $ordersByProduct) {
    $productName = $ordersByProduct[0]->product_name;
    $imgUrl = $ordersByProduct[0]->img_url;
    
    $topOrders = $ordersByProduct
        ->sortByDesc('grand_total')
        ->take(5)
        ->map(function ($order) {
            return [
                'order_id' => $order->order_id,
                'lot_number' => "123",
                'lot_amount' => $order->grand_total,
                'winning_amount' => "0"
            ];
        })->values();
    
    $mostplayed_highest_amounts[] = [
        'product_id' => $productId,
        'product_name' => $productName,
        'img_url' => $imgUrl,
        'tickets' => $topOrders,
    ];
}

        // ---------------- Top 5 Winning Orders ----------------
        $topWinningOrders = DB::table('order_item as oi')
            ->select(
                'oi.order_id',
                DB::raw('SUM(oi.winning_amount) as topwinnings'),
                'u.username as seller_name'
            )
            ->join('orders as o', 'oi.order_id', '=', 'o.order_id')
            ->join('users as u', 'o.user_id', '=', 'u.user_id')
            ->where('oi.winning_amount', '>', 0)
            ->whereBetween('oi.adddatetime', $whereBetweenDates)
            ->groupBy('oi.order_id', 'u.username')
            ->orderByDesc('topwinnings')
            ->limit(5)
            ->get();



        return response()->json([
            'success' => true,
            'mostplayed_numbers' => $mostPlayedNumbers,
            'mostplayed_highest_amounts' => $mostplayed_highest_amounts,
            'top_winning_orders' => $topWinningOrders,
        ]);

    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'msg' => $e->getMessage(),
            'mostplayed_numbers' => [],
            'mostplayed_highest_amounts' => [],
            'top_winning_orders' => []
        ], 500);
    }
}







        // public function deleteLotteryLimit(Request $request)
        //     {
        //         try {
        //             // Get the authenticated user
        //             $user = auth()->user();
        //             $limit_id = $request->input('limit_id');
        //             // Find the limit entry by limit_id and check if it belongs to the authenticated user
        //             $limit = DB::table('limit_game')
        //                 ->where('limit_id', $limit_id)
        //                 ->first();
        
        //             // If no such limit entry exists or it doesn't belong to the user
        //             if (!$limit) {
        //                 return response()->json(['success' => false, 'msg' => 'Limit not found or unauthorized action.'], 404);
        //             }
        
        //             // Proceed to delete the limit entry
        //             DB::table('limit_game')->where('limit_id', $limit_id)->delete();
        
        //             return response()->json(['success' => true, 'msg' => 'Limit deleted successfully.']);
        
        //         } catch (\Exception $e) {
        //             return response()->json(['success' => false, 'msg' => $e->getMessage()], 500);
        //         }
        //     }
}