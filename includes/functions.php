<?php

/**
 * Fonctions utilitaires pour la plateforme de gestion de projets
 */

// Fonction pour vérifier si l'utilisateur est connecté
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Fonction pour vérifier le rôle de l'utilisateur
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }
    return $_SESSION['user_role'] === $role;
}

// Fonction pour hacher un mot de passe
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Fonction pour vérifier un mot de passe
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Fonction pour connecter un utilisateur
function loginUser($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_nom'] = $user['nom'];
    $_SESSION['user_prenom'] = $user['prenom'];
    $_SESSION['user_role'] = $user['role'];
}

// Fonction pour déconnecter un utilisateur
function logoutUser() {
    session_unset();
    session_destroy();
    header('Location: /plateforme_projets/index.php');
    exit();
}



// Fonction pour afficher un message
function displayMessage($message, $type = 'error') {
    $class = $type === 'success' ? 'alert-success' : 'alert-danger';
    return "<div class='alert $class'>$message</div>";
}

// Récupérer l'utilisateur courant
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'email' => $_SESSION['user_email'],
        'nom' => $_SESSION['user_nom'],
        'prenom' => $_SESSION['user_prenom'],
        'role' => $_SESSION['user_role']
    ];
}

// Vérifier si l'utilisateur a un des rôles
function hasAnyRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    return in_array($_SESSION['user_role'], $roles);
}

// Vérification de permission (rôle unique)
function requireRole($required_role) {
    if (!isLoggedIn()) {
        header('Location: ../index.php');
        exit();
    }
    if (!hasRole($required_role)) {
        header('Location: ../pages/access-denied.php');
        exit();
    }
}

// Vérification de permission (multiples rôles)
function requireAnyRole($required_roles) {
    if (!isLoggedIn()) {
        header('Location: ../index.php');
        exit();
    }
    if (!hasAnyRole($required_roles)) {
        header('Location: ../pages/access-denied.php');
        exit();
    }
}

// Récupérer un utilisateur par ID
function getUserById($id) {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erreur getUserById: ".$e->getMessage());
        return false;
    }
}

