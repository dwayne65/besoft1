<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\MonthlyDeduction;
use App\Models\MonthlyDeductionLog;
use App\Models\Group;
use Carbon\Carbon;

class MonthlyDeductionController extends Controller
{
    // Get deduction summary by rule and month
    public function getDeductionSummary(Request $request, $deductionId)
    {
        try {
            $validated = $request->validate([
                'month' => 'nullable|date_format:Y-m',
            ]);

            $deduction = MonthlyDeduction::with('group')->findOrFail($deductionId);
            
            $query = MonthlyDeductionLog::where('deduction_id', $deductionId);
            
            // Filter by month if provided
            if (isset($validated['month'])) {
                $month = Carbon::parse($validated['month']);
                $query->whereYear('scheduled_date', $month->year)
                      ->whereMonth('scheduled_date', $month->month);
            }
            
            $logs = $query->with('member')->get();
            
            $summary = [
                'deduction_name' => $deduction->name,
                'group_name' => $deduction->group->name,
                'total_attempted' => $logs->sum('amount_attempted'),
                'total_deducted' => $logs->sum('amount_deducted'),
                'total_members' => $logs->count(),
                'success_count' => $logs->where('status', 'success')->count(),
                'partial_count' => $logs->where('status', 'partial')->count(),
                'failed_count' => $logs->whereIn('status', ['failed', 'insufficient_balance', 'skipped'])->count(),
                'by_status' => $logs->groupBy('status')->map(function($group) {
                    return [
                        'count' => $group->count(),
                        'total_attempted' => $group->sum('amount_attempted'),
                        'total_deducted' => $group->sum('amount_deducted'),
                    ];
                }),
            ];

            return response()->json([
                'deduction' => $deduction,
                'summary' => $summary,
                'logs' => $logs,
            ]);
        } catch (\Exception $e) {
            Log::error('Deduction Summary Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate deduction summary',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get target account collection summary
    public function getTargetAccountSummary(Request $request, $deductionId)
    {
        try {
            $validated = $request->validate([
                'month' => 'required|date_format:Y-m',
            ]);

            $deduction = MonthlyDeduction::with('group')->findOrFail($deductionId);
            $month = Carbon::parse($validated['month']);
            
            // Get all successful deductions for this month
            $logs = MonthlyDeductionLog::where('deduction_id', $deductionId)
                ->whereYear('scheduled_date', $month->year)
                ->whereMonth('scheduled_date', $month->month)
                ->whereIn('status', ['success', 'partial'])
                ->with('member')
                ->get();
            
            $totalCollected = $logs->sum('amount_deducted');
            
            return response()->json([
                'deduction' => $deduction,
                'month' => $month->format('Y-m'),
                'target_account_number' => $deduction->target_account_number,
                'total_collected' => $totalCollected,
                'total_transactions' => $logs->count(),
                'details' => $logs->map(function($log) {
                    return [
                        'member_name' => $log->member->first_name . ' ' . $log->member->last_name,
                        'amount' => $log->amount_deducted,
                        'status' => $log->status,
                        'date' => $log->actual_run_date,
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            Log::error('Target Account Summary Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to generate target account summary',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Get deduction history for a group
    public function getGroupDeductionHistory(Request $request, $groupId)
    {
        try {
            $validated = $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'deduction_id' => 'nullable|exists:monthly_deductions,id',
            ]);

            $query = MonthlyDeductionLog::whereHas('deduction', function($q) use ($groupId) {
                $q->where('group_id', $groupId);
            });

            // Filter by date range
            if (isset($validated['start_date'])) {
                $query->whereDate('scheduled_date', '>=', $validated['start_date']);
            }
            if (isset($validated['end_date'])) {
                $query->whereDate('scheduled_date', '<=', $validated['end_date']);
            }

            // Filter by specific deduction
            if (isset($validated['deduction_id'])) {
                $query->where('deduction_id', $validated['deduction_id']);
            }

            $logs = $query->with(['deduction', 'member'])->orderBy('scheduled_date', 'desc')->get();

            $summary = [
                'total_attempted' => $logs->sum('amount_attempted'),
                'total_deducted' => $logs->sum('amount_deducted'),
                'total_runs' => $logs->count(),
                'success_count' => $logs->where('status', 'success')->count(),
                'partial_count' => $logs->where('status', 'partial')->count(),
                'failed_count' => $logs->whereIn('status', ['failed', 'insufficient_balance', 'skipped'])->count(),
            ];

            return response()->json([
                'summary' => $summary,
                'logs' => $logs,
            ]);
        } catch (\Exception $e) {
            Log::error('Group Deduction History Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch deduction history',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
