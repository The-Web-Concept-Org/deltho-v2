<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoginController extends Controller
{

    public function webLogin(Request $request)
    {
        $email = $request->input('email');
        $token = Str::random(60);
        $password = $request->input('password');
        $user = User::where('email', $email)->first();

        if ($user && md5($password) === $user->password) {
            // Check if the user role is 0 or 1
            $userRole = $user->user_role;
            if ($userRole != 'superadmin' && $userRole != 'admin' && $userRole != 'manager') {
                // User role is not allowed to login
                return response()->json(['success' => false, 'message' => 'User not  allowed to login!'], 401);
            }

            // Create a session for the user
            session(['user_details' => [
                'token' => $token, // Set token value if needed
                'user_id' => $user->user_id,
                'username' => $user->username,
                'email' => $user->email,
                'user_role' => $user->user_role,
                'phone' => $user->phone,
                'address' => $user->address,
                'commission' => $user->user_commission,
                'added_user_id' => $user->added_user_id,
                'status' => $user->status,
            ]]);

            return response()->json(['success' => true, 'message' => 'Login successful', 'user_details' => session('user_details')]);
        } else {
            // Authentication failed
            return response()->json(['success' => false, 'message' => 'Invalid credentials'], 401);
        }
    }

    public function logout(Request $request)
    {
        $request->session()->forget('user_details');
        $request->session()->regenerate();

        return redirect('/');
    }

    public function login(Request $request)
{
    try {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || md5($request->password) !== $user->password) {
            return response()->json(['msg' => 'Invalid credentials', 'success' => false], 200);
        }

        if ($user->status !== '1' || $user->is_deleted == 1) {
            return response()->json(['error' => 'User Not Active', 'success' => false, 'msg' => 'User Not Active'], 200);
        }

        // Clear previous sessions/tokens
        if($user->user_role == 'sller' || $user->user_role == 'customer')
        {
        $user->tokens()->delete(); // 🚀 This ensures old logins are logged out
        }

        // Replace null with empty string for specific fields
        $user->company_image = $user->company_image ?? '';
        $user->company_header = $user->company_header ?? '';
        $user->company_footer = $user->company_footer ?? '';

        // Create new token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
            'success' => true,
            'msg' => 'User Login Successfully'
        ], 200);

    } catch (\Throwable $th) {
        return response()->json([
            'error' => 'Invalid credentials',
            'success' => false,
            'msg' => 'Invalid credentials'
        ], 200);
    }
}

}
