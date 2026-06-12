<?php
global $db;

$idCompte = $_SESSION['user_id'];
$campagneId = $_GET['campagne_id'] ?? null;
$searchTerm = $_GET['search'] ?? '';

// Récupérer les campagnes config
if (!empty($searchTerm)) {
    // Récupérer toutes les campagnes puis filtrer
    $allCampagnes = $db->select('campagne_config', ['id_compte' => $idCompte], '*', 'created_at DESC');
    $campagnes = [];
    foreach ($allCampagnes as $c) {
        if (stripos($c['nom_campagne'], $searchTerm) !== false || 
            stripos($c['objet'] ?? '', $searchTerm) !== false ||
            stripos($c['message'], $searchTerm) !== false) {
            $campagnes[] = $c;
        }
    }
} else {
    $campagnes = $db->select('campagne_config', ['id_compte' => $idCompte], '*', 'created_at DESC');
}

// Compter les envois pour chaque campagne
foreach ($campagnes as $key => $campagne) {
    $envois = $db->select('campagne', ['id_campagne_config' => $campagne['id_campagne_config']]);
    $campagnes[$key]['nb_envois'] = count($envois);
}

// Si une campagne est sélectionnée, récupérer les détails des envois
$campagneSelectionnee = null;
$envoisListe = [];
if ($campagneId) {
    $campagneSelectionnee = $db->select('campagne_config', [
        'id_campagne_config' => $campagneId,
        'id_compte' => $idCompte
    ]);
    if ($campagneSelectionnee) {
        $campagneSelectionnee = $campagneSelectionnee[0];
        $envoisListe = $db->select('campagne', ['id_campagne_config' => $campagneId], '*', 'created_at DESC');
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des campagnes - <?= APP_NAME ?></title>
    <style>
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .status-brouillon { background: #f3f4f6; color: #4b5563; }
        .status-planifiee { background: #fef3c7; color: #92400e; }
        .status-envoyee { background: #dcfce7; color: #166534; }
        .status-annulee { background: #fee2e2; color: #991b1b; }
        
        .campagne-row {
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .campagne-row:hover {
            background-color: #f9fafb;
        }
        .envoi-row:hover {
            background-color: #fef3c7;
        }
        .search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            cursor: pointer;
        }
        .search-clear:hover {
            color: #ef4444;
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Historique des campagnes</h1>
            <p class="text-gray-500">Consultez toutes vos campagnes et leurs envois</p>
        </div>
        <?php if ($campagneId): ?>
            <a href="index.php?page=campagnes/historique" class="text-blue-600 hover:text-blue-800">
                <i class="fas fa-arrow-left mr-1"></i> Voir toutes les campagnes
            </a>
        <?php endif; ?>
    </div>

    <?php if ($campagneId && $campagneSelectionnee): ?>
        <!-- Affichage des détails de la campagne sélectionnée -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="p-4 border-b bg-purple-50">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-lg font-bold text-purple-800">
                            <i class="fas fa-bullhorn mr-2"></i>
                            <?= htmlspecialchars($campagneSelectionnee['nom_campagne']) ?>
                        </h2>
                        <p class="text-sm text-purple-600 mt-1">
                            Créée le <?= date('d/m/Y H:i', strtotime($campagneSelectionnee['created_at'])) ?>
                        </p>
                    </div>
                    <span class="status-badge status-<?= $campagneSelectionnee['statut'] ?>">
                        <?php
                        $statusText = [
                            'brouillon' => 'Brouillon',
                            'planifiee' => 'Planifiée',
                            'envoyee' => 'Envoyée',
                            'annulee' => 'Annulée'
                        ];
                        echo $statusText[$campagneSelectionnee['statut']] ?? $campagneSelectionnee['statut'];
                        ?>
                    </span>
                </div>
                <?php if ($campagneSelectionnee['objet']): ?>
                    <div class="mt-2 text-sm text-gray-600">
                        <strong>Objet :</strong> <?= htmlspecialchars($campagneSelectionnee['objet']) ?>
                    </div>
                <?php endif; ?>
                <div class="mt-1 text-sm text-gray-600">
                    <strong>Message :</strong> <?= htmlspecialchars(substr($campagneSelectionnee['message'], 0, 100)) ?>...
                </div>
            </div>
            
            <!-- Liste des envois de cette campagne -->
            <div class="p-4">
                <h3 class="font-semibold text-gray-700 mb-3">
                    <i class="fas fa-envelope mr-2"></i>
                    Envois réalisés (<?= count($envoisListe) ?>)
                </h3>
                
                <?php if (empty($envoisListe)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-inbox text-3xl mb-2 block"></i>
                        Aucun envoi pour cette campagne.
                        <?php if ($campagneSelectionnee['statut'] == 'brouillon'): ?>
                            <a href="index.php?page=campagnes/choix&campagne_id=<?= $campagneId ?>" class="text-green-600 block mt-2">
                                <i class="fas fa-plus mr-1"></i>Envoyer un message
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                                    <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Destinataires</th>
                                    <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Succès</th>
                                    <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Échecs</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Appareil</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($envoisListe as $envoi): ?>
                                    <tr class="envoi-row hover:bg-gray-50">
                                        <td class="px-4 py-2 text-sm text-gray-500 whitespace-nowrap">
                                            <?= date('d/m/Y H:i', strtotime($envoi['created_at'])) ?>
                                        </td>
                                        <td class="px-4 py-2">
                                            <?php if ($envoi['type_campagne'] == 'whatsapp'): ?>
                                                <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs">WhatsApp</span>
                                            <?php else: ?>
                                                <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded-full text-xs">SMS</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-2">
                                            <div class="text-sm text-gray-800 max-w-xs truncate" title="<?= htmlspecialchars($envoi['message']) ?>">
                                                <?= htmlspecialchars(substr($envoi['message'], 0, 50)) ?>...
                                            </div>
                                        </td>
                                        <td class="px-4 py-2 text-center text-sm"><?= $envoi['nb_destinataires'] ?></td>
                                        <td class="px-4 py-2 text-center text-sm text-green-600"><?= $envoi['nb_succes'] ?></td>
                                        <td class="px-4 py-2 text-center text-sm text-red-600"><?= $envoi['nb_erreurs'] ?></td>
                                        <td class="px-4 py-2 text-sm text-gray-500">
                                            <?= htmlspecialchars(substr($envoi['appareil_utilise'] ?? '-', 0, 25)) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Statistiques des envois -->
                    <?php
                    $totalSucces = 0;
                    $totalErreurs = 0;
                    $totalDestinataires = 0;
                    foreach ($envoisListe as $e) {
                        $totalSucces += $e['nb_succes'];
                        $totalErreurs += $e['nb_erreurs'];
                        $totalDestinataires += $e['nb_destinataires'];
                    }
                    ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 pt-3 border-t">
                        <div class="bg-blue-50 rounded-lg p-2 text-center">
                            <div class="text-lg font-bold text-blue-600"><?= $totalDestinataires ?></div>
                            <div class="text-xs text-gray-500">Destinataires</div>
                        </div>
                        <div class="bg-green-50 rounded-lg p-2 text-center">
                            <div class="text-lg font-bold text-green-600"><?= $totalSucces ?></div>
                            <div class="text-xs text-gray-500">Succès</div>
                        </div>
                        <div class="bg-red-50 rounded-lg p-2 text-center">
                            <div class="text-lg font-bold text-red-600"><?= $totalErreurs ?></div>
                            <div class="text-xs text-gray-500">Échecs</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <a href="index.php?page=campagnes/historique" class="inline-block text-blue-600 hover:text-blue-800 mt-2">
            <i class="fas fa-arrow-left mr-1"></i> Retour à la liste des campagnes
        </a>
        
    <?php else: ?>
        <!-- Barre de recherche -->
        <div class="bg-white rounded-lg shadow p-4">
            <form method="GET" action="" class="relative">
                <input type="hidden" name="page" value="campagnes/historique">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>" 
                           placeholder="Rechercher par nom, objet ou message..." 
                           class="w-full pl-10 pr-10 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                    <?php if (!empty($searchTerm)): ?>
                        <a href="index.php?page=campagnes/historique" class="search-clear">
                            <i class="fas fa-times-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="mt-2 flex justify-between items-center">
                    <span class="text-xs text-gray-500">
                        <?php if (!empty($searchTerm)): ?>
                            <?= count($campagnes) ?> résultat(s) pour "<strong><?= htmlspecialchars($searchTerm) ?></strong>"
                        <?php else: ?>
                            <?= count($campagnes) ?> campagne(s) au total
                        <?php endif; ?>
                    </span>
                    <?php if (!empty($searchTerm)): ?>
                        <a href="index.php?page=campagnes/historique" class="text-sm text-blue-600 hover:text-blue-800">
                            Effacer la recherche
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Liste des campagnes (cliquables) -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Canal</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Envois</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date création</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campagnes)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                    <i class="fas fa-bullhorn text-4xl mb-2 block"></i>
                                    <?php if (!empty($searchTerm)): ?>
                                        Aucune campagne ne correspond à "<strong><?= htmlspecialchars($searchTerm) ?></strong>".
                                    <?php else: ?>
                                        Aucune campagne pour le moment.
                                        <a href="index.php?page=campagnes/creer" class="text-purple-600 block mt-2">Créer votre première campagne →</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($campagnes as $campagne): ?>
                                <tr class="campagne-row hover:bg-gray-50" 
                                    onclick="window.location.href='index.php?page=campagnes/historique&campagne_id=<?= $campagne['id_campagne_config'] ?>'">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="bg-purple-100 rounded-full p-2 mr-3">
                                                <i class="fas fa-bullhorn text-purple-600 text-sm"></i>
                                            </div>
                                            <div>
                                                <span class="font-medium text-gray-800"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                                                <?php if ($campagne['objet']): ?>
                                                    <div class="text-xs text-gray-500"><?= htmlspecialchars(substr($campagne['objet'], 0, 40)) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($campagne['id_canal'] == 'sms'): ?>
                                            <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded-full text-xs">SMS</span>
                                        <?php elseif ($campagne['id_canal'] == 'whatsapp'): ?>
                                            <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs">WhatsApp</span>
                                        <?php else: ?>
                                            <span class="bg-gray-100 text-gray-500 px-2 py-1 rounded-full text-xs">Non défini</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="font-semibold text-blue-600"><?= $campagne['nb_envois'] ?></span>
                                        <span class="text-xs text-gray-500"> envoi(s)</span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="status-badge status-<?= $campagne['statut'] ?>">
                                            <?php
                                            $statusText = [
                                                'brouillon' => 'Brouillon',
                                                'planifiee' => 'Planifiée',
                                                'envoyee' => 'Envoyée',
                                                'annulee' => 'Annulée'
                                            ];
                                            echo $statusText[$campagne['statut']] ?? $campagne['statut'];
                                            ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?= date('d/m/Y H:i', strtotime($campagne['created_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap" onclick="event.stopPropagation()">
                                        <?php if ($campagne['statut'] == 'brouillon'): ?>
                                            <a href="index.php?page=campagnes/choix&campagne_id=<?= $campagne['id_campagne_config'] ?>" 
                                               class="text-green-600 hover:text-green-800 inline-flex items-center mx-1" title="Envoyer">
                                                <i class="fas fa-paper-plane"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="index.php?page=campagnes/historique&campagne_id=<?= $campagne['id_campagne_config'] ?>" 
                                           class="text-blue-600 hover:text-blue-800 inline-flex items-center mx-1" title="Voir les envois">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>