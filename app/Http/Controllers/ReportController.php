<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Group;
use App\Models\Member;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Models\MonthlyDeduction;

class ReportController extends Controller
{
    /**
     * Get system-wide report (Super Admin only)
     */
    public function getSystemReport(Request $request)
    {
        try {
            $user = $request->user();
            
            if ($user->role !== 'super_admin') {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $groups = Group::count();
            $members = Member::count();
            $totalWalletBalance = Wallet::sum('balance');
            $totalTransactions = WalletTransaction::count();
            $pendingWithdrawals = WithdrawalRequest::where('status', 'pending')->count();
            $activeDeductions = MonthlyDeduction::where('is_active', true)->count();

            // Transaction summary by type
            $transactionsByType = WalletTransaction::select('transaction_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                ->groupBy('transaction_type')
                ->get();

            // Recent activity
            $recentTransactions = WalletTransaction::with('member')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'summary' => [
                    'total_groups' => $groups,
                    'total_members' => $members,
                    'total_wallet_balance' => $totalWalletBalance,
                    'total_transactions' => $totalTransactions,
                    'pending_withdrawals' => $pendingWithdrawals,
                    'active_deductions' => $activeDeductions,
                ],
                'transactions_by_type' => $transactionsByType,
                'recent_transactions' => $recentTransactions,
            ]);
        } catch (\Exception $e) {
            Log::error('System Report Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate system report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get group report (Group Admin and Group User)
     */
    public function getGroupReport(Request $request, $groupId)
    {
        try {
            $user = $request->user();
            
            // Check if user has access to this group
            if ($user->role !== 'super_admin' && $user->group_id != $groupId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $group = Group::findOrFail($groupId);
            $members = Member::where('group_id', $groupId)->count();
            
            // Get wallets for group members
            $wallets = Wallet::whereHas('member', function($query) use ($groupId) {
                $query->where('group_id', $groupId);
            })->get();

            $totalWalletBalance = $wallets->sum('balance');
            
            // Get transactions for group members
            $transactions = WalletTransaction::whereHas('member', function($query) use ($groupId) {
                $query->where('group_id', $groupId);
            })->count();

            // Transaction summary
            $transactionsByType = WalletTransaction::whereHas('member', function($query) use ($groupId) {
                $query->where('group_id', $groupId);
            })
            ->select('transaction_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('transaction_type')
            ->get();

            // Pending withdrawals
            $pendingWithdrawals = WithdrawalRequest::whereHas('member', function($query) use ($groupId) {
                $query->where('group_id', $groupId);
            })->where('status', 'pending')->count();

            // Active deductions
            $activeDeductions = MonthlyDeduction::where('group_id', $groupId)
                ->where('is_active', true)
                ->count();

            // Recent transactions
            $recentTransactions = WalletTransaction::whereHas('member', function($query) use ($groupId) {
                $query->where('group_id', $groupId);
            })
            ->with('member')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

            return response()->json([
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                ],
                'summary' => [
                    'total_members' => $members,
                    'total_wallet_balance' => $totalWalletBalance,
                    'total_transactions' => $transactions,
                    'pending_withdrawals' => $pendingWithdrawals,
                    'active_deductions' => $activeDeductions,
                ],
                'transactions_by_type' => $transactionsByType,
                'recent_transactions' => $recentTransactions,
            ]);
        } catch (\Exception $e) {
            Log::error('Group Report Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate group report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get member's own report (Member self-service)
     */
    public function getMemberReport(Request $request, $memberId)
    {
        try {
            $member = Member::find($memberId);
            
            if (!$member) {
                return response()->json([
                    'error' => 'Member not found',
                    'message' => 'Member with ID ' . $memberId . ' does not exist'
                ], 404);
            }

            $wallet = Wallet::where('member_id', $memberId)->first();

            if (!$wallet) {
                // Auto-create wallet if it doesn't exist
                $wallet = Wallet::create([
                    'member_id' => $memberId,
                    'balance' => 0,
                    'currency' => 'RWF',
                    'is_active' => true,
                ]);
            }

            // Get transaction history
            $transactions = WalletTransaction::where('member_id', $memberId)
                ->orderBy('created_at', 'desc')
                ->get();

            // Get withdrawal requests
            $withdrawalRequests = WithdrawalRequest::where('member_id', $memberId)
                ->orderBy('created_at', 'desc')
                ->get();

            // Transaction summary
            $transactionSummary = [
                'topup' => WalletTransaction::where('member_id', $memberId)
                    ->where('transaction_type', 'topup')
                    ->sum('amount'),
                'cashout' => WalletTransaction::where('member_id', $memberId)
                    ->where('transaction_type', 'cashout')
                    ->sum('amount'),
                'deduction' => WalletTransaction::where('member_id', $memberId)
                    ->where('transaction_type', 'deduction')
                    ->sum('amount'),
            ];

            return response()->json([
                'member' => [
                    'id' => $member->id,
                    'name' => $member->first_name . ' ' . $member->last_name,
                    'phone' => $member->phone,
                    'group' => $member->group,
                ],
                'wallet' => [
                    'id' => $wallet->id,
                    'balance' => $wallet->balance,
                    'currency' => $wallet->currency,
                    'is_active' => $wallet->is_active,
                ],
                'transaction_summary' => $transactionSummary,
                'transactions' => $transactions,
                'withdrawal_requests' => $withdrawalRequests,
            ]);
        } catch (\Exception $e) {
            Log::error('Member Report Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate member report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get audit log (Admin users)
     */
    public function getAuditLog(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!in_array($user->role, ['super_admin', 'group_admin'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $query = WalletTransaction::with('member');

            // Filter by group for group admins
            if ($user->role === 'group_admin') {
                $query->whereHas('member', function($q) use ($user) {
                    $q->where('group_id', $user->group_id);
                });
            }

            $auditLog = $query->orderBy('created_at', 'desc')
                ->limit(50)
                ->get();

            return response()->json([
                'audit_log' => $auditLog,
            ]);
        } catch (\Exception $e) {
            Log::error('Audit Log Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to get audit log',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current authenticated member's report
     */
    public function getMyReport(Request $request)
    {
        try {
            $user = $request->user();
            
            // Find member by matching user email to member data
            // Try to match by email pattern first
            $member = Member::where('group_id', $user->group_id)
                ->where(function($query) use ($user) {
                    // Try exact phone match if email contains phone
                    if (preg_match('/^(\d+)@/', $user->email, $matches)) {
                        $query->where('phone', $matches[1]);
                    }
                    // Try name match (first.last@member.com pattern)
                    else if (preg_match('/^([^.]+)\.([^@]+)@/', $user->email, $matches)) {
                        $firstName = ucfirst($matches[1]);
                        $lastName = ucfirst($matches[2]);
                        $query->where('first_name', $firstName)
                              ->where('last_name', $lastName);
                    }
                })
                ->first();

            if (!$member) {
                return response()->json([
                    'error' => 'Member profile not found',
                    'message' => 'No member profile associated with your account'
                ], 404);
            }

            // Get the member report using the existing method
            return $this->getMemberReport($request, $member->id);
        } catch (\Exception $e) {
            Log::error('My Report Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate your report',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
