# Task: Implement Phase 7 — Room, Players, Table Stacks, and Chip Movement

Bertindak sebagai Senior Laravel Engineer dan Software Architect. Implementasikan fondasi room permainan untuk Virtual Chip Table berdasarkan repository aktual. Jangan membangun ulang fitur yang sudah ada.

## 1. Kondisi Proyek

Stack:
- PHP 8.4
- Laravel 13
- Laravel Fortify
- Livewire 4 dan Blade
- Flux dan Tailwind CSS 4
- PostgreSQL untuk pengujian integritas dan konkurensi
- SQLite untuk pengembangan dan test yang sesuai
- Laravel Reverb, Laravel Echo, dan Pusher client
- Pest, PHPStan, dan Pint

Tahap 1–6 telah diselesaikan:
- Wallet akun dan chip transaction ledger.
- Saldo awal otomatis.
- Transfer antarpengguna dengan locking dan idempotensi.
- Antarmuka wallet dan riwayat transaksi.
- Real-time broadcasting yang sudah dibuktikan melalui WebSocket.
- Suite pengujian lulus pada SQLite dan PostgreSQL.
- Pengujian konkurensi transfer telah dilakukan pada PostgreSQL.

Fitur room, keanggotaan meja, saldo chip meja, buy-in, rebuy, dan cash-out belum diimplementasikan.

## 2. Tujuan Tahap 7

Bangun fondasi multiplayer room agar:
- Host dapat membuat dan mengelola room.
- Pemain dapat bergabung menggunakan kode undangan.
- Setiap pemain memiliki saldo chip terpisah pada setiap meja.
- Chip dapat dipindahkan dari wallet akun ke saldo meja dan kembali.
- Perubahan anggota serta saldo tersinkronisasi secara real-time.
- Semua perubahan saldo tetap atomik, idempoten, dan dapat diaudit.

Pada tahap ini, kartu tetap dimainkan secara fisik. Jangan implementasikan distribusi kartu, betting rounds, evaluasi kartu, pot, side pot, atau aturan poker. Fitur-fitur tersebut masuk Tahap 8.

## 3. Tahap A — Audit sebelum perubahan

Periksa implementasi aktual berikut:
- `app/Models/User.php`
- `app/Models/Wallet.php`
- `app/Models/ChipTransaction.php`
- `app/Actions/Chips/TransferChips.php`
- `app/Events/ChipBalanceUpdated.php`
- `app/Broadcasting/WalletChannel.php`
- Migrasi wallet dan chip transactions.
- Konfigurasi broadcasting dan Reverb.
- Komponen Livewire wallet.
- Struktur layout, navigasi, serta testing yang tersedia.

Jangan mengasumsikan nama kolom, relasi, atau implementasi persis sama dengan rancangan. Gunakan kode yang sudah ada sebagai sumber kebenaran.

Periksa git status terlebih dahulu. Jangan menimpa atau menghapus perubahan pengguna.

## 4. Tahap B — Rancangan Database

Buat skema yang konsisten dengan konvensi proyek dan database target.

### A. Tabel `rooms`

Minimal menyimpan:
- ID room.
- Host.
- Nama room.
- Kode undangan unik.
- Jenis permainan yang tersedia.
- Kapasitas pemain.
- Minimum dan maksimum buy-in.
- Konfigurasi room.
- Status room.
- Timestamp pembuatan dan perubahan.

Status awal dapat mencakup `waiting`, `active`, `completed`, dan `cancelled`.

Untuk Tahap 7, `active` berarti meja sedang dibuka untuk sesi permainan, bukan berarti terdapat betting round yang sedang berjalan.

Kode undangan harus cukup acak sehingga tidak mudah ditebak. Terapkan unique constraint dan penanganan collision.

### B. Tabel `room_members`

Minimal menyimpan:
- ID room.
- ID user.
- Peran `host` atau `player`.
- Status keanggotaan.
- Waktu bergabung.
- Timestamp lain yang diperlukan.

Gunakan unique constraint pada pasangan room dan user sesuai kebijakan keanggotaan.

Host otomatis menjadi anggota room. Jangan menyimpan status online permanen hanya berdasarkan koneksi WebSocket; gunakan Presence untuk status online dan database untuk keanggotaan persisten.

### C. Tabel `table_stacks`

