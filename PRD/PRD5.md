# Implementation Task — Tahap 9: Poker Table Operations

**Produk:** Virtual Chip Table  
**Stack:** Laravel 13, Livewire 4, Blade, Flux, Tailwind CSS 4, PostgreSQL, Reverb  
**Prasyarat:** Tahap 1–8E selesai  
**Mode:** Poker Texas Hold'em dengan kartu fisik

## 1. Tujuan

Tambahkan kemampuan operasional meja tanpa mengubah aturan inti poker yang telah diuji.

Fitur tahap ini:
1. Assignment dan pengelolaan kursi pemain secara otomatis.
2. Riwayat hand dan detail hasil permainan.
3. Timer aksi pemain.
4. Penanganan timeout.
5. Penanganan misdeal dan pembatalan hand yang aman.
6. Audit trail dan sinkronisasi real-time seluruh perubahan.

Jangan membangun fitur uang asli, distribusi kartu digital, evaluasi kombinasi kartu otomatis, atau fitur blackjack.

## 2. Tahap A — Preflight Audit

Sebelum melakukan perubahan, periksa implementasi aktual:
- `Room`, `RoomMember`, `TableStack`.
- `PokerHand`, model pemain hand, model aksi poker.
- `StartHand`, `PlayerAction`, `AdvanceStreet`, `SettleHand`.
- `PotCalculator`.
- Seluruh migrasi poker dan chip ledger.
- `PokerHandStateUpdated`, `RoomStateUpdated`.
- Channel authorization dan UI Livewire room.
- Job, scheduler, queue, serta konfigurasi broadcasting yang sudah tersedia.

Periksa git status dan jangan menimpa perubahan lokal.

Gunakan nama class dan struktur yang ditemukan di repository. Nama model serta migrasi dalam task ini merupakan konsep, bukan asumsi nama file pasti.

## 3. Tahap B — Seat Assignment Otomatis

### B.1 Persyaratan

Pengguna yang memiliki akses ke room dapat menempati kursi yang tersedia sesuai kapasitas meja.

Sistem harus:
- Menemukan kursi kosong secara otomatis.
- Mendukung pilihan kursi manual jika masih tersedia.
- Menolak seat di luar kapasitas room.
- Mencegah dua pengguna mendapat seat yang sama pada room yang sama.
- Mengizinkan nomor seat yang sama digunakan di room berbeda.
- Menjaga seat historis yang tercatat di snapshot hand sebelumnya.
- Mencegah pemain berpindah seat ketika sedang mengikuti hand aktif.

### B.2 Konkurensi

Seat assignment harus dijalankan melalui transaksi database.

Gunakan locking pada room atau mekanisme yang setara sehingga dua request bersamaan tidak dapat memilih seat yang sama. Unique constraint `(room_id, seat)` tetap menjadi perlindungan terakhir.

Jangan mengandalkan pemeriksaan frontend atau query `first available seat` tanpa mekanisme penguncian.

### B.3 Lifecycle

Implementasikan aturan untuk:
- Duduk di meja.
- Berdiri dari meja.
- Mengganti seat sebelum hand aktif.
- Menolak keluar jika hand atau saldo stack belum diselesaikan sesuai kebijakan aplikasi.
- Menangani disconnect tanpa otomatis menghapus keanggotaan atau menghilangkan chip.

Seat harus menjadi data persisten. Kehadiran WebSocket hanya menunjukkan status online.

## 4. Tahap C — Hand History

### C.1 Daftar Hand

Sediakan riwayat hand per room dengan:
- Nomor hand.
- Waktu mulai dan selesai.
- Street terakhir.
- Status hand.
- Pemain yang berpartisipasi.
- Total pot.
- Pemenang dan distribusi payout.
- Alasan pembatalan, jika ada.

Gunakan pagination dan indeks database yang sesuai.

### C.2 Detail Hand

Halaman detail menampilkan:
- Dealer button dan posisi blind.
- Urutan aksi pemain.
- Aksi check, call, bet, raise, fold, dan all-in.
- Jumlah kontribusi setiap aksi.
- Perubahan kontribusi per street.
- Main pot dan side pot.
- Uncalled excess yang dikembalikan.
- Payout per pemain.
- Hasil split pot dan odd chip.
- Settlement serta transaksi ledger terkait.

Karena kartu dimainkan secara fisik, riwayat tidak perlu memuat kartu digital. Sistem tidak boleh mengklaim mengevaluasi kombinasi kartu secara otomatis.

### C.3 Integritas dan Otorisasi

- Riwayat hand yang sudah selesai harus immutable melalui aplikasi.
- Hanya pengguna yang berhak mengakses room tersebut yang dapat membaca riwayat privat.
- Snapshot kursi serta identitas pemain pada hand lampau tidak boleh berubah ketika pemain berpindah seat.
- Riwayat tetap bisa ditelusuri meskipun room sudah ditutup.
- Jangan menghitung ulang hasil historis dari state room saat ini.
- Gunakan data aksi dan settlement yang benar-benar tersimpan.

