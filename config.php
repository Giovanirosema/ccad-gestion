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

// Durée d'inactivité avant déconnexion automatique (secondes)
defined('SESSION_TIMEOUT') || define('SESSION_TIMEOUT', 30 * 60);

// Détails des erreurs : seulement en local. En ligne, les erreurs sont journalisées, jamais affichées.
// Une requête relayée par un tunnel (Cloudflare) arrive de 127.0.0.1 mais vient d'Internet
$depuisBoucle = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1'], true);
define('VIA_TUNNEL', $depuisBoucle && (isset($_SERVER['HTTP_CF_CONNECTING_IP']) || isset($_SERVER['HTTP_X_FORWARDED_FOR'])));
$enLocal = $depuisBoucle && !VIA_TUNNEL;
defined('APP_DEBUG') || define('APP_DEBUG', $enLocal);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
if (!APP_DEBUG) {
    // En ligne : l'erreur est journalisée, l'utilisateur voit un message neutre (aucun chemin ni détail SQL)
    set_exception_handler(function (Throwable $e) {
        $code = bin2hex(random_bytes(4));
        error_log("[CCAD $code] " . $e);
        if (!headers_sent()) http_response_code(500);
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Erreur</title><div style="font-family:system-ui,sans-serif;max-width:480px;margin:80px auto;padding:24px;'
            . 'border:1px solid #e0e4ed;border-radius:12px"><h2 style="color:#12276e;margin:0 0 8px">Une erreur est survenue</h2>'
            . '<p>L’opération n’a pas pu aboutir. Réessayez ou contactez l’administrateur en indiquant le code <b>' . $code . '</b>.</p>'
            . '<p><a href="index.php">Retour au tableau de bord</a></p></div>';
    });
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (VIA_TUNNEL && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', 'https')));

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; "
        . "script-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    if ($https) header('Strict-Transport-Security: max-age=31536000');
    header_remove('X-Powered-By');
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_TIMEOUT);
    session_name('CCADSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $https,
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
