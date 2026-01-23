<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Championship;
use Carbon\Carbon;

class ChampionshipSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $games = [
            'COD Mobile',
            'Free Fire',
            'PUBG Mobile',
            'Mobile Legends',
            'eFootball / FC / DLS',
            'Clash Royale',
            'Brawl Stars',
            'Stumble Guys'
        ];

        $divisions = ['1', '2', '3'];

        // Toutes les inscriptions commencent le 19 janvier 2026
        $registrationStart = Carbon::create(2026, 1, 19)->startOfDay();
        $registrationEnd = $registrationStart->copy()->addDays(14); // Fin des inscriptions dans 14 jours
        $startDate = $registrationEnd->copy()->addDays(3); // Début du championnat 3 jours après la fin des inscriptions
        $endDate = $startDate->copy()->addDays(30); // Fin du championnat 30 jours après le début
        
        $championshipIndex = 0;

        foreach ($divisions as $division) {
            foreach ($games as $game) {
                $championshipIndex++;

                // Prix d'inscription et prize pool selon la division
                $registrationFee = $division === '1' ? 50.00 : ($division === '2' ? 25.00 : 10.00);
                $prizePool = $division === '1' ? 5000.00 : ($division === '2' ? 2500.00 : 1000.00);

                // Distribution des prix
                $prizeDistribution = [
                    '1st' => $prizePool * 0.50,
                    '2nd' => $prizePool * 0.30,
                    '3rd' => $prizePool * 0.15,
                    '4th' => $prizePool * 0.05,
                ];

                Championship::create([
                    'name' => 'EBETSTREAM Championship',
                    'game' => $game,
                    'division' => $division,
                    'description' => "Compete in the prestigious EBETSTREAM Championship for {$game}. Join players from around the world and prove your skills in Division {$division}.",
                    'rules' => "Standard tournament rules apply. All matches must be streamed. Fair play required.",
                    'registration_fee' => $registrationFee,
                    'total_prize_pool' => $prizePool,
                    'prize_distribution' => $prizeDistribution,
                    'registration_start_date' => $registrationStart->format('Y-m-d'),
                    'registration_end_date' => $registrationEnd->format('Y-m-d'),
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'max_participants' => 32,
                    'min_participants' => 8,
                    'status' => 'registration_open',
                    'is_active' => true,
                    'current_round' => 0,
                ]);
            }
        }

        $this->command->info('✅ Created ' . count($divisions) * count($games) . ' championships successfully!');
    }
}

