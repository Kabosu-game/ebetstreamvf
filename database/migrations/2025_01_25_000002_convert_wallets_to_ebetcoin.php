<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convertir toutes les balances de USD en EBT
        // 1 USD = 100 EBT
        // Donc multiplier toutes les balances par 100
        DB::table('wallets')->update([
            'balance' => DB::raw('balance * 100'),
            'locked_balance' => DB::raw('locked_balance * 100'),
            'currency' => 'EBT'
        ]);
        
        // Mettre à jour toutes les transactions pour convertir les montants
        // Multiplier les montants par 100 pour les transactions de type deposit, withdraw, bet, win
        DB::table('transactions')->whereIn('type', ['deposit', 'withdraw', 'bet', 'win', 'refund'])->update([
            'amount' => DB::raw('amount * 100')
        ]);
        
        // Mettre à jour les défis (challenges) : bet_amount en EBT
        if (Schema::hasTable('challenges')) {
            DB::table('challenges')->update([
                'bet_amount' => DB::raw('bet_amount * 100')
            ]);
        }
        
        // Mettre à jour les paris (bets) : amount et potential_win en EBT
        if (Schema::hasTable('bets')) {
            DB::table('bets')->update([
                'amount' => DB::raw('amount * 100'),
                'potential_win' => DB::raw('potential_win * 100')
            ]);
        }
        
        // Mettre à jour les dépôts (deposits) : amount en EBT
        if (Schema::hasTable('deposits')) {
            DB::table('deposits')->update([
                'amount' => DB::raw('amount * 100')
            ]);
        }
        
        // Mettre à jour les retraits (withdrawals) : amount en EBT
        if (Schema::hasTable('withdrawals')) {
            DB::table('withdrawals')->update([
                'amount' => DB::raw('amount * 100')
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convertir de EBT en USD (diviser par 100)
        DB::table('wallets')->update([
            'balance' => DB::raw('balance / 100'),
            'locked_balance' => DB::raw('locked_balance / 100'),
            'currency' => 'USD'
        ]);
        
        DB::table('transactions')->whereIn('type', ['deposit', 'withdraw', 'bet', 'win', 'refund'])->update([
            'amount' => DB::raw('amount / 100')
        ]);
        
        if (Schema::hasTable('challenges')) {
            DB::table('challenges')->update([
                'bet_amount' => DB::raw('bet_amount / 100')
            ]);
        }
        
        if (Schema::hasTable('bets')) {
            DB::table('bets')->update([
                'amount' => DB::raw('amount / 100'),
                'potential_win' => DB::raw('potential_win / 100')
            ]);
        }
        
        if (Schema::hasTable('deposits')) {
            DB::table('deposits')->update([
                'amount' => DB::raw('amount / 100')
            ]);
        }
        
        if (Schema::hasTable('withdrawals')) {
            DB::table('withdrawals')->update([
                'amount' => DB::raw('amount / 100')
            ]);
        }
    }
};

