<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\CustomerInfoController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\GroupPolicyController;
use App\Http\Controllers\WalletReportController;
use App\Http\Controllers\MonthlyDeductionController;
use App\Http\Controllers\MobileMoneyController;

Route::get('/hello', function () {
    return ['status' => 'ok'];
});

// Auth routes (public)
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/register', [AuthController::class, 'register']);

// Public customer info lookup
Route::get('customer-info', [CustomerInfoController::class, 'show']);

// Protected routes requiring authentication
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    
    Route::apiResource('groups', GroupController::class);
    Route::apiResource('members', MemberController::class);
    
    // Group Policy routes
    Route::get('group-policy/{groupId}', [GroupPolicyController::class, 'getPolicy'])->middleware('role:super_admin,group_admin,group_user');
    Route::put('group-policy/{groupId}', [GroupPolicyController::class, 'updatePolicy'])->middleware('role:super_admin,group_admin');
    
    Route::post('payments/initiate', [PaymentController::class, 'initiatePayment']);
    Route::get('payments/check-status/{transactionId}', [PaymentController::class, 'checkStatus']);
    Route::get('payments/transactions', [PaymentController::class, 'getTransactions']);
    Route::get('payments/transactions/{transactionId}', [PaymentController::class, 'getTransaction']);
    
    // Wallet routes (with group access control)
    Route::get('wallets/member/{memberId}', [WalletController::class, 'getWallet']);
    Route::get('wallets/group/{groupId}', [WalletController::class, 'getGroupWallets'])->middleware('group.access');
    Route::post('wallets/topup', [WalletController::class, 'topup'])->middleware('role:super_admin,group_admin,group_user');
    Route::post('wallets/cashout', [WalletController::class, 'cashout'])->middleware('role:super_admin,group_admin,group_user');
    Route::get('wallets/transactions/{memberId}', [WalletController::class, 'getTransactions']);
    
    // Withdrawal requests (with group access control)
    Route::post('withdrawals', [WalletController::class, 'createWithdrawalRequest'])->middleware('role:member,super_admin,group_admin');
    Route::get('withdrawals/group/{groupId}', [WalletController::class, 'getWithdrawalRequests'])->middleware('group.access');
    Route::post('withdrawals/{id}/approve', [WalletController::class, 'approveWithdrawal'])->middleware('role:super_admin,group_admin');
    Route::post('withdrawals/{id}/reject', [WalletController::class, 'rejectWithdrawal'])->middleware('role:super_admin,group_admin');
    
    // Monthly deductions (with group access control)
    Route::get('deductions/group/{groupId}', [WalletController::class, 'getMonthlyDeductions'])->middleware('group.access');
    Route::post('deductions', [WalletController::class, 'createMonthlyDeduction'])->middleware('role:super_admin,group_admin');
    Route::put('deductions/{id}', [WalletController::class, 'updateMonthlyDeduction'])->middleware('role:super_admin,group_admin');
    Route::delete('deductions/{id}', [WalletController::class, 'deleteMonthlyDeduction'])->middleware('role:super_admin,group_admin');
    
    // Deduction logs (with group access control)
    Route::get('deduction-logs/group/{groupId}', [WalletController::class, 'getDeductionLogs'])->middleware('role:super_admin,group_admin,group_user')->middleware('group.access');
    Route::get('deduction-summary/{deductionId}', [MonthlyDeductionController::class, 'getDeductionSummary'])->middleware('role:super_admin,group_admin');
    Route::get('deduction-target-summary/{deductionId}', [MonthlyDeductionController::class, 'getTargetAccountSummary'])->middleware('role:super_admin,group_admin');
    Route::get('deduction-history/group/{groupId}', [MonthlyDeductionController::class, 'getGroupDeductionHistory'])->middleware('role:super_admin,group_admin,group_user')->middleware('group.access');
    
    // Reports (with group access control)
    Route::get('reports/system', [ReportController::class, 'getSystemReport'])->middleware('role:super_admin');
    Route::get('reports/my', [ReportController::class, 'getMyReport'])->middleware('role:member');
    Route::get('reports/group/{groupId}', [ReportController::class, 'getGroupReport'])->middleware('role:super_admin,group_admin,group_user')->middleware('group.access');
    Route::get('reports/member/{memberId}', [ReportController::class, 'getMemberReport']);
    Route::get('reports/audit', [ReportController::class, 'getAuditLog'])->middleware('role:super_admin,group_admin');
    
    // Wallet Reports
    Route::get('wallet-reports/topup', [WalletReportController::class, 'getTopUpReport'])->middleware('role:super_admin,group_admin,group_user');
    Route::get('wallet-reports/cashout', [WalletReportController::class, 'getCashOutReport'])->middleware('role:super_admin,group_admin,group_user');
    Route::get('wallet-reports/group-summary/{groupId}', [WalletReportController::class, 'getGroupWalletSummary'])->middleware('role:super_admin,group_admin,group_user')->middleware('group.access');
    Route::get('wallet-reports/member-statement/{memberId}', [WalletReportController::class, 'getMemberStatement']);
    
    // Mobile Money
    Route::post('mobile-money/withdraw', [MobileMoneyController::class, 'initiateWithdrawal'])->middleware('role:member,super_admin,group_admin');
    Route::get('mobile-money/status/{transactionId}', [MobileMoneyController::class, 'checkStatus']);
});

// Public route for MoPay callbacks
Route::post('mobile-money/callback', [MobileMoneyController::class, 'handleCallback']);