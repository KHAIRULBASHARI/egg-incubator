<?php

namespace Tests\Feature;

use App\Models\CaptureJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EggCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected string $validKey = 'EggCamSecretKey_9876543210_abcdef1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.device.key', $this->validKey);
        Storage::fake('public');
    }

    public function test_poll_without_key_returns_401(): void
    {
        $response = $this->get('/api/device/poll');
        $response->assertStatus(401);
    }

    public function test_poll_with_invalid_key_returns_401(): void
    {
        $response = $this->withHeader('X-Device-Key', 'wrong-key')
            ->get('/api/device/poll');
        $response->assertStatus(401);
    }

    public function test_poll_without_job_returns_200_with_none(): void
    {
        $response = $this->withHeader('X-Device-Key', $this->validKey)
            ->get('/api/device/poll');

        $response->assertStatus(200);
        $this->assertSame('NONE', $response->getContent());
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_poll_with_job_returns_job_params_and_marks_taken(): void
    {
        $job = CaptureJob::create([
            'exposure' => 600,
            'gain' => 10,
            'quality' => 5,
            'settle_ms' => 300,
            'status' => 'pending',
        ]);

        $response = $this->withHeaders([
            'X-Device-Key' => $this->validKey,
            'X-RSSI' => '-65',
        ])->get('/api/device/poll');

        $response->assertStatus(200);
        $expectedBody = "job={$job->id};exp=600;gain=10;q=5;delay=300";
        $this->assertSame($expectedBody, $response->getContent());

        $job->refresh();
        $this->assertSame('taken', $job->status);
        $this->assertNotNull($job->taken_at);

        $this->assertSame(-65, Cache::get('device:rssi'));
        $this->assertNotNull(Cache::get('device:last_seen'));
    }

    protected function postRaw(string $uri, string $content, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $server = $this->transformHeadersToServerVars(array_merge([
            'X-Device-Key' => $this->validKey,
            'Content-Type' => 'image/jpeg',
        ], $headers));

        return $this->call('POST', $uri, [], [], [], $server, $content);
    }

    public function test_upload_valid_jpeg_returns_ok_and_marks_done(): void
    {
        $job = CaptureJob::create([
            'status' => 'taken',
            'taken_at' => now(),
        ]);

        // Generate dummy valid JPEG bytes >= 1024 bytes
        $fakeJpeg = "\xFF\xD8".str_repeat('A', 1500)."\xFF\xD9";

        $response = $this->postRaw("/api/device/upload?job={$job->id}", $fakeJpeg);

        $response->assertStatus(200);
        $this->assertSame('OK', $response->getContent());

        $job->refresh();
        $this->assertSame('done', $job->status);
        $this->assertNotNull($job->image_base64);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $job->image_base64);
    }

    public function test_upload_non_jpeg_returns_422(): void
    {
        $job = CaptureJob::create([
            'status' => 'taken',
            'taken_at' => now(),
        ]);

        $fakePng = "\x89PNG\r\n\x1a\n".str_repeat('B', 1500);

        $response = $this->postRaw("/api/device/upload?job={$job->id}", $fakePng, [
            'Content-Type' => 'application/octet-stream',
        ]);

        $response->assertStatus(422);
    }

    public function test_upload_invalid_size_returns_422(): void
    {
        $job = CaptureJob::create([
            'status' => 'taken',
            'taken_at' => now(),
        ]);

        // Too small (< 1024 bytes)
        $tinyJpeg = "\xFF\xD8".str_repeat('A', 10)."\xFF\xD9";

        $response = $this->postRaw("/api/device/upload?job={$job->id}", $tinyJpeg);

        $response->assertStatus(422);
    }

    public function test_upload_job_not_in_taken_status_returns_409(): void
    {
        $job = CaptureJob::create([
            'status' => 'pending',
        ]);

        $fakeJpeg = "\xFF\xD8".str_repeat('A', 1500)."\xFF\xD9";

        $response = $this->postRaw("/api/device/upload?job={$job->id}", $fakeJpeg);

        $response->assertStatus(409);
    }

    public function test_upload_with_fail_query_marks_failed(): void
    {
        $job = CaptureJob::create([
            'status' => 'taken',
            'taken_at' => now(),
        ]);

        $response = $this->withHeaders([
            'X-Device-Key' => $this->validKey,
        ])->post("/api/device/upload?job={$job->id}&fail=1");

        $response->assertStatus(200);
        $this->assertSame('OK', $response->getContent());

        $job->refresh();
        $this->assertSame('failed', $job->status);
    }

    public function test_expired_taken_job_marked_failed_on_poll(): void
    {
        $staleJob = CaptureJob::create([
            'status' => 'taken',
            'taken_at' => now()->subSeconds(65),
        ]);

        $response = $this->withHeader('X-Device-Key', $this->validKey)
            ->get('/api/device/poll');

        $response->assertStatus(200);

        $staleJob->refresh();
        $this->assertSame('failed', $staleJob->status);
    }

    public function test_egg_status_endpoint(): void
    {
        // No last seen -> offline
        Cache::forget('device:last_seen');
        Cache::forget('device:rssi');

        $response = $this->get('/egg/status');
        $response->assertStatus(200);
        $response->assertJson([
            'online' => false,
            'last_seen_sec' => null,
            'rssi' => null,
        ]);

        // Polled recently (<15s) -> online
        Cache::put('device:last_seen', now()->timestamp - 5, 3600);
        Cache::put('device:rssi', -50, 3600);

        $responseOnline = $this->get('/egg/status');
        $responseOnline->assertStatus(200);
        $responseOnline->assertJson([
            'online' => true,
            'rssi' => -50,
        ]);
        $this->assertLessThanOrEqual(6, $responseOnline->json('last_seen_sec'));

        // Polled long ago (>15s) -> offline
        Cache::put('device:last_seen', now()->timestamp - 20, 3600);

        $responseOffline = $this->get('/egg/status');
        $responseOffline->assertStatus(200);
        $responseOffline->assertJson([
            'online' => false,
        ]);
        $this->assertGreaterThanOrEqual(20, $responseOffline->json('last_seen_sec'));
    }

    public function test_egg_capture_creates_job_and_returns_id(): void
    {
        $response = $this->postJson('/egg/capture', [
            'exposure' => 700,
            'gain' => 5,
            'quality' => 8,
            'settle_ms' => 400,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['job']);

        $jobId = $response->json('job');
        $this->assertDatabaseHas('capture_jobs', [
            'id' => $jobId,
            'exposure' => 700,
            'gain' => 5,
            'quality' => 8,
            'settle_ms' => 400,
            'status' => 'pending',
        ]);
    }

    public function test_egg_job_show_endpoint(): void
    {
        $job = CaptureJob::create([
            'status' => 'done',
            'image_path' => 'eggs/job-99.jpg',
        ]);

        $response = $this->getJson("/egg/job/{$job->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'done',
            'url' => Storage::disk('public')->url('eggs/job-99.jpg'),
        ]);
    }

    public function test_egg_history_returns_list_of_done_jobs(): void
    {
        CaptureJob::create(['status' => 'pending']);
        CaptureJob::create(['status' => 'failed']);

        $done1 = CaptureJob::create([
            'status' => 'done',
            'image_path' => 'eggs/job-1.jpg',
        ]);
        $done2 = CaptureJob::create([
            'status' => 'done',
            'image_path' => 'eggs/job-2.jpg',
        ]);

        $response = $this->getJson('/egg/history');
        $response->assertStatus(200);
        $response->assertJsonCount(2);

        $response->assertJsonFragment([
            'id' => $done2->id,
            'url' => Storage::disk('public')->url($done2->image_path),
        ]);
    }

    public function test_dashboard_renders_successfully(): void
    {
        $response = $this->get('/dashboard');
        $response->assertStatus(200);
        $response->assertSee('Egg Candling & Capture', false);
    }
}
