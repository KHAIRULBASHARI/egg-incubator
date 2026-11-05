"""
app.py
======
RESTful API Deteksi Telur Inkubator (Overhead Camera / Tray Detection) berbasis Flask.
Mendukung inferensi Deep Learning YOLO (Ultralytics) jika model tersedia,
dengan fallback otomatis ke detektor Computer Vision OpenCV (Contour & Hough Circles)
yang tangguh untuk menghitung dan melokalisasi telur pada tray inkubator.

Default Port: 5001
Endpoint Utama: POST /api/incubator/detect
"""

import os
import sys
import glob
import base64
import logging
from pathlib import Path
from typing import List, Dict, Any, Tuple, Optional

import cv2
import numpy as np
from flask import Flask, request, jsonify

# ---------------------------------------------------------------- Logging & Paths
logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
logger = logging.getLogger("api_incubator_yolo")

ROOT = Path(__file__).resolve().parent
DIR_MODELS = ROOT / "models"
DIR_MODELS.mkdir(parents=True, exist_ok=True)

# ---------------------------------------------------------------- Inisialisasi Flask & CORS
app = Flask(__name__)

@app.after_request
def enable_cors(response):
    response.headers["Access-Control-Allow-Origin"] = "*"
    response.headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
    response.headers["Access-Control-Allow-Headers"] = "Content-Type, Authorization"
    return response


# ---------------------------------------------------------------- YOLO Loader
HAS_ULTRALYTICS = False
yolo_model = None
yolo_model_path: Optional[str] = None

try:
    from ultralytics import YOLO
    HAS_ULTRALYTICS = True
except ImportError:
    HAS_ULTRALYTICS = False
    logger.info("Modul 'ultralytics' belum terpasang. Menggunakan OpenCV Fallback Detector sebagai default.")


def cari_model_yolo() -> Optional[Path]:
    """Mencari file model YOLO (.pt atau .onnx) dari env var atau direktori models/."""
    # 1. Dari environment variable
    env_path = os.environ.get("YOLO_MODEL_PATH")
    if env_path and Path(env_path).is_file():
        return Path(env_path)

    # 2. Dari folder models lokal
    patterns = ["*.pt", "*.onnx", "best.pt", "yolov8*.pt", "egg*.pt"]
    for pat in patterns:
        matches = list(DIR_MODELS.glob(pat))
        if matches:
            return matches[0]

    return None


if HAS_ULTRALYTICS:
    model_file = cari_model_yolo()
    if model_file and model_file.is_file():
        try:
            logger.info(f"Memuat model YOLO dari: {model_file}")
            yolo_model = YOLO(str(model_file))
            yolo_model_path = str(model_file)
            logger.info("Model YOLO berhasil dimuat!")
        except Exception as e:
            logger.error(f"Gagal memuat model YOLO dari {model_file}: {e}")
            yolo_model = None
    else:
        logger.info(f"Tidak ada model YOLO (.pt / .onnx) di {DIR_MODELS}. Menggunakan OpenCV Fallback.")


# ---------------------------------------------------------------- Helper Functions
def to_base64_jpeg(img_rgb: np.ndarray, quality: int = 90) -> Optional[str]:
    """Mengubah numpy array RGB uint8 menjadi Base64 Data URI JPEG."""
    if img_rgb is None:
        return None
    if img_rgb.ndim == 3 and img_rgb.shape[2] == 3:
        bgr = cv2.cvtColor(img_rgb, cv2.COLOR_RGB2BGR)
    else:
        bgr = img_rgb
    encode_param = [int(cv2.IMWRITE_JPEG_QUALITY), quality]
    success, buffer = cv2.imencode(".jpg", bgr, encode_param)
    if not success:
        return None
    b64_str = base64.b64encode(buffer).decode("utf-8")
    return f"data:image/jpeg;base64,{b64_str}"


def baca_citra_dari_request(req) -> Tuple[Optional[np.ndarray], Optional[str]]:
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
        img_b64 = data.get("image") or data.get("image_base64") or data.get("file")
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

    return None, "Tidak ada file citra yang dikirim. Gunakan multipart/form-data ('image') atau JSON Base64 ('image')."


