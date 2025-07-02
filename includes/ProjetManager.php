<?php
/**
 * Classe pour la gestion des projets étudiants
 * Basée sur la structure de base de données fournie
 */

class ProjetManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Créer un nouveau projet
     */
    public function creerProjet($data) {
        try {
            $this->pdo->beginTransaction();
            
            $sql = "INSERT INTO projets (
                titre, description, type_projet, 
                fichier_nom_original, fichier_chemin, fichier_taille,
                etudiant_id, encadrant_id, mode_assignation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $data['titre'],
                $data['description'],
                $data['type_projet'],
                $data['fichier_nom_original'] ?? null,
                $data['fichier_chemin'] ?? null,
                $data['fichier_taille'] ?? null,
                $data['etudiant_id'],
                $data['encadrant_id'] ?? null,
                $data['mode_assignation'] ?? 'manuel'
            ]);
            
            $projet_id = $this->pdo->lastInsertId();
            
            // Enregistrer dans l'historique
            $this->ajouterHistoriqueStatut($projet_id, null, 'soumis', 'Projet créé', $data['etudiant_id']);
            
            $this->pdo->commit();
            return $projet_id;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Erreur lors de la création du projet: " . $e->getMessage());
        }
    }
    
    /**
     * Récupérer les projets d'un étudiant
     */
    public function getProjetsEtudiant($etudiant_id, $limit = null) {
        $sql = "SELECT 
            p.*,
            u_encadrant.nom as encadrant_nom,
            u_encadrant.prenom as encadrant_prenom
        FROM projets p
        LEFT JOIN utilisateurs u_encadrant ON p.encadrant_id = u_encadrant.id
        WHERE p.etudiant_id = ?
        ORDER BY p.date_creation DESC";
        
        if ($limit) {
            $sql .= " LIMIT " . intval($limit);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$etudiant_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Récupérer les projets à évaluer pour un encadrant
     */
    public function getProjetsAEvaluer($encadrant_id) {
        $sql = "SELECT 
            p.*,
            u_etudiant.nom as etudiant_nom,
            u_etudiant.prenom as etudiant_prenom,
            u_etudiant.email as etudiant_email,
            f.nom as etudiant_filiere
        FROM projets p
        JOIN utilisateurs u_etudiant ON p.etudiant_id = u_etudiant.id
        LEFT JOIN filieres f ON u_etudiant.filiere_id = f.id
        WHERE p.encadrant_id = ? 
        AND p.statut IN ('soumis', 'en_cours_evaluation', 'en_revision')
        ORDER BY p.date_creation ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$encadrant_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Changer le statut d'un projet
     */
    public function changerStatut($projet_id, $nouveau_statut, $motif, $utilisateur_id, $note = null) {
        try {
            $this->pdo->beginTransaction();
            
            // Récupérer l'ancien statut
            $stmt = $this->pdo->prepare("SELECT statut FROM projets WHERE id = ?");
            $stmt->execute([$projet_id]);
            $ancien_statut = $stmt->fetchColumn();
            
            // Mettre à jour le projet
            $sql = "UPDATE projets SET statut = ?";
            $params = [$nouveau_statut];
            
            if ($note !== null) {
                $sql .= ", note_finale = ?";
                $params[] = $note;
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $projet_id;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            // Enregistrer dans l'historique
            $this->ajouterHistoriqueStatut($projet_id, $ancien_statut, $nouveau_statut, $motif, $utilisateur_id);
            
            $this->pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Erreur lors du changement de statut: " . $e->getMessage());
        }
    }
    
    /**
     * Ajouter un commentaire à un projet
     */
    public function ajouterCommentaire($projet_id, $utilisateur_id, $contenu, $type = 'feedback') {
        $sql = "INSERT INTO commentaires (projet_id, utilisateur_id, contenu, type_commentaire) 
                VALUES (?, ?, ?, ?)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$projet_id, $utilisateur_id, $contenu, $type]);
    }
    
    /**
     * Récupérer les commentaires d'un projet
     */
    public function getCommentaires($projet_id) {
        $sql = "SELECT 
            c.*,
            u.nom as auteur_nom,
            u.prenom as auteur_prenom,
            u.role as auteur_role
        FROM commentaires c
        JOIN utilisateurs u ON c.utilisateur_id = u.id
        WHERE c.projet_id = ?
        ORDER BY c.date_creation DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$projet_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Récupérer l'historique d'un projet
     */
    public function getHistoriqueStatut($projet_id) {
        $sql = "SELECT 
            h.*,
            u.nom as modifie_par_nom,
            u.prenom as modifie_par_prenom
        FROM historique_statut_projets h
        LEFT JOIN utilisateurs u ON h.modifie_par = u.id
        WHERE h.projet_id = ?
        ORDER BY h.date_modification DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$projet_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Statistiques des projets d'un étudiant
     */
    public function getStatistiquesEtudiant($etudiant_id) {
        $sql = "SELECT 
            COUNT(*) as projets_soumis,
            SUM(CASE WHEN statut = 'valide' THEN 1 ELSE 0 END) as projets_valides,
            SUM(CASE WHEN statut = 'en_cours_evaluation' THEN 1 ELSE 0 END) as projets_en_cours,
            SUM(CASE WHEN statut = 'refuse' THEN 1 ELSE 0 END) as projets_refuses,
            SUM(CASE WHEN statut = 'en_revision' THEN 1 ELSE 0 END) as projets_en_revision,
            AVG(CASE WHEN statut = 'valide' AND note_finale IS NOT NULL THEN note_finale ELSE NULL END) as moyenne_notes
        FROM projets 
        WHERE etudiant_id = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$etudiant_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Récupérer un projet par ID avec détails complets
     */
    public function getProjetComplet($projet_id) {
        $sql = "SELECT * FROM vue_details_projets WHERE id = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$projet_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Liste des enseignants pour assignation
     */
    public function getEnseignantsDisponibles() {
        $sql = "SELECT 
            u.id,
            u.nom,
            u.prenom,
            u.email,
            COUNT(p.id) as nb_projets_actuels
        FROM utilisateurs u
        LEFT JOIN projets p ON u.id = p.encadrant_id 
            AND p.statut IN ('soumis', 'en_cours_evaluation', 'en_revision')
        WHERE u.role = 'enseignant' AND u.actif = TRUE
        GROUP BY u.id, u.nom, u.prenom, u.email
        ORDER BY nb_projets_actuels ASC, u.nom ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Méthode privée pour ajouter à l'historique
     */
    private function ajouterHistoriqueStatut($projet_id, $ancien_statut, $nouveau_statut, $motif, $utilisateur_id) {
        $sql = "INSERT INTO historique_statut_projets 
                (projet_id, ancien_statut, nouveau_statut, motif, modifie_par) 
                VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$projet_id, $ancien_statut, $nouveau_statut, $motif, $utilisateur_id]);
    }
    
    /**
     * Rechercher des projets avec filtres
     */
    public function rechercherProjets($filtres = []) {
        $conditions = [];
        $params = [];
        
        $sql = "SELECT * FROM vue_details_projets WHERE 1=1";
        
        if (!empty($filtres['statut'])) {
            $conditions[] = "statut = ?";
            $params[] = $filtres['statut'];
        }
        
        if (!empty($filtres['type_projet'])) {
            $conditions[] = "type_projet = ?";
            $params[] = $filtres['type_projet'];
        }
        
        if (!empty($filtres['encadrant_id'])) {
            $conditions[] = "encadrant_id = ?";
            $params[] = $filtres['encadrant_id'];
        }
        
        if (!empty($filtres['etudiant_filiere'])) {
            $conditions[] = "etudiant_filiere ILIKE ?";
            $params[] = '%' . $filtres['etudiant_filiere'] . '%';
        }
        
        if (!empty($filtres['date_debut'])) {
            $conditions[] = "date_creation >= ?";
            $params[] = $filtres['date_debut'];
        }
        
        if (!empty($filtres['date_fin'])) {
            $conditions[] = "date_creation <= ?";
            $params[] = $filtres['date_fin'];
        }
        
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        $sql .= " ORDER BY date_creation DESC";
        
        if (!empty($filtres['limit'])) {
            $sql .= " LIMIT " . intval($filtres['limit']);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Fonctions utilitaires pour l'affichage
 */

function getStatutBadge($statut) {
    $badges = [
        'soumis' => '<span class="bg-blue-100 text-blue-800 text-xs font-medium px-2 py-1 rounded-full">Soumis</span>',
        'en_cours_evaluation' => '<span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2 py-1 rounded-full">En évaluation</span>',
        'valide' => '<span class="bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded-full">Validé</span>',
        'refuse' => '<span class="bg-red-100 text-red-800 text-xs font-medium px-2 py-1 rounded-full">Refusé</span>',
        'en_revision' => '<span class="bg-orange-100 text-orange-800 text-xs font-medium px-2 py-1 rounded-full">En révision</span>'
    ];
    
    return $badges[$statut] ?? '<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2 py-1 rounded-full">Inconnu</span>';
}





function getTypeProjetNom($type) {
    $types = [
        'projet_tutore' => 'Projet Tutoré',
        'memoire' => 'Mémoire',
        'stage' => 'Rapport de Stage',
        'autre' => 'Autre'
    ];
    
    return $types[$type] ?? $type;
}
?>
