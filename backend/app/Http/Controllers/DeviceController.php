<?php

namespace App\Http\Controllers;

use App\Models\CaptureJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        // 2. Simpan hasil klasifikasi & gambar segera agar ESP32 tidak menggantung
        $job->update([
            'status' => 'done',
            'image_path' => null,
            'prediction_label' => $aiLabel,
            'confidence' => $aiConfidence,
            'prediction_result' => $aiResult,
            'llm_analysis' => null,
            'image_base64' => $base64Image,
        ]);

        // Kirim respon 200 OK langsung ke ESP32 agar siklus upload selesai seketika
        if (function_exists('fastcgi_finish_request')) {
            response('OK')->send();
            fastcgi_finish_request();
        }

        // 3. Eksekusi LLM Router di background (cepat ~6-8s dengan gemini-3.1-flash-lite)
        $llmAnalysis = null;
        try {
            $llmUrl = config('services.candling.llm_url', 'https://router.walker880.my.id/v1/chat/completions');
            $llmKey = config('services.candling.llm_key', 'sk-87fb2556b4e913ec-035612-de0e95de');
            $llmModel = config('services.candling.llm_model', 'antigravity/gemini-3.1-flash-lite');

            $labelDesc = $aiLabel ? strtoupper($aiLabel) : 'Tidak terdeteksi';
            $confDesc = $aiConfidence !== null ? round($aiConfidence * 100, 2).'%' : '-';

            $probText = '';
            if (! empty($aiResult['probabilitas_persen'])) {
                foreach ($aiResult['probabilitas_persen'] as $k => $v) {
                    $probText .= '- Probabilitas '.ucfirst($k).": {$v}\n";
                }
            } elseif (! empty($aiResult['probabilitas'])) {
                foreach ($aiResult['probabilitas'] as $k => $v) {
                    $pct = round(((float) $v) * 100, 2).'%';
                    $probText .= '- Probabilitas '.ucfirst($k).": {$pct}\n";
                }
            } else {
                $probText = "- Keyakinan: {$confDesc}\n";
            }

            $systemPrompt = "Anda adalah Asisten Ahli Candling Telur yang bertugas memberikan penjelasan hasil peneropongan telur kepada peternak.\n\n"
                ."Tugas Anda:\n"
                ."1. Menjelaskan kondisi telur berdasarkan foto candling dan hasil deteksi model secara jelas, praktis, dan mudah dipahami orang awam atau peternak.\n"
                ."2. Gunakan bahasa peternakan yang sederhana dan komunikatif. HINDARI istilah ilmiah atau teknis yang rumit (jangan gunakan istilah seperti translusensi, homogenitas, mottling, convex hull, dekomposisi, vaskularisasi). Ganti dengan bahasa sederhana seperti telur bening, urat darah merah, bintik pori cangkang, embrio hidup, cincin darah.\n"
                ."3. Jelaskan kondisi visual yang tampak:\n"
                ."   - Fertil: Terlihat urat darah menyerupai jaring laba-laba dan titik embrio hidup, atau tampak gelap pekat menjelang menetas.\n"
                ."   - Infertil: Telur tampak terang dan bening tembus cahaya, hanya terlihat bayangan kuning telur tanpa urat darah.\n"
                ."   - Mortal: Terlihat cincin darah melingkar, urat darah rusak atau pecah, atau noda hitam embrio mati yang mengambang.\n"
                ."4. Sebutkan angka persentase keyakinan secara jelas.\n"
                ."5. Berikan instruksi tindakan praktis bagi peternak (apakah tetap dierami atau segera dikeluarkan dari rak mesin tetas).\n\n"
                ."Aturan Penulisan (SANGAT KETAT):\n"
                ."- Dilarang keras menggunakan simbol Markdown: JANGAN gunakan tanda pagar (#), JANGAN gunakan garis pembatas (---), JANGAN gunakan tanda bintang (*) untuk tebal atau miring.\n"
                ."- Dilarang menggunakan emoji atau emotikon apa pun.\n"
                ."- Tuliskan jawaban dalam teks polos yang bersih dengan struktur penomoran berikut:\n\n"
                ."DIAGNOSIS: [STATUS TELUR]\n\n"
                ."1. Ringkasan:\n"
                ."(Uraian singkat status dan angka keyakinan)\n\n"
                ."2. Kondisi Tampilan Telur:\n"
                ."- (Keterangan tembus cahaya)\n"
                ."- (Keterangan urat darah atau embrio)\n"
                ."- (Keterangan kondisi cangkang atau noda)\n\n"
                ."3. Alasan Hasil:\n"
                ."(Penjelasan mudah dipahami mengapa telur dinilai demikian)\n\n"
                ."4. Tindakan untuk Peternak:\n"
                ."- (Langkah yang harus dilakukan peternak)\n"
                .'- (Tips perawatan mesin tetas atau pemanfaatan telur)';

            $userPrompt = "Data Deteksi:\n"
                ."- Status: {$labelDesc}\n"
                .$probText
                ."- Citra candling telur terlampir.\n\n"
                .'Instruksi: Berikan penjelasan hasil peneropongan telur ini sesuai format teks polos tanpa markdown dan tanpa emoji.';

            $ensureDataUri = function ($img) {
                if (! str_starts_with($img, 'data:image/')) {
                    return 'data:image/jpeg;base64,'.$img;
                }

                return $img;
            };

            $userContents = [
                ['type' => 'text', 'text' => $userPrompt],
                [
                    'type' => 'image_url',
                    'image_url' => ['url' => $ensureDataUri($base64Image)],
                ],
            ];

            $llmPayload = [
                'model' => $llmModel,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => $userContents,
                    ],
                ],
                'max_tokens' => 500,
            ];

            $llmResponse = Http::timeout(45)
                ->withToken($llmKey)
                ->post($llmUrl, $llmPayload);

            if ($llmResponse->successful()) {
                $llmAnalysis = $llmResponse->json('choices.0.message.content');
                if ($llmAnalysis) {
                    $job->update(['llm_analysis' => $llmAnalysis]);
                }
            } else {
                Log::error('LLM Router non-200: '.$llmResponse->status().' - '.$llmResponse->body());
            }
        } catch (\Throwable $e) {
            Log::error('Gagal memanggil LLM Router: '.$e->getMessage());
        }

        return response('OK');
    }

    /**
     * Delegate to IncubatorController for incubator snapshot upload.
     */
    public function incubatorUpload(Request $request)
    {
        return app(IncubatorController::class)->upload($request);
    }

    /**
     * Delegate to IncubatorController for latest incubator snapshot.
     */
    public function incubatorLatest(Request $request)
    {
        return app(IncubatorController::class)->latest($request);
    }
}
