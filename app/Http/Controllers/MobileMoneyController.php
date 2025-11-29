<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Models\Member;

class MobileMoneyController extends Controller
{
    private $mopayBaseUrl = 'https://api.mopay.rw';
    
    // Initiate mobile money withdrawal
    public function initiateWithdrawal(Request $request)
    {
        try {
            $validated = $request->validate([
                'member_id' => 'required|exists:members,id',
                'amount' => 'required|numeric|min:100', // Minimum 100 RWF
                'phone' => 'required|string',
                'created_by' => 'required|string',
            ]);

            $member = Member::with('wallet')->findOrFail($validated['member_id']);
            
            if (!$member->wallet) {
                return response()->json(['error' => 'Member has no wallet'], 404);
            }

            $wallet = $member->wallet;

            // Check balance
            if ($wallet->balance < $validated['amount']) {
                return response()->json([
                    'error' => 'Insufficient balance',
                    'current_balance' => $wallet->balance,
                    'requested_amount' => $validated['amount']
                ], 400);
            }

            DB::beginTransaction();

            // Create pending transaction
            $balanceBefore = $wallet->balance;
            $wallet->balance -= $validated['amount'];
            $wallet->save();

            // Create wallet transaction with pending status
            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'member_id' => $member->id,
                'transaction_type' => 'mobile_money_transfer',
                'amount' => $validated['amount'],
                'direction' => 'debit',
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->balance,
                'description' => 'Mobile money withdrawal',
                'reference' => 'MM-' . time() . '-' . $member->id,
                'created_by' => $validated['created_by'],
                'initiated_by' => 'member',
                'status' => 'pending',
            ]);

            // Create withdrawal request
            $withdrawalRequest = WithdrawalRequest::create([
                'member_id' => $member->id,
                'wallet_id' => $wallet->id,
                'amount' => $validated['amount'],
                'phone' => $validated['phone'],
                'status' => 'pending',
                'created_by' => $validated['created_by'],
            ]);

