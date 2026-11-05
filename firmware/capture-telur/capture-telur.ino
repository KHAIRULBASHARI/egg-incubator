/*
  ESP32-CAM AI-Thinker (OV3660 / OV2640) - Fitur Capture Telur Jarak Jauh
  Alur:
  1. ESP polling ke server (GET /api/device/poll) tiap 3 detik.
  2. Saat ada tugas pending -> server mengirim parameter (job, exp, gain, q, delay).
  3. ESP menyalakan kamera -> relay candling GPIO14 HIGH -> tunggu settle -> ambil frame JPEG.
  4. Relay candling kembali LOW -> salin citra ke buffer PSRAM -> kamera dinonaktifkan.
  5. Kirim JPEG mentah ke server via POST /api/device/upload?job={id}.
*/

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include "esp_camera.h"
#include "esp_log.h"
#include "secrets.h"

// ================= KONFIGURASI HARDWARE & JARINGAN =================
#define POLL_MS        2000                 // Jeda polling ke server (milidetik)
#define TX_POWER       WIFI_POWER_19_5dBm   // Daya transmisi WiFi maksimal untuk upload cepat
#define PIN_RELAY      14                   // Relay LED candling (HIGH trigger)
#define JPEG_QUALITY   8                    // Kualitas JPEG optimal QXGA (tajam, hemat bandwidth)
#define MAX_TRIES      3                    // Maksimum percobaan capture saat frame cacat

// ================= PIN KAMERA AI-THINKER =================
#define PWDN_GPIO_NUM  32
#define RESET_GPIO_NUM -1
#define XCLK_GPIO_NUM   0
#define SIOD_GPIO_NUM  26
#define SIOC_GPIO_NUM  27
#define Y9_GPIO_NUM    35
#define Y8_GPIO_NUM    34
#define Y7_GPIO_NUM    39
#define Y6_GPIO_NUM    36
#define Y5_GPIO_NUM    21
#define Y4_GPIO_NUM    19
#define Y3_GPIO_NUM    18
#define Y2_GPIO_NUM     5
#define VSYNC_GPIO_NUM 25
#define HREF_GPIO_NUM  23
#define PCLK_GPIO_NUM  22

// ================= VARIABEL GLOBAL =================
bool camUp = false;
unsigned long lastPoll = 0;

struct CamCfg {
  int ev;
  int gain;
  int q;
  bool valid;
};
CamCfg camCfg = {0, 0, 0, false};

// Deteksi otomatis protokol TLS dari awalan SERVER_URL
static inline bool useTls() {
  return strncmp(SERVER_URL, "https", 5) == 0;
}

// ================= KONTROL RELAY =================
// Menyalakan atau mematikan relay LED candling (GPIO14)
void relaySet(bool on) {
  digitalWrite(PIN_RELAY, on ? HIGH : LOW);
}

// ================= MANAJEMEN KAMERA =================
// Membentuk struktur konfigurasi kamera untuk AI-Thinker
static camera_config_t makeConfig(framesize_t fs) {
  camera_config_t c = {};
  c.ledc_channel = LEDC_CHANNEL_0;
  c.ledc_timer   = LEDC_TIMER_0;
  c.pin_d0       = Y2_GPIO_NUM;
  c.pin_d1       = Y3_GPIO_NUM;
  c.pin_d2       = Y4_GPIO_NUM;
  c.pin_d3       = Y5_GPIO_NUM;
  c.pin_d4       = Y6_GPIO_NUM;
  c.pin_d5       = Y7_GPIO_NUM;
  c.pin_d6       = Y8_GPIO_NUM;
  c.pin_d7       = Y9_GPIO_NUM;
  c.pin_xclk     = XCLK_GPIO_NUM;
  c.pin_pclk     = PCLK_GPIO_NUM;
  c.pin_vsync    = VSYNC_GPIO_NUM;
  c.pin_href     = HREF_GPIO_NUM;
  c.pin_sccb_sda = SIOD_GPIO_NUM;
  c.pin_sccb_scl = SIOC_GPIO_NUM;
  c.pin_pwdn     = PWDN_GPIO_NUM;
  c.pin_reset    = RESET_GPIO_NUM;
  c.xclk_freq_hz = 20000000;
  c.pixel_format = PIXFORMAT_JPEG;
  c.grab_mode    = CAMERA_GRAB_LATEST;

  if (psramFound()) {
    c.frame_size   = fs;
    c.jpeg_quality = JPEG_QUALITY;
    c.fb_count     = 2;
    c.fb_location  = CAMERA_FB_IN_PSRAM;
  } else {
    c.frame_size   = FRAMESIZE_SVGA;
    c.jpeg_quality = 12;
    c.fb_count     = 1;
    c.fb_location  = CAMERA_FB_IN_DRAM;
  }
  return c;
}