Menyimpan saldo chip yang dibawa pemain ke setiap room:
- ID stack.
- ID room.
- ID user.
- Saldo chip sebagai bilangan bulat besar.
- Timestamp.

Satu pemain maksimal memiliki satu saldo meja aktif per room. Pastikan relasi room, anggota, dan stack konsisten.

### D. Integrasi chip ledger

Sebelum menambahkan buy-in, audit struktur `chip_transactions` saat ini.

Ledger saat ini sudah mendukung wallet pengirim dan penerima. Rancang perluasannya agar perpindahan chip ke atau dari `table_stacks` juga tercatat.

Pertimbangkan kolom referensi tambahan yang memiliki foreign key, misalnya:
- `from_table_stack_id`
- `to_table_stack_id`

Pertahankan transaksi lama tanpa mengubah maknanya.

Setiap jenis transaksi harus memiliki sumber dan tujuan yang jelas:

| Transaksi | Sumber | Tujuan |
|---|---|---|
| Initial grant | Tidak ada | Wallet akun |
| Transfer | Wallet pengirim | Wallet penerima |
| Buy-in | Wallet akun | Stack meja |
| Rebuy | Wallet akun | Stack meja |
| Cash-out | Stack meja | Wallet akun |

Terapkan validasi agar kombinasi sumber dan tujuan tidak ambigu. Jangan menggunakan metadata JSON sebagai satu-satunya referensi saldo meja jika foreign key dapat digunakan.

Riwayat lama harus tetap dapat dibaca setelah migrasi.

Pertimbangkan dengan hati-hati perilaku `cascadeOnDelete` dan `nullOnDelete`. Jangan menghapus stack atau room yang menyebabkan hilangnya kemampuan untuk mengaudit transaksi. Jika diperlukan, gunakan soft delete atau status penutupan dan dokumentasikan kebijakannya.

## 5. Tahap C — Pembuatan dan Pengelolaan Room

Implementasikan fitur berikut.

### Create Room
- Hanya pengguna terautentikasi dan terverifikasi yang dapat membuat room.
- Host menentukan nama, kapasitas, dan batas buy-in.
- Jenis permainan untuk MVP adalah poker dengan kartu fisik.
- Room dan keanggotaan host dibuat secara konsisten.
- Validasi kapasitas serta konfigurasi dilakukan di server.

### Join Room
- Pengguna dapat memasukkan kode undangan.
- Sistem memverifikasi kode, status room, dan kapasitas.
- Pengguna tidak dapat bergabung dua kali.
- Pengguna tidak dapat melewati batas kapasitas melalui request bersamaan.
- Akses ke room tidak diberikan hanya karena seseorang mengetahui ID room.

### Leave Room
- Pemain dapat meninggalkan room jika aturan memungkinkan.
- Jika masih mempunyai stack, pemain harus melakukan cash-out terlebih dahulu, kecuali terdapat mekanisme pemulihan yang secara eksplisit disetujui.
- Host tidak boleh menghilangkan saldo pemain dengan menutup room.
- Kebijakan penutupan room harus mencegah chip tersisa menjadi tidak dapat diakses.

### Host Controls
- Host dapat mengubah pengaturan yang diperbolehkan.
- Host dapat memulai sesi meja dan mengakhiri sesi sesuai status room.
- Host dapat mengeluarkan pemain jika kebijakan room mengizinkan.
- Host tidak boleh mengubah saldo wallet atau stack secara langsung.
- Tidak boleh ada transfer kepemilikan host yang memutus otorisasi atau audit secara tidak sengaja.

Jangan izinkan pemain baru masuk ketika room sudah terkunci untuk sesi aktif, kecuali terdapat kebijakan yang eksplisit dan diuji.

## 6. Tahap D — Buy-in, Rebuy, dan Cash-out

Implementasikan operasi saldo melalui action atau service Laravel, bukan melalui penulisan saldo langsung dari Livewire.

Buat komponen business logic yang jelas, misalnya:
- `App\Actions\Chips\BuyInToRoom`
- `App\Actions\Chips\CashOutFromRoom`

Rebuy dapat menggunakan alur top-up stack yang sama selama aturan dan pencatatannya tetap eksplisit.

