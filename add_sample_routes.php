<?php
/**
 * Add popular intercity routes and bookable trips without rebuilding the database.
 * Run from the project root with: C:\xampp\php\php.exe database\add_sample_routes.php
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\Trip;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Run this migration from the command line.\n");
}

$db = Database::getConnection();
$connections = [
    ['Chennai', 'Salem', 340, 360, 1, '06:30:00', 550],
    ['Salem', 'Chennai', 340, 360, 1, '14:00:00', 550],
    ['Chennai', 'Erode', 400, 420, 2, '06:00:00', 650],
    ['Erode', 'Chennai', 400, 420, 2, '16:00:00', 650],
    ['Chennai', 'Pondicherry', 165, 210, 3, '07:00:00', 350],
    ['Pondicherry', 'Chennai', 165, 210, 3, '16:00:00', 350],
    ['Salem', 'Bengaluru', 200, 240, 4, '06:00:00', 450],
    ['Bengaluru', 'Salem', 200, 240, 4, '15:00:00', 450],
    ['Chennai', 'Trichy', 330, 330, 1, '20:30:00', 500],
    ['Trichy', 'Madurai', 130, 150, 2, '21:00:00', 300],
    ['Bengaluru', 'Coimbatore', 370, 420, 3, '21:00:00', 700],
    ['Coimbatore', 'Madurai', 215, 240, 4, '20:00:00', 400],
];

$findStop = $db->prepare('SELECT id FROM stops WHERE city = ? ORDER BY id LIMIT 1');
$addStop = $db->prepare('INSERT INTO stops (city, stop_name, landmark) VALUES (?, ?, ?)');
$findRoute = $db->prepare('SELECT id FROM routes WHERE source_city = ? AND destination_city = ? LIMIT 1');
$addRoute = $db->prepare('INSERT INTO routes (source_city, destination_city, distance_km, duration_minutes, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())');
$findRouteStops = $db->prepare('SELECT COUNT(*) FROM route_stops WHERE route_id = ?');
$addRouteStop = $db->prepare("INSERT INTO route_stops (route_id, stop_id, stop_type, stop_order, minutes_from_start) VALUES (?, ?, ?, ?, ?)");
$findTrip = $db->prepare('SELECT id FROM trips WHERE route_id = ? AND departure_datetime = ? LIMIT 1');



$getStopId = static function (string $city) use ($db, $findStop, $addStop): int {
    $findStop->execute([$city]);
    $id = $findStop->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }
    $addStop->execute([$city, $city . ' Bus Stand', 'Main intercity bus terminal']);
    return (int)$db->lastInsertId();
};

$createdRoutes = 0;
$createdTrips = 0;
foreach ($connections as [$source, $destination, $distance, $duration, $busId, $departureTime, $fare]) {
    $findRoute->execute([$source, $destination]);
    $routeId = $findRoute->fetchColumn();
    if ($routeId === false) {
        $addRoute->execute([$source, $destination, $distance, $duration]);
        $routeId = (int)$db->lastInsertId();
        $createdRoutes++;
    } else {
        $routeId = (int)$routeId;
    }

    $findRouteStops->execute([$routeId]);
    if ((int)$findRouteStops->fetchColumn() === 0) {
        $sourceStop = $getStopId($source);
        $destinationStop = $getStopId($destination);
        $addRouteStop->execute([$routeId, $sourceStop, 'boarding', 1, 0]);
        $addRouteStop->execute([$routeId, $destinationStop, 'dropping', 2, $duration]);
    }

    for ($day = 0; $day <= 15; $day++) {
        $departure = (new DateTimeImmutable('today'))->modify("+{$day} days")->format('Y-m-d') . ' ' . $departureTime;
        $arrival = (new DateTimeImmutable($departure))->modify("+{$duration} minutes")->format('Y-m-d H:i:s');
        $findTrip->execute([$routeId, $departure]);
        if ($findTrip->fetchColumn() !== false) {
            continue;
        }
        Trip::create([
            'route_id' => $routeId,
            'bus_id' => $busId,
            'departure_datetime' => $departure,
            'arrival_datetime' => $arrival,
            'base_fare' => $fare,
            'trip_status' => 'scheduled',
        ]);
        $createdTrips++;
    }
}

// Home page trending links use today's date. Ensure every active route, including
// routes that were already in the seed database, has trips on today and the next
// 15 days so each route card leads to bookable results.
$activeRoutes = $db->query('SELECT id, source_city, destination_city, distance_km, duration_minutes FROM routes WHERE is_active = 1')->fetchAll();
foreach ($activeRoutes as $route) {
    $routeId = (int)$route['id'];
    $findRouteStops->execute([$routeId]);
    if ((int)$findRouteStops->fetchColumn() === 0) {
        $sourceStop = $getStopId($route['source_city']);
        $destinationStop = $getStopId($route['destination_city']);
        $addRouteStop->execute([$routeId, $sourceStop, 'boarding', 1, 0]);
        $addRouteStop->execute([$routeId, $destinationStop, 'dropping', 2, (int)$route['duration_minutes']]);
    }

    for ($day = 0; $day <= 15; $day++) {
        $departure = (new DateTimeImmutable('today'))->modify("+{$day} days")->setTime(21, 0)->format('Y-m-d H:i:s');
        $findTrip->execute([$routeId, $departure]);
        if ($findTrip->fetchColumn() !== false) {
            continue;
        }

        $busId = (($routeId - 1) % 5) + 1;
        $arrival = (new DateTimeImmutable($departure))->modify('+' . (int)$route['duration_minutes'] . ' minutes')->format('Y-m-d H:i:s');
        Trip::create([
            'route_id' => $routeId,
            'bus_id' => $busId,
            'departure_datetime' => $departure,
            'arrival_datetime' => $arrival,
            'base_fare' => max(300, (int)round((float)$route['distance_km'] * 1.5)),
            'trip_status' => 'scheduled',
        ]);
        $createdTrips++;
    }
}

echo "Added {$createdRoutes} routes and {$createdTrips} trips.\n";
echo "All active routes now have bookable trips for today and the next 15 days.\n";



