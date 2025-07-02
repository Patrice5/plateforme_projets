<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier si l'utilisateur est connecté et a un rôle autorisé
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['enseignant', 'etudiant'])) {
    http_response_code(403);
    echo '<div class="text-center py-12"><i class="fas fa-lock text-red-500 text-4xl mb-4"></i><p class="text-red-600">Accès non autorisé</p></div>';
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$projet_id = (int)($_GET['id'] ?? 0);

if (!$projet_id) {
    echo '<div class="text-center py-12"><i class="fas fa-exclamation-triangle text-red-500 text-4xl mb-4"></i><p class="text-red-600">ID de projet invalide</p></div>';
    exit();
}

try {
    // Construire la requête selon le rôle de l'utilisateur
    if ($user_role === 'enseignant') {
        // L'enseignant peut voir les projets qu'il encadre
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                e.nom as etudiant_nom,
                e.prenom as etudiant_prenom,
                e.email as etudiant_email,
                e.niveau as etudiant_niveau,
                f.nom as filiere_nom,
                f.code as filiere_code,
                enc.nom as encadrant_nom,
                enc.prenom as encadrant_prenom
            FROM projets p
            LEFT JOIN utilisateurs e ON p.etudiant_id = e.id
            LEFT JOIN filieres f ON e.filiere_id = f.id
            LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
            WHERE p.id = ? AND p.encadrant_id = ?
        ");
        $stmt->execute([$projet_id, $user_id]);
    } else {
        // L'étudiant peut voir ses propres projets
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                e.nom as etudiant_nom,
                e.prenom as etudiant_prenom,
                e.email as etudiant_email,
                e.niveau as etudiant_niveau,
                f.nom as filiere_nom,
                f.code as filiere_code,
                enc.nom as encadrant_nom,
                enc.prenom as encadrant_prenom
            FROM projets p
            LEFT JOIN utilisateurs e ON p.etudiant_id = e.id
            LEFT JOIN filieres f ON e.filiere_id = f.id
            LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
            WHERE p.id = ? AND p.etudiant_id = ?
        ");
        $stmt->execute([$projet_id, $user_id]);
    }
    
    $projet = $stmt->fetch();

    if (!$projet) {
        echo '<div class="text-center py-12"><i class="fas fa-exclamation-triangle text-red-500 text-4xl mb-4"></i><p class="text-red-600">Projet non trouvé ou accès non autorisé</p></div>';
        exit();
    }

    // Récupérer les commentaires
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            u.nom as auteur_nom,
            u.prenom as auteur_prenom,
            u.role as auteur_role
        FROM commentaires c
        LEFT JOIN utilisateurs u ON c.utilisateur_id = u.id
        WHERE c.projet_id = ?
        ORDER BY c.date_creation DESC
    ");
    $stmt->execute([$projet_id]);
    $commentaires = $stmt->fetchAll();

    // Récupérer l'historique des statuts
    $stmt = $pdo->prepare("
        SELECT 
            h.*,
            u.nom as modifie_par_nom,
            u.prenom as modifie_par_prenom
        FROM historique_statut_projets h
        LEFT JOIN utilisateurs u ON h.modifie_par = u.id
        WHERE h.projet_id = ?
        ORDER BY h.date_modification DESC
    ");
    $stmt->execute([$projet_id]);
    $historique = $stmt->fetchAll();

    function getStatutLibelle($statut) {
        return match($statut) {
            'soumis' => 'Soumis',
            'en_cours_evaluation' => 'En évaluation',
            'valide' => 'Validé',
            'refuse' => 'Refusé',
            'en_revision' => 'En révision',
            default => ucfirst($statut)
        };
    }

    function getTypeProjetLibelle($type) {
        return match($type) {
            'projet_tutore' => 'Projet Tutoré',
            'memoire' => 'Mémoire',
            'stage' => 'Rapport de Stage',
            'autre' => 'Autre',
            default => ucfirst($type)
        };
    }

    function getCommentaireTypeClass($type) {
        return match($type) {
            'validation' => 'bg-green-50 border-l-4 border-green-400',
            'rejet' => 'bg-red-50 border-l-4 border-red-400',
            'revision' => 'bg-orange-50 border-l-4 border-orange-400',
            default => 'bg-gray-50 border-l-4 border-gray-400'
        };
    }

    function getCommentaireIcon($type) {
        return match($type) {
            'validation' => 'fas fa-check-circle text-green-600',
            'rejet' => 'fas fa-times-circle text-red-600',
            'revision' => 'fas fa-edit text-orange-600',
            default => 'fas fa-comment text-gray-600'
        };
    }

?>

<div class="space-y-6">
    <!-- Informations principales du projet -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($projet['titre']); ?></h3>
                <div class="flex items-center space-x-4 mt-2 text-sm text-gray-600">
                    <span><i class="fas fa-calendar mr-1"></i>Créé le <?php echo date('d/m/Y à H:i', strtotime($projet['date_creation'])); ?></span>
                    <span><i class="fas fa-edit mr-1"></i>Modifié le <?php echo date('d/m/Y à H:i', strtotime($projet['date_modification'])); ?></span>
                </div>
            </div>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo getStatutClass($projet['statut']); ?>">
                <?php echo getStatutLibelle($projet['statut']); ?>
            </span>
        </div>

        <?php if ($projet['description']): ?>
            <div class="mb-4">
                <h4 class="font-medium text-gray-700 mb-2">Description</h4>
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($projet['description']); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Informations étudiant (visible pour les enseignants) ou encadrant (visible pour les étudiants) -->
            <div>
                <?php if ($user_role === 'enseignant'): ?>
                    <h4 class="font-medium text-gray-700 mb-3">Étudiant</h4>
                    <div class="space-y-2 text-sm">
                        <div><span class="font-medium">Nom:</span> <?php echo htmlspecialchars($projet['etudiant_prenom'] . ' ' . $projet['etudiant_nom']); ?></div>
                        <div><span class="font-medium">Email:</span> <?php echo htmlspecialchars($projet['etudiant_email']); ?></div>
                        <div><span class="font-medium">Filière:</span> <?php echo htmlspecialchars($projet['filiere_nom'] ?? 'Non spécifiée'); ?></div>
                        <div><span class="font-medium">Niveau:</span> <?php echo htmlspecialchars($projet['etudiant_niveau'] ?? 'Non spécifié'); ?></div>
                    </div>
                <?php else: ?>
                    <h4 class="font-medium text-gray-700 mb-3">Encadrant</h4>
                    <div class="space-y-2 text-sm">
                        <div><span class="font-medium">Nom:</span> <?php echo htmlspecialchars($projet['encadrant_prenom'] . ' ' . $projet['encadrant_nom']); ?></div>
                        <div><span class="font-medium">Rôle:</span> Enseignant</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Informations projet -->
            <div>
                <h4 class="font-medium text-gray-700 mb-3">Détails du projet</h4>
                <div class="space-y-2 text-sm">
                    <div><span class="font-medium">Type:</span> <?php echo getTypeProjetLibelle($projet['type_projet']); ?></div>
                    <div><span class="font-medium">Mode d'assignation:</span> <?php echo ucfirst($projet['mode_assignation']); ?></div>
                    <?php if ($projet['fichier_nom_original']): ?>
                        <div><span class="font-medium">Fichier:</span> <?php echo htmlspecialchars($projet['fichier_nom_original']); ?></div>
                        <div><span class="font-medium">Taille:</span> <?php echo number_format($projet['fichier_taille'] / 1024, 2); ?> KB</div>
                    <?php endif; ?>
                    <?php if ($projet['note_finale']): ?>
                        <div><span class="font-medium">Note finale:</span> 
                            <span class="font-bold text-lg <?php echo $projet['note_finale'] >= 10 ? 'text-green-600' : 'text-red-600'; ?>">
                                <?php echo $projet['note_finale']; ?>/20
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Historique des statuts -->
    <?php if (!empty($historique)): ?>
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <h4 class="font-medium text-gray-700 mb-4">
                <i class="fas fa-history mr-2"></i>Historique des statuts
            </h4>
            <div class="space-y-3">
                <?php foreach ($historique as $h): ?>
                    <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg">
                        <div class="flex-shrink-0 w-2 h-2 bg-gray-400 rounded-full mt-2"></div>
                        <div class="flex-1">
                            <div class="flex items-center space-x-2 text-sm">
                                <span class="font-medium">
                                    <?php echo htmlspecialchars($h['modifie_par_prenom'] . ' ' . $h['modifie_par_nom']); ?>
                                </span>
                                <span class="text-gray-500">a changé le statut de</span>
                                <span class="px-2 py-1 bg-gray-200 text-gray-700 rounded text-xs"><?php echo getStatutLibelle($h['ancien_statut']); ?></span>
                                <span class="text-gray-500">vers</span>
                                <span class="px-2 py-1 <?php echo getStatutClass($h['nouveau_statut']); ?> rounded text-xs"><?php echo getStatutLibelle($h['nouveau_statut']); ?></span>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                <?php echo date('d/m/Y à H:i', strtotime($h['date_modification'])); ?>
                            </div>
                            <?php if ($h['motif']): ?>
                                <div class="mt-2 p-2 bg-white rounded border text-sm">
                                    <span class="font-medium text-gray-700">Motif:</span>
                                    <p class="text-gray-700 mt-1"><?php echo htmlspecialchars($h['motif']); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Commentaires -->
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h4 class="font-medium text-gray-700 mb-4">
            <i class="fas fa-comments mr-2"></i>Commentaires et feedback
        </h4>
        
        <?php if (!empty($commentaires)): ?>
            <div class="space-y-4">
                <?php foreach ($commentaires as $c): ?>
                    <div class="<?php echo getCommentaireTypeClass($c['type_commentaire']); ?> p-4 rounded-lg">
                        <div class="flex items-start space-x-3">
                            <i class="<?php echo getCommentaireIcon($c['type_commentaire']); ?> mt-1"></i>
                            <div class="flex-1">
                                <div class="flex items-center space-x-2 mb-2">
                                    <span class="font-medium text-gray-800">
                                        <?php echo htmlspecialchars($c['auteur_prenom'] . ' ' . $c['auteur_nom']); ?>
                                    </span>
                                    <span class="text-xs text-gray-500">
                                        (<?php echo ucfirst($c['auteur_role']); ?>)
                                    </span>
                                    <span class="text-xs text-gray-500">•</span>
                                    <span class="text-xs text-gray-500">
                                        <?php echo date('d/m/Y à H:i', strtotime($c['date_creation'])); ?>
                                    </span>
                                </div>
                                <p class="text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($c['contenu']); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-comment-slash text-3xl mb-2"></i>
                <p>Aucun commentaire pour ce projet</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Actions rapides - Adaptées selon le rôle -->
    <div class="bg-gray-50 rounded-lg p-4">
        <div class="flex flex-wrap gap-3 justify-center">
            <?php if ($projet['fichier_chemin']): ?>
                <a href="download.php?id=<?php echo $projet['id']; ?>" 
                   class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                    <i class="fas fa-download mr-2"></i>
                    Télécharger le fichier
                </a>
            <?php endif; ?>
            
            <?php if ($user_role === 'enseignant'): ?>
                <!-- Actions spécifiques aux enseignants -->
                <button onclick="closeDetailsModal(); openEvaluationModal(<?php echo htmlspecialchars(json_encode($projet)); ?>)"
                        class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-colors">
                    <i class="fas fa-edit mr-2"></i>
                    Évaluer ce projet
                </button>
                
                <a href="mailto:<?php echo htmlspecialchars($projet['etudiant_email']); ?>" 
                   class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors">
                    <i class="fas fa-envelope mr-2"></i>
                    Contacter l'étudiant
                </a>
            <?php else: ?>
                <!-- Actions spécifiques aux étudiants -->
                <?php if (in_array($projet['statut'], ['refuse', 'en_revision'])): ?>
                    <button onclick="closeDetailsModal(); openUploadModal(<?php echo $projet['id']; ?>)"
                            class="inline-flex items-center px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 transition-colors">
                        <i class="fas fa-upload mr-2"></i>
                        Resoumetre le projet
                    </button>
                <?php endif; ?>
                
                <button onclick="closeDetailsModal(); openCommentModal(<?php echo $projet['id']; ?>)"
                        class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 transition-colors">
                    <i class="fas fa-comment mr-2"></i>
                    Ajouter un commentaire
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php

} catch (PDOException $e) {
    error_log("Erreur get_projet_details: " . $e->getMessage());
    echo '<div class="text-center py-12"><i class="fas fa-exclamation-triangle text-red-500 text-4xl mb-4"></i><p class="text-red-600">Erreur lors du chargement des détails</p></div>';
}
?>