// Statistiques étudiant
function getStudentStats($student_id) {
    $pdo = getDB();
    try {
        $stats = [];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM projets WHERE etudiant_id = ?");
        $stmt->execute([$student_id]);
        $stats['total_projets'] = $stmt->fetch()['total'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM projets WHERE etudiant_id = ? AND statut = 'valide'");
        $stmt->execute([$student_id]);
        $stats['projets_valides'] = $stmt->fetch()['total'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM projets WHERE etudiant_id = ? AND statut = 'en_evaluation'");
        $stmt->execute([$student_id]);
        $stats['projets_en_evaluation'] = $stmt->fetch()['total'];
        
        $stmt = $pdo->prepare("SELECT AVG(note) as moyenne FROM evaluations e JOIN projets p ON e.projet_id = p.id WHERE p.etudiant_id = ?");
        $stmt->execute([$student_id]);
        $result = $stmt->fetch();
        $stats['moyenne_notes'] = $result['moyenne'] ? round($result['moyenne'], 1) : null;
        
        return $stats;
    } catch (Exception $e) {
        error_log("Erreur getStudentStats: ".$e->getMessage());
        return ['total_projets' => 0, 'projets_valides' => 0, 'projets_en_evaluation' => 0, 'moyenne_notes' => null];
    }
}

// Statistiques enseignant
function getTeacherStats($teacher_id) {
    $pdo = getDB();
    try {
        $stats = [];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM projets WHERE enseignant_id = ?");
        $stmt->execute([$teacher_id]);
        $stats['total_encadres'] = $stmt->fetch()['total'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM projets WHERE enseignant_id = ? AND statut = 'en_evaluation'");
        $stmt->execute([$teacher_id]);
        $stats['a_evaluer'] = $stmt->fetch()['total'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM projets p JOIN evaluations e ON p.id = e.projet_id WHERE p.enseignant_id = ?");
        $stmt->execute([$teacher_id]);
        $stats['evalues'] = $stmt->fetch()['total'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM projets WHERE enseignant_id = ? AND created_at >= NOW() - INTERVAL '1 week'");
        $stmt->execute([$teacher_id]);
        $stats['cette_semaine'] = $stmt->fetch()['total'];
        
        return $stats;
    } catch (Exception $e) {
        error_log("Erreur getTeacherStats: ".$e->getMessage());
        return ['total_encadres' => 0, 'a_evaluer' => 0, 'evalues' => 0, 'cette_semaine' => 0];
    }
}

// Statistiques admin
function getAdminStats() {
    $pdo = getDB();
    try {
        $stats = [];
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM utilisateurs WHERE actif = true");
        $stats['total_utilisateurs'] = $stmt->fetch()['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM projets");
        $stats['total_projets'] = $stmt->fetch()['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM utilisateurs WHERE role = 'etudiant' AND actif = true");
        $stats['etudiants_actifs'] = $stmt->fetch()['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM utilisateurs WHERE role = 'enseignant' AND actif = true");
        $stats['enseignants'] = $stmt->fetch()['total'];
        
        return $stats;
    } catch (Exception $e) {
        error_log("Erreur getAdminStats: ".$e->getMessage());
        return ['total_utilisateurs' => 0, 'total_projets' => 0, 'etudiants_actifs' => 0, 'enseignants' => 0];
    }
}

// Activités récentes génériques
function getRecentActivities($user_id, $role) {
    $pdo = getDB();
    try {
        $activities = [];
        
        if ($role == 'etudiant') {
            $stmt = $pdo->prepare("
                SELECT p.titre,
                    CASE 
                        WHEN p.statut = 'soumis' THEN 'Projet soumis'
                        WHEN p.statut = 'en_evaluation' THEN 'Projet en évaluation'
                        WHEN p.statut = 'valide' THEN 'Projet validé'
                        WHEN p.statut = 'rejete' THEN 'Projet rejeté'
                        ELSE 'Mise à jour du projet'
                    END as description,
                    p.updated_at as date
                FROM projets p 
                WHERE p.etudiant_id = ? 
                ORDER BY p.updated_at DESC 
                LIMIT 5");
            $stmt->execute([$user_id]);
        } elseif ($role == 'enseignant') {
            $stmt = $pdo->prepare("
                SELECT p.titre,
                    CONCAT('Nouveau projet à évaluer de ', u.prenom, ' ', u.nom) as description,
                    p.created_at as date
                FROM projets p 
                JOIN utilisateurs u ON p.etudiant_id = u.id
                WHERE p.enseignant_id = ? AND p.statut = 'en_evaluation'
                ORDER BY p.created_at DESC 
                LIMIT 5");
            $stmt->execute([$user_id]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erreur getRecentActivities: ".$e->getMessage());
        return [];
    }
}

// Formatage du temps écoulé
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'à l\'instant';
    if ($time < 3600) return floor($time/60) . ' min';
    if ($time < 86400) return floor($time/3600) . ' h';
    if ($time < 2592000) return floor($time/86400) . ' j';
    if ($time < 31536000) return floor($time/2592000) . ' mois';
    return floor($time/31536000) . ' an';
}

// Validation email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Nettoyage des entrées
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Récupération filière
function getFiliereById($filiereId) {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT * FROM filieres WHERE id = ?");
        $stmt->execute([$filiereId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getFiliereById: ".$e->getMessage());
        return null;
    }
}

// Formatage date en français
function formatDateFrench($date) {
    $timestamp = strtotime($date);
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) return "à l'instant";
    if ($diff < 3600) return floor($diff/60) . ' min';
    if ($diff < 86400) return floor($diff/3600) . ' h';
    if ($diff < 604800) return floor($diff/86400) . ' j';
    return date('d/m/Y à H:i', $timestamp);
}

// Icône selon statut
function getActivityIcon($statut) {
    switch ($statut) {
        case 'soumis': return 'fas fa-upload text-blue-500';
        case 'en_cours': return 'fas fa-hourglass-half text-yellow-500';
        case 'valide': return 'fas fa-check-circle text-green-500';
        case 'refuse': return 'fas fa-times-circle text-red-500';
        default: return 'fas fa-file text-gray-500';
    }
}

// Traduction rôle
function getRoleFrench($role) {
    switch ($role) {
        case 'etudiant': return 'Étudiant';
        case 'enseignant': return 'Enseignant';
        case 'administrateur': return 'Administrateur';
        default: return $role;
    }
}

/**
 * Récupère les activités récentes spécifiques aux étudiants
 * @param int $studentId ID de l'étudiant
 * @param int $limit Nombre maximum d'activités à retourner
 * @return array Tableau des activités récentes
 */
function getStudentRecentActivities($studentId, $limit = 5) {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                e.nom as encadrant_nom,
                e.prenom as encadrant_prenom,
                f.nom as filiere_nom
            FROM projets p
            LEFT JOIN utilisateurs e ON p.encadrant_id = e.id
            LEFT JOIN filieres f ON p.filiere_id = f.id
            WHERE p.etudiant_id = ? 
            ORDER BY p.date_creation DESC 
            LIMIT ?
        ");
        $stmt->execute([$studentId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur dans getStudentRecentActivities: " . $e->getMessage());
        return [];
    }
}

function getActiveTeachers() {
    $pdo = getDB(); // Assurez-vous que getDB() existe et fonctionne
    try {
        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE role = 'enseignant' AND actif = true");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur dans getActiveTeachers: " . $e->getMessage());
        return [];
    }
}


function getActivityIconProjet($statut) {
    switch ($statut) {
        case 'soumis':
            return 'fas fa-paper-plane text-gray-500';
        case 'en_cours_evaluation':
            return 'fas fa-hourglass-half text-yellow-500';
        case 'valide':
            return 'fas fa-check-circle text-green-500';
        case 'refuse':
            return 'fas fa-times-circle text-red-500';
        case 'en_revision':
            return 'fas fa-edit text-blue-500';
        default:
            return 'fas fa-question-circle text-gray-500';
    }
}

function getStatutClass($statut) {
    switch ($statut) {
        case 'soumis':
            return 'bg-gray-100 text-gray-800';
        case 'en_cours_evaluation':
            return 'bg-yellow-100 text-yellow-800';
        case 'valide':
            return 'bg-green-100 text-green-800';
        case 'refuse':
            return 'bg-red-100 text-red-800';
        case 'en_revision':
            return 'bg-blue-100 text-blue-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

function getStatutText($statut) {
    switch ($statut) {
        case 'soumis':
            return 'Soumis';
        case 'en_cours_evaluation':
            return 'En évaluation';
        case 'valide':
            return 'Validé';
        case 'refuse':
            return 'Refusé';
        case 'en_revision':
            return 'En révision';
        default:
            return 'Inconnu';
    }
}


function getStatutIcon($statut) {
    switch ($statut) {
        case 'soumis':
            return 'fas fa-paper-plane text-gray-500';
        case 'en_cours_evaluation':
            return 'fas fa-hourglass-half text-yellow-500';
        case 'valide':
            return 'fas fa-check-circle text-green-500';
        case 'refuse':
            return 'fas fa-times-circle text-red-500';
        case 'en_revision':
            return 'fas fa-edit text-blue-500';
        default:
            return 'fas fa-question-circle text-gray-500';
    }
}


function sendNotification($pdo, $user_id, $message, $type = 'info') {
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, date_creation) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user_id, $message, $type]);
    } catch (PDOException $e) {
        error_log("Erreur send notification: " . $e->getMessage());
    }
}

function getUnreadNotificationsCount($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lu = 0");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Erreur count notifications: " . $e->getMessage());
        return 0;
    }
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        $bytes = $bytes . ' bytes';
    } elseif ($bytes == 1) {
        $bytes = $bytes . ' byte';
    } else {
        $bytes = '0 bytes';
    }
    return $bytes;
}

function checkFileType($filename, $allowedTypes = ['pdf', 'doc', 'docx']) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowedTypes);
}

function createSlug($string) {
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9\s-]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    return trim($string, '-');
}


function isValidPDF($filePath) {
    $handle = fopen($filePath, 'r');
    $header = fread($handle, 4);
    fclose($handle);
    return $header === '%PDF';
}

function compressImage($source, $destination, $quality = 80) {
    $info = getimagesize($source);
    
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
    } else {
        return false;
    }
    
    return imagejpeg($image, $destination, $quality);
}

function formatBytes($size, $precision = 2) {
    $base = log($size, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}

/**
 * Nettoie et sécurise les données d'entrée
 */
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Convertit une date en format "il y a X temps" (ex: "Il y a 2 heures")
 * @param string $datetime Date au format MySQL (YYYY-MM-DD HH:MM:SS)
 * @return string
 */
function formatTimeAgo($datetime) {
    $units = [
        'y' => 'an',
        'm' => 'mois',
        'd' => 'jour',
        'h' => 'heure',
        'i' => 'minute',
        's' => 'seconde',
    ];

    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    foreach ($units as $key => &$unit) {
        if ($diff->$key) {
            $unit = $diff->$key . ' ' . $unit;
            if ($diff->$key > 1 && $key !== 'm') {
                $unit .= 's';
            }
        } else {
            unset($units[$key]);
        }
    }

    $units = array_slice($units, 0, 1);
    return $units ? 'Il y a ' . implode(', ', $units) : 'À l\'instant';
}



function getStatusConfig($status) {
    $configs = [
        'soumis' => [
            'label' => 'Soumis',
            'class' => 'bg-blue-100 text-blue-800',
            'icon' => 'fas fa-clock'
        ],
        'en_cours_evaluation' => [
            'label' => 'En évaluation',
            'class' => 'bg-yellow-100 text-yellow-800',
            'icon' => 'fas fa-hourglass-half'
        ],
        'valide' => [
            'label' => 'Validé',
            'class' => 'bg-green-100 text-green-800',
            'icon' => 'fas fa-check-circle'
        ],
        'refuse' => [
            'label' => 'Refusé',
            'class' => 'bg-red-100 text-red-800',
            'icon' => 'fas fa-times-circle'
        ],
        'en_revision' => [
            'label' => 'En révision',
            'class' => 'bg-purple-100 text-purple-800',
            'icon' => 'fas fa-edit'
        ]
    ];
    
    return $configs[$status] ?? [
        'label' => 'Statut inconnu',
        'class' => 'bg-gray-100 text-gray-800',
        'icon' => 'fas fa-question-circle'
    ];
}

// Ajoutez cette fonction dans includes/functions.php
function logActivity($pdo, $user_id, $action, $details = '') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO actions_administrateur 
            (administrateur_id, action, details, adresse_ip) 
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user_id,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Erreur logActivity: " . $e->getMessage());
        return false;
    }
}

?>
