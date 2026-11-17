<?php
/**
 * SmartBus - Atomic Booking & Concurrency Engine
 */

namespace App\Services;

use App\Core\Database;
use App\Models\Trip;
use App\Models\Coupon;
use PDO;
use Exception;

class BookingService
{
    /**
     * Create an initial booking with row locking to prevent race conditions
     */
    public static function createBooking(
        int $userId,
        int $tripId,
        array $selectedSeats,
        array $passengers,
        int $boardingStopId,
        int $droppingStopId,
        ?string $couponCode = null
    ): array {
        if (empty($selectedSeats)) {
            throw new Exception("Please select at least one seat.");
        }

        if (count($selectedSeats) > 6) {
            throw new Exception("You may select a maximum of 6 seats per booking.");
        }

        if (count($selectedSeats) !== count($passengers)) {
            throw new Exception("Passenger details must be provided for every selected seat.");
        }

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // 0. Release any expired PENDING reservations for this trip first
            $db->prepare("
                UPDATE trip_seats
                SET status = 'available'
                WHERE trip_id = ? AND seat_number IN (
                    SELECT bs.seat_number FROM booking_seats bs
                    JOIN bookings b ON bs.booking_id = b.id
                    WHERE b.trip_id = ? AND b.booking_status = 'PENDING' AND b.expires_at < NOW()
                )
            ")->execute([$tripId, $tripId]);

            $db->prepare("
                UPDATE bookings SET booking_status = 'EXPIRED', updated_at = NOW()
                WHERE trip_id = ? AND booking_status = 'PENDING' AND expires_at < NOW()
            ")->execute([$tripId]);
            // 1. Fetch trip and ensure it is active and in future
            $tripStmt = $db->prepare("SELECT * FROM trips WHERE id = ? FOR UPDATE");
            $tripStmt->execute([$tripId]);
            $trip = $tripStmt->fetch();

            if (!$trip || $trip['trip_status'] === 'cancelled') {
                throw new Exception("This trip is not available for booking.");
            }

            // 2. Lock requested seats in trip_seats and check availability
            $inPlaceholders = implode(',', array_fill(0, count($selectedSeats), '?'));
            $seatParams = array_merge([$tripId], $selectedSeats);

            $seatStmt = $db->prepare("
                SELECT seat_number, status, fare_multiplier 
                FROM trip_seats 
                WHERE trip_id = ? AND seat_number IN ({$inPlaceholders})
                FOR UPDATE
            ");
            $seatStmt->execute($seatParams);
            $lockedSeats = $seatStmt->fetchAll();

            // Verify all requested seats exist in inventory
            if (count($lockedSeats) !== count($selectedSeats)) {
                throw new Exception("One or more selected seats do not exist on this bus.");
            }

            // Verify each seat is currently available
            $baseFare = (float)$trip['base_fare'];
            $totalBaseAmount = 0.0;
            $seatPrices = [];

            foreach ($lockedSeats as $seat) {
                if ($seat['status'] !== 'available') {
                    throw new Exception("Seat {$seat['seat_number']} has just been taken by another passenger. Please choose another seat.");
                }
                $price = $baseFare * (float)$seat['fare_multiplier'];
                $totalBaseAmount += $price;
                $seatPrices[$seat['seat_number']] = $price;
            }

            // 3. Coupon validation & discount calculation
            $discountAmount = 0.0;
            $appliedCouponId = null;

            if (!empty($couponCode)) {
                $couponRes = Coupon::validate($couponCode, $totalBaseAmount, $userId);
                if ($couponRes['valid']) {
                    $discountAmount = (float)$couponRes['discount'];
                    $appliedCouponId = (int)$couponRes['coupon']['id'];
                }
            }

            // 4. Taxes & Total calculation (5% GST)
            $taxableAmount = max(0, $totalBaseAmount - $discountAmount);
            $taxAmount = round($taxableAmount * 0.05, 2);
            $finalTotal = round($taxableAmount + $taxAmount, 2);

            // 5. Generate unique booking reference (PNR)
            $pnr = 'SB-' . date('Ym') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

            // Set reservation expiry time (15 mins from now)
            $expiresAt = date('Y-m-d H:i:s', time() + 900);

            // 6. Insert booking record
            $bookStmt = $db->prepare("
                INSERT INTO bookings (
                    booking_reference, user_id, trip_id, boarding_stop_id, dropping_stop_id,
                    total_seats, base_amount, discount_amount, tax_amount, total_amount,
                    booking_status, expires_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, NOW())
            ");
            $bookStmt->execute([
                $pnr,
                $userId,
                $tripId,
                $boardingStopId,
                $droppingStopId,
                count($selectedSeats),
                $totalBaseAmount,
                $discountAmount,
                $taxAmount,
                $finalTotal,
                $expiresAt
            ]);
            $bookingId = (int)$db->lastInsertId();

            // 7. Insert booking seats and update trip_seats to 'reserved'
            $bSeatStmt = $db->prepare("INSERT INTO booking_seats (booking_id, seat_number, price) VALUES (?, ?, ?)");
            $reserveSeatStmt = $db->prepare("UPDATE trip_seats SET status = 'reserved' WHERE trip_id = ? AND seat_number = ?");

            foreach ($selectedSeats as $seatNum) {
                $bSeatStmt->execute([$bookingId, $seatNum, $seatPrices[$seatNum]]);
                $reserveSeatStmt->execute([$tripId, $seatNum]);
            }

            // 8. Insert passengers
            $passStmt = $db->prepare("
                INSERT INTO booking_passengers (booking_id, seat_number, passenger_name, passenger_age, passenger_gender)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($passengers as $p) {
                $passStmt->execute([
                    $bookingId,
                    $p['seat_number'],
                    trim($p['name']),
                    (int)$p['age'],
                    $p['gender'] ?? 'male'
                ]);
            }

            // 9. If coupon applied, record redemption
            if ($appliedCouponId) {
                $redempStmt = $db->prepare("
                    INSERT INTO coupon_redemptions (coupon_id, user_id, booking_id, discount_amount, redeemed_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $redempStmt->execute([$appliedCouponId, $userId, $bookingId, $discountAmount]);

                $db->prepare("UPDATE coupons SET times_used = times_used + 1 WHERE id = ?")->execute([$appliedCouponId]);
            }

            $db->commit();

            return [
                'success' => true,
                'booking_id' => $bookingId,
                'reference' => $pnr,
                'total_amount' => $finalTotal
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Transition booking to CONFIRMED upon successful demo payment
     */
    public static function confirmPayment(int $bookingId, string $paymentMethod, ?string $txnNotes = null): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                throw new Exception("Booking not found.");
            }

            if ($booking['booking_status'] === 'CONFIRMED') {
                $db->commit();
                return ['success' => true, 'reference' => $booking['booking_reference']];
            }

            if ($booking['booking_status'] !== 'PENDING') {
                throw new Exception("Booking cannot be confirmed because status is " . $booking['booking_status']);
            }

            $txnRef = 'TXN-' . strtoupper($paymentMethod) . '-' . strtoupper(substr(uniqid(), -8));

            // Update booking to CONFIRMED
            $updBooking = $db->prepare("UPDATE bookings SET booking_status = 'CONFIRMED', updated_at = NOW() WHERE id = ?");
            $updBooking->execute([$bookingId]);

            // Transition trip seats to 'booked'
            $updSeats = $db->prepare("
                UPDATE trip_seats ts
                JOIN booking_seats bs ON ts.seat_number = bs.seat_number
                SET ts.status = 'booked'
                WHERE bs.booking_id = ? AND ts.trip_id = ?
            ");
            $updSeats->execute([$bookingId, $booking['trip_id']]);

            // Insert payment record
            $payStmt = $db->prepare("
                INSERT INTO payments (booking_id, transaction_ref, payment_method, payment_status, amount, currency, demo_notes, paid_at, created_at)
                VALUES (?, ?, ?, 'SUCCESS', ?, 'INR', ?, NOW(), NOW())
            ");
            $payStmt->execute([
                $bookingId,
                $txnRef,
                $paymentMethod,
                $booking['total_amount'],
                $txnNotes ?: 'Simulated demo payment completed successfully.'
            ]);

            // Create notification for passenger
            create_notification(
                (int)$booking['user_id'],
                "Booking Confirmed: {$booking['booking_reference']}",
                "Your reservation of " . format_currency($booking['total_amount']) . " has been successfully confirmed. Have a safe journey!",
                "booking"
            );

            // Audit log
            audit_log("PAYMENT_SUCCESS", "BOOKING", $bookingId, "Confirmed booking {$booking['booking_reference']} with txn {$txnRef}");

            $db->commit();

            return [
                'success' => true,
                'reference' => $booking['booking_reference'],
                'txn_ref' => $txnRef
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Handle payment failure / cancellation during checkout
     */
    public static function failPayment(int $bookingId, string $reason = 'Payment declined by user or demo simulator'): void
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();

            if (!$booking || $booking['booking_status'] !== 'PENDING') {
                $db->rollBack();
                return;
            }

            // Update status
            $db->prepare("UPDATE bookings SET booking_status = 'PAYMENT_FAILED', updated_at = NOW() WHERE id = ?")->execute([$bookingId]);

            // Release reserved seats back to available
            $db->prepare("
                UPDATE trip_seats ts
                JOIN booking_seats bs ON ts.seat_number = bs.seat_number
                SET ts.status = 'available'
                WHERE bs.booking_id = ? AND ts.trip_id = ?
            ")->execute([$bookingId, $booking['trip_id']]);

            // Record failed payment
            $txnRef = 'TXN-FAIL-' . strtoupper(substr(uniqid(), -8));
            $db->prepare("
                INSERT INTO payments (booking_id, transaction_ref, payment_method, payment_status, amount, currency, demo_notes, created_at)
                VALUES (?, ?, 'DEMO', 'FAILED', ?, 'INR', ?, NOW())
            ")->execute([$bookingId, $txnRef, $booking['total_amount'], $reason]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Cancel confirmed booking and process simulated refund according to cancellation policy
     */
    public static function cancelBooking(int $bookingId, int $userId, string $reason = 'Passenger requested cancellation'): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT b.*, t.departure_datetime 
                FROM bookings b
                JOIN trips t ON b.trip_id = t.id
                WHERE b.id = ? FOR UPDATE
            ");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                throw new Exception("Booking not found.");
            }

            // Security check: Only owner or admin can cancel
            if ((int)$booking['user_id'] !== $userId && !is_admin()) {
                throw new Exception("You are not authorized to cancel this booking.");
            }

            if ($booking['booking_status'] !== 'CONFIRMED') {
                throw new Exception("Only confirmed bookings can be cancelled.");
            }

            $departureTime = strtotime($booking['departure_datetime']);
            $now = time();
            $diffHours = ($departureTime - $now) / 3600;

            if ($diffHours <= 0) {
                throw new Exception("Cannot cancel after bus has already departed.");
            }

            // Calculate refund percentage based on time tier
            $refundPercent = 0;
            $chargePercent = 100;

            if ($diffHours >= 24) {
                $refundPercent = 90;
                $chargePercent = 10;
            } elseif ($diffHours >= 12) {
                $refundPercent = 75;
                $chargePercent = 25;
            } elseif ($diffHours >= 2) {
                $refundPercent = 50;
                $chargePercent = 50;
            } else {
                $refundPercent = 0;
                $chargePercent = 100;
            }

            $totalAmount = (float)$booking['total_amount'];
            $refundAmount = round(($totalAmount * $refundPercent) / 100, 2);
            $cancellationCharge = round($totalAmount - $refundAmount, 2);

            // Update booking status
            $db->prepare("UPDATE bookings SET booking_status = 'CANCELLED', updated_at = NOW() WHERE id = ?")->execute([$bookingId]);

            // Release seats back to available
            $db->prepare("
                UPDATE trip_seats ts
                JOIN booking_seats bs ON ts.seat_number = bs.seat_number
                SET ts.status = 'available'
                WHERE bs.booking_id = ? AND ts.trip_id = ?
            ")->execute([$bookingId, $booking['trip_id']]);

            // Insert cancellation record
            $cancStmt = $db->prepare("
                INSERT INTO cancellations (booking_id, user_id, cancellation_reason, refund_amount, cancellation_charge, refund_status, cancelled_at)
                VALUES (?, ?, ?, ?, ?, 'COMPLETED', NOW())
            ");
            $cancStmt->execute([
                $bookingId,
                $booking['user_id'],
                $reason,
                $refundAmount,
                $cancellationCharge
            ]);

            // Update payment record to REFUNDED if fully/partially refunded
            $db->prepare("UPDATE payments SET payment_status = 'REFUNDED' WHERE booking_id = ?")->execute([$bookingId]);

            // Create notification
            create_notification(
                (int)$booking['user_id'],
                "Booking Cancelled (PNR: {$booking['booking_reference']})",
                "Your booking was cancelled. Simulated refund of " . format_currency($refundAmount) . " has been credited to your original demo payment method.",
                "cancellation"
            );

            audit_log("BOOKING_CANCELLED", "BOOKING", $bookingId, "Refund: " . format_currency($refundAmount) . " ({$refundPercent}%)");

            $db->commit();

            return [
                'success' => true,
                'refund_amount' => $refundAmount,
                'cancellation_charge' => $cancellationCharge,
                'refund_percent' => $refundPercent
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}

