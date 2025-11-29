<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            $user = User::where('email', $validated['email'])->first();

            if (!$user || !Hash::check($validated['password'], $user->password)) {
                return response()->json([
                    'error' => 'Invalid credentials',
                    'message' => 'The provided email or password is incorrect'
                ], 401);
            }

            // Get group information if user belongs to a group
            $groupInfo = null;
            if ($user->group_id) {
                $group = \App\Models\Group::find($user->group_id);
                if ($group) {
                    $groupInfo = [
                        'id' => $group->id,
                        'name' => $group->name,
                    ];
                }
            }

            // Create token for API authentication
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Login successful',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'group_id' => $user->group_id,
                    'group' => $groupInfo,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Login Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Login failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:6',
                'role' => 'sometimes|string|in:super_admin,group_admin,group_user,member',
                'group_id' => 'sometimes|exists:groups,id',
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'] ?? 'member',
                'group_id' => $validated['group_id'] ?? null,
            ]);

            // Create token for API authentication
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Registration successful',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'group_id' => $user->group_id,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Registration Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Registration failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function me(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'group_id' => $user->group_id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get User Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to get user',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
