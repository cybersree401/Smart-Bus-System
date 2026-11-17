<?php
/**
 * SmartBus - Operational Analytics & Reporting Engine
 */

namespace App\Services;

use App\Core\Database;
use PDO;

class ReportService
{
    /**
     * Get KPI summary metrics calculated from actual database
     */
    public static function getDashboardSummary(): array
    {
        $db = Database::getConnection();

        $passengersCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'passenger'")->fetchColumn();
        $busesCount = (int)$db->query("SELECT COUNT(*) FROM buses WHERE is_active = 1")->fetchColumn();
        $routesCount = (int)$db->query("SELECT COUNT(*) FROM routes WHERE is_active = 1")->fetchColumn();
        $tripsCount = (int)$db->query("SELECT COUNT(*) FROM trips WHERE departure_datetime >= NOW() AND trip_status != 'cancelled'")->fetchColumn();

        $totalBookings = (int)$db->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
        $confirmedBookings = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE booking_status = 'CONFIRMED'")->fetchColumn();
        $cancelledBookings = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE booking_status = 'CANCELLED'")->fetchColumn();

        $revenue = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE booking_status = 'CONFIRMED'")->fetchColumn();

        return [
            'total_passengers' => $passengersCount,
            'total_buses' => $busesCount,
            'active_routes' => $routesCount,
            'upcoming_trips' => $tripsCount,
            'total_bookings' => $totalBookings,
            'confirmed_bookings' => $confirmedBookings,
            'cancelled_bookings' => $cancelledBookings,
            'total_revenue' => $revenue
        ];
    }

    /**
     * Booking & Revenue trend for past 7 days (for Chart.js)
     */
    public static function getDailyTrends(): array
    {
        $db = Database::getConnection();

        $sql = "
            SELECT 
                DATE(created_at) as booking_date,
                COUNT(*) as count,
                SUM(CASE WHEN booking_status = 'CONFIRMED' THEN total_amount ELSE 0 END) as daily_revenue
            FROM bookings
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY booking_date ASC
        ";
        $results = $db->query($sql)->fetchAll();

        $dates = [];
        $counts = [];
        $revenues = [];

        // Build continuous 7-day array
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $dates[] = date('d M', strtotime($d));
            $found = false;
            foreach ($results as $r) {
                if ($r['booking_date'] === $d) {
                    $counts[] = (int)$r['count'];
                    $revenues[] = (float)$r['daily_revenue'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $counts[] = 0;
                $revenues[] = 0.0;
            }
        }

        return [
            'labels' => $dates,
            'bookings' => $counts,
            'revenue' => $revenues
        ];
    }

    /**
     * Top booked routes breakdown
     */
    public static function getPopularRoutes(): array
    {
        $db = Database::getConnection();
        $sql = "
            SELECT 
                CONCAT(r.source_city, ' → ', r.destination_city) as route_name,
                COUNT(b.id) as booking_count,
                COALESCE(SUM(b.total_amount), 0) as total_revenue
            FROM bookings b
            JOIN trips t ON b.trip_id = t.id
            JOIN routes r ON t.route_id = r.id
            WHERE b.booking_status = 'CONFIRMED'
            GROUP BY r.id
            ORDER BY booking_count DESC
            LIMIT 5
        ";
        return $db->query($sql)->fetchAll();
    }

    /**
     * Export all bookings into a downloadable CSV file
     */
    public static function exportBookingsCsv(): void
    {
        $db = Database::getConnection();
        $sql = "
            SELECT 
                b.booking_reference,
                u.full_name as passenger_name,
                u.email as passenger_email,
                u.phone as passenger_phone,
                CONCAT(r.source_city, ' to ', r.destination_city) as route,
                bus.bus_number,
                bus.operator_name,
                t.departure_datetime,
                b.total_seats,
                b.base_amount,
                b.discount_amount,
                b.tax_amount,
                b.total_amount,
                b.booking_status,
                b.created_at
            FROM bookings b
            JOIN users u ON b.user_id = u.id
            JOIN trips t ON b.trip_id = t.id
            JOIN routes r ON t.route_id = r.id
            JOIN buses bus ON t.bus_id = bus.id
            ORDER BY b.id DESC
        ";
        $bookings = $db->query($sql)->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=smartbus_bookings_' . date('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'PNR', 'Passenger Name', 'Email', 'Phone', 'Route',
            'Bus Number', 'Operator', 'Departure', 'Seats',
            'Base Fare', 'Discount', 'Tax', 'Total Amount', 'Status', 'Booked At'
        ]);

        foreach ($bookings as $row) {
            fputcsv($out, [
                $row['booking_reference'],
                $row['passenger_name'],
                $row['passenger_email'],
                $row['passenger_phone'],
                $row['route'],
                $row['bus_number'],
                $row['operator_name'],
                $row['departure_datetime'],
                $row['total_seats'],
                $row['base_amount'],
                $row['discount_amount'],
                $row['tax_amount'],
                $row['total_amount'],
                $row['booking_status'],
                $row['created_at']
            ]);
        }
        fclose($out);
        exit;
    }
}

