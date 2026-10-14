# PRD — Virtual Chip Table: Transfer Chips Antar Pengguna

**Versi:** 1.2  
**Status:** Ready for technical design  
**Target platform:** Laravel Web Application  
**Prioritas:** High  
**Jenis chip:** Virtual, non-monetary

## 1. Tujuan Fitur

Fitur Transfer Chips memungkinkan pengguna mengirim chip virtual kepada pengguna lain yang memiliki akun Virtual Chip Table.

Transfer menggunakan saldo chip pada akun, bukan chip yang sedang berada di meja permainan. Transaksi dilakukan secara aman di server Laravel dan dicatat dalam database.

Fitur harus terintegrasi dengan teknologi yang sudah tersedia di proyek, yaitu Laravel, Blade, Livewire, Flux, dan Tailwind CSS. Tidak diperlukan migrasi frontend ke Next.js atau integrasi Supabase.

## 2. Kondisi Awal Proyek

Berdasarkan hasil audit repository:
- Laravel 13 dan PHP 8.4 sudah digunakan.
- Laravel Fortify menyediakan autentikasi session.
- Antarmuka menggunakan Blade, Livewire 4, Flux, dan Tailwind CSS 4.
- Model `User` dan sistem profil dasar sudah tersedia.
- Saldo chip, wallet, transfer, ledger transaksi, serta broadcasting belum tersedia.
- Database default pengembangan saat ini SQLite.

Implementasi harus memanfaatkan autentikasi serta struktur UI yang sudah ada. Jangan membuat autentikasi baru atau memindahkan frontend ke framework lain.

## 3. Ruang Lingkup

### Termasuk MVP

1. Wallet untuk saldo chip setiap pengguna.
2. Pemberian saldo awal otomatis bagi pengguna baru.
3. Identitas pengguna yang dapat dicari untuk transfer.
4. Pengiriman chip antarpengguna.
5. Konfirmasi transfer.
6. Transaksi database yang atomik.
7. Idempotensi untuk mencegah transfer ganda.
8. Riwayat transaksi.
9. Pembaruan saldo real-time menggunakan Laravel Broadcasting.
10. Pengujian keamanan, saldo, dan transaksi bersamaan.

### Tidak termasuk MVP

- Pembelian atau penarikan chip menggunakan uang.
- Penukaran chip menjadi uang.
- Transfer chip langsung dari saldo meja atau pot.
- Transfer anonim.
- Transfer yang membutuhkan persetujuan penerima.
- Biaya transfer.
- Pembatalan sepihak atas transfer yang sudah berhasil.

## 4. Peran Pengguna

### Pengirim

Pengguna yang sudah login dan terverifikasi dapat:
- Mencari akun penerima.
- Memasukkan jumlah chip.
- Melihat ringkasan transfer.
- Mengonfirmasi atau membatalkan sebelum pengiriman.
- Melihat status serta riwayat transfer.

### Penerima

Pengguna yang memenuhi persyaratan akun dapat:
- Menerima chip secara otomatis setelah transfer berhasil.
- Melihat saldo terbaru.
- Melihat informasi transfer masuk.
- Memeriksa riwayat chip yang diterima.

Penerima tidak perlu sedang online agar transfer berhasil.

## 5. Aturan Bisnis

1. Saldo awal yang ditetapkan untuk MVP adalah 10.000 chips per akun baru.
2. Saldo awal diberikan satu kali dan tidak boleh berulang akibat retry atau registrasi yang diproses ulang.
3. Transfer hanya menggunakan saldo wallet akun.
4. Jumlah transfer minimum adalah 1 chip.
5. Maksimum transfer adalah saldo akun pengirim yang tersedia.
6. Transfer tidak dikenai biaya.
7. Pengirim dan penerima harus merupakan dua pengguna berbeda.
8. Pengguna tidak boleh memiliki saldo negatif.
9. Transfer harus berhasil seluruhnya atau gagal seluruhnya.
10. Saldo kedua pengguna dan catatan transaksi diperbarui dalam satu transaksi database.
11. Transfer yang berhasil tidak boleh diproses ulang menggunakan operation ID yang sama.
12. Pengguna tidak dapat mengubah saldo melalui frontend atau permintaan Livewire yang dimanipulasi.
13. Total chip gabungan sebelum dan sesudah transfer harus tetap sama.
14. Transaksi yang berhasil tidak dapat diedit atau dihapus melalui fungsi aplikasi biasa.
15. Koreksi administratif harus dibuat sebagai transaksi kompensasi baru dengan alasan yang tercatat.

