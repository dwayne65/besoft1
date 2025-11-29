<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\WalletTransaction;
use App\Models\Member;
use App\Models\User;

class WalletReportController extends Controller
{
    // Get top-up reports
    public function getTopUpReport(Request $request)
    {
        try {
            $validated = $request->validate([
                'group_id' => 'nullable|exists:groups,id',
                'member_id' => 'nullable|exists:members,id',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'initiated_by' => 'nullable|string',
            ]);

            $query = WalletTransaction::where('transaction_type', 'topup')
                ->where('status', 'completed');

            // Filter by group if specified
            if (isset($validated['group_id'])) {
                $query->whereHas('member', function($q) use ($validated) {
                    $q->where('group_id', $validated['group_id']);
                });
            }

            // Filter by member if specified
            if (isset($validated['member_id'])) {
                $query->where('member_id', $validated['member_id']);
            }

            // Filter by date range
            if (isset($validated['start_date'])) {
                $query->whereDate('created_at', '>=', $validated['start_date']);
            }
            if (isset($validated['end_date'])) {
                $query->whereDate('created_at', '<=', $validated['end_date']);
            }

            // Filter by who initiated
            if (isset($validated['initiated_by'])) {
                $query->where('initiated_by', $validated['initiated_by']);
            }

            // Get transactions with member info
            $transactions = $query->with('member')->orderBy('created_at', 'desc')->get();

            // Summary statistics
            $summary = [
                'total_count' => $transactions->count(),
                'total_amount' => $transactions->sum('amount'),
                'by_source' => $query->select('source', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                    ->groupBy('source')
                    ->get(),
                'by_initiated_by' => $query->select('initiated_by', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                    ->groupBy('initiated_by')
                    ->get(),
            ];

            return response()->json([
                'transactions' => $transactions,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            Log::error('Top-up Report Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate top-up report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get cash-out reports
    public function getCashOutReport(Request $request)
    {
        try {
            $validated = $request->validate([
                'group_id' => 'nullable|exists:groups,id',
                'member_id' => 'nullable|exists:members,id',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'initiated_by' => 'nullable|string',
            ]);

            $query = WalletTransaction::where('transaction_type', 'cashout')
                ->where('status', 'completed');

            // Filter by group if specified
            if (isset($validated['group_id'])) {
                $query->whereHas('member', function($q) use ($validated) {
                    $q->where('group_id', $validated['group_id']);
                });
            }

            // Filter by member if specified
            if (isset($validated['member_id'])) {
                $query->where('member_id', $validated['member_id']);
            }

            // Filter by date range
            if (isset($validated['start_date'])) {
                $query->whereDate('created_at', '>=', $validated['start_date']);
            }
            if (isset($validated['end_date'])) {
                $query->whereDate('created_at', '<=', $validated['end_date']);
            }

            // Filter by who initiated
            if (isset($validated['initiated_by'])) {
                $query->where('initiated_by', $validated['initiated_by']);
            }

            // Get transactions with member info
            $transactions = $query->with('member')->orderBy('created_at', 'desc')->get();

            // Summary statistics
            $summary = [
                'total_count' => $transactions->count(),
                'total_amount' => $transactions->sum('amount'),
                'by_method' => $query->select('method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                    ->groupBy('method')
                    ->get(),
                'by_initiated_by' => $query->select('initiated_by', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                    ->groupBy('initiated_by')
                    ->get(),
            ];

            return response()->json([
                'transactions' => $transactions,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            Log::error('Cash-out Report Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate cash-out report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get wallet balance summary for a group
    public function getGroupWalletSummary(Request $request, $groupId)
    {
        try {
            $members = Member::where('group_id', $groupId)
                ->with(['wallet', 'wallet.transactions' => function($q) {
                    $q->latest()->limit(5);
                }])
                ->get();

            $totalBalance = $members->sum(function($member) {
                return $member->wallet ? $member->wallet->balance : 0;
            });

            $totalTopUps = WalletTransaction::whereIn('member_id', $members->pluck('id'))
                ->where('transaction_type', 'topup')
                ->where('status', 'completed')
                ->sum('amount');

            $totalCashOuts = WalletTransaction::whereIn('member_id', $members->pluck('id'))
                ->where('transaction_type', 'cashout')
                ->where('status', 'completed')
                ->sum('amount');

            return response()->json([
                'total_balance' => $totalBalance,
                'total_topups' => $totalTopUps,
                'total_cashouts' => $totalCashOuts,
                'member_count' => $members->count(),
                'members' => $members->map(function($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->first_name . ' ' . $member->last_name,
                        'phone' => $member->phone,
                        'balance' => $member->wallet ? $member->wallet->balance : 0,
                        'recent_transactions' => $member->wallet ? $member->wallet->transactions : [],
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            Log::error('Group Wallet Summary Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate group wallet summary',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get member wallet statement
    public function getMemberStatement(Request $request, $memberId)
    {
        try {
            $validated = $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'transaction_type' => 'nullable|string',
            ]);

            $member = Member::with('wallet')->findOrFail($memberId);

            $query = WalletTransaction::where('member_id', $memberId);

            // Filter by date range
            if (isset($validated['start_date'])) {
                $query->whereDate('created_at', '>=', $validated['start_date']);
            }
            if (isset($validated['end_date'])) {
                $query->whereDate('created_at', '<=', $validated['end_date']);
            }

            // Filter by transaction type
            if (isset($validated['transaction_type'])) {
                $query->where('transaction_type', $validated['transaction_type']);
            }

            $transactions = $query->orderBy('created_at', 'desc')->get();

            $summary = [
                'current_balance' => $member->wallet ? $member->wallet->balance : 0,
                'total_transactions' => $transactions->count(),
                'total_credits' => $transactions->where('direction', 'credit')->sum('amount'),
                'total_debits' => $transactions->where('direction', 'debit')->sum('amount'),
            ];

            return response()->json([
                'member' => [
                    'id' => $member->id,
                    'name' => $member->first_name . ' ' . $member->last_name,
                    'phone' => $member->phone,
                ],
                'summary' => $summary,
                'transactions' => $transactions,
            ]);
        } catch (\Exception $e) {
            Log::error('Member Statement Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate member statement',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
