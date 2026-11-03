"""
fitur.py - preprocessing dan ekstraksi fitur untuk klasifikasi candling (fertil / infertil / mortal).

Modul ini dipakai OLEH SEMUA notebook dan web demo, supaya preprocessing saat training
dan saat prediksi web PERSIS sama. Jangan menyalin fungsi ini ke tempat lain.

Input fungsi utama: gambar RGB (numpy array, uint8) -- format yang sama dengan Gradio/PIL.
Dari file, gunakan muat_gambar(path) yang sudah mengembalikan RGB.

Alur:
  gambar RGB -> resize -> gray -> CLAHE -> blur -> segmentasi ROI berbasis saturasi warna (area telur)
             -> normalisasi intensitas relatif di dalam ROI (p2-p98 -> 0..1)
             -> fitur (intensitas, histogram, area gelap, spasial, LBP, GLCM, gradien, warna relatif)
"""
from collections import OrderedDict

import cv2
import numpy as np
from scipy.stats import kurtosis, skew
from skimage.feature import graycomatrix, graycoprops, local_binary_pattern

# ---------------------------------------------------------------- pengaturan
UKURAN = (224, 224)        # (lebar, tinggi) setelah resize
CLAHE_CLIP = 2.0
CLAHE_GRID = (8, 8)
BLUR_K = 5                 # kernel Gaussian blur (ganjil)
ERODE_K = 7                # batas erosi ROI maksimum supaya pinggir cangkang tidak ikut
MIN_ROI, MAX_ROI = 0.005, 0.995  # batas rasio luas ROI yang dianggap wajar (proporsional untuk telur jauh/dekat)
V_MIN = 20                 # piksel dengan kecerahan (V) di bawah ini dianggap latar gelap
HUE_MAX, HUE_MIN = 35, 155 # hue 'hangat' (OpenCV 0..179): <=35 (merah-kuning) atau >=155 (merah-oranye)
CLOSEUP_FRAC = 0.50        # jika >= 50% piksel sangat jenuh-hangat, anggap telur memenuhi frame (mode 'penuh')
CLOSEUP_S = 120
GAIN_TARGET = 200          # auto-terang HANYA untuk segmentasi ROI: foto gelap diperkuat sampai piksel terterangnya ~200
GAIN_MIN_V = 20            # gambar yang terterangnya di bawah ini dianggap hitam/noise: tidak diperkuat
GAIN_MAX = 6.0             # batas penguatan (supaya noise foto yang sangat gelap tidak ikut membesar)
DARK_T = 0.35              # ambang "area gelap" pada intensitas ternormalisasi (0..1)
BRIGHT_T = 0.80
MIN_BLOB = 0.002           # blob gelap dihitung jika luasnya >= 0.2% luas ROI
HIST_BINS = 16
LBP_P, LBP_R = 8, 1
GLCM_LEVELS = 32
USE_COLOR = True           # fitur warna relatif (kromatisitas); set False kalau warna antar perangkat terlalu beda


# ---------------------------------------------------------------- I/O
def muat_gambar(path):
    """Baca file gambar -> RGB uint8. Aman untuk path berspasi/unicode di Windows."""
    data = np.fromfile(str(path), dtype=np.uint8)
    bgr = cv2.imdecode(data, cv2.IMREAD_COLOR)
    if bgr is None:
        raise ValueError(f"Gambar tidak bisa dibaca: {path}")
    return cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB)


def _rgb_uint8(img):
    img = np.asarray(img)
    if img.ndim == 2:
        img = np.stack([img] * 3, axis=-1)
    if img.shape[2] == 4:
        img = img[:, :, :3]
    if img.dtype != np.uint8:
        img = np.clip(img, 0, 255).astype(np.uint8)
    return img


