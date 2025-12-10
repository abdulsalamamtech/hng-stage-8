<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Paystack;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\info;
use function Symfony\Component\Clock\now;

class WalletController extends Controller
{
    public function deposit(Request $request)
    {

        $data = $request->validate([
            'amount' => 'required|numeric|min:100', // Minimum deposit of 100 units
        ]);
        $amountInKobo = $data['amount'] * 100;
        $reference = Str::random(12);
        $user = $request->user();

        DB::beginTransaction();
        try {
            // // 1. Record pending transaction for idempotency
            // $user->wallet->transactions()->create([
            //     'type' => 'deposit',
            //     'amount' => $request->amount,
            //     'reference' => $reference,
            //     'status' => 'pending',
            // ]);

            // 2. Initialize Paystack
            $paystackData = [
                'name' => $user->name,
                'amount' => $amountInKobo,
                'email' => $user->email,
                'reference' => $reference,
                'payment_id' => Str::uuid(),
                // callback_url
                'callback_url' => env('APP_URL') . '/api/wallet/paystack/webhook', // Optional: Paystack often prefers server-to-server webhook
                'metadata' => [
                    'user_id' => $user->id,
                ],
            ];

            // $response = Paystack::getAuthorizationUrl($paystackData)->toArray();
            $PSP = Paystack::make($paystackData);

            if ($PSP['success']) {
                // 1. Record pending transaction for idempotency
                $user->wallet->transactions()->create([
                    'type' => 'deposit',
                    'amount' => $data['amount'],
                    'reference' => $PSP['reference'],
                    'status' => 'pending',
                ]);
                // Payment link
                $response = [
                    'amount' => $data['amount'] ?? ($amountInKobo / 100),
                    'reference' => $PSP['reference'],
                    'authorization_url' => $PSP['authorization_url'],
                ];

                // commit transaction
                DB::commit();

                // return response
                return ApiResponse::success($response, 'Dedicated payment link created successfully, please make payment to validate your deposit!', 201, $response);
            } else {
                info('payment initialization error: ' . $PSP['message']);
                return ApiResponse::error([], 'Error: unable to initialize payment process!', 500);
            }
            DB::commit();

            return response()->json([
                'reference' => $reference,
                'authorization_url' => $response['data']['authorization_url'],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Deposit initialization failed.'], 500);
        }
    }

    public function handlePaystackWebhook(Request $request)
    {
        // Timestamp the webhook receipt
        info('Paystack Webhook Timestamp: ' . now());
        info('Request all: ' . json_encode($request->all()));
        info('Paystack Webhook Received: ' . $request->getContent());

        // 1. Signature Validation (Security)
        $paystackSignatureOne = $request->header('x-paystack-signature');
        info('Paystack Signature Header: ' . $paystackSignatureOne);
        $paystackSignature = $request->header('HTTP_X_PAYSTACK_SIGNATURE');
        info('Paystack Signature Header (HTTP_X_PAYSTACK_SIGNATURE): ' . $paystackSignature);

        $secret = config('services.paystack.secret');

        if ($paystackSignature !== hash_hmac('sha512', $request->getContent(), $secret) || $paystackSignatureOne !== hash_hmac('sha512', $request->getContent(), $secret)) {
            Log::warning('Paystack Webhook: Invalid Signature.', $request->all());
            return response()->json(['status' => false], 403);
        }

        $event = $request->event;
        $data = $request->data;
        $reference = $data['reference'];

        // 2. Handle 'charge.success'
        if ($event === 'charge.success') {
            // Atomic & Idempotency Check
            DB::beginTransaction();
            try {
                $transaction = Transaction::where('reference', $reference)->lockForUpdate()->first();

                if (!$transaction || $transaction->status !== 'pending') {
                    info('Transaction already processed or not found for reference: ' . $reference);
                    // Already processed (idempotency), or transaction not found/invalid.
                    DB::commit(); // Always acknowledge 200 OK to Paystack
                    return response()->json(['status' => true]);
                }

                // 3. Update Transaction and Credit Wallet
                $transaction->status = 'success';
                $transaction->save();

                $wallet = $transaction->wallet()->lockForUpdate()->first();
                $wallet->balance += $data['amount'] / 100; // Amount is in kobo/cent
                $wallet->save();
                info('Wallet credited with amount: ' . ($data['amount'] / 100) . ' for reference: ' . $reference);

                DB::commit();
                Log::info("Wallet credited successfully for reference: " . $reference);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Webhook failed to process reference $reference: " . $e->getMessage());
                return response()->json(['status' => false], 500); // Trigger Paystack retry
            }
        }

        return response()->json(['status' => true]); // Always return 200 OK to Paystack
    }


    public function transfer(Request $request)
    {
        // ... validation for amount and wallet_number ...
        $data = $request->validate([
            'amount' => 'required|numeric|min:10', // Minimum transfer of 100 units
            'wallet_number' => 'required|exists:wallets,id', // Assuming wallet_number maps to wallet id
        ]);

        $senderWallet = $request->user()->wallet;
        $recipientWallet = Wallet::where('id', $request->wallet_number)->first();
        $amount = $request->amount;
        $reference = Str::uuid(); // Unique reference for the transfer pair

        if (!$recipientWallet) {
            return ApiResponse::error([], 'Recipient wallet not found.', 404);
        }
        if ($senderWallet->balance < $amount) {
            return ApiResponse::error([], 'Insufficient balance.', 403);
        }

        DB::transaction(function () use ($senderWallet, $recipientWallet, $amount, $reference) {
            // 1. Debit Sender
            $senderWallet->decrement('balance', $amount);
            $senderWallet->transactions()->create([
                'type' => 'transfer',
                'amount' => $amount,
                'reference' => $reference . date('YmdHis'),
                'recipient_wallet_id' => $recipientWallet->id,
                'status' => 'success',
            ]);

            // 2. Credit Recipient
            $recipientWallet->increment('balance', $amount);
            $recipientWallet->transactions()->create([
                'type' => 'receive',
                'amount' => $amount,
                'reference' => $reference . date('YmdHis') . rand(100, 999),
                'recipient_wallet_id' => $senderWallet->id, // Store sender's wallet for history
                'status' => 'success',
            ]);
        });

        $response = [
            'amount' => $amount,
            'reference' => $reference,
            'sender_wallet_balance' => $senderWallet->balance,
        ];

        // return response()->json(['status' => 'success', 'message' => 'Transfer completed']);
        return ApiResponse::success($response, 'Transfer completed successfully.', 200);
    }


    // verify deposit status
    public function verifyPayment(Request $request)
    {
        $data = $request->validate([
            'reference' => 'required|string',
        ]);

        $reference = $data['reference'];
        $transaction = Transaction::where('reference', $reference)->first();

        if (!$transaction) {
            return ApiResponse::error([], 'Transaction not found.', 404);
        }

        $PSP = Paystack::verify($reference);
        if (!$PSP['success']) {
            return ApiResponse::error([], 'Transaction verification failed: ' . $PSP['message'], 500);
        }

        info('Paystack Verification Response: ' . json_encode($PSP));

        $transaction->status = $PSP['data']['status'];
        $transaction->save();

        $response = [
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'amount' => $transaction->amount,
            'type' => $transaction->type,
            'created_at' => $transaction->created_at,
        ];

        return ApiResponse::success($response, 'Transaction retrieved successfully.', 200);
    }


    public function getBalance(Request $request)
    {
        $wallet = $request->user()->wallet;
        return ApiResponse::success([
            'balance' => $wallet->balance,
            'currency' => 'NGN', // Assuming NGN, adjust as needed
        ], 'Wallet balance retrieved successfully.', 200);
    }

    public function getTransactions(Request $request)
    {
        $wallet = $request->user()->wallet;
        $transactions = $wallet->transactions()->orderBy('created_at', 'desc')->get();

        return ApiResponse::success($transactions, 'Transaction history retrieved successfully.', 200);
    }


    public function verifyDepositStatus($reference)
    {
        $transaction = Transaction::where('reference', $reference)->first();
        if (!$transaction) {
            return ApiResponse::error([], 'Transaction not found.', 404);
        }
        return ApiResponse::success([
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'amount' => $transaction->amount,
            'type' => $transaction->type,
            'created_at' => $transaction->created_at,
        ], 'Transaction retrieved successfully.', 200);
    }
}
