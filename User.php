<?php
/**
 * SmartBus - User Model
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class User
{
    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([trim(strtolower($email))]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function create(array $data): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO users (full_name, email, phone, password_hash, role, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            trim($data['full_name']),
            trim(strtolower($data['email'])),
            trim($data['phone']),
            password_hash($data['password'], PASSWORD_BCRYPT),
            $data['role'] ?? 'passenger',
            $data['is_active'] ?? 1
        ]);
        return (int)$db->lastInsertId();
    }

    public static function updateProfile(int $id, array $data): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE users 
            SET full_name = ?, phone = ?, updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([
            trim($data['full_name']),
            trim($data['phone']),
            $id
        ]);
    }

    public static function updatePassword(int $id, string $newPassword): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE users 
            SET password_hash = ?, updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([
            password_hash($newPassword, PASSWORD_BCRYPT),
            $id
        ]);
    }

    public static function getAllPassengers(int $limit = 50, int $offset = 0, string $search = ''): array
    {
        $db = Database::getConnection();
        $sql = "SELECT id, full_name, email, phone, is_active, created_at FROM users WHERE role = 'passenger'";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= " ORDER BY id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countPassengers(string $search = ''): int
    {
        $db = Database::getConnection();
        $sql = "SELECT COUNT(*) FROM users WHERE role = 'passenger'";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function toggleStatus(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND role != 'admin'");
        return $stmt->execute([$id]);
    }
}

