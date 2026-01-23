<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class EventController extends Controller
{
    /**
     * Liste tous les événements
     */
    public function index(Request $request)
    {
        // S'assurer que toutes les colonnes existent si on filtre par status
        if ($request->has('status')) {
            $this->ensureEventColumnsExist();
        }
        
        $query = Event::query();

        // Filtres par statut (draft, published, cancelled)
        if ($request->has('status') && in_array($request->status, ['draft', 'published', 'cancelled'])) {
            $query->where('status', $request->status);
        }

        // Filtres par période (upcoming, ongoing, past)
        if ($request->has('time')) {
            switch ($request->time) {
                case 'upcoming':
                    $query->upcoming();
                    break;
                case 'ongoing':
                    $query->ongoing();
                    break;
                case 'past':
                    $query->past();
                    break;
            }
        }

        // Filtre par type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Recherche par titre, description, location
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%");
            });
        }

        // Tri
        $sortBy = $request->get('sort_by', 'start_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $limit = $request->get('limit', 20);
        $events = $query->paginate($limit);

        // Formater les données
        $events->getCollection()->transform(function ($event) {
            return $this->formatEvent($event);
        });

        return response()->json([
            'success' => true,
            'data' => $events
        ]);
    }

    /**
     * Afficher un événement spécifique
     */
    public function show($id)
    {
        $event = Event::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->formatEvent($event)
        ]);
    }

    /**
     * S'assurer que toutes les colonnes nécessaires existent dans la table events
     */
    private function ensureEventColumnsExist()
    {
        if (!Schema::hasColumn('events', 'image')) {
            DB::statement('ALTER TABLE `events` ADD COLUMN `image` VARCHAR(255) NULL AFTER `location`');
        }
        if (!Schema::hasColumn('events', 'status')) {
            DB::statement("ALTER TABLE `events` ADD COLUMN `status` ENUM('draft', 'published', 'cancelled') DEFAULT 'published' AFTER `image`");
        }
        if (!Schema::hasColumn('events', 'type')) {
            DB::statement('ALTER TABLE `events` ADD COLUMN `type` VARCHAR(100) NULL AFTER `status`');
        }
        if (!Schema::hasColumn('events', 'max_participants')) {
            DB::statement('ALTER TABLE `events` ADD COLUMN `max_participants` INT NULL AFTER `type`');
        }
        if (!Schema::hasColumn('events', 'registration_deadline')) {
            DB::statement('ALTER TABLE `events` ADD COLUMN `registration_deadline` TIMESTAMP NULL AFTER `max_participants`');
        }
    }

    /**
     * Créer un nouvel événement (Admin)
     */
    public function store(Request $request)
    {
        try {
            // S'assurer que toutes les colonnes existent
            $this->ensureEventColumnsExist();
            
            \Log::info('Event store request:', $request->all());
            
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:50000',
                'start_at' => 'required|date',
                'end_at' => 'nullable|date|after:start_at',
                'location' => 'nullable|string|max:255',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'status' => 'nullable|in:draft,published,cancelled',
                'type' => 'nullable|string|max:100',
                'max_participants' => 'nullable|integer|min:1',
                'registration_deadline' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                \Log::error('Event validation failed:', $validator->errors()->toArray());
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = [];
            
            // Récupérer les données une par une pour éviter les problèmes avec FormData
            if ($request->has('title')) {
                $data['title'] = $request->title;
            }
            if ($request->has('description')) {
                $data['description'] = $request->description;
            }
            if ($request->has('start_at')) {
                $data['start_at'] = $request->start_at;
            }
            if ($request->has('end_at') && $request->end_at) {
                $data['end_at'] = $request->end_at;
            }
            if ($request->has('location') && $request->location) {
                $data['location'] = $request->location;
            }
            if ($request->has('status')) {
                $data['status'] = $request->status;
            } else {
                $data['status'] = 'published';
            }
            if ($request->has('type') && $request->type) {
                $data['type'] = $request->type;
            }
            if ($request->has('max_participants') && $request->max_participants) {
                $data['max_participants'] = (int)$request->max_participants;
            }
            if ($request->has('registration_deadline') && $request->registration_deadline) {
                $data['registration_deadline'] = $request->registration_deadline;
            }

            // Gérer l'upload de l'image
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('events', 'public');
                $data['image'] = $path;
            } elseif ($request->has('image_url') && $request->image_url) {
                // Permettre aussi une URL d'image
                $data['image'] = $request->image_url;
            }

            \Log::info('Event data to create:', $data);

            $event = Event::create($data);

            \Log::info('Event created successfully:', ['id' => $event->id]);

            return response()->json([
                'success' => true,
                'message' => 'Event created successfully',
                'data' => $this->formatEvent($event)
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating event:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating event: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour un événement (Admin)
     */
    public function update(Request $request, $id)
    {
        // S'assurer que toutes les colonnes existent
        $this->ensureEventColumnsExist();
        
        $event = Event::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:50000',
            'start_at' => 'sometimes|date',
            'end_at' => 'nullable|date|after:start_at',
            'location' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'status' => 'nullable|in:draft,published,cancelled',
            'type' => 'nullable|string|max:100',
            'max_participants' => 'nullable|integer|min:1',
            'registration_deadline' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = [];
        
        // Mettre à jour uniquement les champs fournis
        if ($request->has('title')) {
            $data['title'] = $request->title;
        }
        if ($request->has('description')) {
            $data['description'] = $request->description;
        }
        if ($request->has('start_at')) {
            $data['start_at'] = $request->start_at;
        }
        if ($request->has('end_at')) {
            $data['end_at'] = $request->end_at;
        }
        if ($request->has('location')) {
            $data['location'] = $request->location;
        }
        if ($request->has('status')) {
            $data['status'] = $request->status;
        }
        if ($request->has('type')) {
            $data['type'] = $request->type;
        }
        if ($request->has('max_participants')) {
            $data['max_participants'] = $request->max_participants;
        }
        if ($request->has('registration_deadline')) {
            $data['registration_deadline'] = $request->registration_deadline;
        }

        // Gérer l'upload de l'image
        if ($request->hasFile('image')) {
            // Supprimer l'ancienne image si elle existe
            if ($event->image && Storage::disk('public')->exists($event->image)) {
                Storage::disk('public')->delete($event->image);
            }
            $path = $request->file('image')->store('events', 'public');
            $data['image'] = $path;
        } elseif ($request->has('image_url')) {
            // Supprimer l'ancienne image si elle existe
            if ($event->image && Storage::disk('public')->exists($event->image)) {
                Storage::disk('public')->delete($event->image);
            }
            $data['image'] = $request->image_url;
        }

        if (!empty($data)) {
            $event->update($data);
        }

        return response()->json([
            'success' => true,
            'message' => 'Event updated successfully',
            'data' => $this->formatEvent($event->fresh())
        ]);
    }

    /**
     * Supprimer un événement (Admin)
     */
    public function destroy($id)
    {
        $event = Event::findOrFail($id);

        // Supprimer l'image si elle existe
        if ($event->image && Storage::disk('public')->exists($event->image)) {
            Storage::disk('public')->delete($event->image);
        }

        $event->delete();

        return response()->json([
            'success' => true,
            'message' => 'Event deleted successfully'
        ]);
    }

    /**
     * S'inscrire à un événement
     */
    public function register(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        // Vérifier si l'événement accepte encore les inscriptions
        if ($event->registration_deadline && now() > $event->registration_deadline) {
            return response()->json([
                'success' => false,
                'message' => 'Registration is closed for this event.'
            ], 400);
        }

        // Vérifier si l'événement a atteint le maximum de participants
        if ($event->max_participants) {
            $currentRegistrations = $event->registrations()->count();
            if ($currentRegistrations >= $event->max_participants) {
                return response()->json([
                    'success' => false,
                    'message' => 'The event has reached the maximum number of participants.'
                ], 400);
            }
        }

        $validator = Validator::make($request->all(), [
            'pseudo' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Vérifier si l'email n'est pas déjà inscrit pour cet événement
        $existingRegistration = EventRegistration::where('event_id', $event->id)
            ->where('email', $request->email)
            ->first();

        if ($existingRegistration) {
            return response()->json([
                'success' => false,
                'message' => 'You are already registered for this event with this email.'
            ], 400);
        }

        $registration = EventRegistration::create([
            'event_id' => $event->id,
            'pseudo' => $request->pseudo,
            'email' => $request->email,
            'phone' => $request->phone,
            'country' => $request->country,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful!',
            'data' => $registration
        ], 201);
    }

    /**
     * Obtenir les inscriptions d'un événement (Admin)
     */
    public function getRegistrations($id)
    {
        $event = Event::findOrFail($id);
        
        $registrations = $event->registrations()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $registrations
        ]);
    }

    /**
     * Formater un événement pour la réponse
     */
    private function formatEvent(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'start_at' => $event->start_at?->toIso8601String(),
            'end_at' => $event->end_at?->toIso8601String(),
            'location' => $event->location,
            'image' => $event->image,
            'image_url' => $event->image_url,
            'status' => $event->status,
            'type' => $event->type,
            'max_participants' => $event->max_participants,
            'registration_deadline' => $event->registration_deadline?->toIso8601String(),
            'registrations_count' => $event->registrations_count,
            'is_upcoming' => $event->isUpcoming(),
            'is_ongoing' => $event->isOngoing(),
            'is_past' => $event->isPast(),
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }
}
