<?php
requireAdmin();
global $db;

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: index.php?page=admin/users');
    exit;
}

$user = $db->select('compte', ['id_compte' => $id]);
if (!$user) {
    header('Location: index.php?page=admin/users');
    exit;
}
$user = $user[0];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entreprise = trim($_POST['entreprise']);
    $prenom = trim($_POST['prenom']);
    $nom = trim($_POST['nom']);
    $user_login = trim($_POST['user']);
    $credits = floatval($_POST['credits']);
    
    if (empty($entreprise) || empty($prenom) || empty($nom) || empty($user_login)) {
        $error = "Veuillez remplir tous les champs";
    } else {
        $data = [
            'entreprise' => $entreprise,
            'prenom' => $prenom,
            'nom' => $nom,
            'user' => $user_login,
            'credits_total' => $credits
        ];
        
        if (!empty($_POST['new_password'])) {
            $data['password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        }
        
        try {
            $db->update('compte', $data, ['id_compte' => $id]);
            $success = "Utilisateur modifié avec succès !";
            $user = array_merge($user, $data);
        } catch (Exception $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    }
}
?>
<div class="max-w-2xl mx-auto">
    <div class="flex items-center mb-6">
        <a href="index.php?page=admin/users" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <h1 class="text-2xl font-bold text-gray-800">Modifier l'utilisateur</h1>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Entreprise</label>
                    <input type="text" name="entreprise" required value="<?= htmlspecialchars($user['entreprise']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Prénom</label>
                    <input type="text" name="prenom" required value="<?= htmlspecialchars($user['prenom']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                    <input type="text" name="nom" required value="<?= htmlspecialchars($user['nom']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Identifiant</label>
                    <input type="text" name="user" required value="<?= htmlspecialchars($user['user']) ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Crédits (€)</label>
                    <input type="number" name="credits" step="0.01" value="<?= $user['credits_total'] ?>"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                    <input type="password" name="new_password" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2"
                           placeholder="Laissez vide pour ne pas changer">
                </div>
            </div>
            
            <div class="mt-6 flex justify-end">
                <a href="index.php?page=admin/users" class="px-4 py-2 border rounded-lg mr-2">Annuler</a>
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg">Enregistrer</button>
            </div>
        </form>
    </div>
</div>