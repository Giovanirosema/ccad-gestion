<?php
require_once __DIR__ . '/../config.php';

/* ---------- Rôles et permissions ---------- */

const ROLES = [
    'Administrateur'               => 'Accès complet, y compris paramètres, plans et comptes utilisateurs.',
    'Administrateur départemental' => 'Tous les modules, limités à son département d’affectation.',
    'Agent de gestion'             => 'Adhésions, fiches assurés et encaissements de sa zone.',
    'Caissier'                     => 'Encaissements et reçus uniquement.',
    'Agent de collecte'            => 'Saisie des cotisations sur le terrain, sans modification de police.',
];

const ROLE_PERMS = [
    'Administrateur'               => ['assures', 'polices', 'paiements', 'reclamations', 'rapports', 'parametres'],
    'Administrateur départemental' => ['assures', 'polices', 'paiements', 'reclamations', 'rapports'],
    'Agent de gestion'             => ['assures', 'polices', 'paiements', 'reclamations', 'rapports'],
    'Caissier'                     => ['paiements'],
    'Agent de collecte'            => ['paiements'],
];

const STATUT_TONES = [
    'Active' => 'success', 'Encaissé' => 'success', 'Validé' => 'success', 'Versé' => 'success',
    'En retard' => 'danger', 'Rejeté' => 'danger', 'Annulé' => 'danger',
    'En attente' => 'warning', 'À valider' => 'warning',
    'Suspendue' => 'neutral', 'Résiliée' => 'neutral', 'Décédé' => 'neutral',
];

const DEPARTEMENTS = [
    'Ouest' => 'Port-au-Prince,Delmas,Carrefour,Pétion-Ville,Tabarre,Cité Soleil,Kenscoff,Gressier,Croix-des-Bouquets,Thomazeau,Ganthier,Fonds-Verrettes,Cornillon,Cabaret,Arcahaie,Léogâne,Petit-Goâve,Grand-Goâve,Anse-à-Galets,Pointe-à-Raquette',
    'Sud' => 'Les Cayes,Camp-Perrin,Chantal,Maniche,Torbeck,Île-à-Vache,Cavaillon,Saint-Louis-du-Sud,Aquin,Port-Salut,Saint-Jean-du-Sud,Arniquet,Chardonnières,Les Anglais,Tiburon,Port-à-Piment,Roche-à-Bateau,Côteaux',
    'Nord' => 'Cap-Haïtien,Limonade,Quartier-Morin,Acul-du-Nord,Milot,Plaine-du-Nord,Grande-Rivière-du-Nord,Bahon,Dondon,Saint-Raphaël,Pignon,La Victoire,Ranquitte,Borgne,Port-Margot,Pilate,Plaisance,Limbé,Bas-Limbé',
    'Artibonite' => 'Gonaïves,Saint-Marc,Dessalines,Grande-Saline,Desdunes,Petite-Rivière-de-l’Artibonite,Verrettes,La Chapelle,Liancourt,Marmelade,Saint-Michel-de-l’Attalaye,Gros-Morne,Terre-Neuve,Anse-Rouge,Ennery',
    'Centre' => 'Hinche,Cerca-Carvajal,Maïssade,Thomonde,Mirebalais,Boucan-Carré,Saut-d’Eau,Lascahobas,Belladère,Savanette,Cerca-la-Source,Thomassique',
    'Grand’Anse' => 'Jérémie,Abricots,Bonbon,Moron,Chambellan,Dame-Marie,Anse-d’Hainault,Les Irois,Corail,Roseaux,Beaumont,Pestel',
    'Nippes' => 'Miragoâne,Fonds-des-Nègres,Paillant,Petite-Rivière-de-Nippes,Anse-à-Veau,Arnaud,L’Asile,Petit-Trou-de-Nippes,Plaisance-du-Sud,Baradères,Grand-Boucan',
    'Nord-Est' => 'Fort-Liberté,Ferrier,Perches,Ouanaminthe,Capotille,Mont-Organisé,Trou-du-Nord,Sainte-Suzanne,Terrier-Rouge,Caracol,Vallières,Carice,Mombin-Crochu',
    'Nord-Ouest' => 'Port-de-Paix,Bassin-Bleu,Chansolme,La Tortue,Saint-Louis-du-Nord,Anse-à-Foleur,Jean-Rabel,Môle-Saint-Nicolas,Bombardopolis,Baie-de-Henne',
    'Sud-Est' => 'Jacmel,Marigot,Cayes-Jacmel,La Vallée,Bainet,Côtes-de-Fer,Belle-Anse,Grand-Gosier,Thiotte,Anse-à-Pitre',
];

