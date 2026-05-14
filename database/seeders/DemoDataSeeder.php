<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    private Carbon $now;

    public function run(): void
    {
        $this->now = now();

        DB::transaction(function () {
            $this->call([
                RolesAndPermissionsSeeder::class,
                AdminUserSeeder::class,
                PaymentMethodSeeder::class,
            ]);

            $this->seedRolesAndAssignments();
            $users = $this->seedUsers();
            $this->seedRolesAndAssignments();
            $gameNames = ['PUBG Mobile', 'Garena Free Fire', 'Mobile Legends: Bang Bang'];
            $this->seedProfilesAndWallets($users);
            $this->seedPaymentActivity($users);
            $clans = $this->seedClans($users);
            $teams = $this->seedTeams($users);
            $federations = $this->seedFederations($users);
            $tournaments = $this->seedTournaments($federations, $teams, $users);
            $this->seedEvents($users);
            $this->seedForum($users);
            $this->seedChallenges($users, $clans, $gameNames);
            $championships = $this->seedChampionships($users, $teams, $gameNames);
            $this->seedStreams($users, $gameNames);
            $this->seedCertificationAndAgents($users);
            $this->seedAmbassadorsAndPartners();
            $this->seedBallonDor($users, $clans, $teams);
            $this->seedMarketplace($teams, $users);
            $this->seedMonetization();
            $this->seedBetting($users, $championships);
        });

        $this->command?->info('Demo data generated successfully.');
        $this->command?->line('Demo logins:');
        $this->command?->line('  admin@ebetstream.com / admin123');
        $this->command?->line('  demo.player1@ebetstream.local / password');
        $this->command?->line('  demo.streamer@ebetstream.local / password');
    }

    private function seedUsers(): array
    {
        $rows = [
            ['DemoPlayerOne', 'demo.player1@ebetstream.local', 'player', '+22997000001', true],
            ['DemoPlayerTwo', 'demo.player2@ebetstream.local', 'player', '+22997000002', false],
            ['DemoChampion', 'demo.champion@ebetstream.local', 'player', '+22997000003', true],
            ['DemoStreamer', 'demo.streamer@ebetstream.local', 'player', '+22997000004', true],
            ['DemoReferee', 'demo.referee@ebetstream.local', 'referee', '+22997000005', false],
            ['DemoAmbassador', 'demo.ambassador@ebetstream.local', 'ambassador', '+22997000006', true],
            ['DemoOrganizer', 'demo.organizer@ebetstream.local', 'player', '+22997000007', false],
            ['DemoAgent', 'demo.agent@ebetstream.local', 'player', '+22997000008', false],
            ['DemoProMobile', 'demo.promobile@ebetstream.local', 'player', '+22997000009', true],
            ['DemoCasual', 'demo.casual@ebetstream.local', 'player', '+22997000010', false],
            ['DemoLegend', 'demo.legend@ebetstream.local', 'player', '+22997000011', true],
            ['DemoRookie', 'demo.rookie@ebetstream.local', 'player', '+22997000012', false],
        ];

        foreach ($rows as [$username, $email, $role, $phone, $isStar]) {
            DB::table('users')->updateOrInsert(
                ['email' => $email],
                [
                    'username' => $username,
                    'phone' => $phone,
                    'email_verified_at' => $this->now,
                    'password' => Hash::make('password'),
                    'promo_code' => 'DEMO' . substr(strtoupper($username), -4),
                    'used_welcome_code' => $isStar ? 'WELCOME50' : null,
                    'role' => $role,
                    'premium_until' => $isStar ? $this->now->copy()->addDays(45) : null,
                    'first_deposit_bonus_applied' => $isStar,
                    'remember_token' => Str::random(10),
                    'is_ebetstar' => $isStar,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        return DB::table('users')
            ->whereIn('email', array_column($rows, 1))
            ->get()
            ->keyBy('email')
            ->all();
    }

    private function seedRolesAndAssignments(): void
    {
        $roles = ['admin', 'writer', 'player', 'referee', 'ambassador', 'organizer', 'agent'];
        $permissions = [
            'view dashboard',
            'manage users',
            'manage games',
            'manage payments',
            'manage streams',
            'manage championships',
            'place bets',
            'join challenges',
            'moderate matches',
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role, 'guard_name' => 'web'],
                ['created_at' => $this->now, 'updated_at' => $this->now]
            );
        }

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => $this->now, 'updated_at' => $this->now]
            );
        }

        $roleIds = DB::table('roles')->pluck('id', 'name');
        $permissionIds = DB::table('permissions')->pluck('id', 'name');

        $rolePermissionMap = [
            'admin' => $permissions,
            'player' => ['place bets', 'join challenges', 'view dashboard'],
            'referee' => ['moderate matches', 'view dashboard'],
            'ambassador' => ['manage streams', 'view dashboard'],
            'organizer' => ['manage championships', 'view dashboard'],
            'agent' => ['manage payments', 'view dashboard'],
            'writer' => ['view dashboard'],
        ];

        foreach ($rolePermissionMap as $role => $rolePermissions) {
            foreach ($rolePermissions as $permission) {
                if (!isset($roleIds[$role], $permissionIds[$permission])) {
                    continue;
                }

                DB::table('role_has_permissions')->updateOrInsert([
                    'role_id' => $roleIds[$role],
                    'permission_id' => $permissionIds[$permission],
                ]);
            }
        }

        $users = DB::table('users')->get();
        foreach ($users as $user) {
            $role = $user->role ?: 'player';
            if (!isset($roleIds[$role])) {
                $role = 'player';
            }

            DB::table('model_has_roles')->updateOrInsert([
                'role_id' => $roleIds[$role],
                'model_type' => 'App\\Models\\User',
                'model_id' => $user->id,
            ]);

            if ($role === 'admin' && isset($permissionIds['manage users'])) {
                DB::table('model_has_permissions')->updateOrInsert([
                    'permission_id' => $permissionIds['manage users'],
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $user->id,
                ]);
            }
        }
    }

    private function seedProfilesAndWallets(array $users): void
    {
        $countries = ['Bénin', 'Côte d’Ivoire', 'Sénégal', 'Togo', 'France', 'Cameroun'];
        $i = 0;

        foreach ($users as $user) {
            $i++;
            DB::table('profiles')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'username' => $user->username,
                    'full_name' => str_replace('Demo', 'Demo ', $user->username),
                    'in_game_pseudo' => $user->username . '#EB' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'status' => $i % 3 === 0 ? 'En tournoi' : 'Disponible',
                    'country' => $countries[$i % count($countries)],
                    'bio' => 'Profil de démonstration pour tester les pages joueur, classement et dashboard.',
                    'wins' => 8 + ($i * 3),
                    'losses' => 2 + $i,
                    'ratio' => round((8 + ($i * 3)) / max(1, 2 + $i), 2),
                    'tournaments_won' => $i % 4,
                    'tournaments_list' => json_encode(['Spring Cup', 'Mobile Masters']),
                    'ranking' => '#' . (20 + $i),
                    'division' => (string) (($i % 3) + 1),
                    'global_score' => 800 + ($i * 115),
                    'current_season' => 'Saison Démo 2026',
                    'badges' => json_encode(['Fair-play', 'Top joueur']),
                    'certifications' => json_encode($user->role === 'referee' ? ['Arbitre vérifié'] : []),
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );

            DB::table('wallets')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'balance' => 250 + ($i * 37.50),
                    'locked_balance' => $i % 2 ? 20 : 0,
                    'currency' => 'USD',
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $referrers = array_values($users);
        foreach (array_slice($referrers, 1, 6) as $index => $referred) {
            DB::table('referrals')->updateOrInsert(
                ['referrer_id' => $referrers[0]->id, 'referred_id' => $referred->id],
                [
                    'bonus' => 5 + $index,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }
    }

    private function seedPaymentActivity(array $users): void
    {
        DB::table('promo_codes')->updateOrInsert(
            ['code' => 'WELCOME50'],
            [
                'description' => 'Bonus de bienvenue de démonstration',
                'amount' => 50,
                'welcome_bonus' => 50,
                'first_deposit_bonus_percentage' => 15,
                'premium_days' => 7,
                'is_welcome_code' => true,
                'is_active' => true,
                'expires_at' => $this->now->copy()->addMonths(3),
                'usage_limit' => 500,
                'used_count' => 6,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]
        );

        DB::table('promo_codes')->updateOrInsert(
            ['code' => 'DEMO2026'],
            [
                'description' => 'Crédit démo pour tests',
                'amount' => 25,
                'welcome_bonus' => 0,
                'first_deposit_bonus_percentage' => 10,
                'premium_days' => 0,
                'is_welcome_code' => false,
                'is_active' => true,
                'expires_at' => $this->now->copy()->addMonths(6),
                'usage_limit' => 1000,
                'used_count' => 12,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]
        );

        DB::table('recharge_agents')->updateOrInsert(
            ['agent_id' => 'AG1001'],
            [
                'name' => 'Agent Démo Cotonou',
                'phone' => '+22996001001',
                'status' => 'active',
                'description' => 'Agent fictif pour les dépôts et retraits en cash.',
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]
        );

        DB::table('recharge_agents')->updateOrInsert(
            ['agent_id' => 'AG1002'],
            [
                'name' => 'Agent Démo Lomé',
                'phone' => '+22896001002',
                'status' => 'active',
                'description' => 'Point de recharge de démonstration.',
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]
        );

        $agents = DB::table('recharge_agents')->pluck('id', 'agent_id');

        foreach (array_values($users) as $index => $user) {
            $wallet = DB::table('wallets')->where('user_id', $user->id)->first();
            if (!$wallet) {
                continue;
            }

            DB::table('deposits')->updateOrInsert(
                ['transaction_hash' => 'DEMO-TX-' . $user->id],
                [
                    'user_id' => $user->id,
                    'method' => $index % 2 ? 'USDT (TRC20)' : 'Cash via Agent',
                    'amount' => 50 + ($index * 10),
                    'crypto_name' => $index % 2 ? 'USDT' : null,
                    'location' => $index % 2 ? null : 'Cotonou',
                    'status' => $index % 4 === 0 ? 'pending' : 'confirmed',
                    'created_at' => $this->now->copy()->subDays($index),
                    'updated_at' => $this->now,
                ]
            );

            DB::table('transactions')->updateOrInsert(
                ['txid' => 'DEMO-DEPOSIT-' . $user->id],
                [
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'type' => 'deposit',
                    'amount' => 50 + ($index * 10),
                    'status' => 'confirmed',
                    'provider' => $index % 2 ? 'crypto' : 'agent',
                    'meta' => json_encode(['demo' => true]),
                    'created_at' => $this->now->copy()->subDays($index),
                    'updated_at' => $this->now,
                ]
            );

            if ($index < 6) {
                DB::table('withdrawals')->updateOrInsert(
                    ['user_id' => $user->id, 'method' => 'USDT (TRC20)', 'amount' => 15 + ($index * 5)],
                    [
                        'crypto_name' => 'USDT',
                        'crypto_address' => 'TDemoAddress' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                        'status' => $index % 3 === 0 ? 'pending' : 'approved',
                        'created_at' => $this->now->copy()->subDays($index + 1),
                        'updated_at' => $this->now,
                    ]
                );

                DB::table('withdrawal_codes')->updateOrInsert(
                    ['code' => 'WD-DEMO-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                    [
                        'amount' => 20 + ($index * 5),
                        'user_id' => $user->id,
                        'recharge_agent_id' => $agents[$index % 2 ? 'AG1002' : 'AG1001'] ?? null,
                        'status' => $index % 2 ? 'completed' : 'pending',
                        'expires_at' => $this->now->copy()->addDays(7),
                        'completed_at' => $index % 2 ? $this->now->copy()->subDay() : null,
                        'notes' => 'Code de retrait démo',
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ]
                );
            }
        }
    }

    private function seedClans(array $users): array
    {
        $leaders = array_values($users);
        $clans = [
            ['name' => 'Neon Warriors', 'leader' => $leaders[0], 'description' => 'Clan compétitif mobile et battle royale.'],
            ['name' => 'Royal Strikers', 'leader' => $leaders[2], 'description' => 'Équipe orientée football mobile et tournois rapides.'],
            ['name' => 'Shadow Pixels', 'leader' => $leaders[3], 'description' => 'Communauté stream et défis hebdomadaires.'],
        ];

        foreach ($clans as $clan) {
            DB::table('clans')->updateOrInsert(
                ['name' => $clan['name']],
                [
                    'description' => $clan['description'],
                    'leader_id' => $clan['leader']->id,
                    'status' => 'active',
                    'member_count' => 4,
                    'max_members' => 50,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $created = DB::table('clans')->whereIn('name', array_column($clans, 'name'))->get()->keyBy('name')->all();
        foreach ($created as $clanIndex => $clan) {
            foreach (array_slice(array_values($users), 0, 5) as $memberIndex => $user) {
                if (($memberIndex + strlen((string) $clanIndex)) % 2 === 0 || $user->id === $clan->leader_id) {
                    DB::table('clan_user')->updateOrInsert(
                        ['clan_id' => $clan->id, 'user_id' => $user->id],
                        ['created_at' => $this->now, 'updated_at' => $this->now]
                    );

                    DB::table('clan_messages')->insert([
                        'clan_id' => $clan->id,
                        'user_id' => $user->id,
                        'message' => 'Message démo: prêt pour le prochain match.',
                        'is_deleted' => false,
                        'created_at' => $this->now->copy()->subMinutes($memberIndex * 8),
                        'updated_at' => $this->now,
                    ]);
                }
            }

            $candidateUser = array_values($users)[6];
            DB::table('clan_leader_candidates')->updateOrInsert(
                ['clan_id' => $clan->id, 'user_id' => $candidateUser->id],
                [
                    'motivation' => 'Organiser plus de tournois internes et accompagner les nouveaux joueurs.',
                    'vote_count' => 3,
                    'status' => 'pending',
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $candidate = DB::table('clan_leader_candidates')->first();
        if ($candidate) {
            foreach (array_slice(array_values($users), 0, 3) as $voter) {
                DB::table('clan_votes')->updateOrInsert(
                    ['clan_id' => $candidate->clan_id, 'candidate_id' => $candidate->id, 'voter_id' => $voter->id],
                    ['created_at' => $this->now, 'updated_at' => $this->now]
                );
            }
        }

        return $created;
    }

    private function seedTeams(array $users): array
    {
        $owners = array_values($users);
        $rows = [
            ['Neon Wolves', $owners[0], '1'],
            ['Cotonou Kings', $owners[1], '2'],
            ['Pixel Storm', $owners[2], '3'],
            ['Royal Five', $owners[3], '1'],
            ['Lagoon Esports', $owners[4], '2'],
            ['Arena Ghosts', $owners[5], '3'],
        ];

        foreach ($rows as [$name, $owner, $division]) {
            DB::table('teams')->updateOrInsert(
                ['name' => $name],
                [
                    'owner_id' => $owner->id,
                    'description' => 'Équipe de démonstration pour tester championnats et marketplace.',
                    'status' => 'active',
                    'division' => $division,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $teams = DB::table('teams')->whereIn('name', array_column($rows, 0))->get()->keyBy('name')->all();

        foreach ($teams as $team) {
            foreach (array_slice(array_values($users), 0, 4) as $user) {
                DB::table('team_user')->updateOrInsert(
                    ['team_id' => $team->id, 'user_id' => $user->id],
                    ['created_at' => $this->now, 'updated_at' => $this->now]
                );
            }
        }

        return $teams;
    }

    private function seedFederations(array $users): array
    {
        $owners = array_values($users);
        $rows = [
            ['Fédération Mobile Bénin', 'federation-mobile-benin', $owners[6], 'Bénin', 'Cotonou'],
            ['West Africa Esports League', 'west-africa-esports-league', $owners[7], 'Sénégal', 'Dakar'],
        ];

        foreach ($rows as [$name, $slug, $owner, $country, $city]) {
            DB::table('federations')->updateOrInsert(
                ['slug' => $slug],
                [
                    'user_id' => $owner->id,
                    'name' => $name,
                    'description' => 'Organisation fictive pour les compétitions et championnats démo.',
                    'website' => 'https://example.com/' . $slug,
                    'email' => $slug . '@example.com',
                    'phone' => '+2299700' . rand(1000, 9999),
                    'country' => $country,
                    'city' => $city,
                    'address' => 'Adresse de démonstration',
                    'status' => 'approved',
                    'settings' => json_encode(['public_profile' => true, 'demo' => true]),
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        return DB::table('federations')->whereIn('slug', array_column($rows, 1))->get()->keyBy('slug')->all();
    }

    private function seedTournaments(array $federations, array $teams, array $users): array
    {
        $fed = array_values($federations)[0] ?? null;
        $rows = [
            ['Demo Mobile Masters', 'PUBG Mobile', 'individual', '1', 32, 10, 500],
            ['Demo Team Clash', 'Mobile Legends: Bang Bang', 'team', '2', 16, 20, 900],
            ['Demo Free Fire Night', 'Garena Free Fire', 'team', '3', 24, 5, 300],
        ];

        foreach ($rows as [$title, $game, $type, $division, $max, $fee, $reward]) {
            DB::table('tournaments')->updateOrInsert(
                ['title' => $title],
                [
                    'federation_id' => $fed?->id,
                    'type' => $type,
                    'division' => $division,
                    'max_participants' => $max,
                    'rules' => 'Règles démo: fair-play, présence obligatoire, score validé par arbitre.',
                    'game' => $game,
                    'entry_fee' => $fee,
                    'reward' => $reward,
                    'status' => 'upcoming',
                    'start_at' => $this->now->copy()->addDays(rand(7, 20)),
                    'end_at' => $this->now->copy()->addDays(rand(21, 35)),
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $tournaments = DB::table('tournaments')->whereIn('title', array_column($rows, 0))->get()->keyBy('title')->all();

        foreach ($tournaments as $tournament) {
            foreach (array_slice(array_values($users), 0, 5) as $user) {
                DB::table('tournament_user')->updateOrInsert(
                    ['tournament_id' => $tournament->id, 'user_id' => $user->id],
                    ['created_at' => $this->now, 'updated_at' => $this->now]
                );
            }

            if ($tournament->type === 'team') {
                foreach (array_slice(array_values($teams), 0, 2) as $team) {
                    DB::table('tournament_teams')->updateOrInsert(
                        ['tournament_id' => $tournament->id, 'team_id' => $team->id],
                        [
                            'registered_by' => $team->owner_id ?? array_values($users)[0]->id,
                            'status' => 'confirmed',
                            'registered_at' => $this->now,
                            'created_at' => $this->now,
                            'updated_at' => $this->now,
                        ]
                    );
                }
            }
        }

        return $tournaments;
    }

    private function seedEvents(array $users): void
    {
        $events = [
            ['Soirée Stream Battle Royale', 'Tournoi communautaire avec diffusion live.', 'online', 'tournoi'],
            ['Bootcamp Mobile Legends', 'Session entraînement et stratégie pour équipes.', 'Cotonou Arena', 'atelier'],
            ['Finale Démo eBetStream', 'Grande finale de démonstration multi-jeux.', 'Online', 'finale'],
        ];

        foreach ($events as $index => [$title, $description, $location, $type]) {
            DB::table('events')->updateOrInsert(
                ['title' => $title],
                [
                    'description' => $description,
                    'start_at' => $this->now->copy()->addDays(5 + ($index * 4))->setTime(19, 0),
                    'end_at' => $this->now->copy()->addDays(5 + ($index * 4))->setTime(22, 0),
                    'location' => $location,
                    'status' => 'published',
                    'type' => $type,
                    'max_participants' => 64,
                    'registration_deadline' => $this->now->copy()->addDays(3 + ($index * 4)),
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $createdEvents = DB::table('events')->whereIn('title', array_column($events, 0))->get();
        foreach ($createdEvents as $event) {
            foreach (array_slice(array_values($users), 0, 4) as $user) {
                DB::table('event_registrations')->updateOrInsert(
                    ['event_id' => $event->id, 'email' => $user->email],
                    [
                        'pseudo' => $user->username,
                        'phone' => $user->phone,
                        'country' => 'Bénin',
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ]
                );
            }
        }
    }

    private function seedForum(array $users): void
    {
        $authors = array_values($users);
        $posts = [
            ['Vos meilleurs réglages pour PUBG Mobile ?', 'Partagez vos sensibilités, HUD et astuces pour les duels.'],
            ['Comment préparer une finale eSport ?', 'Routine, échauffement, communication et gestion du stress.'],
            ['Recherche équipe Free Fire', 'Je cherche une équipe active pour les tournois de fin de semaine.'],
        ];

        foreach ($posts as $index => [$title, $content]) {
            DB::table('forum_posts')->updateOrInsert(
                ['title' => $title],
                [
                    'user_id' => $authors[$index]->id,
                    'content' => $content,
                    'created_at' => $this->now->copy()->subDays($index),
                    'updated_at' => $this->now,
                ]
            );
        }

        $createdPosts = DB::table('forum_posts')->whereIn('title', array_column($posts, 0))->get();
        foreach ($createdPosts as $post) {
            foreach (array_slice($authors, 3, 3) as $commenter) {
                DB::table('forum_comments')->insert([
                    'post_id' => $post->id,
                    'user_id' => $commenter->id,
                    'content' => 'Réponse démo: merci pour le partage, je vais tester ça.',
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }
        }

        for ($i = 0; $i < 4; $i++) {
            DB::table('messages')->insert([
                'sender_id' => $authors[$i]->id,
                'receiver_id' => $authors[$i + 1]->id,
                'message' => 'Message privé démo pour tester la messagerie.',
                'read' => $i % 2 === 0,
                'created_at' => $this->now->copy()->subMinutes($i * 12),
                'updated_at' => $this->now,
            ]);
        }
    }

    private function seedChallenges(array $users, array $clans, array $gameNames): void
    {
        $players = array_values($users);
        $rows = [
            ['user', $players[0], $players[1], null, null, $gameNames[0] ?? 'PUBG Mobile', 25, 'open'],
            ['user', $players[2], $players[3], null, null, $gameNames[1] ?? 'Free Fire', 50, 'in_progress'],
            ['clan', $players[0], null, array_values($clans)[0] ?? null, array_values($clans)[1] ?? null, $gameNames[2] ?? 'Mobile Legends', 100, 'accepted'],
            ['user', $players[4], $players[5], null, null, $gameNames[3] ?? 'Clash Royale', 15, 'completed'],
        ];

        foreach ($rows as $index => [$type, $creator, $opponent, $creatorClan, $opponentClan, $game, $amount, $status]) {
            DB::table('challenges')->updateOrInsert(
                ['creator_id' => $creator->id, 'game' => $game, 'bet_amount' => $amount],
                [
                    'type' => $type,
                    'creator_clan_id' => $creatorClan?->id,
                    'opponent_id' => $opponent?->id,
                    'opponent_clan_id' => $opponentClan?->id,
                    'status' => $status,
                    'expires_at' => $this->now->copy()->addDays(3),
                    'creator_score' => $status === 'completed' ? 3 : null,
                    'opponent_score' => $status === 'completed' ? 1 : null,
                    'creator_screen_recording' => $status !== 'open',
                    'opponent_screen_recording' => $status === 'completed',
                    'is_live' => $status === 'in_progress',
                    'stream_key' => 'demo-challenge-' . ($index + 1),
                    'rtmp_url' => 'rtmp://127.0.0.1/live/demo-challenge-' . ($index + 1),
                    'stream_url' => '/streams/demo-challenge-' . ($index + 1),
                    'live_started_at' => $status === 'in_progress' ? $this->now->copy()->subMinutes(30) : null,
                    'viewer_count' => $status === 'in_progress' ? 42 : 0,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $created = DB::table('challenges')->whereIn('stream_key', ['demo-challenge-1', 'demo-challenge-2', 'demo-challenge-3', 'demo-challenge-4'])->get();
        foreach ($created as $challenge) {
            foreach (array_slice($players, 0, 3) as $player) {
                DB::table('challenge_messages')->insert([
                    'challenge_id' => $challenge->id,
                    'user_id' => $player->id,
                    'message' => 'Message démo du challenge.',
                    'is_deleted' => false,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }

            if ($challenge->status === 'completed') {
                DB::table('matches')->updateOrInsert(
                    ['challenge_id' => $challenge->id],
                    [
                        'player1_id' => $challenge->creator_id,
                        'player2_id' => $challenge->opponent_id,
                        'player1_score' => 3,
                        'player2_score' => 1,
                        'status' => 'finished',
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ]
                );

                DB::table('challenge_stop_requests')->updateOrInsert(
                    ['challenge_id' => $challenge->id],
                    [
                        'initiator_id' => $challenge->creator_id,
                        'confirmer_id' => $challenge->opponent_id,
                        'status' => 'confirmed',
                        'reason' => 'Fin normale du match démo.',
                        'confirmed_at' => $this->now,
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ]
                );
            }
        }
    }

    private function seedChampionships(array $users, array $teams, array $gameNames): array
    {
        $rows = [
            ['Demo Championship Elite', $gameNames[0] ?? 'PUBG Mobile', '1', 25, 1500],
            ['Demo Championship Rising', $gameNames[1] ?? 'Garena Free Fire', '2', 10, 750],
            ['Demo Championship Academy', $gameNames[2] ?? 'Mobile Legends: Bang Bang', '3', 5, 300],
        ];

        foreach ($rows as [$name, $game, $division, $fee, $pool]) {
            DB::table('championships')->updateOrInsert(
                ['name' => $name, 'game' => $game, 'division' => $division],
                [
                    'description' => 'Championnat de démonstration avec inscriptions, équipes et matchs.',
                    'rules' => 'Règles démo: inscription validée, présence obligatoire, score confirmé.',
                    'registration_fee' => $fee,
                    'total_prize_pool' => $pool,
                    'prize_distribution' => json_encode(['1st' => $pool * .5, '2nd' => $pool * .3, '3rd' => $pool * .2]),
                    'registration_start_date' => $this->now->copy()->subDays(3)->toDateString(),
                    'registration_end_date' => $this->now->copy()->addDays(10)->toDateString(),
                    'start_date' => $this->now->copy()->addDays(14)->toDateString(),
                    'end_date' => $this->now->copy()->addDays(30)->toDateString(),
                    'max_participants' => 32,
                    'min_participants' => 4,
                    'status' => 'registration_open',
                    'is_active' => true,
                    'current_round' => 1,
                    'admin_notes' => 'Données démo.',
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $championships = DB::table('championships')->whereIn('name', array_column($rows, 0))->get()->keyBy('name')->all();
        $players = array_values($users);
        $teamValues = array_values($teams);

        foreach ($championships as $championship) {
            $registrationIds = [];
            foreach (array_slice($players, 0, 6) as $index => $user) {
                $wallet = DB::table('wallets')->where('user_id', $user->id)->first();
                $txId = null;
                if ($wallet) {
                    DB::table('transactions')->updateOrInsert(
                        ['txid' => 'DEMO-CHAMP-' . $championship->id . '-' . $user->id],
                        [
                            'wallet_id' => $wallet->id,
                            'user_id' => $user->id,
                            'type' => 'championship_registration',
                            'amount' => -$championship->registration_fee,
                            'status' => 'confirmed',
                            'provider' => 'wallet',
                            'meta' => json_encode(['championship_id' => $championship->id]),
                            'created_at' => $this->now,
                            'updated_at' => $this->now,
                        ]
                    );
                    $txId = DB::table('transactions')->where('txid', 'DEMO-CHAMP-' . $championship->id . '-' . $user->id)->value('id');
                }

                DB::table('championship_registrations')->updateOrInsert(
                    ['championship_id' => $championship->id, 'user_id' => $user->id],
                    [
                        'full_name' => str_replace('Demo', 'Demo ', $user->username),
                        'team_id' => $teamValues[$index % count($teamValues)]->id ?? null,
                        'team_name' => $teamValues[$index % count($teamValues)]->name ?? null,
                        'player_name' => $user->username,
                        'player_username' => $user->username,
                        'player_id' => 'P-' . $user->id,
                        'player_rank' => ['Bronze', 'Silver', 'Gold', 'Platinum'][$index % 4],
                        'players_list' => json_encode([$user->username, 'SubPlayer' . $index]),
                        'contact_phone' => $user->phone,
                        'contact_email' => $user->email,
                        'additional_info' => 'Inscription démo',
                        'accept_terms' => true,
                        'status' => $index < 4 ? 'validated' : 'paid',
                        'transaction_id' => $txId,
                        'fee_paid' => $championship->registration_fee,
                        'current_position' => $index + 1,
                        'matches_won' => max(0, 3 - $index),
                        'matches_lost' => $index % 3,
                        'matches_drawn' => $index % 2,
                        'points' => max(1, 10 - $index),
                        'registered_at' => $this->now->copy()->subDays($index),
                        'validated_at' => $index < 4 ? $this->now->copy()->subDays(1) : null,
                        'paid_at' => $this->now->copy()->subDays(1),
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ]
                );

                $registrationIds[] = DB::table('championship_registrations')
                    ->where('championship_id', $championship->id)
                    ->where('user_id', $user->id)
                    ->value('id');
            }

            for ($i = 0; $i < count($registrationIds) - 1; $i += 2) {
                DB::table('championship_matches')->updateOrInsert(
                    ['championship_id' => $championship->id, 'round_number' => 1, 'player1_id' => $registrationIds[$i], 'player2_id' => $registrationIds[$i + 1]],
                    [
                        'player1_odds' => 1.85,
                        'draw_odds' => 2.40,
                        'player2_odds' => 2.05,
                        'player1_score' => $i === 0 ? 2 : null,
                        'player2_score' => $i === 0 ? 1 : null,
                        'winner_id' => $i === 0 ? $registrationIds[$i] : null,
                        'status' => $i === 0 ? 'completed' : 'scheduled',
                        'scheduled_at' => $this->now->copy()->addDays(15)->addHours($i),
                        'started_at' => $i === 0 ? $this->now->copy()->subHours(2) : null,
                        'completed_at' => $i === 0 ? $this->now->copy()->subHour() : null,
                        'match_details' => 'Match de championnat démo.',
                        'admin_notes' => 'Créé automatiquement par DemoDataSeeder.',
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ]
                );
            }
        }

        return $championships;
    }

    private function seedStreams(array $users, array $gameNames): void
    {
        $players = array_values($users);
        $rows = [
            [$players[3], 'Live ranked PUBG Mobile', $gameNames[0] ?? 'PUBG Mobile', true],
            [$players[5], 'Training Free Fire', $gameNames[1] ?? 'Garena Free Fire', true],
            [$players[0], 'Replay finale démo', $gameNames[2] ?? 'Mobile Legends: Bang Bang', false],
        ];

        foreach ($rows as $index => [$user, $title, $game, $live]) {
            $streamKey = 'demo-stream-' . ($index + 1);
            DB::table('streams')->updateOrInsert(
                ['stream_key' => $streamKey],
                [
                    'user_id' => $user->id,
                    'title' => $title,
                    'description' => 'Stream de démonstration pour tester la page live.',
                    'rtmp_url' => 'rtmp://127.0.0.1/live/' . $streamKey,
                    'hls_url' => '/hls/' . $streamKey . '.m3u8',
                    'category' => 'Gaming',
                    'game' => $game,
                    'viewer_count' => $live ? 80 + ($index * 15) : 0,
                    'follower_count' => 120 + ($index * 20),
                    'is_live' => $live,
                    'use_twitch' => false,
                    'started_at' => $live ? $this->now->copy()->subMinutes(45) : $this->now->copy()->subDays(2),
                    'ended_at' => $live ? null : $this->now->copy()->subDays(2)->addHours(2),
                    'settings' => json_encode(['chat_enabled' => true, 'demo' => true]),
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $streams = DB::table('streams')->whereIn('stream_key', ['demo-stream-1', 'demo-stream-2', 'demo-stream-3'])->get();
        foreach ($streams as $stream) {
            DB::table('stream_sessions')->updateOrInsert(
                ['session_id' => 'session-' . $stream->stream_key],
                [
                    'stream_id' => $stream->id,
                    'status' => $stream->is_live ? 'live' : 'ended',
                    'peak_viewers' => $stream->viewer_count + 30,
                    'total_viewers' => $stream->viewer_count + 250,
                    'started_at' => $stream->started_at,
                    'ended_at' => $stream->ended_at,
                    'metadata' => json_encode(['source' => 'demo']),
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );

            foreach (array_slice($players, 0, 5) as $index => $user) {
                DB::table('stream_followers')->updateOrInsert(
                    ['stream_id' => $stream->id, 'user_id' => $user->id],
                    [
                        'notifications_enabled' => $index % 2 === 0,
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ]
                );

                DB::table('stream_chat_messages')->insert([
                    'stream_id' => $stream->id,
                    'user_id' => $user->id,
                    'message' => 'Chat démo: super live !',
                    'type' => 'message',
                    'is_moderator' => $index === 0,
                    'is_subscriber' => $index % 2 === 0,
                    'is_deleted' => false,
                    'created_at' => $this->now->copy()->subMinutes($index * 3),
                    'updated_at' => $this->now,
                ]);
            }
        }
    }

    private function seedCertificationAndAgents(array $users): void
    {
        $adminId = DB::table('users')->where('email', 'admin@ebetstream.com')->value('id');
        foreach (array_slice(array_values($users), 0, 6) as $index => $user) {
            DB::table('certification_requests')->updateOrInsert(
                ['professional_email' => 'cert-' . $user->email],
                [
                    'user_id' => $user->id,
                    'type' => ['organizer', 'referee', 'ambassador'][$index % 3],
                    'status' => ['pending', 'under_review', 'approved'][$index % 3],
                    'full_name' => str_replace('Demo', 'Demo ', $user->username),
                    'birth_date' => $this->now->copy()->subYears(22 + $index)->toDateString(),
                    'date_of_birth' => $this->now->copy()->subYears(22 + $index)->toDateString(),
                    'id_type' => 'national_id',
                    'id_number' => 'DEMO-ID-' . $user->id,
                    'country' => 'Bénin',
                    'city' => 'Cotonou',
                    'phone' => $user->phone,
                    'username' => $user->username,
                    'experience' => 'Expérience fictive de tournoi et animation communautaire.',
                    'availability' => 'Soirs et week-ends',
                    'technical_skills' => 'Streaming, arbitrage, gestion Discord',
                    'specific_documents' => json_encode(['demo-document.pdf']),
                    'submitted_at' => $this->now->copy()->subDays(4),
                    'reviewed_at' => $index % 3 === 2 ? $this->now->copy()->subDay() : null,
                    'reviewed_by' => $index % 3 === 2 ? $adminId : null,
                    'notes' => 'Demande de certification démo.',
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );

            DB::table('certifications')->updateOrInsert(
                ['user_id' => $user->id, 'title' => 'Certification Démo ' . ($index + 1)],
                [
                    'file' => null,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );

            DB::table('agent_requests')->updateOrInsert(
                ['email' => 'agent-' . $user->email],
                [
                    'name' => str_replace('Demo', 'Demo ', $user->username),
                    'whatsapp' => $user->phone,
                    'message' => 'Je souhaite devenir agent local de démonstration.',
                    'status' => ['pending', 'approved', 'rejected'][$index % 3],
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'birth_date' => $this->now->copy()->subYears(24 + $index)->toDateString(),
                    'city' => 'Cotonou',
                    'occupation' => 'Entrepreneur',
                    'experience' => 'Paiements mobiles et support client',
                    'skills' => 'Communication, fiabilité, gestion cash',
                    'availability' => 'Disponible',
                    'working_hours' => '09:00-20:00',
                    'motivation' => 'Aider les joueurs à recharger rapidement.',
                    'has_id_card' => 'yes',
                    'has_business_license' => $index % 2 ? 'yes' : 'no',
                    'agree_terms' => true,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        DB::table('referees')->updateOrInsert(
            ['user_id' => array_values($users)[4]->id],
            [
                'speciality' => 'Battle royale et MOBA mobile',
                'verified' => true,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]
        );
    }

    private function seedAmbassadorsAndPartners(): void
    {
        $ambassadors = [
            ['name' => 'Amina Kora', 'username' => 'AminaLive', 'score' => 1320, 'country' => 'Bénin', 'position' => 1],
            ['name' => 'Kevin N’Guessan', 'username' => 'KevElite', 'score' => 1240, 'country' => 'Côte d’Ivoire', 'position' => 2],
            ['name' => 'Maya Stream', 'username' => 'MayaStream', 'score' => 1180, 'country' => 'Sénégal', 'position' => 3],
        ];

        foreach ($ambassadors as $ambassador) {
            DB::table('ambassadors')->updateOrInsert(
                ['username' => $ambassador['username']],
                [
                    'name' => $ambassador['name'],
                    'score' => $ambassador['score'],
                    'country' => $ambassador['country'],
                    'bio' => 'Ambassadeur fictif pour valoriser la communauté eBetStream.',
                    'position' => $ambassador['position'],
                    'is_active' => true,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $partners = [
            ['name' => 'DemoPay Mobile', 'specialty' => 'Paiements mobiles', 'country' => 'Bénin'],
            ['name' => 'Arena Connect', 'specialty' => 'Événements esport', 'country' => 'Togo'],
            ['name' => 'StreamKit Africa', 'specialty' => 'Streaming gaming', 'country' => 'Sénégal'],
        ];

        foreach ($partners as $index => $partner) {
            DB::table('partners')->updateOrInsert(
                ['name' => $partner['name']],
                [
                    'specialty' => $partner['specialty'],
                    'website' => 'https://example.com/' . Str::slug($partner['name']),
                    'bio' => 'Partenaire fictif pour tester la page partenaires.',
                    'country' => $partner['country'],
                    'position' => $index + 1,
                    'is_active' => true,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }
    }

    private function seedBallonDor(array $users, array $clans, array $teams): void
    {
        DB::table('seasons')->updateOrInsert(
            ['slug' => 'saison-demo-2026'],
            [
                'name' => 'Saison Démo 2026',
                'description' => 'Saison fictive pour les votes Ballon d’Or.',
                'start_date' => $this->now->copy()->subMonth()->toDateString(),
                'end_date' => $this->now->copy()->addMonths(2)->toDateString(),
                'voting_start_date' => $this->now->copy()->subDays(2)->toDateString(),
                'voting_end_date' => $this->now->copy()->addDays(20)->toDateString(),
                'status' => 'voting',
                'is_current' => true,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]
        );

        $seasonId = DB::table('seasons')->where('slug', 'saison-demo-2026')->value('id');
        foreach (['player', 'clan', 'team'] as $category) {
            DB::table('ballon_dor_voting_rules')->updateOrInsert(
                ['season_id' => $seasonId, 'category' => $category],
                [
                    'community_can_vote' => true,
                    'players_can_vote' => true,
                    'federations_can_vote' => $category !== 'player',
                    'min_participations' => 1,
                    'max_votes_per_category' => 3,
                    'additional_rules' => json_encode(['demo' => true]),
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $nominations = [
            ['player', 'Joueur', array_values($users)[0]->id, 'App\\Models\\User'],
            ['player', 'Joueur', array_values($users)[2]->id, 'App\\Models\\User'],
            ['clan', 'Clan', array_values($clans)[0]->id ?? null, 'App\\Models\\Clan'],
            ['team', 'Équipe', array_values($teams)[0]->id ?? null, 'App\\Models\\Team'],
        ];

        foreach ($nominations as $index => [$category, $label, $nomineeId, $type]) {
            if (!$nomineeId) {
                continue;
            }

            DB::table('ballon_dor_nominations')->updateOrInsert(
                ['season_id' => $seasonId, 'category' => $category, 'nominee_id' => $nomineeId, 'nominee_type' => $type],
                [
                    'category_label' => $label,
                    'description' => 'Nomination de démonstration.',
                    'achievements' => json_encode(['Top performance', 'Fair-play']),
                    'vote_count' => 10 - $index,
                    'rank' => $index + 1,
                    'is_winner' => $index === 0,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $nominationIds = DB::table('ballon_dor_nominations')->where('season_id', $seasonId)->pluck('id')->all();
        foreach (array_slice(array_values($users), 0, 6) as $index => $user) {
            $nominationId = $nominationIds[$index % count($nominationIds)] ?? null;
            if (!$nominationId) {
                continue;
            }
            $nomination = DB::table('ballon_dor_nominations')->where('id', $nominationId)->first();
            DB::table('ballon_dor_votes')->updateOrInsert(
                ['season_id' => $seasonId, 'nomination_id' => $nominationId, 'voter_id' => $user->id, 'voter_type' => 'App\\Models\\User'],
                [
                    'category' => $nomination->category,
                    'points' => 1 + ($index % 3),
                    'comment' => 'Vote démo.',
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }
    }

    private function seedMarketplace(array $teams, array $users): void
    {
        foreach (array_values($teams) as $index => $team) {
            DB::table('team_marketplace_listings')->updateOrInsert(
                ['team_id' => $team->id, 'listing_type' => $index % 2 ? 'loan' : 'sale'],
                [
                    'seller_id' => $team->owner_id ?? array_values($users)[0]->id,
                    'price' => $index % 2 ? null : 500 + ($index * 100),
                    'loan_fee' => $index % 2 ? 75 + ($index * 10) : null,
                    'loan_duration_days' => $index % 2 ? 14 : null,
                    'conditions' => 'Annonce marketplace de démonstration.',
                    'status' => 'active',
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }
    }

    private function seedMonetization(): void
    {
        $settings = [
            'platform_commission_percentage' => 8,
            'streamer_bonus_threshold' => 100,
            'withdrawal_minimum' => 10,
        ];

        foreach ($settings as $key => $value) {
            DB::table('monetization_settings')->updateOrInsert(
                ['setting_key' => $key],
                [
                    'setting_value' => json_encode($value),
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $agentTiers = [
            ['Bronze', 0, 2.5, 1.5, 0, 1],
            ['Silver', 1000, 3.5, 2.0, 100, 2],
            ['Gold', 5000, 5.0, 2.5, 300, 3],
        ];

        foreach ($agentTiers as [$name, $volume, $deposit, $withdrawal, $guarantee, $sort]) {
            DB::table('agent_tiers')->updateOrInsert(
                ['name' => $name],
                [
                    'min_monthly_volume' => $volume,
                    'deposit_commission_percentage' => $deposit,
                    'withdrawal_commission_percentage' => $withdrawal,
                    'requires_guarantee_amount' => $guarantee,
                    'is_active' => true,
                    'sort_order' => $sort,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }

        $streamerTiers = [
            ['Starter', 0, 499, 3.0, 1],
            ['Creator', 500, 4999, 5.0, 2],
            ['Elite', 5000, null, 8.0, 3],
        ];

        foreach ($streamerTiers as [$name, $min, $max, $commission, $sort]) {
            DB::table('streamer_tiers')->updateOrInsert(
                ['name' => $name],
                [
                    'min_followers' => $min,
                    'max_followers' => $max,
                    'commission_percentage' => $commission,
                    'benefits' => json_encode(['Badge démo', 'Mise en avant']),
                    'is_active' => true,
                    'sort_order' => $sort,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]
            );
        }
    }

    private function seedBetting(array $users, array $championships): void
    {
        $players = array_values($users);

        $championshipMatches = DB::table('championship_matches')->limit(4)->get();
        foreach ($championshipMatches as $index => $match) {
            $user = $players[($index + 3) % count($players)];
            DB::table('bets')->insert([
                'user_id' => $user->id,
                'championship_match_id' => $match->id,
                'bet_type' => $index % 2 ? 'player2_win' : 'player1_win',
                'amount' => 15 + ($index * 5),
                'potential_win' => 30 + ($index * 7),
                'status' => $index === 0 ? 'won' : 'pending',
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }

        $challenges = DB::table('challenges')->limit(3)->get();
        foreach ($challenges as $index => $challenge) {
            DB::table('bets')->insert([
                'user_id' => $players[($index + 5) % count($players)]->id,
                'challenge_id' => $challenge->id,
                'bet_type' => $index % 2 ? 'opponent_win' : 'creator_win',
                'amount' => 8 + ($index * 4),
                'potential_win' => 14 + ($index * 6),
                'status' => 'pending',
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }
}
