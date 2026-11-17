<?php
/**
 * SmartBus - Route Model
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class Route
{
    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM routes WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $route = $stmt->fetch();
        if ($route) {
            $route['stops'] = self::getStops($id);
        }
        return $route ?: null;
    }

    public static function getAll(bool $activeOnly = false): array
    {
        $db = Database::getConnection();
        $sql = "SELECT * FROM routes";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY source_city ASC, destination_city ASC";
        $stmt = $db->query($sql);
        $routes = $stmt->fetchAll();

        foreach ($routes as &$r) {
            $r['stops'] = self::getStops($r['id']);
        }
        return $routes;
    }

    public static function getCities(): array
    {
        $db = Database::getConnection();
        $sql = "
            SELECT DISTINCT source_city as city FROM routes WHERE is_active = 1
            UNION
            SELECT DISTINCT destination_city as city FROM routes WHERE is_active = 1
            ORDER BY city ASC
        ";
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function getStops(int $routeId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT rs.*, s.stop_name, s.city, s.landmark, s.latitude, s.longitude
            FROM route_stops rs
            JOIN stops s ON rs.stop_id = s.id
            WHERE rs.route_id = ?
            ORDER BY rs.stop_order ASC
        ");
        $stmt->execute([$routeId]);
        return $stmt->fetchAll();
    }

    public static function getBoardingStops(int $routeId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT rs.*, s.stop_name, s.city, s.landmark
            FROM route_stops rs
            JOIN stops s ON rs.stop_id = s.id
            WHERE rs.route_id = ? AND rs.stop_type IN ('boarding', 'both')
            ORDER BY rs.stop_order ASC
        ");
        $stmt->execute([$routeId]);
        return $stmt->fetchAll();
    }

    public static function getDroppingStops(int $routeId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT rs.*, s.stop_name, s.city, s.landmark
            FROM route_stops rs
            JOIN stops s ON rs.stop_id = s.id
            WHERE rs.route_id = ? AND rs.stop_type IN ('dropping', 'both')
            ORDER BY rs.stop_order ASC
        ");
        $stmt->execute([$routeId]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO routes (source_city, destination_city, distance_km, duration_minutes, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            trim(ucwords($data['source_city'])),
            trim(ucwords($data['destination_city'])),
            (float)$data['distance_km'],
            (int)$data['duration_minutes'],
            (int)($data['is_active'] ?? 1)
        ]);
        return (int)$db->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE routes
            SET source_city = ?, destination_city = ?, distance_km = ?, duration_minutes = ?, is_active = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            trim(ucwords($data['source_city'])),
            trim(ucwords($data['destination_city'])),
            (float)$data['distance_km'],
            (int)$data['duration_minutes'],
            (int)($data['is_active'] ?? 1),
            $id
        ]);
    }

    public static function getAllStops(): array
    {
        $db = Database::getConnection();
        return $db->query("SELECT * FROM stops ORDER BY city ASC, stop_name ASC")->fetchAll();
    }

    public static function toggleStatus(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE routes SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        return $stmt->execute([$id]);
    }
}