const LIENS = ['Souscripteur', 'Souscriptrice', 'Époux', 'Épouse', 'Conjoint', 'Enfant', 'Fils', 'Fille', 'Père', 'Mère', 'Frère', 'Sœur', 'Neveu', 'Nièce', 'Autre parent'];
const ETATS_CIVILS = ['Célibataire', 'Marié(e)', 'En plaçage', 'Veuf / veuve', 'Divorcé(e)'];
const PIECES_ADHESION = ['Photo d’identité', 'Acte de naissance', 'Carte d’identification nationale', 'NIF', 'Preuve d’adresse', 'Certificat médical'];
const PIECES_RECLAMATION = ['Acte de décès', 'Certificat médical de décès', 'Pièce d’identité du déclarant', 'Pièces d’identité des bénéficiaires', 'Reçu de la dernière cotisation', 'Attestation de lien de parenté'];

/* ---------- Utilitaires ---------- */

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($n): string
{
    return number_format((float)$n, 0, ',', ' ');
}

function fdate(?string $d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '—';
}

/** Accepte jj/mm/aaaa ou aaaa-mm-jj, retourne aaaa-mm-jj ou null. */
function parse_date(?string $s): ?string
{
    $s = trim((string)$s);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $s;
    }
    return null;
}

function age(?string $naissance): string
{
    if (!$naissance) return '—';
    return (new DateTime($naissance))->diff(new DateTime('today'))->y . ' ans';
}

function mois_ecoules(?string $date): int
{
    if (!$date) return 0;
    $d = (new DateTime($date))->diff(new DateTime('today'));
    return $d->invert ? 0 : $d->y * 12 + $d->m;
}

