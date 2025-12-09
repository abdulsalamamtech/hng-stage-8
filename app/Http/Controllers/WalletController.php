<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

class WalletController extends Controller
{
    public function deposit(Request $request)
    {
        $amountInKobo = $request->amount * 100;
        $reference = Str::random(12);
        $user = $request->user();

        DB::beginTransaction();
        try {
            // 1. Record pending transaction for idempotency
            $user->wallet->transactions()->create([
                'type' => 'deposit',
                'amount' => $request->amount,
                'reference' => $reference,
                'status' => 'pending',
            ]);

            // 2. Initialize Paystack
            $paystackData = [
                'amount' => $amountInKobo,
                'email' => $user->email,
                'reference' => $reference,
                'callback_url' => env('APP_URL') . '/api/wallet/paystack/webhook', // Optional: Paystack often prefers server-to-server webhook
                'metadata' => [
                    'user_id' => $user->id,
                ],
            ];

            // $response = Paystack::getAuthorizationUrl($paystackData)->toArray();
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
        // 1. Signature Validation (Security)
        $paystackSignature = $request->header('x-paystack-signature');
        $secret = config('paystack.secretKey');

        if ($paystackSignature !== hash_hmac('sha512', $request->getContent(), $secret)) {
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

        $senderWallet = $request->user()->wallet;
        $recipientWallet = Wallet::where('id', $request->wallet_number)->first();
        $amount = $request->amount;
        $reference = Str::uuid(); // Unique reference for the transfer pair

        if (!$recipientWallet) {
            return response()->json(['error' => 'Recipient wallet not found.'], 404);
        }
        if ($senderWallet->balance < $amount) {
            return response()->json(['error' => 'Insufficient balance.'], 403);
        }

        DB::transaction(function () use ($senderWallet, $recipientWallet, $amount, $reference) {
            // 1. Debit Sender
            $senderWallet->decrement('balance', $amount);
            $senderWallet->transactions()->create([
                'type' => 'transfer_out',
                'amount' => $amount,
                'reference' => $reference,
                'recipient_wallet_id' => $recipientWallet->id,
                'status' => 'success',
            ]);

            // 2. Credit Recipient
            $recipientWallet->increment('balance', $amount);
            $recipientWallet->transactions()->create([
                'type' => 'transfer_in',
                'amount' => $amount,
                'reference' => $reference,
                'recipient_wallet_id' => $senderWallet->id, // Store sender's wallet for history
                'status' => 'success',
            ]);
        });

        return response()->json(['status' => 'success', 'message' => 'Transfer completed']);
    }
}