def hitung_iou(box_a: List[int], box_b: List[int]) -> float:
    """Menghitung Intersection over Union (IoU) antara dua box [x, y, w, h]."""
    x1 = max(box_a[0], box_b[0])
    y1 = max(box_a[1], box_b[1])
    x2 = min(box_a[0] + box_a[2], box_b[0] + box_b[2])
    y2 = min(box_a[1] + box_a[3], box_b[1] + box_b[3])

    w_inter = max(0, x2 - x1)
    h_inter = max(0, y2 - y1)
    area_inter = w_inter * h_inter

    area_a = box_a[2] * box_a[3]
    area_b = box_b[2] * box_b[3]
    area_union = area_a + area_b - area_inter

    if area_union <= 0:
        return 0.0
    return float(area_inter / area_union)


def non_max_suppression_boxes(detections: List[Dict[str, Any]], iou_thresh: float = 0.35) -> List[Dict[str, Any]]:
    """Menyaring duplikasi deteksi yang bertumpukan menggunakan NMS."""
    if not detections:
        return []

    # Urutkan berdasarkan confidence tertinggi
    sorted_dets = sorted(detections, key=lambda d: d["confidence"], reverse=True)
    selected = []

    for det in sorted_dets:
        box = det["box"]
        overlap = False
        for s in selected:
            if hitung_iou(box, s["box"]) > iou_thresh:
                overlap = True
                break
        if not overlap:
            selected.append(det)

    # Urutkan kembali berdasarkan urutan baris/kolom (top-to-bottom, left-to-right)
    # dengan toleransi baris 30px
    def sort_key(d):
        b = d["box"]
        row_bucket = b[1] // 40
        return (row_bucket, b[0])

    selected = sorted(selected, key=sort_key)
    # Beri nomor id urut 1 .. N
    for idx, item in enumerate(selected, start=1):
        item["id"] = idx

    return selected


