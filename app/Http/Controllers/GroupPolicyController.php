<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\GroupPolicy;
use App\Models\Group;

class GroupPolicyController extends Controller
{
    // Get policy for a group
    public function getPolicy($groupId)
    {
        try {
            $policy = GroupPolicy::where('group_id', $groupId)->first();
            
            if (!$policy) {
                // Create default policy if it doesn't exist
                $policy = GroupPolicy::create([
                    'group_id' => $groupId,
                    'allow_group_user_cashout' => true,
                    'allow_member_withdrawal' => true,
                    'require_approval_for_withdrawal' => true,
                ]);
            }
            
            return response()->json($policy);
        } catch (\Exception $e) {
            Log::error('Get Group Policy Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch group policy',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Update group policy
    public function updatePolicy(Request $request, $groupId)
    {
        try {
            $validated = $request->validate([
                'allow_group_user_cashout' => 'boolean',
                'allow_member_withdrawal' => 'boolean',
                'max_cashout_amount' => 'nullable|numeric|min:0',
                'max_withdrawal_amount' => 'nullable|numeric|min:0',
                'require_approval_for_withdrawal' => 'boolean',
            ]);

            $policy = GroupPolicy::where('group_id', $groupId)->first();
            
            if (!$policy) {
                $policy = GroupPolicy::create(array_merge(
                    ['group_id' => $groupId],
                    $validated
                ));
            } else {
                $policy->update($validated);
            }

            return response()->json([
                'message' => 'Policy updated successfully',
                'policy' => $policy,
            ]);
        } catch (\Exception $e) {
            Log::error('Update Policy Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to update policy',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