// Kalibrasi parameter sensor untuk meminimalkan noise pada kondisi candling gelap
void tuneSensor() {
  sensor_t *s = esp_camera_sensor_get();
  if (!s) return;

  s->set_whitebal(s, 1);
  s->set_awb_gain(s, 1);
  s->set_wb_mode(s, 0);
  s->set_bpc(s, 1);
  s->set_wpc(s, 1);
  s->set_raw_gma(s, 1);
  s->set_lenc(s, 1);
  s->set_gainceiling(s, GAINCEILING_2X);
  s->set_brightness(s, 0);
  s->set_contrast(s, 0);
  s->set_saturation(s, 0);

  // Penyesuaian orientasi dan saturasi khusus sensor OV3660
  if (s->id.PID == OV3660_PID) {
    s->set_vflip(s, 1);
    s->set_saturation(s, -1);
  }
}

// Inisialisasi kamera on-demand dengan target resolusi QXGA (2048x1536)
bool initCamera() {
  // Coba inisialisasi urutan resolusi tertinggi yang didukung sensor (QXGA -> UXGA -> SXGA)
  camera_config_t c = makeConfig(FRAMESIZE_QXGA);
  if (esp_camera_init(&c) == ESP_OK) {
    tuneSensor();
    camCfg.valid = false;
    return true;
  }

  esp_camera_deinit();
  c = makeConfig(FRAMESIZE_UXGA);
  if (esp_camera_init(&c) == ESP_OK) {
    tuneSensor();
    camCfg.valid = false;
    return true;
  }

  esp_camera_deinit();
  c = makeConfig(FRAMESIZE_SXGA);
  if (esp_camera_init(&c) == ESP_OK) {
    tuneSensor();
    camCfg.valid = false;
    return true;
  }

  return false;
}

// Mengaktifkan kamera jika belum aktif
bool camStart() {
  if (camUp) return true;
  camUp = initCamera();
  return camUp;
}

// Mematikan kamera untuk menghemat daya dan mencegah interferensi Wi-Fi
void camStop() {
  if (!camUp) return;
  esp_camera_deinit();
  camUp = false;
  camCfg.valid = false;
}

// Pemulihan kamera saat frame berulang kali gagal
void recoverCamera() {
  esp_camera_deinit();
  delay(300);
  camUp = initCamera();
}

// Penerapan nilai exposure, gain, dan kualitas JPEG yang diminta server
bool applyCamSettings(int ev, int gain, int q) {
  if (camCfg.valid && camCfg.ev == ev && camCfg.gain == gain && camCfg.q == q) {
    return false;
  }
  sensor_t *s = esp_camera_sensor_get();
  if (!s) return false;

  s->set_quality(s, q);
  s->set_exposure_ctrl(s, 0);
  s->set_aec_value(s, ev);
  s->set_gain_ctrl(s, 0);
  s->set_agc_gain(s, gain);

  camCfg = {ev, gain, q, true};
  return true;
}

// Verifikasi integritas format JPEG (marker SOI FF D8 dan EOI FF D9)
static bool jpegValid(camera_fb_t *fb) {
  if (!fb || fb->format != PIXFORMAT_JPEG || fb->len < 1000) {
    return false;
  }
  return fb->buf[0] == 0xFF && fb->buf[1] == 0xD8 &&
         fb->buf[fb->len - 2] == 0xFF && fb->buf[fb->len - 1] == 0xD9;
}

// Eksekusi pemotretan telur candling
bool captureEgg(int ev, int gain, int q, int settle, uint8_t **out, size_t *outLen) {
  if (!camStart()) {
    relaySet(false);
    return false;
  }

  bool changed = applyCamSettings(ev, gain, q);
  int warm = changed ? 3 : 1;

  // Nyalakan lampu relay candling (GPIO14 HIGH)
  relaySet(true);
  delay(settle);

  camera_fb_t *fb = NULL;
  int tries = 0;
  int qUsed = q;

  while (!fb && tries < MAX_TRIES) {
    tries++;
    // Buang frame pemanasan sensor agar eksposur stabil
    for (int i = 0; i < warm; i++) {
      camera_fb_t *old = esp_camera_fb_get();
      if (old) esp_camera_fb_return(old);
    }

    camera_fb_t *t = esp_camera_fb_get();
    if (jpegValid(t)) {
      fb = t;
      break;
    }
    if (t) esp_camera_fb_return(t);
    Serial.printf("[CAP] Percobaan %d gagal\n", tries);

    warm = 3;
    if (tries == 3) {
      recoverCamera();
      applyCamSettings(ev, gain, qUsed);
    } else {
      qUsed = min(qUsed + 2, 15);
      sensor_t *s = esp_camera_sensor_get();
      if (s) s->set_quality(s, qUsed);
      camCfg.valid = false;
    }
    delay(100);
  }

  // Pastikan relay SELALU mati setelah pemotretan
  relaySet(false);

  bool ok = false;
  if (fb) {
    *out = (uint8_t*)ps_malloc(fb->len);
    if (*out) {
      memcpy(*out, fb->buf, fb->len);
      *outLen = fb->len;
      ok = true;
    }
    esp_camera_fb_return(fb);
  }

  // Matikan modul kamera sebelum mulai upload agar radio WiFi maksimal
  camStop();
  return ok;
}

