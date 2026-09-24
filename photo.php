<?php
// Sert les photos d'identité aux seuls utilisateurs connectés (le dossier uploads/ est fermé au public)
require_once __DIR__ . '/includes/functions.php';
require_login();

$f = get('f');
if (!preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $f) || !is_file(UPLOAD_DIR . $f)) {
    http_response_code(404);
    exit;
}
// Un utilisateur limité à un département ne voit que les photos de son territoire
[$sc, $sp] = scope_dept('a');
if (!scalar("SELECT COUNT(*) FROM assures a WHERE a.photo = ? $sc", array_merge([$f], $sp))) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[pathinfo($f, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize(UPLOAD_DIR . $f));
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline');
readfile(UPLOAD_DIR . $f);
