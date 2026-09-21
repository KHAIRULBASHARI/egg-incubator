/**
 * ====================================================================================
 * SISTEM CERDAS MONITORING PENETASAN TELUR AYAM - UNIT CANDLING
 * Board: AI Thinker ESP32-CAM
 *
 * Fitur:
 * 1. Kamera OV2640 Macro / Close-up untuk deteksi embrio, fertil, infertil, dan mortalitas.
 * 2. Kontrol Lampu Penetrasi (Flash LED GPIO 4 / Lampu Eksternal GPIO 16).
 * 3. Tombol shutter fisik (GPIO 13) untuk ambil gambar langsung saat telur diletakkan.
 * 4. HTTP Endpoint: /capture (menyalakan lampu -> snapshot -> matikan lampu -> kirim JPEG).
 * ====================================================================================
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <WebServer.h>

// ─── 1. KONFIGURASI WIFI ───────────────────────────────────────────────────────────
const char* WIFI_SSID     = "NAMA_WIFI_ANDA";
const char* WIFI_PASSWORD = "PASSWORD_WIFI_ANDA";

// ─── 2. PIN DEFINISI ───────────────────────────────────────────────────────────────
#define PIN_FLASH_LED      4   // Onboard High-Power Flash LED (PWM/Digital)
#define PIN_EXTERNAL_LIGHT 16  // Opsi Lampu Sorot Candling Luar (Relay / Transistor)
#define PIN_BUTTON_SHUTTER 13  // Tombol Fisik Shutter (INPUT_PULLUP ke GND)

WebServer server(80);

// ─── 3. PIN KAMERA AI-THINKER OV2640 ───────────────────────────────────────────────
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27

#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

bool initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  if (psramFound()) {
    config.frame_size = FRAMESIZE_UXGA; // Resolusi tajam 1600x1200 untuk serat pembuluh darah telur
    config.jpeg_quality = 10;
    config.fb_count = 2;
  } else {
    config.frame_size = FRAMESIZE_SXGA;
    config.jpeg_quality = 12;
    config.fb_count = 1;
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("Kamera Candling gagal diinisialisasi: 0x%x\n", err);
    return false;
  }

  sensor_t * s = esp_camera_sensor_get();
  s->set_brightness(s, 0);
  s->set_contrast(s, 2);      // Naikkan kontras untuk memperjelas siluet kantung udara & pembuluh darah
  s->set_saturation(s, 1);
  return true;
}

void setCandlingLight(bool on) {
  digitalWrite(PIN_FLASH_LED, on ? HIGH : LOW);
  digitalWrite(PIN_EXTERNAL_LIGHT, on ? HIGH : LOW);
}

// ─── 4. HANDLERS HTTP ─────────────────────────────────────────────────────────────

void handleCapture() {
  // Nyalakan lampu candling sesaat sebelum ambil foto
  setCandlingLight(true);
  delay(200); // Beri waktu sensor kamera auto-exposure menyesuaikan intensitas cahaya

  camera_fb_t * fb = esp_camera_fb_get();
  // Segera matikan lampu agar telur tidak panas
  setCandlingLight(false);

  if (!fb) {
    server.send(500, "text/plain", "Kamera gagal mengambil frame!");
    return;
  }

  server.sendHeader("Content-Disposition", "inline; filename=candling_snapshot.jpg");
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.setContentLength(fb->len);
  server.send(200, "image/jpeg", "");
  
  WiFiClient client = server.client();
  client.write(fb->buf, fb->len);
  esp_camera_fb_return(fb);
}

void handleFlash() {
  if (server.hasArg("state")) {
    bool state = server.arg("state") == "1";
    setCandlingLight(state);
    server.send(200, "text/plain", state ? "Lampu ON" : "Lampu OFF");
  } else {
    server.send(400, "text/plain", "Gunakan ?state=1 atau ?state=0");
  }
}

void handleStatus() {
  String json = "{\"device\":\"esp32_candling\",\"ip\":\"" + WiFi.localIP().toString() + "\"}";
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "application/json", json);
}

// ─── 5. SETUP & LOOP ──────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println("\n[SYSTEM] Inisialisasi ESP32-CAM Candling...");

  pinMode(PIN_FLASH_LED, OUTPUT);
  pinMode(PIN_EXTERNAL_LIGHT, OUTPUT);
  pinMode(PIN_BUTTON_SHUTTER, INPUT_PULLUP);

  setCandlingLight(false);

  if (!initCamera()) {
    Serial.println("[ERROR] Kamera tidak terdeteksi!");
  } else {
    Serial.println("[OK] Kamera siap.");
  }

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("[WIFI] Menghubungkan");
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 25) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[WIFI] Terhubung! IP Candling: " + WiFi.localIP().toString());
  } else {
    Serial.println("\n[WIFI] Gagal terhubung ke WiFi!");
  }

  server.on("/capture", HTTP_GET, handleCapture);
  server.on("/flash", HTTP_GET, handleFlash);
  server.on("/status", HTTP_GET, handleStatus);
  server.begin();
  Serial.println("[HTTP] Candling server aktif di port 80");
}

unsigned long lastButtonPress = 0;

void loop() {
  server.handleClient();

  // Cek tombol shutter fisik (jika ditekan)
  if (digitalRead(PIN_BUTTON_SHUTTER) == LOW) {
    if (millis() - lastButtonPress > 1000) { // Debounce 1 detik
      lastButtonPress = millis();
      Serial.println("[BUTTON] Shutter fisik ditekan, memicu foto candling...");
      setCandlingLight(true);
      delay(300);
      setCandlingLight(false);
    }
  }
}
