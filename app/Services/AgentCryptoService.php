<?php

namespace App\Services;

use App\Models\AgentCryptoDeposit;
use App\Models\AgentTier;
use App\Models\AgentTransfer;
use App\Models\AgentWallet;
use App\Models\MonetizationSetting;
use App\Models\RechargeAgent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalCode;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AgentCryptoService
{
    public function getLimits(): array
    {
        $setting = MonetizationSetting::where('setting_key', 'agent_limits')->first();
        return $setting?->setting_value ?? [
            'daily_deposit_limit' => 5000,
            'daily_withdrawal_limit' => 5000,
            'daily_internal_transfer_limit' => 10000,
            'minimum_agent_reload' => 100,
            'recommended_network' => 'USDT TRC20',
        ];
    }

    public function getAgentForUser(User $user): ?RechargeAgent
    {
        return RechargeAgent::with(['wallet', 'tier'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    public function setupAgentAccount(RechargeAgent $agent, ?int $userId = null): void
    {
        $bronze = AgentTier::where('name', 'Bronze')->first();

        if ($userId) {
            $agent->user_id = $userId;
            User::where('id', $userId)->update(['role' => 'agent']);
        }

        $agent->agent_tier_id = $agent->agent_tier_id ?? $bronze?->id;
        $agent->kyc_verified = true;
        $agent->contract_signed_at = $agent->contract_signed_at ?? now();
        $agent->save();

        AgentWallet::firstOrCreate(
            ['recharge_agent_id' => $agent->id],
            [
                'balance' => 0,
                'locked_balance' => 0,
                'guarantee_deposit' => $bronze?->requires_guarantee_amount ?? 0,
                'currency' => 'USDT',
            ]
        );
    }

    public function requestCryptoDeposit(RechargeAgent $agent, float $amount, string $txHash, string $network = 'USDT TRC20'): AgentCryptoDeposit
    {
        $limits = $this->getLimits();
        if ($amount < ($limits['minimum_agent_reload'] ?? 100)) {
            throw new \InvalidArgumentException('Montant minimum de recharge : ' . ($limits['minimum_agent_reload'] ?? 100) . ' USDT');
        }

        return AgentCryptoDeposit::create([
            'recharge_agent_id' => $agent->id,
            'amount' => $amount,
            'crypto_network' => $network,
            'tx_hash' => $txHash,
            'status' => 'pending',
        ]);
    }

    public function approveCryptoDeposit(AgentCryptoDeposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            $wallet = AgentWallet::firstOrCreate(['recharge_agent_id' => $deposit->recharge_agent_id]);
            $wallet->balance += $deposit->amount;
            $wallet->total_deposited += $deposit->amount;
            $wallet->save();

            $deposit->update(['status' => 'approved', 'credited_at' => now()]);
        });
    }

    public function depositToPlayer(RechargeAgent $agent, User $player, float $amountEbt): AgentTransfer
    {
        $limits = $this->getLimits();
        $this->assertDailyLimit($agent, 'deposit_to_player', $amountEbt, $limits['daily_internal_transfer_limit'] ?? 10000);

        return DB::transaction(function () use ($agent, $player, $amountEbt) {
            $agentWallet = $agent->wallet ?? AgentWallet::where('recharge_agent_id', $agent->id)->firstOrFail();
            $playerWallet = Wallet::firstOrCreate(['user_id' => $player->id], ['balance' => 0, 'locked_balance' => 0, 'currency' => 'EBT']);

            if ($agentWallet->availableBalance() < $amountEbt) {
                throw new \RuntimeException('Solde agent insuffisant. Disponible : ' . number_format($agentWallet->availableBalance(), 2) . ' USDT');
            }

            $commission = $this->calcCommission($agent, 'deposit', $amountEbt);

            $agentWallet->balance -= $amountEbt;
            $agentWallet->total_transferred += $amountEbt;
            $agentWallet->save();

            $playerWallet->balance += $amountEbt;
            $playerWallet->save();

            Transaction::create([
                'wallet_id' => $playerWallet->id,
                'user_id' => $player->id,
                'type' => 'agent_deposit',
                'amount' => $amountEbt,
                'status' => 'completed',
                'provider' => 'agent',
                'meta' => ['agent_id' => $agent->id, 'agent_name' => $agent->name],
            ]);

            return AgentTransfer::create([
                'recharge_agent_id' => $agent->id,
                'user_id' => $player->id,
                'type' => 'deposit_to_player',
                'amount' => $amountEbt,
                'commission' => $commission,
                'status' => 'completed',
                'reference' => 'ADP-' . strtoupper(uniqid()),
            ]);
        });
    }

    public function lockPlayerFundsForWithdrawal(User $player, float $amountEbt): void
    {
        $wallet = Wallet::where('user_id', $player->id)->firstOrFail();
        $available = (float) $wallet->balance - (float) $wallet->locked_balance;

        if ($available < $amountEbt) {
            throw new \RuntimeException('Solde insuffisant pour le retrait');
        }

        $wallet->locked_balance += $amountEbt;
        $wallet->save();
    }

    public function completeWithdrawalViaAgent(WithdrawalCode $code, RechargeAgent $agent): AgentTransfer
    {
        return DB::transaction(function () use ($code, $agent) {
            $player = User::findOrFail($code->user_id);
            $playerWallet = Wallet::where('user_id', $player->id)->firstOrFail();
            $amountEbt = (float) $code->amount;

            if ((float) $playerWallet->locked_balance < $amountEbt) {
                throw new \RuntimeException('Montant non bloqué sur le compte joueur');
            }

            $playerWallet->locked_balance -= $amountEbt;
            $playerWallet->balance -= $amountEbt;
            $playerWallet->save();

            $agentWallet = $agent->wallet ?? AgentWallet::firstOrCreate(['recharge_agent_id' => $agent->id]);
            $agentWallet->balance += $amountEbt;
            $agentWallet->save();

            Transaction::create([
                'wallet_id' => $playerWallet->id,
                'user_id' => $player->id,
                'type' => 'agent_withdrawal',
                'amount' => -$amountEbt,
                'status' => 'completed',
                'provider' => 'agent',
                'meta' => ['agent_id' => $agent->id, 'code' => $code->code],
            ]);

            $commission = $this->calcCommission($agent, 'withdrawal', $amountEbt);

            return AgentTransfer::create([
                'recharge_agent_id' => $agent->id,
                'user_id' => $player->id,
                'type' => 'withdrawal_from_player',
                'amount' => $amountEbt,
                'commission' => $commission,
                'status' => 'completed',
                'reference' => $code->code,
                'withdrawal_code_id' => $code->id,
            ]);
        });
    }

    public function releaseLockedFunds(WithdrawalCode $code): void
    {
        $wallet = Wallet::where('user_id', $code->user_id)->first();
        if (!$wallet) return;

        $wallet->locked_balance = max(0, (float) $wallet->locked_balance - (float) $code->amount);
        $wallet->save();
    }

    protected function calcCommission(RechargeAgent $agent, string $type, float $amount): float
    {
        $tier = $agent->tier;
        if (!$tier) return 0;

        $pct = $type === 'deposit'
            ? (float) $tier->deposit_commission_percentage
            : (float) $tier->withdrawal_commission_percentage;

        return round($amount * $pct / 100, 2);
    }

    protected function assertDailyLimit(RechargeAgent $agent, string $type, float $amount, float $limit): void
    {
        $today = AgentTransfer::where('recharge_agent_id', $agent->id)
            ->where('type', $type)
            ->whereDate('created_at', Carbon::today())
            ->sum('amount');

        if ($today + $amount > $limit) {
            throw new \RuntimeException('Limite journalière atteinte (' . number_format($limit, 2) . ' USDT)');
        }
    }
}