### Buy-in
1. Validasi pengguna terautentikasi dan merupakan anggota room yang sah.
2. Validasi status room serta batas buy-in.
3. Pastikan wallet dan table stack tersedia.
4. Gunakan operation ID yang unik.
5. Mulai transaksi database.
6. Kunci wallet dan saldo meja terkait sesuai mekanisme database target.
7. Validasi ulang saldo serta batas meja.
8. Kurangi wallet dan tambahkan table stack.
9. Simpan transaksi ledger.
10. Commit transaksi.
11. Kirim event setelah commit.

### Rebuy
- Hanya diizinkan oleh aturan room.
- Harus berasal dari saldo akun pemain tersebut.
- Tidak boleh melampaui batas yang ditetapkan.
- Mengikuti perlindungan atomik dan idempotensi yang sama dengan buy-in.

### Cash-out
- Hanya pemain yang memiliki stack tersebut yang dapat melakukan cash-out.
- Perpindahan chip dari meja ke akun harus atomik.
- Tidak boleh menghasilkan saldo negatif.
- Tidak boleh diproses dua kali.
- Room tidak boleh ditutup sehingga meninggalkan saldo meja tanpa mekanisme pemulihan.

Belum ada betting rounds pada tahap ini, sehingga cash-out hanya dilakukan ketika chip belum terikat pada taruhan atau pot. Ketika betting engine dibangun pada Tahap 8, aturan ini harus diperketat untuk ronde aktif.

## 7. Tahap E — Konsistensi dan Keamanan

- Gunakan `DB::transaction()` serta locking yang sesuai untuk database target.
- Kunci beberapa saldo dalam urutan konsisten guna mengurangi deadlock.
- Terapkan idempotency key unik pada setiap operasi saldo.
- Operation ID yang sama dengan parameter berbeda harus ditolak.
- Jangan mempercayai `user_id`, saldo, atau role yang dikirim oleh frontend.
- Gunakan policy atau otorisasi yang setara untuk room, keanggotaan, dan stack.
- Aktifkan foreign key dan constraint yang sesuai.
- Jangan sediakan aksi Livewire yang memungkinkan pengguna mengubah saldo langsung.
- Batasi request pembuatan room, join room, dan transaksi chip jika diperlukan.
- Pertahankan audit ledger.

Definisikan dan uji invariants berikut:

1. Saldo wallet tidak negatif.
2. Saldo table stack tidak negatif.
3. Buy-in dan cash-out tidak menambah atau mengurangi total chip gabungan.
4. Setiap perubahan saldo memiliki catatan ledger.
5. Tidak ada operasi yang mengubah stack milik pemain lain tanpa otorisasi.
6. Total saldo semua wallet dan table stack berubah hanya melalui operasi penerbitan chip yang sah atau koreksi administratif yang tercatat.

Lakukan rekonsiliasi antara saldo dan ledger pada fixture uji. Jangan menganggap kesamaan saldo membuktikan ledger benar apabila transaksi tidak dicatat.

## 8. Tahap F — Real-time Room Updates

Manfaatkan Laravel Reverb, Echo, dan arsitektur event yang sudah tersedia.

Implementasikan event untuk perubahan room yang relevan, misalnya `RoomStateUpdated`.

Event atau mekanisme real-time harus mendukung:
- Pemain bergabung dan meninggalkan room.
- Perubahan konfigurasi room.
- Perubahan saldo stack.
- Buy-in, rebuy, dan cash-out.
- Perubahan status room.

Gunakan private channel untuk state room dan presence channel terpisah apabila daftar pemain online dibutuhkan.

Otorisasi channel harus memastikan pengguna adalah anggota room yang diizinkan.

Event dikirim setelah transaksi berhasil di-commit. Event tidak boleh digunakan untuk memutasi saldo langsung di frontend.

Seperti implementasi wallet, klien yang menerima event harus mengambil state terbaru dari database melalui mekanisme aplikasi, sedangkan klien yang reconnect harus memuat ulang state untuk mengejar perubahan yang terlewat.

Polling fallback yang telah ada boleh dipertahankan jika memang diperlukan, tetapi dokumentasikan jalur pembaruan utama.

## 9. Tahap G — UI Livewire

Pertahankan pola Blade + Livewire + Flux + Tailwind.

Implementasikan halaman:
- Daftar room.
- Form Create Room.
- Form Join Room menggunakan kode.
- Detail atau lobi room.
- Daftar pemain dan status online.
- Saldo akun.
- Saldo chip meja.
- Form Buy-in dan Rebuy.
- Form Cash-out.
- Riwayat transaksi chip terkait room.
- Kontrol host yang sesuai status room.

