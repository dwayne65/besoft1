<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MemberController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Member::with('group')->orderByDesc('created_at');
        
        // Super admin can see all members
        if ($user->role === 'super_admin') {
            if ($request->input('group_id')) {
                $query->where('group_id', $request->input('group_id'));
            }
            return $query->get();
        }
        
        // Group admin and group user can only see members from their group
        if (in_array($user->role, ['group_admin', 'group_user']) && $user->group_id) {
            $query->where('group_id', $user->group_id);
            return $query->get();
        }
        
        // Members cannot list other members
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
        
        $data = $request->validate([
            'first_name' => ['required','string','max:255'],
            'last_name' => ['required','string','max:255'],
            'birth_date' => ['required','date'],
            'gender' => ['required','in:MALE,FEMALE,OTHER'],
            'is_active' => ['required','boolean'],
            'national_id' => ['required','string','max:255','unique:members,national_id'],
            'phone' => ['required','string','max:50'],
            'group_id' => ['required','exists:groups,id'],
        ]);
        
        // Group user can only add members to their own group
        if ($user->role === 'group_user' && $user->group_id != $data['group_id']) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You can only add members to your own group'
            ], 403);
        }
        
        // Group admin can only add members to their own group
        if ($user->role === 'group_admin' && $user->group_id != $data['group_id']) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You can only add members to your own group'
            ], 403);
        }
        
        try {
            DB::beginTransaction();
            
            // Create the member
            $member = Member::create($data);
            
            // Automatically create wallet for the member
            $wallet = Wallet::create([
                'member_id' => $member->id,
                'balance' => 0,
                'currency' => 'RWF',
                'is_active' => true,
            ]);
            
            DB::commit();
            
            Log::info('Member and Wallet Created', [
                'member_id' => $member->id,
                'wallet_id' => $wallet->id,
                'created_by' => $user->name,
            ]);
            
            return response()->json([
                'member' => $member->load('group'),
                'wallet' => $wallet,
                'message' => 'Member and wallet created successfully'
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Member Creation Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to create member',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Member $member)
    {
        $user = $request->user();
        
        // Super admin can view any member
        if ($user->role === 'super_admin') {
            return $member->load('group');
        }
        
        // Group admin and group user can only view members from their group
        if (in_array($user->role, ['group_admin', 'group_user'])) {
            if ($user->group_id != $member->group_id) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'You can only view members from your own group'
                ], 403);
            }
            return $member->load('group');
        }
        
        return response()->json(['error' => 'Forbidden'], 403);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Member $member)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Member $member)
    {
        $user = $request->user();
        
        // Group user and group admin can only update members from their group
        if (in_array($user->role, ['group_admin', 'group_user'])) {
            if ($user->group_id != $member->group_id) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'You can only update members from your own group'
                ], 403);
            }
        }
        
        $data = $request->validate([
            'first_name' => ['sometimes','string','max:255'],
            'last_name' => ['sometimes','string','max:255'],
            'birth_date' => ['sometimes','date'],
            'gender' => ['sometimes','in:MALE,FEMALE,OTHER'],
            'is_active' => ['sometimes','boolean'],
            'national_id' => ['sometimes','string','max:255','unique:members,national_id,'.$member->id],
            'phone' => ['sometimes','string','max:50'],
            'group_id' => ['sometimes','exists:groups,id'],
        ]);
        
        // Prevent changing group_id if user is group_user or group_admin
        if (isset($data['group_id']) && in_array($user->role, ['group_admin', 'group_user'])) {
            if ($data['group_id'] != $user->group_id) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'You cannot move members to a different group'
                ], 403);
            }
        }
        
        $member->update($data);
        return $member->load('group');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Member $member)
    {
        $user = $request->user();
        
        // Super admin can delete any member
        if ($user->role === 'super_admin') {
            $member->delete();
            return response()->noContent();
        }
        
        // Group admin can only delete members from their group
        if ($user->role === 'group_admin' && $user->group_id == $member->group_id) {
            $member->delete();
            return response()->noContent();
        }
        
        return response()->json([
            'error' => 'Forbidden',
            'message' => 'You can only delete members from your own group'
        ], 403);
    }
}
