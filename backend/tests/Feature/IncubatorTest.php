<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IncubatorTest extends TestCase
{
    use RefreshDatabase;

    protected string $validKey = 'EggCamSecretKey_9876543210_abcdef1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.device.key', $this->validKey);
        Config::set('services.incubator.api_url', 'http://127.0.0.1:5001/api/incubator/detect');
        Cache::flush();
    }

    protected function postRaw(string $uri, string $content, array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars(array_merge([
            'X-Device-Key' => $this->validKey,
            'Content-Type' => 'image/jpeg',
        ], $headers));

        return $this->call('POST', $uri, [], [], [], $server, $content);
    }

    public function test_upload_without_key_returns_401(): void
    {
        $fakeJpeg = "\xFF\xD8".str_repeat('A', 1500)."\xFF\xD9";
        $response = $this->call('POST', '/api/incubator/upload', [], [], [], [
            'CONTENT_TYPE' => 'image/jpeg',
        ], $fakeJpeg);

        $response->assertStatus(401);
    }

    public function test_upload_with_invalid_key_returns_401(): void
    {
        $fakeJpeg = "\xFF\xD8".str_repeat('A', 1500)."\xFF\xD9";
        $response = $this->postRaw('/api/incubator/upload', $fakeJpeg, [
            'X-Device-Key' => 'invalid-key',
        ]);

        $response->assertStatus(401);
    }

    public function test_upload_non_jpeg_returns_422(): void
    {
        $fakePng = "\x89PNG\r\n\x1a\n".str_repeat('B', 1500);
        $response = $this->postRaw('/api/incubator/upload', $fakePng, [
            'Content-Type' => 'application/octet-stream',
        ]);

        $response->assertStatus(422);
    }

    public function test_upload_too_small_returns_422(): void
    {
        $tinyJpeg = "\xFF\xD8".str_repeat('A', 10)."\xFF\xD9";
        $response = $this->postRaw('/api/incubator/upload', $tinyJpeg);

        $response->assertStatus(422);
    }

    public function test_upload_raw_jpeg_success(): void
    {
        Http::fake([
            'http://127.0.0.1:5001/api/incubator/detect' => Http::response([
                'status' => 'success',
                'total_eggs' => 4,
                'detections' => [
                    ['class' => 'egg', 'confidence' => 0.95, 'box' => [10, 20, 100, 120]],
                    ['class' => 'egg', 'confidence' => 0.92, 'box' => [110, 25, 200, 125]],
                    ['class' => 'egg', 'confidence' => 0.88, 'box' => [15, 130, 105, 230]],
                    ['class' => 'egg', 'confidence' => 0.91, 'box' => [115, 135, 205, 235]],
                ],
            ], 200),
        ]);

        $fakeJpeg = "\xFF\xD8".str_repeat('A', 2000)."\xFF\xD9";
        $response = $this->postRaw('/api/incubator/upload', $fakeJpeg, [
            'X-RSSI' => '-55',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'total_eggs' => 4,
        ]);

        $this->assertNotNull(Cache::get('incubator:latest_image'));
        $this->assertStringStartsWith('data:image/jpeg;base64,', Cache::get('incubator:latest_image'));
        $this->assertNotNull(Cache::get('incubator:last_seen'));
        $this->assertSame(4, Cache::get('incubator:total_eggs'));
        $this->assertCount(4, Cache::get('incubator:detections'));
        $this->assertSame(-55, Cache::get('incubator:rssi'));

        Http::assertSent(function ($request) {
            return $request->url() === 'http://127.0.0.1:5001/api/incubator/detect';
        });
    }

    public function test_upload_multipart_success(): void
    {
        Http::fake([
            'http://127.0.0.1:5001/api/incubator/detect' => Http::response([
                'status' => 'success',
                'count' => 2,
                'detections' => [
                    ['class' => 'egg', 'confidence' => 0.94],
                    ['class' => 'egg', 'confidence' => 0.89],
                ],
            ], 200),
        ]);

        $file = UploadedFile::fake()->image('egg_incubator.jpg', 640, 480);

        $response = $this->withHeaders([
            'X-Device-Key' => $this->validKey,
        ])->post('/api/incubator/upload', [
            'image' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'total_eggs' => 2,
        ]);

        $this->assertSame(2, Cache::get('incubator:total_eggs'));
        $this->assertCount(2, Cache::get('incubator:detections'));
    }

    public function test_upload_handles_yolo_offline_gracefully(): void
    {
        Http::fake([
            'http://127.0.0.1:5001/api/incubator/detect' => Http::response('Server Error', 500),
        ]);

        $fakeJpeg = "\xFF\xD8".str_repeat('A', 2000)."\xFF\xD9";
        $response = $this->postRaw('/api/incubator/upload', $fakeJpeg);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'total_eggs' => 0,
        ]);

        $this->assertNotNull(Cache::get('incubator:latest_image'));
        $this->assertSame(0, Cache::get('incubator:total_eggs'));
    }

    public function test_latest_when_no_data_returns_200_no_data(): void
    {
        $response = $this->get('/api/incubator/latest');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'no_data',
            'online' => false,
            'image' => null,
            'total_eggs' => 0,
            'detections' => [],
            'last_updated' => null,
        ]);
    }

    public function test_latest_returns_cached_snapshot_and_detections(): void
    {
        $now = now()->timestamp;
        $fakeBase64 = 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8".str_repeat('B', 500)."\xFF\xD9");
        $fakeDetections = [
            ['class' => 'egg', 'confidence' => 0.98, 'box' => [0, 0, 50, 50]],
        ];

        Cache::put('incubator:latest_image', $fakeBase64, 3600);
        Cache::put('incubator:last_seen', $now, 3600);
        Cache::put('incubator:total_eggs', 1, 3600);
        Cache::put('incubator:detections', $fakeDetections, 3600);

        $response = $this->get('/api/incubator/latest');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'online',
            'online' => true,
            'image' => $fakeBase64,
            'url' => $fakeBase64,
            'total_eggs' => 1,
            'detections' => $fakeDetections,
            'timestamp' => $now,
        ]);
        $response->assertJsonStructure([
            'status',
            'online',
            'image',
            'url',
            'total_eggs',
            'detections',
            'last_updated',
            'timestamp',
            'last_seen_sec',
        ]);
    }

    public function test_latest_with_raw_param_streams_binary(): void
    {
        $rawBytes = "\xFF\xD8".str_repeat('C', 500)."\xFF\xD9";
        $fakeBase64 = 'data:image/jpeg;base64,'.base64_encode($rawBytes);

        Cache::put('incubator:latest_image', $fakeBase64, 3600);
        Cache::put('incubator:last_seen', now()->timestamp, 3600);

        $response = $this->get('/api/incubator/latest?raw=1');

        $response->assertStatus(200);
        $this->assertSame($rawBytes, $response->getContent());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_web_routes_parity(): void
    {
        $now = now()->timestamp;
        $fakeBase64 = 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8".str_repeat('D', 500)."\xFF\xD9");

        Cache::put('incubator:latest_image', $fakeBase64, 3600);
        Cache::put('incubator:last_seen', $now, 3600);
        Cache::put('incubator:total_eggs', 5, 3600);

        $response = $this->get('/incubator/latest');
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'online',
            'total_eggs' => 5,
        ]);
    }
}
