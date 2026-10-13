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
- **Pembaruan data otomatis harian**: sistem menarik data emas terbaru dari Yahoo Finance (`GC=F`) secara otomatis tiap hari, menambal tanggal perdagangan yang belum ada, lalu melatih ulang model. Upload CSV manual tetap tersedia.
- **Data bersih**: baris Sabtu/Minggu (bukan hari perdagangan) otomatis dibuang dan baris yang memiliki kolom kosong otomatis ditolak — sehingga tidak ada sel kosong di database.
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

Aturan kebersihan data:

- Baris **Sabtu/Minggu** otomatis dibuang (bukan hari perdagangan).
- Baris dengan **kolom kosong** (termasuk `Vol.` atau `Perubahan%`) otomatis ditolak, sehingga database tidak berisi nilai kosong.

File upload harus berupa `.csv` (maks ±10 MB). Setelah upload, pelatihan berjalan otomatis di background (progress terlihat di dashboard dan panel admin).

## Pembaruan Data Otomatis

Selain upload manual, data emas dapat diperbarui otomatis setiap hari:

```bash
php artisan gold:update              # tarik data terbaru + tambal tanggal kosong + latih ulang
php artisan gold:update --no-retrain # sinkronkan data saja (tanpa latih ulang)
php artisan gold:update --days=90    # atur rentang penyegaran data terbaru
```

Sumber data: **Yahoo Finance** simbol `GC=F` (Gold Futures COMEX) — nilainya identik dengan data historis yang dipakai sistem, sehingga aman untuk menambal tanggal yang belum ada. (Investing.com tidak bisa dipakai otomatis karena dilindungi Cloudflare / HTTP 403.)

Yang dilakukan perintah ini:

1. Menarik data harian terbaru; baris hari Sabtu/Minggu dan baris tanpa volume dilewati.
2. **Menambal tanggal perdagangan yang belum ada** (mis. hari Jumat yang sempat hilang) tanpa menimpa data lama yang sudah benar.
3. Menghapus sisa baris weekend dan baris berkolom kosong.
4. Menghitung ulang kolom `Perubahan%` mengikuti urutan hari perdagangan.
5. Melatih ulang model tsQRF bila ada data baru/berubah (`--no-retrain` untuk melewati).

**Penjadwalan di Windows (XAMPP):** jalankan `auto-update-gold.bat` lewat Task Scheduler, atau daftarkan sekali dengan:

```bat
schtasks /Create /TN "GoldAutoUpdate" /TR "C:\Xampp\htdocs\gold_update\auto-update-gold.bat" /SC DAILY /ST 05:15 /F
```

> ⚠️ **PC + XAMPP harus menyala** pada jam tersebut. Tugas dijadwalkan pada **05:15 WIB** (tepat saat pasar emas COMEX buka, 18:00 ET) agar data hari sebelumnya sudah final. Disarankan mengaktifkan opsi *"StartWhenAvailable"* / *"Run task as soon as possible after a scheduled start is missed"* supaya bila PC sempat mati, tugas dijalankan sesegera mungkin saat PC dinyalakan.

Bila memakai `php artisan schedule:run` (Task Scheduler tiap 1 menit), jadwalnya sudah didefinisikan pada `routes/console.php` (harian **05:15 WIB** — tepat saat pasar emas buka).

## Model & Metodologi (tsQRF)

### Fitur

Untuk setiap baris `t` (semua dinyatakan sebagai **rasio/persen terhadap penutupan hari itu**, agar model tetap valid ketika harga terkini berada di luar rentang data latih — pohon keputusan tidak bisa mengekstrapolasi):

- Pembukaan, Tertinggi, Terendah dibanding penutupan hari `t` (%)
- Vol., Perubahan% pada hari `t`
- `Lag_1, Lag_2, Lag_3`: penutupan 1, 2, dan 3 hari sebelumnya relatif terhadap penutupan hari `t` (%)

### Target

**Return (perubahan %) penutupan periode berikutnya** (`shift -1`). Harga prediksi akhir = penutupan "hari itu" × (1 + return%), sehingga prediksi selalu berpusat pada level harga terkini. Baris terakhir tidak memiliki target sehingga tidak dipakai untuk training.

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
- Fitur dibangun pada ruang rasio: OHLC/Vol diasumsikan mengikuti pola baris observasi terakhir, sedangkan lag/perubahan dihitung dari penutupan terakhir + hasil prediksi hari sebelumnya
- Return hasil prediksi dikonversi ke harga: `penutupan terakhir × (1 + return%)`
- Data testing perlu `horizon` hari buffer di akhir sehingga peramalan dimulai tepat setelah data aktual terakhir

## Metrik Evaluasi

| Metrik    | Arti |
|-----------|------|
| **MAE**   | Rata-rata selisih mutlak antara aktual dan Q50 (median prediksi) |
| **RMSE**  | Akar rata-rata kuadrat error (lebih sensitif ke error besar) |
| **MAPE**  | Persentase error rata-rata terhadap harga aktual |
| **Coverage** | Persentase aktual yang jatuh di dalam rentang Q10–Q90 |
| **Avg Interval Width** | Rata-rata lebar rentang (Q90−Q10) pada data testing |

Contoh hasil pada dataset 1.269 baris (2021–2026):

```
MAE=59.68  RMSE=84.26  MAPE≈1.33%  Coverage=53.75%  Lebar interval≈103
```

## Struktur Penting

```
app/Services/CsvParser.php          # parsing CSV format Indonesia
app/Services/GoldPreprocessor.php   # fitur relatif (rasio %) & target return
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
php artisan gold:update                   # tarik data emas terbaru otomatis (Yahoo Finance)
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