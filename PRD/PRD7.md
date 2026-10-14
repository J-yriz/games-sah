# Task: Tahap 11 — Staging Deployment & Operational Verification

Bertindak sebagai Senior Laravel Engineer dan Site Reliability Engineer. Lanjutkan Virtual Chip Table setelah Tahap 10 selesai.

## 1. Kondisi Proyek

Stack:
- Laravel 13, PHP 8.4
- Livewire 4, Blade, Flux, Tailwind CSS 4
- PostgreSQL
- Laravel Reverb dan Echo
- Laravel queue dan scheduler
- Pest, PHPStan, Pint

Fitur aplikasi mencakup:
- Wallet, transfer chip, room, seat assignment, dan saldo meja.
- Poker betting engine, side pot, settlement, dan ledger.
- Hand history, action timer, timeout, dan pembatalan hand.
- Broadcasting real-time serta pemulihan state setelah reconnect.
- Perlindungan transaksi dan konkurensi.

Tahap 10 telah menyelesaikan audit keamanan, safe error messages, penonaktifan akun yang mempertahankan ledger, health endpoint, scheduler isolation, serta dokumentasi operasi dan backup.

Hasil terakhir yang dilaporkan:
- PostgreSQL: 206/206 test lulus.
- SQLite: 205 lulus, 1 skip.
- PHPStan: 0 error.
- Pint: bersih.
- Load test terbatas dan worker restart sudah diuji.

Status terakhir adalah **siap staging, belum soft launch**.

## 2. Tujuan

Buktikan bahwa aplikasi dapat berjalan secara operasional pada staging dengan:
- Konfigurasi environment yang aman.
- PostgreSQL staging yang benar.
- Web server, queue worker, scheduler, dan Reverb berjalan persisten.
- HTTPS/WSS dan real-time lintas perangkat.
- Backup serta prosedur pemulihan yang dapat diverifikasi.
- Penanganan kegagalan database dan worker yang aman.
- Pengujian end-to-end di lingkungan yang menyerupai produksi.

## 3. Batasan

- Jangan deploy ke production.
- Jangan mengakses atau mengubah data produksi.
- Jangan mencetak secret atau kredensial.
- Jangan mereset database staging yang berisi data penting.
- Jangan menjalankan migrasi destruktif tanpa backup dan persetujuan operator.
- Jangan menambahkan fitur produk baru.
- Jangan mengklaim deployment atau validasi operasional berhasil apabila belum benar-benar dilakukan.
- Periksa git status dan pertahankan perubahan lokal.

## 4. Preflight Infrastruktur

Baca dan gunakan:
- `docs/operations.md`
- `docs/backup-recovery.md`
- `config/database.php`
- `config/broadcasting.php`
- `config/reverb.php`
- `config/queue.php`
- `routes/console.php`
- `.env.example`
- Konfigurasi reverse proxy dan service deployment jika tersedia.

Identifikasi:
- OS dan metode deployment staging.
- Domain staging.
- PostgreSQL yang digunakan.
- Cache driver dan apakah cache dibagikan antarp proses.
- Queue connection.
- Host dan port Reverb.
- Kebutuhan TLS/WSS.
- Status backup otomatis.
- Empat proses wajib: web, queue worker, scheduler, dan Reverb.

Jika informasi atau akses lingkungan belum tersedia, buat daftar blocker. Jangan menebak domain, secret, atau kredensial.

## 5. Environment dan Secret

Validasi:
- `APP_ENV` sesuai lingkungan staging.
- `APP_DEBUG=false`.
- `APP_KEY` tersedia dan tidak berubah tanpa alasan.
- Kredensial PostgreSQL hanya tersimpan dalam konfigurasi deployment.
- `SESSION_SECURE_COOKIE` dan domain session sesuai HTTPS serta domain aktual.
- Konfigurasi trusted proxy sesuai reverse proxy yang benar-benar digunakan.
- `REVERB_*` dan `VITE_REVERB_*` sesuai endpoint publik dan internal yang semestinya.
- Secret tidak masuk ke frontend bundle atau log.

