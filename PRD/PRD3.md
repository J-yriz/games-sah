# PRD / Implementation Task — Tahap 8: Poker Betting Engine

**Produk:** Virtual Chip Table  
**Target:** Laravel 13, Livewire 4, Blade, Flux, Tailwind CSS 4, PostgreSQL, Laravel Reverb  
**Jenis permainan:** No-Limit Texas Hold'em dengan kartu fisik  
**Status:** Tahap 7 selesai, Tahap 8 belum diimplementasikan

## 1. Tujuan

Implementasikan mesin taruhan poker yang terintegrasi dengan room, anggota, table stack, chip transaction ledger, dan broadcasting yang sudah tersedia.

Aplikasi tidak membagikan atau mengevaluasi kartu digital. Pemain tetap menggunakan kartu fisik. Aplikasi mengelola state permainan, giliran, taruhan, kontribusi chip, pot, side pot, dan penyelesaian hasil.

Seluruh state permainan yang menentukan saldo harus dihitung serta divalidasi di server Laravel. Frontend hanya menampilkan state dan mengirim permintaan aksi.

## 2. Kondisi Repository

Tahap 1–7 telah selesai, dengan:
- Wallet akun dan saldo awal.
- Transfer chip antarpengguna.
- Room, host, anggota, dan kode undangan.
- Table stack.
- Buy-in, rebuy, dan cash-out atomik.
- Chip transaction ledger dengan referensi wallet dan table stack.
- Laravel Reverb, Echo, private channel, dan presence.
- Pengujian SQLite dan PostgreSQL.
- Pest, PHPStan, serta Pint.

**Wajib:** audit repository dan gunakan implementasi aktual sebagai sumber kebenaran. Jangan menebak struktur kolom, nama method, status room, ataupun skema ledger.

Sebelum mengubah kode, periksa:
- Git status dan perubahan lokal.
- Model serta action chip dan room.
- Migrasi `chip_transactions` beserta seluruh foreign key.
- `RoomStateUpdated`, channel authorization, dan konfigurasi broadcasting.
- UI Livewire room yang sudah tersedia.
- Test yang sudah berjalan pada SQLite dan PostgreSQL.

Pertahankan fitur Tahap 1–7 dan hindari perubahan yang tidak diperlukan.

## 3. Ruang Lingkup

### Termasuk
- Membuka dan mengakhiri hand.
- Rotasi dealer button.
- Small blind dan big blind.
- Giliran pemain.
- Check, call, bet, raise, fold, dan all-in.
- Validasi minimum bet dan minimum raise.
- Pengelolaan street preflop, flop, turn, dan river.
- Perhitungan kontribusi dan pot.
- Perhitungan main pot serta side pot.
- Konfirmasi pemenang berdasarkan kartu fisik.
- Pembagian pot dan split pot.
- Audit transaksi chip.
- Sinkronisasi state permainan secara real-time.
- Penanganan disconnect dan reconnect.
- Pengujian integritas serta konkurensi pada PostgreSQL.

### Tidak termasuk
- Pembagian kartu digital.
- Pengenalan kartu menggunakan kamera atau OCR.
- Evaluasi kombinasi kartu otomatis.
- Turnamen dan leaderboard global.
- Uang asli atau penukaran chip menjadi uang.
- Blackjack.
- Implementasi ulang fitur wallet, transfer, atau room.

## 4. Aturan Permainan

Implementasikan No-Limit Texas Hold'em untuk permainan kartu fisik.

### 4.1 Persyaratan Memulai Hand

Hand hanya dapat dimulai apabila:
- Room berstatus aktif.
- Host memiliki otorisasi untuk memulai hand.
- Minimal dua pemain memiliki keanggotaan aktif dan saldo meja positif.
- Tidak ada hand sebelumnya yang masih belum diselesaikan.
- Konfigurasi small blind dan big blind valid.
- Semua pemain yang diikutsertakan mempunyai table stack yang sah.

Pemain dengan stack nol tidak dapat ikut dalam hand baru sampai melakukan buy-in atau rebuy yang diizinkan.

