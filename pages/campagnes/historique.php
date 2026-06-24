<?php
global $db;

$idCompte = $_SESSION['user_id'];
$campagneId = $_GET['campagne_id'] ?? null;
$searchTerm = $_GET['search'] ?? '';
$export = $_GET['export'] ?? null;
$typeFiltre = $_GET['type_filtre'] ?? ''; // Ajout du filtre par type

// ============================================
// FILTRES PAR PÉRIODE
// ============================================
$dateDebut = $_GET['date_debut'] ?? '';
$dateFin = $_GET['date_fin'] ?? '';
$errorMessage = ''; // Variable pour les messages d'erreur

// Vérification des dates
if (!empty($dateDebut) && !empty($dateFin)) {
    if (strtotime($dateDebut) > strtotime($dateFin)) {
        $errorMessage = 'La date de début ne peut pas être postérieure à la date de fin.';
        $dateDebut = '';
        $dateFin = '';
    }
}

// Construction de la requête WHERE pour les filtres
$whereConditions = ['id_compte' => $idCompte];
$orderBy = 'created_at DESC';

// Filtre par recherche de nom
$searchCondition = '';
if (!empty($searchTerm)) {
    $searchCondition = $searchTerm;
}

// Filtre par période
$periodCondition = '';
if (!empty($dateDebut) && !empty($dateFin)) {
    // Convertir les dates au format Y-m-d pour la comparaison
    $dateDebutFormatted = date('Y-m-d H:i:s', strtotime($dateDebut . ' 00:00:00'));
    $dateFinFormatted = date('Y-m-d H:i:s', strtotime($dateFin . ' 23:59:59'));
    $periodCondition = [
        'created_at >= ? AND created_at <= ?' => [$dateDebutFormatted, $dateFinFormatted]
    ];
} elseif (!empty($dateDebut)) {
    $dateDebutFormatted = date('Y-m-d H:i:s', strtotime($dateDebut . ' 00:00:00'));
    $periodCondition = ['created_at >= ?' => $dateDebutFormatted];
} elseif (!empty($dateFin)) {
    $dateFinFormatted = date('Y-m-d H:i:s', strtotime($dateFin . ' 23:59:59'));
    $periodCondition = ['created_at <= ?' => $dateFinFormatted];
}

// Récupérer les campagnes avec les filtres
$allCampagnes = $db->select('campagne_config', $whereConditions, '*', $orderBy);

// Appliquer les filtres supplémentaires (recherche et période)
$campagnes = [];
foreach ($allCampagnes as $c) {
    $match = true;
    
    // Filtre par recherche de nom
    if (!empty($searchTerm) && stripos($c['nom_campagne'], $searchTerm) === false) {
        $match = false;
    }
    
    // Filtre par période (déjà appliqué en SQL, mais on garde pour sécurité)
    if ($match && !empty($dateDebut) && !empty($dateFin)) {
        $dateCreation = strtotime($c['created_at']);
        $debut = strtotime($dateDebut . ' 00:00:00');
        $fin = strtotime($dateFin . ' 23:59:59');
        if ($dateCreation < $debut || $dateCreation > $fin) {
            $match = false;
        }
    } elseif ($match && !empty($dateDebut)) {
        $dateCreation = strtotime($c['created_at']);
        $debut = strtotime($dateDebut . ' 00:00:00');
        if ($dateCreation < $debut) {
            $match = false;
        }
    } elseif ($match && !empty($dateFin)) {
        $dateCreation = strtotime($c['created_at']);
        $fin = strtotime($dateFin . ' 23:59:59');
        if ($dateCreation > $fin) {
            $match = false;
        }
    }
    
    if ($match) {
        $campagnes[] = $c;
    }
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
        // Récupérer tous les envois
        $allEnvois = $db->select('campagne', ['id_campagne_config' => $campagneId], '*', 'created_at DESC');
        
        // Appliquer le filtre par type
        if (!empty($typeFiltre)) {
            $envoisListe = array_filter($allEnvois, function($envoi) use ($typeFiltre) {
                return $envoi['type_campagne'] === $typeFiltre;
            });
            // Réindexer le tableau
            $envoisListe = array_values($envoisListe);
        } else {
            $envoisListe = $allEnvois;
        }
    }
}

