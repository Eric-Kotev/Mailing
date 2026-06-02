-- ============================================
-- SCRIPT COMPLET DE CRÉATION DES TABLES
-- (Extrait de notre échange précédent)
-- ============================================

-- Activation de l'extension UUID
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- TABLE 1 : type_message
CREATE TABLE IF NOT EXISTS type_message (
    id_type_message INTEGER PRIMARY KEY,
    libelle_type VARCHAR(50) NOT NULL UNIQUE
);

-- Insertion des types de messages
INSERT INTO type_message (id_type_message, libelle_type) VALUES
(1, 'SMS'),
(2, 'Email'),
(3, 'WhatsApp'),
(4, 'Audio')
ON CONFLICT (id_type_message) DO NOTHING;

-- TABLE 2 : compte
CREATE TABLE IF NOT EXISTS compte (
    id_compte UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    entreprise VARCHAR(255) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    nom VARCHAR(100) NOT NULL,
    "user" VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    credits_total DECIMAL(10,2) DEFAULT 0,
    role VARCHAR(20) DEFAULT 'user',
    actif BOOLEAN DEFAULT true,
    date_creation TIMESTAMP DEFAULT NOW(),
    date_suspension TIMESTAMP NULL
);

-- TABLE 3 : contact
CREATE TABLE IF NOT EXISTS contact (
    id_contact UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_compte UUID NOT NULL REFERENCES compte(id_compte) ON DELETE CASCADE,
    prenom VARCHAR(100),
    nom VARCHAR(100),
    email VARCHAR(255),
    telephone VARCHAR(20),
    date_naissance DATE,
    adresse TEXT,
    code_postal VARCHAR(20),
    ville VARCHAR(100),
    pays VARCHAR(100) DEFAULT 'France',
    date_inscription TIMESTAMP DEFAULT NOW(),
    champs1 TEXT,
    champs2 TEXT,
    champs3 TEXT,
    champs4 TEXT,
    champs5 TEXT
);

-- TABLE 4 : liste
CREATE TABLE IF NOT EXISTS liste (
    id_liste UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_compte UUID NOT NULL REFERENCES compte(id_compte) ON DELETE CASCADE,
    nom_liste VARCHAR(255) NOT NULL,
    date_creation TIMESTAMP DEFAULT NOW()
);

-- TABLE 5 : liste_contact
CREATE TABLE IF NOT EXISTS liste_contact (
    id_listcontact UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_liste UUID NOT NULL REFERENCES liste(id_liste) ON DELETE CASCADE,
    id_contact UUID NOT NULL REFERENCES contact(id_contact) ON DELETE CASCADE,
    date_ajout TIMESTAMP DEFAULT NOW(),
    UNIQUE(id_liste, id_contact)
);

-- TABLE 6 : provider
CREATE TABLE IF NOT EXISTS provider (
    id_provider UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_type_message INTEGER NOT NULL REFERENCES type_message(id_type_message),
    nom_provider VARCHAR(100) NOT NULL,
    api_endpoint VARCHAR(255),
    documentation_url VARCHAR(255),
    est_actif BOOLEAN DEFAULT true
);

-- Insertion des providers par défaut
INSERT INTO provider (id_provider, id_type_message, nom_provider, est_actif) VALUES
(gen_random_uuid(), 1, 'Octopush', true),
(gen_random_uuid(), 1, 'Allmysms', true),
(gen_random_uuid(), 2, 'Brevo', true),
(gen_random_uuid(), 2, 'Mailjet', true),
(gen_random_uuid(), 2, 'Gmail SMTP', true),
(gen_random_uuid(), 3, 'WhatsApp Business API', true),
(gen_random_uuid(), 3, 'Waha', true);

-- TABLE 7 : canal
CREATE TABLE IF NOT EXISTS canal (
    id_canal UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_compte UUID NOT NULL REFERENCES compte(id_compte) ON DELETE CASCADE,
    id_type_message INTEGER NOT NULL REFERENCES type_message(id_type_message),
    id_provider UUID NOT NULL REFERENCES provider(id_provider),
    nom_canal VARCHAR(100) NOT NULL,
    setup_du_canal JSONB NOT NULL,
    est_actif BOOLEAN DEFAULT true,
    date_creation TIMESTAMP DEFAULT NOW()
);

