<?php
/**
 * Installation : crée la base, les tables et les données de démonstration.
 * À exécuter une seule fois (http://localhost/ccad-gestion/install.php),
 * puis SUPPRIMER ce fichier.
 */
require_once __DIR__ . '/config.php';

$log = [];
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // 1. Schéma
    $sql = file_get_contents(__DIR__ . '/database/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    $log[] = 'Base « ' . DB_NAME . ' » et tables créées.';

    $pdo->exec('USE `' . DB_NAME . '`');

    // 2. Comptes utilisateurs
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $users = [
            ['Jocelyn Dériphonse', 'admin', 'Administrateur', 'Sud', 'Les Cayes', 'Siège'],
            ['Elionor Baptichon', 'e.baptichon', 'Administrateur départemental', 'Ouest', 'Port-au-Prince', 'Zone 5 — Delmas'],
            ['Nadège Charles', 'n.charles', 'Agent de gestion', 'Sud', 'Camp-Perrin', 'Zone 2 — Mersan'],
            ['Sherline Aubourg', 's.aubourg', 'Caissier', 'Sud', 'Les Cayes', 'Zone 3 — Les Cayes'],
            ['Wisly Toussaint', 'w.toussaint', 'Agent de collecte', 'Nippes', 'Miragoâne', 'Zone 8 — Miragoâne'],
        ];
        $st = $pdo->prepare('INSERT INTO users (nom, login, password_hash, role, departement, commune, zone) VALUES (?,?,?,?,?,?,?)');
        foreach ($users as $u) {
            $st->execute([$u[0], $u[1], password_hash('ccad2026', PASSWORD_DEFAULT), $u[2], $u[3], $u[4], $u[5]]);
        }
        $log[] = count($users) . ' comptes créés (mot de passe : ccad2026).';
    }

    // 3. Assurés de démonstration
    if ((int)$pdo->query('SELECT COUNT(*) FROM assures')->fetchColumn() === 0) {
        $plans = $pdo->query('SELECT nom, id, prime FROM plans')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $demo = [
            ['Marie-Lourdes', 'Jean-Baptiste', 'F', '1979-03-17', 'Plan 6', 'famille',   '2024-08-04', 'Active',    'Camp-Perrin', 'Sud'],
            ['Wilfrid',       'Étienne',       'M', '1968-11-02', 'Plan 3', 'individuel','2024-09-19', 'En retard', 'Les Cayes',   'Sud'],
            ['Roseline',      'Pierre-Louis',  'F', '1985-06-24', 'Plan 5', 'famille',   '2024-10-02', 'Active',    'Camp-Perrin', 'Sud'],
            ['Jean-Robert',   'Casimir',       'M', '1957-02-09', 'Plan 7', 'individuel','2025-01-11', 'En attente','Les Cayes',   'Sud'],
            ['Yolette',       'Dorvilier',     'F', '1991-09-30', 'Plan 1', 'individuel','2025-02-27', 'Active',    'Maniche',     'Sud'],
            ['Fritzner',      'Saint-Juste',   'M', '1974-12-12', 'Plan 4', 'individuel','2025-04-15', 'Suspendue', 'Camp-Perrin', 'Sud'],
            ['Mirlande',      'Toussaint',     'F', '1963-05-05', 'Plan 6', 'famille',   '2025-06-06', 'Active',    'Delmas',      'Ouest'],
            ['Gérard',        'Nazaire',       'M', '1988-07-21', 'Plan 2', 'individuel','2025-07-21', 'En retard', 'Miragoâne',   'Nippes'],
        ];
        $ins = $pdo->prepare('INSERT INTO assures (reference, police, prenom, nom, sexe, naissance, telephone, departement, commune,
            plan_id, formule_id, type_police, adhesion, statut, mode_paiement, zone, adresse, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
        $pay = $pdo->prepare('INSERT INTO paiements (reference, assure_id, montant, mode, date_paiement, objet, statut, created_by)
            VALUES (?,?,?,?,?,?,?,1)');
        $ben = $pdo->prepare('INSERT INTO beneficiaires (assure_id, nom, lien, part, telephone) VALUES (?,?,?,?,?)');
        $recu = 3300;
        foreach ($demo as $i => $d) {
            $seq  = 1041 + $i * 23;
            $plan = $plans[$d[4]];
            $formule = $d[5] === 'famille' ? 2 : 1;
            $ins->execute(['A-' . $seq, 'P-' . substr($d[6], 0, 4) . '-' . $seq, $d[0], $d[1], $d[2], $d[3],
                '+509 3' . rand(100, 999) . ' ' . rand(1000, 9999), $d[9], $d[8], $plan['id'], $formule, $d[5], $d[6], $d[7],
                'moncash', 'Zone 2 — Mersan', rand(1, 40) . ', rue Ferdinand', ]);
            $aid = (int)$pdo->lastInsertId();
            $ben->execute([$aid, 'Bénéficiaire principal de ' . $d[0], 'Enfant', 60, '+509 3410 7761']);
            $ben->execute([$aid, 'Conjoint de ' . $d[0], 'Conjoint', 40, '+509 4822 1190']);
            // Historique : un paiement par mois depuis l'adhésion (sauf en retard/suspendus : derniers mois manquants)
            $start = new DateTime($d[6]);
            $stop  = new DateTime('first day of this month');
            if ($d[7] === 'En retard')  { $stop->modify('-2 month'); }
            if ($d[7] === 'Suspendue')  { $stop->modify('-3 month'); }
            if ($d[7] === 'En attente') { $stop = clone $start; }
            $pay->execute(['R-' . (++$recu), $aid, 1000, 'Espèces', $start->format('Y-m-d'), 'Inscription', 'Encaissé']);
            for ($dt = clone $start; $dt <= $stop; $dt->modify('+1 month')) {
                $pay->execute(['R-' . (++$recu), $aid, $plan['prime'], rand(0, 3) ? 'MonCash' : 'Espèces',
                    $dt->format('Y-m-d'), 'Cotisation', 'Encaissé']);
            }
        }
        $log[] = count($demo) . ' assurés et leur historique de paiements créés.';
    }
    $ok = true;
} catch (Throwable $e) {
    $ok = false;
    $log[] = 'Erreur : ' . $e->getMessage();
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Installation CCAD</title>
<link rel="stylesheet" href="assets/css/style.css"></head>
<body class="auth-body">
<div class="card" style="max-width:560px;margin:60px auto">
  <div class="card-head"><h2>Installation de CCAD</h2></div>
  <div class="card-body">
    <?php foreach ($log as $l): ?><p><?= htmlspecialchars($l) ?></p><?php endforeach; ?>
    <?php if ($ok): ?>
      <div class="alert alert-warning">Supprimez maintenant le fichier <code>install.php</code> du serveur.</div>
      <a class="btn btn-primary" href="login.php">Aller à la connexion</a>
    <?php endif; ?>
  </div>
</div>
</body></html>