function plus_mois(string $date, int $n): string
{
    return (new DateTime($date))->modify("+$n month")->format('Y-m-d');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function post(string $k, $default = '')
{
    return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $default;
}

function get(string $k, $default = '')
{
    return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $default;
}

function scalar(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

function rows(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/* ---------- Paramètres ---------- */

function setting(string $k, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = db()->query('SELECT cle, valeur FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $cache[$k] ?? $default;
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)')
        ->execute([$k, $v]);
}

function devise(): string
{
    return setting('org_devise', 'HTG');
}

function plans(bool $actifsSeulement = true): array
{
    return db()->query('SELECT * FROM plans' . ($actifsSeulement ? ' WHERE actif = 1' : '') . ' ORDER BY prime')->fetchAll();
}

function modes_paiement(bool $actifsSeulement = true): array
{
    return db()->query('SELECT * FROM modes_paiement' . ($actifsSeulement ? ' WHERE actif = 1' : '') . ' ORDER BY id')->fetchAll();
}

/* ---------- Authentification ---------- */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}

function can(string $perm): bool
{
    $u = current_user();
    return $u && in_array($perm, ROLE_PERMS[$u['role']] ?? [], true);
}

function require_perm(string $perm): void
{
    require_login();
    if (!can($perm)) {
        http_response_code(403);
        $page_title = 'Accès non autorisé';
        $active = '';
        require __DIR__ . '/header.php';
        echo '<div class="card"><div class="empty"><h3>Section réservée</h3><p>Le rôle « ' . e(current_user()['role'])
            . ' » n’ouvre pas cette section.</p><a class="btn btn-primary" href="index.php">Retour au tableau de bord</a></div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
}

/** Restreint les requêtes au département de l'utilisateur (sauf Administrateur). */
function scope_dept(string $alias = 'a'): array
{
    $u = current_user();
    if ($u && $u['role'] !== 'Administrateur' && !empty($u['departement'])) {
        return [" AND $alias.departement = ?", [$u['departement']]];
    }
    return ['', []];
}

/* ---------- CSRF et messages ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Jeton de sécurité invalide. Rechargez la page.');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Audit ---------- */

function audit(string $type, string $cible, string $detail): void
{
    $u = current_user();
    db()->prepare('INSERT INTO audit (user_id, user_nom, zone, type, cible, detail, ip) VALUES (?,?,?,?,?,?,?)')
        ->execute([$u['id'] ?? null, $u['nom'] ?? 'Système', $u['zone'] ?? 'Système', $type, $cible,
            mb_substr($detail, 0, 255), $_SERVER['REMOTE_ADDR'] ?? null]);
}

/* ---------- Références ---------- */

function next_reference(string $prefix, string $table): string
{
    $allowed = ['assures', 'paiements', 'reclamations'];
    if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Table inconnue');
    $max = db()->query("SELECT MAX(CAST(SUBSTRING_INDEX(reference, '-', -1) AS UNSIGNED)) FROM $table")->fetchColumn();
    $base = ['assures' => 1040, 'paiements' => 3300, 'reclamations' => 410][$table];
    return $prefix . '-' . str_pad((string)max((int)$max, $base) + 1, 4, '0', STR_PAD_LEFT);
}

/* ---------- Règles métier ---------- */

/** Recalcule En retard / Suspendue / Active selon la date du dernier versement. */
function recalculer_statuts(): void
{
    $retard = (int)setting('regle_retard', 30);
    $susp   = (int)setting('regle_suspension', 60);
    $rows = db()->query("SELECT a.id, a.police, a.statut, a.adhesion,
            (SELECT MAX(DATE_ADD(date_paiement, INTERVAL mois MONTH)) FROM paiements p WHERE p.assure_id = a.id AND p.statut = 'Encaissé' AND p.objet = 'Cotisation') AS couvert
        FROM assures a WHERE a.statut IN ('Active', 'En retard', 'Suspendue')")->fetchAll();
    $upd = db()->prepare('UPDATE assures SET statut = ? WHERE id = ?');
    foreach ($rows as $r) {
        // Une cotisation couvre « mois » mois : le retard court à partir de la fin de la période payée.
        $echeance = $r['couvert'] ? new DateTime($r['couvert']) : (new DateTime($r['adhesion']))->modify('+1 month');
        $jours = (int)$echeance->diff(new DateTime('today'))->format('%r%a');
        $nouveau = $jours > $susp ? 'Suspendue' : ($jours > $retard ? 'En retard' : 'Active');
        if ($nouveau !== $r['statut']) {
            $upd->execute([$nouveau, $r['id']]);
            audit('Statut', $r['police'], "Passage automatique de {$r['statut']} à $nouveau ($jours jours)");
        }
    }
}

/** Recalcule le statut d'un seul assuré après un encaissement ou une annulation. */
function recalculer_statut_assure(array $a): void
{
    if (!in_array($a['statut'], ['En attente', 'Active', 'En retard', 'Suspendue'], true)) return;
    $payes = (int)scalar("SELECT COUNT(*) FROM paiements WHERE assure_id = ? AND statut = 'Encaissé' AND objet = 'Cotisation'", [$a['id']]);
    if ($a['statut'] === 'En attente' && !$payes) return;
    $jours = jours_retard($a);
    $nouveau = $jours > (int)setting('regle_suspension', 60) ? 'Suspendue' : ($jours > (int)setting('regle_retard', 30) ? 'En retard' : 'Active');
    if ($nouveau !== $a['statut']) {
        db()->prepare('UPDATE assures SET statut = ? WHERE id = ?')->execute([$nouveau, $a['id']]);
        audit('Statut', $a['police'], "Passage de {$a['statut']} à $nouveau après mouvement de caisse");
    }
}

function nom_mode(?string $code): string
{
    foreach (modes_paiement(false) as $m) if ($m['code'] === $code) return $m['nom'];
    return $code ?: '—';
}

function jours_retard(array $a): int
{
    $j = (int)(new DateTime(couvert_jusqua($a)))->diff(new DateTime('today'))->format('%r%a');
    return max(0, $j);
}

/** Date de fin de la période couverte par les cotisations encaissées. */
function couvert_jusqua(array $a): string
{
    $st = db()->prepare("SELECT MAX(DATE_ADD(date_paiement, INTERVAL mois MONTH)) FROM paiements WHERE assure_id = ? AND statut = 'Encaissé' AND objet = 'Cotisation'");
    $st->execute([$a['id']]);
    return $st->fetchColumn() ?: plus_mois($a['adhesion'], 1);
}

function prochaine_echeance(): string
{
    $jour = max(1, min(28, (int)setting('regle_echeance', 1)));
    $d = new DateTime(date('Y-m-') . str_pad((string)$jour, 2, '0', STR_PAD_LEFT));
    if ($d <= new DateTime('today')) $d->modify('+1 month');
    return $d->format('d/m/Y');
}

/* ---------- Téléversement de photo ---------- */

function upload_photo(string $field): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Échec du téléversement.');
    if ($f['size'] > 3 * 1024 * 1024) throw new RuntimeException('La photo dépasse 3 Mo.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Format accepté : JPEG, PNG ou WebP.');
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $name)) throw new RuntimeException('Impossible d’enregistrer la photo.');
    return $name;
}

/* ---------- Composants d'affichage ---------- */

function badge(?string $statut): string
{
    $tone = STATUT_TONES[$statut] ?? 'neutral';
    return '<span class="badge badge-' . $tone . '"><span class="dot"></span>' . e($statut) . '</span>';
}

function person(string $name, string $meta = ''): string
{
    $initials = '';
    foreach (array_slice(preg_split('/[\s-]+/u', trim($name)), 0, 2) as $w) $initials .= mb_substr($w, 0, 1);
    return '<div class="person"><span class="avatar">' . e(mb_strtoupper($initials)) . '</span><div><div class="person-name">'
        . e($name) . '</div>' . ($meta !== '' ? '<div class="person-meta mono">' . e($meta) . '</div>' : '') . '</div></div>';
}

function options(array $opts, $selected = null, bool $assoc = true): string
{
    $html = '';
    foreach ($opts as $k => $v) {
        $val = $assoc ? $k : $v;
        $html .= '<option value="' . e($val) . '"' . ((string)$val === (string)$selected ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $html;
}

function icon(string $name, int $size = 18): string
{
    $p = [
        'chart' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'folder' => '<path d="M6 14l1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'printer' => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
    ][$name] ?? '';
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function seal(int $size = 40): string
{
    return '<img class="seal" src="assets/img/logo-ccad.jpg" width="' . $size . '" height="' . $size . '" alt="Logo CCAD">';
}

function stat_card(string $label, string $value, string $unit, string $delta = '', string $tone = '', string $ic = 'chart'): string
{
    return '<div class="stat' . ($tone === 'brand' ? ' stat-brand' : '') . '"><div class="stat-top"><span class="eyebrow">' . e($label)
        . '</span>' . icon($ic, 16) . '</div><div class="stat-value"><span class="mono">' . e($value) . '</span> <small>' . e($unit)
        . '</small></div>' . ($delta !== '' ? '<div class="stat-delta">' . e($delta) . '</div>' : '') . '</div>';
}

function photo_frame(?string $photo, string $class = 'photo'): string
{
    if ($photo && is_file(UPLOAD_DIR . $photo)) {
        return '<img class="' . $class . '" src="' . e(UPLOAD_URL . $photo) . '" alt="Photo de l’assuré">';
    }
    return '<div class="' . $class . ' photo-empty">Photo<br>96×120</div>';
}
