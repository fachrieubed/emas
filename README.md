# Prediksi Harga Emas (tsQRF) — Laravel + PHP

Aplikasi web untuk melihat data harga emas dan memprediksi harga emas sebagai **rentang nilai** (dari X hingga Y) menggunakan metode **Random Forest dengan Quantile Regression (tsQRF)**, sesuai panduan skripsi (`panduanskipsi.pdf`).

> 📘 **Sedang installing di komputer sendiri?** Baca **[PANDUAN-INSTALLASI.md](PANDUAN-INSTALLASI.md)** — berisi langkah demi langkah (XAMPP, Composer,VS Code, database, admin, data contoh) plus daftar perintah dan troubleshoot.

## Fitur

- **Dashboard**: ringkasan singkat (harga terakhir, total data) dan tabel data emas (pencarian + pagination).
- **Prediksi**: kotak prediksi rentang (`Q10 – Q90` + nilai tengah `Q50`), metrik evaluasi model, grafik harga historis & evaluasi testing, grafik peramalan 7 hari ke depan (recursive), dan tabel hasil peramalan.
- **Metodologi**: halaman penjelasan singkat tsQRF (fitur, split 80/20, 500 pohon, kuantil, metrik, peramalan recursive).
- **Prediksi rentang**: hasil akhir berupa `Q10 – Q90` (rentang bawah–atas), bukan satu angka tunggal.
- **Login Admin**: autentikasi khusus pengguna berperan `admin` (navbar → Admin). Halaman Admin hanya bisa diakses setelah login.
- **Panel Admin**: upload file CSV data emas, **edit** data per baris, **hapus** data per baris, dan **hapus semua data** (beserta model & prediksi). Dilengkapi pencarian & pagination.
- **Latih Ulang**: setelah mengedit/menghapus data, tombol *Latih Ulang Model* melatih ulang tsQRF dari data yang ada (tanpa perlu upload ulang).
- **Tanpa dependensi Python/sklearn**: model Random Forest ditulis murni dalam PHP.
- **Tampilan bersih & klasik**: latar terang krem, aksen emas, tipografi sistem yang rapi, dan navigasi breadcrumb antar halaman.

## Kebutuhan

- XAMPP dengan **PHP ≥ 8.2** dan **MariaDB/MySQL**
- Composer (untuk install Laravel dependencies)
- Apache running (dokumen root `htdocs`)

## Setup

```bash
cd C:\Xampp\htdocs
composer create-project laravel/laravel gold
# atau jika project sudah ada:
cd gold
composer install
```

Konfigurasi `.env`:

```env
APP_URL=http://localhost/gold/public
APP_LOCALE=id
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gold_db
DB_USERNAME=root
DB_PASSWORD=
```

