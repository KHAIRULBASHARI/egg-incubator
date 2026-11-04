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

        // 2. Kirim ke LLM Router untuk penjelasan mendalam sesuai SPESIFIKASI AGENT LLM
        $llmAnalysis = null;
        try {
            $llmUrl = config('services.candling.llm_url', 'https://router.walker880.my.id/v1/chat/completions');
            $llmKey = config('services.candling.llm_key', 'sk-87fb2556b4e913ec-035612-de0e95de');
            $llmModel = config('services.candling.llm_model', 'antigravity/gemini-3.8-flash-tiered');

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

            $segStatus = 'Sukses';
            if (! empty($aiResult['kualitas']['roi_mode'])) {
                $segStatus .= ' ('.$aiResult['kualitas']['roi_mode'].')';
            }
            if (! empty($aiResult['kualitas']['peringatan'])) {
                $segStatus .= ' | Catatan: '.implode(', ', $aiResult['kualitas']['peringatan']);
            }

            $systemPrompt = "Anda adalah Agen Diagnostik Candling Telur yang bertugas menganalisis status fertilitas dan viabilitas embrio telur unggas.\n\n"
                ."Tugas Anda:\n"
                ."1. Menerima hasil prediksi Neural Network (label, probabilitas, analisis citra) serta gambar hasil candling.\n"
                ."2. Memberikan penjelasan deskriptif yang ilmiah, tepat sasaran, dan mudah dipahami peternak mengenai kondisi telur tersebut.\n"
                ."3. Menjelaskan tanda-tanda visual spesifik yang teramati pada citra sesuai dataset acuan:\n"
                ."   - Fertil: Terdapat struktur jaring pembuluh darah aktif, titik embrio hidup, atau rongga gelap masif pada fase mendekati menetas.\n"
                ."   - Infertil: Bening merata, tembus cahaya, tidak ada jaring pembuluh darah maupun noda embrio.\n"
                ."   - Mortal: Terdapat cincin darah (blood ring), pembuluh darah yang runtuh, atau gumpalan embrio mati yang menghitam di bagian tengah tanpa percabangan pembuluh aktif.\n"
                ."4. Menyebutkan nilai probabilitas model secara akurat sebagai tingkat keyakinan sistem.\n"
                ."5. Memberikan instruksi manajemen inkubasi yang konkret.\n\n"
                ."Aturan Penulisan:\n"
                ."- Jangan menggunakan emotikon dalam bentuk apa pun.\n"
                ."- Jangan membahas topik di luar biologi telur, inkubasi, dan pengolahan citra candling.\n"
                ."- Gunakan format terstruktur: Diagnosis, Analisis Visual, Alasan Probabilitas, dan Rekomendasi Tindakan.";

            $userPrompt = "Data Model:\n"
                ."- Label Prediksi: {$labelDesc}\n"
                .$probText
                ."- Status Segmentasi: {$segStatus}\n"
                ."- Citra Terlampir: Citra candling dan visualisasi rekonstruksi jaringan terlampir.\n\n"
                ."Instruksi: Berikan penjelasan diagnostik telur ini.";

            $userContents = [
                ['type' => 'text', 'text' => $userPrompt],
                [
                    'type' => 'image_url',
                    'image_url' => ['url' => $base64Image],
                ],
            ];

            if (! empty($aiResult['visualisasi']['citra_rekonstruksi_rgb'])) {
                $userContents[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:image/jpeg;base64,'.$aiResult['visualisasi']['citra_rekonstruksi_rgb']],
                ];
            }

            if (! empty($aiResult['visualisasi']['citra_jaringan_darah'])) {
                $userContents[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:image/jpeg;base64,'.$aiResult['visualisasi']['citra_jaringan_darah']],
                ];
            }

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
                'max_tokens' => 800,
            ];

            $llmResponse = Http::timeout(120)
                ->withToken($llmKey)
                ->post($llmUrl, $llmPayload);

            if ($llmResponse->successful()) {
                $llmAnalysis = $llmResponse->json('choices.0.message.content');
            } else {
                Log::error('LLM Router non-200: '.$llmResponse->status().' - '.$llmResponse->body());
            }
        } catch (\Throwable $e) {
            Log::error('Gagal memanggil LLM Router: '.$e->getMessage());
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
