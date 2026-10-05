# Panduan Instalasi — Aplikasi Prediksi Harga Emas (tsQRF)

Aplikasi web prediksi harga emas menggunakan **Tree-structured Quantile Regression Forest (tsQRF)**
yang menghasilkan **rentang prediksi (Q10–Q90)** dan **nilai tengah (Q50)** untuk beberapa hari ke depan.

---

## 1. Yang Perlu Disiapkan

| Kebutuhan | Keterangan |
|---|---|
| **XAMPP** | Apache + MySQL (PHP **minimal 8.2**) |
| **Composer** | https://getcomposer.org/Composer-Setup.exe |
| **VS Code** | Opsional, untuk menulis perintah |
| **File CSV data emas** | Opsional — tanpa ini masih bisa pakai data contoh bawaan |

> ✅ Tidak perlu `npm install`. Bootstrap, Chart.js, dan Bootstrap Icons sudah disertakan di `public/vendor`.

---

## 2. Letakkan Project

Salin folder project ke dalam direktori `htdocs` XAMPP:

```
C:\xampp\htdocs\gold
```

Kalau nama foldernya diganti, sesuaikan juga `APP_URL` di file `.env` (lihat langkah 4).

---

## 3. Nyalakan XAMPP

Buka **XAMPP Control Panel** → klik **Start** pada:

- **Apache**
- **MySQL**

Harus muncul status *running* (hijau).

---

## 4. Buka Terminal di VS Code

