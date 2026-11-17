<?php
/**
 * SmartBus - Notification Model
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class Notification
{
    public static function getUserNotifications(int $userId, int $limit = 20): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? 
            ORDER BY id DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    public static function getUnreadCount(int $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public static function markAsRead(int $id, int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }

    public static function markAllAsRead(int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        return $stmt->execute([$userId]);
    }
}

