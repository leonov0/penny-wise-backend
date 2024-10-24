<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    // Function to update user info
    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        // Validate each field only if it is present in the request
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'current_password' => 'required_with:password|string',
            'password' => 'nullable|string|min:8|confirmed|required_with:current_password',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Check if the name is present and update it
        if ($request->has('name')) {
            $user->name = $request->name;
        }

        // Check if the email is present and update it
        if ($request->has('email')) {
            $user->email = $request->email;
        }

        // Check if the password is present and update it
        if ($request->has('password') && !empty($request->password)) {
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'message' => 'The current password is incorrect.'
                ], 403); // Return error if the current password is incorrect
            }
    
            // If the current password is correct and a new password is provided, set the new password
            $user->password = Hash::make($request->password);
        }
        // Save the updated user information
        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user
        ], 200);
    }


    public function destroy($id)
    {
        // Find the user by ID
        $user = User::find($id);

        // Check if the user exists
        if (!$user) {
            return response()->json(['message' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        // Optional: Prevent the admin from deleting themselves
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], Response::HTTP_FORBIDDEN);
        }

        // Delete the user
        $user->delete();

        return response()->json(['message' => 'User deleted successfully.'], Response::HTTP_OK);
    }

    public function getUser(Request $request)
    {
        return response()->json($request->user());
    }
}