-- ==========================================================
-- CCAD — Confiance, Compagnie d'Assurance de Décès
-- Version pour hébergeur (InfinityFree…) : à importer dans la base déjà créée.
-- Sans CREATE DATABASE ni USE. Crée aussi le compte admin (mot de passe provisoire ccad2026, changement obligatoire).
-- ==========================================================


-- Paramètres clé / valeur (informations admin, règles, imprimante…)
CREATE TABLE IF NOT EXISTS settings (
  cle    VARCHAR(64)  NOT NULL PRIMARY KEY,
  valeur TEXT         NOT NULL
) ENGINE=InnoDB;

-- Comptes du personnel
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom           VARCHAR(120) NOT NULL,
  login         VARCHAR(60)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('Administrateur','Administrateur départemental','Agent de gestion','Caissier','Agent de collecte') NOT NULL DEFAULT 'Agent de gestion',
  telephone     VARCHAR(40)  NULL,
  email         VARCHAR(120) NULL,
  departement   VARCHAR(60)  NULL,
  commune       VARCHAR(80)  NULL,
  zone          VARCHAR(120) NULL,
  actif         TINYINT(1)   NOT NULL DEFAULT 1,
  derniere_connexion DATETIME NULL,
  doit_changer_mdp TINYINT(1) NOT NULL DEFAULT 0,
  mdp_change_le DATETIME NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Plans tarifaires