            // Call MoPay API
            try {
                $mopayResponse = $this->callMoPayAPI([
                    'amount' => $validated['amount'],
                    'currency' => 'RWF',
                    'phone' => $validated['phone'],
                    'payment_mode' => 'withdrawal',
                    'message' => 'Wallet withdrawal',
                    'callback_url' => url('/api/mopay/callback'),
                ]);

                if ($mopayResponse['success']) {
                    // Update transaction with MoPay reference
                    $transaction->update([
                        'reference' => $mopayResponse['transaction_id'],
                        'notes' => 'MoPay transaction initiated',
                    ]);

                    $withdrawalRequest->update([
                        'status' => 'processing',
                        'reference_number' => $mopayResponse['transaction_id'],
                    ]);

                    DB::commit();

                    return response()->json([
                        'message' => 'Withdrawal initiated successfully',
                        'transaction_id' => $transaction->id,
                        'mopay_transaction_id' => $mopayResponse['transaction_id'],
                        'status' => 'processing',
                    ]);
                } else {
                    throw new \Exception($mopayResponse['message'] ?? 'MoPay API error');
                }

            } catch (\Exception $e) {
                // MoPay API failed - reverse the transaction
                DB::rollBack();
                
                // Start new transaction to reverse
                DB::beginTransaction();
                
                // Restore balance
                $wallet->balance = $balanceBefore;
                $wallet->save();

                // Update transaction status
                $transaction->update([
                    'status' => 'failed',
                    'notes' => 'MoPay API error: ' . $e->getMessage(),
                ]);

                // Create reversal transaction
                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'member_id' => $member->id,
                    'transaction_type' => 'adjustment',
                    'amount' => $validated['amount'],
                    'direction' => 'credit',
                    'balance_before' => $wallet->balance - $validated['amount'],
                    'balance_after' => $wallet->balance,
                    'description' => 'Reversal: Mobile money withdrawal failed',
                    'reference' => 'REV-' . $transaction->reference,
                    'created_by' => 'system',
                    'initiated_by' => 'system',
                    'status' => 'completed',
                    'processed_at' => now(),
                ]);

                $withdrawalRequest->update([
                    'status' => 'failed',
                    'notes' => 'MoPay API error: ' . $e->getMessage(),
                ]);

                DB::commit();

                return response()->json([
                    'error' => 'Withdrawal failed',
                    'message' => 'Failed to process mobile money withdrawal. Your balance has been restored.',
                    'details' => $e->getMessage(),
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Mobile Money Withdrawal Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to initiate withdrawal',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // MoPay callback handler
    public function handleCallback(Request $request)
    {
        try {
            Log::info('MoPay Callback Received', $request->all());

            $transactionId = $request->input('transaction_id');
            $status = $request->input('status'); // success, failed, pending
            
            $transaction = WalletTransaction::where('reference', $transactionId)->first();
            
            if (!$transaction) {
                Log::warning('Transaction not found for callback', ['transaction_id' => $transactionId]);
                return response()->json(['message' => 'Transaction not found'], 404);
            }

            DB::beginTransaction();

            if ($status === 'success') {
                // Update transaction to success
                $transaction->update([
                    'status' => 'completed',
                    'processed_at' => now(),
                    'notes' => 'Mobile money withdrawal successful',
                ]);

                // Update withdrawal request
                WithdrawalRequest::where('reference_number', $transactionId)
                    ->update([
                        'status' => 'approved',
                        'approved_at' => now(),
                        'approved_by' => 'mopay_system',
                    ]);

            } elseif ($status === 'failed') {
                // Reverse the deduction
                $wallet = $transaction->wallet;
                $wallet->balance += $transaction->amount;
                $wallet->save();

                // Update transaction to failed
                $transaction->update([
                    'status' => 'failed',
                    'processed_at' => now(),
                    'notes' => 'Mobile money withdrawal failed - balance reversed',
                ]);

                // Create reversal transaction
                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'member_id' => $transaction->member_id,
                    'transaction_type' => 'adjustment',
                    'amount' => $transaction->amount,
                    'direction' => 'credit',
                    'balance_before' => $wallet->balance - $transaction->amount,
                    'balance_after' => $wallet->balance,
                    'description' => 'Reversal: Mobile money withdrawal failed',
                    'reference' => 'REV-' . $transactionId,
                    'created_by' => 'system',
                    'initiated_by' => 'system',
                    'status' => 'completed',
                    'processed_at' => now(),
                ]);

                // Update withdrawal request
                WithdrawalRequest::where('reference_number', $transactionId)
                    ->update([
                        'status' => 'rejected',
                        'notes' => 'Mobile money withdrawal failed',
                    ]);
            }

            DB::commit();

            return response()->json(['message' => 'Callback processed successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('MoPay Callback Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to process callback',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Check withdrawal status
    public function checkStatus($transactionId)
    {
        try {
            $transaction = WalletTransaction::where('reference', $transactionId)
                ->orWhere('id', $transactionId)
                ->with(['member', 'wallet'])
                ->first();

            if (!$transaction) {
                return response()->json(['error' => 'Transaction not found'], 404);
            }

            return response()->json([
                'transaction' => $transaction,
                'withdrawal_request' => WithdrawalRequest::where('reference_number', $transaction->reference)->first(),
            ]);
        } catch (\Exception $e) {
            Log::error('Check Status Error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to check status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Call MoPay API
    private function callMoPayAPI($data)
    {
        try {
            $token = env('MOPAY_API_TOKEN');
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])->post($this->mopayBaseUrl . '/initiate-payment', $data);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'transaction_id' => $response->json('transaction_id'),
                    'message' => $response->json('message'),
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $response->json('message') ?? 'API request failed',
                ];
            }
        } catch (\Exception $e) {
            Log::error('MoPay API Error', ['message' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