-- TABLE 8 : campagne
CREATE TABLE IF NOT EXISTS campagne (
    id_campagne UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_compte UUID NOT NULL REFERENCES compte(id_compte) ON DELETE CASCADE,
    id_liste UUID NOT NULL REFERENCES liste(id_liste),
    id_type_message INTEGER NOT NULL REFERENCES type_message(id_type_message),
    id_canal UUID NOT NULL REFERENCES canal(id_canal),
    nom_campagne VARCHAR(255) NOT NULL,
    objet VARCHAR(255),
    message TEXT NOT NULL,
    date_creation TIMESTAMP DEFAULT NOW(),
    date_planification TIMESTAMP,
    statut VARCHAR(20) DEFAULT 'PROGRAMMEE',
    CHECK (statut IN ('PROGRAMMEE', 'EN_COURS', 'ENVOYEE', 'ANNULEE', 'ECHEC'))
);

-- TABLE 9 : campagne_statut_log
CREATE TABLE IF NOT EXISTS campagne_statut_log (
    id_log UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_campagne UUID NOT NULL REFERENCES campagne(id_campagne) ON DELETE CASCADE,
    ancien_statut VARCHAR(20),
    nouveau_statut VARCHAR(20) NOT NULL,
    date_changement TIMESTAMP DEFAULT NOW(),
    utilisateur VARCHAR(100)
);

-- TABLE 10 : rapport_campagne
CREATE TABLE IF NOT EXISTS rapport_campagne (
    id_rapport UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_campagne UUID NOT NULL REFERENCES campagne(id_campagne) ON DELETE CASCADE,
    nb_envoyes INTEGER DEFAULT 0,
    nb_livres INTEGER DEFAULT 0,
    nb_erreurs INTEGER DEFAULT 0,
    nb_ouverts INTEGER DEFAULT 0,
    nb_clics INTEGER DEFAULT 0,
    date_generation TIMESTAMP DEFAULT NOW(),
    details_json JSONB
);

-- TABLE 11 : blacklist
CREATE TABLE IF NOT EXISTS blacklist (
    id_blacklist UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_type_message INTEGER NOT NULL REFERENCES type_message(id_type_message),
    id_contact UUID NOT NULL REFERENCES contact(id_contact) ON DELETE CASCADE,
    motif TEXT,
    date_ajout TIMESTAMP DEFAULT NOW(),
    UNIQUE(id_type_message, id_contact)
);

-- TABLE 12 : tarif
CREATE TABLE IF NOT EXISTS tarif (
    id_tarif UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_compte UUID NOT NULL REFERENCES compte(id_compte) ON DELETE CASCADE,
    id_canal UUID NOT NULL REFERENCES canal(id_canal) ON DELETE CASCADE,
    prix DECIMAL(10,4) NOT NULL,
    date_debut DATE DEFAULT CURRENT_DATE,
    date_fin DATE
);

-- TABLE 13 : credit
CREATE TABLE IF NOT EXISTS credit (
    id_credit UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_compte UUID NOT NULL REFERENCES compte(id_compte) ON DELETE CASCADE,
    type_mouvement VARCHAR(10) NOT NULL,
    id_reference UUID,
    montant DECIMAL(10,2) NOT NULL,
    description TEXT,
    date_time TIMESTAMP DEFAULT NOW(),
    CHECK (type_mouvement IN ('CREDIT', 'DEBIT'))
);

-- TABLE 14 : import_fichier
CREATE TABLE IF NOT EXISTS import_fichier (
    id_import UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_compte UUID NOT NULL REFERENCES compte(id_compte) ON DELETE CASCADE,
    nom_fichier VARCHAR(255) NOT NULL,
    type_fichier VARCHAR(20) NOT NULL,
    statut_import VARCHAR(20) DEFAULT 'EN_COURS',
    nb_lignes_total INTEGER DEFAULT 0,
    nb_lignes_importees INTEGER DEFAULT 0,
    nb_erreurs INTEGER DEFAULT 0,
    fichier_url VARCHAR(500),
    date_import TIMESTAMP DEFAULT NOW(),
    details_erreur JSONB
);

-- TABLE 15 : api_log
CREATE TABLE IF NOT EXISTS api_log (
    id_api_log UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    id_compte UUID REFERENCES compte(id_compte) ON DELETE SET NULL,
    api_key_utilisee VARCHAR(100),
    endpoint VARCHAR(255) NOT NULL,
    methode VARCHAR(10) NOT NULL,
    code_retour INTEGER NOT NULL,
    requete TEXT,
    reponse TEXT,
    ip_source INET,
    duree_ms INTEGER,
    date_heure TIMESTAMP DEFAULT NOW()
);