# ---------------------------------------------------------------- deteksi & cropping telur
def deteksi_crop_telur(rgb, target_size=UKURAN, margin_pad=0.03):
    """Mendeteksi objek telur pada citra mentah, membuang latar belakang (tangan, jari, HP, flash),
    melakukan cropping rapat pada telur, resize ke ukuran standar target, dan memberikan kontras seragam.

    Return: (img_crop_kontras_rgb, crop_box, is_closeup)
    crop_box berupa tuple (x1, y1, x2, y2) pada resolusi citra asli.
    """
    rgb = _rgb_uint8(rgb)
    h, w = rgb.shape[:2]

    # Analisis pada skala lebih rendah untuk efisiensi komputasi
    scale = 600.0 / max(h, w)
    sw, sh = max(1, int(w * scale)), max(1, int(h * scale))
    small = cv2.resize(rgb, (sw, sh), interpolation=cv2.INTER_AREA)

    hsv = cv2.cvtColor(small, cv2.COLOR_RGB2HSV).astype(np.float32)
    H, S, V = hsv[:, :, 0], hsv[:, :, 1], hsv[:, :, 2]
    R = small[:, :, 0].astype(np.float32)
    G = small[:, :, 1].astype(np.float32)
    B = small[:, :, 2].astype(np.float32)

    # Deteksi pendaran hangat candling
    warm = ((H <= 45) | (H >= 145)).astype(np.float32)
    diff_rb = np.clip(R - B, 0, 255.0) / 255.0
    val = V / 255.0
    sat = S / 255.0
    glow = val * (0.5 * sat + 0.5 * diff_rb) * warm
    glow_b = cv2.GaussianBlur(glow, (7, 7), 0)

    # Cek apakah citra sudah merupakan close-up telur yang memenuhi frame
    is_closeup = bool((glow_b > 0.15).mean() >= 0.55)

    crop_box = None
    if not is_closeup and glow_b.max() > 0.04:
        nz = glow_b[glow_b > 0.04 * glow_b.max()]
        if len(nz) > 50:
            t_otsu, _ = cv2.threshold((nz * 255).astype(np.uint8), 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
            t_val = max(t_otsu / 255.0 * 0.70, 0.18 * glow_b.max())
        else:
            t_val = 0.18 * glow_b.max()

        bw = (glow_b > t_val).astype(np.uint8) * 255
        bw[:3, :] = 0; bw[-3:, :] = 0; bw[:, :3] = 0; bw[:, -3:] = 0

        n_g, lab_g, stats_g, _ = cv2.connectedComponentsWithStats(bw)
        if n_g > 1:
            best_g = max(range(1, n_g), key=lambda i: stats_g[i, cv2.CC_STAT_AREA])
            comp = (lab_g == best_g).astype(np.uint8) * 255

            # Distance transform opening rapat untuk memutus rembesan jari tangan / HP
            dt = cv2.distanceTransform(comp, cv2.DIST_L2, 5)
            dt_max = dt.max()
            if dt_max > 8:
                k_size = max(5, int(dt_max * 0.32) * 2 + 1)
                kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k_size, k_size))
                comp_open = cv2.morphologyEx(comp, cv2.MORPH_OPEN, kernel)
                nc_o, lab_o, stats_o, _ = cv2.connectedComponentsWithStats(comp_open)
                if nc_o > 1:
                    best_o = max(range(1, nc_o), key=lambda i: stats_o[i, cv2.CC_STAT_AREA])
                    comp = (lab_o == best_o).astype(np.uint8) * 255

            cnts, _ = cv2.findContours(comp, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
            if cnts:
                cnt = max(cnts, key=cv2.contourArea)
                if cv2.contourArea(cnt) > 100:
                    hull = cv2.convexHull(cnt)
                    bx, by, bw_b, bh_b = cv2.boundingRect(hull)
                    if bw_b < sw * 0.96 or bh_b < sh * 0.96:
                        pad_x = int(bw_b * margin_pad)
                        pad_y = int(bh_b * margin_pad)
                        x1 = max(0, bx - pad_x)
                        y1 = max(0, by - pad_y)
                        x2 = min(sw, bx + bw_b + pad_x)
                        y2 = min(sh, by + bh_b + pad_y)

                        orig_x1 = max(0, int(round(x1 / scale)))
                        orig_y1 = max(0, int(round(y1 / scale)))
                        orig_x2 = min(w, int(round(x2 / scale)))
                        orig_y2 = min(h, int(round(y2 / scale)))
                        crop_box = (orig_x1, orig_y1, orig_x2, orig_y2)

    if crop_box is not None:
        x1, y1, x2, y2 = crop_box
        cropped = rgb[y1:y2, x1:x2]
    else:
        cropped = rgb.copy()

    resized = cv2.resize(cropped, target_size, interpolation=cv2.INTER_AREA)

    # Peningkatan kontras seragam: LAB CLAHE pada kanal L + unsharp mask halus
    lab = cv2.cvtColor(resized, cv2.COLOR_RGB2LAB)
    clahe_l = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(8, 8))
    lab[:, :, 0] = clahe_l.apply(lab[:, :, 0])
    contrast_rgb = cv2.cvtColor(lab, cv2.COLOR_LAB2RGB)

    gaussian = cv2.GaussianBlur(contrast_rgb, (0, 0), 2.0)
    crisp_rgb = cv2.addWeighted(contrast_rgb, 1.35, gaussian, -0.35, 0)

    return crisp_rgb, crop_box, is_closeup


