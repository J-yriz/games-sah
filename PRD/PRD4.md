# Task: Tahap 8E — Poker Hardening, Seat Integrity, dan PostgreSQL Verification

Bertindak sebagai Senior Laravel Engineer dan Database Reliability Engineer. Lakukan hardening terhadap implementasi Virtual Chip Table Tahap 1–8.

## 1. Kondisi Proyek

Stack:
- PHP 8.4
- Laravel 13
- Livewire 4
- PostgreSQL sebagai target validasi transaksi dan konkurensi
- SQLite untuk pengujian lokal yang sesuai
- Laravel Reverb dan Echo
- Pest, PHPStan, Pint

Implementasi poker saat ini mencakup:
- State machine hand.
- Blind, dealer rotation, dan heads-up order.
- Check, call, bet, raise-to, fold, dan all-in.
- Short all-in serta reopening action.
- Main pot dan side pot.
- Uncalled contribution return.
- Split pot dan odd chip.
- Manual showdown dan settlement.
- Ledger, idempotensi, serta broadcasting real-time.

Hasil pengujian terakhir:
- SQLite: 162/162 lulus.
- PHPStan: 0 error.
- Pint: bersih.
- PostgreSQL: belum diverifikasi karena driver/server belum tersedia pada environment saat itu.
- `room_members.seat` masih memiliki unique constraint global, bukan unique constraint per room.

## 2. Tujuan

1. Memperbaiki batasan seat agar berlaku per room.
2. Membuktikan migrasi dan seluruh suite dapat berjalan pada PostgreSQL.
3. Membuktikan transaksi poker aman terhadap aksi bersamaan pada hand yang sama.
4. Memverifikasi bahwa ledger, stack, dan settlement tetap konsisten.
5. Tidak menambahkan fitur produk baru dalam tahap ini.

## 3. Tahap A — Audit Seat Constraint

Periksa migration dan skema database aktual untuk:
- Tabel tempat `seat` didefinisikan.
- Nama kolom foreign key room.
- Unique index atau constraint yang saat ini mengikat `seat`.
- Data yang sudah ada dan kemungkinan konflik.
- Relasi seat dengan `room_members` dan `poker_hand_players`.

Jangan mengasumsikan nama index atau bentuk skema tanpa memeriksa migration dan database.

### Perbaikan

Buat migration baru yang:
1. Menghapus unique constraint global pada nomor seat.
2. Mengganti constraint menjadi unik berdasarkan room dan nomor seat, misalnya `(room_id, seat)`.
3. Mempertahankan foreign key serta unique constraint keanggotaan yang tidak terkait.
4. Dapat dijalankan terhadap database yang sudah memiliki data.
5. Menyediakan prosedur rollback yang benar.

Sebelum memasang constraint baru, periksa apakah ada data yang bertabrakan. Jika ditemukan, laporkan konflik secara eksplisit dan jangan menghapus atau memindahkan data pengguna secara diam-diam.

Pertahankan semantik seat nullable apabila memang digunakan oleh skema aktual untuk anggota yang belum mendapat kursi.

### Test

Tambahkan pengujian untuk membuktikan:
- Seat yang sama boleh digunakan dalam dua room berbeda.
- Dua anggota tidak boleh mendapatkan seat yang sama dalam room yang sama.
- Keanggotaan pengguna tetap unik dalam room.
- Perubahan seat tetap lolos foreign key dan constraint lain.
- Join atau pengaturan seat bersamaan tidak melampaui constraint.
- Migrasi fresh dan migration upgrade berhasil pada database target.

## 4. Tahap B — PostgreSQL Environment

Audit apakah PHP PDO PostgreSQL driver dan PostgreSQL server tersedia pada environment yang akan digunakan.

Jika belum tersedia:
- Laporkan persyaratan yang kurang secara eksplisit.
- Gunakan environment PostgreSQL terisolasi jika dapat disediakan dengan aman.
- Jangan mengubah database produksi.
- Jangan mengasumsikan PostgreSQL tersedia hanya karena test configuration menggunakan driver `pgsql`.
- Jangan mengklaim validasi PostgreSQL berhasil sebelum test benar-benar tersambung dan dieksekusi pada PostgreSQL.

Gunakan database khusus pengujian, terpisah dari database staging yang berisi data penting.

Jangan mencetak kredensial atau isi `.env`. Jangan mereset database yang tidak secara eksplisit ditetapkan sebagai database pengujian.

## 5. Tahap C — Migrasi dan Suite PostgreSQL

Jalankan migrasi dari keadaan database pengujian yang bersih serta verifikasi upgrade dari skema sebelumnya yang sesuai.

Kemudian jalankan seluruh test suite pada PostgreSQL, bukan hanya test poker.

Verifikasi:
- Semua migration berjalan.
- Semua foreign key, unique constraint, dan CHECK constraint bekerja.
- Settlement tidak kehilangan ledger.
- Idempotency key tetap mencegah aksi ganda.
- Test pada SQLite tetap lulus.
- Tidak ada regression pada wallet, room, transfer, buy-in, dan cash-out.

Laporkan jumlah aktual test yang lulus, gagal, dilewati, atau tidak dapat dijalankan.

## 6. Tahap D — Audit Locking dan Transaksi Poker

Audit implementasi aktual pada:
- `StartHand`
- `PlayerAction`
- `AdvanceStreet`
- `SettleHand`
- `PotCalculator`
- Seluruh event poker dan room yang relevan.

Verifikasi urutan locking yang ditetapkan implementasi, termasuk hubungan antara hand,