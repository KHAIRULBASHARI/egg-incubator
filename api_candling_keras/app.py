"""
app.py
======
RESTful API Candling Telur (Fertil, Infertil, Mortal) berbasis Flask.
Menggunakan Deep Learning / Neural Network Keras Sequential MLP dan
Computer Vision Klasik (Binerisasi ROI & Ekstraksi 59 Fitur).

Didesain untuk diintegrasikan dengan Website Laravel, Node.js, atau Frontend SPA.
"""

import os
import sys
import io
import json
import base64
from pathlib import Path

import cv2
import numpy as np
from flask import Flask, request, jsonify

# Setup path modul lokal
ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT / "src"))
import fitur

# ---------------------------------------------------------------- Inisialisasi Flask
app = Flask(__name__)

# Konfigurasi CORS agar frontend / Laravel bisa memanggil API dari domain / port lain
@app.after_request
def enable_cors(response):
    response.headers["Access-Control-Allow-Origin"] = "*"
    response.headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
    response.headers["Access-Control-Allow-Headers"] = "Content-Type, Authorization"
    return response


# ---------------------------------------------------------------- Muat Model & Parameter
print("=" * 60)
print("Memuat Model Candling Keras dan Scaler...")
DIR_MODELS = ROOT / "models"
model, meta = fitur.muat_model_candling(DIR_MODELS)

NAMA_FITUR = meta.get("nama_fitur", fitur.nama_fitur())
KELAS = list(model.classes_)
MU = model.scaler.mean_
SD = model.scaler.scale_

# Ambang batas evaluasi kualitas & deteksi Out-of-Distribution (OOD)
AMBANG_KEYAKINAN = 0.70
LUAS_CURIGA = 0.03
OOD_Z = 4.0
OOD_BATAS = 0.05

WARNA_KELAS = {
    "fertil": "#2ca02c",    # Hijau
    "infertil": "#1f77b4",  # Biru
    "mortal": "#d62728"     # Merah
}

print(f"Kelas Target       : {KELAS}")
print(f"Jumlah Fitur       : {len(NAMA_FITUR)}")
print(f"Arsitektur Model   : {meta.get('arsitektur', '-')}")
print(f"Best Val Accuracy  : {meta.get('val_akurasi', 0)*100:.2f}%")
print(f"Test Accuracy      : {meta.get('test_akurasi', 0)*100:.2f}%")
print("=" * 60)


# ---------------------------------------------------------------- Helper Functions
def to_base64_jpeg(img_np, quality=90):
    """Mengubah numpy array (RGB uint8 atau Grayscale) menjadi Base64 Data URI."""
    if img_np is None:
        return None
    if img_np.ndim == 3 and img_np.shape[2] == 3:
        bgr = cv2.cvtColor(img_np, cv2.COLOR_RGB2BGR)
    else:
        bgr = img_np
    encode_param = [int(cv2.IMWRITE_JPEG_QUALITY), quality]
    success, buffer = cv2.imencode(".jpg", bgr, encode_param)
    if not success:
        return None
    b64_str = base64.b64encode(buffer).decode("utf-8")
    return f"data:image/jpeg;base64,{b64_str}"


def baca_citra_dari_request(req):
    """Membaca citra dari form-data (file upload) atau JSON (Base64 string)."""
    # 1. Dari File Upload (multipart/form-data)
    file_obj = None
    for key in ["image", "file", "gambar", "foto"]:
        if key in req.files:
            file_obj = req.files[key]
            break

    if file_obj and file_obj.filename != "":
        bytes_data = file_obj.read()
        nparr = np.frombuffer(bytes_data, np.uint8)
        bgr = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        if bgr is None:
            return None, "File yang diunggah bukan format citra valid."
        return cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB), None

    # 2. Dari JSON Payload (Base64 string)
    if req.is_json:
        data = req.get_json(silent=True) or {}
        img_b64 = data.get("image") or data.get("image_base64")
        if img_b64:
            if "," in img_b64:
                img_b64 = img_b64.split(",")[1]
            try:
                decoded = base64.b64decode(img_b64)
                nparr = np.frombuffer(decoded, np.uint8)
                bgr = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
                if bgr is None:
                    return None, "String Base64 tidak dapat didekode menjadi citra."
                return cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB), None
            except Exception as e:
                return None, f"Gagal membaca Base64: {str(e)}"

    return None, "Tidak ada file citra yang dikirim. Gunakan multipart/form-data dengan field 'image'."