// ============================================
// EXPORT CSV
// ============================================
if ($export === 'csv' && $campagneId && !empty($envoisListe)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campagne_' . $campagneId . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Ajouter BOM UTF-8 pour gérer les accents correctement dans Excel
    fwrite($output, "\xEF\xBB\xBF");
    
    // En-têtes CSV
    fputcsv($output, [
        'Date d\'envoi',
        'Type',
        'Message',
        'Nombre de destinataires',
        'Succès',
        'Échecs',
        'Appareil utilisé',
        'Statut',
        'Destinataires'
    ]);
    
    // Données
    foreach ($envoisListe as $envoi) {
        // Décoder les destinataires
        $destinataires = json_decode($envoi['destinataires'], true);
        $destinatairesTexte = is_array($destinataires) ? implode('; ', $destinataires) : $envoi['destinataires'];
        
        // Traduire le type
        $typeTexte = $envoi['type_campagne'] == 'whatsapp' ? 'WhatsApp' : 'SMS';
        
        // Traduire le statut
        $statutTexte = $envoi['statut'] == 'envoye' ? 'Envoyé' : 'Échoué';
        
        fputcsv($output, [
            date('d/m/Y H:i', strtotime($envoi['created_at'])),
            $typeTexte,
            $envoi['message'],
            $envoi['nb_destinataires'],
            $envoi['nb_succes'],
            $envoi['nb_erreurs'],
            $envoi['appareil_utilise'] ?? '-',
            $statutTexte,
            $destinatairesTexte
        ]);
    }
    
    fclose($output);
    exit;
}

