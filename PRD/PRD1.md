# Task: Tahap 6 — Real-time Activation dan Production Readiness

Bertindak sebagai Senior Laravel Engineer. Lanjutkan pengembangan Virtual Chip Table berdasarkan implementasi yang sudah ada. Jangan membangun ulang fitur transfer chips.

## Kondisi proyek

Stack:
- PHP 8.4.1
- Laravel 13.36
- Laravel Fortify
- Livewire 4
- Blade, Flux, Tailwind CSS 4
- SQLite untuk pengembangan saat ini
- Pest, PHPStan, Pint

Tahap 1–5 telah selesai:
- Wallet dan chip transaction ledger.
- Pemberian saldo awal.
- Transfer chips dengan locking dan idempotensi.
- UI transfer menggunakan Livewire.
- Event ChipBalanceUpdated dan private wallet channel.
- 62/62 test lulus.
- PHPStan 0 error.
- Pint bersih.

**Jangan mengasumsikan Reverb sudah terpasang.** Dependensi Reverb dan Laravel Echo belum dipasang. Kode broadcasting dan polling sudah tersedia.

## Tujuan

1. Mengaktifkan real-time push notification melalui Laravel Reverb.
2. Mempertahankan fallback polling apabila diperlukan.
3. Menyiapkan database PostgreSQL atau MySQL untuk validasi transaksi bersamaan.
4. Memastikan fitur transfer aman dan konsisten pada database target.
5. Menghasilkan laporan kesiapan sebelum deployment produksi.

## Tahap A — Preflight

Sebelum melakukan perubahan:

1. Periksa `git status` dan perubahan lokal yang belum di-commit.
2. Periksa `composer.json`, `composer.lock`, `package.json`, lockfile frontend, serta konfigurasi broadcasting yang sudah dibuat.
3. Baca implementasi berikut:
   - `app/Actions/Chips/TransferChips.php`
   - `app/Events/ChipBalanceUpdated.php`
   - `app/Broadcasting/WalletChannel.php`
   - `routes/channels.php`
   - `bootstrap/app.php`
   - `config/broadcasting.php`
   - `resources/js/app.js`
   - komponen Wallet Livewire.
4. Jangan membaca atau mencetak nilai rahasia dari `.env`.
5. Jangan menghapus perubahan pengguna atau mereset repository.

Gunakan hasil pemeriksaan untuk menentukan metode instalasi yang paling aman.

## Tahap B — Aktivasi Laravel Reverb

1. Pasang dependensi backend Reverb yang sesuai dengan Laravel 13.
2. Pasang Laravel Echo dan client Pusher yang diperlukan menggunakan package manager frontend yang telah digunakan repository.
3. Hindari mencampur package manager atau membuat lockfile baru yang tidak diperlukan.
4. Jangan menimpa konfigurasi broadcasting, channel authorization, atau event yang sudah tersedia.
5. Konfigurasikan koneksi Reverb dan integrasikan Echo ke frontend yang sudah ada.
6. Pertahankan Blade dan Livewire. Jangan memigrasikan frontend ke React, Vue, atau Next.js.
7. Pastikan private channel `wallets.{id}` hanya dapat diakses oleh pemilik wallet.
8. Event yang sudah ada harus tetap dikirim setelah transaksi database berhasil di-commit.
9. Event tidak boleh memuat email, token, kredensial, atau informasi sensitif.
10. Hindari event yang mengubah saldo langsung di browser. Klien tetap membaca saldo resmi dari database melalui mekanisme aplikasi yang tersedia.

Verifikasi:
- Reverb dapat dijalankan secara lokal.
- Laravel Echo berhasil terhubung.
- Pengirim dan penerima menerima notifikasi ketika wallet berubah.
- Pengguna tidak berwenang ditolak ketika mencoba subscribe.
- Kegagalan broadcasting tidak mengubah hasil transaksi yang sudah berhasil disimpan.
- Polling fallback tetap bekerja selama masa transisi.

Dokumentasi resmi yang menjadi acuan:
https://laravel.com/framework/docs/broadcasting

## Tahap C — Queue dan konfigurasi operasional

Periksa konfigurasi queue dan broadcasting yang aktual.

Pastikan:
- Broadcast queue benar-benar diproses oleh worker.
- Instruksi menjalankan Reverb dan queue worker tersedia untuk pengembangan lokal.
- Konfigurasi environment dicatat dalam `.env.example` tanpa secret.
- Deployment production mempunyai strategi pengelolaan proses yang sesuai.
- Dokumentasi menjelaskan kebutuhan proses persisten, koneksi WebSocket melalui HTTPS/WSS, dan pengaturan host serta port.
- Tidak ada ketergantungan pada worker yang berjalan hanya selama request HTTP.

Jangan menganggap broadcasting berfungsi hanya karena event berhasil dibuat atau test menggunakan Event::fake lulus.

