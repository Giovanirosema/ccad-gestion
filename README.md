# CCAD — Gestion interne

Application web de gestion pour la CCAD (assurance décès) : assurés, polices, paiements, relances, réclamations, rapports, supervision, journal d’audit et paramètres.
Technologies : PHP 8, MySQL/MariaDB, HTML, CSS, un peu de JavaScript (sans framework).

## Installation (XAMPP / WAMP / Laragon)

1. Copier le dossier `ccad-gestion` dans `htdocs` (XAMPP) ou `www` (WAMP).
2. Démarrer **Apache** et **MySQL**.
3. Vérifier les accès à la base dans `config.php` (par défaut : `root` sans mot de passe, base `ccad`).
4. Ouvrir `http://localhost/ccad-gestion/install.php` : la base, les tables, les plans et le compte `admin` sont créés (aucune donnée de démonstration).
5. **Supprimer `install.php`** une fois l’installation terminée.
6. Se connecter sur `http://localhost/ccad-gestion/`.

Autre possibilité : importer `database/schema.sql` dans phpMyAdmin, puis créer les comptes vous-même.

## Premier accès

Identifiant `admin`, mot de passe provisoire `ccad2026`. L’application demande de le changer dès la première connexion. Créez ensuite les comptes du personnel dans Paramètres › Utilisateurs.

## Structure

```
config.php              Connexion à la base, session
install.php             Installation (à supprimer ensuite)
index.php               Tableau de bord
assures.php             Liste des assurés
assure.php              Fiche assuré (police, paiements, bénéficiaires, membres, réclamations, photo)
modifier.php            Correction d’un dossier (chaque champ modifié est tracé avec son motif)
document.php            Pièces numérisées (accès réservé aux utilisateurs connectés)
adhesion.php            Adhésion individuelle ou familiale
polices.php             Portefeuille de polices
paiements.php           Encaissements, reçus, collecte du jour
relances.php            Polices en retard et à échoir
reclamations.php        Dossiers de décès (liste, nouveau dossier, décision)
rapports.php            Rapport annuel (par mois, plan, département, mode)
supervision.php         Recouvrement par département, activité des agents
audit.php               Journal d’audit
parametres.php          Plans, formules, services, infos de la compagnie, règles, utilisateurs, paiements, imprimante, données
mon-compte.php          Changement de mot de passe et activité de connexion
photo.php               Photos d’identité (accès réservé aux utilisateurs connectés)
imprimer.php            Documents imprimables (A4 ou ticket 80 mm)
export.php              Exports PDF (par défaut) et CSV (Excel)
includes/               Fonctions, en-tête et pied de page
assets/css/style.css    Styles (charte CCAD)
assets/js/app.js        Interactions (communes, plans, lignes dynamiques, montant auto)
database/schema.sql     Schéma MySQL et données de référence
database/schema-hebergeur.sql  Même schéma sans CREATE DATABASE, avec le compte admin (import phpMyAdmin chez l’hébergeur)
includes/lib/fpdf/      Bibliothèque FPDF 1.86 (exports PDF)
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
2. Dans « MySQL Databases », créer une base et noter : hôte, nom de la base, utilisateur, mot de passe. Dans phpMyAdmin de l’hébergeur, importer `database/schema-hebergeur.sql` (et non `schema.sql`, refusé car il contient CREATE DATABASE).
3. Copier `config.local.example.php` en `config.local.php` et y mettre les accès de l’étape 2.
4. Envoyer tous les fichiers dans `htdocs/` (gestionnaire de fichiers ou FTP avec FileZilla).
5. Ouvrir `https://votre-site/install.php` pour créer les tables et les comptes, puis supprimer `install.php`.

## Sécurité

- Mots de passe chiffrés (bcrypt), 10 caractères minimum avec majuscules, minuscules et chiffres.
- Mot de passe provisoire (création de compte, réinitialisation, installation) : changement obligatoire à la première connexion.
- Blocage 15 minutes après 5 échecs de connexion pour un même identifiant, ou 20 échecs depuis une même adresse IP. Tous les échecs sont inscrits au journal d’audit.
- Session fermée après 30 minutes d’inactivité ; identifiant de session renouvelé toutes les 15 minutes ; cookie `HttpOnly`, `SameSite=Strict`, `Secure` en HTTPS.
- Un compte désactivé, ou dont le mot de passe change, est déconnecté immédiatement sur tous les appareils.
- Jeton CSRF sur tous les formulaires, y compris la déconnexion.
- En-têtes de sécurité (CSP, X-Frame-Options, nosniff, HSTS en HTTPS) et redirection automatique vers HTTPS en ligne.
- Photos d’identité ré-encodées (métadonnées supprimées) et servies uniquement aux utilisateurs connectés du bon département.
- Fichiers sensibles (`.sql`, `.git`, `config.local.php`, `includes/`, `database/`) inaccessibles depuis le web.
- Exports CSV protégés contre l’injection de formules Excel.
- En ligne, les erreurs sont journalisées sans jamais afficher de détail technique à l’écran.
- `install.php` refuse de s’exécuter si l’application est déjà installée.

## Mise en production

- Changer les mots de passe des comptes et mettre les accès MySQL dans `config.local.php`.
- Servir le site en HTTPS.
- Sauvegarder chaque jour : `mysqldump -u root -p ccad > ccad-AAAAMMJJ.sql`.
