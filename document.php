<?php
// Sert une pièce justificative aux seuls utilisateurs connectés autorisés (le dossier uploads/ est fermé au public)
require_once __DIR__ . '/includes/functions.php';
require_login();
if (!can('assures') && !can('reclamations')) { http_response_code(403); exit('Accès non autorisé.'); }

[$sc, $sp] = scope_dept('a');
$st = db()->prepare("SELECT d.* FROM documents d JOIN assures a ON a.id = d.assure_id WHERE d.id = ? $sc");
$st->execute(array_merge([(int)get('id')], $sp));
$d = $st->fetch();
$chemin = $d ? docs_dir() . $d['fichier'] : '';
if (!$d || !preg_match('/^[a-f0-9]{32}\.(pdf|jpg)$/', $d['fichier']) || !is_file($chemin)) {
    http_response_code(404);
    exit('Document introuvable.');
}

audit('Consultation', 'DOC-' . $d['id'], 'Pièce consultée : ' . $d['piece']);
$telecharger = get('dl') === '1';
header('Content-Type: ' . ($d['mime'] === 'application/pdf' ? 'application/pdf' : 'image/jpeg'));
header('Content-Length: ' . filesize($chemin));
header('Content-Disposition: ' . ($telecharger ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($d['nom_original']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
// Une image est affichée isolée (aucun script) ; un PDF doit rester lisible par la visionneuse du navigateur
if ($d['mime'] !== 'application/pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($chemin);