Host boleh ikut bermain sebagai pemain biasa, tetapi hak sebagai host tidak memberikan kewenangan untuk memanipulasi saldo atau melewati validasi permainan.

### 4.2 Dealer Button dan Blind

Sistem harus menentukan urutan pemain secara konsisten berdasarkan posisi kursi.

Jika posisi kursi belum tersedia dalam model room, buat migrasi dan mekanisme seat assignment yang cocok dengan desain saat ini.

Ketentuan:
- Dealer button bergerak mengikuti urutan kursi dari hand ke hand.
- Small blind dan big blind ditentukan berdasarkan posisi dealer.
- Aturan heads-up harus ditangani dengan benar.
- Pemain yang tidak memiliki cukup chip untuk blind hanya menyetor sisa chip yang tersedia dan dianggap all-in jika stack habis.
- Blind harus dicatat sebagai kontribusi hand yang sah.
- Permintaan memulai hand berulang tidak boleh memposting blind dua kali.

Small blind dan big blind harus menjadi konfigurasi room. Jangan menetapkan nilai yang tidak dapat diubah atau hanya berada di frontend.

## 5. State Machine

Buat state machine server-side yang eksplisit dan tervalidasi.

Minimal, lifecycle hand meliputi:
- `preflop`
- `awaiting_flop`
- `flop`
- `awaiting_turn`
- `turn`
- `awaiting_river`
- `river`
- `awaiting_showdown`
- `settled`
- `cancelled`

Sesuaikan nama status dengan konvensi proyek jika diperlukan, tetapi transisi harus eksplisit.

### Aturan transisi

1. Hand dimulai pada preflop.
2. Small blind dan big blind diposting.
3. Pemain bertindak sesuai urutan giliran.
4. Ketika betting round selesai, sistem menunggu host mengonfirmasi bahwa kartu fisik street berikutnya telah dibagikan.
5. Host tidak boleh mengubah street selama masih ada aksi taruhan yang belum diselesaikan.
6. Setelah flop, turn, dan river, hand berlanjut ke showdown.
7. Host mengonfirmasi pemenang berdasarkan kartu fisik.
8. Sistem menghitung serta mendistribusikan pot yang valid.
9. Hand berubah menjadi settled dan tidak dapat diproses ulang.

Jika semua lawan fold sehingga hanya satu pemain tersisa, sistem dapat langsung menentukan pemain tersebut sebagai pemenang tanpa meminta konfirmasi showdown.

Jika semua pemain yang tersisa all-in, host tetap dapat menjalankan proses pembagian kartu fisik hingga street terakhir sebelum mengonfirmasi hasil.

Host tidak dapat mengubah state langsung melalui parameter request. Setiap transisi divalidasi di backend.

## 6. Rancangan Data Hand

Rancang model serta migrasi sesuai konvensi proyek. Pertimbangkan struktur berikut.

### `poker_hands`

Menyimpan:
- ID hand.
- Room ID.
- Nomor hand berurutan dalam room.
- Dealer user ID atau referensi kursi dealer.
- Small blind dan big blind yang berlaku pada hand.
- Street dan status hand.
- Pemain yang sedang mendapat giliran.
- Current bet untuk street berjalan.
- Ukuran minimum full raise terakhir.
- Timestamp mulai dan selesai.

Nomor hand dalam satu room harus unik.

### `poker_hand_players`

Menyimpan snapshot pemain pada hand:
- Hand ID.
- User ID.
- Posisi kursi ketika hand dimulai.
- Stack awal.
- Total kontribusi pada hand.
- Kontribusi street berjalan.
- Status pemain: active, folded, all-in, atau status terminal lain yang diperlukan.
- Flag apakah pemain telah bertindak pada tahap betting berjalan.
- Timestamp yang relevan.

Pemain yang keluar dari room tidak boleh menghilangkan snapshot hand historis.

### `poker_actions`

Simpan event aksi secara append-only:
- Hand ID.
- Urutan aksi.
- User ID.
- Street.
- Jenis aksi.
- Jumlah kontribusi, jika berlaku.
- Target raise, jika berlaku.
- Operation ID atau idempotency key.
- Metadata tambahan yang relevan.
- Timestamp.

