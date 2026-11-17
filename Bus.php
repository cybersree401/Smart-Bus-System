<?php
/**
 * SmartBus - Bus Model
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class Bus
{
    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT b.*, bt.name as bus_type_name, bt.category, bt.is_ac, bt.has_upper_deck
            FROM buses b
            JOIN bus_types bt ON b.bus_type_id = bt.id
            WHERE b.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $bus = $stmt->fetch();
        if ($bus) {
            $bus['amenities'] = self::getAmenities($id);
        }
        return $bus ?: null;
    }

    public static function getAll(bool $activeOnly = false): array
    {
        $db = Database::getConnection();
        $sql = "
            SELECT b.*, bt.name as bus_type_name, bt.category, bt.is_ac, bt.has_upper_deck
            FROM buses b
            JOIN bus_types bt ON b.bus_type_id = bt.id
        ";
        if ($activeOnly) {
            $sql .= " WHERE b.is_active = 1";
        }
        $sql .= " ORDER BY b.id DESC";
        $stmt = $db->query($sql);
        $buses = $stmt->fetchAll();

        foreach ($buses as &$bus) {
            $bus['amenities'] = self::getAmenities($bus['id']);
        }
        return $buses;
    }

    public static function getAmenities(int $busId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.* 
            FROM amenities a
            JOIN bus_amenities ba ON a.id = ba.amenity_id
            WHERE ba.bus_id = ?
            ORDER BY a.name ASC
        ");
        $stmt->execute([$busId]);
        return $stmt->fetchAll();
    }

    public static function getAllBusTypes(): array
    {
        $db = Database::getConnection();
        return $db->query("SELECT * FROM bus_types ORDER BY id ASC")->fetchAll();
    }

    public static function getAllAmenities(): array
    {
        $db = Database::getConnection();
        return $db->query("SELECT * FROM amenities ORDER BY name ASC")->fetchAll();
    }

    public static function create(array $data, array $amenityIds = []): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO buses (bus_number, operator_name, bus_type_id, total_seats, rating, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            trim(strtoupper($data['bus_number'])),
            trim($data['operator_name']),
            (int)$data['bus_type_id'],
            (int)$data['total_seats'],
            (float)($data['rating'] ?? 4.5),
            (int)($data['is_active'] ?? 1)
        ]);
        $busId = (int)$db->lastInsertId();

        self::syncAmenities($busId, $amenityIds);
        return $busId;
    }

    public static function update(int $id, array $data, array $amenityIds = []): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE buses 
            SET bus_number = ?, operator_name = ?, bus_type_id = ?, total_seats = ?, rating = ?, is_active = ?
            WHERE id = ?
        ");
        $res = $stmt->execute([
            trim(strtoupper($data['bus_number'])),
            trim($data['operator_name']),
            (int)$data['bus_type_id'],
            (int)$data['total_seats'],
            (float)($data['rating'] ?? 4.5),
            (int)($data['is_active'] ?? 1),
            $id
        ]);

        self::syncAmenities($id, $amenityIds);
        return $res;
    }

    public static function syncAmenities(int $busId, array $amenityIds): void
    {
        $db = Database::getConnection();
        $db->prepare("DELETE FROM bus_amenities WHERE bus_id = ?")->execute([$busId]);

        if (!empty($amenityIds)) {
            $stmt = $db->prepare("INSERT INTO bus_amenities (bus_id, amenity_id) VALUES (?, ?)");
            foreach ($amenityIds as $amenityId) {
                $stmt->execute([$busId, (int)$amenityId]);
            }
        }
    }

    public static function toggleStatus(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE buses SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        return $stmt->execute([$id]);
    }
}