// ================= PARSING PROTOKOL =================
static int getInt(const String &s, const char *key, int def) {
  String k = String(key) + "=";
  int i = s.indexOf(k);
  if (i < 0) return def;
  i += k.length();
  int j = s.indexOf(';', i);
  if (j < 0) j = s.length();
  return s.substring(i, j).toInt();
}

// ================= KOMUNIKASI HTTP KE SERVER =================
// Mengirim data foto mentah atau notifikasi kegagalan ke server
bool sendResult(int job, const uint8_t *buf, size_t len, bool fail) {
  WiFiClientSecure sclient;
  WiFiClient pclient;
  HTTPClient http;

  String url = String(SERVER_URL) + "/api/device/upload?job=" + String(job) + (fail ? "&fail=1" : "");

  // CATATAN KEAMANAN: Lingkungan produksi sebaiknya menggunakan sclient.setCACert(root_ca)
  if (useTls()) {
    sclient.setInsecure();
    http.begin(sclient, url);
  } else {
    http.begin(pclient, url);
  }

  http.setTimeout(30000);
  http.addHeader("X-Device-Key", DEVICE_KEY);
  http.addHeader("Content-Type", fail ? "text/plain" : "image/jpeg");

  int code = fail ? http.POST("fail") : http.POST((uint8_t*)buf, len);
  http.end();

  Serial.printf("[UPLOAD] job=%d kode=%d ukuran=%uKB\n", job, code, (unsigned)(len / 1024));
  return code == 200;
}

// Memeriksa antrean pekerjaan pada server
void pollServer() {
  WiFiClientSecure sclient;
  WiFiClient pclient;
  HTTPClient http;

  String url = String(SERVER_URL) + "/api/device/poll";

  if (useTls()) {
    sclient.setInsecure();
    http.begin(sclient, url);
  } else {
    http.begin(pclient, url);
  }

  http.setTimeout(8000);
  http.addHeader("X-Device-Key", DEVICE_KEY);
  http.addHeader("X-RSSI", String(WiFi.RSSI()));

  int code = http.GET();
  String body = (code == 200) ? http.getString() : "";
  http.end();

  if (code != 200) {
    Serial.printf("[POLL] kode=%d\n", code);
    return;
  }

  // Jika response adalah "NONE", tidak ada tugas
  if (!body.startsWith("job=")) {
    return;
  }

  int job     = getInt(body, "job", 0);
  int ev      = constrain(getInt(body, "exp", 500), 0, 1200);
  int gain    = constrain(getInt(body, "gain", 0), 0, 30);
  int q       = constrain(getInt(body, "q", JPEG_QUALITY), 4, 15);
  int delayMs = constrain(getInt(body, "delay", 250), 100, 3000);

  Serial.printf("[JOB %d] exp=%d gain=%d q=%d delay=%d\n", job, ev, gain, q, delayMs);

  uint8_t *img = NULL;
  size_t len = 0;
  unsigned long t0 = millis();

  if (captureEgg(ev, gain, q, delayMs, &img, &len)) {
    Serial.printf("[JOB %d] foto %uKB dalam %lums\n", job, (unsigned)(len / 1024), millis() - t0);
    sendResult(job, img, len, false);
    free(img);
  } else {
    Serial.printf("[JOB %d] Gagal memotret, melapor ke server\n", job);
    sendResult(job, NULL, 0, true);
  }
}

// ================= KONEKSI WIFI =================
void connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  WiFi.setTxPower(TX_POWER);

  Serial.print("Menghubungkan ke WiFi");
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED) {
    delay(300);
    Serial.print(".");
    if (millis() - t0 > 30000) {
      Serial.println("\nWiFi gagal terhubung, melakukan restart...");
      ESP.restart();
    }
  }
  Serial.printf("\nWiFi terhubung. IP: %s | RSSI: %d dBm\n",
                WiFi.localIP().toString().c_str(),
                WiFi.RSSI());
}

// ================= SETUP & LOOP =================
void setup() {
  Serial.begin(115200);

  // Redam log internal GPIO ISR service
  esp_log_level_set("gpio", ESP_LOG_NONE);

  // Inisialisasi pin relay candling (harus LOW saat boot)
  pinMode(PIN_RELAY, OUTPUT);
  digitalWrite(PIN_RELAY, LOW);

  if (!psramFound()) {
    Serial.println("PERINGATAN: PSRAM tidak terdeteksi! Pastikan PSRAM diaktifkan pada menu Board.");
  }

  // Uji coba kamera satu kali saat startup
  if (!camStart()) {
    Serial.println("Kamera GAGAL inisialisasi awal, restart...");
    delay(3000);
    ESP.restart();
  }

  sensor_t *s = esp_camera_sensor_get();
  if (s) {
    Serial.printf("Sensor kamera terdeteksi, PID: 0x%04X\n", s->id.PID);
  }
  camStop();

  connectWiFi();
  Serial.println("ESP32-CAM Candling siap, mulai mendengarkan tugas...");
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    WiFi.reconnect();
    delay(2000);
    return;
  }

  if (millis() - lastPoll >= POLL_MS) {
    lastPoll = millis();
    pollServer();
  }

  delay(50);
}