## 6. Identitas Penerima

Saat ini model `User` memiliki nama dan email, tetapi audit belum menemukan kolom username unik.

Sebelum fitur transfer dibuat, tambahkan identitas publik berupa `username` yang:
- Unik setelah normalisasi huruf besar dan kecil.
- Divalidasi saat pendaftaran atau pengaturan profil.
- Tidak dapat digunakan untuk menyamar sebagai pengguna lain.
- Digunakan untuk pencarian dan identifikasi penerima.
- Tidak mengungkapkan email atau informasi sensitif pengguna lain.

Sistem harus memastikan identitas penerima benar sebelum transaksi dikonfirmasi.

## 7. Rancangan Database

### 7.1 Tabel `wallets`

Menyimpan saldo chip akun.

| Kolom | Ketentuan |
|---|---|
| `id` | Primary key sesuai konvensi proyek |
| `user_id` | Foreign key ke `users`, wajib unik |
| `balance` | `UNSIGNED BIGINT`, default 0 |
| `created_at` | Waktu pembuatan |
| `updated_at` | Waktu perubahan saldo |

Satu pengguna hanya boleh memiliki satu wallet akun.

Saldo harus selalu diperbarui melalui business logic yang tepercaya.

### 7.2 Tabel `chip_transactions`

Mencatat pergerakan chip.

| Kolom | Ketentuan |
|---|---|
| `id` | Primary key |
| `from_wallet_id` | Foreign key ke wallet pengirim; nullable hanya untuk penerbitan chip awal |
| `to_wallet_id` | Foreign key ke wallet penerima |
| `type` | Jenis transaksi |
| `amount` | `UNSIGNED BIGINT`, lebih besar dari 0 |
| `idempotency_key` | UUID unik untuk mencegah transaksi ganda |
| `metadata` | JSON nullable untuk informasi tambahan |
| `created_at` | Waktu transaksi |

Jenis transaksi minimum:
- `initial_grant`: pemberian chip awal.
- `transfer`: perpindahan chip antarpengguna.

Catatan transfer yang berhasil bersifat immutable melalui aplikasi. Jangan menyediakan fitur edit atau delete untuk riwayat transaksi yang telah selesai.

Untuk menjaga konsistensi, seluruh perubahan saldo harus memiliki record transaksi yang sesuai. Jangan mengizinkan perubahan saldo langsung tanpa pencatatan ledger.

### 7.3 Relasi Model

- `User` memiliki satu `Wallet`.
- `Wallet` dimiliki satu `User`.
- `Wallet` dapat menjadi pengirim atau penerima dalam banyak `ChipTransaction`.
- `ChipTransaction` memiliki relasi ke wallet pengirim dan penerima.
- `ChipTransaction` menyimpan satu operation ID unik.

Gunakan foreign key, indeks, serta constraint database yang relevan.

## 8. Arsitektur Laravel

Pertahankan pola aplikasi yang telah ada.

### Frontend

Gunakan:
- Blade untuk struktur halaman.
- Livewire untuk interaksi form, pencarian pengguna, dan riwayat.
- Flux untuk komponen UI yang konsisten.
- Tailwind CSS untuk styling.

Jangan membuat frontend transfer dengan React, Inertia, atau aplikasi Next.js terpisah.

### Business Logic

Gunakan satu layanan khusus:

`App\Actions\Chips\TransferChips`

Tanggung jawab layanan:
- Memvalidasi penerima.
- Memvalidasi jumlah chip.
- Memastikan pengirim memiliki saldo cukup.
- Menjalankan transaksi database.
- Mencatat ledger.
- Menghasilkan hasil transaksi yang konsisten.
- Menangani permintaan duplikat.
- Memicu event setelah transaksi berhasil dikomit.

Livewire bertanggung jawab terhadap interaksi UI, sedangkan action tersebut menangani logika transfer.

### Validasi dan Otorisasi

Gunakan validasi sisi server dan mekanisme otorisasi yang sesuai dengan pola Laravel. Aksi transfer harus selalu menentukan pengirim dari pengguna yang sedang terautentikasi.

Jangan menerima `sender_id` dari frontend sebagai identitas yang dipercaya.

Batasi frekuensi permintaan transfer per pengguna melalui rate limiting.

## 9. Proses Transfer Atomik

Alur transaksi yang wajib diterapkan:

