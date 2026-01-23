<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\ChampionshipRegistration;
use App\Models\ChampionshipMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class AdminChampionshipController extends Controller
{
    /**
     * Get all championships (admin)
     */
    public function index(Request $request)
    {
        $query = Championship::withCount(['registrations', 'validatedRegistrations', 'matches'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('game')) {
            $query->where('game', $request->game);
        }

        if ($request->has('division')) {
            $query->where('division', $request->division);
        }

        $championships = $query->get();

        return response()->json([
            'success' => true,
            'data' => $championships,
        ]);
    }

    /**
     * Create a new championship
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'game' => 'required|string|max:255',
            'division' => 'required|in:1,2,3',
            'description' => 'nullable|string',
            'rules' => 'nullable|string',
            'registration_fee' => 'required|numeric|min:0',
            'total_prize_pool' => 'nullable|numeric|min:0',
            'prize_distribution' => 'nullable|array',
            'registration_start_date' => 'required|date',
            'registration_end_date' => 'required|date|after:registration_start_date',
            'start_date' => 'required|date|after:registration_end_date',
            'end_date' => 'nullable|date|after:start_date',
            'max_participants' => 'required|integer|min:2',
            'min_participants' => 'required|integer|min:2',
            'banner_image' => 'nullable|image|max:5120',
            'thumbnail_image' => 'nullable|image|max:5120',
        ]);

        DB::beginTransaction();

        try {
            $championship = new Championship($validated);
            $championship->status = 'draft';
            $championship->is_active = true;

            // Handle image uploads
            if ($request->hasFile('banner_image')) {
                $championship->banner_image = $request->file('banner_image')->store('championships/banners', 'public');
            }

            if ($request->hasFile('thumbnail_image')) {
                $championship->thumbnail_image = $request->file('thumbnail_image')->store('championships/thumbnails', 'public');
            }

            $championship->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Championnat créé avec succès',
                'data' => $championship,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a championship
     */
    public function update(Request $request, $id)
    {
        $championship = Championship::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'game' => 'sometimes|required|string|max:255',
            'division' => 'sometimes|required|in:1,2,3',
            'description' => 'nullable|string',
            'rules' => 'nullable|string',
            'registration_fee' => 'sometimes|required|numeric|min:0',
            'total_prize_pool' => 'nullable|numeric|min:0',
            'prize_distribution' => 'nullable|array',
            'registration_start_date' => 'sometimes|required|date',
            'registration_end_date' => 'sometimes|required|date|after:registration_start_date',
            'start_date' => 'sometimes|required|date|after:registration_end_date',
            'end_date' => 'nullable|date|after:start_date',
            'max_participants' => 'sometimes|required|integer|min:2',
            'min_participants' => 'sometimes|required|integer|min:2',
            'status' => 'sometimes|required|in:draft,registration_open,registration_closed,validated,started,finished,cancelled',
            'banner_image' => 'nullable|image|max:5120',
            'thumbnail_image' => 'nullable|image|max:5120',
            'is_active' => 'sometimes|boolean',
            'admin_notes' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // Handle image uploads
            if ($request->hasFile('banner_image')) {
                if ($championship->banner_image) {
                    Storage::disk('public')->delete($championship->banner_image);
                }
                $championship->banner_image = $request->file('banner_image')->store('championships/banners', 'public');
            }

            if ($request->hasFile('thumbnail_image')) {
                if ($championship->thumbnail_image) {
                    Storage::disk('public')->delete($championship->thumbnail_image);
                }
                $championship->thumbnail_image = $request->file('thumbnail_image')->store('championships/thumbnails', 'public');
            }

            $championship->fill($validated);
            $championship->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Championnat mis à jour avec succès',
                'data' => $championship,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a championship
     */
    public function destroy($id)
    {
        $championship = Championship::findOrFail($id);

        // Prevent deletion if championship has started
        if (in_array($championship->status, ['started', 'finished'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un championnat en cours ou terminé',
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Delete images
            if ($championship->banner_image) {
                Storage::disk('public')->delete($championship->banner_image);
            }
            if ($championship->thumbnail_image) {
                Storage::disk('public')->delete($championship->thumbnail_image);
            }

            $championship->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Championnat supprimé avec succès',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get championship registrations
     */
    public function registrations($id)
    {
        $championship = Championship::findOrFail($id);

        $registrations = ChampionshipRegistration::where('championship_id', $id)
            ->with(['user:id,username,email', 'transaction', 'team'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $registrations,
        ]);
    }

    /**
     * Validate or reject a registration
     */
    public function validateRegistration(Request $request, $id, $registrationId)
    {
        $championship = Championship::findOrFail($id);
        $registration = ChampionshipRegistration::where('championship_id', $id)
            ->findOrFail($registrationId);

        $validated = $request->validate([
            'action' => 'required|in:validate,reject',
            'notes' => 'nullable|string',
        ]);

        if ($validated['action'] === 'validate') {
            // Check if championship is full
            if ($championship->isFull()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le championnat est complet',
                ], 400);
            }

            $registration->status = 'validated';
            $registration->validated_at = now();
        } else {
            $registration->status = 'rejected';
            
            // Refund if paid
            if ($registration->transaction_id && $registration->status === 'paid') {
                // Refund logic here
            }
        }

        $registration->save();

        return response()->json([
            'success' => true,
            'message' => 'Inscription ' . ($validated['action'] === 'validate' ? 'validée' : 'rejetée'),
            'data' => $registration,
        ]);
    }

    /**
     * Generate matches for a championship (when it starts)
     */
    public function generateMatches($id)
    {
        $championship = Championship::findOrFail($id);

        if ($championship->status !== 'validated' && $championship->status !== 'started') {
            return response()->json([
                'success' => false,
                'message' => 'Le championnat doit être validé avant de générer les matchs',
            ], 400);
        }

        $participants = $championship->validatedRegistrations()->pluck('id')->toArray();

        if (count($participants) < $championship->min_participants) {
            return response()->json([
                'success' => false,
                'message' => 'Nombre insuffisant de participants. Minimum requis: ' . $championship->min_participants,
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Simple round-robin or elimination tournament logic here
            // For now, we'll create a basic structure
            
            $roundNumber = 1;
            $remainingParticipants = $participants;

            while (count($remainingParticipants) > 1) {
                for ($i = 0; $i < count($remainingParticipants) - 1; $i += 2) {
                    ChampionshipMatch::create([
                        'championship_id' => $id,
                        'round_number' => $roundNumber,
                        'player1_id' => $remainingParticipants[$i],
                        'player2_id' => $remainingParticipants[$i + 1] ?? null,
                        'status' => 'scheduled',
                        'scheduled_at' => $championship->start_date,
                    ]);
                }
                
                $roundNumber++;
                // Winners will advance (logic to be implemented based on match results)
                break; // Simplified for now
            }

            $championship->current_round = 1;
            $championship->status = 'started';
            $championship->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Matchs générés avec succès',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération des matchs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get championship matches
     */
    public function matches($id)
    {
        $championship = Championship::findOrFail($id);

        $matches = ChampionshipMatch::where('championship_id', $id)
            ->with(['player1.user', 'player2.user', 'winner'])
            ->orderBy('round_number', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $matches,
        ]);
    }

    /**
     * Update match result
     */
    public function updateMatchResult(Request $request, $id, $matchId)
    {
        $championship = Championship::findOrFail($id);
        $match = ChampionshipMatch::where('championship_id', $id)
            ->findOrFail($matchId);

        $validated = $request->validate([
            'player1_score' => 'required|integer|min:0',
            'player2_score' => 'required|integer|min:0',
            'winner_id' => 'nullable|exists:championship_registrations,id',
        ]);

        $match->setResult(
            $validated['player1_score'],
            $validated['player2_score'],
            $validated['winner_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Résultat du match mis à jour',
            'data' => $match->load(['player1.user', 'player2.user', 'winner']),
        ]);
    }
}