// Export de toutes les campagnes filtrées
if ($export === 'all_csv' && empty($campagneId)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="toutes_campagnes_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Ajouter BOM UTF-8 pour gérer les accents correctement dans Excel
    fwrite($output, "\xEF\xBB\xBF");
    
    // En-têtes CSV
    fputcsv($output, [
        'ID',
        'Nom de la campagne',
        'Nombre d\'envois',
        'Statut',
        'Date de création'
    ]);
    
    // Données
    foreach ($campagnes as $campagne) {
        // Traduire le statut
        $statutTexte = '';
        switch ($campagne['statut']) {
            case 'brouillon': $statutTexte = 'Brouillon'; break;
            case 'planifiee': $statutTexte = 'Planifiée'; break;
            case 'envoyee': $statutTexte = 'Envoyée'; break;
            case 'annulee': $statutTexte = 'Annulée'; break;
            default: $statutTexte = $campagne['statut'];
        }
        
        fputcsv($output, [
            $campagne['id_campagne_config'],
            $campagne['nom_campagne'],
            $campagne['nb_envois'],
            $statutTexte,
            date('d/m/Y H:i', strtotime($campagne['created_at']))
        ]);
    }
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des campagnes - <?= APP_NAME ?></title>
    <style>
        /* ============================================
           STYLES GÉNÉRAUX
           ============================================ */
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
        
        .btn-export {
            background-color: #10b981;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-export:hover {
            background-color: #059669;
        }
        
        /* ============================================
           FILTRES
           ============================================ */
        .filter-container {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            background: #f9fafb;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 12px;
        }
        .filter-container label {
            font-size: 13px;
            font-weight: 500;
            color: #4b5563;
        }
        .filter-container input[type="date"] {
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            transition: all 0.2s;
        }
        .filter-container input[type="date"]:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        .filter-container input[type="date"].error {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .filter-container .btn-filter {
            background: #8b5cf6;
            color: white;
            padding: 6px 16px;
            border-radius: 6px;
            border: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-container .btn-filter:hover {
            background: #7c3aed;
        }
        .filter-container .btn-clear {
            background: #e5e7eb;
            color: #4b5563;
            padding: 6px 16px;
            border-radius: 6px;
            border: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-container .btn-clear:hover {
            background: #d1d5db;
        }
        .filter-container .filter-info {
            font-size: 12px;
            color: #6b7280;
        }
        .filter-container .filter-info strong {
            color: #374151;
        }

        /* ============================================
           FILTRE PAR TYPE (DANS LES DÉTAILS)
           ============================================ */
        .type-filter-container {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            background: #f3f4f6;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 12px;
        }
        .type-filter-container label {
            font-size: 13px;
            font-weight: 500;
            color: #4b5563;
            margin-right: 4px;
        }
        .type-filter-container select {
            padding: 5px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
        }
        .type-filter-container select:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        .type-filter-container .btn-filter-type {
            background: #8b5cf6;
            color: white;
            padding: 5px 14px;
            border-radius: 6px;
            border: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .type-filter-container .btn-filter-type:hover {
            background: #7c3aed;
        }
        .type-filter-container .btn-clear-type {
            background: #e5e7eb;
            color: #4b5563;
            padding: 5px 14px;
            border-radius: 6px;
            border: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .type-filter-container .btn-clear-type:hover {
            background: #d1d5db;
        }
        .type-filter-container .filter-type-info {
            font-size: 12px;
            color: #6b7280;
        }
        .type-filter-container .filter-type-info strong {
            color: #374151;
        }

        /* ============================================
           TOAST / NOTIFICATION
           ============================================ */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 400px;
            width: 100%;
            pointer-events: none;
        }
        .toast {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            pointer-events: auto;
            animation: slideInRight 0.4s ease forwards;
            border-left: 4px solid;
        }
        .toast.error {
            border-left-color: #ef4444;
        }
        .toast.success {
            border-left-color: #10b981;
        }
        .toast.warning {
            border-left-color: #f59e0b;
        }
        .toast.info {
            border-left-color: #3b82f6;
        }
        .toast-icon {
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .toast-content {
            flex: 1;
        }
        .toast-title {
            font-weight: 600;
            color: #1f2937;
            font-size: 14px;
            margin-bottom: 4px;
        }
        .toast-message {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.4;
        }
        .toast-close {
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            font-size: 18px;
            padding: 0 4px;
            transition: color 0.2s;
            flex-shrink: 0;
        }
        .toast-close:hover {
            color: #4b5563;
        }
        .toast.hide {
            animation: slideOutRight 0.3s ease forwards;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        @keyframes slideOutRight {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(100px);
            }
        }

        /* ============================================
           MESSAGE D'ERREUR INTÉGRÉ
           ============================================ */
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #991b1b;
            font-size: 14px;
            animation: fadeIn 0.3s ease;
        }
        .error-message i {
            font-size: 18px;
            color: #dc2626;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<!-- ============================================
     CONTAINER POUR TOASTS
     ============================================ -->
<div id="toastContainer" class="toast-container"></div>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Historique des campagnes</h1>
            <p class="text-gray-500">Consultez toutes vos campagnes et leurs envois</p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <?php if ($campagneId && !empty($envoisListe)): ?>
                <a href="?page=campagnes/historique&campagne_id=<?= $campagneId ?>&export=csv<?= !empty($typeFiltre) ? '&type_filtre=' . urlencode($typeFiltre) : '' ?>" 
                   class="btn-export">
                    <i class="fas fa-download"></i> Exporter cette campagne (CSV)
                </a>
            <?php elseif (empty($campagneId) && !empty($campagnes)): ?>
                <a href="?page=campagnes/historique&export=all_csv<?= !empty($dateDebut) ? '&date_debut=' . urlencode($dateDebut) : '' ?><?= !empty($dateFin) ? '&date_fin=' . urlencode($dateFin) : '' ?><?= !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '' ?>" 
                   class="btn-export">
                    <i class="fas fa-download"></i> Exporter les résultats (CSV)
                </a>
            <?php endif; ?>
            
            <?php if ($campagneId): ?>
                <a href="index.php?page=campagnes/historique" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-1"></i> Voir toutes les campagnes
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($campagneId && $campagneSelectionnee): ?>
        <!-- ============================================
             DÉTAILS DE LA CAMPAGNE SÉLECTIONNÉE
             ============================================ -->
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
                <?php if (!empty($campagneSelectionnee['date_planification'])): ?>
                    <div class="mt-2 text-sm text-gray-600">
                        <strong>Planifiée le :</strong> <?= date('d/m/Y H:i', strtotime($campagneSelectionnee['date_planification'])) ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Liste des envois de cette campagne -->
            <div class="p-4">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-semibold text-gray-700">
                        <i class="fas fa-envelope mr-2"></i>
                        Envois réalisés (<?= count($envoisListe) ?>)
                    </h3>
                    <?php if (!empty($envoisListe)): ?>
                        <a href="?page=campagnes/historique&campagne_id=<?= $campagneId ?>&export=csv<?= !empty($typeFiltre) ? '&type_filtre=' . urlencode($typeFiltre) : '' ?>" 
                           class="text-sm text-green-600 hover:text-green-800">
                            <i class="fas fa-file-csv mr-1"></i> Exporter en CSV
                        </a>
                    <?php endif; ?>
                </div>
                
                <!-- Filtre par type pour les détails de la campagne -->
                <div class="type-filter-container">
                    <label for="type_filtre">Filtrer par type :</label>
                    <form method="GET" action="" style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px;" id="typeFilterForm">
                        <input type="hidden" name="page" value="campagnes/historique">
                        <input type="hidden" name="campagne_id" value="<?= $campagneId ?>">
                        <select name="type_filtre" id="type_filtre">
                            <option value="">Tous les types</option>
                            <option value="whatsapp" <?= $typeFiltre === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
                            <option value="sms" <?= $typeFiltre === 'sms' ? 'selected' : '' ?>>SMS</option>
                        </select>
                        <button type="submit" class="btn-filter-type">
                            <i class="fas fa-filter mr-1"></i> Filtrer
                        </button>
                        <?php if (!empty($typeFiltre)): ?>
                            <a href="?page=campagnes/historique&campagne_id=<?= $campagneId ?>" class="btn-clear-type">
                                <i class="fas fa-times mr-1"></i> Effacer
                            </a>
                        <?php endif; ?>
                        <div class="filter-type-info">
                            <?php if (!empty($typeFiltre)): ?>
                                Affichage des envois de type <strong><?= $typeFiltre === 'whatsapp' ? 'WhatsApp' : 'SMS' ?></strong>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <?php if (empty($envoisListe)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-inbox text-3xl mb-2 block"></i>
                        <?php if (!empty($typeFiltre)): ?>
                            Aucun envoi de type <strong><?= $typeFiltre === 'whatsapp' ? 'WhatsApp' : 'SMS' ?></strong> pour cette campagne.
                        <?php else: ?>
                            Aucun envoi pour cette campagne.
                        <?php endif; ?>
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
                    $totalWhatsApp = 0;
                    $totalSMS = 0;
                    foreach ($envoisListe as $e) {
                        $totalSucces += $e['nb_succes'];
                        $totalErreurs += $e['nb_erreurs'];
                        $totalDestinataires += $e['nb_destinataires'];
                        if ($e['type_campagne'] == 'whatsapp') {
                            $totalWhatsApp++;
                        } else {
                            $totalSMS++;
                        }
                    }
                    ?>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-4 pt-3 border-t">
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
                        <div class="bg-green-100 rounded-lg p-2 text-center">
                            <div class="text-lg font-bold text-green-700"><?= $totalWhatsApp ?></div>
                            <div class="text-xs text-gray-500">WhatsApp</div>
                        </div>
                        <div class="bg-blue-100 rounded-lg p-2 text-center">
                            <div class="text-lg font-bold text-blue-700"><?= $totalSMS ?></div>
                            <div class="text-xs text-gray-500">SMS</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <a href="javascript:history.back()" class="inline-block text-blue-600 hover:text-blue-800 mt-2">
            <i class="fas fa-arrow-left mr-1"></i> Retour
        </a>
        
    <?php else: ?>
        <!-- ============================================
             BARRE DE RECHERCHE ET FILTRES DE PÉRIODE
             ============================================ -->
        <div class="bg-white rounded-lg shadow p-4">
            <form method="GET" action="" id="filterForm">
                <input type="hidden" name="page" value="campagnes/historique">
                
                <!-- Recherche par nom -->
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchTerm) ?>" 
                           placeholder="Rechercher par nom de campagne..." 
                           class="w-full pl-10 pr-10 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-purple-500">
                    <?php if (!empty($searchTerm)): ?>
                        <a href="index.php?page=campagnes/historique<?= !empty($dateDebut) ? '&date_debut=' . urlencode($dateDebut) : '' ?><?= !empty($dateFin) ? '&date_fin=' . urlencode($dateFin) : '' ?>" class="search-clear">
                            <i class="fas fa-times-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
                
                <!-- Filtres par période -->
                <div class="filter-container">
                    <label for="date_debut">Du :</label>
                    <input type="date" name="date_debut" id="date_debut" value="<?= htmlspecialchars($dateDebut) ?>" class="<?= !empty($errorMessage) ? 'error' : '' ?>">
                    
                    <label for="date_fin">Au :</label>
                    <input type="date" name="date_fin" id="date_fin" value="<?= htmlspecialchars($dateFin) ?>" class="<?= !empty($errorMessage) ? 'error' : '' ?>">
                    
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-filter mr-1"></i> Filtrer
                    </button>
                    
                    <?php if (!empty($dateDebut) || !empty($dateFin) || !empty($searchTerm)): ?>
                        <a href="index.php?page=campagnes/historique" class="btn-clear">
                            <i class="fas fa-times mr-1"></i> Effacer les filtres
                        </a>
                    <?php endif; ?>
                    
                    <div class="filter-info">
                        <?php
                        $totalFiltres = 0;
                        if (!empty($searchTerm)) $totalFiltres++;
                        if (!empty($dateDebut)) $totalFiltres++;
                        if (!empty($dateFin)) $totalFiltres++;
                        
                        if ($totalFiltres > 0) {
                            echo count($campagnes) . ' résultat(s)';
                            if (!empty($searchTerm)) {
                                echo ' pour "<strong>' . htmlspecialchars($searchTerm) . '</strong>"';
                            }
                            if (!empty($dateDebut) && !empty($dateFin)) {
                                echo ' du <strong>' . date('d/m/Y', strtotime($dateDebut)) . '</strong> au <strong>' . date('d/m/Y', strtotime($dateFin)) . '</strong>';
                            } elseif (!empty($dateDebut)) {
                                echo ' à partir du <strong>' . date('d/m/Y', strtotime($dateDebut)) . '</strong>';
                            } elseif (!empty($dateFin)) {
                                echo ' jusqu\'au <strong>' . date('d/m/Y', strtotime($dateFin)) . '</strong>';
                            }
                        } else {
                            echo count($campagnes) . ' campagne(s) au total';
                        }
                        ?>
                    </div>
                </div>
                
                <!-- Message d'erreur intégré -->
                <?php if (!empty($errorMessage)): ?>
                    <div class="error-message" id="errorMessage">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= htmlspecialchars($errorMessage) ?></span>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Liste des campagnes (cliquables) -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Envois</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date création</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campagnes)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                    <i class="fas fa-bullhorn text-4xl mb-2 block"></i>
                                    <?php if (!empty($searchTerm) || !empty($dateDebut) || !empty($dateFin)): ?>
                                        Aucune campagne ne correspond aux filtres sélectionnés.
                                        <div class="mt-2">
                                            <a href="index.php?page=campagnes/historique" class="text-purple-600">Réinitialiser les filtres</a>
                                        </div>
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
                                            <span class="font-medium text-gray-800"><?= htmlspecialchars($campagne['nom_campagne']) ?></span>
                                        </div>
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

<script>
// ============================================
// FONCTIONS POUR LES TOASTS
// ============================================
function showToast(title, message, type = 'info', duration = 5000) {
    const container = document.getElementById('toastContainer');
    
    // Icônes par type
    const icons = {
        error: 'fas fa-times-circle',
        success: 'fas fa-check-circle',
        warning: 'fas fa-exclamation-triangle',
        info: 'fas fa-info-circle'
    };
    
    // Créer le toast
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="${icons[type] || icons.info}"></i>
        </div>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.closest('.toast').remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    container.appendChild(toast);
    
    // Supprimer automatiquement après la durée
    setTimeout(() => {
        if (toast.parentNode) {
            toast.classList.add('hide');
            setTimeout(() => toast.remove(), 300);
        }
    }, duration);
}

// ============================================
// VALIDATION DES DATES AVEC TOAST
// ============================================
const dateDebut = document.getElementById('date_debut');
const dateFin = document.getElementById('date_fin');

if (dateDebut && dateFin) {
    dateDebut.addEventListener('change', function() {
        if (dateFin.value && this.value > dateFin.value) {
            showToast(
                'Erreur de date',
                'La date de début ne peut pas être postérieure à la date de fin.',
                'error',
                4000
            );
            this.classList.add('error');
            this.value = '';
            setTimeout(() => this.classList.remove('error'), 500);
        } else {
            this.classList.remove('error');
        }
    });
    
    dateFin.addEventListener('change', function() {
        if (dateDebut.value && this.value < dateDebut.value) {
            showToast(
                'Erreur de date',
                'La date de fin ne peut pas être antérieure à la date de début.',
                'error',
                4000
            );
            this.classList.add('error');
            this.value = '';
            setTimeout(() => this.classList.remove('error'), 500);
        } else {
            this.classList.remove('error');
        }
    });
}

// ============================================
// RÉINITIALISATION DES FILTRES
// ============================================
document.querySelectorAll('.btn-clear, .btn-clear-type').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = this.getAttribute('href');
    });
});

// ============================================
// SOUMISSION AUTOMATIQUE DU FILTRE TYPE
// ============================================
document.getElementById('type_filtre')?.addEventListener('change', function() {
    this.closest('form').submit();
});

// ============================================
// AFFICHAGE D'UN TOAST SI MESSAGE D'ERREUR PHP
// ============================================
<?php if (!empty($errorMessage)): ?>
document.addEventListener('DOMContentLoaded', function() {
    showToast(
        'Erreur de validation',
        '<?= addslashes($errorMessage) ?>',
        'error',
        5000
    );
});
<?php endif; ?>

// ============================================
// TOAST DE BIENVENUE / INFORMATION
// ============================================
<?php if (isset($_GET['filtered']) && $_GET['filtered'] === 'success'): ?>
document.addEventListener('DOMContentLoaded', function() {
    showToast(
        'Filtres appliqués',
        'Les filtres ont été appliqués avec succès.',
        'success',
        3000
    );
});
<?php endif; ?>
</script>

</body>
</html>