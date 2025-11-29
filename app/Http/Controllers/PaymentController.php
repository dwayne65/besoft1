<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Transaction;

class PaymentController extends Controller
{
    private function generateTransactionId()
    {
        return strtoupper(Str::random(6)) . time();
    }

    public function initiatePayment(Request $request)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:1',
                'currency' => 'required|string',
                'phone' => 'required|string',
                'payment_mode' => 'required|string',
                'message' => 'nullable|string',
                'callback_url' => 'nullable|string',
                'transfers' => 'nullable|array',
                'transfers.*.amount' => 'required_with:transfers|numeric|min:1',
                'transfers.*.phone' => 'required_with:transfers|string',
                'transfers.*.message' => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Payment Initiation Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Server error',
                'message' => $e->getMessage()
            ], 500);
        }

        $transactionId = $this->generateTransactionId();

        $payload = [
            'transaction_id' => $transactionId,
            'amount' => (int) $validated['amount'],
            'currency' => $validated['currency'],
            'phone' => (int) $validated['phone'],
            'payment_mode' => $validated['payment_mode'],
            'message' => $validated['message'] ?? 'Payment transaction',
        ];

        // Only add callback_url if it's provided
        if (!empty($validated['callback_url'])) {
            $payload['callback_url'] = $validated['callback_url'];
        }

        // Handle transfers with auto-generated transaction IDs
        if (isset($validated['transfers']) && !empty($validated['transfers'])) {
            $transfers = [];
            foreach ($validated['transfers'] as $transfer) {
                $transfers[] = [
                    'transaction_id' => $this->generateTransactionId(),
                    'amount' => (int) $transfer['amount'],
                    'phone' => 250796588225, // Static receiver phone number
                    'message' => $transfer['message'] ?? 'Transfer transaction',
                ];
            }
            $payload['transfers'] = $transfers;
        }

        $token = env('MOPAY_API_TOKEN', '2fuytPgoD4At0FE1MgoF08xuAr03xSvkJ1ZlGrT5jYFyolQsBU7XKU28OW4Oqq3a');

        // Save transaction to database
        try {
            $transaction = Transaction::create([
                'transaction_id' => $transactionId,
                'amount' => $payload['amount'],
                'currency' => $payload['currency'],
                'phone' => $payload['phone'],
                'payment_mode' => $payload['payment_mode'],
                'message' => $payload['message'],
                'callback_url' => $payload['callback_url'] ?? null,
                'status' => 201, // Pending
                'transfers' => $validated['transfers'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Database Error - Transaction Create', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Database error',
                'message' => 'Failed to create transaction record: ' . $e->getMessage()
            ], 500);
        }

        try {
            Log::info('Mopay Payment Request', ['payload' => $payload]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])->post('https://api.mopay.rw/initiate-payment', $payload);

            Log::info('Mopay Payment Response', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            // Update transaction with Mopay response
            $transaction->update([
                'mopay_response' => $response->json(),
                'status' => $response->status(),
            ]);

            if ($response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            return response()->json([
                'error' => 'Payment initiation failed',
                'details' => $response->json(),
                'status' => $response->status()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Mopay Payment Exception', ['message' => $e->getMessage()]);
            
            // Update transaction status to failed
            $transaction->update(['status' => 400]);
            
            return response()->json([
                'error' => 'Payment initiation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function checkStatus($transactionId)
    {
        $token = env('MOPAY_API_TOKEN');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])->get("https://api.mopay.rw/check-status/{$transactionId}");

            if ($response->successful()) {
                // Update local transaction record if exists
                try {
                    $transaction = Transaction::where('transaction_id', $transactionId)->first();
                    if ($transaction) {
                        $transaction->update([
                            'status' => $response->json()['status'] ?? $transaction->status,
                            'mopay_response' => $response->json(),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to update transaction in database', [
                        'message' => $e->getMessage()
                    ]);
                }
                
                return response()->json($response->json(), $response->status());
            }

            return response()->json([
                'error' => 'Status check failed',
                'details' => $response->json()
            ], $response->status());
        } catch (\Exception $e) {
            Log::error('Check Status Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Status check failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getTransactions()
    {
        try {
            $transactions = Transaction::orderBy('created_at', 'desc')->get();
            return response()->json($transactions);
        } catch (\Exception $e) {
            Log::error('Get Transactions Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to fetch transactions',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getTransaction($transactionId)
    {
        try {
            $transaction = Transaction::where('transaction_id', $transactionId)->first();
            
            if (!$transaction) {
                return response()->json([
                    'error' => 'Transaction not found'
                ], 404);
            }
            
            return response()->json($transaction);
        } catch (\Exception $e) {
            Log::error('Get Transaction Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to fetch transaction',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