CREATE TABLE IF NOT EXISTS plans (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom      VARCHAR(60)    NOT NULL,
  prime    DECIMAL(12,2)  NOT NULL,
  capital  DECIMAL(14,2)  NOT NULL,
  actif    TINYINT(1)     NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Formules de police
CREATE TABLE IF NOT EXISTS formules (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(30)  NOT NULL UNIQUE,
  nom         VARCHAR(120) NOT NULL,
  description TEXT         NULL,
  actif       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Services funéraires
CREATE TABLE IF NOT EXISTS services (
  id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom   VARCHAR(80) NOT NULL,
  mode  VARCHAR(60) NOT NULL,
  actif TINYINT(1)  NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Moyens de paiement
CREATE TABLE IF NOT EXISTS modes_paiement (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code    VARCHAR(30)  NOT NULL UNIQUE,
  nom     VARCHAR(60)  NOT NULL,
  type    VARCHAR(30)  NOT NULL,
  numero  VARCHAR(80)  NULL,
  frais   DECIMAL(10,2) NOT NULL DEFAULT 0,
  actif   TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Assurés (une police par assuré)
CREATE TABLE IF NOT EXISTS assures (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference      VARCHAR(20)  NOT NULL UNIQUE,
  police         VARCHAR(30)  NOT NULL UNIQUE,
  prenom         VARCHAR(80)  NOT NULL,
  nom            VARCHAR(80)  NOT NULL,
  sexe           ENUM('F','M') NULL,
  etat_civil     VARCHAR(30)  NULL,
  naissance      DATE         NULL,
  lieu_naissance VARCHAR(100) NULL,
  nif            VARCHAR(30)  NULL,
  profession     VARCHAR(80)  NULL,
  personnes_charge TINYINT UNSIGNED NULL,
  telephone      VARCHAR(40)  NOT NULL,
  telephone2     VARCHAR(40)  NULL,
  email          VARCHAR(120) NULL,
  departement    VARCHAR(60)  NOT NULL,
  commune        VARCHAR(80)  NOT NULL,
  section        VARCHAR(100) NULL,
  adresse        VARCHAR(200) NULL,
  temoin1_nom    VARCHAR(120) NULL,
  temoin1_tel    VARCHAR(40)  NULL,
  temoin2_nom    VARCHAR(120) NULL,
  temoin2_tel    VARCHAR(40)  NULL,
  sante          VARCHAR(255) NULL,
  -- plan familial
  chef_famille   VARCHAR(120) NULL,
  contact_urgence VARCHAR(120) NULL,
  contact_tel    VARCHAR(40)  NULL,
  inhumation     VARCHAR(160) NULL,
  caveau         VARCHAR(120) NULL,
  -- police
  formule_id     INT UNSIGNED NULL,
  plan_id        INT UNSIGNED NULL,
  type_police    ENUM('individuel','famille') NOT NULL DEFAULT 'individuel',
  devise         ENUM('HTG','USD') NOT NULL DEFAULT 'HTG',
  mode_paiement  VARCHAR(30)  NULL,
  numero_mobile  VARCHAR(40)  NULL,
  zone           VARCHAR(120) NULL,
  adhesion       DATE         NOT NULL,
  statut         ENUM('En attente','Active','En retard','Suspendue','Résiliée','Décédé') NOT NULL DEFAULT 'En attente',
  pieces         TEXT         NULL,
  photo          VARCHAR(255) NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_statut (statut),
  KEY idx_dept (departement),
  CONSTRAINT fk_assure_plan    FOREIGN KEY (plan_id)    REFERENCES plans(id)    ON DELETE SET NULL,
  CONSTRAINT fk_assure_formule FOREIGN KEY (formule_id) REFERENCES formules(id) ON DELETE SET NULL,
  CONSTRAINT fk_assure_user    FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB;

-- Membres couverts (plan familial)
CREATE TABLE IF NOT EXISTS membres (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assure_id INT UNSIGNED NOT NULL,
  nom       VARCHAR(120) NOT NULL,
  lien      VARCHAR(40)  NOT NULL,
  naissance DATE         NULL,
  part      DECIMAL(5,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_membre_assure FOREIGN KEY (assure_id) REFERENCES assures(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Bénéficiaires du capital
CREATE TABLE IF NOT EXISTS beneficiaires (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assure_id INT UNSIGNED NOT NULL,
  nom       VARCHAR(120) NOT NULL,
  lien      VARCHAR(40)  NOT NULL,
  naissance DATE         NULL,
  part      DECIMAL(5,2) NOT NULL DEFAULT 0,
  telephone VARCHAR(40)  NULL,
  CONSTRAINT fk_benef_assure FOREIGN KEY (assure_id) REFERENCES assures(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Paiements / reçus
CREATE TABLE IF NOT EXISTS paiements (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference     VARCHAR(20)   NOT NULL UNIQUE,
  assure_id     INT UNSIGNED  NOT NULL,
  montant       DECIMAL(12,2) NOT NULL,
  devise        ENUM('HTG','USD') NOT NULL DEFAULT 'HTG',
  mode          VARCHAR(60)   NOT NULL,
  date_paiement DATE          NOT NULL,
  objet         VARCHAR(60)   NOT NULL DEFAULT 'Cotisation',
  mois          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  statut        ENUM('Encaissé','À valider','Annulé') NOT NULL DEFAULT 'Encaissé',
  created_by    INT UNSIGNED  NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_date (date_paiement),
  CONSTRAINT fk_paiement_assure FOREIGN KEY (assure_id)  REFERENCES assures(id) ON DELETE CASCADE,
  CONSTRAINT fk_paiement_user   FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB;

-- Dossiers de réclamation (décès)
CREATE TABLE IF NOT EXISTS reclamations (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference      VARCHAR(20)   NOT NULL UNIQUE,
  assure_id      INT UNSIGNED  NOT NULL,
  date_deces     DATE          NOT NULL,
  lieu_deces     VARCHAR(160)  NULL,
  cause          VARCHAR(60)   NULL,
  acte_deces     VARCHAR(40)   NULL,
  medecin        VARCHAR(120)  NULL,
  declarant_nom  VARCHAR(120)  NOT NULL,
  declarant_lien VARCHAR(60)   NULL,
  declarant_tel  VARCHAR(40)   NOT NULL,
  temoin1        VARCHAR(160)  NULL,
  temoin2        VARCHAR(160)  NULL,
  services       TEXT          NULL,
  capital        DECIMAL(14,2) NOT NULL DEFAULT 0,
  arrieres       DECIMAL(12,2) NOT NULL DEFAULT 0,
  frais_dossier  DECIMAL(12,2) NOT NULL DEFAULT 1500,
  pieces         TEXT          NULL,
  statut         ENUM('En attente','À valider','Validé','Versé','Rejeté') NOT NULL DEFAULT 'En attente',
  motif_rejet    VARCHAR(255)  NULL,
  created_by     INT UNSIGNED  NULL,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_recl_assure FOREIGN KEY (assure_id)  REFERENCES assures(id) ON DELETE CASCADE,
  CONSTRAINT fk_recl_user   FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB;

-- Pièces justificatives numérisées (PDF, photos) : dossier assuré ou dossier de réclamation
CREATE TABLE IF NOT EXISTS documents (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assure_id      INT UNSIGNED  NOT NULL,
  reclamation_id INT UNSIGNED  NULL,
  piece          VARCHAR(80)   NOT NULL,
  fichier        VARCHAR(64)   NOT NULL UNIQUE,
  nom_original   VARCHAR(160)  NOT NULL,
  mime           VARCHAR(40)   NOT NULL,
  taille         INT UNSIGNED  NOT NULL,
  created_by     INT UNSIGNED  NULL,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assure (assure_id),
  KEY idx_recl (reclamation_id),
  CONSTRAINT fk_doc_assure FOREIGN KEY (assure_id)      REFERENCES assures(id)      ON DELETE CASCADE,
  CONSTRAINT fk_doc_recl   FOREIGN KEY (reclamation_id) REFERENCES reclamations(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_user   FOREIGN KEY (created_by)     REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB;

-- Historique des changements de plan tarifaire
CREATE TABLE IF NOT EXISTS historique_plans (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assure_id      INT UNSIGNED  NOT NULL,
  ancien_plan_id INT UNSIGNED  NULL,
  nouveau_plan_id INT UNSIGNED NOT NULL,
  ancienne_prime DECIMAL(12,2) NOT NULL,
  nouvelle_prime DECIMAL(12,2) NOT NULL,
  date_effet     DATE          NOT NULL,
  motif          VARCHAR(255)  NOT NULL,
  created_by     INT UNSIGNED  NULL,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assure (assure_id),
  CONSTRAINT fk_hist_assure FOREIGN KEY (assure_id) REFERENCES assures(id) ON DELETE CASCADE,
  CONSTRAINT fk_hist_user   FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tentatives de connexion (anti force brute)
CREATE TABLE IF NOT EXISTS login_tentatives (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45)  NOT NULL,
  login      VARCHAR(60)  NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip (ip, created_at),
  KEY idx_login (login, created_at)
) ENGINE=InnoDB;

-- Journal d'audit (non modifiable par l'application)
CREATE TABLE IF NOT EXISTS audit (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  user_nom   VARCHAR(120) NOT NULL,
  zone       VARCHAR(120) NULL,
  type       VARCHAR(40)  NOT NULL,
  cible      VARCHAR(60)  NOT NULL,
  detail     VARCHAR(255) NOT NULL,
  ip         VARCHAR(45)  NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Données de référence
-- ----------------------------------------------------------
INSERT IGNORE INTO settings (cle, valeur) VALUES
('org_nom', 'CCAD — Confiance, Compagnie d''Assurance de Décès'),
('org_nif', '001-234-567-8'),
('org_adresse', '7, Bouette, Camp-Perrin, Les Cayes'),
('org_tel', '+509 3714 6868 / 3262 5720'),
('org_mail', 'ccad.haiti@gmail.com'),
('org_site', 'www.ccad.ht'),
('org_slogan', 'Peye antèman w kounye a pou w ka antere ak diyite'),
('org_devise', 'HTG'),
('org_inscription', '1000'),
('regle_eligibilite', '24'),
('regle_retard', '30'),
('regle_suspension', '60'),
('regle_echeance', '1'),
('mode_defaut', 'moncash'),
('notif_adhesion', '1'),
('notif_retard', '1'),
('notif_eligibilite', '1'),
('notif_reclamation', '0'),
('imp_nom', 'CCAD-Bureau-01'),
('imp_ip', '192.168.1.42'),
('imp_format', 'a4'),
('imp_copies', '2');

INSERT INTO plans (nom, prime, capital, actif)
SELECT * FROM (SELECT 'Plan 1', 1000, 200000, 1 UNION ALL
               SELECT 'Plan 2', 1250, 250000, 1 UNION ALL
               SELECT 'Plan 3', 1500, 300000, 1 UNION ALL
               SELECT 'Plan 4', 1750, 325000, 1 UNION ALL
               SELECT 'Plan 5', 2000, 400000, 1 UNION ALL
               SELECT 'Plan 6', 2500, 500000, 1 UNION ALL
               SELECT 'Plan 7', 5000, 1000000, 1) t
WHERE NOT EXISTS (SELECT 1 FROM plans);

INSERT IGNORE INTO formules (code, nom, description, actif) VALUES
('individuel', 'Police individuelle', 'Un seul assuré, cotisation mensuelle jusqu''à son décès.', 1),
('collectif', 'Plan collectif familial', 'Une police négociée pour tous les membres de la famille. Tout membre ajouté exige une nouvelle entente avec la compagnie.', 1),
('bien', 'Bien en échange conditionnel entre vif', 'Funérailles planifiées du vivant de l''intéressé en échange d''un bien.', 1),
('parent', 'CCAD, Parent analogique', 'La compagnie tient le rôle de parent pour une personne décédée non assurée, après négociation avec les proches.', 1);

INSERT INTO services (nom, mode, actif)
SELECT * FROM (SELECT 'Cercueil', 'Vente sur commande', 1 UNION ALL
               SELECT 'Gerbe', 'Vente sur commande', 1 UNION ALL
               SELECT 'Couronne', 'Vente sur commande', 1 UNION ALL
               SELECT 'Chaise', 'Location', 1 UNION ALL
               SELECT 'Table', 'Location', 1) t
WHERE NOT EXISTS (SELECT 1 FROM services);

INSERT IGNORE INTO modes_paiement (code, nom, type, numero, frais, actif) VALUES
('moncash', 'MonCash', 'Mobile', '+509 2812 4000', 0, 1),
('natcash', 'NatCash', 'Mobile', '+509 3140 7700', 0, 1),
('especes', 'Espèces', 'Guichet', NULL, 0, 1),
('virement', 'Virement bancaire', 'Banque', 'Unibank 120-456-789', 25, 1),
('cheque', 'Chèque', 'Banque', NULL, 0, 0);

-- Compte administrateur initial
INSERT INTO users (nom, login, password_hash, role, zone, doit_changer_mdp)
SELECT 'Administrateur', 'admin', '$2y$10$5NFEbDiByIW3NOvUSxn/LOfB5B.AmCmybr6DBZviSlVgkzCMCDq8m', 'Administrateur', 'Siège', 1
WHERE NOT EXISTS (SELECT 1 FROM users);
