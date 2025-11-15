<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\RequestUser;
use App\Mail\RequestConfirmation;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;


class UserController extends Controller
{
    
    public function editProfile(Request $request)
{
    try {
        $validatedData = $request->validate([
            'user_id' => 'required',
            'username' => 'nullable|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'user_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $user = User::where('user_id', $validatedData['user_id'])->first();

        // Update username and phone
        if (isset($validatedData['username'])) {
            $user->username = $validatedData['username'];
        }

        if (isset($validatedData['phone'])) {
            $user->phone = $validatedData['phone'];
        }
        
        if (isset($validatedData['address'])) {
            $user->address = $validatedData['address'];
        }

        // Handle user_image upload
        if ($request->hasFile('user_image')) {
            // Delete old image if exists
            if ($user->user_image) {
                $oldImagePath = str_replace('/storage/', '', $user->user_image);
                if (Storage::disk('public')->exists($oldImagePath)) {
                    Storage::disk('public')->delete($oldImagePath);
                }
            }

            // Store new image
            $image = $request->file('user_image');
            $imagePath = $image->store('user_images', 'public');
            $imageUrl = '/storage/' . $imagePath;

            $user->user_image = $imageUrl;
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

    
    //
    public function deleteUser(Request $request)
    {
        try{
            $validatedData = $request->validate([
                'user_id' => 'required',
            ]);
            
            $user = User::where('user_id', $validatedData['user_id'])->first();
            
            if($user->user_role == 'manager'){
                $sellers = User::where('added_user_id', $user->user_id)->get();
                
                foreach ($sellers as $seller) {
                $seller->is_deleted = 1;
                $seller->save();
                }
                
            }
            
            $user->is_deleted = 1;
            $user->save();
            
            return response()->json(['success' => true, 'message' => 'User deleted successfully']);
        }catch(\Exception $e){
            return response()->json(['success' => false, 'message' =>$e->getMessage()]);
        }
    }
    
    public function getNotifications(){
        try{
            
            $user = Auth()->user();
            
            $notifications = DB::table('notifications')->where('seller_id', $user->user_id)->orderBy('add_datetime', 'DESC')->get();
            
            if ($notifications->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No notifications found', 'data' => []], 404);
            }
            
            return response()->json(['success' => true, 'data' => $notifications], 200);
            
        }catch(\Exception $e){
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    
    public function readNotification(Request $request){
        try{
            
            $id = $request->input('notification_id');
            
        $notification = DB::table('notifications')->where('notification_id', $id)->first();
        
        // Check if the notification exists
        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }

        // Update the notification status to 'read'
        DB::table('notifications')->where('notification_id', $id)->update(['notification_status' => 'read']);
        
        return response()->json(['success' => true, 'message' => 'Notification marked as read'], 200);
        
        }catch(\Exception $e){
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function verifyUser(Request $request)
{
    try{
        // Get the authenticated user
    $user = Auth()->user();

    // Store the images in the storage folder and get their paths
    $cnicFrontPath = $request->file('cnic_front')->store('public/cnic_images');
    $cnicBackPath = $request->file('cnic_back')->store('public/cnic_images');
    $verifiedImagePath = $request->file('verified_image')->store('public/cnic_images');

   // Update the user record with the paths of the stored images
        DB::table('users')->where('user_id', $user->user_id)->update([
            'cnic_front' => Storage::url($cnicFrontPath),
            'cnic_back' => Storage::url($cnicBackPath),
            'verified_image' => Storage::url($verifiedImagePath),
        ]);

    return response()->json(['success' => true, 'message' => 'User verified successfully'], 200);
    }catch(\Exception $e){
        return response()->json(['success' => false, 'message' => $e->getMessage], 400);
    }
}

    public function addusers(Request $request ,  $user_id = null)
    {
        //dd($request);
        if ($request->filled(['email', 'password','user_role'])) {

            $user = auth()->user();

            try {
                $userData = [
                    'username'      => $request->input('username'),
                    'email'         => $request->input('email'),
                    'password'      => md5($request->input('password')),
                    'phone'         => $request->input('phone'),
                    'user_role'     => strtolower($request->input('user_role')),
                    'commission'    => $request->input('commission'),
                    'added_user_id' => $request->input('assign_id') != null ? $request->input('assign_id') : $user->user_id,
                    'address'       => $request->input('address'),
                ];

                if ($request->filled('req_user_id')) {
                    // Assuming 'request_user' is your table for storing requests
                    // Remove the request entry if req_user_id is provided
                    // Replace 'request_user' with your actual table name
                    DB::table('request_user')->where('req_user_id', $request->input('req_user_id'))->delete();
                }
                if(!empty($user_id)){
                    DB::table('users')->where('user_id', $user_id)->update($userData);
                }else{
                // Insert user data into the 'users' table
                DB::table('users')->insert($userData);
                }
                $response = [
                    'success' => true,
                    'msg'       => ($user_id !== null) ? 'User Updated Successfully' : 'User Added Successfully',

                ];
            } catch (\Exception $e) {
                $response = [
                    'success' => false,
                    'msg'       => "User Already Exist",
                ];
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'msg'       => 'Invalid request parameters',
            ]);
        }
    }


public function requestUser(Request $request)
{
    if ($request->filled(['username', 'useremail', 'password'])) {
        try {
            $userRole = strtolower($request->input('user_role'));

            $requestData = [
                'username'  => $request->input('username'),
                'email'     => $request->input('useremail'),
                'password'  => $request->input('password'), // Plaintext for now; will be hashed if creating user
                'phone'     => $request->input('phone'),
                'user_role' => $userRole,
                'address'   => $request->input('address'),
            ];

            // Handle image uploads
            if ($request->hasFile('cnic_front')) {
                $cnicFrontPath = $request->file('cnic_front')->store('public/cnic_images');
                $requestData['cnic_front'] = Storage::url($cnicFrontPath);
            }

            if ($request->hasFile('cnic_back')) {
                $cnicBackPath = $request->file('cnic_back')->store('public/cnic_images');
                $requestData['cnic_back'] = Storage::url($cnicBackPath);
            }

            if ($request->hasFile('verified_image')) {
                $verifiedImagePath = $request->file('verified_image')->store('public/cnic_images');
                $requestData['verified_image'] = Storage::url($verifiedImagePath);
            }

            if ($userRole === 'customer') {
                
                // Check if email already exists
                $emailExists = DB::table('users')->where('email', $requestData['email'])->exists();
            
                if ($emailExists) {
                    return response()->json([
                        'success' => false,
                        'msg'     => 'Email already exists. Please use a different email address.',
                    ]);
                }
                
                // Insert into users table
                DB::table('users')->insert([
                    'username'     => $requestData['username'],
                    'email'    => $requestData['email'],
                    'password' => md5($requestData['password']),
                    'phone'    => $requestData['phone'],
                    'user_role'     => $userRole,
                    'address'  => $requestData['address'],
                    'added_user_id' => 0,
                    'cnic_front'     => $requestData['cnic_front'] ?? null,
                    'cnic_back'      => $requestData['cnic_back'] ?? null,
                    'verified_image' => $requestData['verified_image'] ?? null,
                ]);

                $response = [
                    'success' => true,
                    'msg'     => 'Customer registered successfully.',
                ];
            } else {
                // Insert into request_user table
                RequestUser::create($requestData);

                $response = [
                    'success' => true,
                    'msg'     => 'Information added, we will update you soon.',
                ];
            }
        } catch (\Exception $e) {
            $response = [
                'success' => false,
                'msg'     => $e->getMessage(),
            ];
        }
    } else {
        $response = [
            'success' => false,
            'msg'     => 'Invalid request parameters',
        ];
    }

    return response()->json($response);
}


            public function approveUser(Request $request)
        {
            try {
                $user = Auth()->user();
                $requestedUserId = $request->input('req_user_id');
        
                // Fetch the user data from the request_user table
                $requestedUser = DB::table('request_user')->where('req_user_id', $requestedUserId)->first();
        
                if (!$requestedUser) {
                    return response()->json(['success' => false, 'message' => 'Requested user not found'], 404);
                }
        
                // Prepare data for insertion into the users table
                $userData = [
                    'username'          => $requestedUser->username,
                    'email'             => $requestedUser->email,
                    'password'          => md5($requestedUser->password),
                    'address'           => $requestedUser->address,
                    'phone'             => $requestedUser->phone,
                    'user_role'         => $requestedUser->user_role,
                    'commission'        => 0, // Assuming initial commission is 0
                    'added_user_id'     => $user->user_id, // Assuming the current authenticated user is adding the user
                    'status'            => $requestedUser->status, // Assuming the status is active on approval
                    'cnic_front'        => $requestedUser->cnic_front,
                    'cnic_back'         => $requestedUser->cnic_back,
                    'verified_image'    => $requestedUser->verified_image,
                ];
        
                // Insert the user data into the users table
                DB::table('users')->insert($userData);
        
                // Delete the user from the request_user table
                DB::table('request_user')->where('req_user_id', $requestedUserId)->delete();
        
                return response()->json(['success' => true, 'message' => 'User approved and added to users table successfully']);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
            }
        }

    public function requestUserList(Request $request)
{
$baseUrl = '';
    $requestedUsers = RequestUser::orderBy('created_at', 'desc')->get()->map(function ($user) use ($baseUrl) {
        $user->cnic_front = asset($user->cnic_front);
        $user->cnic_back = asset($user->cnic_back);
        $user->verified_image = asset($user->verified_image);
        return $user;
    });

    return response()->json([
        'success' => true,
        'msg' => 'All requested List',
        'data' => $requestedUsers
    ]);
}



     function sendConfirmationEmail(Request $request)
{
    // Assume $userData contains the necessary user information


    $userData = [
        'username' => $request->input('username'),
        'email' => $request->input('useremail'),
        'phone' =>  $request->input('phone'),
        'user_role' =>  $request->input('userrole'),
        'address' => $request->input('address'),
    ];

    // Send the email
    Mail::to($userData['email'])->send(new RequestConfirmation($userData));

    // Optionally, you can check if the email was sent successfully
    if (count(Mail::failures()) > 0) {
        return response()->json([
            'is_status' => 0,
            'msg' => 'Failed to send confirmation email',
        ]);
    }

    return response()->json([
        'is_status' => 1,
        'msg' => 'Confirmation email sent successfully',
    ]);
}

// user list based on user role

public function userList(Request $request, $all = null)
{
    $userId = auth()->user()->user_id;
    $loggedInUser = User::find($userId);

    if ($loggedInUser) {
        if ($loggedInUser->user_role === 'superadmin') {
            $users = User::select('user_id', 'username', 'commission', 'email', 'phone', 'address', 'user_role', 'added_user_id','status')
                // ->where('status', 1)
                ->where('user_id', '!=', $userId)
                ->where('is_deleted', '<>', '1')
                ->orderBy('user_role', 'ASC')
                ->get();
            //dd($users);
            $userTree = $this->buildUserTree($users->toArray(), null);

            $jsonResponse = [
                'success' => true,
                'msg' => 'Get Successfully',
                'data' => $userTree
            ];
        } elseif ($loggedInUser->user_role === 'manager') {
            $admins = User::select('user_id', 'username', 'commission', 'email', 'phone', 'address', 'user_role', 'commission', 'status')
                ->where(function($query) use ($userId) {
                    $query->where('added_user_id', $userId);
                })
                ->where('is_deleted', '<>', '1')
                ->orderBy('user_role', 'ASC')
                ->get();

            $adminsArray = $admins->toArray();

            $jsonResponse = [
                'success' => true,
                'msg' => 'Get Successfully',
                'data' => $adminsArray
            ];
        } elseif ($loggedInUser->user_role === 'admin') {
            // Get admin details
    $adminDetails = User::select('user_id', 'username', 'commission', 'email', 'phone', 'address', 'user_role', 'status')
        ->where('user_id', $userId)
        ->where('is_deleted', '<>', '1')
        // ->where('status', 1)
        ->first();

    // Get managers added by admin
    $managers = User::select('user_id', 'username', 'commission', 'email', 'phone', 'address', 'user_role', 'status')
        ->where('added_user_id', $userId)
        ->where('user_role', 'manager')
        ->where('is_deleted', '<>', '1')
        // ->where('status', 1)
        ->get();

    // Get sellers directly added by admin
    $adminSellers = User::select('user_id', 'username', 'commission', 'email', 'phone', 'address', 'user_role', 'status')
        ->where('added_user_id', $userId)
        ->where('user_role', 'seller')
        ->where('status', 1)
        ->where('is_deleted', '<>', '1')
        ->get();

    // Get sellers added by managers
    $managersWithSellers = $managers->flatMap(function($manager) {
        return User::select('user_id', 'username', 'commission', 'email', 'phone', 'address', 'user_role', 'status')
            ->where('added_user_id', $manager->user_id)
            ->where('user_role', 'seller')
            ->where('is_deleted', '<>', '1')
            // ->where('status', 1)
            ->get();
    });

    if ($all == 'all') {
        // Create an array to hold all the data
        $responseData = [];
        
        // Add admin details first
        // $adminData = [
        //     'user_id' => $adminDetails->user_id,
        //     'username' => $adminDetails->username,
        //     'email' => $adminDetails->email,
        //     'phone' => $adminDetails->phone,
        //     'user_role' => $adminDetails->user_role,
        //     'status' => $adminDetails->status,
        // ];
        
        // // Add admin details to the response data
        // $responseData[] = $adminData;
        
        // Merge sellers directly under the admin
        $adminSellers = collect($adminSellers)->map(function($seller) {
            $seller->username = $seller->username;
            return $seller;
        });
        $responseData = array_merge($responseData, $adminSellers->toArray());
        
        // Merge managers
        $managers = collect($managers)->map(function($manager) {
            $manager->username = $manager->username;
            return $manager;
        });
        $responseData = array_merge($responseData, $managers->toArray());
        
        // Merge sellers under the managers
        $managersWithSellers = collect($managersWithSellers)->map(function($seller) {
            $seller->username = $seller->username;
            return $seller;
        });
        $responseData = array_merge($responseData, $managersWithSellers->toArray());
    }elseif ($all == 'allManagers'){
        // Merge managers
        $managers = collect($managers)->map(function($manager) {
            $manager->username = $manager->username;
            return $manager;
        });
        
        
    $jsonResponse = [
                'success' => true,
                'msg' => 'Get Successfully',
                'data' => $managers
            ];
            
            return response()->json($jsonResponse);
        
    }else {
                $managersWithChildren = $managers->map(function($manager) {
        $sellers = User::select('user_id', 'username', 'commission', 'email', 'phone', 'address', 'user_role', 'status')
            ->where('added_user_id', $manager->user_id)
            ->where('user_role', 'seller')
            ->where('is_deleted', '<>', '1')
            // ->where('status', 1)
            ->get()
            ->map(function($seller) {
                // Add user_role in parentheses to the seller's username
                $seller->username = $seller->username;
                $seller->children = []; // Add empty children array to each seller
                return $seller;
            });

        // Add user_role in parentheses to the manager's username
        $manager->username = $manager->username;
        
        // Attach the sellers as children to the manager
        $manager->children = $sellers->toArray();
        return $manager;
    });
    
    // Ensure admin sellers have empty children arrays and include user_role in username
    $adminSellersWithChildren = $adminSellers->map(function($seller) {
        // Add user_role in parentheses to the seller's username
        $seller->username = $seller->username;
        $seller->children = []; // Add empty children array to each seller
        return $seller;
    });

    // Prepare the response data including admin details
    $responseData = [[
        'user_id' => $adminDetails->user_id,
        'username' => $adminDetails->username, // Add user_role in parentheses to the admin's username
        'email' => $adminDetails->email,
        'phone' => $adminDetails->phone,
        'user_role' => $adminDetails->user_role,
        'commission' => $adminDetails->commission,
        'status' => $adminDetails->status,
        'children' => array_merge($adminSellersWithChildren->toArray(), $managersWithChildren->toArray())
    ]];
            }

            $jsonResponse = [
                'success' => true,
                'msg' => 'Get Successfully',
                'data' => $responseData
            ];
        } else {
            $admins = User::select('user_id', 'username', 'commission', 'email', 'phone', 'address', 'user_role', 'commission', 'status')
                ->where(function($query) use ($userId) {
                    $query->where('added_user_id', $userId)
                          ->orWhere('user_id', $userId);
                })
                ->where('is_deleted', '<>', '1')
                // ->where('status', 1)
                ->orderBy('user_role', 'ASC')
                ->get();

            $adminsArray = $admins->toArray();

            $jsonResponse = [
                'success' => true,
                'msg' => 'Get Successfully',
                'data' => $adminsArray
            ];
        }

        return response()->json($jsonResponse);
    }
}



public function customerList()
{
    try {
        $customers = User::where('user_role', 'customer')->get();

        if ($customers->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No customers found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $customers,
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}


public function addCompanyDetails(Request $request){
    try{
        $validator = validator($request->all(), [
        'user_id' => 'required',
        'company_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'company_header' => 'nullable|string',
        'company_footer' => 'nullable|string',
    ]);

    // Check if validation fails
    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'msg' => $validator->errors()->first(),
        ], 400);
    }

    // Find the user by user_id
    $user = User::where('user_id', $request->user_id)->first();

    if (!$user) {
        return response()->json([
            'success' => false,
            'msg' => 'User not found',
        ], 404);
    }

    // Handle the company image upload
    if ($request->hasFile('company_image') || $request->file('company_image') != null) {
        // Store the company image in the 'company_images' folder
        $imagePath = $request->file('company_image')->store('company_images', 'public');
        
        // Get the full URL to the stored image
        $fullImagePath = '/storage/' . $imagePath;

        // Update the user's company_image column with the full image path
        $user->company_image = $fullImagePath;
    }

    // Update the company_header and company_footer
    if($request->company_header != null || $request->company_header != ''){
        $user->company_header = $request->company_header;
    }
    
    if($request->company_footer != null || $request->company_footer != ''){
        $user->company_footer = $request->company_footer;
    }

    // Save the updated user record
    $user->save();

    return response()->json([
        'success' => true,
        'msg' => 'Company details updated successfully',
        'data' => $user,
    ]);
    }catch(\Exception $e){
        return response()->json(['success' => false, 'msg' => $e->getMessage()], 400);
    }
}

public function changePassword(Request $request)
{
    $validator = validator($request->all(), [
        'current_password' => 'required',
        'new_password' => 'required|min:8',
        'confirm_password' => 'required|same:new_password',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => true,
            'msg' => $validator->errors()->first(),
            'error' => 'error',

        ], 422);
    }

    if(!empty($request->user_id)){
        $userId = $request->user_id;
        $user = User::find($userId);
    }else{
    $user = auth()->user();
    }
    // Check if the current password matches the one in the database (for MD5 hashed passwords)
    if (md5($request->current_password) !== $user->password) {
        return response()->json([
            'success' => true,
            'msg' => 'Current password is incorrect.',
            'error' => 'error',

        ], 401);
    }



    // Update the user's password
    $user->update([
        'password' => md5($request->new_password),
    ]);

    return response()->json([
        'success' => true,
        'msg' => 'Password changed successfully.',
        'user_id' => $user->user_id,
    ]);


}


public function changePin(Request $request)
{
    $validator = validator($request->all(), [
        'current_pin' => 'required|digits:4',
        'new_pin' => 'required|digits:4',
        'confirm_pin' => 'required|same:new_pin',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'msg' => $validator->errors()->first(),
            'error' => 'error',
        ], 422);
    }

    if (!empty($request->user_id)) {
        $user = User::find($request->user_id);
    } else {
        $user = auth()->user();
    }

    if (!$user) {
        return response()->json([
            'success' => false,
            'msg' => 'User not found',
            'error' => 'error',
        ], 404);
    }

    // Check if the current PIN matches
    if ($request->current_pin != $user->secret_pin) {
        return response()->json([
            'success' => false,
            'msg' => 'Current PIN is incorrect.',
            'error' => 'error',
        ], 402);
    }
// return $request->new_pin;
    // Update PIN
    $user->update([
        'secret_pin' => $request->new_pin,
    ]);

    return response()->json([
        'success' => true,
        'msg' => 'PIN changed successfully.',
        'user_id' => $user->user_id,
    ]);
}







// private funcations

private function buildUserTree($users)
{
    $userHash = [];

    // Create a hash table using user_id as keys and initialize the children array
    foreach ($users as $user) {
        $user['children'] = [];
        $userHash[$user['user_id']] = $user;
    }

    $tree = [];

    foreach ($users as $user) {
        if ($user['user_role'] === 'admin') {
            // Admin is a root element
            $tree[] = &$userHash[$user['user_id']];
        } elseif ($user['user_role'] === 'manager' && isset($userHash[$user['added_user_id']])) {
            // Manager is a child of admin
            $parent = &$userHash[$user['added_user_id']];
            $parent['children'][] = &$userHash[$user['user_id']];
        } elseif ($user['user_role'] === 'seller' && isset($userHash[$user['added_user_id']])) {
            // Seller is a child of manager
            $parent = &$userHash[$user['added_user_id']];
            $parent['children'][] = &$userHash[$user['user_id']];
        }
    }

    return $tree;
}


//edit user only commsion and status


public function editUser(Request $request, $userId)
{
    try {
        // Validate the incoming request data
        $validatedData = $request->validate([
            'commission' => 'required',
            'status' => 'required',
            'username' => 'required',
            'email' => 'required',
            'address' => 'nullable',
            'phone' => 'nullable'
        ]);

        // Prepare the user data for update
        $userData = [
            'commission' => $validatedData['commission'],
            'status' => $validatedData['status'],
            'username' => $validatedData['username'],
            'email' => $validatedData['email'],
            'address' => $validatedData['address'],
            'phone' => $validatedData['phone'],
        ];

        // Update the user attributes using the DB facade
        DB::table('users')
            ->where('user_id', $userId)
            ->update($userData);
        
        // If the status is 0, revoke the user's tokens
        if ($validatedData['status'] == 0) {
            // Find the user by ID
            $user = User::find($userId);
        
            if ($user) {
                if ($user->user_role == 'manager') {
                    // Get sellers under this manager
                    $sellers = User::where('added_user_id', $user->user_id)->get();
        
                    foreach ($sellers as $seller) {
                        // Update seller status to 0
                        $seller->status = 0;
                        $seller->save();
        
                        // Revoke seller tokens as well
                        $seller->tokens()->delete();
                    }
                }
        
                // Revoke all tokens for the manager
                $user->tokens()->delete();
        
                return response()->json([
                    'success' => true,
                    'msg' => 'User and related sellers updated, all tokens revoked successfully',
                ], 200);
            }
        }

        // Return a response indicating success
        return response()->json([
            'success' => true,
            'msg' => 'User updated successfully',

        ], 200);
    } catch (\Exception $e) {
        // Log the error
        \Log::error('Error updating user: ' . $e->getMessage());

        // Return an error response
        return response()->json([
            'success' => false,
            'msg' => 'Failed to update user.'], 500);
    }
}



}
