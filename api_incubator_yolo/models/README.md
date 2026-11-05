# YOLO Models Directory

Tempatkan file weights model YOLO (misalnya `best.pt`, `yolov8n.pt`, atau `egg_detector.pt`) di direktori ini.

API `api_incubator_yolo/app.py` akan otomatis mendeteksi file `.pt` atau `.onnx` yang diletakkan di folder ini saat dijalankan dengan pustaka `ultralytics`.

Jika file model belum disediakan atau pustaka `ultralytics` belum terpasang, sistem secara otomatis beralih ke detektor Computer Vision **OpenCV Robust Fallback** (Segmentasi Kontur & Hough Circles) tanpa error.
