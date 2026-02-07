<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Console\Command;

class CreditUsers extends Command
{
    protected $signature = 'users:credit {emails} {amount}';
    protected $description = 'Credit user wallets by email (comma-separated)';

    public function handle()
    {
        $emails = array_map('trim', explode(',', $this->argument('emails')));
        $amount = (float) $this->argument('amount');

        foreach ($emails as $email) {
            $user = User::where('email', $email)->first();
            if (!$user) {
                $this->error("User not found: {$email}");
                continue;
            }

            $wallet = $user->wallet;
            if (!$wallet) {
                $wallet = $user->wallet()->create([
                    'balance' => 0,
                    'locked_balance' => 0,
                    'currency' => 'EBT',
                ]);
            }

            $wallet->balance += $amount;
            $wallet->save();

            $user->transactions()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => 'deposit',
                'amount' => $amount,
                'status' => 'confirmed',
                'provider' => 'admin',
                'txid' => 'ADMIN_CREDIT_' . now()->format('YmdHis') . '_' . $user->id,
            ]);

            $this->info("Credited {$amount} EBT to {$email} (new balance: {$wallet->balance})");
        }

        return 0;
    }
}
