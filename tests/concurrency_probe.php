<?php
/**
 * concurrency_probe.php — Pengujian konkurensi lapisan service/database SportBook.
 *
 * Dipakai oleh Laporan Uji Kualitas: 10 proses PHP CLI paralel memanggil
 * BookingService::createBooking() pada slot & tanggal yang sama untuk
 * memverifikasi perlindungan double-booking (constraint uk_slot + transaksi DB).
 *
 * Pemakaian: php concurrency_probe.php <user_id> <field_id> <Y-m-d> <jam> [payment_type]
 */

$repo = dirname(__DIR__); // akar proyek (berkas ini ada di tests/)

require $repo . '/vendor/autoload.php';
$app = require_once $repo . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$userId   = (int) ($argv[1] ?? 0);
$fieldId  = (int) ($argv[2] ?? 0);
$date     = $argv[3] ?? date('Y-m-d', strtotime('+5 days'));
$hour     = (int) ($argv[4] ?? 15);
$payType  = $argv[5] ?? 'full';

$t0 = microtime(true);

/** @var App\Services\BookingService $svc */
$svc   = $app->make(App\Services\BookingService::class);
$field = App\Models\Field::findOrFail($fieldId);

try {
    $booking = $svc->createBooking([
        'user_id'      => $userId,
        'booking_date' => $date,
        'hours'        => [$hour],
        'payment_type' => $payType,
    ], $field);

    printf(
        "USER=%d STATUS=OK CODE=%s DURASI=%.3fs SLOT_DIBUAT=%d\n",
        $userId,
        $booking->booking_code,
        microtime(true) - $t0,
        $booking->slots()->count()
    );
} catch (\Throwable $e) {
    printf(
        "USER=%d STATUS=REJECT KELAS=%s PESAN=%s DURASI=%.3fs\n",
        $userId,
        get_class($e),
        str_replace(["\r", "\n"], ' ', $e->getMessage()),
        microtime(true) - $t0
    );
}