Di VS Code: **File → Open Folder** → pilih folder `C:\xampp\htdocs\gold`.
Lalu buka terminal: **Terminal → New Terminal** (Ctrl + `).

Semua perintah di bawah dijalankan di terminal tersebut.

---

## 5. Install Dependensi PHP

```bash
composer install
```

Tunggu sampai selesai. (Kalau muncul erreur `composer` bukan perintah, install Composer dulu.)

---

## 6. Buat File `.env`

Di terminal VS Code (PowerShell):

```powershell
copy .env.example .env
```

Kalau MySQL `root` punya password, buka `.env` lalu ubah baris:

```ini
DB_PASSWORD=password_anda
```

---

## 7. Buat Database

Cara 1 — lewat **phpMyAdmin**:
1. Buka http://localhost/phpmyadmin
2. Klik tab **SQL**
3. Jalankan:

```sql
CREATE DATABASE gold_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Cara 2 — lewat terminal:

```bash
mysql -u root -e "CREATE DATABASE gold_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

---

## 8. Buat Tabel & Kunci Aplikasi

```bash
php artisan key:generate
php artisan migrate
```

---

## 9. Buat Akun Admin

```bash
php artisan gold:make-admin admin@example.com admin123 --name="Administrator"
```

| | |
|---|---|
| Email | `admin@example.com` |
| Password | `admin123` |

> Ganti passwordnya kalau ini dipakai di publik.

---

## 10. Masukkan Data Emas (opsional tapi disarankan)

### Cara A — pakai data contoh (otomatis melatih model)

```bash
php artisan gold:seed-sample
```

> Proses melatih 500 pohon butuh **2–5 menit**. Tunggu sampai selesai.

### Cara B — upload CSV sendiri

1. Buka http://localhost/gold/public/login lalu login admin
2. Masuk ke menu **Admin** → klik **Upload Data Emas**
3. Pilih file CSV → **Upload & Latih**

**Format CSV** (pemisah otomatis `,` atau `;`):

```csv
Tanggal,Terakhir,Pembukaan,Tertinggi,Terendah,Vol.,Perubahan%
22/09/2026,"4.376,40","4.382,50","4.414,10","4.327,60","178,13K","-0,17%"
```

> ⚠️ **Penting:** file harus berisi **rentang tanggal lengkap** (mis. 2021 – sekarang).
> File yang hanya berisi 1 bulan akan **menggantikan seluruh data lama**.

---

## 11. Jalankan

Buka di browser:

```
http://localhost/gold/public
```

| Halaman | Isi |
|---|---|
| **Dashboard** | Harga terakhir, jumlah data, tabel data emas |
| **Prediksi** | Rentang Q10–Q90 5 hari ke depan, metrik model, grafik historis (per tahun / per bulan), tabel peramalan |
| **Metodologi** | Penjelasan cara kerja tsQRF |
| **Admin** | Upload / edit / hapus data, latih ulang model (butuh login) |

---

## 12. Daftar Perintah (``cheat sheet``)

| Perintah | Fungsi |
|---|---|
| `php artisan serve` | Jalankan tanpa Apache (opsional, default port 8000) |
| `php artisan gold:seed-sample` | Import data contoh + latih model |
| `php artisan gold:retrain` | Latih ulang model dari data yang ada di database |
| `php artisan gold:make-admin {email} {password}` | Buat / perbarui akun admin |
| `php artisan gold:process {uploadId}` | Proses upload CSV tertentu (jarang dipakai manual) |
| `php artisan migrate` | Jalankan / perbarui tabel database |
| `php artisan migrate:fresh` | Hapus & buat ulang semua tabel (**hapus semua data!**) |
| `php artisan optimize:clear` | Bersihkan cache (mengatasi halaman blank) |
| `php artisan storage:link` | Link storage publik (opsional) |

Opsi latih ulang:

```bash
php artisan gold:retrain --n-estimators=500 --min-samples-leaf=5 --split=0.8 --horizon=7 --random-state=42
```

---

## 13. Kalau Ada Masalah

| Gejala | Penyebab & Solusi |
|---|---|
| **Halaman blank / error 500** | Jalankan `php artisan optimize:clear`, lalu refresh (Ctrl+F5). Lihat log: `storage/logs/laravel.log` |
| `SQLSTATE[HY000] [1045] Access denied` | Password MySQL di `.env` tidak cocok dengan MySQL yang dipakai |
| `Base table or view not found` | Database belum dibuat / `php artisan migrate` belum dijalankan |
| Halaman tidak bisa dibuka (`localhost/gold/public`) | Apache belum Start, atau folder project tidak berada di `htdocs` |
| `php` bukan perintah / bukan versi 8.2 | Pakai PHP milik XAMPP: `C:\xampp\php\php.exe artisan ...` |
| Porto 80 sudah dipakai aplikasi lain | Ubah `Listen 80` di `C:\xampp\apache\conf\httpd.conf` → misal 8080, lalu buka `http://localhost:8080/gold/public` |
| Latih model tidak jalan / macet | Jalankan manual `php artisan gold:process {uploadId}` untuk melihat pesan error |
| Halaman `/prediksi` kosong | Belum ada model — upload CSV atau jalankan `php artisan gold:seed-sample` |

---

## 14. Struktur Folder Penting

```
gold/
├── app/
│   ├── Console/Commands/     # perintah artisan (seed, retrain, make-admin, process)
│   ├── Http/Controllers/     # Dashboard, Prediksi, Admin, Auth
│   ├── Models/               # GoldPrice, Upload, ModelRun, Prediction, User
│   ├── Services/             # CsvParser, TrainingService, GoldPipeline, tsQRF
│   └── Support/              # BackgroundProcess
├── database/migrations/      # struktur tabel
├── public/vendor/            # Bootstrap, Chart.js, Bootstrap Icons (sudah lokal)
├── resources/views/          # layout, dashboard, prediksi, metodologi, admin, auth
├── routes/web.php            # routing
├── storage/app/samples/      # file CSV data contoh
└── .env                      # konfigurasi (dibuat sendiri, jangan dibagikan)
```

> 🔒 **Jangan pernah membagikan file `.env`** — isinya APP_KEY dan kredensial database.
> Bagikan `.env.example` (sudah termasuk di paket ini).