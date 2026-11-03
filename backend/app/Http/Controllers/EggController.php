<?php

namespace App\Http\Controllers;

use App\Models\CaptureJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class EggController extends Controller
{
    /**
     * Request a new capture job.
     */
    public function request(Request $request)
    {
        $validated = $request->validate([
            'exposure' => 'nullable|integer|between:0,1200',
            'gain' => 'nullable|integer|between:0,30',
            'quality' => 'nullable|integer|between:4,15',
            'settle_ms' => 'nullable|integer|between:100,3000',
        ]);

        $jobData = array_filter($validated, fn ($val) => $val !== null);

        $job = CaptureJob::create($jobData);

        return response()->json(['job' => $job->id]);
    }

    /**
     * Show job status and image URL.
     */
    public function show(CaptureJob $job)
    {
        return response()->json([
            'status' => $job->status,
            'url' => $job->image_base64 ?: ($job->image_path ? Storage::disk('public')->url($job->image_path) : null),
            'prediction_label' => $job->prediction_label,
            'confidence' => $job->confidence,
            'prediction_result' => $job->prediction_result,
            'llm_analysis' => $job->llm_analysis,
        ]);
    }

    /**
     * List last 20 done jobs.
     */
    public function history()
    {
        $jobs = CaptureJob::where('status', 'done')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($j) => [
                'id' => $j->id,
                'url' => $j->image_base64 ?: ($j->image_path ? Storage::disk('public')->url($j->image_path) : null),
                'prediction_label' => $j->prediction_label,
                'confidence' => $j->confidence,
                'at' => $j->updated_at->toIso8601String(),
            ]);

        return response()->json($jobs);
    }

    /**
     * Return device status.
     */
    public function status()
    {
        $last = Cache::get('device:last_seen');
        $ago = $last !== null ? (now()->timestamp - (int) $last) : null;

        return response()->json([
            'online' => ($ago !== null && $ago < 15),
            'last_seen_sec' => $ago,
            'rssi' => Cache::get('device:rssi'),
        ]);
    }

    /**
     * Show dashboard view.
     */
    public function dashboard()
    {
        return view('egg.dashboard');
    }
}
