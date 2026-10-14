# Task: Tahap 10 — Production Readiness & Operational Hardening

Bertindak sebagai Senior Laravel Engineer, Application Security Engineer, dan Site Reliability Engineer.

Lakukan audit dan hardening operasional terhadap proyek Virtual Chip Table yang sudah menyelesaikan Tahap 1–9E.

## 1. Kondisi Proyek

Stack saat ini:
- PHP 8.4
- Laravel 13
- Laravel Fortify
- Livewire 4, Blade, Flux, Tailwind CSS 4
- PostgreSQL sebagai database validasi transaksi
- Laravel Reverb dan Laravel Echo
- Laravel queue dan scheduler
- Pest, PHPStan, dan Pint

Fitur yang sudah diimplementasikan:
- Registrasi, autentikasi, wallet, dan transfer chip.
- Room, anggota, seat assignment, buy-in, rebuy, dan cash-out.
- Poker state machine, betting, pot, side pot, dan settlement.
- Hand history, action timer, timeout otomatis, dan pembatalan hand.
- Ledger transaksi dan idempotensi.
- Real-time updates melalui private/presence channels.

Hasil pengujian terakhir yang dilaporkan:
- PostgreSQL: 196/196 test lulus.
- SQLite: 195 test lulus dan 1 skip yang khusus PostgreSQL.
- PHPStan: 0 error.
- Pint: bersih.
- Pengujian konkurensi PostgreSQL mencakup aksi, settlement, start hand, seat assignment, timeout versus action, dan cancellation versus settlement.

Verifikasi kondisi aktual repository sebelum mengambil keputusan. Jangan menganggap semua dependensi, variabel environment, proses server, atau deployment sudah terkonfigurasi hanya karena aplikasinya lulus test.

## 2. Batasan

- Jangan deploy ke production.
- Jangan menjalankan migrasi destruktif terhadap database pengguna.
- Jangan membaca atau mencetak kredensial rahasia.
- Jangan menghapus data atau ledger.
- Jangan menurunkan validasi untuk membuat test lulus.
- Jangan menambahkan fitur permainan baru.
- Jangan mengubah aturan poker yang telah teruji kecuali ditemukan bug yang dapat dibuktikan.
- Jangan memasang dependensi yang tidak diperlukan.
- Periksa git status dan pertahankan perubahan lokal.
- Lakukan audit terlebih dahulu, implementasikan hardening yang aman, lalu laporkan bagian yang memerlukan keputusan atau konfigurasi operator.

## 3. Tahap A — Scheduler dan Timeout

Audit konfigurasi scheduler aktual, khususnya command pemrosesan timeout poker.

Pastikan:
- `ProcessPokerTimeouts` terdaftar dengan benar.
- Jadwal dijalankan secara konsisten.
- Deadline yang telah lewat dapat diproses setelah scheduler sempat berhenti.
- Lock per hand dan idempotensi tetap berlaku ketika command dijalankan berulang.
- Job atau command tidak memproses hand yang telah settled atau cancelled.
- Kegagalan pada satu hand tidak menghentikan pemrosesan hand lain yang tidak terkait.
- Log dapat menunjukkan timeout yang berhasil, dilewati, atau gagal tanpa mengungkap data sensitif.

Dokumentasikan pilihan scheduler:
- Cron yang menjalankan `php artisan schedule:run` setiap menit; atau
- Proses `php artisan schedule:work` yang dikelola sebagai service.

Untuk deployment multi-node, evaluasi `onOneServer()` dan `withoutOverlapping()` berdasarkan cache bersama yang benar-benar tersedia. Jangan mengklaim koordinasi lintas-node jika setiap node menggunakan lock cache lokal sendiri.

Tambahkan test atau pemeriksaan integrasi sesuai kebutuhan.

## 4. Tahap B — Queue Worker dan Reverb

Audit konfigurasi queue dan broadcasting yang sudah ada.

### Queue
- Pastikan broadcast events yang diantre dapat diproses.
- Pastikan worker menggunakan timeout, retry, dan backoff yang masuk akal.
- Pastikan failed jobs dapat diperiksa dan di-retry.
- Pastikan deployment dapat melakukan graceful worker restart.
- Pastikan timeout poker tidak bergantung pada job yang hanya hidup dalam memori request.
- Pastikan kegagalan broadcast tidak membatalkan transaksi database yang sudah committed.