Jangan mengubah nilai rahasia `.env` secara otomatis dan jangan mencetak nilainya ke laporan.

## 6. Empat Proses Operasional

Pastikan seluruh proses berikut mempunyai konfigurasi start, restart, logging, dan recovery yang jelas.

### Web
Aplikasi Laravel tersedia melalui web server staging yang benar.

### Queue Worker
Worker memproses broadcast dan job lain. Pastikan:
- Worker berjalan persisten.
- Retry dan backoff sesuai konfigurasi.
- Failed jobs dapat dipantau dan di-retry.
- Worker restart tidak menggandakan efek bisnis.

### Scheduler
Scheduler menjalankan timeout poker sesuai jadwal.

Pastikan:
- `schedule:run` berjalan secara periodik atau `schedule:work` dikelola sebagai service.
- `withoutOverlapping` dan `onOneServer` didukung oleh cache lock backend yang benar-benar digunakan.
- Catch-up timeout bekerja setelah scheduler terlambat atau berhenti sementara.
- Kegagalan satu hand tidak menghentikan seluruh sweep timeout.

### Reverb
Pastikan:
- Reverb berjalan persisten.
- Klien menggunakan HTTPS/WSS melalui TLS termination yang benar.
- Port internal tidak dibuka ke publik tanpa kebutuhan.
- Channel authorization aktif.
- Reconnect mengambil state terbaru dari database.

Jika deployment menggunakan systemd atau Supervisor, validasi konfigurasi yang aktual. Jangan membuat unit service dengan placeholder seolah-olah sudah siap digunakan.

## 7. PostgreSQL Staging

Gunakan database staging yang memang dialokasikan untuk pengujian.

Verifikasi:
- Koneksi dari aplikasi.
- Migrasi schema.
- Foreign key dan unique constraint.
- Check constraint saldo dan jumlah transaksi.
- Indeks yang kritis.
- Role database dengan izin minimum yang diperlukan.
- Backup sebelum perubahan schema.
- Prosedur rollback aplikasi ketika deployment gagal.

Jangan gunakan `migrate:fresh` pada database staging yang menyimpan data penting.

Jalankan suite PostgreSQL penuh jika lingkungan staging memungkinkan. Laporkan hasil aktual.

## 8. Validasi Realtime Multi-Klien

Lakukan pengujian dengan setidaknya dua browser atau perangkat independen.

Skenario:
1. Pengguna A dan B bergabung ke room yang sama.
2. A melakukan aksi poker yang valid.
3. B menerima push update dari Reverb tanpa menunggu polling.
4. Saldo, pot, giliran, dan state hand pada kedua klien tetap konsisten.
5. Pengguna yang bukan anggota tidak dapat subscribe.
6. Salah satu browser terputus lalu tersambung kembali.
7. Klien yang reconnect mengambil state resmi terbaru dari database.
8. Reverb restart tidak menyebabkan chip hilang atau transaksi dijalankan ulang.
9. Queue worker restart tidak menyebabkan payout ganda.
10. Event yang terlewat tidak meninggalkan state permanen yang salah.

Pisahkan hasil yang diverifikasi melalui WebSocket aktual dari test yang menggunakan event fake atau mock.

## 9. Database Outage dan Recovery

Lakukan pengujian pada lingkungan staging terisolasi saja.

Gunakan metode yang aman untuk mensimulasikan database tidak tersedia atau koneksi terputus.

Pastikan:
- Request yang memerlukan database gagal dengan penanganan aman.
- Tidak ada pesan SQL atau stack trace sensitif di UI.
- Transaksi yang gagal tidak meninggalkan perubahan saldo parsial.
- Tidak ada payout ganda saat request dicoba kembali.
- State hand dan ledger tetap konsisten.
- Setelah database kembali tersedia, aplikasi dapat pulih.
- Scheduler dan queue dapat mengejar pekerjaan tertunda tanpa menggandakan transaksi.

