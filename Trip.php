<?php
/**
 * SmartBus - Trip Model & Search Engine
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class Trip
{
    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT t.*, 
                   r.source_city, r.destination_city, r.distance_km, r.duration_minutes,
                   b.bus_number, b.operator_name, b.rating, b.total_seats,
                   bt.name as bus_type_name, bt.category as bus_category, bt.is_ac, bt.has_upper_deck
            FROM trips t
            JOIN routes r ON t.route_id = r.id
            JOIN buses b ON t.bus_id = b.id
            JOIN bus_types bt ON b.bus_type_id = bt.id
            WHERE t.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $trip = $stmt->fetch();
        if ($trip) {
            $trip['amenities'] = Bus::getAmenities($trip['bus_id']);
            $trip['boarding_stops'] = Route::getBoardingStops($trip['route_id']);
            $trip['dropping_stops'] = Route::getDroppingStops($trip['route_id']);
            $trip['available_seats_count'] = self::getAvailableSeatsCount($id);
        }
        return $trip ?: null;
    }

    /**
     * Search trips matching criteria with rich filters and sorting
     */
    public static function search(array $filters = []): array
    {
        $db = Database::getConnection();

        $sql = "
            SELECT t.*, 
                   r.source_city, r.destination_city, r.distance_km, r.duration_minutes,
                   b.bus_number, b.operator_name, b.rating, b.total_seats,
                   bt.name as bus_type_name, bt.category as bus_category, bt.is_ac, bt.has_upper_deck,
                   (SELECT COUNT(*) FROM trip_seats ts WHERE ts.trip_id = t.id AND ts.status = 'available') as available_seats
            FROM trips t
            JOIN routes r ON t.route_id = r.id
            JOIN buses b ON t.bus_id = b.id
            JOIN bus_types bt ON b.bus_type_id = bt.id
            WHERE t.trip_status != 'cancelled' AND r.is_active = 1 AND b.is_active = 1
        ";

        $params = [];

        // Source & Destination
        if (!empty($filters['source'])) {
            $sql .= " AND r.source_city = ?";
            $params[] = trim($filters['source']);
        }
        if (!empty($filters['destination'])) {
            $sql .= " AND r.destination_city = ?";
            $params[] = trim($filters['destination']);
        }

        // Travel Date (matching date portion)
        if (!empty($filters['date'])) {
            $sql .= " AND DATE(t.departure_datetime) = ?";
            $params[] = $filters['date'];
        }

        // Bus Category (seater, sleeper, semi-sleeper)
        if (!empty($filters['bus_type'])) {
            $sql .= " AND bt.category = ?";
            $params[] = $filters['bus_type'];
        }

        // AC / Non-AC
        if (isset($filters['is_ac']) && $filters['is_ac'] !== '') {
            $sql .= " AND bt.is_ac = ?";
            $params[] = (int)$filters['is_ac'];
        }

        // Price range
        if (!empty($filters['min_fare'])) {
            $sql .= " AND t.base_fare >= ?";
            $params[] = (float)$filters['min_fare'];
        }
        if (!empty($filters['max_fare'])) {
            $sql .= " AND t.base_fare <= ?";
            $params[] = (float)$filters['max_fare'];
        }

        // Departure time window
        // morning: 06:00 - 12:00, afternoon: 12:00 - 18:00, evening: 18:00 - 24:00, night: 00:00 - 06:00
        if (!empty($filters['time_window'])) {
            switch ($filters['time_window']) {
                case 'morning':
                    $sql .= " AND HOUR(t.departure_datetime) >= 6 AND HOUR(t.departure_datetime) < 12";
                    break;
                case 'afternoon':
                    $sql .= " AND HOUR(t.departure_datetime) >= 12 AND HOUR(t.departure_datetime) < 18";
                    break;
                case 'evening':
                    $sql .= " AND HOUR(t.departure_datetime) >= 18 AND HOUR(t.departure_datetime) < 24";
                    break;
                case 'night':
                    $sql .= " AND HOUR(t.departure_datetime) >= 0 AND HOUR(t.departure_datetime) < 6";
                    break;
            }
        }

        // Rating filter
        if (!empty($filters['min_rating'])) {
            $sql .= " AND b.rating >= ?";
            $params[] = (float)$filters['min_rating'];
        }

        // Sorting
        $sort = $filters['sort'] ?? 'departure_asc';
        $orderBy = match ($sort) {
            'fare_asc' => 't.base_fare ASC',
            'fare_desc' => 't.base_fare DESC',
            'departure_desc' => 't.departure_datetime DESC',
            'duration_asc' => 'r.duration_minutes ASC',
            'rating_desc' => 'b.rating DESC',
            default => 't.departure_datetime ASC'
        };

        $sql .= " ORDER BY {$orderBy}";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $trips = $stmt->fetchAll();

        foreach ($trips as &$trip) {
            $trip['amenities'] = Bus::getAmenities($trip['bus_id']);
            $trip['boarding_stops'] = Route::getBoardingStops($trip['route_id']);
            $trip['dropping_stops'] = Route::getDroppingStops($trip['route_id']);
        }

        return $trips;
    }

    public static function getAvailableSeatsCount(int $tripId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM trip_seats WHERE trip_id = ? AND status = 'available'");
        $stmt->execute([$tripId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get all seats for a trip organized by deck and rows
     */
    public static function getSeatLayout(int $tripId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM trip_seats 
            WHERE trip_id = ? 
            ORDER BY deck DESC, row_num ASC, col_num ASC
        ");
        $stmt->execute([$tripId]);
        $seats = $stmt->fetchAll();

        $layout = [
            'lower' => [],
            'upper' => []
        ];

        foreach ($seats as $seat) {
            $deck = $seat['deck'] === 'upper' ? 'upper' : 'lower';
            $row = $seat['row_num'];
            if (!isset($layout[$deck][$row])) {
                $layout[$deck][$row] = [];
            }
            $layout[$deck][$row][] = $seat;
        }

        return $layout;
    }

    /**
     * Automatically generate seats for a new trip based on bus configuration
     */
    public static function generateSeatsForTrip(int $tripId, int $busId): void
    {
        $bus = Bus::findById($busId);
        if (!$bus) return;

        $db = Database::getConnection();
        $category = $bus['category'];
        $hasUpper = (bool)$bus['has_upper_deck'];

        $stmt = $db->prepare("
            INSERT INTO trip_seats (trip_id, seat_number, deck, seat_type, row_num, col_num, status, fare_multiplier)
            VALUES (?, ?, ?, ?, ?, ?, 'available', ?)
        ");

        if ($category === 'sleeper') {
            // Sleeper layout: 5 rows, 3 berths per row (1 single + 2 double) on lower & upper
            for ($row = 1; $row <= 5; $row++) {
                for ($col = 1; $col <= 3; $col++) {
                    $seatNum = "L" . (($row - 1) * 3 + $col);
                    $stmt->execute([$tripId, $seatNum, 'lower', 'sleeper', $row, $col, 1.00]);
                }
            }
            if ($hasUpper) {
                for ($row = 1; $row <= 5; $row++) {
                    for ($col = 1; $col <= 3; $col++) {
                        $seatNum = "U" . (($row - 1) * 3 + $col);
                        $stmt->execute([$tripId, $seatNum, 'upper', 'sleeper', $row, $col, 1.05]);
                    }
                }
            }
        } else {
            // Seater layout: 10 rows, 4 seats per row (2+2)
            $cols = ['A', 'B', 'C', 'D'];
            for ($row = 1; $row <= 10; $row++) {
                foreach ($cols as $idx => $letter) {
                    $seatNum = "{$row}{$letter}";
                    $stmt->execute([$tripId, $seatNum, 'lower', $category, $row, $idx + 1, 1.00]);
                }
            }
        }
    }

    public static function create(array $data): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO trips (route_id, bus_id, departure_datetime, arrival_datetime, base_fare, trip_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            (int)$data['route_id'],
            (int)$data['bus_id'],
            $data['departure_datetime'],
            $data['arrival_datetime'],
            (float)$data['base_fare'],
            $data['trip_status'] ?? 'scheduled'
        ]);
        $tripId = (int)$db->lastInsertId();

        // Automatically populate trip seat inventory
        self::generateSeatsForTrip($tripId, (int)$data['bus_id']);

        return $tripId;
    }

    public static function update(int $id, array $data): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE trips
            SET route_id = ?, bus_id = ?, departure_datetime = ?, arrival_datetime = ?, base_fare = ?, trip_status = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            (int)$data['route_id'],
            (int)$data['bus_id'],
            $data['departure_datetime'],
            $data['arrival_datetime'],
            (float)$data['base_fare'],
            $data['trip_status'] ?? 'scheduled',
            $id
        ]);
    }

    public static function getAll(int $limit = 50, int $offset = 0): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT t.*, 
                   r.source_city, r.destination_city, 
                   b.bus_number, b.operator_name,
                   (SELECT COUNT(*) FROM trip_seats ts WHERE ts.trip_id = t.id AND ts.status = 'available') as available_seats
            FROM trips t
            JOIN routes r ON t.route_id = r.id
            JOIN buses b ON t.bus_id = b.id
            ORDER BY t.departure_datetime DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    public static function countAll(): int
    {
        $db = Database::getConnection();
        return (int)$db->query("SELECT COUNT(*) FROM trips")->fetchColumn();
    }
}