Jika hasil hand mengalami koreksi administratif, buat mekanisme audit yang eksplisit dan jangan mengedit hasil secara diam-diam.

## 5. Tahap D — Action Timer

### D.1 Konfigurasi

Tambahkan konfigurasi timer per room:
- Durasi aksi normal.
- Durasi yang digunakan untuk aksi berikutnya ketika waktunya habis.
- Status timer aktif atau nonaktif sesuai kebijakan room.

Gunakan nilai default yang wajar dan dapat dikonfigurasi. Jangan menanamkan durasi hanya di frontend.

Simpan waktu mulai giliran dan deadline yang berasal dari server.

### D.2 Mekanisme

Timer harus ditentukan oleh server, bukan hitung mundur browser.

Ketentuan:
- Deadline ditetapkan ketika giliran baru dimulai.
- Perubahan giliran harus membatalkan atau membuat timer lama tidak berlaku.
- Deadline disimpan sebagai timestamp persisten.
- Proses timeout tidak boleh berjalan untuk hand yang sudah selesai.
- Scheduler atau queue worker dapat memeriksa deadline yang telah lewat.
- Jika beberapa worker memproses hand yang sama, hanya satu timeout yang boleh berhasil.
- Event real-time mengirim pembaruan deadline ke klien.

Jangan bergantung pada proses PHP di memori atau timer JavaScript sebagai sumber kebenaran.

### D.3 Perilaku Timeout

Untuk MVP, gunakan aturan berikut:
- Jika pemain dapat melakukan check tanpa menyamai taruhan tambahan, timeout menghasilkan auto-check.
- Jika pemain menghadapi taruhan yang harus dibayar, timeout menghasilkan auto-fold.
- Jika state berubah tepat saat timeout diproses, server harus mengevaluasi ulang kondisi terkini sebelum mengambil tindakan.
- Aksi otomatis harus tercatat dengan jelas sebagai aksi timeout, bukan dianggap aksi manual pengguna.

Auto-check dan auto-fold harus melalui aturan validasi serta transaction flow yang sama dengan aksi pemain normal.

Jangan membuat timeout menambah chip atau melewati giliran secara langsung tanpa mencatat aksi yang sah.

## 6. Tahap E — Misdeal dan Pembatalan Hand

### E.1 Tujuan

Sediakan mekanisme untuk membatalkan hand jika terjadi kesalahan pada permainan kartu fisik, misalnya distribusi kartu yang salah atau kondisi meja tidak memungkinkan permainan dilanjutkan.

Aplikasi tidak mengevaluasi kartu, sehingga alasan misdeal harus dikonfirmasi oleh pengguna yang berwenang.

### E.2 Otorisasi

- Host dapat mengajukan pembatalan dengan alasan yang wajib diisi.
- Semua aturan pembatalan divalidasi di backend.
- Hand settled tidak dapat dibatalkan melalui jalur normal.
- Pembatalan tidak boleh menjadi jalan pintas untuk mengubah saldo.
- Jika hand sudah berlangsung setelah pemain melakukan aksi, terapkan kebijakan yang eksplisit: membutuhkan persetujuan pemain aktif atau otorisasi administratif yang lebih tinggi.

Pilih satu kebijakan yang konsisten dan dokumentasikan sebelum implementasi.

### E.3 Refund dan Ledger

Jika hand dibatalkan setelah kontribusi chip dicatat:
1. Kunci hand dan seluruh stack terkait.
2. Pastikan hand belum diselesaikan.
3. Tentukan kontribusi yang perlu dikembalikan per pemain.
4. Lakukan pengembalian chip ke stack masing-masing.
5. Catat setiap pengembalian melalui transaksi kompensasi yang dapat diaudit.
6. Tandai hand sebagai cancelled.
7. Simpan alasan dan identitas pihak yang mengonfirmasi.
8. Kirim event setelah transaksi di-commit.

Jangan menghapus blind, betting action, atau ledger historis.

Jumlah yang dikembalikan harus berasal dari kontribusi aktual masing-masing pemain, bukan dari input frontend. Pengembalian juga harus memperhitungkan mekanisme uncalled excess yang mungkin sudah diproses.

Pembatalan tidak boleh menghasilkan pengembalian ganda jika request dikirim berulang.

## 7. State Machine dan Konkurensi

Pertahankan state machine poker yang telah ada.

- Seat assignment tidak boleh mengubah snapshot hand aktif.
- Timeout harus bersaing secara aman dengan aksi manual.
- Timeout harus bersaing secara aman dengan settlement.
- Misdeal harus bersaing secara aman dengan aksi dan settlement.
- Dua pembatalan dengan operation ID yang sama harus menjadi satu operasi.
- Permintaan pembatalan yang sama dengan parameter berbeda harus ditolak sebagai konflik.
- Hand settled tidak dapat berubah menjadi cancelled.
- Hand cancelled tidak dapat dilanjutkan atau diselesaikan sebagai hand normal.

