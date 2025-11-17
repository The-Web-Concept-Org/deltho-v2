<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Riddles;
use Illuminate\Support\Facades\DB;

class RiddlesController extends Controller
{


    // List all riddles
    public function index()
    {

        $baseUrl = url('/');

        $riddles = Riddles::all();

        // Add base URL to the rid_image field in each Riddles object
        $riddles->each(function ($riddle) use ($baseUrl) {
            $riddle->rid_img = $baseUrl . $riddle->rid_img;
            //dd($riddle->rid_image);
        });

        // Check if the $riddles collection is empty
        if ($riddles->isEmpty()) {
            return response()->json([
                'success' => false,
                'msg' => 'No riddles found',
                'data' => []
            ]);
        }

        return response()->json([
            'success' => true,
            'msg' => 'Riddles Get Successfully',
            'data' => $riddles
        ]);
    }



    public function destroy(Request $request, $rid_id)
    {
        // Find the Riddle instance by its ID
        $riddle = Riddles::findOrFail($rid_id);

        // Delete the found Riddle
        $riddle->delete();

        // Optionally, you can return a response indicating success
        return response()->json([
            'success' => true,
            'msg' => 'Riddles Deleted ',
        ]);
    }

    public function winingList($id = null)
    {
        $baseUrl = url('/');
        $defaultImageUrl = asset('/assets/images/logo2.png');

        // Determine the effective admin id based on the role
        $adminId = null;
        if ($id !== null) {
            $user = DB::table('users')->where('user_id', $id)->first();

            if ($user) {
                if ($user->user_role === 'admin') {
                    $adminId = $user->user_id;
                } elseif ($user->user_role === 'manager') {
                    $adminId = $user->added_user_id; // manager's parent (admin)
                } elseif ($user->user_role === 'seller') {
                    // Get manager first
                    $manager = DB::table('users')->where('user_id', $user->added_user_id)->first();
                    if ($manager) {
                        $adminId = $manager->added_user_id; // manager's admin
                    }
                }
            }
        }

        // Build query
        $query = DB::table('winning_numbers')
            ->join('lotteries', 'lotteries.lot_id', '=', 'winning_numbers.lot_id')
            ->join('users', 'users.user_id', '=', 'winning_numbers.added_by')
            ->select('winning_numbers.*', 'lotteries.*', 'users.username')
            ->orderBy('win_id', 'DESC')
            ->limit(20);

        // Apply filter only if $adminId is not null
        if ($adminId !== null) {
            $query->where('winning_numbers.added_by', $adminId);
        }

        $winningNumbers = $query->get();

        // Get all lotteries
        $allLotteries = DB::table('lotteries')->get();

        $results = [];

        // Create a list of lottery IDs that already have winning numbers
        $lotteryIdsWithWinningNumbers = $winningNumbers->pluck('lot_id')->toArray();

        // Process each winning number
        foreach ($winningNumbers as $winning) {
            $winning->img_url = $winning->img_url ? $baseUrl . $winning->img_url : $defaultImageUrl;

            $baseName = substr($winning->lot_name, 0, strpos($winning->lot_name, ' ('));

            if (!isset($results[$baseName])) {
                $results[$baseName] = [
                    'base_name' => (string) $baseName,
                    'lotteries' => [],
                ];
            }

            $results[$baseName]['lotteries'][] = [
                'win_id' => (string) $winning->win_id,
                'add_date' => (string) $winning->add_date,
                'lot_id' => (string) $winning->lot_id,
                'number_win' => (string) $winning->number_win,
                'added_by' => (string) $winning->added_by,
                'adddatetime' => (string) $winning->adddatetime,
                'first_win_number' => (string) $winning->first_win_number,
                'second_win_number' => (string) $winning->second_win_number,
                'third_win_number' => (string) $winning->third_win_number,
                'lot_name' => (string) $winning->lot_name,
                'img_url' => (string) $winning->img_url,
                'lot_colorcode' => (string) $winning->lot_colorcode,
                'multiply_number' => (string) $winning->multiply_number,
                'winning_type' => (string) $winning->winning_type,
                'lot_opentime' => (string) $winning->lot_opentime,
                'lot_closetime' => (string) $winning->lot_closetime,
                'is_open' => (string) $winning->is_open,
                'lot_weekday' => (string) $winning->lot_weekday,
                'user_added_id' => (string) $winning->user_added_id,
                'user_edited_id' => (string) $winning->user_edited_id,
                'username' => (string) $winning->username,
            ];

            // Check for similar name lottery without winning number
            $similarLotteries = $allLotteries->filter(function ($lottery) use ($baseName, $lotteryIdsWithWinningNumbers) {
                return strpos($lottery->lot_name, $baseName) !== false
                    && !in_array($lottery->lot_id, $lotteryIdsWithWinningNumbers)
                    && strpos($lottery->lot_name, ' (') !== false;
            });

            foreach ($similarLotteries as $similarLottery) {
                $similarLottery->img_url = $similarLottery->img_url ? $baseUrl . $similarLottery->img_url : $defaultImageUrl;

                $results[$baseName]['lotteries'][] = [
                    'win_id' => null,
                    'add_date' => null,
                    'lot_id' => (string) $similarLottery->lot_id,
                    'number_win' => null,
                    'added_by' => null,
                    'adddatetime' => null,
                    'first_win_number' => null,
                    'second_win_number' => null,
                    'third_win_number' => null,
                    'lot_name' => (string) $similarLottery->lot_name,
                    'img_url' => (string) $similarLottery->img_url,
                    'lot_colorcode' => (string) $similarLottery->lot_colorcode,
                    'multiply_number' => (string) $similarLottery->multiply_number,
                    'winning_type' => (string) $similarLottery->winning_type,
                    'lot_opentime' => (string) $similarLottery->lot_opentime,
                    'lot_closetime' => (string) $similarLottery->lot_closetime,
                    'is_open' => (string) $similarLottery->is_open,
                    'lot_weekday' => (string) $similarLottery->lot_weekday,
                    'user_added_id' => null,
                    'user_edited_id' => null,
                    'username' => null,
                ];

                // Add this lottery ID to the list to avoid duplicates
                $lotteryIdsWithWinningNumbers[] = $similarLottery->lot_id;
            }
        }

        // Convert results to array
        $resultsArray = array_values($results);

        // If results are empty, return response with success false
        if (empty($resultsArray)) {
            return response()->json([
                'success' => false,
                'msg' => 'No winning numbers found.',
                'data' => [],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'msg' => 'Winning Number list',
            'data' => $resultsArray,
        ], 200);
    }









    public function store(Request $request,  $rid_id = null)
    {
        $user = auth()->user();

        $validatedData = $request->validate([
            'rid_title' => 'required',
            'rid_img' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // Add validation rules for image files
        ]);

        // // Store the uploaded file in the storage/app/public directory
        // $imagePath = $request->file('rid_img')->store('riddle_images');

        // // Get the file name from the stored path
        // $imageName = basename($imagePath);


        $imgName = uniqid() . '.' . $request->file('rid_img')->getClientOriginalExtension();
        $request->file('rid_img')->storeAs('public/riddle_images', $imgName);
        $imgUrlForApi = Storage::url('riddle_images/' . $imgName);

        if ($rid_id !== null) {
            // Editing an existing riddle
            $riddleData = [
                'rid_title' => $validatedData['rid_title'],
                'rid_img' => $imgUrlForApi,
                'user_id' => $user->user_id,
                // Add other fields as needed
            ];

            DB::table('riddles')->where('rid_id', $rid_id)->update($riddleData);

            $riddle = DB::table('riddles')->where('rid_id', $rid_id)->first();
        } else {
            // Adding a new riddle
            $riddleData = [
                'rid_title' => $validatedData['rid_title'],
                'rid_img' => $imgUrlForApi,
                'user_id' => $user->user_id,
                // Add other fields as needed
            ];

            $rid_id = DB::table('riddles')->insertGetId($riddleData);

            $riddle = DB::table('riddles')->where('rid_id', $rid_id)->first();
        }

        // You can now use $riddle as needed


        return response()->json([
            'success' => true,
            'msg' => 'Riddles Added Successfully',
            'data' => $riddle,
        ], 200);
    }
}
