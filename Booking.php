<?php
/**
 * SmartBus - Booking Model
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class Booking
{
    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT b.*, 
                   u.full_name as user_name, u.email as user_email, u.phone as user_phone,
                   t.departure_datetime, t.arrival_datetime, t.base_fare,
                   r.source_city, r.destination_city, r.distance_km, r.duration_minutes,
                   bus.bus_number, bus.operator_name,
                   bt.name as bus_type_name,
                   bs.stop_name as boarding_stop_name, bs.landmark as boarding_landmark,
                   ds.stop_name as dropping_stop_name, ds.landmark as dropping_landmark
            FROM bookings b
            JOIN users u ON b.user_id = u.id
            JOIN trips t ON b.trip_id = t.id
            JOIN routes r ON t.route_id = r.id
            JOIN buses bus ON t.bus_id = bus.id
            JOIN bus_types bt ON bus.bus_type_id = bt.id
            JOIN stops bs ON b.boarding_stop_id = bs.id
            JOIN stops ds ON b.dropping_stop_id = ds.id
            WHERE b.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $booking = $stmt->fetch();
        if ($booking) {
            $booking['passengers'] = self::getPassengers($id);
            $booking['seats'] = self::getSeats($id);
            $booking['payment'] = self::getPayment($id);
            $booking['cancellation'] = self::getCancellation($id);
        }
        return $booking ?: null;
    }

    public static function findByReference(string $reference): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM bookings WHERE booking_reference = ? LIMIT 1");
        $stmt->execute([trim(strtoupper($reference))]);
        $id = $stmt->fetchColumn();
        return $id ? self::findById((int)$id) : null;
    }

    public static function getPassengers(int $bookingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM booking_passengers WHERE booking_id = ? ORDER BY id ASC");
        $stmt->execute([$bookingId]);
        return $stmt->fetchAll();
    }

    public static function getSeats(int $bookingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM booking_seats WHERE booking_id = ? ORDER BY seat_number ASC");
        $stmt->execute([$bookingId]);
        return $stmt->fetchAll();
    }

    public static function getPayment(int $bookingId): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$bookingId]);
        $pay = $stmt->fetch();
        return $pay ?: null;
    }

    public static function getCancellation(int $bookingId): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM cancellations WHERE booking_id = ? LIMIT 1");
        $stmt->execute([$bookingId]);
        $canc = $stmt->fetch();
        return $canc ?: null;
    }

    public static function getUserBookings(int $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT b.*, 
                   t.departure_datetime, t.arrival_datetime,
                   r.source_city, r.destination_city,
                   bus.bus_number, bus.operator_name
            FROM bookings b
            JOIN trips t ON b.trip_id = t.id
            JOIN routes r ON t.route_id = r.id
            JOIN buses bus ON t.bus_id = bus.id
            WHERE b.user_id = ?
            ORDER BY b.id DESC
        ");
        $stmt->execute([$userId]);
        $bookings = $stmt->fetchAll();

        foreach ($bookings as &$b) {
            $b['seats'] = self::getSeats($b['id']);
        }
        return $bookings;
    }

    public static function getAll(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $db = Database::getConnection();
        $sql = "
            SELECT b.*, 
                   u.full_name as user_name, u.email as user_email,
                   t.departure_datetime,
                   r.source_city, r.destination_city,
                   bus.bus_number, bus.operator_name
            FROM bookings b
            JOIN users u ON b.user_id = u.id
            JOIN trips t ON b.trip_id = t.id
            JOIN routes r ON t.route_id = r.id
            JOIN buses bus ON t.bus_id = bus.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND b.booking_status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (b.booking_reference LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $params[] = "%{$filters['search']}%";
            $params[] = "%{$filters['search']}%";
            $params[] = "%{$filters['search']}%";
        }

        $sql .= " ORDER BY b.id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll();

        foreach ($bookings as &$b) {
            $b['seats'] = self::getSeats($b['id']);
        }
        return $bookings;
    }

    public static function countAll(array $filters = []): int
    {
        $db = Database::getConnection();
        $sql = "SELECT COUNT(*) FROM bookings b JOIN users u ON b.user_id = u.id WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND b.booking_status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (b.booking_reference LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $params[] = "%{$filters['search']}%";
            $params[] = "%{$filters['search']}%";
            $params[] = "%{$filters['search']}%";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

