<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallengeScreenRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected User $creator;
    protected User $opponent;
    protected Challenge $challenge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creator = User::factory()->create();
        $this->opponent = User::factory()->create();
        $this->challenge = Challenge::create([
            'creator_id' => $this->creator->id,
            'opponent_id' => $this->opponent->id,
            'game' => 'FIFA',
            'bet_amount' => 1000,
            'status' => 'accepted',
            'type' => 'user',
        ]);
    }

    public function test_start_screen_recording_as_creator(): void
    {
        $response = $this->actingAs($this->creator, 'api')
            ->postJson("/api/challenges/{$this->challenge->id}/screen-recording/start", []);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'recording' => true,
                    'is_live' => true,
                ]
            ])
            ->assertJsonStructure([
                'data' => ['stream_key', 'rtmp_url', 'stream_url', 'screen_stream_url']
            ]);

        $this->challenge->refresh();
        $this->assertTrue($this->challenge->is_live);
        $this->assertTrue($this->challenge->creator_screen_recording);
        $this->assertNotNull($this->challenge->stream_key);
        $this->assertNotNull($this->challenge->live_started_at);
    }

    public function test_start_screen_recording_requires_auth(): void
    {
        $response = $this->postJson("/api/challenges/{$this->challenge->id}/screen-recording/start", []);
        $response->assertStatus(401);
    }

    public function test_start_screen_recording_forbidden_for_opponent(): void
    {
        $response = $this->actingAs($this->opponent, 'api')
            ->postJson("/api/challenges/{$this->challenge->id}/screen-recording/start", []);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Only the challenge creator can start screen recording and live streaming']);
    }

    public function test_start_screen_recording_requires_accepted_or_in_progress(): void
    {
        $this->challenge->update(['status' => 'open']);
        $response = $this->actingAs($this->creator, 'api')
            ->postJson("/api/challenges/{$this->challenge->id}/screen-recording/start", []);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Challenge must be accepted and started before starting live stream']);
    }

    public function test_stop_screen_recording_as_creator(): void
    {
        $this->challenge->update([
            'is_live' => true,
            'creator_screen_recording' => true,
            'live_started_at' => now(),
        ]);

        $response = $this->actingAs($this->creator, 'api')
            ->postJson("/api/challenges/{$this->challenge->id}/screen-recording/stop", []);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'recording' => false,
                    'is_live' => false,
                ]
            ]);

        $this->challenge->refresh();
        $this->assertFalse($this->challenge->is_live);
        $this->assertFalse($this->challenge->creator_screen_recording);
        $this->assertNotNull($this->challenge->live_ended_at);
    }

    public function test_get_live_stream_when_live(): void
    {
        $this->challenge->update([
            'is_live' => true,
            'creator_screen_recording' => true,
            'stream_key' => 'test_key',
            'stream_url' => 'http://example.com/stream.m3u8',
            'live_started_at' => now(),
        ]);

        $response = $this->getJson("/api/challenges/{$this->challenge->id}/live");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_live' => true,
                    'peer_id' => 'challenge_' . $this->challenge->id,
                ]
            ])
            ->assertJsonStructure([
                'data' => ['challenge', 'stream_url', 'viewer_count', 'live_started_at']
            ]);
    }

    public function test_get_live_stream_fails_when_not_live(): void
    {
        $response = $this->getJson("/api/challenges/{$this->challenge->id}/live");
        $response->assertStatus(400)
            ->assertJson(['message' => 'This challenge is not currently live']);
    }

    public function test_live_challenges_list_includes_live_challenge(): void
    {
        $this->challenge->update([
            'is_live' => true,
            'creator_screen_recording' => true,
            'live_started_at' => now(),
        ]);

        $response = $this->getJson('/api/challenges/live/list');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotNull($data);
        $ids = collect($data)->pluck('id')->toArray();
        $this->assertContains($this->challenge->id, $ids);
    }

    public function test_pause_and_resume_screen_recording(): void
    {
        $this->challenge->update([
            'is_live' => true,
            'creator_screen_recording' => true,
            'live_started_at' => now(),
        ]);

        $pause = $this->actingAs($this->creator, 'api')
            ->postJson("/api/challenges/{$this->challenge->id}/screen-recording/pause", []);
        $pause->assertStatus(200);
        $this->challenge->refresh();
        $this->assertTrue($this->challenge->is_live_paused ?? false);

        $resume = $this->actingAs($this->creator, 'api')
            ->postJson("/api/challenges/{$this->challenge->id}/screen-recording/resume", []);
        $resume->assertStatus(200);
        $this->challenge->refresh();
        $this->assertFalse($this->challenge->is_live_paused ?? true);
    }
}
