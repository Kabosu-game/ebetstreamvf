<?php

namespace Database\Seeders;

use App\Models\ArenaMatch;
use App\Models\ArenaPlayerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class ArenaSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::limit(10)->get();
        if ($users->isEmpty()) {
            return;
        }

        $classes = ['attacker', 'defender', 'support', 'tactical'];
        $ranks = ['bronze', 'silver', 'gold', 'elite', 'champion'];

        foreach ($users as $i => $user) {
            ArenaPlayerProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'player_class' => $classes[$i % 4],
                    'rank' => $ranks[min($i, 4)],
                    'league_tier' => ['amateur', 'semi_pro', 'pro', 'champion'][min((int) floor($i / 3), 3)],
                    'level' => $i + 1,
                    'mmr' => 800 + ($i * 150),
                    'points' => $i * 45,
                    'matches_played' => $i * 3 + 5,
                    'matches_won' => $i * 2 + 2,
                    'matches_lost' => $i + 1,
                ]
            );
        }

        $now = Carbon::now();

        $matches = [
            [
                'team1_name' => 'Phoenix Squad',
                'team2_name' => 'Shadow Legion',
                'team1_score' => 0, 'team2_score' => 0,
                'team1_odds' => 1.75, 'team2_odds' => 2.10,
                'mode' => 'ranked', 'league_tier' => 'semi_pro',
                'status' => 'live',
                'scheduled_at' => $now->copy()->subMinutes(3),
                'started_at' => $now->copy()->subMinutes(3),
            ],
            [
                'team1_name' => 'Nova Strike',
                'team2_name' => 'Iron Wolves',
                'team1_score' => 0, 'team2_score' => 0,
                'team1_odds' => 1.90, 'team2_odds' => 1.90,
                'mode' => 'quick_match', 'league_tier' => 'amateur',
                'status' => 'scheduled',
                'scheduled_at' => $now->copy()->addHours(2),
            ],
            [
                'team1_name' => 'Elite Vanguard',
                'team2_name' => 'Crimson Tide',
                'team1_score' => 0, 'team2_score' => 0,
                'team1_odds' => 2.05, 'team2_odds' => 1.80,
                'mode' => 'tournament', 'league_tier' => 'pro',
                'status' => 'scheduled',
                'scheduled_at' => $now->copy()->addDay(),
            ],
            [
                'team1_name' => 'Champion Kings',
                'team2_name' => 'Arena Legends',
                'team1_score' => 78, 'team2_score' => 65,
                'team1_odds' => 1.85, 'team2_odds' => 1.95,
                'mode' => 'ranked', 'league_tier' => 'champion',
                'status' => 'completed',
                'winner_team' => 'team1',
                'scheduled_at' => $now->copy()->subDay(),
                'started_at' => $now->copy()->subDay(),
                'completed_at' => $now->copy()->subDay()->addMinutes(7),
            ],
            [
                'team1_name' => 'Beta Force',
                'team2_name' => 'Delta Unit',
                'team1_score' => 55, 'team2_score' => 100,
                'team1_odds' => 2.20, 'team2_odds' => 1.65,
                'mode' => 'private_match', 'league_tier' => 'amateur',
                'status' => 'completed',
                'winner_team' => 'team2',
                'scheduled_at' => $now->copy()->subDays(2),
                'completed_at' => $now->copy()->subDays(2)->addMinutes(8),
            ],
        ];

        foreach ($matches as $data) {
            ArenaMatch::updateOrCreate(
                [
                    'team1_name' => $data['team1_name'],
                    'team2_name' => $data['team2_name'],
                    'mode' => $data['mode'],
                ],
                array_merge($data, [
                    'match_state' => [
                        'zones' => 3,
                        'central_zone' => true,
                        'duration_minutes' => rand(5, 8),
                    ],
                    'created_by' => $users->first()->id,
                ])
            );
        }
    }
}