# ---------------------------------------------------------------- ekstraksi peta jaringan darah / embrio
def ekstrak_peta_darah(rgb, mask=None):
    """Mengekstrak jaringan pembuluh darah dan embrio berbasis perbedaan penyerapan hemoglobin.
    Hemoglobin menyerap kuat kanal hijau (Green), sementara cangkang/cahaya meneruskan merah.
    Menghasilkan peta darah kontras tinggi (grayscale), visualisasi RGB, dan statistik kuantitatif.

    Return: (peta_darah_uint8, vis_darah_rgb, statistik_darah)
    """
    rgb = _rgb_uint8(rgb)
    h, w = rgb.shape[:2]
    if mask is None:
        mask = np.ones((h, w), dtype=bool)

    R = rgb[:, :, 0].astype(np.float32)
    G = rgb[:, :, 1].astype(np.float32)

    # Selisih penyerapan hemoglobin
    diff = np.clip(R - G, 0, 255.0).astype(np.uint8)
    clahe_v = cv2.createCLAHE(clipLimit=4.0, tileGridSize=(8, 8))
    diff_clahe = clahe_v.apply(diff)

    # Inversi kanal hijau setelah CLAHE (area darah gelap menjadi terang)
    g_clahe = clahe_v.apply(rgb[:, :, 1])
    g_inv = 255 - g_clahe

    # Fusi peta serapan darah
    fusi = 0.60 * diff_clahe.astype(np.float32) + 0.40 * g_inv.astype(np.float32)
    peta_darah = cv2.normalize(fusi, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)

    # Visualisasi RGB berfokus pada jaringan darah
    vis_darah = rgb.copy()
    vis_darah[:, :, 0] = np.clip(rgb[:, :, 0].astype(np.int32) + (peta_darah * 0.40).astype(np.int32), 0, 255).astype(np.uint8)
    vis_darah[:, :, 1] = np.clip(rgb[:, :, 1].astype(np.int32) - (peta_darah * 0.30).astype(np.int32), 0, 255).astype(np.uint8)
    vis_darah[:, :, 2] = np.clip(rgb[:, :, 2].astype(np.int32) - (peta_darah * 0.30).astype(np.int32), 0, 255).astype(np.uint8)

    # Statistik kuantitatif jaringan darah di dalam ROI
    v = peta_darah[mask].astype(np.float32) / 255.0
    if len(v) > 0:
        d_mean = float(v.mean())
        d_std = float(v.std())
        t_vessel = float(np.percentile(v, 75))
        d_densitas = float((v > t_vessel).mean())
    else:
        d_mean, d_std, d_densitas = 0.0, 0.0, 0.0

    stats = {
        "darah_mean": d_mean,
        "darah_std": d_std,
        "darah_densitas": d_densitas,
    }
    return peta_darah, vis_darah, stats


