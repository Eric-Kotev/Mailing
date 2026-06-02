<?php
global $db;

$idCompte = $_SESSION['user_id'];
$canaux = $db->select('canal', ['id_compte' => $idCompte], '*', 'date_creation=order.desc');

// Récupérer les types et providers pour l'affichage
$typesMessage = $db->select('type_message');
$providers = $db->select('provider');

// Créer des tableaux associatifs
$typesMap = [];
foreach ($typesMessage as $t) {
    $typesMap[$t['id_type_message']] = $t['libelle_type'];
}

$providersMap = [];
foreach ($providers as $p) {
    $providersMap[$p['id_provider']] = $p['nom_provider'];
}
?>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Canaux d'envoi</h1>
            <p class="text-gray-500">Configurez vos passerelles d'envoi (SMS, Email, WhatsApp)</p>
        </div>
        <a href="index.php?page=canaux/ajouter" 
           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-plus mr-2"></i>Ajouter un canal
        </a>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded">
            <?= $_SESSION['flash_message'] ?>
            <?php unset($_SESSION['flash_message']); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($canaux)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <i class="fas fa-plug text-5xl text-gray-300 mb-4"></i>
            <p class="text-gray-500">Aucun canal configuré.</p>
            <a href="index.php?page=canaux/ajouter" class="text-blue-600 mt-2 inline-block">
                Configurer votre premier canal →
            </a>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($canaux as $canal): 
                $typeCanal = $typesMap[$canal['id_type_message']] ?? '?';
                
                // Couleur du badge selon le type (PHP 7 compatible)
                if ($typeCanal == 'SMS') {
                    $badgeColor = 'bg-purple-100 text-purple-800';
                    $icon = 'fa-comment-dots';
                } elseif ($typeCanal == 'Email') {
                    $badgeColor = 'bg-blue-100 text-blue-800';
                    $icon = 'fa-envelope';
                } elseif ($typeCanal == 'WhatsApp') {
                    $badgeColor = 'bg-green-100 text-green-800';
                    $icon = 'fa-whatsapp';
                } else {
                    $badgeColor = 'bg-gray-100 text-gray-800';
                    $icon = 'fa-plug';
                }
            ?>
                <div class="bg-white rounded-lg shadow hover:shadow-lg transition">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div class="bg-gray-100 p-3 rounded-full">
                                <i class="fas <?= $icon ?> text-gray-600 text-xl"></i>
                            </div>
                            <span class="px-2 py-1 rounded text-xs <?= $badgeColor ?>">
                                <?= $typeCanal ?>
                            </span>
                        </div>
                        <h3 class="font-bold text-lg text-gray-800 mb-1"><?= htmlspecialchars($canal['nom_canal']) ?></h3>
                        <p class="text-sm text-gray-500 mb-3">
                            Fournisseur : <?= htmlspecialchars($providersMap[$canal['id_provider']] ?? 'Inconnu') ?>
                        </p>
                        <div class="flex items-center justify-between mt-4 pt-3 border-t">
                            <span class="text-xs text-gray-400">
                                Créé le <?= date('d/m/Y', strtotime($canal['date_creation'])) ?>
                            </span>
                            <div class="space-x-2">
                                <a href="index.php?page=canaux/modifier&id=<?= $canal['id_canal'] ?>" 
                                   class="text-blue-600 hover:text-blue-800" title="Modifier">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="index.php?page=canaux/supprimer&id=<?= $canal['id_canal'] ?>" 
                                   class="text-red-600 hover:text-red-800" title="Supprimer"
                                   onclick="return confirm('Supprimer ce canal ?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>