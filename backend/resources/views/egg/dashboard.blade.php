<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Egg Candling & Capture Dashboard</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-base: #0c0f17;
            --bg-surface: #141923;
            --bg-card: #1b2232;
            --bg-input: #232c40;
            --border-color: #2b364e;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --primary: #f59e0b;
            --primary-hover: #d97706;
            --primary-glow: rgba(245, 158, 11, 0.25);
            --success: #10b981;
            --success-glow: rgba(16, 185, 129, 0.25);
            --danger: #ef4444;
            --info: #38bdf8;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        }

        body {
            background-color: var(--bg-base);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 24px 16px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 28px;
        }

        .title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .title-group .icon {
            font-size: 28px;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .subtitle {
            font-size: 13px;
            color: var(--text-secondary);
        }

        /* Status Badge */
        .device-status-badge {
            display: flex;
            align-items: center;
            gap: 16px;
            background-color: var(--bg-surface);
            padding: 8px 16px;
            border-radius: 9999px;
            border: 1px solid var(--border-color);
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: var(--text-muted);
            box-shadow: 0 0 8px rgba(0,0,0,0.5);
            transition: all 0.3s ease;
        }

        .status-dot.online {
            background-color: var(--success);
            box-shadow: 0 0 10px var(--success-glow);
        }

        .status-dot.offline {
            background-color: var(--danger);
        }

        .device-telemetry {
            font-size: 12px;
            color: var(--text-secondary);
            border-left: 1px solid var(--border-color);
            padding-left: 12px;
            display: flex;
            gap: 12px;
        }

        /* Grid Layout */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }

        @media (min-width: 900px) {
            .main-grid {
                grid-template-columns: 360px 1fr;
            }
        }

        .card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Form Controls */
        .control-group {
            margin-bottom: 20px;
        }

        .control-label-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .control-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .control-value {
            font-weight: 700;
            color: var(--primary);
            background: var(--bg-card);
            padding: 2px 8px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            font-size: 12px;
            font-family: monospace;
        }

        .slider {
            -webkit-appearance: none;
            width: 100%;
            height: 6px;
            border-radius: 3px;
            background: var(--bg-input);
            outline: none;
            transition: opacity .2s;
        }

        .slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: 2px solid #fff;
            box-shadow: 0 0 8px var(--primary-glow);
        }

        .slider::-moz-range-thumb {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: 2px solid #fff;
            box-shadow: 0 0 8px var(--primary-glow);
        }

        .slider-desc {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Capture Button */
        .btn-capture {
            width: 100%;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0c0f17;
            font-weight: 700;
            font-size: 15px;
            padding: 14px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            box-shadow: 0 4px 15px var(--primary-glow);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-capture:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px var(--primary-glow);
        }

        .btn-capture:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Job Tracker */
        .tracker-card {
            margin-top: 20px;
            background-color: var(--bg-card);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 16px;
            display: none;
        }

        .tracker-status {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 600;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid var(--border-color);
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .tracker-time {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* Preview Area */
        .preview-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 380px;
            background-color: var(--bg-card);
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }

        .preview-empty {
            text-align: center;
            color: var(--text-muted);
            padding: 40px;
        }

        .preview-img {
            max-width: 100%;
            max-height: 520px;
            object-fit: contain;
            display: none;
        }

        .preview-actions {
            margin-top: 16px;
            display: flex;
            gap: 12px;
            width: 100%;
            justify-content: flex-end;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-download {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .btn-download:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* History Section */
        .history-section {
            margin-top: 36px;
        }

        .history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 18px;
            margin-top: 16px;
        }

        .history-item {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            overflow: hidden;
            transition: transform 0.2s, border-color 0.2s;
            display: flex;
            flex-direction: column;
        }

        .history-item:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
        }

        .history-thumb {
            width: 100%;
            height: 140px;
            object-fit: cover;
            background-color: #000;
            cursor: pointer;
        }

        .history-meta {
            padding: 10px 12px;
            font-size: 11px;
            color: var(--text-secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-color);
            background-color: var(--bg-surface);
        }

        .history-id {
            font-weight: 700;
            color: var(--text-primary);
        }

        .history-dl {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .history-dl:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="title-group">
                <span class="icon">🥚</span>
                <div>
                    <h1>Egg Candling & Capture</h1>
                    <div class="subtitle">Sistem Pemantauan dan Pengambilan Foto Telur Otomatis</div>
                </div>
            </div>

            <div class="device-status-badge">
                <div class="status-indicator">
                    <span id="status-dot" class="status-dot"></span>
                    <span id="status-text">Memeriksa ESP...</span>
                </div>
                <div class="device-telemetry">
                    <span>RSSI: <strong id="telemetry-rssi">-</strong></span>
                    <span>Last: <strong id="telemetry-last">-</strong></span>
                </div>
            </div>
        </header>

        <main class="main-grid">
            <!-- Left Panel: Controls -->
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">⚙️ Konfigurasi Kamera</h2>
                </div>

                <div class="control-group">
                    <div class="control-label-row">
                        <span class="control-label">Exposure (Pencahayaan)</span>
                        <span id="val-exposure" class="control-value">500</span>
                    </div>
                    <input id="slider-exposure" type="range" min="0" max="1200" value="500" class="slider">
                    <div class="slider-desc">Rentang: 0 - 1200 (Default: 500)</div>
                </div>

                <div class="control-group">
                    <div class="control-label-row">
                        <span class="control-label">Sensor Gain</span>
                        <span id="val-gain" class="control-value">0</span>
                    </div>
                    <input id="slider-gain" type="range" min="0" max="30" value="0" class="slider">
                    <div class="slider-desc">Rentang: 0 - 30 (Default: 0)</div>
                </div>

                <div class="control-group">
                    <div class="control-label-row">
                        <span class="control-label">Kualitas JPEG</span>
                        <span id="val-quality" class="control-value">6</span>
                    </div>
                    <input id="slider-quality" type="range" min="4" max="15" value="6" class="slider">
                    <div class="slider-desc">Rentang: 4 (Tinggi) - 15 (Rendah)</div>
                </div>

                <div class="control-group">
                    <div class="control-label-row">
                        <span class="control-label">LED Settle Delay (ms)</span>
                        <span id="val-settle" class="control-value">250</span>
                    </div>
                    <input id="slider-settle" type="range" min="100" max="3000" step="50" value="250" class="slider">
                    <div class="slider-desc">Waktu tunggu LED sebelum jepret (100 - 3000 ms)</div>
                </div>

                <button id="btn-capture" class="btn-capture">
                    <span>📷 Ambil Foto</span>
                </button>

                <div id="tracker-box" class="tracker-card">
                    <div class="tracker-status">
                        <div id="tracker-spinner" class="spinner"></div>
                        <span id="tracker-msg">Memulai...</span>
                    </div>
                    <div id="tracker-time" class="tracker-time">Waktu berlalu: 0s</div>
                </div>
            </section>

            <!-- Right Panel: Preview & AI Analysis -->
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">🖼️ Hasil Tangkapan & Analisis AI</h2>
                </div>

                <div class="preview-container">
                    <div id="preview-placeholder" class="preview-empty">
                        <svg style="width: 48px; height: 48px; margin-bottom: 12px; opacity: 0.4;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <p>Belum ada foto yang diambil sesi ini.<br>Klik tombol <strong>Ambil Foto</strong> untuk memulai.</p>
                    </div>
                    <img id="preview-image" class="preview-img" alt="Captured Egg">
                </div>

                <!-- AI NN Prediction Result Box -->
                <div id="ai-result-box" style="display: none; margin-top: 20px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <span style="font-size: 13px; color: var(--text-secondary); font-weight: 600;">HASIL KLASIFIKASI MODEL NN</span>
                        <span id="ai-badge" style="padding: 4px 14px; border-radius: 9999px; font-weight: 700; font-size: 13px; text-transform: uppercase;">-</span>
                    </div>

                    <!-- Probabilities -->
                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                            <span>Tingkat Keyakinan (Confidence)</span>
                            <strong id="ai-confidence" style="color: var(--primary);">-</strong>
                        </div>
                        <div style="width: 100%; height: 8px; background: var(--bg-input); border-radius: 4px; overflow: hidden;">
                            <div id="ai-confidence-bar" style="height: 100%; width: 0%; background: var(--primary); transition: width 0.5s ease;"></div>
                        </div>
                    </div>

                    <!-- Probability Bars Breakdown -->
                    <div id="ai-breakdown" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; font-size: 11px; text-align: center; margin-bottom: 16px;">
                        <div style="background: var(--bg-surface); padding: 6px; border-radius: 6px;">
                            <div style="color: #2ca02c; font-weight: 600;">Fertil</div>
                            <div id="prob-fertil">-</div>
                        </div>
                        <div style="background: var(--bg-surface); padding: 6px; border-radius: 6px;">
                            <div style="color: #1f77b4; font-weight: 600;">Infertil</div>
                            <div id="prob-infertil">-</div>
                        </div>
                        <div style="background: var(--bg-surface); padding: 6px; border-radius: 6px;">
                            <div style="color: #d62728; font-weight: 600;">Mortal</div>
                            <div id="prob-mortal">-</div>
                        </div>
                    </div>

                    <!-- LLM Expert Analysis Box -->
                    <div id="llm-box" style="display: none; background: rgba(56, 189, 248, 0.08); border-left: 3px solid var(--info); padding: 14px 16px; border-radius: 0 8px 8px 0; font-size: 13px; line-height: 1.6; color: var(--text-primary); margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: var(--info); margin-bottom: 8px;">
                            <span>🧠 Analisis Pakar AI (LLM Vision)</span>
                        </div>
                        <div id="llm-content" style="white-space: pre-wrap; font-family: inherit;"></div>
                    </div>

                    <!-- Visualizations Gallery (5 Tahapan Pipeline Computer Vision) -->
                    <div id="ai-vis-container" style="display: none;">
                        <span style="font-size: 12px; color: var(--text-secondary); font-weight: 600; display: block; margin-bottom: 8px;">5 TAHAP PEMROSESAN COMPUTER VISION (KLIK UNTUK LIHAT):</span>
                        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-bottom: 8px;">
                            <div style="text-align: center;">
                                <img id="vis-crop" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 1: Crop & Kontras" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">1. Crop Telur</span>
                            </div>
                            <div style="text-align: center;">
                                <img id="vis-roi" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 2: Binerisasi ROI" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">2. ROI Biner</span>
                            </div>
                            <div style="text-align: center;">
                                <img id="vis-masked" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 3: Masked RGB" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">3. Masked RGB</span>
                            </div>
                            <div style="text-align: center;">
                                <img id="vis-peta" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 4: Peta Darah" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">4. Peta Darah</span>
                            </div>
                            <div style="text-align: center;">
                                <img id="vis-jaringan" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 5: Jaringan Vaskular" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">5. Vaskular</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="preview-actions">
                    <a id="btn-download" class="btn-action btn-download" style="display: none;" download="egg-candling.jpg">
                        ⬇️ Unduh Foto
                    </a>
                </div>
            </section>
        </main>

        <!-- History Section -->
        <section class="history-section">
            <h2 class="card-title">🕒 Riwayat Tangkapan Terakhir (Maks 20)</h2>
            <div id="history-grid" class="history-grid">
                <div style="color: var(--text-muted); font-size: 13px; grid-column: 1/-1;">Memuat riwayat...</div>
            </div>
        </section>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        // Sliders binding
        const sliders = [
            { slider: 'slider-exposure', display: 'val-exposure' },
            { slider: 'slider-gain', display: 'val-gain' },
            { slider: 'slider-quality', display: 'val-quality' },
            { slider: 'slider-settle', display: 'val-settle' }
        ];

        sliders.forEach(item => {
            const input = document.getElementById(item.slider);
            const valDisplay = document.getElementById(item.display);
            input.addEventListener('input', () => {
                valDisplay.textContent = input.value;
            });
        });

        // Device Status Polling (every 5 seconds)
        async function checkStatus() {
            try {
                const res = await fetch('/egg/status');
                if (!res.ok) throw new Error('Status error');
                const data = await res.json();

                const dot = document.getElementById('status-dot');
                const text = document.getElementById('status-text');
                const rssi = document.getElementById('telemetry-rssi');
                const last = document.getElementById('telemetry-last');

                if (data.online) {
                    dot.className = 'status-dot online';
                    text.textContent = 'ESP Online';
                    text.style.color = 'var(--success)';
                } else {
                    dot.className = 'status-dot offline';
                    text.textContent = 'ESP Offline';
                    text.style.color = 'var(--danger)';
                }

                rssi.textContent = data.rssi !== null ? `${data.rssi} dBm` : '-';
                last.textContent = data.last_seen_sec !== null ? `${data.last_seen_sec}s lalu` : 'Tidak aktif';
            } catch (err) {
                console.error(err);
            }
        }

        checkStatus();
        setInterval(checkStatus, 5000);

        // History Loader
        async function loadHistory() {
            try {
                const res = await fetch('/egg/history');
                if (!res.ok) throw new Error('History error');
                const list = await res.json();

                const grid = document.getElementById('history-grid');
                if (list.length === 0) {
                    grid.innerHTML = '<div style="color: var(--text-muted); font-size: 13px; grid-column: 1/-1;">Belum ada foto yang tersimpan.</div>';
                    return;
                }

                grid.innerHTML = list.map(item => `
                    <div class="history-item">
                        <img src="${item.url}" class="history-thumb" alt="Job #${item.id}" onclick="loadJobDetails(${item.id})">
                        <div class="history-meta">
                            <div>
                                <span class="history-id">#${item.id}</span>
                                ${item.prediction_label ? `<span style="margin-left: 6px; font-size: 10px; padding: 2px 6px; border-radius: 4px; background: ${item.prediction_label === 'fertil' ? '#2ca02c' : (item.prediction_label === 'infertil' ? '#1f77b4' : '#d62728')}; color: white; text-transform: uppercase; font-weight: 700;">${item.prediction_label}</span>` : ''}
                                <div style="color: var(--text-muted);">${new Date(item.at).toLocaleTimeString()}</div>
                            </div>
                            <a href="${item.url}" download="egg-${item.id}.jpg" class="history-dl">Unduh</a>
                        </div>
                    </div>
                `).join('');
            } catch (err) {
                console.error(err);
            }
        }

        async function loadJobDetails(id) {
            try {
                const res = await fetch(`/egg/job/${id}`);
                if (!res.ok) throw new Error('Job fetch error');
                const job = await res.json();
                const pred = job.prediction_result || { label: job.prediction_label, confidence: job.confidence };
                const vis = job.prediction_result ? job.prediction_result.visualisasi : null;
                showInPreview(job.url, pred, job.llm_analysis, vis);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (e) {
                console.error(e);
            }
        }

        loadHistory();

        function showInPreview(url, prediction = null, llm = null, vis = null) {
            const img = document.getElementById('preview-image');
            const placeholder = document.getElementById('preview-placeholder');
            const btnDownload = document.getElementById('btn-download');

            img.src = url;
            img.style.display = 'block';
            placeholder.style.display = 'none';
            btnDownload.href = url;
            btnDownload.style.display = 'inline-flex';

            const resultBox = document.getElementById('ai-result-box');
            if (prediction) {
                resultBox.style.display = 'block';
                const badge = document.getElementById('ai-badge');
                const confText = document.getElementById('ai-confidence');
                const confBar = document.getElementById('ai-confidence-bar');

                const label = (prediction.label || '-').toLowerCase();
                badge.textContent = label;

                if (label === 'fertil') {
                    badge.style.background = '#2ca02c';
                    badge.style.color = '#fff';
                } else if (label === 'infertil') {
                    badge.style.background = '#1f77b4';
                    badge.style.color = '#fff';
                } else if (label === 'mortal') {
                    badge.style.background = '#d62728';
                    badge.style.color = '#fff';
                } else {
                    badge.style.background = 'var(--text-muted)';
                    badge.style.color = '#fff';
                }

                const conf = Math.round((prediction.keyakinan || prediction.confidence || 0) * 100);
                confText.textContent = `${conf}%`;
                confBar.style.width = `${conf}%`;

                if (prediction.probabilitas) {
                    document.getElementById('prob-fertil').textContent = `${Math.round((prediction.probabilitas.fertil || 0) * 100)}%`;
                    document.getElementById('prob-infertil').textContent = `${Math.round((prediction.probabilitas.infertil || 0) * 100)}%`;
                    document.getElementById('prob-mortal').textContent = `${Math.round((prediction.probabilitas.mortal || 0) * 100)}%`;
                }

                // LLM Box
                const llmBox = document.getElementById('llm-box');
                const llmContent = document.getElementById('llm-content');
                if (llm) {
                    llmBox.style.display = 'block';
                    llmContent.textContent = llm;
                } else {
                    llmBox.style.display = 'none';
                }

                // Visualizations (5 Tahap Computer Vision)
                const visBox = document.getElementById('ai-vis-container');
                if (vis) {
                    visBox.style.display = 'block';
                    if (vis.citra_crop) document.getElementById('vis-crop').src = vis.citra_crop;
                    if (vis.citra_biner_roi) document.getElementById('vis-roi').src = vis.citra_biner_roi;
                    if (vis.citra_rekonstruksi_rgb) document.getElementById('vis-masked').src = vis.citra_rekonstruksi_rgb;
                    if (vis.citra_peta_darah) document.getElementById('vis-peta').src = vis.citra_peta_darah;
                    if (vis.citra_jaringan_darah) document.getElementById('vis-jaringan').src = vis.citra_jaringan_darah;
                } else {
                    visBox.style.display = 'none';
                }
            }
        }

        // Capture Job Dispatch & Tracker
        const btnCapture = document.getElementById('btn-capture');
        const trackerBox = document.getElementById('tracker-box');
        const trackerSpinner = document.getElementById('tracker-spinner');
        const trackerMsg = document.getElementById('tracker-msg');
        const trackerTime = document.getElementById('tracker-time');

        let pollInterval = null;
        let timerInterval = null;
        let startTime = null;

        btnCapture.addEventListener('click', async () => {
            btnCapture.disabled = true;
            trackerBox.style.display = 'block';
            trackerSpinner.style.display = 'block';
            trackerMsg.textContent = 'Membuat job capture...';
            trackerMsg.style.color = 'var(--text-primary)';

            startTime = Date.now();
            clearInterval(timerInterval);
            timerInterval = setInterval(() => {
                const elapsed = Math.floor((Date.now() - startTime) / 1000);
                trackerTime.textContent = `Waktu berlalu: ${elapsed}s / 60s`;
            }, 500);

            try {
                const payload = {
                    exposure: parseInt(document.getElementById('slider-exposure').value, 10),
                    gain: parseInt(document.getElementById('slider-gain').value, 10),
                    quality: parseInt(document.getElementById('slider-quality').value, 10),
                    settle_ms: parseInt(document.getElementById('slider-settle').value, 10)
                };

                const res = await fetch('/egg/capture', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                if (!res.ok) throw new Error('Gagal membuat job capture');
                const data = await res.json();
                const jobId = data.job;

                trackJob(jobId);
            } catch (err) {
                stopTracking(false, err.message || 'Terjadi kesalahan sistem.');
            }
        });

        function trackJob(jobId) {
            trackerMsg.textContent = 'Menunggu ESP mengambil tugas...';

            clearInterval(pollInterval);
            pollInterval = setInterval(async () => {
                const elapsed = Math.floor((Date.now() - startTime) / 1000);

                if (elapsed > 120) {
                    stopTracking(false, 'Timeout: ESP atau analisis LLM belum selesai dalam 120 detik.');
                    return;
                }

                try {
                    const res = await fetch(`/egg/job/${jobId}`);
                    if (!res.ok) throw new Error('Gagal memeriksa status job');
                    const job = await res.json();

                    if (job.status === 'pending') {
                        trackerMsg.textContent = 'Menunggu ESP mengambil tugas...';
                    } else if (job.status === 'taken') {
                        trackerMsg.textContent = 'ESP sedang Mengambil Foto...';
                    } else if (job.status === 'done') {
                        stopTracking(true, 'Pengambilan Foto & Analisis Selesai!');
                        if (job.url) {
                            const pred = job.prediction_result || { label: job.prediction_label, confidence: job.confidence };
                            const vis = job.prediction_result ? job.prediction_result.visualisasi : null;
                            showInPreview(job.url, pred, job.llm_analysis, vis);
                        }
                        loadHistory();
                    } else if (job.status === 'failed') {
                        stopTracking(false, 'Pengambilan foto gagal di perangkat ESP.');
                    }
                } catch (err) {
                    console.error(err);
                }
            }, 1500);
        }

        function stopTracking(success, message) {
            clearInterval(pollInterval);
            clearInterval(timerInterval);
            trackerSpinner.style.display = 'none';
            trackerMsg.textContent = message;
            trackerMsg.style.color = success ? 'var(--success)' : 'var(--danger)';
            btnCapture.disabled = false;
        }
    </script>
</body>
</html>