UI harus:
- Responsif pada desktop dan seluler.
- Menampilkan loading serta status operasi.
- Meminta konfirmasi sebelum pemindahan chip.
- Menampilkan error validasi dari server.
- Tidak menyatakan transaksi sukses sebelum hasil resmi diterima.
- Tetap konsisten dengan layout dan komponen yang sudah tersedia.

Jangan membuat desain poker table untuk seluruh aksi permainan pada tahap ini. Fokus pada lobi, anggota, dan saldo meja.

## 10. Tahap H — Pengujian

Gunakan Pest dan pola test yang tersedia di repository.

### Room
- Membuat room.
- Host otomatis menjadi anggota.
- Join dengan kode yang benar.
- Kode tidak valid ditolak.
- Join ganda ditolak atau ditangani secara idempoten.
- Kapasitas tidak dapat dilampaui melalui request bersamaan.
- Nonanggota tidak dapat membaca atau mengubah state privat room.
- Hanya host yang dapat menjalankan kontrol yang memerlukan hak host.

### Saldo
- Buy-in berhasil.
- Rebuy berhasil ketika diizinkan.
- Cash-out berhasil.
- Saldo tidak mencukupi menyebabkan rollback.
- Operasi duplikat tidak menggerakkan chip dua kali.
- Operation ID yang sama dengan jumlah berbeda ditolak.
- Transfer stack dan wallet tetap konsisten.
- Pemain tidak dapat mengambil chip milik pemain lain.

### Real-time
- Event dikirim setelah commit.
- Tidak ada event sukses setelah transaksi gagal.
- Anggota room yang berwenang menerima update.
- Pengguna nonanggota tidak dapat subscribe.
- Reconnect memuat state terbaru.

### Konkurensi PostgreSQL
- Buy-in bersamaan tidak menghabiskan wallet secara berlebihan.
- Dua cash-out bersamaan tidak menggandakan saldo.
- Buy-in dan cash-out yang bersamaan tetap menghasilkan saldo valid.
- Join room bersamaan tidak melampaui kapasitas.
- Ledger tetap konsisten pada seluruh skenario.

Jalankan pengujian konkurensi sungguhan dengan koneksi database terpisah. Jangan menggunakan pemanggilan service secara berurutan sebagai bukti locking bekerja.

Jalankan suite pada PostgreSQL. SQLite juga harus tetap lulus untuk test yang mendukung driver tersebut.

## 11. Batasan Implementasi

- Jangan membangun betting engine atau side pot pada tahap ini.
- Jangan merombak transfer service yang sudah lulus test tanpa alasan konkret.
- Jangan mengubah saldo pengguna atau data produksi.
- Jangan menghapus ledger atau mematikan constraint demi membuat pengujian lulus.
- Jangan mengubah framework frontend.
- Jangan memasang dependensi yang tidak diperlukan.
- Jangan melakukan deploy ke production.
- Jangan menyatakan bahwa aplikasi mendukung multi-node atau beban besar tanpa pengujian.

## 12. Definition of Done

Tahap 7 dianggap selesai ketika:
1. Room dapat dibuat dan diikuti dengan aman.
2. Host dan anggota memiliki izin yang benar.
3. Wallet dan table stack dikelola secara terpisah.
4. Buy-in, rebuy, dan cash-out bekerja secara atomik.
5. Ledger mencatat seluruh perpindahan chip.
6. Real-time room updates bekerja melalui Reverb.
7. Reconnect memulihkan state dari database.
8. Pengujian konkurensi pada PostgreSQL lulus.
9. SQLite test yang relevan tetap lulus.
10. Seluruh suite, PHPStan, dan Pint lulus.

## 13. Laporan Akhir

Setelah implementasi, sampaikan:
- File dan migrasi yang dibuat atau diubah.
- Perubahan skema ledger serta strategi audit.
- Cara menjalankan fitur secara lokal.
- Test baru dan hasil seluruh suite.
- Hasil pengujian konkurensi PostgreSQL.
- Bukti event real-time berfungsi.
- Risiko yang belum ditangani.
- Acceptance criteria yang terpenuhi dan yang masih terbuka.

Kerjakan sesuai kondisi repository aktual dan jangan mengklaim test atau verifikasi yang tidak benar-benar dijalankan.