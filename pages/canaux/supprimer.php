<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $db;

$id = $_GET['id'] ?? null;

if ($id) {
    try {
        // Vérifier que le canal appartient à l'utilisateur
        $canaux = $db->select('canal', ['id_canal' => $id, 'id_compte' => $_SESSION['user_id']]);
        if ($canaux) {
            $db->delete('canal', $id, 'id_canal');
            $_SESSION['flash_message'] = "Canal supprimé avec succès";
        } else {
            $_SESSION['flash_error'] = "Canal non trouvé";
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Erreur lors de la suppression";
    }
}

ob_clean();
header('Location: index.php?page=canaux/index');
exit;
?>