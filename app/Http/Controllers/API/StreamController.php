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
use Illuminate\Support\Str;

class StreamController extends Controller
{
    /**
     * Liste tous les streams (live et offline)
     */
    public function index(Request $request)
    {
        $query = Stream::with(['user', 'sessions'])
            ->orderBy('is_live', 'desc')
            ->orderBy('viewer_count', 'desc');

        // Filtres
        if ($request->has('live_only') && $request->live_only) {
            $query->live();
        }

        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('game')) {
            $query->byGame($request->game);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        $streams = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $streams
        ]);
    }

    /**
     * Create a new stream
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'game' => 'nullable|string|max:100',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'use_twitch' => 'nullable|boolean',
            'twitch_username' => 'required|string|max:100',
            'twitch_stream_key' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user already has a stream
        $existingStream = Stream::where('user_id', $user->id)->first();

        if ($existingStream) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a stream. Use update to modify it.'
            ], 400);
        }

        // Always use Twitch
        $useTwitch = true;
        $twitchUsername = $request->input('twitch_username');
        $twitchStreamKey = $request->input('twitch_stream_key');
        
        // Validate Twitch fields (always required)
        if (!$twitchUsername || !$twitchStreamKey) {
            return response()->json([
                'success' => false,
                'message' => 'Twitch username and stream key are required.'
            ], 422);
        }
        
        // Utiliser les variables d'environnement pour les URLs de streaming
        $rtmpBaseUrl = env('RTMP_SERVER_URL', 'rtmp://localhost:1935');
        $hlsBaseUrl = env('HLS_SERVER_URL', 'http://localhost:8888');
        
        $streamData = [
            'user_id' => $user->id,
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category,
            'game' => $request->game,
            'use_twitch' => $useTwitch,
            'twitch_username' => $twitchUsername,
            'twitch_stream_key' => $twitchStreamKey,
            'is_live' => false,
        ];
        
        // Handle thumbnail upload
        if ($request->hasFile('thumbnail')) {
            $path = $request->file('thumbnail')->store('streams/thumbnails', 'public');
            $streamData['thumbnail'] = $path;
        } elseif ($request->has('thumbnail') && $request->thumbnail) {
            // Also allow an image URL (for compatibility)
            $streamData['thumbnail'] = $request->thumbnail;
        }
        
        // For Twitch (always used):
        // - RTMP URL : rtmp://live.twitch.tv/app/[STREAM_KEY]
        // - HLS URL : https://www.twitch.tv/[USERNAME] (embed)
        $streamData['rtmp_url'] = 'rtmp://live.twitch.tv/app/' . $twitchStreamKey;
        $streamData['hls_url'] = 'https://www.twitch.tv/' . $twitchUsername;
        
        $stream = Stream::create($streamData);
        $stream->load('user');
        
        $streamData = $stream->toArray();
        $streamData['thumbnail_url'] = $stream->thumbnail_url;

        return response()->json([
            'success' => true,
            'message' => 'Stream created successfully',
            'data' => $streamData
        ], 201);
    }

    /**
     * Show a specific stream
     */
    public function show($id)
    {
        $stream = Stream::with(['user', 'sessions', 'followers'])
            ->findOrFail($id);

        // Add formatted thumbnail URL
        $streamData = $stream->toArray();
        $streamData['thumbnail_url'] = $stream->thumbnail_url;

        return response()->json([
            'success' => true,
            'data' => $streamData
        ]);
    }

    /**
     * Update a stream
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $stream = Stream::where('user_id', $user->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'game' => 'nullable|string|max:100',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'use_twitch' => 'nullable|boolean',
            'twitch_username' => 'required|string|max:100',
            'twitch_stream_key' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Always use Twitch
        $twitchUsername = $request->input('twitch_username');
        $twitchStreamKey = $request->input('twitch_stream_key');
        
        // Validate Twitch fields (always required)
        if (!$twitchUsername || !$twitchStreamKey) {
            return response()->json([
                'success' => false,
                'message' => 'Twitch username and stream key are required.'
            ], 422);
        }
        
        $updateData = [
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'category' => $request->input('category'),
            'game' => $request->input('game'),
            'twitch_username' => $twitchUsername,
            'twitch_stream_key' => $twitchStreamKey,
        ];
        
        // Handle thumbnail upload
        if ($request->hasFile('thumbnail')) {
            // Supprimer l'ancienne image si elle existe
            if ($stream->thumbnail && Storage::disk('public')->exists($stream->thumbnail)) {
                Storage::disk('public')->delete($stream->thumbnail);
            }
            $path = $request->file('thumbnail')->store('streams/thumbnails', 'public');
            $updateData['thumbnail'] = $path;
        } elseif ($request->has('thumbnail') && $request->thumbnail) {
            // Also allow an image URL (for compatibility)
            $updateData['thumbnail'] = $request->thumbnail;
        }
        
        // Always use Twitch
        $updateData['use_twitch'] = true;
        $updateData['rtmp_url'] = 'rtmp://live.twitch.tv/app/' . $twitchStreamKey;
        $updateData['hls_url'] = 'https://www.twitch.tv/' . $twitchUsername;
        
        $stream->update($updateData);

        $streamData = $stream->load('user')->toArray();
        $streamData['thumbnail_url'] = $stream->thumbnail_url;

        return response()->json([
            'success' => true,
            'message' => 'Stream updated successfully',
            'data' => $streamData
        ]);
    }

    /**
     * Start a stream (go live)
     */
    public function start(Request $request, $id)
    {
        $user = $request->user();
        $stream = Stream::where('user_id', $user->id)->findOrFail($id);

        if ($stream->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'Stream is already live'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $stream->update([
                'is_live' => true,
                'started_at' => now(),
            ]);

            $session = StreamSession::create([
                'stream_id' => $stream->id,
                'status' => 'live',
                'started_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stream started successfully',
                'data' => [
                    'stream' => $stream->load('user'),
                    'session' => $session
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error starting stream: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stop a stream
     */
    public function stop(Request $request, $id)
    {
        $user = $request->user();
        $stream = Stream::where('user_id', $user->id)->findOrFail($id);

        if (!$stream->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'Stream is not live'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $activeSession = $stream->sessions()
                ->where('status', 'live')
                ->latest()
                ->first();

            if ($activeSession) {
                $activeSession->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                ]);
            }

            $stream->update([
                'is_live' => false,
                'ended_at' => now(),
                'viewer_count' => 0,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stream stopped successfully',
                'data' => $stream->load('user')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error stopping stream: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stream key for the streamer
     */
    public function getStreamKey(Request $request)
    {
        $user = $request->user();
        $stream = Stream::where('user_id', $user->id)->first();

        if (!$stream) {
            return response()->json([
                'success' => false,
                'message' => 'No stream found. Please create a stream first.'
            ], 404);
        }

        // If Twitch is used
        if ($stream->use_twitch) {
            return response()->json([
                'success' => true,
                'data' => [
                    'stream_id' => $stream->id,
                    'use_twitch' => true,
                    'twitch_username' => $stream->twitch_username,
                    'rtmp_url' => $stream->rtmp_url, // rtmp://live.twitch.tv/app/[KEY]
                    'hls_url' => $stream->hls_url, // https://www.twitch.tv/[USERNAME]
                    'stream_key' => $stream->twitch_stream_key, // For OBS
                ]
            ]);
        }
        
        // Pour MediaMTX/Node Media Server
        $rtmpUrl = $stream->rtmp_url;
        if (!$rtmpUrl || !str_contains($rtmpUrl, '/live/')) {
            $rtmpBaseUrl = env('RTMP_SERVER_URL', 'rtmp://localhost:1935');
            $rtmpUrl = rtrim($rtmpBaseUrl, '/') . '/' . $stream->stream_key;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stream_id' => $stream->id,
                'use_twitch' => false,
                'stream_key' => $stream->stream_key,
                'rtmp_url' => $rtmpUrl,
                'hls_url' => $stream->hls_url,
            ]
        ]);
    }

    /**
     * Update viewer count
     */
    public function updateViewers(Request $request, $id)
    {
        $stream = Stream::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'viewer_count' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $stream->update([
            'viewer_count' => $request->viewer_count
        ]);

        return response()->json([
            'success' => true,
            'data' => $stream
        ]);
    }

    /**
     * Suivre/Ne plus suivre un stream
     */
    public function toggleFollow(Request $request, $id)
    {
        $user = $request->user();
        $stream = Stream::findOrFail($id);

        $follower = StreamFollower::where('stream_id', $stream->id)
            ->where('user_id', $user->id)
            ->first();

        if ($follower) {
            $follower->delete();
            $stream->decrement('follower_count');
            $isFollowing = false;
        } else {
            StreamFollower::create([
                'stream_id' => $stream->id,
                'user_id' => $user->id,
            ]);
            $stream->increment('follower_count');
            $isFollowing = true;
        }

        return response()->json([
            'success' => true,
            'message' => $isFollowing ? 'Vous suivez maintenant ce stream' : 'Vous ne suivez plus ce stream',
            'data' => [
                'is_following' => $isFollowing,
                'follower_count' => $stream->fresh()->follower_count
            ]
        ]);
    }

    /**
     * Obtenir les messages du chat
     */
    public function getChatMessages(Request $request, $id)
    {
        $stream = Stream::findOrFail($id);

        $messages = StreamChatMessage::where('stream_id', $stream->id)
            ->notDeleted()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->limit($request->get('limit', 50))
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * Envoyer un message dans le chat
     */
    public function sendChatMessage(Request $request, $id)
    {
        $user = $request->user();
        $stream = Stream::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:500',
            'reply_to' => 'nullable|exists:stream_chat_messages,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $message = StreamChatMessage::create([
            'stream_id' => $stream->id,
            'user_id' => $user->id,
            'message' => $request->message,
            'reply_to' => $request->reply_to,
            'is_moderator' => false, // TODO: Check permissions
            'is_subscriber' => false, // TODO: Check subscription
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message sent',
            'data' => $message->load('user')
        ], 201);
    }

    /**
     * Delete a chat message (moderation)
     */
    public function deleteChatMessage(Request $request, $id, $messageId)
    {
        $user = $request->user();
        $stream = Stream::findOrFail($id);

        // Check that user is the stream owner or a moderator
        if ($stream->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $chatMessage = StreamChatMessage::where('stream_id', $stream->id)
            ->findOrFail($messageId);

        $chatMessage->update(['is_deleted' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Message deleted'
        ]);
    }

    /**
     * Liste tous les streams pour l'admin
     */
    public function adminIndex(Request $request)
    {
        $query = Stream::with(['user', 'sessions'])
            ->orderBy('is_live', 'desc')
            ->orderBy('viewer_count', 'desc')
            ->orderBy('created_at', 'desc');

        // Filtres
        if ($request->has('live_only') && $request->live_only) {
            $query->live();
        }

        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('game')) {
            $query->byGame($request->game);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%')
                  ->orWhereHas('user', function($userQuery) use ($request) {
                      $userQuery->where('username', 'like', '%' . $request->search . '%');
                  });
            });
        }

        $streams = $query->paginate($request->get('per_page', 50));

        // Statistiques
        $stats = [
            'total_streams' => Stream::count(),
            'live_streams' => Stream::where('is_live', true)->count(),
            'total_viewers' => Stream::where('is_live', true)->sum('viewer_count'),
            'total_followers' => Stream::sum('follower_count'),
        ];

        return response()->json([
            'success' => true,
            'data' => $streams,
            'stats' => $stats
        ]);
    }

    /**
     * Show a specific stream for admin
     */
    public function adminShow($id)
    {
        $stream = Stream::with(['user', 'sessions', 'followers', 'chatMessages'])
            ->findOrFail($id);

        // Stream statistics
        $stats = [
            'total_sessions' => $stream->sessions()->count(),
            'total_chat_messages' => $stream->chatMessages()->notDeleted()->count(),
            'total_followers' => $stream->followers()->count(),
            'total_viewers_all_time' => $stream->sessions()->sum('total_viewers'),
            'peak_viewers' => $stream->sessions()->max('peak_viewers'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stream,
            'stats' => $stats
        ]);
    }

    /**
     * Update a stream (admin)
     */
    public function adminUpdate(Request $request, $id)
    {
        $stream = Stream::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:100',
            'game' => 'nullable|string|max:100',
            'thumbnail' => 'nullable|url',
            'is_live' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $stream->update($request->only([
            'title', 'description', 'category', 'game', 'thumbnail', 'is_live'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Stream updated successfully',
            'data' => $stream->load('user')
        ]);
    }

    /**
     * Supprimer un stream (admin)
     */
    public function adminDestroy($id)
    {
        $stream = Stream::findOrFail($id);

        // Delete associated sessions
        $stream->sessions()->delete();
        
        // Delete chat messages
        $stream->chatMessages()->delete();
        
        // Delete followers
        $stream->followers()->delete();

        // Delete the stream
        $stream->delete();

        return response()->json([
            'success' => true,
            'message' => 'Stream deleted successfully'
        ]);
    }

    /**
     * Force stop a stream (admin)
     */
    public function forceStop($id)
    {
        $stream = Stream::findOrFail($id);

        if (!$stream->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'Stream is not live'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $activeSession = $stream->sessions()
                ->where('status', 'live')
                ->latest()
                ->first();

            if ($activeSession) {
                $activeSession->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                ]);
            }

            $stream->update([
                'is_live' => false,
                'ended_at' => now(),
                'viewer_count' => 0,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stream stopped successfully',
                'data' => $stream->load('user')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error stopping stream: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les sessions d'un stream
     */
    public function getSessions($id)
    {
        $stream = Stream::findOrFail($id);
        
        $sessions = $stream->sessions()
            ->orderBy('started_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sessions
        ]);
    }

    /**
     * Supprimer un message du chat (admin - peut supprimer n'importe quel message)
     */
    public function adminDeleteChatMessage($id, $messageId)
    {
        $stream = Stream::findOrFail($id);
        
        $chatMessage = StreamChatMessage::where('stream_id', $stream->id)
            ->findOrFail($messageId);

        $chatMessage->update(['is_deleted' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully'
        ]);
    }
}
