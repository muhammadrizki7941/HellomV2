<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Services\Landing\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Seller › Jadwal: booked and held times of the shop's rental products in a date range. */
class SellerBookingController extends BaseApiController
{
    use ResolvesSellerOrganization;

    public function index(Request $request, BookingService $bookings): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $validated = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        try {
            $items = $bookings->forSeller((int) $organization->id, $validated['from'], $validated['to']);
        } catch (ValidationException $e) {
            return $this->fail($e->validator->errors()->first(), ['code' => 'BOOKING_RANGE_INVALID'], 422);
        }

        return $this->ok(['items' => $items], 'Jadwal');
    }
}
