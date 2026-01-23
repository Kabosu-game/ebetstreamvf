<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class MigrateBonusesToLockedSpace extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bonuses:migrate-to-locked-space';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migre tous les bonus confirmés vers l\'espace bonus (status locked) et retire les montants des balances';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Début de la migration des bonus...');

        // Récupérer tous les bonus confirmés (welcome_bonus et first_deposit_bonus)
        $confirmedBonuses = Transaction::where('type', 'deposit')
            ->whereIn('provider', ['welcome_bonus', 'first_deposit_bonus'])
            ->where('status', 'confirmed')
            ->get();

        $this->info("Nombre de bonus à migrer : {$confirmedBonuses->count()}");

        if ($confirmedBonuses->count() === 0) {
            $this->info('Aucun bonus à migrer.');
            return 0;
        }

        $migratedCount = 0;
        $totalAmountRetrieved = 0;

        DB::beginTransaction();

        try {
            foreach ($confirmedBonuses as $transaction) {
                $user = $transaction->user;
                $wallet = $user->wallet;

                if (!$wallet) {
                    $this->warn("Wallet introuvable pour l'utilisateur ID: {$user->id}, transaction ID: {$transaction->id}");
                    continue;
                }

                $bonusAmount = (float) $transaction->amount;

                // Retirer le montant du bonus de la balance si elle contient encore ce montant
                if ($wallet->balance >= $bonusAmount) {
                    $wallet->balance -= $bonusAmount;
                    $wallet->save();
                    $totalAmountRetrieved += $bonusAmount;
                } else {
                    // Si la balance est inférieure au bonus, on ajuste à 0 minimum
                    $this->warn("Balance insuffisante pour l'utilisateur ID: {$user->id}. Balance actuelle: {$wallet->balance}, Bonus: {$bonusAmount}");
                    $wallet->balance = max(0, $wallet->balance - $bonusAmount);
                    $wallet->save();
                    $totalAmountRetrieved += $bonusAmount;
                }

                // Changer le status de la transaction de 'confirmed' à 'locked'
                $transaction->status = 'locked';
                $transaction->save();

                $migratedCount++;
            }

            DB::commit();

            $this->info("✅ Migration terminée avec succès !");
            $this->info("   - Nombre de bonus migrés : {$migratedCount}");
            $this->info("   - Montant total retiré des balances : $" . number_format($totalAmountRetrieved, 2));

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ Erreur lors de la migration : " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}