Tambahkan constraint untuk mencegah urutan aksi duplikat dan operation ID yang digunakan berulang secara tidak sah.

Aksi check dan fold tetap harus dicatat walaupun tidak memindahkan chip.

Data yang diperlukan untuk menentukan urutan aksi, taruhan, dan kontribusi tidak boleh hanya disimpan dalam metadata JSON yang tidak tervalidasi.

## 7. Integrasi Ledger dan Escrow Pot

Ini adalah bagian kritis.

`chip_transactions` sudah mendukung perpindahan antara wallet dan table stack. Pot poker belum menjadi tujuan transaksi eksplisit pada skema sebelumnya.

Sebelum mengimplementasikan taruhan, rancang perluasan ledger agar:
- Kontribusi dari stack ke pot memiliki referensi hand yang eksplisit.
- Pembagian pot dari hand ke stack pemenang memiliki referensi hand yang eksplisit.
- Ledger dapat digunakan untuk merekonsiliasi kontribusi, payout, dan saldo stack.
- Riwayat lama tetap kompatibel.

Pertimbangkan merepresentasikan setiap hand sebagai akun escrow virtual, menggunakan foreign key ke hand dari ledger untuk membedakan sumber dan tujuan chip. Perluasan dapat menggunakan foreign key sumber dan tujuan hand, atau struktur lain yang setara, selama aturan akuntansi jelas dan bisa diuji.

Jangan sekadar mengurangi stack dan menyimpan angka pot terpisah tanpa jejak transaksi yang dapat diaudit.

### Aturan kontribusi

Ketika pemain bet, call, raise, all-in, atau membayar blind:
1. Validasi aksi dan giliran.
2. Kunci hand serta stack yang relevan.
3. Tentukan jumlah kontribusi valid.
4. Kurangi stack sebesar kontribusi tersebut.
5. Tingkatkan kontribusi pemain untuk hand dan street.
6. Catat aksi dan ledger.
7. Commit transaksi.
8. Siarkan perubahan setelah commit.

Stack tidak boleh menjadi negatif. Kontribusi yang telah disetorkan tidak dapat digunakan kembali oleh pemain.

## 8. Aturan Aksi Taruhan

### Check
Diizinkan jika pemain tidak perlu menambah chip untuk menyamai taruhan yang berlaku.

### Call
Kontribusi sebesar jumlah yang dibutuhkan untuk menyamai taruhan saat ini, dibatasi oleh stack yang tersedia. Jika chip tidak mencukupi, aksi menghasilkan all-in call.

### Bet
Diizinkan ketika belum ada taruhan aktif pada street berjalan. Minimum bet mengikuti aturan blind dan konfigurasi permainan.

### Raise
Antarmuka harus menentukan dengan jelas apakah jumlah yang dimasukkan adalah raise-to atau raise-by. Pilih satu konvensi dan gunakan konsisten di seluruh backend serta UI.

Rekomendasi: gunakan `raise_to`, yaitu total kontribusi pemain pada street tersebut setelah aksi selesai.

Validasi:
- Target harus melebihi current bet.
- Jumlah kenaikan harus memenuhi minimum full raise, kecuali pemain melakukan all-in yang sah dengan jumlah kurang dari minimum.
- Kenaikan tidak boleh melebihi chip yang tersedia.
- Full raise mengatur ulang kesempatan bertindak sesuai aturan poker.

### Fold
Pemain menyerah dari hand berjalan:
- Tidak boleh bertindak lagi pada hand tersebut.
- Kontribusi sebelumnya tetap menjadi bagian dari pot.
- Pemain tidak memenuhi syarat memenangkan pot.
- Status stack dan riwayat kontribusi tetap dipertahankan.

### All-in
Pemain mempertaruhkan seluruh chip meja yang masih dimiliki untuk hand tersebut.

