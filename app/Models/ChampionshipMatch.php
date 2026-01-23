<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChampionshipMatch extends Model
{
    protected $fillable = [
        'championship_id',
        'round_number',
        'player1_id',
        'player2_id',
        'player1_odds',
        'draw_odds',
        'player2_odds',
        'player1_score',
        'player2_score',
        'winner_id',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'match_details',
        'admin_notes',
    ];

    protected $casts = [
        'player1_score' => 'integer',
        'player2_score' => 'integer',
        'round_number' => 'integer',
        'player1_odds' => 'decimal:2',
        'draw_odds' => 'decimal:2',
        'player2_odds' => 'decimal:2',
        'match_details' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the championship this match belongs to
     */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /**
     * Get player 1 registration
     */
    public function player1(): BelongsTo
    {
        return $this->belongsTo(ChampionshipRegistration::class, 'player1_id');
    }

    /**
     * Get player 2 registration
     */
    public function player2(): BelongsTo
    {
        return $this->belongsTo(ChampionshipRegistration::class, 'player2_id');
    }

    /**
     * Get winner registration
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(ChampionshipRegistration::class, 'winner_id');
    }

    /**
     * Get all bets on this match
     */
    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    /**
     * Set match result and update player statistics
     */
    public function setResult($player1Score, $player2Score, $winnerId = null)
    {
        $this->player1_score = $player1Score;
        $this->player2_score = $player2Score;
        $this->status = 'completed';
        $this->completed_at = now();

        if ($winnerId) {
            $this->winner_id = $winnerId;
        } else {
            // Determine winner based on scores
            if ($player1Score > $player2Score) {
                $this->winner_id = $this->player1_id;
            } elseif ($player2Score > $player1Score) {
                $this->winner_id = $this->player2_id;
            }
            // If equal, it's a draw (winner_id remains null)
        }

        $this->save();

        // Update player statistics
        $this->updatePlayerStats();

        // Resolve bets
        $this->resolveBets();
    }

    /**
     * Resolve all bets on this match
     */
    protected function resolveBets()
    {
        $bets = Bet::where('championship_match_id', $this->id)
            ->where('status', 'pending')
            ->with('user')
            ->get();

        foreach ($bets as $bet) {
            $wallet = Wallet::where('user_id', $bet->user_id)->first();
            
            if (!$wallet) {
                continue;
            }

            // Determine winning bet type
            $winningBetType = null;
            if ($this->winner_id) {
                if ($this->winner_id === $this->player1_id) {
                    $winningBetType = 'player1_win';
                } else {
                    $winningBetType = 'player2_win';
                }
            } else {
                // Draw
                $winningBetType = 'draw';
            }

            if ($bet->bet_type === $winningBetType) {
                // Winning bet - unlock amount and add winnings
                $wallet->locked_balance -= $bet->amount;
                $wallet->balance += $bet->potential_win;
                $wallet->save();
                $bet->status = 'won';
            } else {
                // Losing bet - unlock amount (it's already deducted)
                $wallet->locked_balance -= $bet->amount;
                $wallet->save();
                $bet->status = 'lost';
            }
            
            $bet->save();
        }
    }

    /**
     * Update statistics for both players
     */
    protected function updatePlayerStats()
    {
        $player1 = $this->player1;
        $player2 = $this->player2;

        if ($this->winner_id) {
            if ($this->winner_id === $this->player1_id) {
                $player1->matches_won++;
                $player2->matches_lost++;
                $player1->points += 3;
            } else {
                $player2->matches_won++;
                $player1->matches_lost++;
                $player2->points += 3;
            }
        } else {
            // Draw
            $player1->matches_drawn++;
            $player2->matches_drawn++;
            $player1->points += 1;
            $player2->points += 1;
        }

        $player1->save();
        $player2->save();
    }
}