1. Pengguna mengisi penerima dan jumlah chip.
2. UI menampilkan ringkasan untuk dikonfirmasi.
3. Server memvalidasi sesi pengguna dan input.
4. Server memvalidasi operation ID.
5. Server memulai transaksi database.
6. Server mengunci wallet pengirim dan penerima dengan mekanisme locking yang didukung database.
7. Penguncian dilakukan dalam urutan konsisten, misalnya berdasarkan ID wallet.
8. Server memvalidasi ulang saldo terbaru.
9. Saldo pengirim dikurangi sebesar jumlah transfer.
10. Saldo penerima ditambahkan sebesar jumlah yang sama.
11. Catatan transfer dibuat dalam ledger.
12. Database melakukan commit.
13. Event real-time dikirim setelah commit berhasil.
14. UI menampilkan hasil transaksi dan saldo terbaru.

Jika terjadi kegagalan sebelum commit, perubahan saldo dan catatan transaksi harus di-rollback bersama-sama.

### Idempotensi

Setiap permintaan transfer harus mempunyai operation ID yang unik.

Jika permintaan yang sama dikirim ulang:
- Sistem tidak boleh mengurangi atau menambah saldo untuk kedua kalinya.
- Sistem harus dapat mengembalikan hasil transaksi terdahulu.
- Jika operation ID yang sama digunakan dengan penerima atau jumlah berbeda, permintaan harus ditolak sebagai konflik.

Constraint unik pada database tetap diperlukan sebagai perlindungan ketika beberapa request diproses bersamaan.

## 10. Real-time Synchronization

Konfigurasikan Laravel Broadcasting menggunakan Laravel Reverb setelah fitur transaksi dasar siap.

Gunakan private channel yang terotorisasi untuk setiap pengguna, misalnya channel wallet milik pengguna. Jangan menyiarkan informasi saldo atau transfer secara publik.

Event real-time harus:
- Dikirim hanya setelah transaksi database berhasil di-commit.
- Memuat informasi minimum yang diperlukan UI.
- Tidak membocorkan email, password, token, atau data privat lain.
- Dapat diterima oleh pengirim dan penerima.
- Tidak mengubah saldo database secara langsung ketika diterima browser.

Ketika aplikasi kembali tersambung setelah offline, UI harus mengambil saldo dan riwayat dari database. Event real-time hanyalah notifikasi tentang perubahan, bukan sumber kebenaran saldo.

## 11. UI dan UX

Tambahkan fitur pada dashboard atau halaman Wallet yang sudah ada.

### A. Wallet

Menampilkan:
- Saldo chip saat ini.
- Tombol Transfer Chips.
- Ringkasan transfer terakhir.
- Link ke riwayat transaksi.

### B. Form Transfer

Kolom:
- Pencarian username penerima.
- Jumlah chip.
- Catatan opsional.
- Tombol Review Transfer.

### C. Konfirmasi

Sebelum mengirim, tampilkan:
- Username penerima.
- Jumlah transfer.
- Saldo awal pengirim.
- Saldo pengirim setelah transfer.
- Biaya transfer: 0 chips.

Pengguna harus mengonfirmasi sebelum transaksi diproses.

### D. Status Transaksi

Tangani keadaan:
- Berhasil.
- Saldo tidak mencukupi.
- Penerima tidak ditemukan.
- Akun penerima tidak memenuhi syarat.
- Transfer duplikat.
- Konflik transaksi.
- Kegagalan koneksi atau server.

UI tidak boleh menampilkan transfer sebagai berhasil hanya karena tombol telah ditekan.

### E. Riwayat

Setiap pengguna dapat melihat:
- Chip terkirim.
- Chip diterima.
- Username pihak lawan transaksi.
- Jumlah chip.
- Waktu transaksi.
- Referensi transaksi.

Pengguna hanya boleh membaca transaksi yang melibatkan wallet miliknya sendiri.

## 12. Persyaratan Keamanan

- Route transfer hanya tersedia bagi pengguna terautentikasi dan terverifikasi.
- Saldo tidak dapat ditulis langsung oleh pengguna.
- Gunakan transaksi database dan locking untuk operasi saldo.
- Terapkan idempotensi dan constraint unik.
- Terapkan foreign key untuk wallet dan transaksi.
- Validasi jumlah sebagai bilangan bulat positif.
- Cegah transfer ke diri sendiri.
- Gunakan private channel untuk event wallet.
- Terapkan rate limit yang dapat dikonfigurasi.
- Catat aktivitas transaksi yang diperlukan untuk audit.
- Jangan mengekspos secret konfigurasi atau kredensial melalui browser.
- Jangan menghapus ledger ketika pengguna dinonaktifkan.

### Database Produksi

