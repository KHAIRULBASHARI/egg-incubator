/**
 * ====================================================================================
 * SISTEM CERDAS MONITORING PENETASAN TELUR AYAM - UNIT PENETASAN (INCUBATOR)
 * Board: AI Thinker ESP32-CAM
 *
 * Fitur:
 * 1. Kamera Overhead OV2640 untuk snapshot citra telur otomatis (disimpan ke PC / YOLOv8).
 * 2. Sensor DHT22 untuk pemantauan suhu & kelembaban real-time.
 * 3. Kontrol Relay Lampu Pemanas otomatis (Hysteresis Thermostat 37.5°C - 38.0°C).
 * 4. Kontrol Relay Kipas Sirkulasi (Overheat protection > 38.2°C).
 * 5. Kontrol Relay Dinamo Pembalik Telur Asinkron otomatis terjadwal (Timer).
 * 6. Tampilan status LCD 16x4 I2C (Wire SDA=13, SCL=14).
 * 7. HTTP REST Server (/capture, /status, /turn).
 * ====================================================================================
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <WebServer.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include "DHT.h"

// ─── 1. KONFIGURASI WIFI ───────────────────────────────────────────────────────────
const char* WIFI_SSID     = "NAMA_WIFI_ANDA";
const char* WIFI_PASSWORD = "PASSWORD_WIFI_ANDA";

// ─── 2. PIN DEFINITION ─────────────────────────────────────────────────────────────
#define PIN_I2C_SDA     13    // SDA LCD I2C
#define PIN_I2C_SCL     14    // SCL LCD I2C
#define PIN_DHT         16    // Data pin DHT22
#define PIN_RELAY_HEATER 15   // Relay 1: Lampu Pemanas AC 220V
#define PIN_RELAY_MOTOR  2    // Relay 2: Dinamo Pembalik Telur
#define PIN_RELAY_FAN   12    // Relay 3: Kipas Sirkulasi / Buang

#define DHTTYPE         DHT22 // Sensor DHT22 (AM2302)

// Modul Relay Active LOW (LOW = ON, HIGH = OFF)
#define RELAY_ON        LOW
#define RELAY_OFF       HIGH

// ─── 3. PARAMETER KONTROL INKUBATOR ────────────────────────────────────────────────
float tempSetpointMin = 37.5; // Batas bawah lampu ON (°C)
float tempSetpointMax = 38.0; // Batas atas lampu OFF (°C)
float tempFanTrigger  = 38.2; // Batas atas kipas aktif (°C)

// Interval Pembalikan Telur (Default: Tiap 3 jam sekali, motor menyala 12 detik)
unsigned long motorIntervalMs = 3UL * 60UL * 60UL * 1000UL; // 3 jam
unsigned long motorDurationMs = 12000UL;                    // 12 detik rotasi
unsigned long lastMotorTurn   = 0;
bool isMotorRunning           = false;
unsigned long motorStartTime  = 0;

// Variabel Sensor Real-time
float currentTemp = 0.0;
float currentHum  = 0.0;
bool heaterState  = false;
bool fanState     = false;

// Objek Hardware
DHT dht(PIN_DHT, DHTTYPE);
LiquidCrystal_I2C lcd(0x27, 16, 4); // Alamat I2C umum 0x27 atau 0x3F
WebServer server(80);

// ─── 4. PIN KAMERA AI-THINKER OV2640 ───────────────────────────────────────────────
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

// ─── 5. FUNGSI INISIALISASI KAMERA ─────────────────────────────────────────────────
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
    config.frame_size = FRAMESIZE_UXGA; // 1600x1200 untuk ketajaman deteksi telur
    config.jpeg_quality = 10;           // 0-63, angka lebih kecil kualitas lebih tinggi
    config.fb_count = 2;
  } else {
    config.frame_size = FRAMESIZE_SVGA;
    config.jpeg_quality = 12;
    config.fb_count = 1;
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("Kamera gagal diinisialisasi: 0x%x\n", err);
    return false;
  }

  sensor_t * s = esp_camera_sensor_get();
  s->set_vflip(s, 1);       // Balik vertikal jika posisi kamera di atap terbalik
  s->set_brightness(s, 1);  // Naikkan sedikit brightness untuk interior inkubator
  s->set_contrast(s, 1);
  return true;
}

// ─── 6. HANDLER HTTP ENDPOINTS ────────────────────────────────────────────────────

// Endpoint: GET /capture (Mengirim JPEG snapshot langsung ke klien Python/PC)
void handleCapture() {
  camera_fb_t * fb = esp_camera_fb_get();
  if (!fb) {
    server.send(500, "text/plain", "Kamera gagal mengambil frame!");
    return;
  }
  server.sendHeader("Content-Disposition", "inline; filename=egg_snapshot.jpg");
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.setContentLength(fb->len);
  server.send(200, "image/jpeg", "");
  
  WiFiClient client = server.client();
  client.write(fb->buf, fb->len);
  esp_camera_fb_return(fb);
}

// Endpoint: GET /status (JSON status sensor & aktuator)
void handleStatus() {
  unsigned long timeSinceLastTurn = millis() - lastMotorTurn;
  long nextTurnInSec = (motorIntervalMs > timeSinceLastTurn) ? (motorIntervalMs - timeSinceLastTurn) / 1000 : 0;

  String json = "{";
  json += "\"temperature\":" + String(currentTemp, 2) + ",";
  json += "\"humidity\":" + String(currentHum, 1) + ",";
  json += "\"heater\":" + String(heaterState ? "true" : "false") + ",";
  json += "\"fan\":" + String(fanState ? "true" : "false") + ",";
  json += "\"motor_running\":" + String(isMotorRunning ? "true" : "false") + ",";
  json += "\"next_turn_sec\":" + String(nextTurnInSec) + ",";
  json += "\"ip\":\"" + WiFi.localIP().toString() + "\"";
  json += "}";

  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "application/json", json);
}

// Endpoint: POST/GET /turn (Picu motor pembalik telur manual)
void handleManualTurn() {
  triggerMotorTurn();
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "text/plain", "Motor pembalik telur aktif!");
}

// ─── 7. LOGIKA KONTROL AKTUATOR ───────────────────────────────────────────────────

void triggerMotorTurn() {
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

  // Kontrol Kipas Pendingin / Sirkulasi jika suhu terlalu panas
  if (currentTemp >= tempFanTrigger) {
    digitalWrite(PIN_RELAY_FAN, RELAY_ON);
    fanState = true;
  } else {
    digitalWrite(PIN_RELAY_FAN, RELAY_OFF);
    fanState = false;
  }

  // Kontrol Timer Pembalik Telur
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

// ─── 8. UPDATE LCD 16x4 ───────────────────────────────────────────────────────────
void updateLcd() {
  // Baris 0: Suhu & Hum
  lcd.setCursor(0, 0);
  lcd.print("T:");
  if (isnan(currentTemp)) lcd.print("ERR  ");
  else {
    lcd.print(currentTemp, 1);
    lcd.print((char)223); // Simbol derajat °
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

  // Baris 2: Status Dinamo Pembalik
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

  // Baris 3: IP Address ESP32-CAM
  lcd.setCursor(0, 3);
  lcd.print("IP:");
  lcd.print(WiFi.localIP().toString());
}

// ─── SETUP & LOOP ─────────────────────────────────────────────────────────────────
void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println("\n[SYSTEM] Inisialisasi ESP32-CAM Incubator...");

  // Konfigurasi Pin Relay
  pinMode(PIN_RELAY_HEATER, OUTPUT);
  pinMode(PIN_RELAY_MOTOR, OUTPUT);
  pinMode(PIN_RELAY_FAN, OUTPUT);

  // Default Relay OFF
  digitalWrite(PIN_RELAY_HEATER, RELAY_OFF);
  digitalWrite(PIN_RELAY_MOTOR, RELAY_OFF);
  digitalWrite(PIN_RELAY_FAN, RELAY_OFF);

  // Inisialisasi Software I2C untuk LCD 16x4
  Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);
  lcd.init();
  lcd.backlight();
  lcd.setCursor(0, 0);
  lcd.print("SISTEM PENETASAN");
  lcd.setCursor(0, 1);
  lcd.print("Inisialisasi...");

  // Inisialisasi Sensor DHT22
  dht.begin();

  // Inisialisasi Kamera
  if (!initCamera()) {
    lcd.setCursor(0, 2);
    lcd.print("Kamera: GAGAL!");
  } else {
    lcd.setCursor(0, 2);
    lcd.print("Kamera: OK");
  }

  // Sambungkan ke WiFi
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  lcd.setCursor(0, 3);
  lcd.print("WiFi Connecting");

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 25) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[WIFI] Terhubung! IP: " + WiFi.localIP().toString());
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("WiFi Connected!");
    lcd.setCursor(0, 1);
    lcd.print(WiFi.localIP().toString());
    delay(1500);
  } else {
    Serial.println("\n[WIFI] Gagal terhubung ke AP!");
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("WiFi Timeout!");
  }

  // Daftarkan Web Endpoints
  server.on("/capture", HTTP_GET, handleCapture);
  server.on("/status", HTTP_GET, handleStatus);
  server.on("/turn", HTTP_GET, handleManualTurn);
  server.begin();
  Serial.println("[HTTP] Server siap di port 80");

  lastMotorTurn = millis();
}

unsigned long lastSensorRead = 0;
unsigned long lastLcdUpdate = 0;

void loop() {
  server.handleClient();

  unsigned long now = millis();

  // Baca Sensor setiap 2 detik
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

  // Update Tampilan LCD setiap 1 detik
  if (now - lastLcdUpdate >= 1000) {
    lastLcdUpdate = now;
    updateLcd();
  }
}