## Tahap D — Evaluasi database target

Bandingkan PostgreSQL dan MySQL untuk kebutuhan aplikasi. Gunakan PostgreSQL sebagai target staging awal kecuali ada alasan teknis konkret untuk memilih MySQL.

Jangan mengganti database produksi atau mengubah data pengguna aktual.

Siapkan pengujian menggunakan database staging atau test yang terisolasi dari production. Jangan membaca atau menampilkan kredensial environment.

Verifikasi:
1. Semua migrasi dapat dijalankan pada database target.
2. Seluruh constraint dan foreign key bekerja.
3. Tipe saldo chip tetap mendukung bilangan bulat positif yang dibutuhkan.
4. Transaksi database berhasil melakukan rollback ketika terjadi kegagalan.
5. `lockForUpdate()` benar-benar bekerja pada database target.
6. Unique constraint operation ID mencegah transfer duplikat.
7. Tidak ada saldo negatif atau inkonsistensi ledger selama transfer bersamaan.
8. Suite pengujian dapat dijalankan menggunakan konfigurasi database pengujian yang terpisah.

Jangan menjalankan test yang melakukan reset atau penghapusan tabel terhadap production.

## Tahap E — Pengujian konkurensi

Tambahkan atau perluas pengujian integrasi pada PostgreSQL/MySQL untuk mencakup:

- Dua transfer bersamaan dari wallet yang sama ketika total permintaan melebihi saldo.
- Dua transfer bersamaan yang menggunakan saldo berbeda.
- Dua request dengan operation ID sama.
- Operation ID sama tetapi parameter berbeda.
- Kegagalan transaksi di tengah perpindahan saldo.
- Transfer ketika penerima juga sedang mengirim chip.
- Verifikasi saldo pengirim dan penerima setelah seluruh request selesai.
- Verifikasi total saldo akun dan rekonsiliasi ledger.

Pengujian harus benar-benar menjalankan request atau proses secara bersamaan. Dua pemanggilan service secara berurutan bukan bukti pengujian race condition.

Gunakan database target yang mendukung row-level locking. Jangan menganggap hasil SQLite membuktikan perilaku locking di PostgreSQL/MySQL.

Tambahkan pengujian beban bertahap dan laporkan batas pengujian yang digunakan.

## Tahap F — Pemeriksaan integritas ledger

Audit hubungan antara wallet, pengguna, dan riwayat transaksi.

Khususnya periksa perilaku `nullOnDelete` dan `cascadeOnDelete`:
- Apakah riwayat transaksi tetap dapat diaudit setelah pengguna dihapus?
- Apakah identitas pengirim dan penerima masih dapat ditelusuri?
- Apakah penghapusan akun dapat menyebabkan transaksi kehilangan referensi penting?
- Apakah perlu kebijakan soft delete, account anonymization, atau penyimpanan identitas audit yang terpisah?

Jangan langsung mengubah perilaku penghapusan. Dokumentasikan risiko, dampak privasi, dan rekomendasi terlebih dahulu; implementasikan perubahan hanya jika tetap kompatibel dengan kebutuhan produk.

Periksa juga bahwa pemberian saldo awal dan backfill pengguna lama tidak membuat wallet tanpa grant yang semestinya atau memberikan grant dua kali. Uji urutan backfill lalu transfer serta transfer lalu backfill.

## Tahap G — Verifikasi akhir

Jalankan:
- Seluruh test suite.
- PHPStan.
- Pint.
- Pengujian broadcasting dan otorisasi channel.
- Pengujian integritas serta konkurensi pada database target.
- Build frontend menggunakan package manager yang sesuai.

Catat hasil sebenarnya. Jangan menyatakan tes lulus jika tidak dijalankan.

## Batasan

- Jangan deploy ke production.
- Jangan mengubah kredensial atau membuka secret.
- Jangan menghapus database atau data pengguna aktual.
- Jangan menambahkan fitur poker, side pot, blackjack, atau fitur produk baru dalam tugas ini.
- Jangan menyelesaikan risiko kritis dengan menonaktifkan test atau melemahkan validasi.
- Jangan mengubah saldo chip tanpa transaksi dan catatan ledger.

## Output akhir

Buat laporan:
1. Dependensi baru dan perubahan konfigurasi.
2. Cara menjalankan Reverb, queue worker, dan frontend secara lokal.
3. Hasil pengujian end-to-end real-time.
4. Hasil pengujian pada database target.
5. Hasil pengujian transaksi bersamaan.
6. Risiko ledger atau penghapusan akun yang masih terbuka.
7. Status acceptance criteria.
8. Daftar langkah deployment produksi yang masih diperlukan.

Tugas dinyatakan selesai hanya jika real-time benar-benar berfungsi dan integritas transfer sudah diverifikasi pada database yang mendukung locking.