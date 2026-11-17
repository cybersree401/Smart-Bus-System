<?php
/**
 * SmartBus - Review Model
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class Review
{
    public static function getApproved(int $limit = 6): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT r.*, u.full_name, rt.source_city, rt.destination_city, b.operator_name
            FROM reviews r
            JOIN users u ON r.user_id = u.id
            JOIN trips t ON r.trip_id = t.id
            JOIN routes rt ON t.route_id = rt.id
            JOIN buses b ON t.bus_id = b.id
            WHERE r.is_approved = 1
            ORDER BY r.id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public static function getAll(): array
    {
        $db = Database::getConnection();
        $sql = "
            SELECT r.*, u.full_name, u.email, rt.source_city, rt.destination_city, b.operator_name
            FROM reviews r
            JOIN users u ON r.user_id = u.id
            JOIN trips t ON r.trip_id = t.id
            JOIN routes rt ON t.route_id = rt.id
            JOIN buses b ON t.bus_id = b.id
            ORDER BY r.id DESC
        ";
        return $db->query($sql)->fetchAll();
    }

    public static function create(int $userId, int $tripId, int $rating, string $comment): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO reviews (user_id, trip_id, rating, comment, is_approved, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$userId, $tripId, $rating, trim($comment)]);
        return (int)$db->lastInsertId();
    }

    public static function toggleApproval(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE reviews SET is_approved = IF(is_approved = 1, 0, 1) WHERE id = ?");
        return $stmt->execute([$id]);
    }
}

