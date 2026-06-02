<?php
global $db;

$idCompte = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier'])) {
    $file = $_FILES['fichier'];
    
    // Vérifier les erreurs d'upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = "Erreur lors de l'upload du fichier.";
        header('Location: index.php?page=contacts/index');
        exit;
    }
    
    // Vérifier l'extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xls', 'xlsx'])) {
        $_SESSION['flash_error'] = "Format non supporté. Utilisez CSV, XLS ou XLSX.";
        header('Location: index.php?page=contacts/index');
        exit;
    }
    
    // Lire le fichier
    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        $_SESSION['flash_error'] = "Impossible d'ouvrir le fichier.";
        header('Location: index.php?page=contacts/index');
        exit;
    }
    
    // Lire l'en-tête
    $headers = fgetcsv($handle, 0, ';');
    if (!$headers) {
        $headers = fgetcsv($handle, 0, ',');
    }
    
    // Standardiser les en-têtes
    $headers = array_map('trim', $headers);
    $headers = array_map('strtolower', $headers);
    
    // Mapping des colonnes
    $mapping = [
        'prenom' => array_search('prenom', $headers),
        'nom' => array_search('nom', $headers),
        'email' => array_search('email', $headers),
        'telephone' => array_search('telephone', $headers),
        'ville' => array_search('ville', $headers),
        'adresse' => array_search('adresse', $headers),
        'codepostal' => array_search('codepostal', $headers),
        'code_postal' => array_search('code_postal', $headers),
        'pays' => array_search('pays', $headers),
        'datenaissance' => array_search('datenaissance', $headers),
        'date_naissance' => array_search('date_naissance', $headers)
    ];
    
    $importedCount = 0;
    $existingCount = 0;
    $errorCount = 0;
    $errors = [];
    $rowNumber = 1;
    
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $rowNumber++;
        if (count($row) < 2) {
            // Essayer avec virgule
            fseek($handle, 0);
            fgetcsv($handle, 0, ',');
            continue;
        }
        
        // Extraire les données
        $prenom = $mapping['prenom'] !== false ? trim($row[$mapping['prenom']] ?? '') : '';
        $nom = $mapping['nom'] !== false ? trim($row[$mapping['nom']] ?? '') : '';
        $email = $mapping['email'] !== false ? trim($row[$mapping['email']] ?? '') : null;
        $telephone = $mapping['telephone'] !== false ? trim($row[$mapping['telephone']] ?? '') : null;
        
        // Validation : prénom et nom requis
        if (empty($prenom) || empty($nom)) {
            $errorCount++;
            $errors[] = "Ligne $rowNumber: Prénom et nom requis";
            continue;
        }
        
        // Vérification si le contact existe déjà
        $contactExists = false;
        
        // 1. Vérification par email
        if (!empty($email)) {
            $existing = $db->select('contact', [
                'id_compte' => $idCompte,
                'email' => $email
            ]);
            if (!empty($existing)) {
                $contactExists = true;
            }
        }
        
        // 2. Vérification par nom + prénom + téléphone
        if (!$contactExists && !empty($telephone)) {
            $existing = $db->select('contact', [
                'id_compte' => $idCompte,
                'nom' => $nom,
                'prenom' => $prenom,
                'telephone' => $telephone
            ]);
            if (!empty($existing)) {
                $contactExists = true;
            }
        }
        
        // 3. Vérification par nom + prénom + email
        if (!$contactExists && !empty($email)) {
            $existing = $db->select('contact', [
                'id_compte' => $idCompte,
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email
            ]);
            if (!empty($existing)) {
                $contactExists = true;
            }
        }
        
        // Si le contact existe déjà, on ne l'importe pas
        if ($contactExists) {
            $existingCount++;
            continue;
        }
        
        // Créer les données du nouveau contact
        $data = [
            'id_compte' => $idCompte,
            'prenom' => $prenom,
            'nom' => $nom,
            'email' => !empty($email) ? $email : null,
            'telephone' => !empty($telephone) ? $telephone : null,
            'ville' => $mapping['ville'] !== false ? trim($row[$mapping['ville']] ?? '') : null,
            'adresse' => $mapping['adresse'] !== false ? trim($row[$mapping['adresse']] ?? '') : null,
            'code_postal' => ($mapping['codepostal'] !== false ? trim($row[$mapping['codepostal']] ?? '') : ($mapping['code_postal'] !== false ? trim($row[$mapping['code_postal']] ?? '') : null)),
            'pays' => $mapping['pays'] !== false ? trim($row[$mapping['pays']] ?? 'France') : 'France',
            'date_naissance' => ($mapping['datenaissance'] !== false ? trim($row[$mapping['datenaissance']] ?? '') : ($mapping['date_naissance'] !== false ? trim($row[$mapping['date_naissance']] ?? '') : null))
        ];
        
        // Insérer le nouveau contact
        try {
            $db->insert('contact', $data);
            $importedCount++;
        } catch (Exception $e) {
            $errorCount++;
            $errors[] = "Ligne $rowNumber: Erreur insertion - " . $e->getMessage();
        }
    }
    fclose($handle);
    
    // Construction du message de résultat
    if ($importedCount > 0) {
        $message = "$importedCount contact(s) importé(s) avec succès.";
        if ($existingCount > 0) {
            $message .= " $existingCount contact(s) existant(s) ignoré(s).";
        }
        $_SESSION['flash_message'] = $message;
    } else {
        if ($existingCount > 0) {
            $_SESSION['flash_error'] = "Aucun nouveau contact importé. $existingCount contact(s) existant(s) ignoré(s).";
        } else {
            $_SESSION['flash_error'] = "Aucun contact importé. Vérifiez le format de votre fichier.";
        }
    }
    
    // Redirection vers la liste des contacts
    header('Location: index.php?page=contacts/index');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importer des contacts - <?= APP_NAME ?></title>
    <style>
        /* Toast notification */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.3s ease-out;
        }
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        .toast-notification .toast-content {
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>

<div class="space-y-6">
    <div class="flex items-center">
        <a href="index.php?page=contacts/index" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <h1 class="text-2xl font-bold text-gray-800">Importer des contacts</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-bold mb-4">Format du fichier</h2>
        <div class="bg-gray-50 p-4 rounded mb-6">
            <p class="text-sm text-gray-600 mb-2">Votre fichier CSV doit contenir les colonnes suivantes (séparateur : point-virgule ou virgule) :</p>
            <ul class="text-sm text-gray-600 list-disc list-inside">
                <li><strong>prenom</strong> - Prénom (requis)</li>
                <li><strong>nom</strong> - Nom (requis)</li>
                <li><strong>email</strong> - Adresse email (optionnel)</li>
                <li><strong>telephone</strong> - Téléphone (optionnel, format 33612345678)</li>
                <li><strong>ville</strong> - Ville (optionnel)</li>
                <li><strong>adresse</strong> - Adresse (optionnel)</li>
li><strong>code_postal</strong> - Code postal (optionnel)</li>
                <li><strong>pays</strong> - Pays (optionnel, défaut: France)</li>
                <li><strong>date_naissance</strong> - Date de naissance (optionnel, format YYYY-MM-DD)</li>
            </ul>
        </div>
        
        <div class="bg-blue-50 p-4 rounded mb-6">
            <h3 class="font-medium text-blue-800 mb-2"> Comment ça fonctionne ?</h3>
            <ul class="text-sm text-blue-700 space-y-1">
                <li><i class="fas fa-check-circle mr-2"></i> Vérification par <strong>email</strong> d'abord</li>
                <li><i class="fas fa-check-circle mr-2"></i> Vérification par <strong>nom + prénom + téléphone</strong> ensuite</li>
                <li><i class="fas fa-check-circle mr-2"></i> Vérification par <strong>nom + prénom + email</strong> enfin</li>
                <li><i class="fas fa-info-circle mr-2"></i> Les contacts déjà existants sont ignorés (non importés)</li>
            </ul>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Fichier CSV/Excel</label>
                <input type="file" name="fichier" accept=".csv,.xls,.xlsx" required 
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500">
                <p class="text-xs text-gray-500 mt-1">Formats acceptés : CSV, XLS, XLSX (Taille max : 10MB)</p>
            </div>
            
            <div class="mt-6 flex justify-end">
                <a href="index.php?page=contacts/index" class="px-4 py-2 border border-gray-300 rounded-lg mr-2 hover:bg-gray-50">
                    Annuler
                </a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                    <i class="fas fa-upload mr-2"></i>Importer
                </button>
            </div>
        </form>
        
    </div>
</div>
</body>
</html>