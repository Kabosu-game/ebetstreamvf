<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Stream;
use App\Models\StreamSession;
use App\Models\StreamChatMessage;
use App\Models\StreamFollower;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class StreamController extends Controller
{
    /**
     * URL de base du serveur WebRTC (signaling Node.js)
     * Défini dans .env : STREAM_WS_URL=wss://ton-domaine.com/ws
     */
    private function wsBaseUrl(): string
    {
        return rtrim(env('STREAM_WS_URL', 'ws://localhost:8082'), '/');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUBLIC
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Liste tous les streams (live en premier)
     */
    public function index(Request $request)
    {
        $query = Stream::with(['user', 'sessions'])
            ->orderBy('is_live', 'desc')
            ->orderBy('viewer_count', 'desc');

        if ($request->boolean('live_only')) {
            $query->live();
        }

        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }

        if ($request->filled('game')) {
            $query->byGame($request->game);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        $streams = $query->paginate($request->integer('per_page', 20));

        return response()->json(['success' => true, 'data' => $streams]);
    }

    /**
     * Créer un stream (un seul par utilisateur)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (Stream::where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà un stream. Utilisez la mise à jour pour le modifier.',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category'    => 'nullable|string|max:100',
            'game'        => 'nullable|string|max:100',
            'thumbnail'   => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $streamData = [
            'user_id'     => $user->id,
            'title'       => $request->title,
            'description' => $request->description,
            'category'    => $request->category,
            'game'        => $request->game,
            'is_live'     => false,
            'use_twitch'  => false,
        ];

        if ($request->hasFile('thumbnail')) {
            $streamData['thumbnail'] = $request->file('thumbnail')
                ->store('streams/thumbnails', 'public');
        }

        $stream = Stream::create($streamData);
        $stream->load('user');

        $data = $stream->toArray();
        $data['thumbnail_url'] = $stream->thumbnail_url;
        $data['ws_urls']       = $this->buildWsUrls($stream);

        return response()->json([
            'success' => true,
            'message' => 'Stream créé avec succès',
            'data'    => $data,
        ], 201);
    }

    /**
     * Afficher un stream
     */
    public function show($id)
    {
        $stream = Stream::with(['user', 'sessions', 'followers'])->findOrFail($id);

        $data = $stream->toArray();
        $data['thumbnail_url'] = $stream->thumbnail_url;
        $data['ws_urls']       = $this->buildWsUrls($stream);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Mettre à jour un stream
     */
    public function update(Request $request, $id)
    {
        $user   = $request->user();
        $stream = Stream::where('user_id', $user->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category'    => 'nullable|string|max:100',
            'game'        => 'nullable|string|max:100',
            'thumbnail'   => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $updateData = $request->only(['title', 'description', 'category', 'game']);

        if ($request->hasFile('thumbnail')) {
            // Supprimer l'ancienne miniature si elle existe en storage local
            if ($stream->thumbnail && Storage::disk('public')->exists($stream->thumbnail)) {
                Storage::disk('public')->delete($stream->thumbnail);
            }
            $updateData['thumbnail'] = $request->file('thumbnail')
                ->store('streams/thumbnails', 'public');
        }

        $stream->update($updateData);
        $stream->load('user');

        $data = $stream->toArray();
        $data['thumbnail_url'] = $stream->thumbnail_url;
        $data['ws_urls']       = $this->buildWsUrls($stream);

        return response()->json([
            'success' => true,
            'message' => 'Stream mis à jour avec succès',
            'data'    => $data,
        ]);
    }

    /**
     * Démarrer un stream (go live)
     */
    public function start(Request $request, $id)
    {
        $user   = $request->user();
        $stream = Stream::where('user_id', $user->id)->findOrFail($id);

        Log::info('[Stream::start] user=' . $user->id . ' stream=' . $stream->id . ' is_live=' . ($stream->is_live ? 'TRUE' : 'false'));

        // Auto-fix : si déjà marqué live (session orpheline), on remet à zéro
        if ($stream->is_live) {
            Log::warning('[Stream::start] Reset forcé d\'une session orpheline');
            $stream->update(['is_live' => false]);
            StreamSession::where('stream_id', $stream->id)
                ->where('status', 'live')
                ->update(['status' => 'ended', 'ended_at' => now()]);
        }

        DB::beginTransaction();
        try {
            $stream->update([
                'is_live'    => true,
                'started_at' => now(),
            ]);

            $session = StreamSession::create([
                'stream_id'  => $stream->id,
                'status'     => 'live',
                'started_at' => now(),
            ]);

            DB::commit();

            Log::info('[Stream::start] ✅ session_id=' . $session->id);

            return response()->json([
                'success' => true,
                'message' => 'Stream démarré',
                'data'    => [
                    'stream'  => $stream->load('user'),
                    'session' => $session,
                    'ws_urls' => $this->buildWsUrls($stream),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Stream::start] ❌ ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur au démarrage : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Arrêter un stream
     */
    public function stop(Request $request, $id)
    {
        $user   = $request->user();
        $stream = Stream::where('user_id', $user->id)->findOrFail($id);

        if (!$stream->is_live) {
            return response()->json(['success' => false, 'message' => 'Le stream n\'est pas en direct'], 400);
        }

        DB::beginTransaction();
        try {
            $stream->sessions()
                ->where('status', 'live')
                ->latest()
                ->first()
                ?->update(['status' => 'ended', 'ended_at' => now()]);

            $stream->update([
                'is_live'      => false,
                'ended_at'     => now(),
                'viewer_count' => 0,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stream arrêté',
                'data'    => $stream->load('user'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur à l\'arrêt : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retourne les infos de connexion WebRTC pour le streamer
     * (token Sanctum de l'utilisateur courant + URLs WS)
     */
    public function getStreamKey(Request $request)
    {
        $user   = $request->user();
        $stream = Stream::where('user_id', $user->id)->first();

        if (!$stream) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun stream trouvé. Créez-en un d\'abord.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'stream_id' => $stream->id,
                'is_live'   => $stream->is_live,
                'ws_urls'   => $this->buildWsUrls($stream),
                // Le token Sanctum de l'utilisateur courant est passé par le frontend
                // via l'en-tête Authorization ; on le renvoie ici pour faciliter la
                // connexion WebSocket depuis le composant Vue.
                'token_hint' => 'use your Bearer token',
            ],
        ]);
    }

    /**
     * Mise à jour du nombre de viewers (appelé par le serveur Node.js)
     */
    public function updateViewers(Request $request, $id)
    {
        $stream = Stream::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'count' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream->update(['viewer_count' => $request->count]);

        return response()->json(['success' => true, 'data' => ['viewer_count' => $stream->viewer_count]]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FOLLOW
    // ──────────────────────────────────────────────────────────────────────────

    public function toggleFollow(Request $request, $id)
    {
        $user   = $request->user();
        $stream = Stream::findOrFail($id);

        $follower = StreamFollower::where('stream_id', $stream->id)
            ->where('user_id', $user->id)
            ->first();

        if ($follower) {
            $follower->delete();
            $stream->decrement('follower_count');
            $isFollowing = false;
        } else {
            StreamFollower::create(['stream_id' => $stream->id, 'user_id' => $user->id]);
            $stream->increment('follower_count');
            $isFollowing = true;
        }

        return response()->json([
            'success' => true,
            'message' => $isFollowing ? 'Vous suivez ce stream' : 'Vous ne suivez plus ce stream',
            'data'    => [
                'is_following'   => $isFollowing,
                'follower_count' => $stream->fresh()->follower_count,
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CHAT
    // ──────────────────────────────────────────────────────────────────────────

    public function getChatMessages(Request $request, $id)
    {
        $stream = Stream::findOrFail($id);

        $messages = StreamChatMessage::where('stream_id', $stream->id)
            ->notDeleted()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->limit($request->integer('limit', 50))
            ->get()
            ->reverse()
            ->values();

        return response()->json(['success' => true, 'data' => $messages]);
    }

    public function sendChatMessage(Request $request, $id)
    {
        $user   = $request->user();
        $stream = Stream::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'message'  => 'required|string|max:500',
            'reply_to' => 'nullable|exists:stream_chat_messages,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $message = StreamChatMessage::create([
            'stream_id'    => $stream->id,
            'user_id'      => $user->id,
            'message'      => $request->message,
            'reply_to'     => $request->reply_to,
            'is_moderator' => false,
            'is_subscriber' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message envoyé',
            'data'    => $message->load('user'),
        ], 201);
    }

    public function deleteChatMessage(Request $request, $id, $messageId)
    {
        $user   = $request->user();
        $stream = Stream::findOrFail($id);

        if ($stream->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        StreamChatMessage::where('stream_id', $stream->id)
            ->findOrFail($messageId)
            ->update(['is_deleted' => true]);

        return response()->json(['success' => true, 'message' => 'Message supprimé']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ADMIN
    // ──────────────────────────────────────────────────────────────────────────

    public function adminIndex(Request $request)
    {
        $query = Stream::with(['user', 'sessions'])
            ->orderBy('is_live', 'desc')
            ->orderBy('viewer_count', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->boolean('live_only')) {
            $query->live();
        }
        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }
        if ($request->filled('game')) {
            $query->byGame($request->game);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%')
                    ->orWhereHas('user', fn($u) => $u->where('username', 'like', '%' . $request->search . '%'));
            });
        }

        $streams = $query->paginate($request->integer('per_page', 50));

        $stats = [
            'total_streams'   => Stream::count(),
            'live_streams'    => Stream::where('is_live', true)->count(),
            'total_viewers'   => Stream::where('is_live', true)->sum('viewer_count'),
            'total_followers' => Stream::sum('follower_count'),
        ];

        return response()->json(['success' => true, 'data' => $streams, 'stats' => $stats]);
    }

    public function adminShow($id)
    {
        $stream = Stream::with(['user', 'sessions', 'followers', 'chatMessages'])->findOrFail($id);

        $stats = [
            'total_sessions'         => $stream->sessions()->count(),
            'total_chat_messages'    => $stream->chatMessages()->notDeleted()->count(),
            'total_followers'        => $stream->followers()->count(),
            'total_viewers_all_time' => $stream->sessions()->sum('total_viewers'),
            'peak_viewers'           => $stream->sessions()->max('peak_viewers'),
        ];

        $data = $stream->toArray();
        $data['ws_urls'] = $this->buildWsUrls($stream);

        return response()->json(['success' => true, 'data' => $data, 'stats' => $stats]);
    }

    public function adminUpdate(Request $request, $id)
    {
        $stream = Stream::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category'    => 'nullable|string|max:100',
            'game'        => 'nullable|string|max:100',
            'thumbnail'   => 'nullable|url',
            'is_live'     => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream->update($request->only(['title', 'description', 'category', 'game', 'thumbnail', 'is_live']));

        return response()->json([
            'success' => true,
            'message' => 'Stream mis à jour',
            'data'    => $stream->load('user'),
        ]);
    }

    public function adminDestroy($id)
    {
        $stream = Stream::findOrFail($id);

        $stream->sessions()->delete();
        $stream->chatMessages()->delete();
        $stream->followers()->delete();
        $stream->delete();

        return response()->json(['success' => true, 'message' => 'Stream supprimé']);
    }

    public function forceStop($id)
    {
        $stream = Stream::findOrFail($id);

        if (!$stream->is_live) {
            return response()->json(['success' => false, 'message' => 'Le stream n\'est pas en direct'], 400);
        }

        DB::beginTransaction();
        try {
            $stream->sessions()
                ->where('status', 'live')
                ->latest()
                ->first()
                ?->update(['status' => 'ended', 'ended_at' => now()]);

            $stream->update(['is_live' => false, 'ended_at' => now(), 'viewer_count' => 0]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Stream arrêté (admin)', 'data' => $stream->load('user')]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()], 500);
        }
    }

    public function getSessions($id)
    {
        $stream   = Stream::findOrFail($id);
        $sessions = $stream->sessions()->orderBy('started_at', 'desc')->get();

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    public function adminDeleteChatMessage($id, $messageId)
    {
        $stream = Stream::findOrFail($id);

        StreamChatMessage::where('stream_id', $stream->id)
            ->findOrFail($messageId)
            ->update(['is_deleted' => true]);

        return response()->json(['success' => true, 'message' => 'Message supprimé']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // INTERNE (appelé par Node.js uniquement, protégé par middleware internal.token)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Node.js vérifie via /api/auth/me que le token est valide.
     * Cet endpoint permet à Node.js de récupérer les infos du stream si besoin.
     */
    public function internalGetStreamInfo(Request $request, $id)
    {
        $stream = Stream::findOrFail($id);

        return response()->json([
            'success'   => true,
            'stream_id' => $stream->id,
            'is_live'   => $stream->is_live,
            'title'     => $stream->title,
        ]);
    }

    /**
     * Mise à jour du viewer count par Node.js
     * Route : POST /api/internal/streams/{id}/viewer-count
     */
    public function internalUpdateViewerCount(Request $request, $id)
    {
        $stream = Stream::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'count' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream->update(['viewer_count' => $request->count]);

        Log::info("[ViewerCount] Stream #{$id} → {$request->count} viewer(s)");

        return response()->json(['success' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HELPER PRIVÉ
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Construit les URLs WebSocket pour streamer et viewer.
     *
     * Streamer → wss://domaine/ws/stream/{stream_id}?token=SANCTUM_TOKEN
     * Viewer   → wss://domaine/ws/watch/{stream_id}?token=SANCTUM_TOKEN
     *
     * Le token est ajouté côté frontend (le composant Vue connaît le token
     * de l'utilisateur connecté via le store Pinia/Vuex).
     */
    private function buildWsUrls(Stream $stream): array
    {
        $base = $this->wsBaseUrl();

        return [
            'streamer' => "{$base}/stream/{$stream->id}",
            'viewer'   => "{$base}/watch/{$stream->id}",
        ];
    }
}
