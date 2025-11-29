<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Ensure user is authenticated
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        
        // Super admin can see all groups
        if ($user->role === 'super_admin') {
            return Group::orderByDesc('created_at')->get();
        }
        
        // Group admin and group user can only see their own group
        if (in_array($user->role, ['group_admin', 'group_user']) && $user->group_id) {
            return Group::where('id', $user->group_id)
                ->orderByDesc('created_at')
                ->get();
        }
        
        // Members and other roles cannot see groups - return empty array
        return response()->json([], 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        // Ensure user is authenticated
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        
        // Only super admin and group admin can create groups
        if (!in_array($user->role, ['super_admin', 'group_admin'])) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Only super admin and group admin can create groups'
            ], 403);
        }
        
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'created_by' => ['required','string','max:255'],
        ]);
        $group = Group::create($data);
        return response()->json($group, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Group $group)
    {
        $user = $request->user();
        
        // Super admin can view any group
        if ($user->role === 'super_admin') {
            return $group;
        }
        
        // Group admin and group user can only view their own group
        if (in_array($user->role, ['group_admin', 'group_user'])) {
            if ($user->group_id != $group->id) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'You can only view your own group'
                ], 403);
            }
            return $group;
        }
        
        return response()->json(['error' => 'Forbidden'], 403);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Group $group)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Group $group)
    {
        $user = $request->user();
        
        // Super admin can update any group
        if ($user->role === 'super_admin') {
            $data = $request->validate([
                'name' => ['sometimes','string','max:255'],
                'description' => ['nullable','string'],
                'created_by' => ['sometimes','string','max:255'],
            ]);
            $group->update($data);
            return $group;
        }
        
        // Group admin can only update their own group
        if ($user->role === 'group_admin' && $user->group_id == $group->id) {
            $data = $request->validate([
                'name' => ['sometimes','string','max:255'],
                'description' => ['nullable','string'],
            ]);
            $group->update($data);
            return $group;
        }
        
        return response()->json([
            'error' => 'Forbidden',
            'message' => 'You can only update your own group'
        ], 403);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Group $group)
    {
        $user = $request->user();
        
        // Only super admin can delete groups
        if ($user->role !== 'super_admin') {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Only super admin can delete groups'
            ], 403);
        }
        
        $group->delete();
        return response()->noContent();
    }
}
