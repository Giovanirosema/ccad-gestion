<?php
/**
 * CCAD — Gestion interne
 * Configuration générale et connexion à la base de données.
 */

// Réglages propres au serveur (hébergeur) : copier config.local.example.php en config.local.php
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';

defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_PORT') || define('DB_PORT', 3306);   // WAMP : 3306 = MySQL, 3307 = MariaDB
defined('DB_NAME') || define('DB_NAME', 'ccad');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'CCAD');
define('APP_VERSION', '2.4.1');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');

date_default_timezone_set('America/Port-au-Prince');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