Gunakan locking dengan urutan konsisten sebagaimana telah diperiksa dalam Tahap 8E. Setiap operasi harus mengunci dan memvalidasi state terbaru dalam transaksi.

Semua event dikirim setelah commit. Jika broadcasting gagal, state database tetap menjadi sumber kebenaran.

## 8. UI Livewire

Pertahankan desain dan framework yang sudah tersedia.

Tambahkan:
- Pemilihan seat saat bergabung ke room.
- Tampilan kursi dan pemain.
- Status kursi kosong, ditempati, dan pemain disconnect.
- Daftar hand sebelumnya.
- Halaman detail hand.
- Timer giliran yang menghitung mundur berdasarkan deadline server.
- Status timeout dan aksi otomatis.
- Tombol permintaan misdeal untuk host.
- Dialog konfirmasi dengan alasan pembatalan.
- Status hand dibatalkan beserta rincian chip yang dikembalikan.

UI harus menampilkan status yang sama di seluruh perangkat. Jangan menetapkan saldo, deadline, atau state settlement berdasarkan hasil perhitungan browser.

## 9. Pengujian Wajib

Gunakan Pest dan database testing yang sudah tersedia.

### Seat
- Penetapan otomatis kursi.
- Pemilihan seat manual.
- Dua join bersamaan untuk kursi terakhir.
- Seat yang sama boleh dipakai pada room berbeda.
- Seat ganda pada room yang sama ditolak.
- Pemain tidak bisa berpindah seat saat aktif dalam hand.
- Snapshot posisi hand lampau tetap utuh.

### Hand History
- Hand terselesaikan tampil dengan hasil lengkap.
- Side pot serta payout tercatat benar.
- Pengguna di luar room tidak bisa mengakses riwayat privat.
- Hand historis tetap terbaca setelah room ditutup.
- Koreksi tidak mengubah riwayat tanpa audit.

### Timer
- Deadline dimulai saat giliran ditetapkan.
- Aksi valid mengganti deadline.
- Timeout melakukan auto-check atau auto-fold sesuai kondisi.
- Dua worker timeout menghasilkan satu aksi saja.
- Timeout yang bersamaan dengan aksi manual hanya menghasilkan satu transisi valid.
- Timer tidak bekerja pada hand settled atau cancelled.
- Reconnect menampilkan deadline dan state terkini.

### Misdeal
- Pembatalan sebelum aksi.
- Pembatalan setelah blind atau betting yang diperbolehkan.
- Refund penuh berdasarkan kontribusi aktual.
- Tidak ada refund ganda.
- Settled hand tidak dapat dibatalkan.
- Hand cancelled tidak dapat dilanjutkan.
- Kegagalan settlement rollback seluruh refund.
- Riwayat aksi tetap ada setelah pembatalan.

### PostgreSQL Concurrency
Jalankan dengan koneksi database terpisah:
- Dua assignment seat untuk kursi yang sama.
- Timeout versus action.
- Timeout versus settlement.
- Misdeal versus action.
- Dua misdeal bersamaan.
- Misdeal versus settlement.

Periksa saldo stack, ledger, state hand, dan jumlah event pascacommit. Jangan menggunakan SQLite sebagai satu-satunya bukti konkurensi.

## 10. Quality Gate

Sebelum menyatakan selesai:
- Jalankan seluruh test suite SQLite dan PostgreSQL.
- Jalankan PHPStan dan Pint.
- Verifikasi seluruh migrasi dari skema bersih.
- Pastikan tidak ada regression Tahap 1–8E.
- Laporkan setiap test yang dilewati atau tidak dapat dijalankan.
- Jangan mengklaim pengujian konkurensi berhasil tanpa menjalankannya.

## 11. Batasan

- Jangan deploy ke production.
- Jangan menghapus atau mereset database pengguna.
- Jangan membuat fitur taruhan uang asli.
- Jangan menambahkan evaluasi kartu digital.
- Jangan merombak implementasi saldo atau poker tanpa alasan yang terbukti.
- Jangan menambah dependensi yang tidak diperlukan.
- Jangan menghilangkan validasi untuk membuat test lulus.

## 12. Laporan Akhir

Laporkan:
1. File dan migrasi yang berubah.
2. Strategi assignment seat.
3. Format serta akses riwayat hand.
4. Mekanisme timer dan worker yang digunakan.
5. Kebijakan pembatalan dan pengembalian chip.
6. Hasil test SQLite dan PostgreSQL.
7. Hasil pengujian konkurensi nyata.
8. Rekonsiliasi ledger setelah timeout dan misdeal.
9. Risiko yang masih terbuka.
10. Acceptance criteria yang terpenuhi.

Selesaikan melalui subfase terurut: 9A seat assignment, 9B hand history, 9C timer, 9D misdeal/cancel, lalu 9E hardening dan regresi. Jangan lanjut ke subfase berikutnya apabila invariant saldo pada subfase sebelumnya belum terbukti.