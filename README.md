# PPDB SMP – Sistem Informasi Penerimaan Peserta Didik Baru

Aplikasi sistem informasi PPDB berbasis web untuk mendukung proses pendaftaran, pengelolaan data, verifikasi berkas, pengaturan sekolah dan kuota, seleksi, hingga pengumuman hasil penerimaan peserta didik baru tingkat SMP secara terstruktur dan terintegrasi.

## 🚀 Fitur Utama

### 👨‍🎓 Untuk Siswa
- ✅ Pendaftaran akun dan login siswa
- ✅ Pengisian dan pengelolaan biodata
- ✅ Upload dokumen persyaratan pendaftaran
- ✅ Pemilihan hingga dua sekolah tujuan
- ✅ Pemilihan jalur PPDB
- ✅ Pemantauan status verifikasi berkas
- ✅ Melihat hasil seleksi secara online
- ✅ Cetak kartu peserta dan surat hasil penerimaan

### 🛡️ Untuk Super Admin
- ✅ Dashboard pengelolaan PPDB
- ✅ Manajemen data sekolah
- ✅ Manajemen akun dan hak akses pengguna
- ✅ Pengelolaan operator sekolah
- ✅ Pengaturan jalur dan kuota PPDB
- ✅ Pengaturan jadwal dan periode pendaftaran
- ✅ Pemantauan data pendaftar seluruh sekolah
- ✅ Pengelolaan verifikasi dokumen
- ✅ Proses dan pengelolaan seleksi peserta didik
- ✅ Publikasi hasil seleksi
- ✅ Laporan PDF dan CSV
- ✅ Backup dan restore database
- ✅ Log aktivitas sistem

### 🏫 Untuk Operator Sekolah
- ✅ Dashboard informasi sekolah
- ✅ Pengelolaan data pendaftar sesuai sekolah
- ✅ Verifikasi dokumen calon peserta didik
- ✅ Pemantauan status pendaftaran
- ✅ Pemantauan hasil seleksi sesuai kewenangan
- ✅ Akses laporan sekolah

### 🛣️ Jalur PPDB

| Jalur | Kriteria Seleksi |
|---|---|
| **Zonasi** | Berdasarkan ketentuan zonasi dan jarak tempat tinggal ke sekolah |
| **Prestasi** | Berdasarkan prestasi calon peserta didik |
| **Afirmasi** | Berdasarkan persyaratan jalur afirmasi |
| **Mutasi** | Berdasarkan persyaratan perpindahan tugas orang tua/wali |

Kuota dan ketentuan seleksi dapat dikelola melalui pengaturan sistem.

---

## 🔐 Role-Based Access Control (RBAC)

| Role | Akses | Keterangan |
|---|---|---|
| `super_admin` | Seluruh fitur dan sekolah | Pengelolaan penuh sistem PPDB |
| `operator` | Sekolah yang menjadi kewenangannya | Pengelolaan data sekolah |
| `siswa` | Akun dan data pendaftaran sendiri | Pendaftaran dan pemantauan hasil |

---

## 🔄 Alur Proses PPDB

1. Pendaftaran akun siswa.
2. Pengisian biodata calon peserta didik.
3. Pemilihan sekolah dan jalur pendaftaran.
4. Pengunggahan dokumen persyaratan.
5. Verifikasi berkas oleh operator.
6. Pelaksanaan seleksi berdasarkan jalur.
7. Publikasi hasil seleksi oleh Super Admin.
8. Pengumuman hasil dan pencetakan dokumen oleh siswa.

---

## 📊 Laporan dan Pengelolaan Data

- Rekapitulasi data pendaftar.
- Laporan hasil seleksi.
- Export laporan PDF.
- Export data CSV.
- Pencetakan kartu peserta.
- Pencetakan surat hasil penerimaan.
- Backup dan restore database.

---

## ⚙️ Teknologi yang Digunakan

- PHP
- MySQL / MariaDB
- HTML
- CSS
- JavaScript
- Bootstrap
- Apache Web Server
- XAMPP untuk pengembangan lokal

---

## 📦 Persyaratan dan Instalasi

### 1. Persyaratan Sistem
- PHP dengan ekstensi yang dibutuhkan aplikasi.
- MySQL atau MariaDB.
- Apache Web Server.
- Browser modern.
- XAMPP direkomendasikan untuk penggunaan lokal.

### 2. Persiapkan Proyek

Tempatkan folder `ppdb-smp` di:

```text
C:\xampp\htdocs\
```

### 3. Buat Database

```sql
CREATE DATABASE ppdb_smp;
```

### 4. Import Database

Import file:

```text
database/schema.sql
```

Untuk database yang sudah digunakan, periksa dan jalankan migrasi dalam `database/migrations/` sesuai urutan setelah backup dan pengujian pada database terpisah. Jangan mengimpor ulang schema ke database aktif secara sembarangan.

**Catatan:** `database/clean_for_demo.sql` bersifat destruktif dan hanya untuk database demo yang boleh dikosongkan.

### 5. Konfigurasi Database

Konfigurasi lokal menggunakan:

```text
config/config.local.php
```

Untuk deployment, gunakan variabel environment yang didukung aplikasi. Jangan mengunggah password, konfigurasi lokal, atau kredensial ke repository.

### 6. Akses Aplikasi

```text
Halaman Utama : http://localhost/ppdb-smp/
Administrasi  : http://localhost/ppdb-smp/admin/
Halaman Siswa : http://localhost/ppdb-smp/siswa/
```

Akun Super Admin harus disiapkan melalui prosedur administratif yang terotorisasi. Siswa dapat membuat akun melalui halaman pendaftaran.

---

## 📁 Struktur Folder

```text
ppdb-smp/
├── admin/
│   ├── backup/
│   ├── kuota/
│   ├── laporan/
│   ├── pendaftaran/
│   ├── sekolah/
│   ├── seleksi/
│   ├── users/
│   └── verifikasi/
├── assets/
│   ├── css/
│   ├── js/
│   └── data/
├── config/
├── database/
│   ├── migrations/
│   └── schema.sql
├── includes/
├── siswa/
├── uploads/
├── index.php
├── panduan.php
├── sekolah_detail.php
└── README.md
```

*Struktur di atas merupakan gambaran direktori utama proyek.*

---

## 🔒 Keamanan Sistem

- ✅ CSRF Protection.
- ✅ Password hashing.
- ✅ Prepared statements untuk membantu mencegah SQL Injection.
- ✅ Session management.
- ✅ Validasi input pengguna.
- ✅ Role-Based Access Control.
- ✅ Pembatasan akses data berdasarkan sekolah.
- ✅ Perlindungan dokumen pribadi peserta didik.

---

## 🗄️ Database

Database utama menggunakan MySQL/MariaDB dengan struktur skema pada `database/schema.sql`.

Perubahan struktur database dikelola melalui folder `database/migrations/`. Backup disarankan sebelum melakukan perubahan pada database yang sudah digunakan.

---

## 📝 Changelog

Catat setiap perubahan versi aplikasi, meliputi:

- **Added** – penambahan fitur.
- **Changed** – penyempurnaan fitur.
- **Fixed** – perbaikan kesalahan sistem.

---

## 📄 Lisensi

Lisensi dan ketentuan penggunaan proyek ditetapkan oleh pengembang atau pemilik aplikasi.

---

## 🤝 Kontributor

Dikembangkan untuk mendukung proses pendaftaran, pengelolaan, verifikasi, dan seleksi peserta didik baru tingkat SMP.