### Reverb
- Pastikan Reverb dijalankan sebagai proses persisten.
- Dokumentasikan pengaturan domain, port, TLS/WSS, dan reverse proxy.
- Pastikan port internal tidak diekspos tanpa kebutuhan.
- Pastikan private dan presence channel tetap menggunakan authorization server-side.
- Pastikan aplikasi dapat recover ketika koneksi WebSocket terputus atau Reverb restart.
- Pastikan browser mendapatkan state terbaru dari database setelah reconnect.

Buat konfigurasi service yang sesuai untuk target OS/deployment yang ditemukan. Jangan mengasumsikan penggunaan Supervisor, systemd, atau container jika belum ditentukan oleh proyek.

## 5. Tahap C — Environment dan Deployment Checklist

Audit konfigurasi environment dan dokumentasi deployment.

Pastikan:
- `APP_ENV` dan `APP_DEBUG` disetel dengan benar untuk production.
- Secret aplikasi, database, dan Reverb tidak berada di source code atau frontend bundle.
- Environment variables terdokumentasi melalui `.env.example` tanpa nilai rahasia.
- HTTPS/WSS diwajibkan pada akses produksi.
- Session dan cookie menggunakan pengaturan aman sesuai domain.
- Trusted proxy settings benar jika aplikasi berada di balik reverse proxy.
- Database hanya menerima koneksi dari jaringan yang diperlukan.
- Queue worker dan Reverb dijalankan melalui process manager.
- Scheduler dipastikan aktif.
- Deployment dapat memigrasikan database tanpa meresetnya.
- Cache configuration, route, dan view digunakan sesuai kebutuhan deployment.

Jangan mengubah `.env` secara otomatis. Berikan daftar variabel yang harus disiapkan operator beserta deskripsinya, tanpa menampilkan secret.

## 6. Tahap D — Security Audit

Audit jalur sensitif aplikasi:
- Autentikasi dan verifikasi email.
- Transfer chip.
- Room membership.
- Seat assignment.
- Buy-in dan cash-out.
- PlayerAction.
- StartHand dan AdvanceStreet.
- SettleHand.
- CancelHand.
- ProcessTimeouts.
- Hand history.
- Broadcasting channels.
- Operasi administratif yang sudah ada.

Verifikasi:
- Identitas aktor berasal dari sesi atau konteks autentikasi tepercaya.
- Role host dan player divalidasi pada server.
- ID room, hand, user, dan stack yang dikirim client tidak otomatis dianggap berwenang.
- Pemain nonanggota tidak bisa membaca state privat room atau hand.
- Pemain tidak dapat melakukan aksi atas nama pengguna lain.
- Hand terminal tidak bisa dimutasi melalui jalur normal.
- Idempotency key dan transaction locks tetap melindungi state.
- Rate limiting diterapkan pada operasi sensitif yang rentan disalahgunakan.
- Error response tidak membocorkan SQL, stack trace, secret, atau data pengguna lain.

Jangan menganggap security test yang lulus membuktikan semua aspek keamanan. Catat coverage serta area yang belum diuji.

## 7. Tahap E — Ledger dan Kebijakan Penghapusan Akun

Audit perilaku penghapusan atau penonaktifan akun berdasarkan skema aktual, terutama:
- `wallets`
- `table_stacks`
- `chip_transactions`
- `room_members`
- Snapshot pemain poker
- Hand history dan settlement

Risiko yang perlu ditangani:
- Foreign key `nullOnDelete` dapat menghilangkan referensi langsung dari transaksi.
- Penghapusan wallet dapat mengurangi kemampuan untuk mengaudit saldo historis.
- Riwayat hand perlu tetap dapat dibaca setelah room ditutup.
- Identitas pemain historis harus tetap dapat ditafsirkan secara konsisten.

Rancang kebijakan yang membedakan:
1. Penonaktifan akun.
2. Permintaan penghapusan data pengguna.
3. Retensi catatan transaksi yang diperlukan untuk integritas aplikasi.
4. Anonimisasi identitas apabila diperlukan.

Utamakan soft deletion atau mekanisme setara jika sesuai dengan arsitektur. Namun, jangan menganggap soft delete otomatis menyelesaikan seluruh kebutuhan privasi.

Sampaikan konsekuensi perubahan skema dan kebijakan terlebih dahulu. Implementasikan hanya perubahan yang kompatibel serta dapat diuji. Jangan menghapus atau mengubah ledger historis secara diam-diam.

## 8. Tahap F — Backup dan Disaster Recovery

Siapkan prosedur operasional untuk PostgreSQL.