Jika pengujian memerlukan menghentikan layanan database, pastikan targetnya benar-benar database staging khusus pengujian. Jangan lakukan ini pada production.

## 10. Backup dan Restore Drill

Gunakan prosedur pada `docs/backup-recovery.md`.

1. Buat backup staging.
2. Verifikasi backup selesai dan ukuran serta statusnya masuk akal.
3. Restore ke database terisolasi yang baru.
4. Jalankan pemeriksaan integritas wallet, stack, chip ledger, dan hand history.
5. Jalankan test baca serta rekonsiliasi pada database hasil restore.
6. Catat waktu pemulihan dan hasil aktual.

Jangan menyatakan point-in-time recovery tersedia apabila penyedia database belum mengaktifkannya.

Jangan melakukan restore di atas database staging aktif atau production.

## 11. Monitoring dan Health Checks

Verifikasi:
- `/health` memberikan status yang benar tanpa informasi internal sensitif.
- Queue backlog dan failed jobs dapat dilihat.
- Reverb availability dapat dipantau.
- Scheduler lateness dapat diketahui.
- Timeout yang tertunda dapat diidentifikasi.
- Kegagalan transaksi dan broadcast tercatat.
- Anomali rekonsiliasi dapat terdeteksi.
- Log tidak membocorkan token, session secret, atau kredensial.

Bedakan health check aplikasi dari pemeriksaan kesiapan dependency. Jangan membuat endpoint publik yang mengungkap host, versi database, atau stack internal.

## 12. Load dan Operational Testing

Jalankan pengujian pada staging sesuai sumber daya yang tersedia.

Skenario minimum:
- Lima room berjalan bersamaan.
- Aksi poker dan settlement pada hand berbeda.
- Banyak klien menerima perubahan real-time.
- Queue worker restart ketika ada job tertunda.
- Reverb restart ketika room aktif.
- Scheduler terlambat memproses timeout.
- Beberapa klien reconnect setelah terputus.

Catat:
- Jumlah room dan koneksi yang diuji.
- Jumlah request atau aksi.
- Latency p50 dan p95.
- Jumlah kegagalan.
- Queue backlog dan waktu draining.
- Waktu pemulihan.

Jangan mengklaim kapasitas maksimum aplikasi dari tes terbatas.

## 13. Quality Gates

Jalankan:
- Pest pada PostgreSQL staging atau DB test terisolasi.
- SQLite suite yang kompatibel.
- PHPStan.
- Pint.
- Pemeriksaan migration.
- Realtime multi-klien.
- Worker dan scheduler restart.
- Backup/restore drill.
- Rekonsiliasi saldo dan ledger.

Jika suatu pengujian tidak dapat dilakukan, catat alasan serta langkah yang diperlukan untuk menyelesaikannya.

## 14. Definition of Done

Tahap ini dianggap selesai apabila:
1. Environment staging tervalidasi.
2. Web, queue worker, scheduler, dan Reverb berjalan dengan pengelolaan proses yang jelas.
3. HTTPS/WSS berfungsi.
4. Real-time lintas perangkat terbukti.
5. Reconnect memulihkan state benar.
6. Database outage tidak meninggalkan transaksi parsial.
7. Backup dapat dipulihkan dan diverifikasi.
8. Monitoring minimum tersedia.
9. Test dan rekonsiliasi tetap lulus.
10. Tidak ada deploy production.
11. Semua blocker menuju soft launch terdokumentasi.

## 15. Laporan Akhir

Sampaikan:
- Konfigurasi staging dan file yang berubah.
- Empat proses operasional dan status aktualnya.
- Hasil real-time multi-klien.
- Hasil pengujian outage/recovery.
- Hasil backup/restore.
- Hasil load test beserta batas pengukuran.
- Hasil suite, PHPStan, dan Pint.
- Temuan keamanan yang masih terbuka.
- Checklist operator untuk deployment berikutnya.
- Status: staging tervalidasi, siap soft launch terbatas, atau masih terblokir.

Jangan deploy ke production dan jangan memberikan status siap soft launch tanpa bukti operasional.