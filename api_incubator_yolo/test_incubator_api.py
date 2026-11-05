"""
test_incubator_api.py
=====================
Unit & Integration test untuk Flask App api_incubator_yolo/app.py.
"""

import sys
import io
import json
import base64
from pathlib import Path
import cv2
import numpy as np

# Tambahkan direktori root aplikasi ke sys.path
APP_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(APP_DIR))

from app import app, deteksi_telur_opencv, gambar_anotasi


def buat_citra_telur_sintetis(jumlah_telur=4):
    """Membuat citra simulasi tray inkubator dengan telur."""
    img = np.full((400, 600, 3), 45, dtype=np.uint8)

    # Grid tray inkubator
    for x in range(0, 600, 100):
        cv2.line(img, (x, 0), (x, 400), (70, 70, 70), 2)
    for y in range(0, 400, 100):
        cv2.line(img, (0, y), (600, y), (70, 70, 70), 2)

    positions = [
        (150, 150, 35, 45, 15),
        (250, 150, 33, 44, -10),
        (350, 250, 36, 48, 5),
        (450, 250, 34, 46, -20)
    ]

    for (cx, cy, ax1, ax2, ang) in positions[:jumlah_telur]:
        cv2.ellipse(img, (cx, cy), (ax1, ax2), ang, 0, 360, (215, 230, 245), -1)
        cv2.ellipse(img, (cx, cy), (ax1, ax2), ang, 0, 360, (180, 195, 210), 2)

    # Convert to RGB
    return cv2.cvtColor(img, cv2.COLOR_BGR2RGB)


def test_api():
    client = app.test_client()

    print("\n1. Testing GET / ...")
    res = client.get("/")
    assert res.status_code == 200, f"Expected 200, got {res.status_code}"
    data = res.get_json()
    print("   Response:", data)
    assert data["status"] == "online"

    print("\n2. Testing GET /api/health ...")
    res = client.get("/api/health")
    assert res.status_code == 200
    data = res.get_json()
    print("   Response:", data)
    assert data["status"] == "healthy"

    print("\n3. Testing OPTIONS /api/incubator/detect ...")
    res = client.options("/api/incubator/detect")
    assert res.status_code == 204
    print("   CORS preflight 204 OK")

    print("\n4. Testing POST /api/incubator/detect (Multipart Form-Data) ...")
    rgb = buat_citra_telur_sintetis(4)
    # Encode as JPEG
    bgr = cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)
    _, buffer = cv2.imencode(".jpg", bgr)
    img_bytes = buffer.tobytes()

    data_payload = {
        "image": (io.BytesIO(img_bytes), "incubator_overhead.jpg")
    }
    res = client.post("/api/incubator/detect", data=data_payload, content_type="multipart/form-data")
    assert res.status_code == 200, f"Failed: {res.get_json()}"
    res_json = res.get_json()
    print("   Total eggs detected:", res_json.get("total_eggs"))
    print("   Detections:", res_json.get("detections"))
    print("   Annotated image prefix:", res_json.get("annotated_image")[:40] if res_json.get("annotated_image") else None)
    assert res_json["status"] == "success"
    assert res_json["total_eggs"] == 4
    assert len(res_json["detections"]) == 4
    assert res_json["annotated_image"].startswith("data:image/jpeg;base64,")

    for d in res_json["detections"]:
        assert "id" in d
        assert "box" in d and len(d["box"]) == 4
        assert "confidence" in d

    print("\n5. Testing POST /api/incubator/detect (JSON Base64) ...")
    b64_str = base64.b64encode(img_bytes).decode("utf-8")
    json_payload = {
        "image": f"data:image/jpeg;base64,{b64_str}"
    }
    res = client.post("/api/incubator/detect", data=json.dumps(json_payload), content_type="application/json")
    assert res.status_code == 200
    res_json2 = res.get_json()
    assert res_json2["status"] == "success"
    assert res_json2["total_eggs"] == 4

    print("\n6. Testing error handling (No image sent) ...")
    res = client.post("/api/incubator/detect", data={}, content_type="multipart/form-data")
    assert res.status_code == 400
    print("   Error handled correctly (400 Bad Request):", res.get_json()["message"])

    print("\nALL INTEGRATION TESTS PASSED SUCCESSFULLY!\n")


if __name__ == "__main__":
    test_api()
