<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\MonthlyDeduction;
use App\Models\Member;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\MonthlyDeductionLog;
use Carbon\Carbon;

class ProcessMonthlyDeductions extends Command
{
    protected $signature = 'deductions:process {--force : Force run regardless of day}';
    protected $description = 'Process monthly deductions for all active groups';

    public function handle()
    {
        $this->info('Starting monthly deductions processing...');
        
        $today = Carbon::today();
        $dayOfMonth = $today->day;
        
        // Get all active deduction rules that should run today (or force run all)
        $query = MonthlyDeduction::where('is_active', true);
        
        if (!$this->option('force')) {
            $query->where('run_day_of_month', $dayOfMonth);
        }
        
        $deductions = $query->with('group')->get();
        
        if ($deductions->isEmpty()) {
            $this->info('No deductions scheduled for today (day ' . $dayOfMonth . ')');
            return 0;
        }
        
        $this->info('Found ' . $deductions->count() . ' deduction rules to process');
        
        $totalProcessed = 0;
        $totalSuccess = 0;
        $totalPartial = 0;
        $totalFailed = 0;
        
        foreach ($deductions as $deduction) {
            $this->info("\nProcessing: {$deduction->name} for Group {$deduction->group->name}");
            
            $result = $this->processDeduction($deduction, $today);
            
            $totalProcessed += $result['processed'];
            $totalSuccess += $result['success'];
            $totalPartial += $result['partial'];
            $totalFailed += $result['failed'];
            
            $this->info("  Processed: {$result['processed']}, Success: {$result['success']}, Partial: {$result['partial']}, Failed: {$result['failed']}");
        }
        
        $this->info("\n=== Summary ===");
        $this->info("Total members processed: {$totalProcessed}");
        $this->info("Successful deductions: {$totalSuccess}");
        $this->info("Partial deductions: {$totalPartial}");
        $this->info("Failed deductions: {$totalFailed}");
        
        return 0;
    }
    
    private function processDeduction(MonthlyDeduction $deduction, Carbon $scheduledDate)
    {
        $stats = [
            'processed' => 0,
            'success' => 0,
            'partial' => 0,
            'failed' => 0,
        ];
        
        // Get all members in this group
        $members = Member::where('group_id', $deduction->group_id)
            ->where('is_active', true)
            ->with('wallet')
            ->get();
        
        foreach ($members as $member) {
            $stats['processed']++;
            
            if (!$member->wallet) {
                $this->warn("  Member {$member->id} has no wallet - skipping");
                $this->logDeduction($deduction, $member, null, $scheduledDate, 0, 0, 'skipped', 'No wallet found');
                $stats['failed']++;
                continue;
            }
            
            $result = $this->deductFromWallet($deduction, $member, $member->wallet, $scheduledDate);
            
            if ($result['status'] === 'success') {
                $stats['success']++;
            } elseif ($result['status'] === 'partial') {
                $stats['partial']++;
            } else {
                $stats['failed']++;
            }
        }
        
        return $stats;
    }
    
    private function deductFromWallet(MonthlyDeduction $deduction, Member $member, Wallet $wallet, Carbon $scheduledDate)
    {
        DB::beginTransaction();
        
        try {
            // Calculate deduction amount based on type
            if ($deduction->type === 'percentage_of_balance') {
                $deductionAmount = ($wallet->balance * $deduction->percentage) / 100;
            } else {
                $deductionAmount = $deduction->amount;
            }
            
            $currentBalance = $wallet->balance;
            
            // Check if sufficient balance for full deduction
            if ($currentBalance >= $deductionAmount) {
                // Full deduction
                $balanceBefore = $wallet->balance;
                $wallet->balance -= $deductionAmount;
                $wallet->save();
                
                // Create wallet transaction
                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'member_id' => $member->id,
                    'transaction_type' => 'monthly_deduction',
                    'amount' => $deductionAmount,
                    'direction' => 'debit',
                    'balance_before' => $balanceBefore,
                    'balance_after' => $wallet->balance,
                    'description' => "Monthly deduction: {$deduction->name}",
                    'reference' => "DEDUCTION-{$deduction->id}-" . $scheduledDate->format('Y-m'),
                    'created_by' => 'system',
                    'initiated_by' => 'system',
                    'status' => 'completed',
                    'processed_at' => now(),
                ]);
                
                // Log success
                $this->logDeduction(
                    $deduction,
                    $member,
                    $wallet,
                    $scheduledDate,
                    $deductionAmount,
                    $deductionAmount,
                    'success',
                    'Deduction processed successfully'
                );
                
                DB::commit();
                
                return [
                    'status' => 'success',
                    'amount_deducted' => $deductionAmount,
                ];
                
            } elseif ($currentBalance > 0) {
                // Partial deduction - deduct available balance
                $amountDeducted = $currentBalance;
                $balanceBefore = $wallet->balance;
                $wallet->balance = 0;
                $wallet->save();
                
                // Create wallet transaction
                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'member_id' => $member->id,
                    'transaction_type' => 'monthly_deduction',
                    'amount' => $amountDeducted,
                    'direction' => 'debit',
                    'balance_before' => $balanceBefore,
                    'balance_after' => $wallet->balance,
                    'description' => "Partial monthly deduction: {$deduction->name}",
                    'reference' => "DEDUCTION-{$deduction->id}-" . $scheduledDate->format('Y-m'),
                    'created_by' => 'system',
                    'initiated_by' => 'system',
                    'status' => 'completed',
                    'processed_at' => now(),
                ]);
                
                // Log partial
                $this->logDeduction(
                    $deduction,
                    $member,
                    $wallet,
                    $scheduledDate,
                    $deductionAmount,
                    $amountDeducted,
                    'partial',
                    "Partial deduction: {$amountDeducted} of {$deductionAmount} deducted"
                );
                
                DB::commit();
                
                return [
                    'status' => 'partial',
                    'amount_deducted' => $amountDeducted,
                ];
                
            } else {
                // Insufficient balance
                DB::rollBack();
                
                // Log as insufficient balance
                $this->logDeduction(
                    $deduction,
                    $member,
                    $wallet,
                    $scheduledDate,
                    $deductionAmount,
                    0,
                    'insufficient_balance',
                    "Balance: {$currentBalance}, Required: {$deductionAmount}"
                );
                
                return [
                    'status' => 'insufficient_balance',
                    'amount_deducted' => 0,
                ];
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Deduction processing error', [
                'deduction_id' => $deduction->id,
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->logDeduction(
                $deduction,
                $member,
                $wallet,
                $scheduledDate,
                $deduction->amount,
                0,
                'failed',
                'Error: ' . $e->getMessage()
            );
            
            return [
                'status' => 'failed',
                'amount_deducted' => 0,
            ];
        }
    }
    
    private function logDeduction(
        MonthlyDeduction $deduction,
        Member $member,
        ?Wallet $wallet,
        Carbon $scheduledDate,
        float $amountAttempted,
        float $amountDeducted,
        string $status,
        string $note
    ) {
        MonthlyDeductionLog::create([
            'deduction_id' => $deduction->id,
            'member_id' => $member->id,
            'wallet_id' => $wallet?->id,
            'scheduled_date' => $scheduledDate,
            'actual_run_date' => now(),
            'amount_attempted' => $amountAttempted,
            'amount_deducted' => $amountDeducted,
            'status' => $status,
            'note' => $note,
        ]);
    }
}
