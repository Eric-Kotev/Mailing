<?php
// Forcer la sortie JSON sans le layout
define('AJAX_REQUEST', true);

header('Content-Type: application/json');
error_reporting(0);

$root = dirname(__DIR__, 2);
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/config.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(['error' => 'ID manquant']);
    exit;
}

$contacts = $db->select('contact', ['id_contact' => $id, 'id_compte' => $_SESSION['user_id']]);

if (!$contacts || empty($contacts)) {
    echo json_encode(['error' => 'Contact non trouvé']);
    exit;
}

echo json_encode($contacts[0]);
exit; 
?>