Validasi:
- Jumlah kontribusi tidak boleh melebihi stack.
- Kontribusi all-in tetap digunakan dalam kalkulasi pot.
- All-in dengan jumlah kurang dari call menghasilkan short call yang sah.
- Short all-in raise harus mengikuti aturan minimum raise dan reopening action yang dipilih.
- All-in tidak boleh membuat pemain bertindak melebihi stack yang dimiliki.

Implementasikan aturan short all-in secara eksplisit. Jangan mengasumsikan setiap all-in membuka kembali aksi raise untuk semua pemain.

## 9. Urutan Giliran dan Penyelesaian Betting Round

Implementasikan penentuan giliran berdasarkan posisi kursi dan street.

Ketentuan:
- Preflop mengikuti urutan standar setelah big blind, dengan penanganan khusus heads-up.
- Postflop dimulai dari pemain aktif pertama di sebelah kiri dealer, dengan penanganan heads-up yang benar.
- Pemain folded atau all-in tidak mendapatkan giliran betting baru.
- Pemain harus bertindak saat menjadi current turn.
- Aksi dari pemain yang salah giliran harus ditolak.
- Hand hanya dapat berpindah ke street berikutnya ketika betting round benar-benar selesai.

Betting round dinyatakan selesai apabila:
- Tidak ada lagi aksi legal yang harus dilakukan, atau
- Seluruh pemain aktif yang dapat bertaruh telah bertindak dan jumlah kontribusinya sudah sesuai dengan taruhan yang wajib dibayar, atau
- Tinggal satu pemain yang tidak fold dan hand harus diselesaikan sebagai fold win.

Tangani kondisi semua pemain all-in sehingga aplikasi tidak menunggu aksi dari pemain yang tidak dapat bertaruh lagi.

## 10. Pot Utama dan Side Pot

Bangun algoritma pot yang menghitung kontribusi total setiap pemain pada hand.

Pot harus ditentukan dari kontribusi aktual, bukan dari angka yang dikirim frontend.

Contoh:

- Pemain A berkontribusi 1.000 chips.
- Pemain B berkontribusi 600 chips.
- Pemain C berkontribusi 300 chips.

Kontribusi bertingkat harus menghasilkan:
- Main pot sebesar 900 chips, yang berasal dari tiga kontribusi pertama sebesar 300 chips.
- Side pot sebesar 600 chips, yang hanya berasal dari kontribusi tambahan A dan B.
- Sisa 400 chips milik A yang tidak dapat disamai kontribusi lawan harus dikembalikan kepada A sebagai uncalled excess, bukan dianggap menang otomatis sebagai pot.

Jumlah seluruh kontribusi harus sama dengan total pot yang sedang berada dalam hand sebelum distribusi kemenangan.

### Eligibilitas pot

- Pemain yang fold tidak dapat memenangkan pot.
- Setiap side pot memiliki daftar pemain yang memenuhi syarat berdasarkan kontribusi dan status hand.
- Jika suatu lapisan kontribusi hanya berasal dari pemain yang fold dan tidak memiliki pemain aktif yang memenuhi syarat, algoritma harus menangani jumlah tersebut secara benar sesuai aturan poker, bukan menghasilkan pot tanpa pemenang.
- Side pot tidak boleh dimenangkan pemain yang tidak berkontribusi cukup untuk memenuhi syarat pada lapisan tersebut.
- Kontribusi tak tersamai dikembalikan dengan transaksi yang tercatat.

Buat algoritma pot terpisah dari UI agar dapat diuji dengan kasus sederhana maupun edge case.

## 11. Showdown Fisik dan Settlement

Kartu fisik tidak diketahui oleh server, sehingga MVP menggunakan konfirmasi pemenang manual.

### Proses

1. Hand mencapai keadaan menunggu showdown.
2. Host meninjau hasil kartu fisik.
3. Sistem menyajikan main pot, side pot, jumlahnya, dan pemain yang eligible pada setiap pot.
4. Host memilih satu atau beberapa pemenang untuk setiap pot.
5. Backend memvalidasi pilihan terhadap daftar pemain yang eligible.
6. Sistem menghitung distribusi payout.
7. Server menyimpan hasil dan menjalankan transaksi settlement secara atomik.
8. Chip diberikan kepada stack pemenang.
9. Hand ditandai settled dan event dikirim setelah commit.