# ---------------------------------------------------------------- Detektor OpenCV Fallback
def deteksi_telur_opencv(rgb_img: np.ndarray) -> List[Dict[str, Any]]:
    """
    Deteksi telur overhead inkubator menggunakan segmentasi Computer Vision:
    1. Filter & perbaikan kontras (Bilateral Filter + CLAHE).
    2. Thresholding Otsu & Adaptif untuk mengisolasi bentuk elips/oval telur pada tray.
    3. Analisis geometri kontur (luas, rasio aspek, solidity, dan extent).
    4. Fallback ke Hough Circles jika kontur biner tidak menemukan kandidat telur.
    """
    h_img, w_img = rgb_img.shape[:2]
    area_img = h_img * w_img

    min_area = max(180, int(area_img * 0.0015))
    max_area = int(area_img * 0.28)

    gray = cv2.cvtColor(rgb_img, cv2.COLOR_RGB2GRAY)
    blurred = cv2.GaussianBlur(gray, (7, 7), 0)

    raw_detections: List[Dict[str, Any]] = []

    # 1. Segmentasi Kontur (Otsu Normal & Otsu Invert)
    best_contour_dets: List[Dict[str, Any]] = []

    # Tingkatkan kontras lokal dengan CLAHE
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(blurred)

    threshold_modes = [
        ("otsu_normal", cv2.threshold(enhanced, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]),
        ("otsu_inv", cv2.threshold(enhanced, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)[1]),
        ("adaptive", cv2.adaptiveThreshold(enhanced, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY_INV, 31, 3))
    ]

    morph_kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))

    for mode_name, th in threshold_modes:
        closed = cv2.morphologyEx(th, cv2.MORPH_CLOSE, morph_kernel, iterations=2)
        opened = cv2.morphologyEx(closed, cv2.MORPH_OPEN, morph_kernel, iterations=1)
        contours, _ = cv2.findContours(opened, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        mode_candidates = []
        for c in contours:
            c_area = cv2.contourArea(c)
            if not (min_area <= c_area <= max_area):
                continue

            x, y, w, h = cv2.boundingRect(c)
            aspect_ratio = float(w) / float(h)
            # Telur overhead memiliki rasio aspek 0.50 s/d 2.0
            if not (0.50 <= aspect_ratio <= 2.0):
                continue

            # Hitung solidity (rasio luas kontur terhadap convex hull)
            hull = cv2.convexHull(c)
            hull_area = cv2.contourArea(hull)
            solidity = float(c_area / hull_area) if hull_area > 0 else 0.0
            if solidity < 0.75:
                continue

            # Hitung extent (rasio luas kontur terhadap bounding box)
            # Bentuk elips sempurna memiliki extent pi / 4 ~ 0.785
            extent = float(c_area) / (w * h) if (w * h) > 0 else 0.0
            if not (0.42 <= extent <= 0.92):
                continue

            # Estimasi confidence berbasis kekompakan & simetri telur
            score = 0.78 + (0.14 * min(1.0, solidity)) + (0.05 * min(1.0, extent / 0.785))
            conf = float(np.clip(score, 0.75, 0.98))

            mode_candidates.append({
                "id": 0,
                "box": [int(x), int(y), int(w), int(h)],
                "confidence": round(conf, 2),
                "type": f"contour_{mode_name}"
            })

        # Pilih mode segmentasi yang paling konsisten mendeteksi objek telur
        if len(mode_candidates) > len(best_contour_dets):
            best_contour_dets = mode_candidates

    raw_detections.extend(best_contour_dets)

    # 2. Fallback / Pelengkap Hough Circles jika kontur biner sedikit atau kosong
    if len(raw_detections) == 0:
        min_radius = max(12, int(min(h_img, w_img) * 0.025))
        max_radius = int(min(h_img, w_img) * 0.22)
        min_dist = max(25, int(min_radius * 1.6))

        try:
            circles = cv2.HoughCircles(
                blurred,
                cv2.HOUGH_GRADIENT,
                dp=1.2,
                minDist=min_dist,
                param1=50,
                param2=35,
                minRadius=min_radius,
                maxRadius=max_radius
            )
            if circles is not None:
                circles = np.uint16(np.around(circles[0]))
                for (cx, cy, r) in circles:
                    bx = max(0, int(cx - r))
                    by = max(0, int(cy - r))
                    bw = min(w_img - bx, int(r * 2))
                    bh = min(h_img - by, int(r * 2))

                    if min_area <= (bw * bh) <= max_area:
                        raw_detections.append({
                            "id": 0,
                            "box": [bx, by, bw, bh],
                            "confidence": 0.88,
                            "type": "hough_circle"
                        })
        except Exception as e:
            logger.warning(f"HoughCircles info: {e}")

    # Gabungkan dan saring duplikasi dengan Non-Maximum Suppression
    filtered = non_max_suppression_boxes(raw_detections, iou_thresh=0.35)
    return filtered


# ---------------------------------------------------------------- Detektor YOLO
def deteksi_telur_yolo(rgb_img: np.ndarray, model, conf_thresh: float = 0.25) -> List[Dict[str, Any]]:
    """Deteksi telur menggunakan Ultralytics YOLO model."""
    results = model(rgb_img, conf=conf_thresh, verbose=False)
    detections: List[Dict[str, Any]] = []

    if results and len(results) > 0:
        res = results[0]
        if hasattr(res, "boxes") and res.boxes is not None:
            for b in res.boxes:
                xyxy = b.xyxy[0].cpu().numpy()
                conf = float(b.conf[0].cpu().numpy())
                x1, y1, x2, y2 = xyxy
                w = int(x2 - x1)
                h = int(y2 - y1)
                x = int(x1)
                y = int(y1)

                detections.append({
                    "id": 0,
                    "box": [x, y, w, h],
                    "confidence": round(conf, 4),
                    "type": "yolo"
                })

    return non_max_suppression_boxes(detections, iou_thresh=0.45)


# ---------------------------------------------------------------- Visualisasi Anotasi
def gambar_anotasi(rgb_img: np.ndarray, detections: List[Dict[str, Any]]) -> np.ndarray:
    """Menggambar kotak pembatas, penanda pusat, dan label nomor ID pada citra."""
    annotated = rgb_img.copy()
    h_img, w_img = annotated.shape[:2]

    # Skala visual adaptif terhadap resolusi citra
    thickness = max(2, int(min(h_img, w_img) / 300))
    font_scale = max(0.45, min(h_img, w_img) / 900.0)

    # Palet warna RGB
    color_box = (46, 204, 113)     # Emerald Green
    color_bg_tag = (39, 174, 96)    # Darker Green for Tag
    color_text = (255, 255, 255)    # Putih
    color_center = (231, 76, 60)    # Merah untuk titik tengah

    for det in detections:
        x, y, w, h = det["box"]
        cid = det.get("id", 1)
        conf = det.get("confidence", 0.95)

        # 1. Bounding Box
        cv2.rectangle(annotated, (x, y), (x + w, y + h), color_box, thickness)

        # 2. Titik tengah telur (center marker)
        cx, cy = x + w // 2, y + h // 2
        cv2.circle(annotated, (cx, cy), max(3, thickness + 1), color_center, -1)

        # 3. Label badge: "Egg #1 (95%)"
        label = f"Egg #{cid} ({int(conf * 100)}%)"
        (t_w, t_h), baseline = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, font_scale, 1)

        tag_y1 = max(0, y - t_h - 8)
        tag_y2 = y
        tag_x1 = x
        tag_x2 = min(w_img, x + t_w + 10)

        # Background badge
        cv2.rectangle(annotated, (tag_x1, tag_y1), (tag_x2, tag_y2), color_bg_tag, -1)
        # Teks label
        cv2.putText(
            annotated,
            label,
            (tag_x1 + 4, tag_y2 - 4),
            cv2.FONT_HERSHEY_SIMPLEX,
            font_scale,
            color_text,
            1,
            cv2.LINE_AA
        )

    return annotated


