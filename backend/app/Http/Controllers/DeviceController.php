<?php

namespace App\Http\Controllers;

use App\Models\CaptureJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeviceController extends Controller
{
    /**
     * Device polling endpoint.
     */
    public function poll(Request $request)
    {
        // Record poll time and RSSI
        Cache::put('device:last_seen', now()->timestamp, 3600);
        if ($request->hasHeader('X-RSSI')) {
            Cache::put('device:rssi', (int) $request->header('X-RSSI'), 3600);
        }

        // Expire stale taken jobs: taken_at < now()->subSeconds(60)
        CaptureJob::where('status', 'taken')
            ->where('taken_at', '<', now()->subSeconds(60))
            ->update(['status' => 'failed']);

        // Select pending job atomically with DB::transaction + lockForUpdate
        $job = DB::transaction(function () {
            $pendingJob = CaptureJob::where('status', 'pending')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->first();

            if ($pendingJob) {
                $pendingJob->status = 'taken';
                $pendingJob->taken_at = now();
                $pendingJob->save();
            }

            return $pendingJob;
        });

        if (! $job) {
            return response('NONE')->header('Content-Type', 'text/plain');
        }

        return response("job={$job->id};exp={$job->exposure};gain={$job->gain};q={$job->quality};delay={$job->settle_ms}")
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Device upload endpoint.
     */
    public function upload(Request $request)
    {
        $jobId = $request->query('job');
        $job = CaptureJob::findOrFail($jobId);

        if ($job->status !== 'taken') {
            abort(409, 'Job is not in taken status.');
        }

        if ($request->boolean('fail')) {
            $job->update(['status' => 'failed']);

            return response('OK');
        }

        $content = $request->getContent();
        $length = strlen($content);

        if ($length < 1024 || $length > 5 * 1024 * 1024) {
            abort(422, 'Invalid file size.');
        }

        if (! str_starts_with($content, "\xFF\xD8") || substr($content, -2) !== "\xFF\xD9") {
            abort(422, 'Invalid JPEG content.');
        }

        $base64Image = 'data:image/jpeg;base64,'.base64_encode($content);

        // 1. Kirim langsung ke Model Neural Network Candling Keras (tanpa simpan permanen)
        $aiLabel = null;
        $aiConfidence = null;
        $aiResult = null;
        try {
            $apiUrl = config('services.candling.api_url', 'http://127.0.0.1:5000/api/predict');
            $aiResponse = Http::timeout(15)
                ->attach('image', $content, 'candling.jpg')
                ->post($apiUrl);

            if ($aiResponse->successful()) {
                $resData = $aiResponse->json('data');
                $aiLabel = $resData['label'] ?? null;
                $aiConfidence = isset($resData['keyakinan']) ? (float) $resData['keyakinan'] : null;
                $aiResult = $resData;
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal memanggil API Candling NN: '.$e->getMessage());
        }

        // 2. Kirim ke LLM Router untuk penjelasan mendalam
        $llmAnalysis = null;
        try {
            $llmUrl = config('services.candling.llm_url', 'https://router.walker880.my.id/v1/chat/completions');
            $llmKey = config('services.candling.llm_key', 'sk-87fb2556b4e913ec-035612-de0e95de');
            $llmModel = config('services.candling.llm_model', 'antigravity/gemini-3.1-flash-lite');

            $labelDesc = $aiLabel ? strtoupper($aiLabel) : 'Tidak terdeteksi';
            $confDesc = $aiConfidence ? round($aiConfidence * 100, 2).'%' : '-';

            $prompt = "Kamu adalah dokter pakar penetasan telur ayam dan computer vision. Hasil deteksi Neural Network menunjukkan telur ini: [{$labelDesc}] dengan keyakinan [{$confDesc}]. Berikan analisis singkat dan akurat dalam bahasa Indonesia (maksimal 3 poin): 1. Kondisi embrio dan pembuluh darah pada citra candling. 2. Rekomendasi apakah telur layak diteruskan inkubasi atau harus disortir. 3. Tips optimalisasi candling/inkubasi.";

            $llmPayload = [
                'model' => $llmModel,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => $base64Image],
                            ],
                        ],
                    ],
                ],
                'max_tokens' => 450,
            ];

            $llmResponse = Http::timeout(60)
                ->withToken($llmKey)
                ->post($llmUrl, $llmPayload);

            if ($llmResponse->successful()) {
                $llmAnalysis = $llmResponse->json('choices.0.message.content');
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal memanggil LLM Router: '.$e->getMessage());
        }

        // Simpan hasil klasifikasi & gambar sementara via base64 tanpa menyimpan file fisik ke disk
        $job->update([
            'status' => 'done',
            'image_path' => null,
            'prediction_label' => $aiLabel,
            'confidence' => $aiConfidence,
            'prediction_result' => $aiResult,
            'llm_analysis' => $llmAnalysis,
            'image_base64' => $base64Image,
        ]);

        return response('OK');
    }
}