Buat database lalu jalankan migrasi dan seed data contoh:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS gold_db"
php artisan migrate
php artisan gold:seed-sample
```

> `gold:seed-sample` mengimpor dataset contoh (1137 baris), melatih model, dan menyimpan hasil peramalan — cukup sekali untuk mendapatkan dashboard yang terisi.

### Akun admin

Hanya pengguna dengan peran `admin` yang bisa login:

```bash
php artisan gold:make-admin admin@example.com admin123 --name="Administrator"
```

Tampilkan daftar admin:

```bash
mysql -u root gold_db -e "SELECT id, name, email, role FROM users"
```

## Akses

Buka di browser:

```
http://localhost/gold/public          # Dashboard (publik)
http://localhost/gold/public/prediksi # Prediksi & grafik (publik)
http://localhost/gold/public/metodologi # Penjelasan tsQRF (publik)
http://localhost/gold/public/login    # Halaman login admin
http://localhost/gold/public/admin    # Panel admin (wajib login)
```

## Format CSV yang diterima

Kolom (dipisahkan `;`), header persis seperti panduan:

```
Tanggal;Terakhir;Pembukaan;Tertinggi;Terendah;Vol.;Perubahan%
```

Contoh baris:

```
05/14/2024;1.883.920;1.886.690;1.890.300;1.882.150;93.82K;0,27%
```

Aturan parsing:

- **Tanggal**: format `m/d/Y` (auto-detect delimiter; bisa `/`, `-`, atau `.`).
- **Angka** (harga): pemisah ribuan `.`, desimal `,` (contoh `1.883.920` = 1883920).
- **Vol.** (volume): akhiran `K` (ribu), `M` (juta), `B` (miliar). Contoh `93.82K` = 93820.
- **Perubahan%**: contoh `0,27%` atau `-1,54%`.

File upload harus berupa `.csv` (maks ±10 MB). Setelah upload, pelatihan berjalan otomatis di background (progress terlihat di dashboard dan panel admin).

## Model & Metodologi (tsQRF)

### Fitur

Untuk setiap baris `t`:

- Pembukaan, Tertinggi, Terendah, Vol., Perubahan% pada hari `t` (dalam juta / satuannya: harga dibagi 1000 agar stabil pada skala latihan)
- `Lag_1, Lag_2, Lag_3`: nilai `Terakhir` dari 1, 2, dan 3 hari sebelumnya

### Target

`Terakhir` pada periode berikutnya (`shift -1`). Baris terakhir tidak memiliki target sehingga tidak dipakai untuk training.

### Split data

- **80/20 sekuensial** (tanpa shuffle), sesuai panduan
- Contoh di panduan: 1137 baris → 909 latih / 228 testing

### Model

- **Random Forest Regressor** (implementasi PHP murni, `app/Services/ML/RandomForest.php`)
- `n_estimators = 500`
- `min_samples_leaf = 5`
- `max_features = "sqrt"`
- `min_samples_split = 2`
- `random_state = 42`

Untuk setiap pohon, diambil residu (error) pada data *out-of-bag* untuk mengestimasi distribusi ketidakpastian. Rentang prediksi dihitung dari distribusi residu pada kuantil `Q10`, `Q50`, `Q90`:

- `Q10` = batas bawah rentang
- `Q50` = nilai tengah (median)
- `Q90` = batas atas rentang

Hasil: prediksi harga adalah **rentang `Q10 – Q90`**.

### Peramalan ke depan (recursive)

- Horizon default **7 hari trading** (akhir pekan dilewati)
- Untuk hari-hari ke depan, fitur OHLC/Vol/Perubahan% diasumsikan sama dengan baris observasi terakhir (sesuai keterbatasan panduan; hanya `Terakhir` hasil ramalan yang dipakai)
- Data testing perlu `horizon` hari buffer di akhir sehingga peramalan dimulai tepat setelah data aktual terakhir

## Metrik Evaluasi

| Metrik    | Arti |
|-----------|------|
| **MAE**   | Rata-rata selisih mutlak antara aktual dan Q50 (median prediksi) |
| **RMSE**  | Akar rata-rata kuadrat error (lebih sensitif ke error besar) |
| **MAPE**  | Persentase error rata-rata terhadap harga aktual |
| **Coverage** | Persentase aktual yang jatuh di dalam rentang Q10–Q90 |
| **Avg Interval Width** | Rata-rata lebar rentang (Q90−Q10) pada data testing |

Contoh hasil pada dataset contoh:

```
MAE=22263.97  RMSE=28336.69  MAPE≈1.40%  Coverage=72.69%
```

## Struktur Penting

```
app/Services/CsvParser.php          # parsing CSV format Indonesia
app/Services/GoldPreprocessor.php   # fitur lag & target
app/Services/GoldPipeline.php       # split, metrik, forecast recursive, kuantil
app/Services/ML/RandomForest.php    # model Random Forest (PHP murni)
app/Services/ML/Quantile.php        # perhitungan kuantil dari residual
app/Services/TrainingService.php    # alur upload/training; termasuk retrain dari DB
app/Console/Commands/ProcessTraining.php  # proses background upload (artisan)
app/Console/Commands/Retrain.php          # latih ulang dari data database
app/Console/Commands/CreateAdmin.php      # buat akun admin
app/Console/Commands/SeedSample.php       # seed dataset contoh
app/Support/BackgroundProcess.php   # spawn proses background (Windows: start /B)
app/Http/Controllers/DashboardController.php
app/Http/Controllers/PrediksiController.php  # halaman Prediksi (grafik, metrik, peramalan)
app/Http/Controllers/AuthController.php    # login/logout admin
app/Http/Controllers/AdminController.php   # upload, edit, hapus, hapus semua, retrain
resources/views/dashboard.blade.php
resources/views/prediksi.blade.php
resources/views/metodologi.blade.php
resources/views/auth/login.blade.php
resources/views/admin/index.blade.php
resources/views/partials/upload-modal.blade.php
resources/views/layouts/app.blade.php
routes/web.php
storage/app/samples/harga_emas_contoh.csv   # dataset contoh (1137 baris)
```

## Perintah Artisan

```bash
php artisan gold:make-admin {email} {password} --name=  # buat/pakai akun admin
php artisan gold:seed-sample              # impor contoh + latih + ramal
php artisan gold:process {uploadId}       # proses upload tertentu di background
php artisan gold:retrain                  # latih ulang tsQRF dari data DB
php artisan migrate --seed                # (opsional) jalankan semua seeder
```

## Endpoint API

| Endpoint | Keterangan |
|----------|------------|
| `GET /` | Dashboard (ringkasan + data emas) |
| `GET /prediksi` | Halaman Prediksi (rentang, metrik, grafik, tabel peramalan) |
| `GET /metodologi` | Halaman Metodologi tsQRF |
| `GET /login` | Halaman login admin |
| `POST /login` | Proses login (hanya role `admin`) |
| `POST /logout` | Logout |
| `GET /admin` | Panel admin (auth) — list + search + pagination |
| `POST /admin/upload` | Upload CSV + parameter model (auth) → proses background |
| `POST /admin/retrain` | Latih ulang dari data DB (auth) → proses background |
| `PUT /admin/data/{id}` | Edit satu data emas (auth) |
| `DELETE /admin/data/{id}` | Hapus satu data emas (auth) |
| `DELETE /admin/data` | Hapus semua data emas + model + prediksi (auth) |
| `GET /training/latest/status` | Status model terbaru (JSON, dipoll dashboard saat retrain) |
| `GET /training/{uploadId}/status` | Status pelatihan upload (JSON, dipoll dashboard) |
| `GET /api/gold?page=&per_page=&search=` | Data harga emas (JSON, paginated) |