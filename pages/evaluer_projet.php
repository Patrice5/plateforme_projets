<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier si l'utilisateur est connecté est un enseignant
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'enseignant') {
    header('Location: ../index.php');
    exit();
}

// Récupérer les informations de l'utilisateur immédiatement après la vérification de session
$user_id = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        logoutUser();
        header('Location: ../index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Erreur récupération utilisateur: " . $e->getMessage());
    header('Location: ../index.php');
    exit();
}

// Maintenant que $user est défini, on peut accéder à filiere_id
$filiere = null;
if ($user['filiere_id']) {
    $stmt = $pdo->prepare("SELECT * FROM filieres WHERE id = ?");
    $stmt->execute([$user['filiere_id']]);
    $filiere = $stmt->fetch();
}

$message = '';
$error = '';

// Traitement de l'ajout de commentaire
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'ajouter_commentaire') {
    try {
        $projet_id = (int)$_POST['projet_id'];
        $contenu = trim($_POST['contenu']);
        $type_commentaire = $_POST['type_commentaire'] ?? 'feedback';
        
        if (empty($contenu)) {
            throw new Exception("Le contenu du commentaire ne peut pas être vide");
        }
        
        // Vérifier que le projet appartient bien à cet encadrant
        $stmt = $pdo->prepare("SELECT id FROM projets WHERE id = ? AND encadrant_id = ?");
        $stmt->execute([$projet_id, $user_id]);
        $projet = $stmt->fetch();
        
        if (!$projet) {
            throw new Exception("Projet non trouvé ou accès non autorisé");
        }
        
        // Ajouter le commentaire
        $stmt = $pdo->prepare("
            INSERT INTO commentaires (projet_id, utilisateur_id, contenu, type_commentaire, date_creation)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$projet_id, $user_id, $contenu, $type_commentaire]);
        
        $message = "Commentaire ajouté avec succès";
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Traitement du changement de statut
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'changer_statut') {
    try {
        $projet_id = (int)$_POST['projet_id'];
        $nouveau_statut = $_POST['nouveau_statut'];
        $motif = trim($_POST['motif'] ?? '');
        $note_finale = !empty($_POST['note_finale']) ? (float)$_POST['note_finale'] : null;
        
        // Vérifier que le projet appartient bien à cet encadrant
        $stmt = $pdo->prepare("SELECT statut FROM projets WHERE id = ? AND encadrant_id = ?");
        $stmt->execute([$projet_id, $user_id]);
        $projet = $stmt->fetch();
        
        if (!$projet) {
            throw new Exception("Projet non trouvé ou accès non autorisé");
        }
        
        $ancien_statut = $projet['statut'];
        
        // Validation des transitions de statut
        $transitions_valides = [
            'soumis' => ['en_cours_evaluation', 'valide', 'refuse', 'en_revision'],
            'en_cours_evaluation' => ['valide', 'refuse', 'en_revision'],
            'en_revision' => ['en_cours_evaluation', 'valide', 'refuse']
        ];
        
        if (!isset($transitions_valides[$ancien_statut]) || 
            !in_array($nouveau_statut, $transitions_valides[$ancien_statut])) {
            throw new Exception("Transition de statut non autorisée");
        }
        
        // Validation obligatoire du motif pour certains statuts
        if (in_array($nouveau_statut, ['refuse', 'en_revision']) && empty($motif)) {
            throw new Exception("Un motif est obligatoire pour ce changement de statut");
        }
        
        $pdo->beginTransaction();
        
        // Mettre à jour le projet
        $stmt = $pdo->prepare("
            UPDATE projets 
            SET statut = ?, note_finale = ?, date_modification = CURRENT_TIMESTAMP 
            WHERE id = ? AND encadrant_id = ?
        ");
        $stmt->execute([$nouveau_statut, $note_finale, $projet_id, $user_id]);
        
        // Ajouter à l'historique
        $stmt = $pdo->prepare("
            INSERT INTO historique_statut_projets (projet_id, ancien_statut, nouveau_statut, motif, modifie_par, date_modification)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$projet_id, $ancien_statut, $nouveau_statut, $motif, $user_id]);
        
        // Ajouter un commentaire automatique
        $stmt = $pdo->prepare("
            INSERT INTO commentaires (projet_id, utilisateur_id, contenu, type_commentaire, date_creation)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        $type_commentaire = match($nouveau_statut) {
            'valide' => 'validation',
            'refuse' => 'rejet',
            'en_revision' => 'revision',
            default => 'feedback'
        };
        
        $contenu_commentaire = "Statut changé de \"$ancien_statut\" vers \"$nouveau_statut\"";
        if (!empty($motif)) {
            $contenu_commentaire .= "\nMotif: " . $motif;
        }
        if ($note_finale !== null) {
            $contenu_commentaire .= "\nNote finale: " . $note_finale . "/20";
        }
        
        $stmt->execute([$projet_id, $user_id, $contenu_commentaire, $type_commentaire]);
        
        $pdo->commit();
        
        $message = "Statut du projet mis à jour avec succès";
        
    } catch (Exception $e) {
        $pdo->rollback();
        $error = $e->getMessage();
    }
}

// Récupération des filtres
$filtre_statut = $_GET['statut'] ?? '';
$recherche = $_GET['recherche'] ?? '';
$tri = $_GET['tri'] ?? 'date_creation';

try {
    // Construction de la requête avec filtres
    $where_conditions = ["p.encadrant_id = ?"];
    $params = [$user_id];
    
    if (!empty($filtre_statut)) {
        $where_conditions[] = "p.statut = ?";
        $params[] = $filtre_statut;
    }
    
    if (!empty($recherche)) {
        $where_conditions[] = "(p.titre ILIKE ? OR e.nom ILIKE ? OR e.prenom ILIKE ?)";
        $search_term = "%$recherche%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Définir l'ordre de tri
    $order_by = match($tri) {
        'titre' => 'p.titre ASC',
        'etudiant' => 'e.nom ASC, e.prenom ASC',
        'statut' => 'p.statut ASC',
        'date_creation' => 'p.date_creation DESC',
        default => 'p.date_creation DESC'
    };

    // Récupérer les projets
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.nom as etudiant_nom,
            e.prenom as etudiant_prenom,
            e.email as etudiant_email,
            e.niveau as etudiant_niveau,
            f.nom as filiere_nom,
            f.code as filiere_code
        FROM projets p
        LEFT JOIN utilisateurs e ON p.etudiant_id = e.id
        LEFT JOIN filieres f ON e.filiere_id = f.id
        WHERE $where_clause
        ORDER BY $order_by
    ");
    $stmt->execute($params);
    $projets = $stmt->fetchAll();

    // Statistiques pour l'encadrant
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_projets,
            SUM(CASE WHEN statut = 'soumis' THEN 1 ELSE 0 END) as projets_soumis,
            SUM(CASE WHEN statut = 'en_cours_evaluation' THEN 1 ELSE 0 END) as projets_en_evaluation,
            SUM(CASE WHEN statut = 'valide' THEN 1 ELSE 0 END) as projets_valides,
            SUM(CASE WHEN statut = 'refuse' THEN 1 ELSE 0 END) as projets_refuses,
            SUM(CASE WHEN statut = 'en_revision' THEN 1 ELSE 0 END) as projets_en_revision
        FROM projets 
        WHERE encadrant_id = ?
    ");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Erreur évaluation projets: " . $e->getMessage());
    $projets = [];
    $stats = ['total_projets' => 0, 'projets_soumis' => 0, 'projets_en_evaluation' => 0, 'projets_valides' => 0, 'projets_refuses' => 0, 'projets_en_revision' => 0];
}

// Fonction pour obtenir le libellé du statut
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





?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Évaluer les Projets - Encadrant - UNB-ESI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #059669;
            --primary-light: #10b981;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        .project-card {
            transition: all 0.3s ease;
        }
        
        .project-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .urgence-badge {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .hamburger {
            transition: all 0.3s ease;
        }
        
        .hamburger.active {
            transform: rotate(90deg);
        }
        
        .mobile-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .mobile-menu.open {
            max-height: 1000px;
        }
        
        .sidebar {
            transition: all 0.3s ease;
        }
        
        .nav-link {
            transition: all 0.2s ease;
        }
        
        .nav-link:hover {
            transform: translateX(5px);
        }
        
        .commentaire-item {
            border-left: 4px solid #e5e7eb;
            transition: all 0.2s ease;
        }
        
        .commentaire-item.feedback {
            border-left-color: #3b82f6;
        }
        
        .commentaire-item.validation {
            border-left-color: #10b981;
        }
        
        .commentaire-item.rejet {
            border-left-color: #ef4444;
        }
        
        .commentaire-item.revision {
            border-left-color: #f59e0b;
        }
        
    </style>
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="flex flex-col md:flex-row min-h-screen">
        <!-- Mobile header -->
        <div class="md:hidden bg-white shadow-sm">
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-chalkboard-teacher text-2xl text-emerald-600"></i>
                    <span class="font-bold text-gray-800">UNB-ESI</span>
                </div>
                <button id="mobile-menu-button" class="p-2 focus:outline-none">
                    <i class="fas fa-bars text-gray-600 text-xl"></i>
                </button>
            </div>
            
            <!-- Mobile menu -->
            <div id="mobile-menu" class="mobile-menu bg-white shadow-md">
                <div class="p-4 border-t">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                            <i class="fas fa-chalkboard-teacher text-emerald-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                            <p class="text-xs text-gray-500">Encadrant</p>
                           <?php if ($filiere): ?>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($filiere['nom']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <nav class="space-y-2">
                        <a href="dashboard_enseignant.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="evaluer_projet.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg bg-emerald-50 text-emerald-700">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Projets à évaluer</span>
                        </a>
                        <a href="profile_enseignant.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user w-5 text-center"></i>
                            <span>Mon Profil</span>
                        </a>
                        <a href="../logout.php" class="nav-link flex items-center space-x-3 p-2 rounded-lg text-red-600 hover:bg-red-50">
                            <i class="fas fa-sign-out-alt w-5 text-center"></i>
                            <span>Déconnexion</span>
                        </a>
                    </nav>
                </div>
            </div>
        </div>
        
        <!-- Desktop sidebar -->
        <aside class="sidebar hidden md:flex md:flex-col w-64 bg-white shadow-lg">
            <div class="flex items-center justify-center p-6">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-chalkboard-teacher text-3xl text-emerald-600"></i>
                    <span class="text-xl font-bold text-gray-800">UNB-ESI</span>
                </div>
            </div>
            
            <div class="flex-1 overflow-y-auto">
                <div class="p-4">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                            <i class="fas fa-chalkboard-teacher text-emerald-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
                            <p class="text-xs text-gray-500">Encadrant</p>
                            <?php if ($filiere): ?>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($filiere['nom']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <nav class="space-y-1">
                        <a href="dashboard_enseignant.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-tachometer-alt w-5 text-center"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="evaluer_projet.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg bg-emerald-50 text-emerald-700">
                            <i class="fas fa-project-diagram w-5 text-center"></i>
                            <span>Projets à évaluer</span>
                        </a>
                        <a href="profile_enseignant.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user w-5 text-center"></i>
                            <span>Mon Profil</span>
                        </a>
                    </nav>
                </div>
            </div>
            
            <div class="p-4 border-t">
                <a href="../logout.php" class="nav-link flex items-center space-x-3 p-3 rounded-lg text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt w-5 text-center"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </aside>
        
        <!-- Main content -->
        <main class="flex-1 p-6">
            <!-- Messages -->
            <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 animate-fade-in">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 animate-fade-in">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Header -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-6 animate-fade-in">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800 mb-2">Évaluation des Projets</h1>
                        <p class="text-gray-600">Gérez et évaluez les projets de vos étudiants</p>
                    </div>
                    <div class="mt-4 md:mt-0 flex items-center space-x-2">
                        <?php if ($stats['projets_soumis'] > 0): ?>
                            <span class="urgence-badge bg-red-100 text-red-800 text-sm font-medium px-3 py-1 rounded-full">
                                <?php echo $stats['projets_soumis']; ?> en attente
                            </span>
                        <?php endif; ?>
                        <span class="bg-emerald-100 text-emerald-800 text-sm font-medium px-3 py-1 rounded-full">
                            <?php echo $stats['total_projets']; ?> projets total
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Statistiques rapides -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-md p-4 text-center">
                    <div class="text-2xl font-bold text-gray-800"><?php echo $stats['total_projets']; ?></div>
                    <div class="text-sm text-gray-500">Total</div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-4 text-center">
                    <div class="text-2xl font-bold text-yellow-600"><?php echo $stats['projets_soumis']; ?></div>
                    <div class="text-sm text-gray-500">À Évaluer</div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-4 text-center">
                    <div class="text-2xl font-bold text-blue-600"><?php echo $stats['projets_en_evaluation']; ?></div>
                    <div class="text-sm text-gray-500">En évaluation</div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-4 text-center">
                    <div class="text-2xl font-bold text-green-600"><?php echo $stats['projets_valides']; ?></div>
                    <div class="text-sm text-gray-500">Validés</div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-4 text-center">
                    <div class="text-2xl font-bold text-red-600"><?php echo $stats['projets_refuses']; ?></div>
                    <div class="text-sm text-gray-500">Refusés</div>
                </div>
            </div>
            
            <!-- Filtres et recherche -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-6 animate-fade-in">
                <form method="GET" class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                        <input type="text" name="recherche" value="<?php echo htmlspecialchars($recherche); ?>" 
                               placeholder="Titre du projet, nom ou prénom de l'étudiant..." 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                        <select name="statut" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            <option value="">Tous les statuts</option>
                            <option value="soumis" <?php echo $filtre_statut === 'soumis' ? 'selected' : ''; ?>>Soumis</option>
                            <option value="en_cours_evaluation" <?php echo $filtre_statut === 'en_cours_evaluation' ? 'selected' : ''; ?>>En évaluation</option>
                            <option value="valide" <?php echo $filtre_statut === 'valide' ? 'selected' : ''; ?>>Validé</option>
                            <option value="refuse" <?php echo $filtre_statut === 'refuse' ? 'selected' : ''; ?>>Refusé</option>
                            
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Trier par</label>
                        <select name="tri" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            <option value="date_creation" <?php echo $tri === 'date_creation' ? 'selected' : ''; ?>>Date de création</option>
                            <option value="titre" <?php echo $tri === 'titre' ? 'selected' : ''; ?>>Titre</option>
                            <option value="etudiant" <?php echo $tri === 'etudiant' ? 'selected' : ''; ?>>Étudiant</option>
                            <option value="statut" <?php echo $tri === 'statut' ? 'selected' : ''; ?>>Statut</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="px-6 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            <i class="fas fa-search mr-2"></i>
                            Filtrer
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Liste des projets -->
            <div class="space-y-4">
                <?php if (!empty($projets)): ?>
                    <?php foreach ($projets as $projet): ?>
                        <div class="project-card bg-white rounded-xl shadow-md p-6 animate-fade-in">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex-1">
                                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between mb-4">
                                        <div class="flex-1">
                                            <h3 class="text-lg font-semibold text-gray-800 mb-2">
                                                <?php echo htmlspecialchars($projet['titre']); ?>
                                            </h3>
                                            <div class="flex items-center space-x-4 text-sm text-gray-600 mb-3">
                                                <span class="flex items-center">
                                                    <i class="fas fa-user mr-1"></i>
                                                    <?php echo htmlspecialchars($projet['etudiant_prenom'] . ' ' . $projet['etudiant_nom']); ?>
                                                </span>
                                                <span class="flex items-center">
                                                    <i class="fas fa-envelope mr-1"></i>
                                                    <?php echo htmlspecialchars($projet['etudiant_email']); ?>
                                                </span>
                                                <?php if ($projet['filiere_nom']): ?>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-graduation-cap mr-1"></i>
                                                        <?php echo htmlspecialchars($projet['filiere_nom']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($projet['etudiant_niveau']): ?>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-layer-group mr-1"></i>
                                                        Niveau <?php echo htmlspecialchars($projet['etudiant_niveau']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-gray-600 text-sm mb-3">
                                                <?php echo nl2br(htmlspecialchars(substr($projet['description'], 0, 200))); ?>
                                                <?php if (strlen($projet['description']) > 200): ?>
                                                    <span class="text-emerald-600 cursor-pointer" onclick="voirProjet(<?php echo $projet['id']; ?>)">... voir plus</span>
                                                <?php endif; ?>
                                            </p>
                                            <div class="flex items-center space-x-4 text-xs text-gray-500">
                                                <span>
                                                    <i class="fas fa-calendar mr-1"></i>
                                                    Soumis le <?php echo date('d/m/Y à H:i', strtotime($projet['date_creation'])); ?>
                                                </span>
                                               
                                                <span class="uppercase font-medium">
                                                    <i class="fas fa-tag mr-1"></i>
                                                    <?php echo htmlspecialchars($projet['type_projet']); ?>
                                                </span>
                                             
                                            </div>
                                        </div>
                                        <div class="mt-4 lg:mt-0 lg:ml-6 flex flex-col space-y-2">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo getStatutClass($projet['statut']); ?>">
                                                <i class="<?php echo getStatutIcon($projet['statut']); ?> mr-2"></i>
                                                <?php echo getStatutLibelle($projet['statut']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-wrap gap-2">
                                       
                                        
                                        <?php if (in_array($projet['statut'], ['soumis', 'en_cours_evaluation', 'en_revision'])): ?>
                                            <button onclick="changerStatut(<?php echo $projet['id']; ?>, '<?php echo $projet['statut']; ?>')" 
                                                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                                <i class="fas fa-edit mr-2"></i>
                                                Changer statut
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button onclick="ajouterCommentaire(<?php echo $projet['id']; ?>)" 
                                                class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm">
                                            <i class="fas fa-comment mr-2"></i>
                                            Commenter
                                        </button>
                                        
                                        <?php if ($projet['fichier_chemin']): ?>
                                            <button onclick="telechargerFichiers(<?php echo $projet['id']; ?>)" 
                                                    class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm">
                                                <i class="fas fa-download mr-2"></i>
                                                Télécharger fichier
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bg-white rounded-xl shadow-md p-12 text-center animate-fade-in">
                        <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-project-diagram text-2xl text-gray-400"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-800 mb-2">Aucun projet trouvé</h3>
                        <p class="text-gray-600">
                            <?php if (!empty($filtre_statut) || !empty($recherche)): ?>
                                Aucun projet ne correspond à vos critères de recherche.
                            <?php else: ?>
                                Vous n'avez encore aucun projet à évaluer.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($filtre_statut) || !empty($recherche)): ?>
                            <a href="evaluer_projet.php" class="inline-flex items-center mt-4 px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
                                <i class="fas fa-times mr-2"></i>
                                Effacer les filtres
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
  
    
    <!-- Modal pour changer le statut -->
    <div id="modalStatut" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Changer le Statut</h2>
                <button onclick="fermerModal('modalStatut')" class="text-gray-400 hover:text-gray-600 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="formStatut" method="POST">
                <input type="hidden" name="action" value="changer_statut">
                <input type="hidden" name="projet_id" id="statutProjetId">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau Statut</label>
                    <select name="nouveau_statut" id="nouveauStatut" required 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <!-- Options will be populated by JavaScript -->
                    </select>
                </div>
                
                <div class="mb-4" id="divMotif" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Motif <span class="text-red-500">*</span></label>
                    <textarea name="motif" id="motifStatut" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500"
                              placeholder="Expliquez la raison de ce changement de statut"></textarea>
                </div>
                
                
                <div class="flex space-x-4">
                    <button type="submit" class="flex-1 px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <i class="fas fa-save mr-2"></i>
                        Sauvegarder
                    </button>
                    <button type="button" onclick="fermerModal('modalStatut')" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 focus:outline-none">
                        Annuler
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal pour ajouter un commentaire -->
    <div id="modalCommentaire" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Ajouter un Commentaire</h2>
                <button onclick="fermerModal('modalCommentaire')" class="text-gray-400 hover:text-gray-600 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="formCommentaire" method="POST">
                <input type="hidden" name="action" value="ajouter_commentaire">
                <input type="hidden" name="projet_id" id="commentaireProjetId">
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Commentaire <span class="text-red-500">*</span></label>
                    <textarea name="contenu" required rows="5" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500"
                              placeholder="Votre commentaire..."></textarea>
                </div>
                
                <div class="flex space-x-4">
                    <button type="submit" class="flex-1 px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <i class="fas fa-comment mr-2"></i>
                        Ajouter le commentaire
                    </button>
                    <button type="button" onclick="fermerModal('modalCommentaire')" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 focus:outline-none">
                        Annuler
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Gestion du menu mobile
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const menu = document.getElementById('mobile-menu');
            const button = this.querySelector('i');
            
            menu.classList.toggle('open');
            
            if (menu.classList.contains('open')) {
                button.classList.remove('fa-bars');
                button.classList.add('fa-times');
            } else {
                button.classList.remove('fa-times');
                button.classList.add('fa-bars');
            }
        });
        
        // Fonctions pour les modals
        function ouvrirModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function fermerModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        
        // Fermer modal en cliquant à l'extérieur
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    fermerModal(this.id);
                }
            });
        });
        
       
        
        // Changer le statut d'un projet
        function changerStatut(projetId, statutActuel) {
            document.getElementById('statutProjetId').value = projetId;
            
            // Définir les transitions possibles selon le statut actuel
            const transitions = {
                'soumis': [
                    { value: 'en_cours_evaluation', text: 'Mettre en évaluation' },
                    { value: 'valide', text: 'Valider directement' },
                    { value: 'refuse', text: 'Refuser' }
                ],
                'en_cours_evaluation': [
                    { value: 'valide', text: 'Valider' },
                    { value: 'refuse', text: 'Refuser' }
                ]
            };
            
            const selectStatut = document.getElementById('nouveauStatut');
            selectStatut.innerHTML = '<option value="">Choisir un nouveau statut</option>';
            
            if (transitions[statutActuel]) {
                transitions[statutActuel].forEach(option => {
                    const optionElement = document.createElement('option');
                    optionElement.value = option.value;
                    optionElement.textContent = option.text;
                    selectStatut.appendChild(optionElement);
                });
            }
            
            ouvrirModal('modalStatut');
        }
        
        // Gestion du changement de statut
        // Gestion du changement de statut