# ---------------------------------------------------------------- Endpoints
@app.route("/", methods=["GET"])
def index():
    return jsonify({
        "service": "Incubator Overhead Egg Detection API",
        "description": "Deteksi dan penghitungan jumlah telur pada rak/tray inkubator berbasis YOLO & OpenCV Fallback.",
        "status": "online",
        "port": int(os.environ.get("PORT", 5001)),
        "engine": "YOLO" if yolo_model is not None else "OpenCV Fallback (Contour & Hough Circles)",
        "yolo_model_path": yolo_model_path,
        "endpoints": {
            "GET /api/health": "Pengecekan status kesehatan server",
            "POST /api/incubator/detect": "Deteksi jumlah dan lokasi telur dari citra overhead inkubator"
        }
    }), 200


@app.route("/api/health", methods=["GET"])
def health():
    return jsonify({
        "status": "healthy",
        "engine": "yolo" if yolo_model is not None else "opencv_fallback",
        "has_ultralytics": HAS_ULTRALYTICS,
        "yolo_model_loaded": yolo_model is not None,
        "yolo_model_path": yolo_model_path,
        "port": int(os.environ.get("PORT", 5001))
    }), 200


@app.route("/api/incubator/detect", methods=["POST", "OPTIONS"])
def detect_incubator_eggs():
    """
    Endpoint utama deteksi telur inkubator.
    Menerima citra melalui multipart/form-data ('image') atau JSON Base64 ('image').
    Mengembalikan JSON berisi jumlah telur, list koordinat box [x, y, w, h], keyakinan,
    dan citra teranotasi dalam format Data URI Base64.
    """
    if request.method == "OPTIONS":
        return "", 204

    # 1. Baca Citra
    rgb, err = baca_citra_dari_request(request)
    if err:
        return jsonify({"status": "error", "message": err}), 400

    try:
        # 2. Proses Deteksi (YOLO jika tersedia, atau OpenCV Fallback)
        engine_used = "opencv_fallback"
        if yolo_model is not None:
            detections = deteksi_telur_yolo(rgb, yolo_model)
            engine_used = "yolo"
        else:
            detections = deteksi_telur_opencv(rgb)
            engine_used = "opencv_fallback"

        # 3. Anotasi Citra
        annotated_rgb = gambar_anotasi(rgb, detections)
        annotated_b64 = to_base64_jpeg(annotated_rgb, quality=90)

        # 4. Format List Deteksi Sesuai Spesifikasi: [{"id": 1, "box": [x, y, w, h], "confidence": 0.95}]
        formatted_detections = [
            {
                "id": int(d["id"]),
                "box": [int(coord) for coord in d["box"]],
                "confidence": float(d["confidence"])
            }
            for d in detections
        ]

        # 5. Susun Response JSON
        response_data = {
            "status": "success",
            "total_eggs": len(formatted_detections),
            "detections": formatted_detections,
            "annotated_image": annotated_b64,
            "engine": engine_used
        }

        return jsonify(response_data), 200

    except Exception as e:
        logger.exception("Kesalahan saat menjalankan deteksi telur:")
        return jsonify({
            "status": "error",
            "message": f"Terjadi kesalahan saat memproses deteksi inkubator: {str(e)}"
        }), 500


# ---------------------------------------------------------------- Main Entrypoint
if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5001))
    print("=" * 60)
    print(f"API Deteksi Telur Inkubator siap di http://127.0.0.1:{port}")
    print(f"Engine Aktif: {'YOLO' if yolo_model is not None else 'OpenCV Robust Fallback'}")
    print("=" * 60)
    app.run(host="0.0.0.0", port=port, debug=False)