# ---------------------------------------------------------------- segmentasi ROI
def _otsu_ambang(x):
    t, _ = cv2.threshold(x.reshape(-1, 1).astype(np.uint8), 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    return t


def _segmentasi_roi(rgb):
    """Cari area telur dari pendaran candling dan geometri cembung telur:
    - Telur candling memancarkan pendaran cahaya hangat (transmisi cahaya senter melalui cangkang).
    - Pada telur fertil/mortal dengan embrio gelap, ambang adaptif rendah memastikan seluruh cangkang
      telur tetap tercakup tanpa terbelah diagonal.
    - Rembesan cahaya pada jari tangan/background dipisahkan secara presisi menggunakan
      Distance Transform Egg Core + Marker-Controlled Watershed.
    - Bentuk telur difinalisasi dengan Convex Hull kontur asli telur (seperti test_convex_hull.jpg).

    Mode:
      "convex_hull" : segmentasi presisi berbasis watershed egg core + convex hull telur
      "glow"        : segmentasi fallback berbasis connected component pendaran
      "cadangan"    : fallback elips jika segmentasi tidak mencapai ambang batas
    Return (mask bool, roi_ok, mode, hull)."""
    h, w = rgb.shape[:2]
    total_area = float(h * w)

    # 1. Analisis warna & pendaran candling
    hsv = cv2.cvtColor(rgb, cv2.COLOR_RGB2HSV).astype(np.float32)
    H, S, V = hsv[:, :, 0], hsv[:, :, 1], hsv[:, :, 2]
    R = rgb[:, :, 0].astype(np.float32)
    G = rgb[:, :, 1].astype(np.float32)
    B = rgb[:, :, 2].astype(np.float32)

    # Deteksi hue hangat (0..45 merah-kuning, 140..179 magenta-merah)
    warm = ((H <= 45) | (H >= 140)).astype(np.float32)
    diff_rb = np.clip(R - B, 0, 255.0) / 255.0
    sat = S / 255.0
    val = V / 255.0

    # Peta pendaran candling
    glow = val * (0.5 * sat + 0.5 * diff_rb) * warm
    glow_b = cv2.GaussianBlur(glow, (7, 7), 0)

    # Puncak pendaran di dalam frame
    g_inner = glow_b[8:-8, 8:-8]
    g_max = g_inner.max() if g_inner.size > 0 else glow_b.max()

    # Ambang adaptif rendah agar area embrio gelap fertil/mortal tetap masuk dalam area telur
    t_val = max(0.06, min(0.15, 0.12 * g_max))
    bw_glow = (glow_b > t_val).astype(np.uint8) * 255
    bw_glow[:3, :] = 0; bw_glow[-3:, :] = 0; bw_glow[:, :3] = 0; bw_glow[:, -3:] = 0

    mask = np.zeros((h, w), np.uint8)
    roi_ok = False
    mode = "cadangan"
    hull = None

    # 2. Distance Transform untuk mendeteksi Inti Telur (Egg Core) dan memutus jari tangan
    dt = cv2.distanceTransform(bw_glow, cv2.DIST_L2, 5)
    dt_max = dt.max()

    if dt_max > 12:
        # Inti telur berada di area tebal (dt tinggi), sedangkan jari tangan tipis (dt rendah)
        egg_core = (dt > 0.35 * dt_max).astype(np.uint8) * 255
        nc, lab_c, stats_c, _ = cv2.connectedComponentsWithStats(egg_core)
        if nc > 1:
            best_c = max(range(1, nc), key=lambda i: stats_c[i, cv2.CC_STAT_AREA])
            egg_core = (lab_c == best_c).astype(np.uint8) * 255

        # Marker-Controlled Watershed untuk memisahkan telur dari rembesan jari tangan
        markers = np.zeros((h, w), np.int32)
        markers[~bw_glow.astype(bool)] = 1   # Marker background
        markers[egg_core > 0] = 2            # Marker inti telur

        cv2.watershed(rgb, markers)
        egg_mask = (markers == 2).astype(np.uint8) * 255

        cnts, _ = cv2.findContours(egg_mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if cnts:
            cnt = max(cnts, key=cv2.contourArea)
            area = cv2.contourArea(cnt)
            if len(cnt) >= 5 and area > 100:
                h_cand = cv2.convexHull(cnt)
                mask_hull = np.zeros((h, w), np.uint8)
                cv2.drawContours(mask_hull, [h_cand], -1, 255, -1)
                frac = (mask_hull > 0).mean()
                if 0.20 <= frac <= 0.95:
                    mask = mask_hull
                    hull = h_cand
                    roi_ok = True
                    mode = "convex_hull"

    # 3. Fallback jika watershed tidak mencukupi: morphological opening
    if not roi_ok and dt_max > 8:
        k_size = max(3, int(dt_max * 0.22) * 2 + 1)
        kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k_size, k_size))
        comp = cv2.morphologyEx(bw_glow, cv2.MORPH_OPEN, kernel)
        cnts, _ = cv2.findContours(comp, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if cnts:
            cnt = max(cnts, key=cv2.contourArea)
            area = cv2.contourArea(cnt)
            if len(cnt) >= 5 and area > 100:
                h_cand = cv2.convexHull(cnt)
                mask_hull = np.zeros((h, w), np.uint8)
                cv2.drawContours(mask_hull, [h_cand], -1, 255, -1)
                frac = (mask_hull > 0).mean()
                if 0.15 <= frac <= 0.95:
                    mask = mask_hull
                    hull = h_cand
                    roi_ok = True
                    mode = "glow"

    # 4. Fallback terakhir jika segmentasi gagal total
    if not roi_ok:
        cv2.ellipse(mask, (w // 2, h // 2), (int(w * 0.45), int(h * 0.48)), 0, 0, 360, 255, -1)
        mode = "cadangan"
        cnts_e, _ = cv2.findContours(mask.copy(), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        hull = cv2.convexHull(cnts_e[0]) if cnts_e else None

    return mask > 0, roi_ok, mode, hull


# ---------------------------------------------------------------- preprocessing
def preprocess(img, ukuran=UKURAN):
    """Lakukan cropping telur otomatis, resize seragam, peningkatan kontras, segmentasi ROI,
    binerisasi objek pemisah background, rekonstruksi RGB, serta ekstraksi jaringan darah dan embrio.

    Return dict: rgb, gray, gray_blur, mask (bool), roi_biner, rgb_masked, norm (float 0..1),
                 roi_ok, roi_mode, roi_vis, peta_darah, vis_darah, stats_darah, crop_box, is_closeup.
    """
    img_rgb = _rgb_uint8(img)
    # 1. Deteksi objek telur, buang tangan/latar, crop rapat, dan kontraskan secara seragam
    rgb, crop_box, is_closeup = deteksi_crop_telur(img_rgb, target_size=ukuran)

    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    gray = cv2.createCLAHE(clipLimit=CLAHE_CLIP, tileGridSize=CLAHE_GRID).apply(gray)
    gray_blur = cv2.GaussianBlur(gray, (BLUR_K, BLUR_K), 0)

    # 2. Segmentasi ROI & Binerisasi objek telur (memisahkan objek dari latar belakang)
    mask, roi_ok, roi_mode, hull = _segmentasi_roi(rgb)

    # Citra Biner pemisah objek telur dengan latar belakang (0=latar hitam, 255=objek telur putih)
    roi_biner = (mask.astype(np.uint8) * 255)
    # Format 3 channel uint8 (RGB) untuk penyimpanan & visualisasi seragam
    roi_vis = np.stack([roi_biner, roi_biner, roi_biner], axis=-1)

    # Rekonstruksi Citra RGB (objek telur tetap warna aslinya, latar belakang hitam pekat)
    rgb_masked = cv2.bitwise_and(rgb, rgb, mask=mask.astype(np.uint8))

    # 3. Normalisasi relatif di dalam ROI (p2..p98 -> 0..1)
    v = gray_blur[mask].astype(np.float32)
    p2, p98 = np.percentile(v, [2, 98])
    norm = np.clip((gray_blur.astype(np.float32) - p2) / (p98 - p2 + 1e-6), 0, 1).astype(np.float32)

    # 4. Ekstraksi peta jaringan darah & embrio
    peta_darah, vis_darah, stats_darah = ekstrak_peta_darah(rgb, mask)

    return {
        "rgb": rgb,
        "gray": gray,
        "gray_blur": gray_blur,
        "mask": mask,
        "roi_biner": roi_biner,
        "rgb_masked": rgb_masked,
        "norm": norm,
        "roi_ok": roi_ok,
        "roi_mode": roi_mode,
        "roi_vis": roi_vis,
        "hull": hull,
        "peta_darah": peta_darah,
        "vis_darah": vis_darah,
        "stats_darah": stats_darah,
        "crop_box": crop_box,
        "is_closeup": is_closeup,
    }


# ---------------------------------------------------------------- ekstraksi fitur
def _blob_gelap(norm, mask):
    gelap = ((norm < DARK_T) & mask).astype(np.uint8)
    gelap = cv2.morphologyEx(gelap, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    n, _, stats, _ = cv2.connectedComponentsWithStats(gelap, connectivity=8)
    luas_roi = max(int(mask.sum()), 1)
    areas = [stats[i, cv2.CC_STAT_AREA] for i in range(1, n) if stats[i, cv2.CC_STAT_AREA] >= MIN_BLOB * luas_roi]
    return gelap.astype(bool), areas, luas_roi


def ekstrak_fitur_dict(img):
    """Return (OrderedDict fitur, info). info berisi roi_ok, roi_mode, crop_box."""
    pre = preprocess(img)
    mask, norm, rgb = pre["mask"], pre["norm"], pre["rgb"]
    v = norm[mask]
    f = OrderedDict()

    # 1) statistik intensitas (relatif, di dalam ROI)
    f["int_mean"] = v.mean()
    f["int_std"] = v.std()
    f["int_skew"] = float(skew(v)) if v.std() > 1e-6 else 0.0
    f["int_kurt"] = float(kurtosis(v)) if v.std() > 1e-6 else 0.0
    for p in (10, 25, 50, 75, 90):
        f[f"int_p{p}"] = np.percentile(v, p)

    # 2) histogram ternormalisasi (jumlah = 1)
    hist, _ = np.histogram(v, bins=HIST_BINS, range=(0, 1))
    for i, h in enumerate(hist / max(len(v), 1)):
        f[f"hist_{i:02d}"] = h

    # 3) area gelap / terang
    gelap, areas, luas_roi = _blob_gelap(norm, mask)
    f["rasio_gelap"] = (gelap & mask).sum() / luas_roi
    f["rasio_terang"] = ((norm > BRIGHT_T) & mask).sum() / luas_roi
    v8 = (v * 255).astype(np.uint8).reshape(-1, 1)
    t_otsu, _ = cv2.threshold(v8, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    f["rasio_gelap_otsu"] = (v8 < t_otsu).mean()
    f["blob_jumlah"] = len(areas)
    f["blob_terbesar"] = (max(areas) / luas_roi) if areas else 0.0
    f["blob_rata2"] = (np.mean(areas) / luas_roi) if areas else 0.0

    # 4) spasial: pusat vs tepi ROI
    dt = cv2.distanceTransform(mask.astype(np.uint8), cv2.DIST_L2, 5)
    inner = mask & (dt > 0.5 * dt.max())
    outer = mask & ~inner
    m_in = norm[inner].mean() if inner.any() else f["int_mean"]
    m_out = norm[outer].mean() if outer.any() else f["int_mean"]
    f["spasial_pusat"] = m_in
    f["spasial_tepi"] = m_out
    f["spasial_selisih"] = m_in - m_out

    # 5) tekstur LBP (uniform) di dalam ROI
    lbp = local_binary_pattern((norm * 255).astype(np.uint8), LBP_P, LBP_R, method="uniform")
    nb = LBP_P + 2
    lh, _ = np.histogram(lbp[mask], bins=nb, range=(0, nb))
    for i, h in enumerate(lh / max(mask.sum(), 1)):
        f[f"lbp_{i:02d}"] = h

    # 6) tekstur GLCM pada bounding box ROI (di luar ROI diisi rata-rata ROI agar tidak ada tepi palsu)
    ys, xs = np.where(mask)
    crop = norm[ys.min():ys.max() + 1, xs.min():xs.max() + 1].copy()
    cm = mask[ys.min():ys.max() + 1, xs.min():xs.max() + 1]
    crop[~cm] = v.mean()
    q = np.clip((crop * (GLCM_LEVELS - 1)).round(), 0, GLCM_LEVELS - 1).astype(np.uint8)
    glcm = graycomatrix(q, distances=[1], angles=[0, np.pi / 4, np.pi / 2, 3 * np.pi / 4],
                        levels=GLCM_LEVELS, symmetric=True, normed=True)
    for prop in ("contrast", "dissimilarity", "homogeneity", "energy", "correlation"):
        f[f"glcm_{prop}"] = graycoprops(glcm, prop).mean()

    # 7) gradien / tepi di dalam ROI
    gx = cv2.Sobel(norm, cv2.CV_32F, 1, 0, ksize=3)
    gy = cv2.Sobel(norm, cv2.CV_32F, 0, 1, ksize=3)
    mag = np.sqrt(gx ** 2 + gy ** 2)[mask]
    f["grad_mean"] = mag.mean()
    f["grad_std"] = mag.std()
    edges = cv2.Canny((norm * 255).astype(np.uint8), 50, 150) > 0
    f["tepi_densitas"] = (edges & mask).sum() / max(mask.sum(), 1)

    # 8) warna relatif (kromatisitas r,g) -- lebih tahan terhadap terang-gelap daripada RGB mentah
    if USE_COLOR:
        c = rgb.astype(np.float32)
        s = c.sum(axis=2) + 1e-6
        rn, gn = (c[:, :, 0] / s)[mask], (c[:, :, 1] / s)[mask]
        f["warna_r_mean"], f["warna_r_std"] = rn.mean(), rn.std()
        f["warna_g_mean"], f["warna_g_std"] = gn.mean(), gn.std()

    # 9) fitur kuantitatif jaringan darah & embrio
    for k, val in pre["stats_darah"].items():
        f[k] = val

    f = OrderedDict((k, float(val)) for k, val in f.items())
    return f, {"roi_ok": pre["roi_ok"], "roi_mode": pre["roi_mode"], "crop_box": pre["crop_box"]}


def ekstrak_fitur(img):
    """Gambar RGB -> vektor fitur 1D (numpy float64). Dipakai training dan web."""
    f, _ = ekstrak_fitur_dict(img)
    return np.array(list(f.values()), dtype=np.float64)


def nama_fitur():
    """Daftar nama fitur sesuai urutan ekstrak_fitur()."""
    rng = np.random.default_rng(0)
    dummy = np.zeros((120, 120, 3), np.uint8)
    cv2.ellipse(dummy, (60, 60), (40, 50), 0, 0, 360, (200, 150, 120), -1)
    dummy = np.clip(dummy + rng.normal(0, 8, dummy.shape), 0, 255).astype(np.uint8)
    return list(ekstrak_fitur_dict(dummy)[0].keys())


def ekstrak_dari_path(path):
    """Path file -> (vektor fitur, info). info['roi_ok'] False artinya segmentasi ROI gagal (pakai cadangan)."""
    f, info = ekstrak_fitur_dict(muat_gambar(path))
    return np.array(list(f.values()), dtype=np.float64), info


# ---------------------------------------------------------------- augmentasi pencahayaan
def augment_pencahayaan(img, rng=None):
    """Variasi acak brightness, contrast, gamma, dan gain warna. HANYA untuk data training."""
    rng = rng or np.random.default_rng()
    x = _rgb_uint8(img).astype(np.float32) / 255.0
    gamma = rng.uniform(0.7, 1.4)
    x = np.power(x, gamma)
    alpha = rng.uniform(0.75, 1.25)          # kontras
    beta = rng.uniform(-0.12, 0.12)          # kecerahan
    x = alpha * (x - 0.5) + 0.5 + beta
    gain = rng.uniform(0.92, 1.08, size=3)   # sedikit geser warna
    x = x * gain
    return (np.clip(x, 0, 1) * 255).astype(np.uint8)


# ---------------------------------------------------------------- penyimpanan citra hasil preprocess
def simpan_hasil_preprocessing(df, output_root="hasil_preprocess"):
    """Menyimpan hasil preprocessing ke 3 folder eksplisit:
      1. output_root/hasil_cropping/<label>/<nama_file>      : Citra telur hasil crop rapat + kontras seragam
      2. output_root/hasil_segmentasi_roi/<label>/<nama_file>: Citra binerisasi ROI pemisah objek telur dari latar belakang
      3. output_root/hasil_ekstraksi_fitur/<label>/<nama_file>: Citra visualisasi ekstraksi fitur jaringan darah/embrio

    df harus memiliki kolom: 'path', 'label'. (Jika ada kolom 'sumber', tetap didukung).
    Diproses secara paralel untuk kecepatan maksimal.
    """
    from concurrent.futures import ThreadPoolExecutor
    from pathlib import Path

    out_dir = Path(output_root)
    baris_list = list(df.itertuples(index=False))
    total = len(baris_list)

    folder_crop_base = out_dir / "hasil_cropping"
    folder_roi_base = out_dir / "hasil_segmentasi_roi"
    folder_feat_base = out_dir / "hasil_ekstraksi_fitur"

    for base in (folder_crop_base, folder_roi_base, folder_feat_base):
        base.mkdir(parents=True, exist_ok=True)

    def _proses_satu(r):
        try:
            path_file = Path(r.path)
            nama_file = path_file.name
            label = getattr(r, "label", "fertil")

            folder_crop = folder_crop_base / label
            folder_roi = folder_roi_base / label
            folder_feat = folder_feat_base / label
            folder_crop.mkdir(parents=True, exist_ok=True)
            folder_roi.mkdir(parents=True, exist_ok=True)
            folder_feat.mkdir(parents=True, exist_ok=True)

            img_rgb = muat_gambar(path_file)
            pre = preprocess(img_rgb)

            # 1. Simpan citra hasil cropping & kontras seragam (224x224 RGB)
            bgr_crop = cv2.cvtColor(pre["rgb"], cv2.COLOR_RGB2BGR)
            _, buf_crop = cv2.imencode(".jpg", bgr_crop)
            buf_crop.tofile(str(folder_crop / nama_file))

            # 2. Simpan citra hasil binerisasi ROI objek telur (latar hitam, objek telur putih)
            bgr_roi = cv2.cvtColor(pre["roi_vis"], cv2.COLOR_RGB2BGR)
            _, buf_roi = cv2.imencode(".jpg", bgr_roi)
            buf_roi.tofile(str(folder_roi / nama_file))

            # 3. Simpan citra hasil ekstraksi fitur jaringan darah / embrio
            bgr_feat = cv2.cvtColor(pre["vis_darah"], cv2.COLOR_RGB2BGR)
            _, buf_feat = cv2.imencode(".jpg", bgr_feat)
            buf_feat.tofile(str(folder_feat / nama_file))

            return True, None
        except Exception as e:
            return False, f"{r.path}: {e}"

    sukses = 0
    with ThreadPoolExecutor(max_workers=6) as executor:
        hasil = list(executor.map(_proses_satu, baris_list))

    for ok, err in hasil:
        if ok:
            sukses += 1
        elif err:
            print(f"[peringatan] {err}")

    print(f"Selesai: {sukses}/{total} citra berhasil diproses dan disimpan ke:")
    print(f" - {folder_crop_base}")
    print(f" - {folder_roi_base}")
    print(f" - {folder_feat_base}")
    return sukses


# ---------------------------------------------------------------- visualisasi tahapan
def tahapan(img):
    """Dict gambar tiap tahap untuk ditampilkan di notebook."""
    pre = preprocess(img)
    return {
        "1. crop & kontras": pre["rgb"],
        "2. binerisasi ROI": pre["roi_vis"],
        "3. rekonstruksi RGB": pre["rgb_masked"],
        "4. gray + CLAHE": pre["gray"],
        "5. peta darah": pre["peta_darah"],
        "6. vis. jaringan": pre["vis_darah"],
        "roi_ok": pre["roi_ok"],
        "roi_mode": pre["roi_mode"],
    }


# ---------------------------------------------------------------- pembungkus & pemuat model Keras
class KerasCandlingWrapper:
    """Wrapper untuk memadukan model Keras dengan StandardScaler,
    sehingga mendukung antarmuka predict() dan predict_proba() scikit-learn.
    """
    def __init__(self, model, scaler, classes):
        self.model = model
        self.scaler = scaler
        self.classes_ = np.array(classes)

    def predict_proba(self, X):
        X = np.asarray(X)
        if X.ndim == 1:
            X = X.reshape(1, -1)
        X_s = self.scaler.transform(X)
        return self.model.predict(X_s, verbose=0)

    def predict(self, X):
        proba = self.predict_proba(X)
        return self.classes_[proba.argmax(axis=1)]


def muat_model_candling(dir_models=None):
    """Memuat model Keras (.keras), StandardScaler (.joblib), dan metadata (.json)."""
    import json
    from pathlib import Path
    import joblib
    import keras

    if dir_models is None:
        dir_models = Path(__file__).resolve().parent.parent / "models"
    dir_models = Path(dir_models)

    path_scaler = dir_models / "scaler.joblib"
    path_keras  = dir_models / "nn_candling.keras"
    path_meta   = dir_models / "nn_candling_meta.json"

    assert path_scaler.exists(), f"Scaler tidak ditemukan: {path_scaler}"
    assert path_keras.exists(),  f"Model Keras tidak ditemukan: {path_keras}"
    assert path_meta.exists(),   f"Metadata tidak ditemukan: {path_meta}"

    scaler = joblib.load(path_scaler)
    model = keras.models.load_model(path_keras)
    meta = json.loads(path_meta.read_text(encoding="utf-8"))
    classes = meta.get("kelas", ["fertil", "infertil", "mortal"])

    wrapper = KerasCandlingWrapper(model, scaler, classes)
    return wrapper, meta