document.getElementById('nouveauStatut').addEventListener('change', function() {
    const statut = this.value;
    const divMotif = document.getElementById('divMotif');
    const divNote = document.getElementById('divNote');
    const motifInput = document.getElementById('motifStatut');
    
    // Afficher le champ motif pour validation, refus et révision
    if (['refuse', 'en_revision', 'valide'].includes(statut)) {
        divMotif.style.display = 'block';
        motifInput.required = true;
        
        // Adapter le texte du label selon le statut
        const labelMotif = document.querySelector('label[for="motifStatut"]');
        if (statut === 'valide') {
            labelMotif.innerHTML = 'Commentaire de validation <span class="text-red-500">*</span>';
            motifInput.placeholder = 'Commentaire sur la validation du projet';
        } else if (statut === 'refuse') {
            labelMotif.innerHTML = 'Motif de refus <span class="text-red-500">*</span>';
            motifInput.placeholder = 'Expliquez pourquoi le projet est refusé';
        } else if (statut === 'en_revision') {
            labelMotif.innerHTML = 'Motif de révision <span class="text-red-500">*</span>';
            motifInput.placeholder = 'Expliquez ce qui doit être révisé';
        }
    } else {
        divMotif.style.display = 'none';
        motifInput.required = false;
    }
    
    // Afficher le champ note pour la validation
    if (statut === 'valide') {
        if (divNote) divNote.style.display = 'block';
    } else {
        if (divNote) divNote.style.display = 'none';
    }
});
        
        // Ajouter un commentaire
        function ajouterCommentaire(projetId) {
            document.getElementById('commentaireProjetId').value = projetId;
            ouvrirModal('modalCommentaire');
        }
        
        // Télécharger les fichiers
        function telechargerFichiers(projetId) {
    		window.open(`download.php?id=${projetId}`, '_blank');
	}
        
        // Gestion des touches clavier pour fermer les modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    fermerModal(modal.id);
                });
            }
        });
        
        // Auto-hide des messages d'alerte
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.bg-green-100, .bg-red-100');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'all 0.5s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>
