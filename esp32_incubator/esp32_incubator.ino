/**
 * ====================================================================================
 * SISTEM CERDAS MONITORING PENETASAN TELUR AYAM - UNIT PENETASAN (INCUBATOR FIRMWARE)
 * Board   : AI Thinker ESP32-CAM (OV2640 / OV3660)
 * Fitur   :
 *  1. Kamera On-Demand Resolusi Maksimal (QXGA 2048x1536 OV3660 / UXGA 1600x1200 OV2640).
 *  2. Background Auto-Upload Non-Blocking setiap 20 detik ke Cloud API.
 *  3. Local Web Server (/ , /capture , /led , /status , /turn) untuk direct browser debugging.
 *  4. Sensor DHT22 untuk suhu & kelembaban real-time.
 *  5. Kontrol Termostat Lampu Pemanas & Kipas Sirkulasi Otomatis (Hysteresis).
 *  6. Kontrol Timer Pembalik Telur Dinamo Otomatis & Manual.
 *  7. Display LCD 16x4 I2C dengan auto-detect.
 * ====================================================================================
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <WebServer.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include "DHT.h"
#include "esp_log.h"

// ─── 1. KREDENSIAL & KONFIGURASI CLOUD ──────────────────────────────────────────────
#if __has_include("secrets.h")
  #include "secrets.h"
#endif

#ifndef WIFI_SSID
  #define WIFI_SSID  "Telur"
#endif
#ifndef WIFI_PASS
  #define WIFI_PASS  "12345678"
#endif
#ifndef SERVER_URL
  #define SERVER_URL "https://iot.walker880.my.id"
#endif
#ifndef DEVICE_KEY
  #define DEVICE_KEY "EggCamSecretKey_9876543210_abcdef1234567890"
#endif

// Endpoint Cloud untuk background upload
const char* UPLOAD_ENDPOINT = "/api/incubator/upload";

// Interval Auto Upload (milidetik) - 20 detik
const unsigned long AUTO_UPLOAD_INTERVAL = 20000;

// ─── 2. PIN DEFINITION (AI-THINKER ESP32-CAM) ───────────────────────────────────────
#define PIN_I2C_SDA      13   // SDA LCD I2C
#define PIN_I2C_SCL      14   // SCL LCD I2C
#define PIN_DHT          16   // Data pin DHT22
#define PIN_RELAY_HEATER 15   // Relay 1: Lampu Pemanas AC 220V (Active LOW)
#define PIN_RELAY_MOTOR  2    // Relay 2: Dinamo Pembalik Telur (Active LOW)
#define PIN_RELAY_FAN    12   // Relay 3: Kipas Sirkulasi / Buang (Active LOW)
#define PIN_FLASH_LED    4    // High-power Flash LED bawaan AI-Thinker (Active HIGH)

#define DHTTYPE          DHT22

// Modul Relay Active LOW (LOW = ON, HIGH = OFF)
#define RELAY_ON         LOW
#define RELAY_OFF        HIGH

// Kamera AI-Thinker OV2640 / OV3660
#define PWDN_GPIO_NUM    32
#define RESET_GPIO_NUM   -1
#define XCLK_GPIO_NUM     0
#define SIOD_GPIO_NUM    26
#define SIOC_GPIO_NUM    27
#define Y9_GPIO_NUM      35
#define Y8_GPIO_NUM      34
#define Y7_GPIO_NUM      39
#define Y6_GPIO_NUM      36
#define Y5_GPIO_NUM      21
#define Y4_GPIO_NUM      19
#define Y3_GPIO_NUM      18
#define Y2_GPIO_NUM       5
#define VSYNC_GPIO_NUM   25
#define HREF_GPIO_NUM    23
#define PCLK_GPIO_NUM    22

#define JPEG_QUALITY     10   // Kualitas kompresi JPEG (10 = jernih & tajam)
#define MAX_TRIES        3    // Maksimum percobaan capture saat frame cacat

// ─── 3. PARAMETER & VARIABEL KONTROL INKUBATOR ──────────────────────────────────────
float tempSetpointMin = 37.5; // Batas bawah lampu ON (°C)
float tempSetpointMax = 38.0; // Batas atas lampu OFF (°C)
float tempFanTrigger  = 38.2; // Batas atas kipas aktif (°C)

// Interval Pembalikan Telur (Default: Tiap 3 jam sekali, motor menyala 12 detik)
unsigned long motorIntervalMs = 3UL * 60UL * 60UL * 1000UL; // 3 jam
unsigned long motorDurationMs = 12000UL;                    // 12 detik
unsigned long lastMotorTurn   = 0;
bool isMotorRunning           = false;
unsigned long motorStartTime  = 0;

// Variabel Sensor & Aktuator
float currentTemp = 0.0;
float currentHum  = 0.0;
bool heaterState  = false;
bool fanState     = false;

// Kontrol LED Flash
bool flashLedAuto    = true;  // Flash menyala otomatis saat capture/upload
bool manualLedState  = false; // Status manual lampu LED via web /led

// Status Upload & Jaringan
unsigned long lastUpload      = 0;
int lastUploadCode            = 0;
unsigned long lastUploadTime  = 0;
size_t lastUploadBytes        = 0;
volatile bool uploadInProgress = false;

// Deteksi Hardware
bool lcdAvailable = false;
bool camUp        = false;

// ─── 4. OBJEK & RTOS HANDLERS ───────────────────────────────────────────────────────
DHT dht(PIN_DHT, DHTTYPE);
LiquidCrystal_I2C lcd(0x27, 16, 4);
WebServer server(80);

SemaphoreHandle_t cameraMutex = NULL;
TaskHandle_t uploadTaskHandle = NULL;

// Deteksi otomatis protokol TLS dari awalan SERVER_URL
static inline bool useTls() {
  return strncmp(SERVER_URL, "https", 5) == 0;
}

// ─── 5. KONTROL LED & RELAY ────────────────────────────────────────────────────────
void setFlashLed(bool on) {
  digitalWrite(PIN_FLASH_LED, on ? HIGH : LOW);
}

void triggerMotorTurn() {
  if (isMotorRunning) return; // Mencegah re-trigger ganda
  digitalWrite(PIN_RELAY_MOTOR, RELAY_ON);
  isMotorRunning = true;
  motorStartTime = millis();
  Serial.println("[MOTOR] Pembalik telur MULAI berputar.");
}

void updateThermostat() {
  if (isnan(currentTemp) || currentTemp <= 0) return;

  // Kontrol Pemanas (Heater) dengan Histeresis
  if (currentTemp < tempSetpointMin) {
    digitalWrite(PIN_RELAY_HEATER, RELAY_ON);
    heaterState = true;
  } else if (currentTemp >= tempSetpointMax) {
    digitalWrite(PIN_RELAY_HEATER, RELAY_OFF);
    heaterState = false;
  }

  // Kontrol Kipas Pendingin / Sirkulasi jika suhu terlalu tinggi
  if (currentTemp >= tempFanTrigger) {
    digitalWrite(PIN_RELAY_FAN, RELAY_ON);
    fanState = true;
  } else {
    digitalWrite(PIN_RELAY_FAN, RELAY_OFF);
    fanState = false;
  }

  // Kontrol Timer Pembalik Telur Terjadwal
  unsigned long now = millis();
  if (!isMotorRunning) {
    if (now - lastMotorTurn >= motorIntervalMs) {
      triggerMotorTurn();
    }
  } else {
    if (now - motorStartTime >= motorDurationMs) {
      digitalWrite(PIN_RELAY_MOTOR, RELAY_OFF);
      isMotorRunning = false;
      lastMotorTurn = now;
      Serial.println("[MOTOR] Pembalik telur SELESAI berputar.");
    }
  }
}

// ─── 6. MANAJEMEN & TUNING KAMERA (OV2640 / OV3660) ─────────────────────────────────
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
  s->set_brightness(s, 1);  // Optimal untuk interior inkubator
  s->set_contrast(s, 1);
  s->set_saturation(s, 0);

  // Penyesuaian khusus OV3660 vs OV2640
  if (s->id.PID == OV3660_PID) {
    s->set_vflip(s, 1);
    s->set_saturation(s, -1);
  } else {
    s->set_vflip(s, 1); // Balik vertikal untuk penempatan kamera overhead di atap
  }
}

// Inisialisasi kamera on-demand resolusi maksimal: QXGA (OV3660) -> UXGA (OV2640) -> SXGA
bool initCamera() {
  if (!psramFound()) {
    camera_config_t c = makeConfig(FRAMESIZE_SVGA);
    if (esp_camera_init(&c) == ESP_OK) {
      tuneSensor();
      return true;
    }
    return false;
  }

  // Coba urutan resolusi tertinggi yang didukung sensor
  camera_config_t c = makeConfig(FRAMESIZE_QXGA);
  if (esp_camera_init(&c) == ESP_OK) {
    tuneSensor();
    return true;
  }

  esp_camera_deinit();
  c = makeConfig(FRAMESIZE_UXGA);
  if (esp_camera_init(&c) == ESP_OK) {
    tuneSensor();
    return true;
  }

  esp_camera_deinit();
  c = makeConfig(FRAMESIZE_SXGA);
  if (esp_camera_init(&c) == ESP_OK) {
    tuneSensor();
    return true;
  }

  return false;
}

bool camStart() {
  if (camUp) return true;
  camUp = initCamera();
  return camUp;
}

void camStop() {
  if (!camUp) return;
  esp_camera_deinit();
  camUp = false;
}

// Verifikasi integritas marker JPEG (SOI FF D8 & EOI FF D9)
static bool jpegValid(camera_fb_t *fb) {
  if (!fb || fb->format != PIXFORMAT_JPEG || fb->len < 1000) {
    return false;
  }
  return fb->buf[0] == 0xFF && fb->buf[1] == 0xD8 &&
         fb->buf[fb->len - 2] == 0xFF && fb->buf[fb->len - 1] == 0xD9;
}

// ─── 7. FUNGSI BACKGROUND CAPTURE & UPLOAD KE CLOUD ─────────────────────────────────
bool captureAndUploadFrame() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[UPLOAD] Dibatalkan: WiFi tidak terhubung.");
    return false;
  }

  // Ambil camera mutex dengan timeout 3 detik
  if (xSemaphoreTake(cameraMutex, pdMS_TO_TICKS(3000)) != pdTRUE) {
    Serial.println("[UPLOAD] Kamera sedang dipakai handler lain.");
    return false;
  }

  // Nyalakan LED Flash jika diaktifkan
  bool ledTurnedOnHere = false;
  if (flashLedAuto && !manualLedState) {
    setFlashLed(true);
    ledTurnedOnHere = true;
    delay(100); // Settle exposure sensor
  }

  if (!camStart()) {
    Serial.println("[UPLOAD] Gagal menyalakan modul kamera.");
    if (ledTurnedOnHere) setFlashLed(false);
    xSemaphoreGive(cameraMutex);
    return false;
  }

  camera_fb_t *fb = NULL;
  int tries = 0;

  while (!fb && tries < MAX_TRIES) {
    tries++;
    // Buang frame pemanasan sensor agar eksposur dan AGC stabil
    camera_fb_t *dummy = esp_camera_fb_get();
    if (dummy) esp_camera_fb_return(dummy);

    camera_fb_t *temp = esp_camera_fb_get();
    if (jpegValid(temp)) {
      fb = temp;
      break;
    }
    if (temp) esp_camera_fb_return(temp);
    Serial.printf("[UPLOAD] Percobaan frame %d gagal, mengulang...\n", tries);
    delay(80);
  }

  // Matikan LED Flash yang tadi dinyalakan
  if (ledTurnedOnHere && !manualLedState) {
    setFlashLed(false);
  }

  if (!fb) {
    Serial.println("[UPLOAD] Gagal mendapatkan frame valid dari sensor.");
    camStop();
    xSemaphoreGive(cameraMutex);
    return false;
  }

  // Salin frame ke buffer RAM/PSRAM
  size_t len = fb->len;
  uint8_t *buffer = NULL;
  if (psramFound()) {
    buffer = (uint8_t*) ps_malloc(len);
  } else {
    buffer = (uint8_t*) malloc(len);
  }

  if (!buffer) {
    Serial.println("[UPLOAD] Gagal mengalokasikan buffer memory untuk frame.");
    esp_camera_fb_return(fb);
    camStop();
    xSemaphoreGive(cameraMutex);
    return false;
  }

  memcpy(buffer, fb->buf, len);
  esp_camera_fb_return(fb);

  // Matikan kamera sebelum upload agar radio WiFi 2.4GHz bebas interferensi & daya stabil
  camStop();
  xSemaphoreGive(cameraMutex);

  // Kirim data via HTTP/HTTPS POST
  WiFiClientSecure sclient;
  WiFiClient pclient;
  HTTPClient http;

  String fullUrl = String(SERVER_URL) + UPLOAD_ENDPOINT;

  if (useTls()) {
    sclient.setInsecure(); // Mengabaikan validasi root CA untuk efisiensi RAM ESP32
    http.begin(sclient, fullUrl);
  } else {
    http.begin(pclient, fullUrl);
  }

  http.setTimeout(15000);
  http.addHeader("Content-Type", "image/jpeg");
  http.addHeader("X-Device-Key", DEVICE_KEY);
  http.addHeader("X-RSSI", String(WiFi.RSSI()));
  http.addHeader("X-Temperature", String(currentTemp, 2));
  http.addHeader("X-Humidity", String(currentHum, 1));
  http.addHeader("X-Heater", heaterState ? "1" : "0");
  http.addHeader("X-Fan", fanState ? "1" : "0");
  http.addHeader("X-Motor", isMotorRunning ? "1" : "0");

  unsigned long tStart = millis();
  int code = http.POST(buffer, len);
  unsigned long duration = millis() - tStart;
  http.end();

  free(buffer);

  lastUploadCode  = code;
  lastUploadTime  = millis();
  lastUploadBytes = len;

  Serial.printf("[UPLOAD] Selesai: HTTP %d | Ukuran: %u KB | Waktu: %lu ms\n",
                code, (unsigned)(len / 1024), duration);

  return (code == 200 || code == 201);
}

// FreeRTOS Background Task untuk upload non-blocking
void uploadWorkerTask(void *pvParameters) {
  for (;;) {
    // Menunggu sinyal trigger dari loop()
    ulTaskNotifyTake(pdTRUE, portMAX_DELAY);
    uploadInProgress = true;
    captureAndUploadFrame();
    uploadInProgress = false;
  }
}

// ─── 8. HANDLER LOCAL WEB SERVER ───────────────────────────────────────────────────

// Endpoint: GET / (Dashboard Kontrol & Debugging Langsung dari Browser)
void handleRoot() {
  unsigned long timeSinceLastTurn = millis() - lastMotorTurn;
  long secLeft = (motorIntervalMs > timeSinceLastTurn) ? (motorIntervalMs - timeSinceLastTurn) / 1000 : 0;
  long uploadAgoSec = (lastUploadTime > 0) ? (millis() - lastUploadTime) / 1000 : -1;

  String html = F("<!DOCTYPE html><html lang='id'><head><meta charset='utf-8'>"
    "<meta name='viewport' content='width=device-width,initial-scale=1'>"
    "<title>ESP32-CAM Inkubator</title>"
    "<style>"
    ":root{--bg:#0f172a;--card:#1e293b;--text:#f8fafc;--text-dim:#94a3b8;--primary:#38bdf8;--accent:#22c55e;--danger:#ef4444;--border:#334155;}"
    "*{box-sizing:border-box;margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}"
    "body{background:var(--bg);color:var(--text);padding:16px;max-width:800px;margin:0 auto;line-height:1.5;}"
    "header{padding-bottom:16px;border-bottom:1px solid var(--border);margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}"
    "h1{font-size:1.4rem;color:var(--primary);font-weight:700;}"
    ".badge{background:var(--border);padding:4px 10px;border-radius:99px;font-size:0.8rem;color:var(--text-dim);}"
    ".grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:20px;}"
    ".card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;}"
    ".card h3{font-size:0.85rem;text-transform:uppercase;color:var(--text-dim);margin-bottom:8px;letter-spacing:0.05em;}"
    ".metric{font-size:1.8rem;font-weight:700;color:var(--text);}"
    ".sub{font-size:0.8rem;color:var(--text-dim);margin-top:4px;}"
    ".btn-group{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;}"
    ".btn{background:var(--card);border:1px solid var(--border);color:var(--text);padding:10px 18px;border-radius:8px;font-size:0.9rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:0.2s;}"
    ".btn:hover{background:var(--border);border-color:var(--primary);}"
    ".btn-primary{background:#0284c7;border-color:#0284c7;color:#fff;}"
    ".btn-primary:hover{background:#0369a1;}"
    ".btn-danger{background:#dc2626;border-color:#dc2626;color:#fff;}"
    ".preview-box{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center;}"
    ".preview-img{max-width:100%;height:auto;border-radius:6px;background:#000;margin-top:12px;border:1px solid var(--border);min-height:180px;display:block;margin-left:auto;margin-right:auto;}"
    ".status-on{color:var(--accent);font-weight:700;}.status-off{color:var(--text-dim);}"
    "</style></head><body>"
    "<header>"
    "<div><h1>ESP32-CAM Incubator</h1><p class='sub'>Sistem Kontrol & Monitoring Penetas Telur</p></div>"
    "<div><span class='badge'>IP: ");
  html += WiFi.localIP().toString();
  html += F("</span> <span class='badge'>RSSI: ");
  html += String(WiFi.RSSI());
  html += F(" dBm</span></div></header>"
    "<div class='grid'>"
    "<div class='card'><h3>Suhu Lingkungan</h3><div class='metric'>");
  html += String(currentTemp, 1);
  html += F("&deg;C</div><div class='sub'>Setpoint: ");
  html += String(tempSetpointMin, 1);
  html += F(" - ");
  html += String(tempSetpointMax, 1);
  html += F("&deg;C</div></div>"
    "<div class='card'><h3>Kelembaban</h3><div class='metric'>");
  html += String((int)currentHum);
  html += F("%</div><div class='sub'>Sensor DHT22 Real-time</div></div>"
    "<div class='card'><h3>Status Aktuator</h3>"
    "<div>Pemanas: <span class='");
  html += heaterState ? F("status-on'>MENYALA (ON)") : F("status-off'>MATI (OFF)");
  html += F("</span></div><div>Kipas: <span class='");
  html += fanState ? F("status-on'>MENYALA (ON)") : F("status-off'>STANDBY (OFF)");
  html += F("</span></div><div>Dinamo: <span class='");
  html += isMotorRunning ? F("status-on'>BERPUTAR") : F("status-off'>STANDBY");
  html += F("</span></div></div>"
    "<div class='card'><h3>Cloud Auto-Upload</h3><div class='metric'>");
  html += (lastUploadCode > 0) ? ("HTTP " + String(lastUploadCode)) : "Belum Ada";
  html += F("</div><div class='sub'>Interval: 20s | ");
  if (uploadAgoSec >= 0) {
    html += String(uploadAgoSec) + "s lalu (" + String((unsigned)(lastUploadBytes / 1024)) + " KB)";
  } else {
    html += F("Menunggu upload");
  }
  html += F("</div></div></div>"
    "<div class='btn-group'>"
    "<a class='btn btn-primary' href='/capture' target='_blank'>Ambil Foto Snapshot (/capture)</a>"
    "<button class='btn' onclick='toggleLed()'>Toggle LED Flash (/led)</button>"
    "<button class='btn' onclick='turnMotor()'>Putar Motor Manual (/turn)</button>"
    "<a class='btn' href='/status' target='_blank'>JSON Status (/status)</a>"
    "<button class='btn' onclick='location.reload()'>Refresh Dashboard</button>"
    "</div>"
    "<div class='preview-box'>"
    "<h3>Live Snapshot Preview</h3>"
    "<p class='sub'>Klik tombol di bawah untuk memperbarui gambar kamera di atap inkubator</p>"
    "<div style='margin-top:10px;'><button class='btn btn-primary' onclick='refreshImage()'>Perbarui Snapshot</button></div>"
    "<img id='cam' class='preview-img' src='/capture' alt='Preview Kamera'>"
    "</div>"
    "<script>"
    "function refreshImage(){document.getElementById('cam').src='/capture?t='+new Date().getTime();}"
    "function toggleLed(){fetch('/led').then(r=>r.json()).then(d=>alert('Status LED Flash: '+(d.led?'MENYALA':'MATI')));}"
    "function turnMotor(){if(confirm('Picu putaran motor pembalik telur sekarang?')){fetch('/turn').then(r=>r.text()).then(t=>alert(t));}}"
    "</script></body></html>");

  server.send(200, "text/html", html);
}

// Endpoint: GET /capture (Mengambil snapshot JPEG tunggal)
void handleCapture() {
  if (xSemaphoreTake(cameraMutex, pdMS_TO_TICKS(3500)) != pdTRUE) {
    server.send(503, "text/plain", "Kamera sibuk: proses upload latar belakang sedang berlangsung.");
    return;
  }

  bool ledActive = false;
  if (flashLedAuto && !manualLedState) {
    setFlashLed(true);
    ledActive = true;
    delay(100);
  }

  if (!camStart()) {
    if (ledActive) setFlashLed(false);
    xSemaphoreGive(cameraMutex);
    server.send(500, "text/plain", "Gagal inisialisasi modul kamera!");
    return;
  }

  // Buang frame lama
  camera_fb_t *dummy = esp_camera_fb_get();
  if (dummy) esp_camera_fb_return(dummy);

  camera_fb_t *fb = esp_camera_fb_get();

  if (ledActive && !manualLedState) {
    setFlashLed(false);
  }

  if (!fb || !jpegValid(fb)) {
    if (fb) esp_camera_fb_return(fb);
    camStop();
    xSemaphoreGive(cameraMutex);
    server.send(500, "text/plain", "Gagal mengambil frame valid dari sensor!");
    return;
  }

  server.sendHeader("Content-Disposition", "inline; filename=incubator_snapshot.jpg");
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.sendHeader("Cache-Control", "no-cache, no-store, must-revalidate");
  server.setContentLength(fb->len);
  server.send(200, "image/jpeg", "");

  WiFiClient client = server.client();
  client.write(fb->buf, fb->len);

  esp_camera_fb_return(fb);
  camStop(); // Matikan kamera untuk menghemat daya dan mendinginkan sensor
  xSemaphoreGive(cameraMutex);
}

// Endpoint: GET /led (Kontrol Lampu Flash AI-Thinker)
void handleLed() {
  if (server.hasArg("state")) {
    manualLedState = (server.arg("state").toInt() > 0);
  } else if (server.hasArg("auto")) {
    flashLedAuto = (server.arg("auto").toInt() > 0);
  } else {
    manualLedState = !manualLedState; // Toggle
  }

  setFlashLed(manualLedState);

  String json = "{";
  json += "\"status\":\"ok\",";
  json += "\"led\":" + String(manualLedState ? "true" : "false") + ",";
  json += "\"auto_flash\":" + String(flashLedAuto ? "true" : "false");
  json += "}";

  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "application/json", json);
}

// Endpoint: GET /status (JSON Status Lengkap Sensor & Aktuator)
void handleStatus() {
  unsigned long timeSinceLastTurn = millis() - lastMotorTurn;
  long nextTurnInSec = (motorIntervalMs > timeSinceLastTurn) ? (motorIntervalMs - timeSinceLastTurn) / 1000 : 0;
  long lastUploadAgo = (lastUploadTime > 0) ? (millis() - lastUploadTime) / 1000 : -1;

  String json = "{";
  json += "\"temperature\":" + String(currentTemp, 2) + ",";
  json += "\"humidity\":" + String(currentHum, 1) + ",";
  json += "\"heater\":" + String(heaterState ? "true" : "false") + ",";
  json += "\"fan\":" + String(fanState ? "true" : "false") + ",";
  json += "\"motor_running\":" + String(isMotorRunning ? "true" : "false") + ",";
  json += "\"next_turn_sec\":" + String(nextTurnInSec) + ",";
  json += "\"led_manual\":" + String(manualLedState ? "true" : "false") + ",";
  json += "\"led_auto\":" + String(flashLedAuto ? "true" : "false") + ",";
  json += "\"auto_upload_interval_sec\":" + String(AUTO_UPLOAD_INTERVAL / 1000) + ",";
  json += "\"last_upload_code\":" + String(lastUploadCode) + ",";
  json += "\"last_upload_ago_sec\":" + String(lastUploadAgo) + ",";
  json += "\"rssi\":" + String(WiFi.RSSI()) + ",";
  json += "\"ip\":\"" + WiFi.localIP().toString() + "\"";
  json += "}";

  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "application/json", json);
}

// Endpoint: GET /turn (Pemicu Motor Pembalik Telur Manual)
void handleManualTurn() {
  triggerMotorTurn();
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "text/plain", "Motor pembalik telur aktif!");
}

// ─── 9. UPDATE TAMPILAN LCD 16x4 I2C ───────────────────────────────────────────────
void updateLcd() {
  if (!lcdAvailable) return;

  // Baris 0: Suhu & Kelembaban
  lcd.setCursor(0, 0);
  lcd.print("T:");
  if (isnan(currentTemp)) lcd.print("ERR  ");
  else {
    lcd.print(currentTemp, 1);
    lcd.print((char)223); // Karakter derajat °
    lcd.print("C ");
  }

  lcd.print("H:");
  if (isnan(currentHum)) lcd.print("ERR ");
  else {
    lcd.print((int)currentHum);
    lcd.print("%  ");
  }

  // Baris 1: Status Heater & Kipas
  lcd.setCursor(0, 1);
  lcd.print("Heat:");
  lcd.print(heaterState ? "ON " : "OFF");
  lcd.print(" Fan:");
  lcd.print(fanState ? "ON " : "OFF");

  // Baris 2: Status Dinamo Pembalik Telur
  lcd.setCursor(0, 2);
  if (isMotorRunning) {
    lcd.print("Motor: ROTATING...  ");
  } else {
    unsigned long timePassed = millis() - lastMotorTurn;
    long secLeft = (motorIntervalMs > timePassed) ? (motorIntervalMs - timePassed) / 1000 : 0;
    int m = secLeft / 60;
    lcd.print("Next Turn: ");
    if (m >= 60) {
      lcd.print(m / 60);
      lcd.print("j ");
      lcd.print(m % 60);
      lcd.print("m  ");
    } else {
      lcd.print(m);
      lcd.print("m    ");
    }
  }

  // Baris 3: IP Address & Status Upload
  lcd.setCursor(0, 3);
  if (lastUploadCode > 0) {
    lcd.print("Up:");
    lcd.print(lastUploadCode);
    lcd.print(" IP:");
    lcd.print(WiFi.localIP().toString().substring(WiFi.localIP().toString().lastIndexOf('.') + 1));
    lcd.print("     ");
  } else {
    lcd.print("IP:");
    lcd.print(WiFi.localIP().toString());
  }
}

// ─── 10. SETUP & INITIALIZATION ─────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(400);
  Serial.println("\n=======================================================");
  Serial.println("  SISTEM INKUBATOR PENETAS TELUR ESP32-CAM SIAP BOOT  ");
  Serial.println("=======================================================");

  // Inisialisasi Mutex Kamera
  cameraMutex = xSemaphoreCreateMutex();

  // Konfigurasi Pin Relay & Flash LED
  pinMode(PIN_RELAY_HEATER, OUTPUT);
  pinMode(PIN_RELAY_MOTOR, OUTPUT);
  pinMode(PIN_RELAY_FAN, OUTPUT);
  pinMode(PIN_FLASH_LED, OUTPUT);

  // Default: Semua Relay & Flash LED Mati
  digitalWrite(PIN_RELAY_HEATER, RELAY_OFF);
  digitalWrite(PIN_RELAY_MOTOR, RELAY_OFF);
  digitalWrite(PIN_RELAY_FAN, RELAY_OFF);
  setFlashLed(false);

  // Inisialisasi Sensor DHT22
  dht.begin();

  // Inisialisasi I2C & LCD 16x4 dengan deteksi keberadaan hardware
  Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);
  Wire.setTimeOut(50);
  Wire.beginTransmission(0x27);
  if (Wire.endTransmission() == 0) {
    lcdAvailable = true;
    lcd.init();
    lcd.backlight();
    lcd.setCursor(0, 0);
    lcd.print("SISTEM INKUBATOR");
    lcd.setCursor(0, 1);
    lcd.print("Inisialisasi...");
  } else {
    Wire.beginTransmission(0x3F);
    if (Wire.endTransmission() == 0) {
      lcd = LiquidCrystal_I2C(0x3F, 16, 4);
      lcdAvailable = true;
      lcd.init();
      lcd.backlight();
      lcd.setCursor(0, 0);
      lcd.print("SISTEM INKUBATOR");
      lcd.setCursor(0, 1);
      lcd.print("Inisialisasi...");
    } else {
      Serial.println("[LCD] I2C LCD tidak terdeteksi, beroperasi tanpa LCD.");
    }
  }

  // Uji Modul Kamera pada saat boot
  Serial.println("[CAM] Memeriksa sensor kamera & PSRAM...");
  if (initCamera()) {
    Serial.println("[CAM] Inisialisasi awal kamera BERHASIL.");
    if (lcdAvailable) {
      lcd.setCursor(0, 2);
      lcd.print("Kamera: OK");
    }
    camStop(); // Matikan sensor agar dingin dan hemat daya sampai dibutuhkan
  } else {
    Serial.println("[CAM] Inisialisasi awal kamera GAGAL.");
    if (lcdAvailable) {
      lcd.setCursor(0, 2);
      lcd.print("Kamera: GAGAL!");
    }
  }

  // Koneksi Wi-Fi
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false); // Matikan sleep radio untuk stabilitas komunikasi
  WiFi.setAutoReconnect(true);
  WiFi.setTxPower(WIFI_POWER_19_5dBm);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  if (lcdAvailable) {
    lcd.setCursor(0, 3);
    lcd.print("WiFi Hubungkan..");
  }

  Serial.printf("[WIFI] Menghubungkan ke SSID: %s ", WIFI_SSID);
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 30) {
    delay(400);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("\n[WIFI] Terhubung! IP: %s | RSSI: %d dBm\n",
                  WiFi.localIP().toString().c_str(), WiFi.RSSI());
    if (lcdAvailable) {
      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("WiFi Terhubung!");
      lcd.setCursor(0, 1);
      lcd.print(WiFi.localIP().toString());
      delay(1200);
    }
  } else {
    Serial.println("\n[WIFI] Gagal terhubung ke AP! Memulai operasi offline.");
    if (lcdAvailable) {
      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("WiFi Timeout!");
      delay(1000);
    }
  }

  // Daftarkan Endpoint Web Server Lokal
  server.on("/", HTTP_GET, handleRoot);
  server.on("/capture", HTTP_GET, handleCapture);
  server.on("/led", HTTP_GET, handleLed);
  server.on("/status", HTTP_GET, handleStatus);
  server.on("/turn", HTTP_GET, handleManualTurn);
  server.begin();
  Serial.println("[HTTP] Web Server lokal siap melayani di port 80");

  // Inisialisasi FreeRTOS Background Task untuk Upload Otomatis
  // Stack 10240 byte mencukupi untuk handshake mbedTLS WiFiClientSecure
  xTaskCreatePinnedToCore(
    uploadWorkerTask,
    "uploadTask",
    10240,
    NULL,
    1,
    &uploadTaskHandle,
    0
  );

  lastMotorTurn = millis();
  lastUpload    = millis();
  Serial.println("[SYSTEM] Inisialisasi lengkap. Memulai loop kontrol inkubator.\n");
}

// ─── 11. MAIN LOOP (NON-BLOCKING) ───────────────────────────────────────────────────
unsigned long lastSensorRead = 0;
unsigned long lastLcdUpdate  = 0;

void loop() {
  // 1. Layani permintaan HTTP dari browser / dashboard secara non-blocking
  server.handleClient();

  unsigned long now = millis();

  // 2. Baca Sensor DHT22 & Update Termostat setiap 2 detik
  if (now - lastSensorRead >= 2000) {
    lastSensorRead = now;
    float t = dht.readTemperature();
    float h = dht.readHumidity();

    if (!isnan(t) && !isnan(h)) {
      currentTemp = t;
      currentHum  = h;
    }
    updateThermostat();
  }

  // 3. Update Display LCD 16x4 setiap 1 detik
  if (now - lastLcdUpdate >= 1000) {
    lastLcdUpdate = now;
    updateLcd();
  }

  // 4. Trigger Background Upload otomatis setiap 20 detik (Non-Blocking)
  if (now - lastUpload >= AUTO_UPLOAD_INTERVAL) {
    lastUpload = now;
    if (!uploadInProgress && uploadTaskHandle != NULL) {
      Serial.println("[AUTO] Memicu background upload ke cloud...");
      xTaskNotifyGive(uploadTaskHandle);
    }
  }
}
