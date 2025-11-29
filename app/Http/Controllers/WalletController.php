<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Member;
use App\Models\WithdrawalRequest;
use App\Models\MonthlyDeduction;
use App\Models\MonthlyDeductionLog;
use App\Models\GroupPolicy;

class WalletController extends Controller
{
    // Get wallet for a member
    public function getWallet(Request $request, $memberId)
    {
        try {
            $user = $request->user();
            $member = Member::findOrFail($memberId);
            
            // Group user and group admin can only view wallets from their group
            if (in_array($user->role, ['group_admin', 'group_user'])) {
                if ($user->group_id != $member->group_id) {
                    return response()->json([
                        'error' => 'Forbidden',
                        'message' => 'You can only view wallets from your own group'
                    ], 403);
                }
            }
            
            $wallet = Wallet::where('member_id', $memberId)
                ->with('member')
                ->first();
            
            if (!$wallet) {
                return response()->json([
                    'error' => 'Wallet not found'
                ], 404);
            }
            
            return response()->json($wallet);
        } catch (\Exception $e) {
            Log::error('Get Wallet Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch wallet',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Top-up wallet
    public function topup(Request $request)
    {
        try {
            $validated = $request->validate([
                'member_id' => 'required|exists:members,id',
                'amount' => 'required|numeric|min:1',
                'description' => 'nullable|string',
                'source' => 'nullable|string|in:cash,bank,mobile_money,other',
                'reference' => 'nullable|string',
                'notes' => 'nullable|string',
                'created_by' => 'required|string',
                'initiated_by' => 'nullable|string|in:system,group_admin,group_user,member,super_admin',
            ]);

            $user = $request->user();
            $member = Member::findOrFail($validated['member_id']);
            
            // Group user and group admin can only top-up wallets from their group
            if (in_array($user->role, ['group_admin', 'group_user'])) {
                if ($user->group_id != $member->group_id) {
                    return response()->json([
                        'error' => 'Forbidden',
                        'message' => 'You can only top-up wallets from your own group'
                    ], 403);
                }
            }

            DB::beginTransaction();

            $wallet = Wallet::where('member_id', $validated['member_id'])->first();
            
            if (!$wallet) {
                return response()->json(['error' => 'Wallet not found'], 404);
            }

            $balanceBefore = $wallet->balance;
            $wallet->balance += $validated['amount'];
            $wallet->save();

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'member_id' => $validated['member_id'],
                'transaction_type' => 'topup',
                'amount' => $validated['amount'],
                'direction' => 'credit',
                'source' => $validated['source'] ?? 'cash',
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
                'description' => $validated['description'] ?? 'Wallet top-up',
                'created_by' => $validated['created_by'],
                'initiated_by' => $validated['initiated_by'] ?? 'group_admin',
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Top-up successful',
                'wallet' => $wallet,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Topup Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Top-up failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Cash-out from wallet
    public function cashout(Request $request)
    {
        try {
            $validated = $request->validate([
                'member_id' => 'required|exists:members,id',
                'amount' => 'required|numeric|min:1',
                'description' => 'nullable|string',
                'method' => 'nullable|string|in:cash,bank,mobile_money,internal_balancing,other',
                'reference' => 'nullable|string',
                'notes' => 'nullable|string',
                'created_by' => 'required|string',
                'user_role' => 'nullable|string',
                'initiated_by' => 'nullable|string|in:system,group_admin,group_user,member,super_admin',
            ]);

            $user = $request->user();
            $member = Member::findOrFail($validated['member_id']);
            
            // Group user and group admin can only cash-out wallets from their group
            if (in_array($user->role, ['group_admin', 'group_user'])) {
                if ($user->group_id != $member->group_id) {
                    return response()->json([
                        'error' => 'Forbidden',
                        'message' => 'You can only cash-out wallets from your own group'
                    ], 403);
                }
            }

            DB::beginTransaction();

            $wallet = Wallet::where('member_id', $validated['member_id'])->first();
            
            if (!$wallet) {
                return response()->json(['error' => 'Wallet not found'], 404);
            }

            // Check group policy if user is group_user
            if (isset($validated['user_role']) && $validated['user_role'] === 'group_user') {
                $policy = GroupPolicy::where('group_id', $member->group_id)->first();
                
                if ($policy && !$policy->allow_group_user_cashout) {
                    return response()->json([
                        'error' => 'Forbidden',
                        'message' => 'Group policy does not allow group users to perform cash-out'
                    ], 403);
                }
                
                if ($policy && $policy->max_cashout_amount && $validated['amount'] > $policy->max_cashout_amount) {
                    return response()->json([
                        'error' => 'Amount exceeds limit',
                        'message' => 'Cash-out amount exceeds group policy limit of ' . $policy->max_cashout_amount
                    ], 400);
                }
            }

            if ($wallet->balance < $validated['amount']) {
                return response()->json(['error' => 'Insufficient balance'], 400);
            }

            $balanceBefore = $wallet->balance;
            $wallet->balance -= $validated['amount'];
            $wallet->save();

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'member_id' => $validated['member_id'],
                'transaction_type' => 'cashout',
                'amount' => $validated['amount'],
                'direction' => 'debit',
                'method' => $validated['method'] ?? 'cash',
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
                'description' => $validated['description'] ?? 'Wallet cash-out',
                'created_by' => $validated['created_by'],
                'initiated_by' => $validated['initiated_by'] ?? 'group_admin',
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Cash-out successful',
                'wallet' => $wallet,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Cashout Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Cash-out failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get wallet transactions
    public function getTransactions(Request $request, $memberId)
    {
        try {
            $user = $request->user();
            $member = Member::findOrFail($memberId);
            
            // Group user and group admin can only view transactions from their group
            if (in_array($user->role, ['group_admin', 'group_user'])) {
                if ($user->group_id != $member->group_id) {
                    return response()->json([
                        'error' => 'Forbidden',
                        'message' => 'You can only view transactions from your own group'
                    ], 403);
                }
            }
            
            $wallet = Wallet::where('member_id', $memberId)->first();
            
            if (!$wallet) {
                return response()->json(['error' => 'Wallet not found'], 404);
            }

            $transactions = WalletTransaction::where('wallet_id', $wallet->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($transactions);
        } catch (\Exception $e) {
            Log::error('Get Transactions Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch transactions',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get all wallets for a group
    public function getGroupWallets($groupId)
    {
        try {
            $wallets = Wallet::whereHas('member', function($query) use ($groupId) {
                $query->where('group_id', $groupId);
            })
            ->with('member')
            ->get();

            return response()->json($wallets);
        } catch (\Exception $e) {
            Log::error('Get Group Wallets Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch group wallets',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Create withdrawal request
    public function createWithdrawalRequest(Request $request)
    {
        try {
            $validated = $request->validate([
                'member_id' => 'required|exists:members,id',
                'amount' => 'required|numeric|min:1',
                'phone' => 'required|string',
                'notes' => 'nullable|string',
            ]);

            $wallet = Wallet::where('member_id', $validated['member_id'])->first();
            
            if (!$wallet) {
                return response()->json(['error' => 'Wallet not found'], 404);
            }

            if ($wallet->balance < $validated['amount']) {
                return response()->json(['error' => 'Insufficient balance'], 400);
            }

            $withdrawal = WithdrawalRequest::create([
                'member_id' => $validated['member_id'],
                'wallet_id' => $wallet->id,
                'amount' => $validated['amount'],
                'phone' => $validated['phone'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
            ]);

            return response()->json([
                'message' => 'Withdrawal request created successfully',
                'withdrawal' => $withdrawal,
            ]);
        } catch (\Exception $e) {
            Log::error('Create Withdrawal Request Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to create withdrawal request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get withdrawal requests for a group
    public function getWithdrawalRequests($groupId)
    {
        try {
            $requests = WithdrawalRequest::whereHas('member', function($query) use ($groupId) {
                $query->where('group_id', $groupId);
            })
            ->with('member', 'wallet')
            ->orderBy('created_at', 'desc')
            ->get();

            return response()->json($requests);
        } catch (\Exception $e) {
            Log::error('Get Withdrawal Requests Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch withdrawal requests',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Monthly deduction management
    public function getMonthlyDeductions($groupId)
    {
        try {
            $deductions = MonthlyDeduction::where('group_id', $groupId)->get();
            return response()->json($deductions);
        } catch (\Exception $e) {
            Log::error('Get Monthly Deductions Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch monthly deductions',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function createMonthlyDeduction(Request $request)
    {
        try {
            $validated = $request->validate([
                'group_id' => 'required|exists:groups,id',
                'name' => 'required|string',
                'amount' => 'required|numeric|min:1',
                'account_number' => 'required|string',
                'day_of_month' => 'required|integer|min:1|max:31',
                'created_by' => 'required|string',
            ]);

            $deduction = MonthlyDeduction::create($validated);

            return response()->json([
                'message' => 'Monthly deduction created successfully',
                'deduction' => $deduction,
            ]);
        } catch (\Exception $e) {
            Log::error('Create Monthly Deduction Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to create monthly deduction',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateMonthlyDeduction(Request $request, $id)
    {
        try {
            $deduction = MonthlyDeduction::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|string',
                'amount' => 'sometimes|numeric|min:1',
                'account_number' => 'sometimes|string',
                'day_of_month' => 'sometimes|integer|min:1|max:31',
                'is_active' => 'sometimes|boolean',
            ]);

            $deduction->update($validated);

            return response()->json([
                'message' => 'Monthly deduction updated successfully',
                'deduction' => $deduction,
            ]);
        } catch (\Exception $e) {
            Log::error('Update Monthly Deduction Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to update monthly deduction',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteMonthlyDeduction($id)
    {
        try {
            $deduction = MonthlyDeduction::findOrFail($id);
            $deduction->delete();

            return response()->json([
                'message' => 'Monthly deduction deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete Monthly Deduction Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to delete monthly deduction',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Approve withdrawal request
    public function approveWithdrawal(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'approved_by' => 'required|string',
            ]);

            DB::beginTransaction();

            $withdrawal = WithdrawalRequest::findOrFail($id);
            
            if ($withdrawal->status !== 'pending') {
                return response()->json(['error' => 'Withdrawal already processed'], 400);
            }

            $wallet = Wallet::findOrFail($withdrawal->wallet_id);

            if ($wallet->balance < $withdrawal->amount) {
                return response()->json(['error' => 'Insufficient balance'], 400);
            }

            // Deduct from wallet
            $balanceBefore = $wallet->balance;
            $wallet->balance -= $withdrawal->amount;
            $wallet->save();

            // Create transaction record
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'member_id' => $withdrawal->member_id,
                'transaction_type' => 'withdrawal',
                'amount' => $withdrawal->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
                'description' => 'Withdrawal to ' . $withdrawal->phone,
                'created_by' => $validated['approved_by'],
                'status' => 'completed',
            ]);

            // Update withdrawal request
            $withdrawal->status = 'approved';
            $withdrawal->approved_by = $validated['approved_by'];
            $withdrawal->approved_at = now();
            $withdrawal->save();

            DB::commit();

            return response()->json([
                'message' => 'Withdrawal approved successfully',
                'withdrawal' => $withdrawal,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Approve Withdrawal Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to approve withdrawal',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Reject withdrawal request
    public function rejectWithdrawal(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'approved_by' => 'required|string',
                'notes' => 'nullable|string',
            ]);

            $withdrawal = WithdrawalRequest::findOrFail($id);
            
            if ($withdrawal->status !== 'pending') {
                return response()->json(['error' => 'Withdrawal already processed'], 400);
            }

            $withdrawal->status = 'rejected';
            $withdrawal->approved_by = $validated['approved_by'];
            $withdrawal->approved_at = now();
            $withdrawal->notes = $validated['notes'] ?? $withdrawal->notes;
            $withdrawal->save();

            return response()->json([
                'message' => 'Withdrawal rejected',
                'withdrawal' => $withdrawal,
            ]);
        } catch (\Exception $e) {
            Log::error('Reject Withdrawal Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to reject withdrawal',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get deduction logs for a group
    public function getDeductionLogs($groupId)
    {
        try {
            $logs = MonthlyDeductionLog::whereHas('deduction', function($query) use ($groupId) {
                $query->where('group_id', $groupId);
            })
            ->with(['deduction', 'member', 'wallet'])
            ->orderBy('scheduled_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

            return response()->json($logs);
        } catch (\Exception $e) {
            Log::error('Get Deduction Logs Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch deduction logs',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get deduction log summary for a deduction rule
    public function getDeductionSummary($deductionId)
    {
        try {
            $deduction = MonthlyDeduction::findOrFail($deductionId);
            
            $logs = MonthlyDeductionLog::where('deduction_id', $deductionId)->get();
            
            $summary = [
                'deduction' => $deduction,
                'total_runs' => $logs->count(),
                'total_success' => $logs->where('status', 'success')->count(),
                'total_failed' => $logs->whereIn('status', ['failed', 'insufficient_balance'])->count(),
                'total_amount_attempted' => $logs->sum('amount_attempted'),
                'total_amount_deducted' => $logs->sum('amount_deducted'),
                'recent_logs' => $logs->sortByDesc('scheduled_date')->take(10)->values(),
            ];

            return response()->json($summary);
        } catch (\Exception $e) {
            Log::error('Get Deduction Summary Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch deduction summary',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