SQLite dapat dipertahankan untuk pengembangan lokal jika sesuai dengan kebutuhan proyek. Namun, uji transaksi bersamaan menggunakan database yang akan digunakan di produksi.

PostgreSQL atau MySQL harus dievaluasi untuk deployment multipengguna, khususnya karena mekanisme locking yang digunakan untuk mencegah overspending harus benar-benar didukung dan diuji pada database target.

## 13. Pengujian

Gunakan Pest yang sudah tersedia di proyek dan tambahkan pengujian berikut.

### Pengujian Fungsional
- Transfer berhasil dengan saldo mencukupi.
- Transfer ditolak ketika saldo tidak mencukupi.
- Transfer 0 atau negatif ditolak.
- Transfer ke diri sendiri ditolak.
- Transfer ke pengguna yang tidak ditemukan ditolak.
- Transfer ke akun yang tidak aktif ditolak.
- Saldo pengirim dan penerima berubah sesuai nominal.
- Saldo gabungan kedua pengguna tetap sama.

### Pengujian Keamanan
- Pengguna anonim tidak dapat mentransfer.
- Pengguna tidak dapat menyamarkan identitas pengirim.
- Pengguna tidak dapat mengubah saldo melalui parameter frontend.
- Pengguna tidak dapat membaca riwayat pengguna lain.
- Event private channel tidak dapat diakses oleh pengguna yang tidak berwenang.

### Pengujian Integritas
- Permintaan duplikat tidak menghasilkan transfer kedua.
- Rollback tidak meninggalkan perubahan saldo.
- Dua transfer bersamaan tidak menyebabkan saldo negatif.
- Operation ID yang sama dengan parameter berbeda ditolak.
- Pemberian saldo awal tidak dapat dieksekusi dua kali.
- Event sukses tidak dikirim ketika transaksi gagal.

Pengujian locking harus dijalankan pada database target yang mendukung locking tersebut. Pengujian yang hanya berjalan di SQLite tidak cukup untuk membuktikan konkurensi di produksi.

## 14. Acceptance Criteria

Fitur dianggap selesai ketika:
1. Pengguna yang sudah memenuhi syarat dapat mengirim chip kepada pengguna lain.
2. Saldo awal pengguna dibuat secara otomatis dan hanya sekali.
3. Transfer berhasil mengubah kedua wallet secara atomik.
4. Transfer gagal tidak meninggalkan perubahan saldo parsial.
5. Riwayat transfer tersedia bagi pengirim dan penerima.
6. Saldo kedua pengguna diperbarui secara real-time ketika mereka online.
7. Pengguna offline memperoleh saldo terbaru ketika membuka aplikasi kembali.
8. Transfer duplikat tidak menghasilkan pergerakan chip kedua.
9. Tidak ada jalur untuk mengubah saldo melalui frontend secara langsung.
10. Pengujian integritas dan konkurensi berhasil pada database target.
11. Seluruh perubahan sesuai dengan arsitektur Laravel, Livewire, dan Blade proyek yang ada.

## 15. Urutan Implementasi

### Tahap 1 — Fondasi Saldo
- Buat migrasi `wallets`.
- Buat migrasi `chip_transactions`.
- Tambahkan username unik.
- Buat relasi model.
- Integrasikan pemberian saldo awal ke alur registrasi Fortify.
- Buat proses backfill untuk akun lama yang belum memiliki wallet.

### Tahap 2 — Transfer Service
- Buat `TransferChips`.
- Implementasikan transaksi atomik, locking, dan idempotensi.
- Buat validasi, otorisasi, serta rate limiting.
- Buat pengujian transaksi dan saldo.

### Tahap 3 — UI Transfer
- Buat halaman Wallet.
- Buat pencarian penerima.
- Buat form, konfirmasi, dan status transaksi.
- Buat riwayat transfer.

### Tahap 4 — Real-time
- Konfigurasikan Laravel Broadcasting dan Reverb.
- Buat event transfer dan private channel.
- Perbarui saldo kedua pengguna setelah transaksi berhasil.
- Uji reconnect dan pembacaan ulang state.

### Tahap 5 — Validasi Produksi
- Jalankan pengujian konkurensi.
- Uji otorisasi dan keamanan saldo.
- Uji database yang akan digunakan di produksi.
- Pastikan seluruh transaksi dapat diaudit.

**Kriteria akhir:** fitur transfer baru dinyatakan selesai setelah integritas transaksi terbukti melalui pengujian. Tampilan yang berfungsi saja belum cukup untuk memenuhi kebutuhan fitur ini.
