<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Field;
use App\Models\FieldType;
use App\Models\Booking;
use App\Models\BookingSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FEATURE TEST LENGKAP — TC-INT-01 s.d. TC-INT-25 (Integration Testing)
 * + TC-REG-01 s.d. TC-REG-10 (Regression Testing)
 *
 * Berdasarkan forensic repository analysis di implementation_plan.md
 * dan analisis kode aktual dari:
 * - routes/web.php (route definitions)
 * - app/Services/BookingService.php (business logic)
 * - app/Models/Booking.php, BookingSlot.php, Field.php (models + $fillable)
 * - app/Http/Controllers/User/BookingController.php (user flow)
 * - app/Http/Controllers/Admin/* (admin CRUD)
 * - app/Http/Middleware/RoleMiddleware.php (authorization)
 *
 * CATATAN PENTING:
 * - Route booking menggunakan /user/booking/{field} (dengan field sebagai parameter)
 * - BookingService menerima 'hours' sebagai array integer, bukan 'slots' string
 * - BookingSlot menggunakan kolom 'slot_date' dan 'slot_hour', bukan 'date' dan 'start_time'
 * - Booking menggunakan kolom 'booking_date', bukan 'date'
 * - payment_type, amount_paid, remaining_payment TIDAK ada di Booking $fillable (BUG)
 * - RoleMiddleware me-redirect (bukan 403) jika role tidak sesuai
 *
 * Jalankan dengan:
 *   php artisan test --filter=SportBookScenarioTest
 * atau
 *   vendor/bin/phpunit --filter=SportBookScenarioTest
 */
class SportBookScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Buat user dengan role tertentu
     */
    private function createUser(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /**
     * Buat field dengan harga tertentu
     */
    private function createField(array $overrides = []): Field
    {
        return Field::factory()->create(array_merge([
            'price_offpeak' => 100000,
            'price_peak'    => 120000,
        ], $overrides));
    }

    /**
     * Buat booking via HTTP request (melalui controller & service layer)
     */
    private function makeBookingRequest(
        User $user,
        Field $field,
        string $date,
        array $hours,
        string $paymentType = 'full'
    ) {
        return $this->actingAs($user)->post(
            route('user.booking.store', $field),
            [
                'booking_date' => $date,
                'hours'        => $hours,
                'payment_type' => $paymentType,
            ]
        );
    }

    /*
    |==========================================================================
    | BAGIAN A: INTEGRATION TESTING (TC-INT-01 s.d. TC-INT-25)
    |==========================================================================
    |
    | Menguji interaksi antar komponen:
    | Controller → Service → Model → Database
    |--------------------------------------------------------------------------
    */

    // =====================================================================
    // MODUL 1: BOOKING CREATION & PAYMENT (TC-INT-01 s.d. TC-INT-04)
    // Interaksi: BookingController → BookingService → Booking + BookingSlot → DB
    // =====================================================================

    /**
     * TC-INT-01 [PREDIKSI: FAIL / High]
     *
     * SKENARIO: Booking dengan opsi bayar "DP 50%"
     * KOMPONEN: BookingController::store() → BookingService::createBooking() → Booking::create()
     * RISIKO DIUJI: R1 (Mass-assignment) — payment_type & amount_paid TIDAK di $fillable
     *
     * BUG YANG DIPREDIKSI:
     * BookingService.php L45-58 memasukkan 'payment_type', 'amount_paid', 'remaining_payment'
     * ke Booking::create(), tapi Booking.php L13-26 $fillable TIDAK menyertakan kolom ini.
     * Akibatnya Eloquent secara diam-diam membuang data → payment_type=default('full'),
     * amount_paid=default(0), remaining_payment=default(0).
     */
    public function test_TC_INT_01_dp_payment_fields_are_persisted_correctly(): void
    {
        $user  = $this->createUser();
        $field = $this->createField(['price_offpeak' => 100000, 'price_peak' => 120000]);

        // Booking 1 jam off-peak di hari kerja → total = Rp100.000, DP = Rp50.000
        $weekday = Carbon::parse('next monday')->toDateString();
        $response = $this->makeBookingRequest($user, $field, $weekday, [10], 'dp');

        $response->assertSessionDoesntHaveErrors();

        // Verifikasi di database: payment_type harus 'dp', bukan default 'full'
        $this->assertDatabaseHas('bookings', [
            'user_id'      => $user->id,
            'payment_type' => 'dp',
        ]);

        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking, 'Booking harus berhasil dibuat.');
        $this->assertNotNull($booking->amount_paid, 'amount_paid seharusnya tidak null untuk metode DP.');
        $this->assertEquals(50000, $booking->amount_paid, 'DP 50% dari Rp100.000 harus = Rp50.000');
        $this->assertEquals(50000, $booking->remaining_payment, 'Sisa bayar harus = Rp50.000');
    }

    /**
     * TC-INT-02 [PREDIKSI: PASS]
     *
     * SKENARIO: Booking dengan opsi bayar "Full Payment"
     * KOMPONEN: BookingController::store() → BookingService::createBooking() → Booking::create()
     * RISIKO DIUJI: R1 (Mass-assignment) — verifikasi untuk pembayaran full
     *
     * Meskipun payment_type default='full', amount_paid tetap harus = total_price
     * (bukan default 0). Jika mass-assignment bug ada, amount_paid akan = 0.
     */
    public function test_TC_INT_02_full_payment_fields_are_persisted_correctly(): void
    {
        $user  = $this->createUser();
        $field = $this->createField(['price_offpeak' => 100000]);
        $weekday = Carbon::parse('next monday')->toDateString();

        $response = $this->makeBookingRequest($user, $field, $weekday, [10], 'full');
        $response->assertSessionDoesntHaveErrors();

        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking);
        $this->assertEquals('full', $booking->payment_type);
        $this->assertEquals(100000, $booking->amount_paid, 'Full payment harus = total_price');
        $this->assertEquals(0, $booking->remaining_payment, 'Sisa bayar harus = 0 untuk full payment');
    }

    /**
     * TC-INT-03 [PREDIKSI: FAIL / Critical]
     *
     * SKENARIO: Dua booking untuk slot & jam yang sama (race condition / double booking)
     * KOMPONEN: BookingService::createBooking() → BookingSlot → uk_slot constraint
     * RISIKO DIUJI: R2 (Race condition) — tidak ada DB::transaction atau lockForUpdate()
     *
     * PHPUnit tidak bisa paralel HTTP, tapi constraint uk_slot harus menolak yang kedua.
     * Yang diuji: apakah aplikasi menangani error dengan rapi (bukan 500/QueryException mentah).
     */
    public function test_TC_INT_03_double_booking_on_same_slot_is_rejected(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $field = $this->createField();
        $date  = now()->addDay()->toDateString();

        // Booking pertama harus berhasil
        $responseA = $this->makeBookingRequest($userA, $field, $date, [19]);
        $responseA->assertSessionDoesntHaveErrors();

        // Booking kedua untuk slot yang sama harus gagal
        $responseB = $this->makeBookingRequest($userB, $field, $date, [19]);

        // Percobaan kedua wajib gagal dengan pesan yang rapi, BUKAN error 500
        $responseB->assertSessionHasErrors();

        // Pastikan hanya ada 1 slot di database
        $this->assertEquals(
            1,
            BookingSlot::where('field_id', $field->id)
                ->where('slot_date', $date)
                ->where('slot_hour', 19)
                ->count(),
            'Hanya boleh ada 1 record slot untuk jam & lapangan yang sama.'
        );
    }

    /**
     * TC-INT-04 [PREDIKSI: PASS]
     *
     * SKENARIO: Booking multi-slot (beberapa jam sekaligus)
     * KOMPONEN: BookingController::store() → BookingService → BookingSlot (multiple INSERT)
     * RISIKO DIUJI: Konsistensi data multi-slot — semua slot harus tersimpan dengan benar
     */
    public function test_TC_INT_04_multi_slot_booking_creates_all_slots(): void
    {
        $user  = $this->createUser();
        $field = $this->createField();
        $date  = now()->addDay()->toDateString();

        $response = $this->makeBookingRequest($user, $field, $date, [10, 11, 12]);
        $response->assertSessionDoesntHaveErrors();

        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking);
        $this->assertEquals(3, $booking->duration_hours, 'Duration harus 3 jam');

        // Semua 3 slot harus ada di booking_slots
        $slotsCount = BookingSlot::where('booking_id', $booking->id)->count();
        $this->assertEquals(3, $slotsCount, 'Harus ada 3 record di booking_slots');

        // Verifikasi setiap jam tersimpan
        foreach ([10, 11, 12] as $hour) {
            $this->assertDatabaseHas('booking_slots', [
                'booking_id' => $booking->id,
                'field_id'   => $field->id,
                'slot_date'  => $date,
                'slot_hour'  => $hour,
            ]);
        }
    }

    // =====================================================================
    // MODUL 2: IDOR & OWNERSHIP CHECK (TC-INT-05 & TC-INT-06)
    // Interaksi: BookingController::show()/cancel() → abort_if(owner check)
    // =====================================================================

    /**
     * TC-INT-05 [PREDIKSI: PASS]
     *
     * SKENARIO: User A tidak boleh bisa membuka detail booking milik User B (IDOR)
     * KOMPONEN: BookingController::show() → abort_if($booking->user_id !== auth()->id(), 403)
     * RISIKO DIUJI: Authorization boundary — IDOR vulnerability
     */
    public function test_TC_INT_05_user_cannot_view_other_users_booking_detail(): void
    {
        $userA   = $this->createUser();
        $userB   = $this->createUser();
        $field   = $this->createField();
        $booking = Booking::factory()->create([
            'user_id'  => $userB->id,
            'field_id' => $field->id,
        ]);

        $response = $this->actingAs($userA)->get(
            route('user.bookings.show', $booking)
        );

        $response->assertForbidden(); // HTTP 403
    }

    /**
     * TC-INT-06 [PREDIKSI: PASS]
     *
     * SKENARIO: User A tidak boleh bisa membatalkan booking milik User B
     * KOMPONEN: BookingController::cancel() → abort_if(owner check)
     * RISIKO DIUJI: Authorization pada aksi destructive (cancel)
     */
    public function test_TC_INT_06_user_cannot_cancel_other_users_booking(): void
    {
        $userA   = $this->createUser();
        $userB   = $this->createUser();
        $field   = $this->createField();
        $booking = Booking::factory()->create([
            'user_id'  => $userB->id,
            'field_id' => $field->id,
            'status'   => 'menunggu_pembayaran',
        ]);

        $response = $this->actingAs($userA)->post(
            route('user.bookings.cancel', $booking)
        );

        $response->assertForbidden(); // HTTP 403

        // Status booking TIDAK boleh berubah
        $this->assertDatabaseHas('bookings', [
            'id'     => $booking->id,
            'status' => 'menunggu_pembayaran',
        ]);
    }

    // =====================================================================
    // MODUL 3: PRICING CALCULATION (TC-INT-07, TC-INT-08, TC-INT-09)
    // Interaksi: BookingService::createBooking() → Field pricing → DB
    // =====================================================================

    /**
     * TC-INT-07 [PREDIKSI: PASS]
     *
     * SKENARIO: Kalkulasi harga peak hour (jam ≥17)
     * KOMPONEN: BookingService::createBooking() → price calculation logic
     * RISIKO DIUJI: R7 (Price calculation correctness) — peak vs off-peak
     *
     * Jam 18 & 19 = peak hour → harga = price_peak per jam
     * Hari kerja → tanpa surcharge weekend
     * Expected: 2 × Rp120.000 = Rp240.000
     */
    public function test_TC_INT_07_peak_hour_pricing_is_calculated_correctly(): void
    {
        $user  = $this->createUser();
        $field = $this->createField([
            'price_offpeak' => 100000,
            'price_peak'    => 120000,
        ]);

        // Hari kerja (Senin) untuk isolasi dari weekend surcharge
        $monday = Carbon::parse('next monday')->toDateString();

        $response = $this->makeBookingRequest($user, $field, $monday, [18, 19]);
        $response->assertSessionDoesntHaveErrors();

        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking);

        // 2 jam × Rp120.000 (peak) × 1.0 (weekday) = Rp240.000
        $this->assertEquals(240000, $booking->total_price,
            'Peak hour weekday: 2 × 120.000 = 240.000');
    }

    /**
     * TC-INT-08 [PREDIKSI: PASS]
     *
     * SKENARIO: Kalkulasi surcharge weekend +20%
     * KOMPONEN: BookingService::createBooking() → weekend detection → price × 1.2
     * RISIKO DIUJI: R7 (Price calculation) — weekend multiplier
     *
     * 2 jam peak di Sabtu → 2 × Rp120.000 × 1.2 = Rp288.000
     */
    public function test_TC_INT_08_weekend_surcharge_is_applied_correctly(): void
    {
        $user  = $this->createUser();
        $field = $this->createField([
            'price_offpeak' => 100000,
            'price_peak'    => 120000,
        ]);

        $saturday = Carbon::parse('next saturday')->toDateString();

        $response = $this->makeBookingRequest($user, $field, $saturday, [18, 19]);
        $response->assertSessionDoesntHaveErrors();

        $booking = Booking::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($booking);

        // 2 jam × Rp120.000 × 1.2 (weekend) = Rp288.000
        $this->assertEquals(288000, $booking->total_price,
            'Peak + Weekend: 2 × 120.000 × 1.2 = 288.000');
    }

    /**
     * TC-INT-09 [PREDIKSI: PASS]
     *
     * SKENARIO: Kalkulasi harga off-peak pada hari kerja
     * KOMPONEN: BookingService → price calculation (jam < 17 = off-peak)
     * RISIKO DIUJI: R7 (Price calculation) — off-peak baseline
     *
     * 2 jam off-peak weekday → 2 × Rp100.000 = Rp200.000
     */
    public function test_TC_INT_09_offpeak_weekday_pricing_is_correct(): void
    {
        $user  = $this->createUser();
        $field = $this->createField([
            'price_offpeak' => 100000,
            'price_peak'    => 120000,
        ]);

        $monday = Carbon::parse('next monday')->toDateString();

        $response = $this->makeBookingRequest($user, $field, $monday, [10, 11]);
        $response->assertSessionDoesntHaveErrors();

        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking);

        // 2 jam × Rp100.000 (off-peak) × 1.0 (weekday) = Rp200.000
        $this->assertEquals(200000, $booking->total_price,
            'Off-peak weekday: 2 × 100.000 = 200.000');
    }

    // =====================================================================
    // MODUL 4: BOOKING CANCELLATION & REFUND (TC-INT-10 s.d. TC-INT-14)
    // Interaksi: BookingController::cancel() → BookingService::cancelBooking() → DB
    // =====================================================================

    /**
     * TC-INT-10 [PREDIKSI: PASS]
     *
     * SKENARIO: Pembatalan booking > 24 jam sebelum jadwal → refund 100%
     * KOMPONEN: BookingController::cancel() → BookingService::cancelBooking()
     * RISIKO DIUJI: R9 (Refund calculation) — kebijakan refund 100%
     *
     * Booking 3 hari ke depan, cancel sekarang → > 24 jam → refund 100%
     */
    public function test_TC_INT_10_cancel_more_than_24h_gives_full_refund(): void
    {
        $user  = $this->createUser();
        $field = $this->createField();

        // Buat booking 3 hari ke depan
        $bookingDate = now()->addDays(3)->toDateString();
        $this->makeBookingRequest($user, $field, $bookingDate, [10], 'full');

        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking);

        // Cancel booking
        $response = $this->actingAs($user)->post(
            route('user.bookings.cancel', $booking)
        );

        $booking->refresh();
        $this->assertEquals('dibatalkan', $booking->status, 'Status harus berubah ke dibatalkan');
        $this->assertEquals(
            $booking->amount_paid,
            $booking->refund_amount,
            'Refund 100% karena > 24 jam sebelum jadwal'
        );
    }

    /**
     * TC-INT-11 [PREDIKSI: PASS]
     *
     * SKENARIO: Pembatalan booking 12-24 jam sebelum jadwal → refund 50%
     * KOMPONEN: BookingController::cancel() → BookingService::cancelBooking()
     * RISIKO DIUJI: R9 (Refund calculation) — kebijakan refund 50%
     */
    public function test_TC_INT_11_cancel_between_12_and_24h_gives_half_refund(): void
    {
        $user  = $this->createUser();
        $field = $this->createField(['price_offpeak' => 100000]);

        // Atur waktu supaya booking besok jam 10 = 18 jam dari sekarang
        $bookingDate = now()->addDay()->toDateString();

        $this->makeBookingRequest($user, $field, $bookingDate, [10], 'full');
        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking);

        // Manipulasi waktu: set "sekarang" = 18 jam sebelum slot jam 10
        // Berarti sekarang = besok jam 10 - 18 jam = hari ini jam 16
        Carbon::setTestNow(Carbon::parse($bookingDate . ' 10:00')->subHours(18));

        $response = $this->actingAs($user)->post(
            route('user.bookings.cancel', $booking)
        );

        $booking->refresh();
        $this->assertEquals('dibatalkan', $booking->status);

        // 18 jam = antara 12-24 jam → refund 50%
        if ($booking->amount_paid > 0) {
            $expectedRefund = $booking->amount_paid * 0.5;
            $this->assertEquals($expectedRefund, $booking->refund_amount,
                'Refund 50% karena pembatalan 12-24 jam sebelum jadwal');
        }

        Carbon::setTestNow(); // Reset waktu
    }

    /**
     * TC-INT-12 [PREDIKSI: PASS]
     *
     * SKENARIO: Pembatalan booking < 12 jam sebelum jadwal → refund 0%
     * KOMPONEN: BookingController::cancel() → BookingService::cancelBooking()
     * RISIKO DIUJI: R9 (Refund calculation) — kebijakan tanpa refund
     */
    public function test_TC_INT_12_cancel_less_than_12h_gives_no_refund(): void
    {
        $user  = $this->createUser();
        $field = $this->createField();
        $bookingDate = now()->addDay()->toDateString();

        $this->makeBookingRequest($user, $field, $bookingDate, [10], 'full');
        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking);

        // Set "sekarang" = 6 jam sebelum slot jam 10 = hari itu jam 04:00
        Carbon::setTestNow(Carbon::parse($bookingDate . ' 10:00')->subHours(6));

        $response = $this->actingAs($user)->post(
            route('user.bookings.cancel', $booking)
        );

        $booking->refresh();
        $this->assertEquals('dibatalkan', $booking->status);
        $this->assertEquals(0, $booking->refund_amount,
            'Tidak ada refund karena < 12 jam sebelum jadwal');

        Carbon::setTestNow(); // Reset
    }

    /**
     * TC-INT-13 [PREDIKSI: FAIL / High]
     *
     * SKENARIO: Slot yang sudah dibatalkan seharusnya bisa dipesan ulang
     * KOMPONEN: BookingService::cancelBooking() → BookingSlot → uk_slot constraint
     * RISIKO DIUJI: R3 (Cancelled slots block future bookings)
     *
     * BUG YANG DIPREDIKSI:
     * cancelBooking() di BookingService.php L106-109 hanya mengupdate status='dibatalkan'
     * tapi TIDAK menghapus record di booking_slots. Karena uk_slot unique constraint
     * masih berlaku, booking baru untuk slot yang sama akan ditolak oleh database.
     */
    public function test_TC_INT_13_cancelled_slot_can_be_rebooked_by_another_user(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $field = $this->createField();
        $date  = now()->addDays(3)->toDateString();

        // User A booking slot jam 10
        $this->makeBookingRequest($userA, $field, $date, [10]);
        $bookingA = Booking::where('user_id', $userA->id)->first();
        $this->assertNotNull($bookingA);

        // User A cancel booking
        $this->actingAs($userA)->post(route('user.bookings.cancel', $bookingA));
        $bookingA->refresh();
        $this->assertEquals('dibatalkan', $bookingA->status);

        // User B coba booking slot yang sama → SEHARUSNYA berhasil
        $responseB = $this->makeBookingRequest($userB, $field, $date, [10]);

        // Ini yang membuktikan/menggugurkan prediksi TC-INT-13
        $responseB->assertSessionDoesntHaveErrors();
    }

    /**
     * TC-INT-14 [PREDIKSI: PASS]
     *
     * SKENARIO: Booking dengan status 'selesai' tidak boleh bisa dibatalkan
     * KOMPONEN: BookingController::cancel() → status check
     * RISIKO DIUJI: Business rule — hanya status 'menunggu_pembayaran' dan 'terkonfirmasi' yang bisa dicancel
     */
    public function test_TC_INT_14_completed_booking_cannot_be_cancelled(): void
    {
        $user    = $this->createUser();
        $field   = $this->createField();
        $booking = Booking::factory()->create([
            'user_id'  => $user->id,
            'field_id' => $field->id,
            'status'   => 'selesai',
        ]);

        $response = $this->actingAs($user)->post(
            route('user.bookings.cancel', $booking)
        );

        // Harus ada pesan error atau redirect dengan error
        $booking->refresh();
        $this->assertEquals('selesai', $booking->status,
            'Status booking selesai tidak boleh berubah setelah cancel attempt');
    }

    // =====================================================================
    // MODUL 5: PAYMENT UPLOAD (TC-INT-15 & TC-INT-16)
    // Interaksi: BookingController::uploadPayment() → File storage → DB
    // =====================================================================

    /**
     * TC-INT-15 [PREDIKSI: PASS]
     *
     * SKENARIO: Upload bukti pembayaran dengan file gambar valid
     * KOMPONEN: BookingController::uploadPayment() → file store → booking update
     * RISIKO DIUJI: File upload flow dan database update
     */
    public function test_TC_INT_15_valid_payment_proof_upload_succeeds(): void
    {
        Storage::fake('public');

        $user    = $this->createUser();
        $field   = $this->createField();
        $booking = Booking::factory()->create([
            'user_id'  => $user->id,
            'field_id' => $field->id,
            'status'   => 'menunggu_pembayaran',
        ]);

        $file = UploadedFile::fake()->image('bukti_bayar.jpg', 800, 600);

        $response = $this->actingAs($user)->post(
            route('user.bookings.payment', $booking),
            ['payment_proof' => $file]
        );

        $response->assertSessionDoesntHaveErrors();

        $booking->refresh();
        $this->assertNotNull($booking->payment_proof,
            'Path bukti pembayaran harus tersimpan di database');

        // File harus ada di storage
        Storage::disk('public')->assertExists($booking->payment_proof);
    }

    /**
     * TC-INT-16 [PREDIKSI: PASS]
     *
     * SKENARIO: Upload bukti pembayaran dengan file non-gambar (PDF) harus ditolak
     * KOMPONEN: BookingController::uploadPayment() → validation
     * RISIKO DIUJI: Input validation — hanya menerima jpg, jpeg, png, webp
     */
    public function test_TC_INT_16_invalid_file_type_for_payment_proof_is_rejected(): void
    {
        Storage::fake('public');

        $user    = $this->createUser();
        $field   = $this->createField();
        $booking = Booking::factory()->create([
            'user_id'  => $user->id,
            'field_id' => $field->id,
            'status'   => 'menunggu_pembayaran',
        ]);

        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAs($user)->post(
            route('user.bookings.payment', $booking),
            ['payment_proof' => $file]
        );

        $response->assertSessionHasErrors('payment_proof');

        $booking->refresh();
        $this->assertNull($booking->payment_proof,
            'Payment proof harus tetap null jika file invalid');
    }

    // =====================================================================
    // MODUL 6: INPUT VALIDATION (TC-INT-17 & TC-INT-18)
    // Interaksi: BookingController::store() → Request validation → Error response
    // =====================================================================

    /**
     * TC-INT-17 [PREDIKSI: PASS]
     *
     * SKENARIO: Booking dengan tanggal di masa lalu harus ditolak
     * KOMPONEN: BookingController::store() → validation rule 'after_or_equal:today'
     * RISIKO DIUJI: Validasi tanggal — tidak boleh booking untuk tanggal kemarin
     */
    public function test_TC_INT_17_booking_with_past_date_is_rejected(): void
    {
        $user  = $this->createUser();
        $field = $this->createField();

        $response = $this->makeBookingRequest(
            $user, $field,
            now()->subDay()->toDateString(), // kemarin
            [10]
        );

        $response->assertSessionHasErrors('booking_date');
    }

    /**
     * TC-INT-18 [PREDIKSI: PASS]
     *
     * SKENARIO: Booking tanpa memilih jam sama sekali harus ditolak
     * KOMPONEN: BookingController::store() → validation rule 'required|array|min:1'
     * RISIKO DIUJI: Validasi input — hours wajib diisi minimal 1
     */
    public function test_TC_INT_18_booking_without_hours_is_rejected(): void
    {
        $user  = $this->createUser();
        $field = $this->createField();

        $response = $this->actingAs($user)->post(
            route('user.booking.store', $field),
            [
                'booking_date' => now()->addDay()->toDateString(),
                'hours'        => [],
                'payment_type' => 'full',
            ]
        );

        $response->assertSessionHasErrors('hours');
    }

    // =====================================================================
    // MODUL 7: ROLE-BASED ACCESS CONTROL (TC-INT-19 s.d. TC-INT-21)
    // Interaksi: RoleMiddleware → Controller → Route
    // =====================================================================

    /**
     * TC-INT-19 [PREDIKSI: PASS (redirect, bukan 403)]
     *
     * SKENARIO: User biasa mencoba akses route admin (field-types)
     * KOMPONEN: RoleMiddleware → admin routes
     * RISIKO DIUJI: Authorization boundary — role:admin middleware
     *
     * CATATAN: RoleMiddleware.php me-redirect ke user.dashboard (bukan abort 403)
     */
    public function test_TC_INT_19_regular_user_is_blocked_from_admin_field_types_route(): void
    {
        $user = $this->createUser('user');

        $response = $this->actingAs($user)->get(route('admin.field-types.index'));

        // RoleMiddleware melakukan redirect, bukan abort(403)
        $response->assertRedirect(route('user.dashboard'));
    }

    /**
     * TC-INT-20 [PREDIKSI: PASS (redirect)]
     *
     * SKENARIO: User biasa mencoba akses admin dashboard
     * KOMPONEN: RoleMiddleware → admin dashboard route
     * RISIKO DIUJI: Authorization boundary pada halaman dashboard admin
     */
    public function test_TC_INT_20_regular_user_is_blocked_from_admin_dashboard(): void
    {
        $user = $this->createUser('user');

        $response = $this->actingAs($user)->get(route('admin.dashboard'));

        $response->assertRedirect(route('user.dashboard'));
    }

    /**
     * TC-INT-21 [PREDIKSI: PASS (redirect)]
     *
     * SKENARIO: Admin mencoba akses route user (booking create)
     * KOMPONEN: RoleMiddleware → user routes
     * RISIKO DIUJI: Reverse authorization — admin tidak boleh masuk route user
     */
    public function test_TC_INT_21_admin_is_blocked_from_user_booking_route(): void
    {
        $admin = $this->createUser('admin');
        $field = $this->createField();

        $response = $this->actingAs($admin)->get(
            route('user.booking.create', $field)
        );

        $response->assertRedirect(route('admin.dashboard'));
    }

    // =====================================================================
    // MODUL 8: ADMIN OPERATIONS (TC-INT-22 & TC-INT-23)
    // Interaksi: Admin Controllers → Models → DB
    // =====================================================================

    /**
     * TC-INT-22 [PREDIKSI: PASS]
     *
     * SKENARIO: Admin mengubah status booking dari 'menunggu_pembayaran' ke 'terkonfirmasi'
     * KOMPONEN: Admin\BookingController::update() → Booking model → DB
     * RISIKO DIUJI: R5 (Admin status transition) — apakah status benar-benar berubah
     */
    public function test_TC_INT_22_admin_can_update_booking_status(): void
    {
        $admin   = $this->createUser('admin');
        $user    = $this->createUser();
        $field   = $this->createField();
        $booking = Booking::factory()->create([
            'user_id'  => $user->id,
            'field_id' => $field->id,
            'status'   => 'menunggu_pembayaran',
        ]);

        $response = $this->actingAs($admin)->patch(
            route('admin.bookings.update', $booking),
            ['status' => 'terkonfirmasi']
        );

        $response->assertSessionDoesntHaveErrors();

        $booking->refresh();
        $this->assertEquals('terkonfirmasi', $booking->status,
            'Status booking harus berubah ke terkonfirmasi');
    }

    /**
     * TC-INT-23 [PREDIKSI: PASS — status transitions tanpa business rules]
     *
     * SKENARIO: Admin set status langsung ke 'selesai' tanpa melewati 'terkonfirmasi'
     * KOMPONEN: Admin\BookingController::update() — validasi hanya cek enum, tanpa state machine
     * RISIKO DIUJI: R5 (Unvalidated status transition) — admin bisa lompat status
     *
     * Test ini MENDOKUMENTASIKAN bahwa tidak ada state machine validation.
     * Jika berhasil (PASS), itu berarti bug R5 terkonfirmasi — status bisa dilompat.
     */
    public function test_TC_INT_23_admin_can_skip_status_without_state_machine(): void
    {
        $admin   = $this->createUser('admin');
        $user    = $this->createUser();
        $field   = $this->createField();
        $booking = Booking::factory()->create([
            'user_id'  => $user->id,
            'field_id' => $field->id,
            'status'   => 'menunggu_pembayaran',
        ]);

        // Langsung set ke 'selesai' tanpa melewati 'terkonfirmasi'
        $response = $this->actingAs($admin)->patch(
            route('admin.bookings.update', $booking),
            ['status' => 'selesai']
        );

        $booking->refresh();

        // Jika PASS = bug R5 terkonfirmasi (tidak ada state machine)
        // Jika FAIL = ada state machine validation yang kita tidak tahu
        $this->assertEquals('selesai', $booking->status,
            'Tanpa state machine, admin bisa langsung set status ke selesai');
    }

    // =====================================================================
    // MODUL 9: REGISTRATION SECURITY (TC-INT-24)
    // Interaksi: RegisteredUserController → User model → DB
    // =====================================================================

    /**
     * TC-INT-24 [PREDIKSI: PASS]
     *
     * SKENARIO: Guest tidak boleh bisa mendaftar sebagai admin lewat manipulasi parameter role
     * KOMPONEN: RegisteredUserController::store() → User::create(['role' => 'user'])
     * RISIKO DIUJI: Privilege escalation — parameter 'role' pada form register
     *
     * BUKTI KEAMANAN: RegisteredUserController.php L35 hardcode 'role' => 'user'
     */
    public function test_TC_INT_24_guest_cannot_self_register_as_admin(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'Percobaan Elevasi',
            'email'                 => 'percobaan@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'admin', // parameter yang dicoba disisipkan
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'percobaan@example.com',
            'role'  => 'user', // wajib tetap 'user', bukan 'admin'
        ]);
    }

    // =====================================================================
    // MODUL 10: GUEST ACCESS & FIELD MANAGEMENT (TC-INT-25)
    // Interaksi: Auth middleware → redirect → login
    // =====================================================================

    /**
     * TC-INT-25 [PREDIKSI: PASS]
     *
     * SKENARIO: Guest (tidak login) mencoba akses halaman booking
     * KOMPONEN: auth middleware → redirect ke login
     * RISIKO DIUJI: Unauthenticated access — semua route booking harus dilindungi
     */
    public function test_TC_INT_25_guest_cannot_access_booking_page(): void
    {
        $field = $this->createField();

        $response = $this->get(route('user.booking.create', $field));

        $response->assertRedirect(route('login'));
    }

    /*
    |==========================================================================
    | BAGIAN B: REGRESSION TESTING (TC-REG-01 s.d. TC-REG-10)
    |==========================================================================
    |
    | Memastikan fitur yang sebelumnya berjalan tidak rusak akibat perubahan,
    | khususnya setelah penambahan migration payment fields.
    |--------------------------------------------------------------------------
    */

    /**
     * TC-REG-01 [Regresi: Mass-Assignment setelah penambahan payment fields]
     *
     * KONTEKS: Migration 2026_04_23_064441_add_payment_fields_to_bookings_table.php
     * menambahkan kolom payment_type, amount_paid, remaining_payment ke tabel bookings,
     * tapi $fillable di Booking model TIDAK diupdate.
     *
     * Test ini memastikan bahwa data payment tersimpan dengan benar setelah booking.
     * Merupakan regresi karena fitur booking dasar sudah ada sebelumnya, dan penambahan
     * fitur payment memperkenalkan bug baru.
     */
    public function test_TC_REG_01_booking_creation_still_works_after_payment_migration(): void
    {
        $user  = $this->createUser();
        $field = $this->createField(['price_offpeak' => 80000]);
        $date  = Carbon::parse('next monday')->toDateString();

        $response = $this->makeBookingRequest($user, $field, $date, [10], 'full');
        $response->assertSessionDoesntHaveErrors();

        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking, 'Booking harus berhasil dibuat');

        // Kolom-kolom inti yang sudah ada sebelum payment migration
        $this->assertNotNull($booking->booking_code, 'booking_code harus terisi');
        $this->assertEquals($field->id, $booking->field_id);
        $this->assertEquals($date, $booking->booking_date->toDateString());
        $this->assertEquals(80000, $booking->total_price);
        $this->assertEquals('menunggu_pembayaran', $booking->status);
    }

    /**
     * TC-REG-02 [Regresi: Slot creation setelah perubahan payment flow]
     *
     * Memastikan BookingSlot tetap dibuat dengan benar setelah penambahan logic payment.
     * Regresi bisa terjadi jika error di payment menyebabkan slot tidak dibuat (no transaction).
     */
    public function test_TC_REG_02_booking_slots_are_created_correctly_after_changes(): void
    {
        $user  = $this->createUser();
        $field = $this->createField();
        $date  = now()->addDay()->toDateString();

        $this->makeBookingRequest($user, $field, $date, [8, 9, 10]);

        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking);

        // Verifikasi slot tersimpan dengan harga yang benar
        $slots = BookingSlot::where('booking_id', $booking->id)->get();
        $this->assertCount(3, $slots, 'Harus ada 3 slot');

        foreach ($slots as $slot) {
            $this->assertEquals($field->id, $slot->field_id);
            $this->assertEquals($date, $slot->slot_date);
            $this->assertGreaterThan(0, $slot->price, 'Harga slot harus > 0');
        }
    }

    /**
     * TC-REG-03 [Regresi: Landing page tetap bisa diakses]
     *
     * Landing page (route '/') harus tetap bisa diakses oleh public
     * setelah semua perubahan middleware dan route.
     */
    public function test_TC_REG_03_landing_page_is_still_accessible(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * TC-REG-04 [Regresi: Login flow tetap berfungsi]
     *
     * Memastikan user masih bisa login setelah perubahan role middleware
     * dan penambahan route-route baru.
     */
    public function test_TC_REG_04_login_flow_still_works(): void
    {
        $user = User::factory()->create([
            'role'     => 'user',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(); // redirect ke dashboard
    }

    /**
     * TC-REG-05 [Regresi: Registration flow tetap berfungsi]
     *
     * Memastikan user baru masih bisa mendaftar dan otomatis login.
     */
    public function test_TC_REG_05_registration_flow_still_works(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'User Baru',
            'email'                 => 'userbaru@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'userbaru@example.com',
            'role'  => 'user',
        ]);
    }

    /**
     * TC-REG-06 [Regresi: Field catalog tetap menampilkan field aktif saja]
     *
     * Setelah penambahan fitur-fitur baru, katalog field harus tetap
     * hanya menampilkan field yang aktif (is_active = true).
     */
    public function test_TC_REG_06_field_catalog_only_shows_active_fields(): void
    {
        $user = $this->createUser();

        $activeField   = $this->createField(['name' => 'Lapangan Aktif', 'is_active' => true]);
        $inactiveField = $this->createField(['name' => 'Lapangan Nonaktif', 'is_active' => false]);

        $response = $this->actingAs($user)->get(route('user.fields.index'));

        $response->assertStatus(200);
        $response->assertSee('Lapangan Aktif');
        $response->assertDontSee('Lapangan Nonaktif');
    }

    /**
     * TC-REG-07 [Regresi: Admin CRUD field type masih berfungsi]
     *
     * Memastikan admin masih bisa membuat field type baru setelah semua perubahan.
     */
    public function test_TC_REG_07_admin_can_still_create_field_types(): void
    {
        $admin = $this->createUser('admin');

        $response = $this->actingAs($admin)->post(
            route('admin.field-types.store'),
            ['name' => 'Tenis Meja']
        );

        $response->assertRedirect(route('admin.field-types.index'));
        $this->assertDatabaseHas('field_types', ['name' => 'Tenis Meja']);
    }

    /**
     * TC-REG-08 [Regresi: Admin CRUD field masih berfungsi]
     *
     * Memastikan admin masih bisa membuat field baru termasuk validasi harga.
     */
    public function test_TC_REG_08_admin_can_still_create_fields(): void
    {
        $admin     = $this->createUser('admin');
        $fieldType = FieldType::factory()->create();

        $response = $this->actingAs($admin)->post(
            route('admin.fields.store'),
            [
                'field_type_id' => $fieldType->id,
                'name'          => 'Lapangan Baru Test',
                'price_offpeak' => 75000,
                'price_peak'    => 100000,
                'description'   => 'Deskripsi lapangan baru untuk testing',
                'is_active'     => true,
            ]
        );

        $response->assertRedirect(route('admin.fields.index'));
        $this->assertDatabaseHas('fields', [
            'name'          => 'Lapangan Baru Test',
            'price_offpeak' => 75000,
            'price_peak'    => 100000,
        ]);
    }

    /**
     * TC-REG-09 [Regresi: Booking list user tetap menampilkan booking milik sendiri]
     *
     * Setelah perubahan, halaman my-bookings harus tetap hanya menampilkan
     * booking milik user yang sedang login.
     */
    public function test_TC_REG_09_user_booking_list_shows_own_bookings_only(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $field = $this->createField();

        $bookingA = Booking::factory()->create([
            'user_id'      => $userA->id,
            'field_id'     => $field->id,
            'booking_code' => 'SB-AAAAAAAA',
        ]);
        $bookingB = Booking::factory()->create([
            'user_id'      => $userB->id,
            'field_id'     => $field->id,
            'booking_code' => 'SB-BBBBBBBB',
        ]);

        $response = $this->actingAs($userA)->get(route('user.bookings.index'));

        $response->assertStatus(200);
        $response->assertSee('SB-AAAAAAAA');
        $response->assertDontSee('SB-BBBBBBBB');
    }

    /**
     * TC-REG-10 [Regresi: Inactive field tidak bisa dibooking]
     *
     * Field yang dinonaktifkan (is_active=false) seharusnya tidak bisa diakses
     * untuk booking. Ini harus tetap berfungsi setelah perubahan.
     */
    public function test_TC_REG_10_inactive_field_cannot_be_booked(): void
    {
        $user  = $this->createUser();
        $field = $this->createField(['is_active' => false]);

        $response = $this->actingAs($user)->get(
            route('user.booking.create', $field)
        );

        // Controller memanggil abort_if(!$field->is_active, 404)
        $response->assertNotFound(); // HTTP 404
    }
}
