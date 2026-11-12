<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Field;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookingService) {}

    public function index(Request $request)
    {
        $query = auth()->user()->bookings()->with('field.fieldType');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $bookings = $query->latest()->paginate(10)->withQueryString();

        return view('user.bookings.index', compact('bookings'));
    }

    public function create(Field $field, Request $request)
    {
        abort_if(!$field->is_active, 404);

        $date = $request->date ?? now()->format('Y-m-d');
        $bookedSlots = $this->bookingService->getBookedSlots($field->id, $date);

        $pastSlots = [];
        $dateStr = Carbon::parse($date)->toDateString();
        for ($h = 8; $h <= 21; $h++) {
            if (Carbon::parse($dateStr . ' ' . sprintf('%02d:00:00', $h))->lte(now())) {
                $pastSlots[] = $h;
            }
        }

        return view('user.bookings.create', compact('field', 'date', 'bookedSlots', 'pastSlots'));
    }

    public function store(Request $request, Field $field)
    {
        abort_if(!$field->is_active, 404);

        $request->validate([
            'booking_date' => 'required|date|after_or_equal:today',
            'hours'        => 'required|array|min:1|max:14',
            'hours.*'      => 'integer|min:8|max:21',
            'payment_type' => 'required|in:full,dp',
        ], [
            'booking_date.required'       => 'Tanggal booking wajib diisi.',
            'booking_date.date'           => 'Format tanggal booking tidak valid.',
            'booking_date.after_or_equal' => 'Tanggal booking tidak boleh di masa lalu.',
            'hours.required'              => 'Silakan pilih minimal 1 slot jam.',
            'hours.array'                 => 'Format slot jam tidak valid.',
            'hours.min'                   => 'Silakan pilih minimal 1 slot jam.',
            'hours.*.integer'             => 'Slot jam harus berupa angka.',
            'hours.*.min'                 => 'Slot jam minimal pukul 08:00.',
            'hours.*.max'                 => 'Slot jam maksimal pukul 21:00.',
            'payment_type.required'       => 'Metode pembayaran wajib dipilih.',
            'payment_type.in'             => 'Metode pembayaran tidak valid.',
        ]);

        $hours = $this->bookingService->normalizeHours($request->hours);

        if (!$this->areSlotsContiguous($hours)) {
            return back()
                ->withErrors(['hours' => 'Slot jam yang dipilih harus berurutan tanpa jeda.'])
                ->withInput();
        }

        if ($this->bookingService->hasPastSlots($request->booking_date, $hours)) {
            return back()
                ->withErrors(['hours' => 'Jam yang dipilih sudah lewat. Silakan pilih jam yang masih tersedia.'])
                ->withInput();
        }

        try {
            $booking = $this->bookingService->createBooking([
                'user_id'      => auth()->id(),
                'booking_date' => $request->booking_date,
                'hours'        => $hours,
                'payment_type' => $request->payment_type,
            ], $field);

            return redirect()->route('user.bookings.show', $booking)
                ->with('success', 'Booking berhasil dibuat! Silakan bayar sesuai nominal.');
        } catch (\DomainException $e) {
            return back()->withErrors(['hours' => $e->getMessage()])->withInput();
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? $e->getCode();
            $message = (string) $sqlState === '23000'
                ? 'Slot sudah dipesan, silakan pilih jam lain.'
                : 'Terjadi kesalahan saat menyimpan booking. Silakan coba lagi.';

            return back()->withErrors(['hours' => $message])->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withErrors(['hours' => 'Terjadi kesalahan saat memproses booking. Silakan coba lagi.'])
                ->withInput();
        }
    }

    public function areSlotsContiguous(array $hours): bool
    {
        return $this->bookingService->areSlotsContiguous($hours);
    }

    public function show(Booking $booking)
    {
        abort_if($booking->user_id !== auth()->id(), 403);
        $booking->load('field.fieldType', 'slots');

        return view('user.bookings.show', compact('booking'));
    }

    public function uploadPayment(Request $request, Booking $booking)
    {
        abort_if($booking->user_id !== auth()->id(), 403);
        abort_if($booking->status !== 'menunggu_pembayaran', 422);

        $request->validate([
            'payment_proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'payment_proof.required' => 'File bukti pembayaran wajib diunggah.',
            'payment_proof.image'    => 'Bukti pembayaran harus berupa file gambar.',
            'payment_proof.mimes'    => 'Format bukti pembayaran harus JPG, JPEG, PNG, atau WEBP.',
            'payment_proof.max'      => 'Ukuran bukti pembayaran maksimal 2 MB.',
        ]);

        $path = $request->file('payment_proof')->store('payments', 'public');

        $booking->update(['payment_proof' => $path]);

        return back()->with('success', 'Bukti pembayaran berhasil diupload! Menunggu konfirmasi admin.');
    }

    public function cancel(Request $request, Booking $booking)
    {
        abort_if($booking->user_id !== auth()->id(), 403);

        if (!in_array($booking->status, ['menunggu_pembayaran', 'terkonfirmasi'], true)) {
            return back()->with('error', 'Booking ini tidak dapat dibatalkan.');
        }

        try {
            $booking = $this->bookingService->cancelBooking($booking);

            $msg = 'Booking berhasil dibatalkan.';
            if ($booking->refund_amount > 0) {
                $msg .= ' Refund sebesar Rp ' . number_format($booking->refund_amount, 0, ',', '.') . ' akan diproses.';
            } else {
                $msg .= ' Tidak ada refund karena pembatalan kurang dari 12 jam.';
            }

            return back()->with('success', $msg);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal membatalkan booking. Silakan coba lagi.');
        }
    }
}