Pemain yang menang karena semua lawan fold dapat menerima pot secara otomatis, dengan catatan transaksi yang sama.

### Split pot

Sistem harus mendukung beberapa pemenang yang membagi satu pot.

Gunakan pembagian integer. Jika ada sisa chip yang tidak habis dibagi rata, terapkan aturan odd chip yang deterministik berdasarkan urutan kursi dan dealer button.

Distribusi payout untuk setiap pot harus memenuhi:
- Total payout sama dengan total nilai pot.
- Pemenang adalah pemain yang eligible.
- Tidak ada payout ganda.
- Tidak ada payout untuk pot yang sudah diselesaikan.
- Total pot sebelum settlement sama dengan total chip yang didistribusikan dan pengembalian kontribusi yang sah.

Karena hasil kartu bersifat fisik, aplikasi tidak mengklaim dapat memverifikasi kekuatan kombinasi kartu secara otomatis. Hasil showdown yang dikonfirmasi host harus terekam dalam audit log.

## 12. Real-time dan UI

Gunakan Livewire, Flux, Blade, Tailwind, Laravel Echo, dan Reverb yang telah ada.

UI meja harus menampilkan:
- Daftar pemain dan posisi kursi.
- Dealer button, small blind, dan big blind.
- Stack setiap pemain.
- Pemain yang sedang mendapat giliran.
- Current bet dan jumlah yang harus di-call.
- Aksi terakhir.
- Total pot dan estimasi kontribusi.
- Street saat ini.
- Status hand dan status koneksi pemain.
- Tombol aksi yang sesuai kondisi pemain.
- Kontrol host untuk memulai hand, mengonfirmasi street, dan menyelesaikan showdown.

### Event real-time

Buat event yang sesuai dengan pola proyek, misalnya `PokerHandStateUpdated`.

Event harus dikirim setelah database berhasil commit. Payload hanya menyertakan data minimum yang diperlukan untuk UI.

Semua klien yang merupakan anggota room berwenang harus melihat state terbaru. Aksi dari frontend tidak boleh langsung menentukan saldo atau hasil akhir.

Reconnect harus memuat state resmi dari database. Jika event terlewat, aplikasi harus dapat kembali ke state yang benar tanpa menggandakan transaksi.

## 13. Otorisasi dan Konkurensi

Semua operasi permainan harus divalidasi di server.

Ketentuan:
- Hanya host berwenang memulai atau menutup hand dan mengonfirmasi street sesuai kebijakan meja.
- Pemain hanya dapat melakukan aksi atas namanya sendiri.
- Hanya pemain yang sedang mendapat giliran yang dapat melakukan aksi betting.
- Pemain yang fold atau all-in tidak dapat bertaruh lagi.
- Hand settled tidak menerima aksi baru.
- Nonanggota tidak dapat melihat state privat hand.
- Tidak ada jalur UI untuk mengubah kontribusi atau payout secara langsung.

Gunakan transaksi database dan row-level locking yang sesuai.

Tentukan urutan lock yang konsisten untuk hand dan stack. Semua operasi yang dapat mengubah state hand harus serial terhadap hand yang sama, sehingga dua aksi bersamaan dari current turn tidak bisa keduanya berhasil.

Operation ID harus mencegah aksi yang sama diproses dua kali. Jika operation ID yang sama digunakan dengan parameter berbeda, kembalikan konflik tanpa mutasi state.

Pastikan event hanya dipancarkan setelah commit. Broadcasting yang gagal tidak boleh membatalkan transaksi yang sudah berhasil tersimpan.

## 14. Pengujian Wajib

Gunakan Pest yang sudah tersedia.

### Lifecycle
- Hand hanya dimulai dengan minimal dua pemain yang eligible.
- Blind diposting satu kali.
- Dealer button berputar dengan benar.
- Heads-up memiliki urutan blind dan giliran yang benar.
- Street tidak bisa dilewati ketika betting belum selesai.
- Hand settled tidak bisa diproses ulang.

