<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Championship;
use App\Models\ChampionshipRegistration;
use App\Models\ChampionshipMatch;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ChampionshipMatchesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create test users
        $users = $this->getOrCreateTestUsers(30);
        
        // Get active championships grouped by division
        $championshipsByDivision = Championship::where('is_active', true)
            ->where('start_date', '>=', Carbon::now()->startOfDay())
            ->where('start_date', '<=', Carbon::now()->addDays(30))
            ->orderBy('division', 'asc')
            ->orderBy('game', 'asc')
            ->get()
            ->groupBy('division');

        $totalRegistrations = 0;
        $totalMatches = 0;

        foreach ($championshipsByDivision as $division => $championships) {
            $this->command->info("Processing Division {$division}...");
            
            foreach ($championships as $championship) {
                // Create registrations for this championship (between 4 and 8 participants)
                $numRegistrations = rand(4, 8);
                $selectedUsers = $users->shuffle()->take($numRegistrations);
                
                $this->command->info("  Creating {$numRegistrations} registrations for {$championship->game}...");

                $registrationIds = [];

                foreach ($selectedUsers as $index => $user) {
                    // Check if user is already registered
                    $existingRegistration = ChampionshipRegistration::where('championship_id', $championship->id)
                        ->where('user_id', $user->id)
                        ->first();

                    if ($existingRegistration) {
                        $registrationIds[] = $existingRegistration->id;
                        continue;
                    }

                    // Ensure user has a wallet
                    $wallet = Wallet::firstOrCreate(
                        ['user_id' => $user->id],
                        ['balance' => 1000.00, 'currency' => 'USD', 'locked_balance' => 0]
                    );

                    // Create transaction
                    $transaction = Transaction::create([
                        'user_id' => $user->id,
                        'wallet_id' => $wallet->id,
                        'type' => 'championship_registration',
                        'amount' => -$championship->registration_fee,
                        'status' => 'confirmed',
                        'provider' => 'system',
                        'txid' => 'SEED_CHAMP_' . $championship->id . '_' . $user->id . '_' . now()->format('YmdHis'),
                    ]);

                    // Create registration
                    $teamName = $this->generateTeamName($championship->game, $index + 1);
                    $playersList = $this->generatePlayersList(rand(1, 5));

                    $registration = ChampionshipRegistration::create([
                        'championship_id' => $championship->id,
                        'user_id' => $user->id,
                        'full_name' => $user->username,
                        'team_name' => $teamName,
                        'player_name' => $user->username,
                        'player_username' => $user->username ?? 'player' . $user->id,
                        'players_list' => $playersList,
                        'contact_email' => $user->email,
                        'status' => 'validated', // All validated for matches
                        'transaction_id' => $transaction->id,
                        'fee_paid' => $championship->registration_fee,
                        'registered_at' => Carbon::now()->subDays(rand(0, 5)),
                        'paid_at' => Carbon::now()->subDays(rand(0, 5)),
                        'validated_at' => Carbon::now()->subDays(rand(0, 3)),
                        'accept_terms' => true,
                    ]);

                    $registrationIds[] = $registration->id;
                    $totalRegistrations++;
                }

                // Create matches manually for this championship
                if (count($registrationIds) >= 2) {
                    $matchesCreated = $this->createMatchesForChampionship($championship, $registrationIds);
                    $totalMatches += $matchesCreated;
                    $this->command->info("    ✓ Created {$matchesCreated} matches");
                }
            }
        }

        $this->command->info("✅ Created {$totalRegistrations} registrations and {$totalMatches} matches successfully!");
    }

    /**
     * Get or create test users
     */
    private function getOrCreateTestUsers(int $count)
    {
        $users = User::where('email', 'like', 'testplayer%@example.com')
            ->orWhere('email', 'like', 'player%@test.com')
            ->get();

        if ($users->count() < $count) {
            $needed = $count - $users->count();
            for ($i = 1; $i <= $needed; $i++) {
                $user = User::create([
                    'username' => 'testplayer' . ($users->count() + $i),
                    'email' => 'testplayer' . ($users->count() + $i) . '@example.com',
                    'password' => bcrypt('password'),
                    'email_verified_at' => Carbon::now(),
                    'role' => 'player',
                ]);

                // Create wallet for user if it doesn't exist
                Wallet::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'balance' => 1000.00,
                        'currency' => 'USD',
                        'locked_balance' => 0
                    ]
                );

                $users->push($user);
            }
        }

        return $users->take($count);
    }

    /**
     * Generate a team name
     */
    private function generateTeamName(string $game, int $index): string
    {
        $prefixes = ['Elite', 'Pro', 'Champions', 'Victory', 'Legends', 'Masters', 'Warriors', 'Elite'];
        $suffixes = ['Squad', 'Team', 'Legion', 'Force', 'Squad', 'Clan', 'Guild', 'Alliance'];
        
        $prefix = $prefixes[($index - 1) % count($prefixes)];
        $suffix = $suffixes[($index - 1) % count($suffixes)];
        
        return $prefix . ' ' . $suffix . ' ' . $index;
    }

    /**
     * Generate a list of players
     */
    private function generatePlayersList(int $count): array
    {
        $players = [];
        $names = ['Player', 'Gamer', 'Pro', 'Elite', 'Master', 'Champion', 'Legend', 'Warrior'];
        
        for ($i = 1; $i <= $count; $i++) {
            $name = $names[($i - 1) % count($names)] . ' ' . $i;
            $players[] = $name;
        }
        
        return $players;
    }

    /**
     * Create matches manually for a championship
     */
    private function createMatchesForChampionship(Championship $championship, array $registrationIds): int
    {
        // Delete existing matches for this championship to avoid duplicates
        ChampionshipMatch::where('championship_id', $championship->id)->delete();

        $startDate = Carbon::parse($championship->start_date);
        
        // Shuffle registration IDs for random pairing
        shuffle($registrationIds);
        
        // Create first round matches (pair participants)
        $roundNumber = 1;
        $matchIndex = 0;
        $matchesPerDay = 4;
        $currentDay = 0;
        $matchesCreated = 0;
        
        for ($i = 0; $i < count($registrationIds) - 1; $i += 2) {
            if (isset($registrationIds[$i + 1])) {
                // Distribute matches across days
                if ($matchIndex > 0 && $matchIndex % $matchesPerDay === 0) {
                    $currentDay++;
                }
                
                $scheduledAt = $startDate->copy()
                    ->addDays($currentDay)
                    ->addHours(10 + (($matchIndex % $matchesPerDay) * 3)); // Start at 10 AM, 3 hours between matches
                
                // Make sure scheduled_at is in the future
                if ($scheduledAt->lt(Carbon::now())) {
                    $scheduledAt = Carbon::now()->addDays($currentDay + 1)->setTime(10, 0);
                }
                
                ChampionshipMatch::create([
                    'championship_id' => $championship->id,
                    'round_number' => $roundNumber,
                    'player1_id' => $registrationIds[$i],
                    'player2_id' => $registrationIds[$i + 1],
                    'status' => 'scheduled',
                    'scheduled_at' => $scheduledAt,
                ]);
                
                $matchIndex++;
                $matchesCreated++;
            }
        }

        return $matchesCreated;
    }
}

