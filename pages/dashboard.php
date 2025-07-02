<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérification de session
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$user = getUserById($_SESSION['user_id']);
if (!$user) {
    logoutUser();
    header('Location: ../index.php');
    exit();
}

// Vérifie la cohérence du rôle stocké en session
if ($_SESSION['user_role'] !== $user['role']) {
    logoutUser();
    header('Location: ../index.php');
    exit();
}

$role = $user['role'];

// Redirection vers le bon dashboard selon le rôle
switch ($role) {
    case 'etudiant':
        header('Location: dashboard_etudiant.php');
        exit();
    case 'enseignant':
        header('Location: dashboard_enseignant.php');
        exit();
    case 'administrateur':
        header('Location: dashboard_administrateur.php');
        exit();
    default:
        header('Location: ../index.php?error=' . urlencode('Rôle utilisateur non reconnu'));
        exit();
}
?>
