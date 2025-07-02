-- ============================================================================
-- BASE DE DONNÉES - PLATEFORME DE GESTION DES PROJETS ÉTUDIANTS
-- Université Nazi BONI (UNB) - École Supérieure d'Informatique (ESI)
-- Version avec gestion sécurisée des enseignants par administrateur
-- ============================================================================

-- Suppression des tables existantes (ordre inverse des dépendances)
DROP TABLE IF EXISTS actions_administrateur CASCADE;
DROP TABLE IF EXISTS historique_statut_projets CASCADE;
DROP TABLE IF EXISTS commentaires CASCADE;
DROP TABLE IF EXISTS projets CASCADE;
DROP TABLE IF EXISTS utilisateurs CASCADE;
DROP TABLE IF EXISTS filieres CASCADE;

-- ============================================================================
-- TABLE FILIERES - Filières universitaires
-- ============================================================================
CREATE TABLE filieres (
    id SERIAL PRIMARY KEY,
    code VARCHAR(20) UNIQUE NOT NULL,
    nom VARCHAR(150) NOT NULL,
    niveau_max INTEGER DEFAULT 5,
    actif BOOLEAN DEFAULT TRUE,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- TABLE UTILISATEURS - Gestion des utilisateurs du système
-- ============================================================================
CREATE TABLE utilisateurs (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    mot_de_passe_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('etudiant', 'enseignant', 'administrateur')),
    filiere_id INTEGER REFERENCES filieres(id),
    niveau VARCHAR(50),
    telephone VARCHAR(20),
    cree_par INTEGER REFERENCES utilisateurs(id),
    actif BOOLEAN DEFAULT TRUE,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- TABLE PROJETS - Projets soumis par les étudiants
-- ============================================================================
CREATE TABLE projets (
    id SERIAL PRIMARY KEY,
    titre VARCHAR(255) NOT NULL,
    description TEXT,
    type_projet VARCHAR(50) NOT NULL CHECK (type_projet IN ('projet_tutore', 'memoire', 'stage', 'autre')),
    fichier_nom_original VARCHAR(255),
    fichier_chemin VARCHAR(500),
    fichier_taille INTEGER,
    statut VARCHAR(30) DEFAULT 'soumis' CHECK (statut IN ('soumis', 'en_cours_evaluation', 'valide', 'refuse', 'en_revision')),
    etudiant_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    encadrant_id INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    mode_assignation VARCHAR(20) DEFAULT 'manuel' CHECK (mode_assignation IN ('manuel', 'automatique')),
    note_finale DECIMAL(4,2),
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- TABLE COMMENTAIRES - Commentaires et feedback sur les projets
-- ============================================================================
CREATE TABLE commentaires (
    id SERIAL PRIMARY KEY,
    projet_id INTEGER NOT NULL REFERENCES projets(id) ON DELETE CASCADE,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    contenu TEXT NOT NULL,
    type_commentaire VARCHAR(20) DEFAULT 'feedback' CHECK (type_commentaire IN ('feedback', 'revision', 'validation', 'rejet')),
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- TABLE HISTORIQUE_STATUT_PROJETS - Historique des changements de statut
-- ============================================================================
CREATE TABLE historique_statut_projets (
    id SERIAL PRIMARY KEY,
    projet_id INTEGER NOT NULL REFERENCES projets(id) ON DELETE CASCADE,
    ancien_statut VARCHAR(30),
    nouveau_statut VARCHAR(30),
    motif TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- TABLE ACTIONS_ADMINISTRATEUR - Traçabilité des actions administratives
-- ============================================================================
CREATE TABLE actions_administrateur (
    id SERIAL PRIMARY KEY,
    administrateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    action VARCHAR(50) NOT NULL,
    utilisateur_cible_id INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    projet_cible_id INTEGER REFERENCES projets(id) ON DELETE SET NULL,
    details TEXT,
    adresse_ip INET,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- INDEX POUR OPTIMISER LES PERFORMANCES
-- ============================================================================

-- Index sur la table filieres
CREATE INDEX idx_filieres_code ON filieres(code);
CREATE INDEX idx_filieres_actif ON filieres(actif);

-- Index sur la table utilisateurs
CREATE INDEX idx_utilisateurs_email ON utilisateurs(email);
CREATE INDEX idx_utilisateurs_role ON utilisateurs(role);
CREATE INDEX idx_utilisateurs_actif ON utilisateurs(actif);
CREATE INDEX idx_utilisateurs_filiere_id ON utilisateurs(filiere_id);
CREATE INDEX idx_utilisateurs_cree_par ON utilisateurs(cree_par);

-- Index sur la table projets
CREATE INDEX idx_projets_etudiant_id ON projets(etudiant_id);
CREATE INDEX idx_projets_encadrant_id ON projets(encadrant_id);
CREATE INDEX idx_projets_statut ON projets(statut);
CREATE INDEX idx_projets_type_projet ON projets(type_projet);
CREATE INDEX idx_projets_date_creation ON projets(date_creation);

-- Index sur la table commentaires
CREATE INDEX idx_commentaires_projet_id ON commentaires(projet_id);
CREATE INDEX idx_commentaires_utilisateur_id ON commentaires(utilisateur_id);
CREATE INDEX idx_commentaires_type ON commentaires(type_commentaire);

-- Index sur la table historique_statut_projets
CREATE INDEX idx_historique_projet_id ON historique_statut_projets(projet_id);
CREATE INDEX idx_historique_modifie_par ON historique_statut_projets(modifie_par);

-- Index sur la table actions_administrateur
CREATE INDEX idx_actions_admin_id ON actions_administrateur(administrateur_id);
CREATE INDEX idx_actions_utilisateur_cible ON actions_administrateur(utilisateur_cible_id);
CREATE INDEX idx_actions_action ON actions_administrateur(action);

-- ============================================================================
-- TRIGGERS POUR UPDATE AUTOMATIQUE DU TIMESTAMP
-- ============================================================================

-- Fonction pour mettre à jour date_modification
CREATE OR REPLACE FUNCTION maj_date_modification()
RETURNS TRIGGER AS $$
BEGIN
    NEW.date_modification = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Trigger sur table utilisateurs
CREATE TRIGGER maj_utilisateurs_date_modification
    BEFORE UPDATE ON utilisateurs
    FOR EACH ROW
    EXECUTE FUNCTION maj_date_modification();

-- Trigger sur table projets
CREATE TRIGGER maj_projets_date_modification
    BEFORE UPDATE ON projets
    FOR EACH ROW
    EXECUTE FUNCTION maj_date_modification();

-- ============================================================================
-- DONNÉES D'INITIALISATION
-- ============================================================================

-- Insertion des filières de base
INSERT INTO filieres (code, nom, niveau_max) VALUES
('INFO', 'Informatique', 5);

-- Création du premier administrateur (mot de passe: Admin123!)
-- ATTENTION: Remplacer 'hash_du_mot_de_passe' par un vrai hash bcrypt
INSERT INTO utilisateurs(nom, prenom, email, mot_de_passe_hash, role, actif) VALUES
('Konate', 'Patrice', 'siekonate9@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'administrateur', TRUE);

-- ============================================================================
-- VUES UTILES POUR LES REQUÊTES FRÉQUENTES
-- ============================================================================

-- Vue pour les projets avec informations des utilisateurs
CREATE VIEW vue_details_projets AS
SELECT 
    p.id,
    p.titre,
    p.description,
    p.type_projet,
    p.statut,
    p.note_finale,
    p.date_creation,
    p.date_modification,
    -- Informations étudiant
    u_etudiant.nom AS etudiant_nom,
    u_etudiant.prenom AS etudiant_prenom,
    u_etudiant.email AS etudiant_email,
    f.nom AS etudiant_filiere,
    u_etudiant.niveau AS etudiant_niveau,
    -- Informations encadrant
    u_encadrant.nom AS encadrant_nom,
    u_encadrant.prenom AS encadrant_prenom,
    u_encadrant.email AS encadrant_email
FROM projets p
    LEFT JOIN utilisateurs u_etudiant ON p.etudiant_id = u_etudiant.id
    LEFT JOIN utilisateurs u_encadrant ON p.encadrant_id = u_encadrant.id
    LEFT JOIN filieres f ON u_etudiant.filiere_id = f.id;

-- Vue pour les utilisateurs avec leur créateur
CREATE VIEW vue_utilisateurs_avec_createur AS
SELECT 
    u.id,
    u.nom,
    u.prenom,
    u.email,
    u.role,
    u.actif,
    u.date_creation,
    f.nom as filiere,
    CASE 
        WHEN u.cree_par IS NULL THEN 'Auto-inscription'
        ELSE CONCAT(admin.prenom, ' ', admin.nom)
    END as cree_par_nom
FROM utilisateurs u
    LEFT JOIN utilisateurs admin ON u.cree_par = admin.id
    LEFT JOIN filieres f ON u.filiere_id = f.id;

-- Vue pour les statistiques des projets par statut
CREATE VIEW vue_stats_projets_statut AS
SELECT 
    statut,
    COUNT(*) as nombre_projets,
    ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as pourcentage
FROM projets 
GROUP BY statut;

-- Vue pour les statistiques par filière
CREATE VIEW vue_stats_projets_filiere AS
SELECT 
    f.nom as filiere,
    COUNT(p.id) as nombre_projets,
    AVG(p.note_finale) as moyenne_notes
FROM projets p
    JOIN utilisateurs u ON p.etudiant_id = u.id
    JOIN filieres f ON u.filiere_id = f.id
GROUP BY f.nom;

-- Vue pour les enseignants et leurs projets encadrés
CREATE VIEW vue_enseignants_projets AS
SELECT 
    u.id as enseignant_id,
    u.nom as enseignant_nom,
    u.prenom as enseignant_prenom,
    u.email as enseignant_email,
    COUNT(p.id) as nombre_projets_encadres,
    COUNT(CASE WHEN p.statut = 'valide' THEN 1 END) as projets_valides,
    COUNT(CASE WHEN p.statut = 'en_cours_evaluation' THEN 1 END) as projets_en_cours
FROM utilisateurs u
    LEFT JOIN projets p ON u.id = p.encadrant_id
WHERE u.role = 'enseignant' AND u.actif = TRUE
GROUP BY u.id, u.nom, u.prenom, u.email;

-- ============================================================================
-- FONCTIONS UTILES
-- ============================================================================

-- Fonction pour assigner automatiquement un encadrant
CREATE OR REPLACE FUNCTION assigner_encadrant_automatique()
RETURNS TRIGGER AS $$
DECLARE
    encadrant_id_choisi INTEGER;
BEGIN
    -- Si mode automatique et pas d'encadrant assigné
    IF NEW.mode_assignation = 'automatique' AND NEW.encadrant_id IS NULL THEN
        -- Choisir l'enseignant avec le moins de projets en cours
        SELECT u.id INTO encadrant_id_choisi
        FROM utilisateurs u
            LEFT JOIN projets p ON u.id = p.encadrant_id AND p.statut IN ('soumis', 'en_cours_evaluation')
        WHERE u.role = 'enseignant' AND u.actif = TRUE
        GROUP BY u.id
        ORDER BY COUNT(p.id) ASC
        LIMIT 1;
        
        NEW.encadrant_id = encadrant_id_choisi;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger pour assignation automatique
CREATE TRIGGER trigger_assignation_automatique
    BEFORE INSERT ON projets
    FOR EACH ROW
    EXECUTE FUNCTION assigner_encadrant_automatique();

-- ============================================================================
-- COMMENTAIRES ET DOCUMENTATION
-- ============================================================================

COMMENT ON TABLE filieres IS 'Table des filières universitaires disponibles';
COMMENT ON TABLE utilisateurs IS 'Table des utilisateurs: étudiants, enseignants, administrateurs';
COMMENT ON TABLE projets IS 'Table des projets soumis par les étudiants';
COMMENT ON TABLE commentaires IS 'Table des commentaires et feedback sur les projets';
COMMENT ON TABLE historique_statut_projets IS 'Historique de tous les changements de statut des projets';
COMMENT ON TABLE actions_administrateur IS 'Traçabilité de toutes les actions administratives sensibles';

COMMENT ON COLUMN utilisateurs.mot_de_passe_hash IS 'Mot de passe crypté avec bcrypt';
COMMENT ON COLUMN utilisateurs.cree_par IS 'ID de l''administrateur qui a créé ce compte (NULL pour auto-inscription étudiants)';
COMMENT ON COLUMN projets.fichier_chemin IS 'Chemin de stockage sécurisé du fichier uploadé';
COMMENT ON COLUMN projets.mode_assignation IS 'Manuel: choisi par étudiant, Automatique: assigné par système';
COMMENT ON COLUMN actions_administrateur.adresse_ip IS 'Adresse IP pour traçabilité sécuritaire';

-- ============================================================================
-- VÉRIFICATIONS ET VALIDATIONS
-- ============================================================================

-- Vérifier que la base est bien créée
SELECT 'Base de données créée avec succès!' as message;

-- Compter les tables créées
SELECT COUNT(*) as nombre_tables 
FROM information_schema.tables 
WHERE table_schema = 'public' AND table_type = 'BASE TABLE';

-- Afficher un résumé de la structure
SELECT 
    'Tables: ' || COUNT(CASE WHEN table_type = 'BASE TABLE' THEN 1 END) ||
    ', Vues: ' || COUNT(CASE WHEN table_type = 'VIEW' THEN 1 END) as resume_structure
FROM information_schema.tables 
WHERE table_schema = 'public';

-- Vérifier les contraintes de sécurité
SELECT 
    table_name,
    column_name,
    data_type,
    is_nullable,
    column_default
FROM information_schema.columns
WHERE table_schema = 'public' 
AND table_name = 'utilisateurs'
AND column_name IN ('role', 'cree_par', 'actif')
ORDER BY table_name, ordinal_position;
