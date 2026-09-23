# CCAD — Gestion interne

Application web de gestion pour la CCAD (assurance décès) : assurés, polices, paiements, relances, réclamations, rapports, supervision, journal d’audit et paramètres.
Technologies : PHP 8, MySQL/MariaDB, HTML, CSS, un peu de JavaScript (sans framework).

## Installation (XAMPP / WAMP / Laragon)

1. Copier le dossier `ccad-gestion` dans `htdocs` (XAMPP) ou `www` (WAMP).
2. Démarrer **Apache** et **MySQL**.
3. Vérifier les accès à la base dans `config.php` (par défaut : `root` sans mot de passe, base `ccad`).
4. Ouvrir `http://localhost/ccad-gestion/install.php` : la base, les tables, les plans et des données de démonstration sont créés.
5. **Supprimer `install.php`** une fois l’installation terminée.
6. Se connecter sur `http://localhost/ccad-gestion/`.

Autre possibilité : importer `database/schema.sql` dans phpMyAdmin, puis créer les comptes vous-même.

## Comptes de démonstration

Mot de passe pour tous les comptes : `ccad2026` (à changer dans Paramètres › Utilisateurs).

| Identifiant   | Rôle                         |
|---------------|------------------------------|
| `admin`       | Administrateur               |
| `e.baptichon` | Administrateur départemental |
| `n.charles`   | Agent de gestion             |
| `s.aubourg`   | Caissier                     |
| `w.toussaint` | Agent de collecte            |

## Structure

```
config.php              Connexion à la base, session
install.php             Installation (à supprimer ensuite)
index.php               Tableau de bord
assures.php             Liste des assurés
assure.php              Fiche assuré (police, paiements, bénéficiaires, membres, réclamations, photo)
adhesion.php            Adhésion individuelle ou familiale
polices.php             Portefeuille de polices
paiements.php           Encaissements, reçus, collecte du jour
relances.php            Polices en retard et à échoir
reclamations.php        Dossiers de décès (liste, nouveau dossier, décision)
rapports.php            Rapport annuel (par mois, plan, département, mode)
supervision.php         Recouvrement par département, activité des agents
audit.php               Journal d’audit
parametres.php          Plans, formules, services, infos de la compagnie, règles, utilisateurs, paiements, imprimante, données
imprimer.php            Documents imprimables (A4 ou ticket 80 mm)
export.php              Exports CSV (compatibles Excel)
includes/               Fonctions, en-tête et pied de page
assets/css/style.css    Styles (charte CCAD)
assets/js/app.js        Interactions (communes, plans, lignes dynamiques, montant auto)
database/schema.sql     Schéma MySQL et données de référence
uploads/                Photos d’identité
```

## Règles métier (Paramètres › Règles et alertes)

- **Éligibilité** : le capital est dû si le décès survient après 24 mois d’adhésion.
- **Retard** : une police passe « En retard » 30 jours après la fin de la période payée, puis « Suspendue » après 60 jours. Les statuts sont recalculés automatiquement.
- Un paiement peut couvrir plusieurs mois d’avance (champ « Mois » à l’encaissement).
- Les encaissements saisis par un **agent de collecte** sont « À valider » jusqu’au contrôle au bureau.
- Réclamation : net à verser = capital − arriérés − frais de dossier, réparti entre les bénéficiaires selon leurs parts.
- Les utilisateurs autres que l’administrateur ne voient que les dossiers de leur département.

## Documents imprimables

`imprimer.php?doc=inscription|paiement|recu|reclamation|rapport&id=…`, avec `&format=thermal` pour un ticket 80 mm. Le format par défaut se règle dans Paramètres › Imprimante.

## Hébergement gratuit (InfinityFree)

1. Créer un compte sur https://www.infinityfree.com puis un site (sous-domaine gratuit).
2. Dans « MySQL Databases », créer une base et noter : hôte, nom de la base, utilisateur, mot de passe.
3. Dans phpMyAdmin de l’hébergeur, importer `database/schema.sql` **après avoir retiré** les deux premières lignes `CREATE DATABASE` et `USE` (la base existe déjà).
4. Copier `config.local.example.php` en `config.local.php` et y mettre les accès de l’étape 2.
5. Envoyer tous les fichiers dans `htdocs/` (gestionnaire de fichiers ou FTP avec FileZilla).
6. Ouvrir `https://votre-site/install.php` pour créer les comptes, puis supprimer `install.php`.

## Mise en production

- Changer les mots de passe et le compte MySQL (`config.php`).
- Servir le site en HTTPS.
- Sauvegarder chaque jour : `mysqldump -u root -p ccad > ccad-AAAAMMJJ.sql`.
