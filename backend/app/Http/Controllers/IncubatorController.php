<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IncubatorController extends Controller
{
    /**
     * Handle incubator snapshot upload from ESP32 Incubator.
     * Accepts raw JPEG image binary, multipart/form-data, or base64 JSON.
     */
    public function upload(Request $request)
    {
        $content = null;

        // 1. Check multipart/form-data upload
        if ($request->hasFile('image')) {
            $content = file_get_contents($request->file('image')->getRealPath());
        } elseif ($request->hasFile('file')) {
            $content = file_get_contents($request->file('file')->getRealPath());
        } elseif (count($request->allFiles()) > 0) {
            $files = $request->allFiles();
            $file = reset($files);
            if (is_array($file)) {
                $file = reset($file);
            }
            if ($file instanceof UploadedFile) {
                $content = file_get_contents($file->getRealPath());
            }
        }

        // 2. Check JSON payload with base64 encoded image
        if (! $content && ($request->isJson() || (is_array($request->json()->all()) && ! empty($request->json()->all())))) {
            $b64 = $request->json('image') ?? $request->json('image_base64');
            if ($b64) {
                if (str_contains($b64, ',')) {
                    $b64 = explode(',', $b64, 2)[1];
                }
                $decoded = base64_decode($b64, true);
                if ($decoded !== false) {
                    $content = $decoded;
                }
            }
        }

        // 3. Fallback to raw binary body
        if (! $content) {
            $content = $request->getContent();
        }

        $length = is_string($content) ? strlen($content) : 0;

        // Size validation: between 100 bytes and 10MB
        if ($length < 100 || $length > 10 * 1024 * 1024) {
            abort(422, 'Invalid file size.');
        }

        // Validate JPEG magic SOI marker
        if (! str_starts_with($content, "\xFF\xD8")) {
            abort(422, 'Invalid JPEG content.');
        }

        // Optional RSSI header tracking
        if ($request->hasHeader('X-RSSI')) {
            Cache::put('incubator:rssi', (int) $request->header('X-RSSI'), 3600);
        }

        $timestamp = now()->timestamp;
        $base64Image = 'data:image/jpeg;base64,'.base64_encode($content);

        // 4. Call Flask YOLO Incubator detection endpoint with 5-second timeout
        $apiUrl = config('services.incubator.api_url', 'http://127.0.0.1:5001/api/incubator/detect');
        $detections = [];
        $totalEggs = 0;

        try {
            $yoloResponse = Http::timeout(5)
                ->attach('image', $content, 'incubator.jpg')
                ->post($apiUrl);

            if ($yoloResponse->successful()) {
                $detectionData = $yoloResponse->json();
                if (is_array($detectionData)) {
                    // Extract total eggs count
                    if (isset($detectionData['total_eggs'])) {
                        $totalEggs = (int) $detectionData['total_eggs'];
                    } elseif (isset($detectionData['count'])) {
                        $totalEggs = (int) $detectionData['count'];
                    } elseif (isset($detectionData['total'])) {
                        $totalEggs = (int) $detectionData['total'];
                    }

                    // Extract detections array
                    if (isset($detectionData['detections']) && is_array($detectionData['detections'])) {
                        $detections = $detectionData['detections'];
                        if ($totalEggs === 0 && ! isset($detectionData['total_eggs']) && ! isset($detectionData['count'])) {
                            $totalEggs = count($detections);
                        }
                    } elseif (isset($detectionData['data']) && is_array($detectionData['data'])) {
                        $detections = $detectionData['data'];
                        if ($totalEggs === 0 && ! isset($detectionData['total_eggs']) && ! isset($detectionData['count'])) {
                            $totalEggs = count($detections);
                        }
                    } else {
                        $detections = $detectionData;
                    }
                }
            } else {
                Log::warning('Flask YOLO Incubator non-200: '.$yoloResponse->status().' - '.$yoloResponse->body());
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal memanggil API Flask YOLO Incubator: '.$e->getMessage());
        }

        // 5. Store snapshot & detection results in Cache
        Cache::put('incubator:latest_image', $base64Image, 3600);
        Cache::put('incubator:last_seen', $timestamp, 3600);
        Cache::put('incubator:detections', $detections, 3600);
        Cache::put('incubator:total_eggs', $totalEggs, 3600);

        return response()->json([
            'status' => 'success',
            'message' => 'Incubator snapshot processed successfully',
            'total_eggs' => $totalEggs,
            'detections' => $detections,
            'timestamp' => $timestamp,
        ]);
    }

    /**
     * Retrieve the latest incubator snapshot and detection data for Flutter LiveScreen.
     */
    public function latest(Request $request)
    {
        $lastSeen = Cache::get('incubator:last_seen');
        $image = Cache::get('incubator:latest_image');
        $detections = Cache::get('incubator:detections', []);
        $totalEggs = Cache::get('incubator:total_eggs');

        if ($totalEggs === null) {
            $totalEggs = is_array($detections) ? count($detections) : 0;
        }

        $ago = $lastSeen !== null ? (now()->timestamp - (int) $lastSeen) : null;
        $status = $image !== null ? ($ago !== null && $ago <= 60 ? 'online' : 'idle') : 'no_data';
        $lastUpdated = $lastSeen ? Carbon::createFromTimestamp($lastSeen)->toIso8601String() : null;

        // Support optional ?raw=1 to stream raw binary JPEG
        if ($request->boolean('raw') && $image) {
            $binary = base64_decode(explode(',', $image, 2)[1] ?? $image);

            return response($binary, 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => strlen($binary),
            ]);
        }

        return response()->json([
            'status' => $status,
            'online' => ($ago !== null && $ago <= 60),
            'image' => $image,
            'url' => $image,
            'total_eggs' => (int) $totalEggs,
            'detections' => $detections ?: [],
            'last_updated' => $lastUpdated,
            'timestamp' => $lastSeen,
            'last_seen_sec' => $ago,
        ]);
    }
}
