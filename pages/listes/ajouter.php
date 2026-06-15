<?php
global $db;

$error = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom_liste = trim($_POST['nom_liste'] ?? '');
    
    if (!empty($nom_liste)) {
        try {
            $data = [
                'id_compte' => $_SESSION['user_id'],
                'nom_liste' => $nom_liste
            ];
            $db->insert('liste', $data);
            $_SESSION['flash_message'] = "Liste créée avec succès !";
            header('Location: index.php?page=listes/index');
            exit;
        } catch (Exception $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    } else {
        $error = "Veuillez saisir un nom de liste";
    }
}

// Si on arrive ici, c'est qu'il n'y a pas eu de redirection
// On va afficher le formulaire
?>
<div class="max-w-2xl mx-auto">
    <div class="flex items-center mb-6">
        <a href="javascript:history.back()" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <h1 class="text-2xl font-bold text-gray-800">Créer une nouvelle liste</h1>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST">
            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?= $error ?>
                </div>
            <?php endif; ?>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la liste *</label>
                <input type="text" name="nom_liste" required 
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500"
                       placeholder="Ex: Newsletter, Clients VIP, Prospects...">
                <p class="text-xs text-gray-500 mt-1">Choisissez un nom explicite pour votre liste</p>
            </div>
            
            <div class="mt-6 flex justify-end">
                <a href="index.php?page=listes/index" class="px-4 py-2 border border-gray-300 rounded-lg mr-2 hover:bg-gray-50">
                    Annuler
                </a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-save mr-2"></i>Créer la liste
                </button>
            </div>
        </form>
    </div>
</div>