Dokumentasikan:
- Strategi backup otomatis.
- Retensi backup.
- Point-in-time recovery apabila tersedia pada lingkungan target.
- Prosedur restore ke lingkungan terisolasi.
- Verifikasi integritas setelah restore.
- Penanganan kehilangan koneksi atau kegagalan queue.
- Pemulihan aplikasi ketika Reverb restart.
- Rekonsiliasi saldo wallet, stack, dan ledger setelah pemulihan.

Jika infrastruktur backup belum tersedia, jangan mengklaim backup otomatis sudah berfungsi. Buat dokumentasi prosedur dan tandai konfigurasi yang harus dilakukan operator.

Jangan melakukan restore atau manipulasi pada database production.

## 9. Tahap G — Monitoring dan Observability

Pastikan aplikasi mempunyai informasi operasional untuk:
- Queue backlog dan failed jobs.
- Reverb availability serta koneksi client.
- Scheduler lateness.
- Timeout poker yang melewati deadline.
- Kegagalan transaksi.
- Konflik idempotency.
- Deadlock atau kegagalan locking.
- Anomali rekonsiliasi ledger.
- Kegagalan broadcast setelah commit.

Gunakan logging terstruktur dan hindari mencatat token, session ID rahasia, kredensial, atau payload privat yang tidak diperlukan.

Jika belum ada monitoring eksternal, sediakan health checks dan dokumentasi metrik yang perlu dipantau. Jangan memasang layanan monitoring tambahan tanpa kebutuhan yang jelas.

Health check tidak boleh mengungkap kredensial atau detail internal yang sensitif.

## 10. Tahap H — Load dan Recovery Testing

Jangan menjalankan load test pada production.

Gunakan staging yang terisolasi dan catat konfigurasi database, worker, Reverb, serta jumlah proses yang diuji.

Uji setidaknya:
- Beberapa room aktif bersamaan.
- Banyak klien menerima perubahan state.
- Beban aksi dan settlement secara bersamaan pada hand berbeda.
- Timeout ketika queue mengalami keterlambatan.
- Queue worker restart.
- Reverb restart saat ada room aktif.
- Reconnect beberapa klien setelah terputus.
- Database sementara tidak tersedia.
- Retry job dan deteksi duplikasi event.

Ukur latency p50 dan p95, tingkat kegagalan, queue backlog, dan waktu recovery bila tool pengujian tersedia.

Jangan mengarang angka kapasitas. Bedakan hasil yang benar-benar diukur dengan estimasi.

## 11. Quality Gate

Jalankan:
- Seluruh Pest suite pada SQLite untuk test yang kompatibel.
- Seluruh Pest suite pada PostgreSQL.
- PHPStan.
- Pint.
- Tes migrasi.
- Pemeriksaan konfigurasi scheduler.
- Pemeriksaan broadcasting dan queue.
- Pengujian keamanan yang relevan.
- Pengujian concurrency dan rekonsiliasi yang telah tersedia.

Jangan menyatakan readiness penuh jika PostgreSQL, worker, scheduler, atau Reverb tidak dapat diuji.

## 12. Definition of Done

Tahap ini selesai apabila:
1. Scheduler dan timeout memiliki prosedur operasional yang jelas dan dapat diuji.
2. Queue worker dan Reverb memiliki konfigurasi deployment yang terdokumentasi.
3. Security audit tidak menyisakan temuan kritis tanpa mitigasi.
4. Kebijakan penonaktifan dan penghapusan akun terdokumentasi.
5. Backup dan pemulihan memiliki runbook yang bisa diikuti.
6. Monitoring minimum untuk transaksi, queue, scheduler, dan WebSocket tersedia atau memiliki daftar implementasi yang jelas.
7. Pengujian aplikasi dan kualitas kode tetap lulus.
8. Kesiapan produksi dinilai berdasarkan bukti, bukan hanya jumlah test yang hijau.

## 13. Laporan Akhir

Sampaikan:
- Hasil audit dan perubahan yang dilakukan.
- File baru atau berubah.
- Konfigurasi operasional yang diperlukan.
- Hasil test dan pengujian pemulihan.
- Hasil load test yang benar-benar dijalankan.
- Risiko yang masih terbuka.
- Checklist operator sebelum deployment.
- Status setiap Definition of Done.
- Pernyataan eksplisit apakah aplikasi siap staging, siap soft launch terbatas, atau masih memerlukan pekerjaan.

Jangan melakukan deployment atau mengklaim aplikasi production-ready tanpa bukti operasional yang memadai.