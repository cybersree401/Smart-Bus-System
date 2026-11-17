<?php
/**
 * SmartBus - Simulated GPS Live Tracking Service
 * Provides educational GPS coordinates along route waypoints
 */

namespace App\Services;

use App\Core\Database;
use App\Models\Trip;
use App\Models\Route;
use PDO;

class TrackingSimulationService
{
    /**
     * Get or calculate simulated live tracking for a trip
     */
    public static function getTripTracking(int $tripId): ?array
    {
        $db = Database::getConnection();
        $trip = Trip::findById($tripId);
        if (!$trip) return null;

        // Check if database has live tracking row
        $stmt = $db->prepare("
            SELECT tt.*, s.stop_name as next_stop_name
            FROM trip_tracking tt
            LEFT JOIN stops s ON tt.next_stop_id = s.id
            WHERE tt.trip_id = ? LIMIT 1
        ");
        $stmt->execute([$tripId]);
        $tracking = $stmt->fetch();

        $stops = Route::getStops($trip['route_id']);

        // Default or simulated coordinates if not explicitly seeded
        if (!$tracking) {
            $firstStop = $stops[0] ?? ['latitude' => 13.0674, 'longitude' => 80.2078, 'stop_name' => 'Source Terminal'];
            $tracking = [
                'trip_id' => $tripId,
                'current_latitude' => $firstStop['latitude'] ?? 13.0674,
                'current_longitude' => $firstStop['longitude'] ?? 80.2078,
                'current_speed' => 68,
                'current_location_name' => 'Departed from ' . ($firstStop['stop_name'] ?? 'Terminal'),
                'next_stop_id' => $stops[1]['stop_id'] ?? null,
                'next_stop_name' => $stops[1]['stop_name'] ?? 'Next Junction',
                'delay_minutes' => 0,
                'status_text' => 'On Time (Simulated)',
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        return [
            'trip' => $trip,
            'tracking' => $tracking,
            'route_stops' => $stops,
            'is_simulation' => true
        ];
    }
}