# ---------------------------------------------------------------- Endpoints
@app.route("/", methods=["GET"])
def index():
    return jsonify({
        "service": "Egg Candling Fertility Classification API",
        "model": "Keras Sequential Multilayer Perceptron",
        "classes": KELAS,
        "status": "online",
        "endpoints": {
            "GET /api/health": "Pengecekan status server",
            "POST /api/predict": "Prediksi fertilitas citra candling telur"
        }
    }), 200


@app.route("/api/health", methods=["GET"])
def health():
    return jsonify({
        "status": "healthy",
        "model_loaded": model is not None,
        "classes": KELAS,
        "total_features": len(NAMA_FITUR)
    }), 200


@app.route("/api/predict", methods=["POST", "OPTIONS"])
def predict():
    if request.method == "OPTIONS":
        return "", 204

    # 1. Baca Citra
    rgb, err = baca_citra_dari_request(request)
    if err:
        return jsonify({"status": "error", "message": err}), 400

    try:
        # 2. Preprocessing & Ekstraksi Fitur
        pre = fitur.preprocess(rgb)
        f_dict, info = fitur.ekstrak_fitur_dict(rgb)

        x = np.array(list(f_dict.values()), dtype=np.float64)

        # 3. Prediksi Keras MLP (Probabilitas & Label)
        proba = model.predict_proba(x.reshape(1, -1))[0]
        k_best = int(proba.argmax())
        label_pred = str(model.classes_[k_best])
        confidence = float(proba[k_best])

        # 4. Deteksi Peringatan & Out-of-Distribution (OOD)
        luas_roi = float(pre["mask"].mean())
        Z = np.abs((x - MU) / SD)
        porsi_ood = float((Z > OOD_Z).mean())
        zmax_ood = float(Z.max())
        n_menyimpang = int(round(porsi_ood * len(NAMA_FITUR)))

        peringatan = []
        if not pre["roi_ok"]:
            peringatan.append("Telur tidak terdeteksi dengan jelas (menggunakan ROI cadangan).")
        if luas_roi < LUAS_CURIGA:
            peringatan.append(f"Area telur yang terdeteksi sangat kecil ({luas_roi:.1%} dari frame).")
        if porsi_ood > OOD_BATAS:
            peringatan.append(f"Citra menyimpang dari data latih ({n_menyimpang} fitur berbeda cukup jauh).")
        if confidence < AMBANG_KEYAKINAN:
            peringatan.append(f"Tingkat keyakinan model rendah ({confidence*100:.1f}%, di bawah {AMBANG_KEYAKINAN*100:.0f}%).")

        # 5. Susun Dictionary Probabilitas
        probabilitas = {str(c): float(p) for c, p in zip(model.classes_, proba)}
        probabilitas_persen = {str(c): f"{p*100:.2f}%" for c, p in zip(model.classes_, proba)}

        # 6. Encode 4 Gambar Visualisasi persis seperti Gradio ke Base64
        visualisasi = {
            "citra_crop": to_base64_jpeg(pre["rgb"]),
            "citra_biner_roi": to_base64_jpeg(pre["roi_vis"]),
            "citra_rekonstruksi_rgb": to_base64_jpeg(pre["rgb_masked"]),
            "citra_peta_darah": to_base64_jpeg(pre["peta_darah"]),
            "citra_jaringan_darah": to_base64_jpeg(pre["vis_darah"])
        }

        # 7. Response Lengkap untuk Laravel
        response_data = {
            "status": "success",
            "data": {
                "label": label_pred,
                "label_labeling": label_pred.capitalize(),
                "warna_heks": WARNA_KELAS.get(label_pred, "#333333"),
                "keyakinan": confidence,
                "persentase": f"{confidence * 100:.2f}%",
                "probabilitas": probabilitas,
                "probabilitas_persen": probabilitas_persen,
                "kualitas": {
                    "roi_ok": bool(pre["roi_ok"]),
                    "roi_mode": pre["roi_mode"],
                    "luas_area_telur": round(luas_roi, 4),
                    "porsi_fitur_menyimpang": round(porsi_ood, 4),
                    "zmax": round(zmax_ood, 2),
                    "ada_peringatan": len(peringatan) > 0,
                    "peringatan": peringatan
                },
                "visualisasi": visualisasi
            }
        }
        return jsonify(response_data), 200

    except Exception as e:
        return jsonify({
            "status": "error",
            "message": f"Terjadi kesalahan saat pemrosesan citra: {str(e)}"
        }), 500


# ---------------------------------------------------------------- Jalankan Server
if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5000))
    print(f"\nAPI siap melayani request di http://127.0.0.1:{port}")
    print("Tekan CTRL+C untuk menghentikan server.\n")
    app.run(host="0.0.0.0", port=port, debug=False)
