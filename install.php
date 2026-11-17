<?php
/**
 * SmartBus - Database Auto-Installer / Migrator
 * Run via CLI: php database/install.php
 * Or via browser: http://localhost/SmartBus/database/install.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

$config = require __DIR__ . '/../app/config/database.php';
$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    ob_implicit_flush(true);
    echo "Starting SmartBus Database Setup..." . PHP_EOL;
}

function outputMsg(string $msg, bool $isCli, bool $isError = false): void
{
    if ($isCli) {
        echo ($isError ? "[ERROR] " : "[OK] ") . $msg . PHP_EOL;
    } else {
        $color = $isError ? '#ef4444' : '#10b981';
        echo "<div style='font-family:sans-serif; margin:8px 0; color:{$color}; font-weight:500;'>";
        echo ($isError ? "❌ " : "✅ ") . htmlspecialchars($msg);
        echo "</div>";
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>SmartBus Database Setup</title>";
    echo "<style>body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#f8fafc;padding:40px;line-height:1.6;} .card{background:#1e293b;padding:30px;border-radius:12px;max-width:700px;margin:auto;box-shadow:0 10px 25px rgba(0,0,0,0.3);} h1{color:#38bdf8;} a{color:#38bdf8;text-decoration:none;font-weight:600;}</style>";
    echo "</head><body><div class='card'>";
    echo "<h1>SmartBus Database Installer</h1>";
}

try {
    // 1. Connect without specifying database name first (trying configured port and fallbacks)
    $portsToTry = array_unique([$config['port'] ?? '3307', '3307', '3306']);
    $pdo = null;
    $connectedPort = $config['port'];
    $lastErr = null;

    foreach ($portsToTry as $p) {
        try {
            $dsnNoDb = sprintf('mysql:host=%s;port=%s;charset=%s', $config['host'], $p, $config['charset']);
            if ($isCli) {
                echo "Trying connection on port {$p}..." . PHP_EOL;
            }
            $pdo = new PDO($dsnNoDb, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3
            ]);
            $connectedPort = $p;
            break;
        } catch (Exception $e) {
            $lastErr = $e;
        }
    }

    if (!$pdo) {
        throw new Exception("Could not connect to MySQL on port(s) " . implode('/', $portsToTry) . ": " . ($lastErr ? $lastErr->getMessage() : 'Connection refused'));
    }

    outputMsg("Connected to MySQL server at {$config['host']}:{$connectedPort}", $isCli);

    // 2. Create database if not exists
    $dbName = $config['dbname'];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    outputMsg("Database `{$dbName}` verified or created.", $isCli);

    // 3. Select database and execute smartbus.sql only if tables don't exist yet
    $pdo->exec("USE `{$dbName}`");
    $sqlFile = __DIR__ . '/smartbus.sql';

    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found at: {$sqlFile}");
    }

    // Check if the database has already been installed (users table exists)
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();

    if ($tableCheck) {
        outputMsg("Database already installed — skipping SQL import to preserve existing data.", $isCli);
    } else {
        $sql = file_get_contents($sqlFile);
        $pdo->exec($sql);
        outputMsg("Schema and seed data successfully imported from smartbus.sql!", $isCli);
    }
    outputMsg("Default Admin: admin@smartbus.com / Admin@123", $isCli);
    outputMsg("Default Passenger: passenger@smartbus.com / Passenger@123", $isCli);

    if (!$isCli) {
        $homeUrl = url('/');
        echo "<p style='margin-top:20px;'><a href='{$homeUrl}'>👉 Proceed to SmartBus Home Page</a></p>";
    }
} catch (Exception $e) {
    outputMsg("Installation failed: " . $e->getMessage(), $isCli, true);
    if (!$isCli) {
        echo "<p style='color:#cbd5e1; font-size:14px;'>Make sure MySQL is started in XAMPP Control Panel and credentials in <code>app/config/database.php</code> are correct.</p>";
    }
}

if (!$isCli) {
    echo "</div></body></html>";
}