### Betting
- Check, call, bet, raise, fold, dan all-in valid.
- Aksi saat salah giliran ditolak.
- Saldo yang tidak cukup menghasilkan kontribusi yang valid tanpa saldo negatif.
- Short all-in raise mengikuti aturan reopening action.
- Pemain fold tidak dapat bertindak lagi.
- Permintaan duplikat tidak menggandakan aksi atau transaksi.

### Pot dan settlement
- Main pot sederhana.
- Dua atau lebih side pot.
- Uncalled excess dikembalikan.
- Pemain fold tidak eligible menang.
- Split pot.
- Odd chip.
- Hanya pemain yang eligible dapat memenangkan pot.
- Payout tidak melebihi pot.
- Settlement rollback penuh jika salah satu operasi gagal.
- Payout ganda ditolak.

### Konkurensi PostgreSQL
- Dua aksi bersamaan dari pemain yang sama.
- Aksi dari pemain yang salah giliran.
- Dua percobaan settlement bersamaan.
- Settlement bersamaan dengan permintaan aksi lain.
- Idempotency key sama secara bersamaan.
- Tidak ada deadlock yang tidak ditangani.
- Saldo dan ledger tetap konsisten.

Jalankan pengujian dengan koneksi database terpisah untuk skenario konkurensi. SQLite boleh digunakan untuk test cepat, tetapi hasil konkurensi harus divalidasi pada PostgreSQL.

### Reconciliation
Setelah setiap hand selesai, verifikasi:
- Total kontribusi.
- Pengembalian uncalled excess.
- Total main pot dan side pot.
- Total payout.
- Total saldo stack sebelum dan setelah hand.
- Konsistensi catatan transaksi ledger.

Total chip akun dan meja tidak boleh berubah akibat betting dan settlement normal.

## 15. Definition of Done

Tahap 8 dinyatakan selesai jika:
1. Poker hand memiliki state machine yang eksplisit.
2. Urutan dealer, blind, dan giliran akurat.
3. Semua aksi taruhan tervalidasi pada server.
4. Pot utama, side pot, dan uncalled excess dihitung benar.
5. Konfirmasi pemenang fisik menghasilkan payout yang valid.
6. Chip dan ledger tetap konsisten selama seluruh lifecycle.
7. Real-time state bekerja lintas perangkat.
8. Reconnect mengembalikan state resmi dari database.
9. Semua test lama tetap lulus.
10. Test baru lulus pada SQLite jika relevan dan pada PostgreSQL.
11. PHPStan dan Pint bersih.

## 16. Ketentuan Pelaksanaan

Kerjakan implementasi berdasarkan struktur repository aktual. Jangan berhenti pada desain atau dokumentasi jika environment memungkinkan implementasi dan pengujian.

Jika satu bagian terlalu luas, pecah pekerjaan menjadi subfase internal:
- 8A: state machine, kursi, blind, dan aksi betting.
- 8B: kontribusi, main pot, side pot, dan uncalled excess.
- 8C: showdown, settlement, event, dan UI.
- 8D: pengujian integritas dan konkurensi.

Jangan mulai subfase berikutnya jika invariant saldo pada subfase sebelumnya belum terbukti.

Jangan deploy ke production, jangan menghapus database, dan jangan menurunkan standar validasi demi membuat test lulus.

## 17. Laporan Akhir

Laporkan:
- File, migrasi, model, action, dan event yang berubah.
- Aturan poker yang benar-benar diimplementasikan.
- Asumsi terkait kartu fisik dan konfirmasi hasil.
- Test baru dan hasil test suite lengkap.
- Bukti pengujian konkurensi PostgreSQL.
- Hasil rekonsiliasi chip dan ledger.
- Risiko yang masih terbuka.
- Acceptance criteria yang terpenuhi dan yang belum.

Jangan mengklaim fungsi atau test berhasil tanpa bukti bahwa implementasi dan pengujian benar-benar berjalan.