# Sistem Cerdas Monitoring Penetasan Telur Ayam & Candling Berbasis ESP32-CAM

Proyek ini merupakan sistem monitoring otomatis untuk inkubator penetasan telur ayam dan unit candling menggunakan 2 modul **ESP32-CAM AI-Thinker**.

---

## 📌 Arsitektur Sistem

Sistem terbagi menjadi dua unit independen:
1. **Unit Penetasan (Incubator Overhead):**
   - Kamera ESP32-CAM memantau kondisi rak telur dari atap inkubator.
   - Sensor **DHT22** memantau suhu dan kelembaban inkubator secara berkala.
   - Layar **LCD 16x4 I2C** menampilkan metrik secara real-time.
   - Mengendalikan 3 aktuator tegangan tinggi AC 220V melalui modul relay:
     - **Lampu Pemanas AC 220V** (Termostat otomatis 37.5°C - 38.0°C).
     - **Dinamo Pembalik Telur AC 220V** (Timer otomatis berkala tiap 3 jam).
     - **Kipas Sirkulasi / Buang AC 220V** (Aktif saat overheat > 38.2°C).
2. **Unit Candling (Peneropongan Telur):**
   - Kamera makro ESP32-CAM untuk menangkap citra penetrasi cahaya cangkang telur.
   - Lampu sorot penetrasi (Flash LED internal / Lampu HPL eksternal via relay).
   - Tombol shutter fisik untuk pengambilan citra instan saat telur diletakkan.
   - Buzzer indikator bunyi konfirmasi saat foto selesai diambil.

---

## 🔌 Panduan Wiring Lengkap

### Unit 1: ESP32-CAM Penetasan (Inkubator)

#### 1. Sinyal Tegangan Rendah (DC 5V & 3.3V)
| Pin ESP32-CAM | Terhubung ke | Keterangan |
| :--- | :--- | :--- |
| **5V** | Power Supply DC +5V (min 2A) | Sumber daya utama modul |
| **GND** | Ground Bersama (Common Ground) | Disatukan ke semua GND modul |
| **3.3V** | Pin VCC Sensor DHT22 | Daya referensi sensor |
| **GPIO 16** | Pin DATA Sensor DHT22 | Jalur baca suhu & kelembaban |
| **GPIO 13** | Pin SDA LCD I2C (PCF8574) | Jalur Data I2C |
| **GPIO 14** | Pin SCL LCD I2C (PCF8574) | Jalur Clock I2C |
| **GPIO 15** | Pin IN1 Modul Relay | Kontrol Lampu Pemanas |
| **GPIO 2** | Pin IN2 Modul Relay | Kontrol Dinamo Pembalik Telur |
| **GPIO 12** | Pin IN3 Modul Relay | Kontrol Kipas Sirkulasi AC 220V |

#### 2. Sambungan Beban AC 220V ke Relay (Bebas Resistor)
- **Kabel Fasa / Live (L) PLN 220V** $\rightarrow$ Masuk ke terminal **`COM`** Relay 1, dijumper ke **`COM`** Relay 2, dan dijumper ke **`COM`** Relay 3.
- **Kabel Netral (N) PLN 220V** $\rightarrow$ Langsung menuju ke kaki kedua dari semua beban (Lampu, Dinamo, Kipas).
- **Terminal NO (Normally Open) Relay:**
  - **`NO` Relay 1** $\rightarrow$ Langsung ke Fitting Lampu Pemanas 220V.
  - **`NO` Relay 2** $\rightarrow$ Langsung ke Dinamo Motor Pembalik Telur 220V.
  - **`NO` Relay 3** $\rightarrow$ Langsung ke Kipas Sirkulasi AC 220V.

---

### Unit 2: ESP32-CAM Candling (Peneropongan Telur)

| Pin ESP32-CAM | Terhubung ke | Keterangan |
| :--- | :--- | :--- |
| **5V & GND** | Adaptor 5V DC (min 2A) | Sumber daya |
| **GPIO 4** | Internal Board | Mengendalikan Flash LED putih terang bawaan |
| **GPIO 13** | Push Button (Kaki 1) | Tombol shutter fisik (Kaki 2 ke GND, `INPUT_PULLUP`) |
| **GPIO 2** | Active Buzzer (+) | Indikator bunyi beep foto selesai (Kaki `-` ke GND) |
| **GPIO 16** | Modul Relay (Pin IN) | Trigger lampu sorot candling eksternal (High Power LED) |

---

## 💻 Simulasi Wokwi
Proyek ini menyediakan file sirkuit Wokwi yang siap pakai:
- Sirkuit Penetasan: `esp32_incubator/diagram.json`
- Sirkuit Candling: `esp32_candling/diagram.json`

Untuk melihat dan menguji simulasi secara visual:
1. Buka [https://wokwi.com/projects/new/esp32](https://wokwi.com/projects/new/esp32)
2. Buka tab **diagram.json**, hapus isinya, dan tempelkan isi file `diagram.json` yang ada pada repo ini.
