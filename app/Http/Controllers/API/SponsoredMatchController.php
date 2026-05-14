<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SponsoredMatch;
use App\Models\SponsoredMatchParticipant;
use App\Models\MonetizationSetting;
use App\Services\MonetizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SponsoredMatchController extends Controller
{
    /** GET /sponsored-matches */
    public function index(Request $request)
    {
        $matches = SponsoredMatch::with(['organizer:id,username'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $matches]);
    }

    /** GET /sponsored-matches/{id} */
    public function show(int $id)
    {
        $match = SponsoredMatch::with(['organizer:id,username', 'participants.user:id,username'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $match]);
    }

    /**
     * POST /sponsored-matches
     * Organisateur crée un tournoi sponsorisé avec prize pool
     * Split automatique : 60% joueurs / 20% organisateur / 20% plateforme
     */
    public function store(Request $request)
    {
        $organizer = $request->user();

        $v = Validator::make($request->all(), [
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'game'            => 'nullable|string|max:100',
            'prize_pool_total'=> 'required|numeric|min:10',
            'starts_at'       => 'nullable|date|after:now',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'errors' => $v->errors()], 422);
        }

        $split   = MonetizationSetting::where('setting_key', 'sponsored_match_split')
                   ->value('setting_value')
                   ?? ['prize_pool_percent' => 60, 'organizer_streamer_percent' => 20, 'platform_percent' => 20];

        $total           = (float) $request->prize_pool_total;
        $playersPct      = (float) ($split['prize_pool_percent']          ?? 60);
        $organizerPct    = (float) ($split['organizer_streamer_percent']   ?? 20);
        $platformPct     = (float) ($split['platform_percent']            ?? 20);

        $playersPool     = round($total * $playersPct    / 100, 2);
        $organizerPool   = round($total * $organizerPct  / 100, 2);
        $platformPool    = round($total - $playersPool - $organizerPool, 2);

        try {
            $match = DB::transaction(function () use (
                $organizer, $request, $total, $playersPool, $organizerPool, $platformPool
            ) {
                // Organizer must fund the prize pool upfront
                MonetizationService::debitWallet($organizer->id, $total);

                // Platform holds the funds (in platform wallet) until distribution
                MonetizationService::creditWallet(MonetizationService::platformUserId(), $total);

                return SponsoredMatch::create([
                    'organizer_user_id'  => $organizer->id,
                    'title'              => $request->title,
                    'description'        => $request->description,
                    'game'               => $request->game,
                    'prize_pool_total'   => $total,
                    'players_prize'      => $playersPool,
                    'organizer_prize'    => $organizerPool,
                    'platform_prize'     => $platformPool,
                    'status'             => 'open',
                    'starts_at'          => $request->starts_at,
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json(['success' => true, 'data' => $match], 201);
    }

    /**
     * POST /sponsored-matches/{id}/distribute
     * Admin distribue le prize pool aux gagnants
     * players_prize divisé entre les gagnants selon leur placement
     */
    public function distribute(Request $request, int $id)
    {
        $match = SponsoredMatch::with('participants')->findOrFail($id);

        if ($match->distributed) {
            return response()->json(['success' => false, 'message' => 'Already distributed'], 422);
        }

        $v = Validator::make($request->all(), [
            'winners'              => 'required|array|min:1',
            'winners.*.user_id'    => 'required|exists:users,id',
            'winners.*.share_pct'  => 'required|numeric|min:1|max:100',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'errors' => $v->errors()], 422);
        }

        $totalPct = collect($request->winners)->sum('share_pct');
        if (abs($totalPct - 100) > 0.01) {
            return response()->json(['success' => false, 'message' => "Winners share_pct must sum to 100 (got {$totalPct})"], 422);
        }

        try {
            DB::transaction(function () use ($match, $request) {
                // Distribute players prize pool to winners
                foreach ($request->winners as $i => $winner) {
                    $prize = round($match->players_prize * $winner['share_pct'] / 100, 2);

                    // Debit platform (holds the funds), credit winner
                    MonetizationService::debitWallet(MonetizationService::platformUserId(), $prize);
                    MonetizationService::creditWallet($winner['user_id'], $prize);

                    SponsoredMatchParticipant::updateOrCreate(
                        ['sponsored_match_id' => $match->id, 'user_id' => $winner['user_id']],
                        ['placement' => ($i + 1) . 'st', 'prize_received' => $prize]
                    );
                }

                // Pay organizer their 20%
                $organizerPrize = $match->organizer_prize;
                MonetizationService::debitWallet(MonetizationService::platformUserId(), $organizerPrize);
                MonetizationService::creditWallet($match->organizer_user_id, $organizerPrize);

                // Platform keeps platform_prize (already in platform wallet)
                $match->update(['status' => 'completed', 'distributed' => true]);
            });
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Prize pool distribué avec succès',
            'data'    => [
                'players_prize'   => $match->players_prize,
                'organizer_prize' => $match->organizer_prize,
                'platform_prize'  => $match->platform_prize,
            ],
        ]);
    }
}
