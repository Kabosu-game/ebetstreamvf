<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArenaMatch extends Model
{
    protected $fillable = [
        'team1_name',
        'team2_name',
        'team1_score',
        'team2_score',
        'team1_odds',
        'team2_odds',
        'mode',
        'league_tier',
        'status',
        'winner_team',
        'max_players_per_team',
        'match_state',
        'created_by',
        'scheduled_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'team1_score' => 'integer',
        'team2_score' => 'integer',
        'team1_odds' => 'decimal:2',
        'team2_odds' => 'decimal:2',
        'max_players_per_team' => 'integer',
        'match_state' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function players(): HasMany
    {
        return $this->hasMany(ArenaMatchPlayer::class);
    }

    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    public function setResult(int $team1Score, int $team2Score, ?string $winnerTeam = null): void
    {
        if ($this->status === 'completed') {
            return;
        }

        $this->team1_score = $team1Score;
        $this->team2_score = $team2Score;
        $this->status = 'completed';
        $this->completed_at = now();

        if ($winnerTeam) {
            $this->winner_team = $winnerTeam;
        } elseif ($team1Score > $team2Score) {
            $this->winner_team = 'team1';
        } elseif ($team2Score > $team1Score) {
            $this->winner_team = 'team2';
        } else {
            $this->winner_team = 'draw';
        }

        $this->save();
        $this->updatePlayerStats();
        $this->resolveBets();
    }

    public function startLive(): void
    {
        $this->status = 'live';
        $this->started_at = now();
        if (!$this->scheduled_at) {
            $this->scheduled_at = now();
        }
        $this->save();
    }

    public function cancelMatch(): void
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return;
        }

        $this->refundPendingBets();
        $this->status = 'cancelled';
        $this->completed_at = now();
        $this->save();
    }

    protected function refundPendingBets(): void
    {
        $bets = Bet::where('arena_match_id', $this->id)
            ->where('status', 'pending')
            ->get();

        foreach ($bets as $bet) {
            $wallet = Wallet::where('user_id', $bet->user_id)->first();
            if ($wallet) {
                $wallet->locked_balance = max(0, $wallet->locked_balance - $bet->amount);
                $wallet->save();
            }
            $bet->status = 'cancelled';
            $bet->save();
        }
    }

    protected function updatePlayerStats(): void
    {
        $players = $this->players()->get();

        foreach ($players as $player) {
            $profile = ArenaPlayerProfile::firstOrCreate(
                ['user_id' => $player->user_id],
                ['player_class' => $player->player_class]
            );

            $profile->matches_played++;
            $won = ($this->winner_team === $player->team);
            if ($won) {
                $profile->matches_won++;
                $profile->points += 10;
                $profile->mmr += 25;
            } elseif ($this->winner_team !== 'draw') {
                $profile->matches_lost++;
                $profile->mmr = max(0, $profile->mmr - 15);
            } else {
                $profile->points += 3;
            }

            $profile->level = max(1, (int) floor($profile->mmr / 200));
            $profile->rank = $this->resolveRank($profile->mmr);
            $profile->save();
        }
    }

    protected function resolveRank(int $mmr): string
    {
        if ($mmr >= 2000) return 'champion';
        if ($mmr >= 1600) return 'elite';
        if ($mmr >= 1200) return 'gold';
        if ($mmr >= 800) return 'silver';
        return 'bronze';
    }

    protected function resolveBets(): void
    {
        $bets = Bet::where('arena_match_id', $this->id)
            ->where('status', 'pending')
            ->get();

        $winningBetType = match ($this->winner_team) {
            'team1' => 'team1_win',
            'team2' => 'team2_win',
            default => 'draw',
        };

        foreach ($bets as $bet) {
            $wallet = Wallet::where('user_id', $bet->user_id)->first();
            if (!$wallet) {
                continue;
            }

            $wallet->locked_balance -= $bet->amount;

            if ($bet->bet_type === $winningBetType) {
                $wallet->balance += $bet->potential_win;
                $bet->status = 'won';
            } else {
                $bet->status = 'lost';
            }

            $wallet->save();
            $bet->save();
        }
    }
}
