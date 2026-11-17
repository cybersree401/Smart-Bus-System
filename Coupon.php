<?php
/**
 * SmartBus - Coupon Model & Discount Engine
 */

namespace App\Models;

use App\Core\Database;
use PDO;

class Coupon
{
    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM coupons WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $coupon = $stmt->fetch();
        return $coupon ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM coupons WHERE code = ? LIMIT 1");
        $stmt->execute([trim(strtoupper($code))]);
        $coupon = $stmt->fetch();
        return $coupon ?: null;
    }

    public static function getAll(): array
    {
        $db = Database::getConnection();
        return $db->query("SELECT * FROM coupons ORDER BY id DESC")->fetchAll();
    }

    /**
     * Validate a coupon against total amount and user
     */
    public static function validate(string $code, float $bookingAmount, int $userId): array
    {
        $coupon = self::findByCode($code);
        if (!$coupon) {
            return ['valid' => false, 'message' => 'Invalid coupon code.'];
        }

        if (!$coupon['is_active']) {
            return ['valid' => false, 'message' => 'This coupon has been disabled.'];
        }

        if ($coupon['expiry_date'] < date('Y-m-d')) {
            return ['valid' => false, 'message' => 'This coupon has expired.'];
        }

        if ($coupon['times_used'] >= $coupon['usage_limit']) {
            return ['valid' => false, 'message' => 'Coupon usage limit has been reached.'];
        }

        if ($bookingAmount < (float)$coupon['min_booking_amount']) {
            return [
                'valid' => false, 
                'message' => "Minimum booking amount of " . format_currency($coupon['min_booking_amount']) . " required for this coupon."
            ];
        }

        // Check if user already redeemed this coupon
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?");
        $stmt->execute([$coupon['id'], $userId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return ['valid' => false, 'message' => 'You have already redeemed this coupon.'];
        }

        // Calculate discount
        $discount = 0.0;
        if ($coupon['discount_type'] === 'flat') {
            $discount = (float)$coupon['discount_value'];
        } else {
            $discount = ($bookingAmount * (float)$coupon['discount_value']) / 100.0;
            if ($discount > (float)$coupon['max_discount']) {
                $discount = (float)$coupon['max_discount'];
            }
        }

        if ($discount > $bookingAmount) {
            $discount = $bookingAmount;
        }

        return [
            'valid' => true,
            'coupon' => $coupon,
            'discount' => round($discount, 2),
            'message' => "Coupon applied! You saved " . format_currency($discount)
        ];
    }

    public static function create(array $data): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO coupons (code, discount_type, discount_value, min_booking_amount, max_discount, expiry_date, usage_limit, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            trim(strtoupper($data['code'])),
            $data['discount_type'],
            (float)$data['discount_value'],
            (float)($data['min_booking_amount'] ?? 0),
            (float)($data['max_discount'] ?? 0),
            $data['expiry_date'],
            (int)($data['usage_limit'] ?? 1000),
            (int)($data['is_active'] ?? 1)
        ]);
        return (int)$db->lastInsertId();
    }

    public static function update(int $id, array $data): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE coupons
            SET code = ?, discount_type = ?, discount_value = ?, min_booking_amount = ?, max_discount = ?, expiry_date = ?, usage_limit = ?, is_active = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            trim(strtoupper($data['code'])),
            $data['discount_type'],
            (float)$data['discount_value'],
            (float)($data['min_booking_amount'] ?? 0),
            (float)($data['max_discount'] ?? 0),
            $data['expiry_date'],
            (int)($data['usage_limit'] ?? 1000),
            (int)($data['is_active'] ?? 1),
            $id
        ]);
    }

    public static function toggleStatus(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE coupons SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        return $stmt->execute([$id]);
    }
}

