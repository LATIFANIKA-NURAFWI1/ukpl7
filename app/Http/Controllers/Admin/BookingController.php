<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookingService) {}

    public function index(Request $request)
    {
        $query = Booking::with(['user', 'field.fieldType']);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('booking_code', 'like', '%' . $request->search . '%')
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%' . $request->search . '%'));
            });
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->date) {
            $query->whereDate('booking_date', $request->date);
        }
        if ($request->field_id) {
            $query->where('field_id', $request->field_id);
        }

        $bookings = $query->latest()->paginate(15)->withQueryString();

        return view('admin.bookings.index', compact('bookings'));
    }

    public function show(Booking $booking)
    {
        $booking->load(['user', 'field.fieldType', 'slots']);

        return view('admin.bookings.show', compact('booking'));
    }

    public function update(Request $request, Booking $booking)
    {
        $request->validate([
            'status' => 'required|in:menunggu_pembayaran,terkonfirmasi,dibatalkan,selesai,refund',
        ], [
            'status.required' => 'Status booking wajib dipilih.',
            'status.in'       => 'Status booking tidak valid.',
        ]);

        $newStatus = $request->status;

        if ($newStatus === $booking->status) {
            return back()->withErrors([
                'status' => "Status booking sudah {$booking->statusLabel()}.",
            ]);
        }

        if (!$booking->canTransitionTo($newStatus)) {
            return back()->withErrors([
                'status' => sprintf(
                    'Perubahan status dari %s ke %s tidak diizinkan.',
                    $booking->statusLabel(),
                    Booking::labelForStatus($newStatus)
                ),
            ]);
        }

        try {
            if ($newStatus === 'dibatalkan') {
                $this->bookingService->cancelBooking($booking, 'dibatalkan');

                return back()->with('success', 'Booking dibatalkan, slot dilepas, dan refund dihitung.');
            }

            if ($newStatus === 'refund') {
                if ($booking->status === 'terkonfirmasi') {
                    $this->bookingService->cancelBooking($booking, 'refund');
                } else {
                    DB::transaction(function () use ($booking) {
                        $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
                        if ($locked->status !== 'dibatalkan') {
                            throw new \DomainException('Status booking berubah sebelum refund diproses.');
                        }
                        $locked->slots()->delete();
                        $locked->update(['status' => 'refund']);
                    });
                }

                return back()->with('success', 'Status booking berhasil diperbarui ke Refund.');
            }

            $booking->update(['status' => $newStatus]);

            return back()->with('success', 'Status booking berhasil diperbarui!');
        } catch (\DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['status' => 'Gagal memperbarui status booking. Silakan coba lagi.']);
        }
    }
}
