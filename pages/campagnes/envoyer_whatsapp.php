<?php
global $db;

$idCompte = $_SESSION['user_id'];

// ============================================
// RÉCUPÉRATION DE LA CAMPAGNE CONFIG
// ============================================
$campagneConfigId = $_POST['campagne_config_id'] ?? $_SESSION['campagne_config_id'] ?? null;

if (!$campagneConfigId) {
    header('Location: index.php?page=campagnes/creer');
    exit;
}

// Récupérer les infos de la campagne config
$campagneConfig = $db->select('campagne_config', [
    'id_campagne_config' => $campagneConfigId,
    'id_compte' => $idCompte
]);

if (empty($campagneConfig)) {
    header('Location: index.php?page=campagnes/creer');
    exit;
}

$campagne = $campagneConfig[0];

// Nettoyer la session
unset($_SESSION['campagne_config_id']);

// ============================================
// RÉCUPÉRATION DE L'ID DU TYPE MESSAGE WHATSAPP
// ============================================
$whatsappTypeId = null;

$typeMessageWhatsapp = $db->select('type_message', ['libelle_type' => 'WhatsApp']);
if (empty($typeMessageWhatsapp)) {
    $typeMessageWhatsapp = $db->select('type_message', ['libelle_type' => 'whatsapp']);
}
if (empty($typeMessageWhatsapp)) {
    $typeMessageWhatsapp = $db->select('type_message', ['libelle_type' => 'WHATSAPP']);
}

if (!empty($typeMessageWhatsapp)) {
    $whatsappTypeId = $typeMessageWhatsapp[0]['id_type_message'];
} else {
    die("Erreur: Le type de message 'WhatsApp' n'existe pas dans la base de données.");
}

// ============================================
// RÉCUPÉRATION DE LA BLACKLIST POUR WHATSAPP
// ============================================
$blacklist = $db->select('blacklist', ['id_type_message' => $whatsappTypeId]);
$blacklistIds = [];
foreach ($blacklist as $b) {
    if (!empty($b['id_contact'])) {
        $blacklistIds[] = $b['id_contact'];
    }
}

// Récupérer la session WhatsApp active
$sessions = $db->select('whatsapp_sessions', [
    'id_compte' => $idCompte,
    'est_active' => true
]);

$whatsappSession = null;
if (!empty($sessions)) {
    $whatsappSession = $sessions[0]['nom_session'];
} else {
    $sessions = $db->select('whatsapp_sessions', ['id_compte' => $idCompte], '*', 'created_at.desc');
    if (!empty($sessions)) {
        $whatsappSession = $sessions[0]['nom_session'];
        $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $sessions[0]['id_session']]);
    }
}

if (!$whatsappSession) {
    header('Location: index.php?page=campagnes/choix');
    exit;
}

// Récupérer tous les contacts du compte
$tousContacts = $db->select('contact', ['id_compte' => $idCompte]);

// Filtrer les contacts non blacklistés pour WhatsApp
$contacts = [];
foreach ($tousContacts as $contact) {
    if (!in_array($contact['id_contact'], $blacklistIds)) {
        $contacts[] = $contact;
    }
}

// Récupérer les listes avec le nombre de contacts (excluant blacklist WhatsApp)
$listesBrutes = $db->select('liste', ['id_compte' => $idCompte]);
$listes = [];

foreach ($listesBrutes as $liste) {
    $listeContacts = $db->select('liste_contact', ['id_liste' => $liste['id_liste']]);
    $nbContacts = 0;
    foreach ($listeContacts as $lc) {
        if (!in_array($lc['id_contact'], $blacklistIds)) {
            $nbContacts++;
        }
    }
    
    $listes[] = [
        'id_liste' => $liste['id_liste'],
        'nom_liste' => $liste['nom_liste'],
        'nombre_contacts' => $nbContacts
    ];
}

$error = '';
$success = '';
$resultats = [];

// Fonction pour formater correctement un numéro WhatsApp
function formatWhatsAppNumber($telephone) {
    if (empty($telephone)) {
        return null;
    }
    
    $telephone = preg_replace('/[^0-9]/', '', $telephone);
    
    if (strlen($telephone) == 9) {
        $telephone = '261' . $telephone;
    } elseif (strlen($telephone) == 10 && substr($telephone, 0, 1) == '0') {
        $telephone = '33' . substr($telephone, 1);
    } elseif (strlen($telephone) == 9 && substr($telephone, 0, 1) != '0') {
        $telephone = '261' . $telephone;
    }
    
    if (strlen($telephone) < 10 || strlen($telephone) > 15) {
        return null;
    }
    
    return $telephone;
}

// Fonction pour déterminer le type de fichier
function getFileType($mimeType) {
    if (strpos($mimeType, 'image/') !== false) {
        return 'image';
    } elseif (strpos($mimeType, 'video/') !== false) {
        return 'video';
    } elseif (strpos($mimeType, 'audio/') !== false) {
        return 'voice';
    } else {
        return 'file';
    }
}

// ============================================
// FONCTION POUR PRÉPARER LE FICHIER (UNE SEULE FOIS)
// ============================================
function preparerFichier($hasFile, $hasAudio) {
    global $db, $idCompte;
    
    $type = 'text';
    $payload = [];
    $fichierPret = false;
    
    // Gestion de l'audio enregistré
    if ($hasAudio) {
        $audioData = $_POST['audio_data'] ?? '';
        $base64Data = preg_replace('#^data:audio/[^;]+;base64,#', '', $audioData);
        $fileData = $base64Data;
        $originalName = 'audio_enregistre_' . date('Ymd_His') . '.webm';
        
        $type = 'voice';
        $payload = [
            'data' => $fileData,
            'mimetype' => 'audio/webm',
            'filename' => $originalName
        ];
        $fichierPret = true;
    }
    // Gestion des fichiers uploadés
    elseif ($hasFile && isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['fichier'];
        $uploadDir = '/tmp/whatsapp_uploads/';
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $originalName = $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $tempName = uniqid() . '.' . $extension;
        $filePath = $uploadDir . $tempName;
        
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $mimeType = mime_content_type($filePath);
            $fileData = base64_encode(file_get_contents($filePath));
            $type = getFileType($mimeType);
            
            $payload = [
                'data' => $fileData,
                'mimetype' => $mimeType,
                'filename' => $originalName
            ];
            
            unlink($filePath);
            $fichierPret = true;
        } else {
            return ['success' => false, 'error' => "Erreur lors de l'upload du fichier"];
        }
    }
    
    return [
        'success' => true,
        'type' => $type,
        'payload' => $payload,
        'fichier_pret' => $fichierPret
    ];
}

// ============================================
// FONCTION POUR ENVOYER UN MESSAGE À UN SEUL CONTACT
// ============================================
function envoyerMessageWhatsAppIndividual($contact, $message, $fichierData, $whatsappSession, $apiUrl, $apiKey) {
    
    $data = [
        'session' => $whatsappSession,
        'type' => $fichierData['type'],
        'contacts' => [$contact],
        'payload' => $fichierData['payload'],
        'min_delay' => 0,
        'max_delay' => 0
    ];
    
    // Si c'est un message texte simple sans fichier
    if ($fichierData['type'] === 'text' && empty($fichierData['payload']['text'])) {
        $data['payload']['text'] = $message;
    }
    // Si c'est un fichier avec légende
    elseif ($fichierData['type'] !== 'text' && !empty($message) && $fichierData['type'] !== 'voice') {
        $data['payload']['caption'] = $message;
    }
    
    $fullUrl = $apiUrl . '/messages/send-bulk';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'X-Controller-Key: ' . $apiKey
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    error_log("=== WhatsApp Envoi Individual ===");
    error_log("Contact: " . $contact);
    error_log("HTTP Code: " . $httpCode);
    error_log("Response: " . $response);
    
    $isSuccess = ($httpCode === 200 || $httpCode === 201);
    $responseData = json_decode($response, true);
    
    $errorMsg = null;
    if (!$isSuccess) {
        if (isset($responseData['error'])) {
            $errorMsg = $responseData['error'];
        } elseif (isset($responseData['data']['failed']) && count($responseData['data']['failed']) > 0) {
            $errorMsg = $responseData['data']['failed'][0]['error'] ?? 'Erreur inconnue';
        } else {
            $errorMsg = substr($response, 0, 200);
        }
    }
    
    return [
        'success' => $isSuccess,
        'httpCode' => $httpCode,
        'response' => $response,
        'error' => $errorMsg,
        'responseData' => $responseData
    ];
}

// Configuration API
$apiUrl = 'http://164.68.103.147:8081/api/controller.php';
$apiKey = defined('WHATSAPP_API_KEY') ? WHATSAPP_API_KEY : '29f51fbe00e64ac5a5e3ce6eefbb79b5';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type_envoi = $_POST['type_envoi'] ?? 'simple';
    $message = trim($_POST['message'] ?? '');
    $min_delay = intval($_POST['min_delay'] ?? 60);
    $max_delay = intval($_POST['max_delay'] ?? 180);
    $hasFile = isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK;
    $audioData = $_POST['audio_data'] ?? '';
    $hasAudio = !empty($audioData) && strpos($audioData, 'base64,') !== false;
    
    // Re-récupérer l'ID du type WhatsApp
    $typeMessageWhatsapp = $db->select('type_message', ['libelle_type' => 'WhatsApp']);
    if (empty($typeMessageWhatsapp)) {
        $typeMessageWhatsapp = $db->select('type_message', ['libelle_type' => 'whatsapp']);
    }
    $whatsappTypeId = !empty($typeMessageWhatsapp) ? $typeMessageWhatsapp[0]['id_type_message'] : null;
    
    // Re-récupérer la blacklist WhatsApp pour l'envoi
    $blacklistWhatsappIds = [];
    if ($whatsappTypeId) {
        $blacklistWhatsapp = $db->select('blacklist', ['id_type_message' => $whatsappTypeId]);
        foreach ($blacklistWhatsapp as $b) {
            if (!empty($b['id_contact'])) {
                $blacklistWhatsappIds[] = $b['id_contact'];
            }
        }
    }
    
    // ============================================
    // PRÉPARER LE FICHIER UNE SEULE FOIS
    // ============================================
    $fichierData = null;
    if ($hasFile || $hasAudio) {
        $preparation = preparerFichier($hasFile, $hasAudio);
        if (!$preparation['success']) {
            $error = $preparation['error'];
        } else {
            $fichierData = [
                'type' => $preparation['type'],
                'payload' => $preparation['payload'],
                'fichier_pret' => $preparation['fichier_pret']
            ];
        }
    }
    
    // Si pas d'erreur de fichier, continuer
    if (!isset($error) || empty($error)) {
        if (empty($message) && !$hasFile && !$hasAudio) {
            $error = "Veuillez saisir un message ou joindre un fichier/audio";
        } elseif ($type_envoi === 'simple') {
            $chatId = $_POST['chat_id'] ?? '';
            
            if (empty($chatId)) {
                $error = "Veuillez sélectionner un destinataire";
            } else {
                $phoneNumber = str_replace('@c.us', '', $chatId);
                
                $contactInfo = $db->select('contact', ['telephone' => $phoneNumber, 'id_compte' => $idCompte]);
                if (!empty($contactInfo) && in_array($contactInfo[0]['id_contact'], $blacklistWhatsappIds)) {
                    $error = "Ce contact est blacklisté pour WhatsApp et ne peut pas recevoir de message.";
                } else {
                    // Préparer les données pour l'envoi
                    $fichierDataPourEnvoi = $fichierData ?? [
                        'type' => 'text',
                        'payload' => ['text' => $message],
                        'fichier_pret' => false
                    ];
                    
                    $resultat = envoyerMessageWhatsAppIndividual($phoneNumber, $message, $fichierDataPourEnvoi, $whatsappSession, $apiUrl, $apiKey);
                    
                    if ($resultat['success']) {
                        $success = "Message envoyé avec succès à " . $phoneNumber;
                        $db->update('campagne_config', [
                            'statut' => 'envoyee',
                            'sent_at' => date('Y-m-d H:i:s')
                        ], ['id_campagne_config' => $campagneConfigId]);
                        
                        $campagneData = [
                            'id_compte' => $idCompte,
                            'id_campagne_config' => $campagneConfigId,
                            'type_campagne' => 'whatsapp',
                            'titre' => "WhatsApp: " . substr($message, 0, 40),
                            'message' => $message,
                            'destinataires' => json_encode([$phoneNumber]),
                            'nb_destinataires' => 1,
                            'nb_envoyes' => 1,
                            'nb_succes' => 1,
                            'nb_erreurs' => 0,
                            'appareil_utilise' => $whatsappSession,
                            'statut' => 'envoye',
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        $db->insert('campagne', $campagneData);
                    } else {
                        $error = "Échec de l'envoi: " . $resultat['error'];
                    }
                }
            }
        } else {
            // ============================================
            // ENVOI MULTIPLE
            // ============================================
            $liste_id = $_POST['liste_id'] ?? '';
            
            if (empty($liste_id)) {
                $error = "Veuillez sélectionner une liste";
            } else {
                $listeContacts = $db->select('liste_contact', ['id_liste' => $liste_id]);
                $destinataires = [];
                $contactsInfo = [];
                $contactsBlacklistes = [];
                
                foreach ($listeContacts as $lc) {
                    if (!in_array($lc['id_contact'], $blacklistWhatsappIds)) {
                        $contact = $db->select('contact', ['id_contact' => $lc['id_contact']]);
                        if (!empty($contact)) {
                            $contact = $contact[0];
                            $telephone = $contact['telephone'] ?? '';
                            if (!empty($telephone)) {
                                $phoneNumber = formatWhatsAppNumber($telephone);
                                if ($phoneNumber) {
                                    $destinataires[] = $phoneNumber;
                                    $contactsInfo[$phoneNumber] = $contact;
                                }
                            }
                        }
                    } else {
                        $contactBlack = $db->select('contact', ['id_contact' => $lc['id_contact']]);
                        if (!empty($contactBlack)) {
                            $contactsBlacklistes[] = $contactBlack[0]['prenom'] . ' ' . $contactBlack[0]['nom'];
                        }
                    }
                }
                
                if (empty($destinataires)) {
                    if (!empty($contactsBlacklistes)) {
                        $error = "Aucun destinataire valide. " . count($contactsBlacklistes) . " contact(s) sont blacklistés pour WhatsApp.";
                    } else {
                        $error = "Aucun destinataire valide dans cette liste";
                    }
                } else {
                    // ============================================
                    // ENVOI UN PAR UN AVEC LE MÊME FICHIER
                    // ============================================
                    $total = count($destinataires);
                    $succes = 0;
                    $echecs = 0;
                    $resultatsDetails = [];
                    $destinatairesNoms = [];
                    
                    // Préparer les données du fichier pour tous les envois
                    $fichierDataPourEnvoi = $fichierData ?? [
                        'type' => 'text',
                        'payload' => ['text' => $message],
                        'fichier_pret' => false
                    ];
                    
                    // Si c'est un message texte simple
                    if ($fichierDataPourEnvoi['type'] === 'text' && empty($fichierDataPourEnvoi['payload']['text'])) {
                        $fichierDataPourEnvoi['payload']['text'] = $message;
                    }
                    
                    foreach ($destinataires as $index => $phoneNumber) {
                        if ($index > 0) {
                            $delay = rand($min_delay, $max_delay);
                            sleep($delay);
                        }
                        
                        $resultat = envoyerMessageWhatsAppIndividual(
                            $phoneNumber, 
                            $message, 
                            $fichierDataPourEnvoi, 
                            $whatsappSession, 
                            $apiUrl, 
                            $apiKey
                        );
                        
                        $nom = isset($contactsInfo[$phoneNumber]) 
                            ? $contactsInfo[$phoneNumber]['prenom'] . ' ' . $contactsInfo[$phoneNumber]['nom'] 
                            : $phoneNumber;
                        
                        $destinatairesNoms[] = $nom . ' (' . $phoneNumber . ')';
                        
                        if ($resultat['success']) {
                            $succes++;
                            $resultatsDetails[] = [
                                'contact' => $nom,
                                'phone' => $phoneNumber,
                                'status' => 'success',
                                'message' => '✅ Envoyé avec succès'
                            ];
                        } else {
                            $echecs++;
                            $resultatsDetails[] = [
                                'contact' => $nom,
                                'phone' => $phoneNumber,
                                'status' => 'error',
                                'message' => '❌ Échec: ' . ($resultat['error'] ?? 'Erreur inconnue')
                            ];
                        }
                    }
                    
                    // ============================================
                    // ENREGISTREMENT DANS L'HISTORIQUE
                    // ============================================
                    $titre = "WhatsApp - " . date('d/m/Y H:i');
                    if (!empty($message)) {
                        $titre = "WhatsApp: " . (strlen($message) > 40 ? substr($message, 0, 40) . '...' : $message);
                    } elseif ($hasAudio) {
                        $titre = "WhatsApp: Message vocal";
                    } elseif ($hasFile) {
                        $titre = "WhatsApp: Fichier envoyé";
                    }
                    
                    // 🔥 CORRECTION : Statut correct pour la table campagne
                    // envoye si tout est réussi, echoue si tout a échoué
                    if ($echecs > 0 && $succes > 0) {
                        $statutGlobal = 'partiel';
                    } elseif ($echecs > 0 && $succes == 0) {
                        $statutGlobal = 'echoue';
                    } else {
                        $statutGlobal = 'envoye';
                    }
                    
                    $campagneData = [
                        'id_compte' => $idCompte,
                        'id_campagne_config' => $campagneConfigId,
                        'type_campagne' => 'whatsapp',
                        'titre' => $titre,
                        'message' => $message,
                        'destinataires' => json_encode($destinatairesNoms),
                        'nb_destinataires' => $total,
                        'nb_envoyes' => $total,
                        'nb_succes' => $succes,
                        'nb_erreurs' => $echecs,
                        'appareil_utilise' => $whatsappSession,
                        'statut' => $statutGlobal,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    if ($echecs > 0) {
                        $campagneData['erreur'] = json_encode($resultatsDetails);
                    }
                    
                    try {
                        $db->insert('campagne', $campagneData);
                    } catch (Exception $e) {
                        error_log("Erreur insertion historique WhatsApp: " . $e->getMessage());
                    }
                    
                    // Mise à jour du statut de la campagne config
                    if ($succes > 0) {
                        $db->update('campagne_config', [
                            'statut' => 'envoyee',
                            'sent_at' => date('Y-m-d H:i:s')
                        ], ['id_campagne_config' => $campagneConfigId]);
                    }
                    
                    // ============================================
                    // MESSAGE DE RÉSULTAT
                    // ============================================
                    $successMsg = "📊 Envoi terminé :<br>";
                    $successMsg .= "✅ <strong>$succes</strong> message(s) envoyé(s) avec succès<br>";
                    if ($echecs > 0) {
                        $successMsg .= "❌ <strong>$echecs</strong> échec(s)<br>";
                        
                        $failedDetails = array_filter($resultatsDetails, function($d) {
                            return $d['status'] === 'error';
                        });
                        
                        if (count($failedDetails) > 0) {
                            $successMsg .= "<br><details><summary>📋 Voir les détails des échecs (" . count($failedDetails) . ")</summary>";
                            $successMsg .= "<div style='font-size:13px; margin-top:8px; max-height:300px; overflow-y:auto;'>";
                            foreach ($failedDetails as $detail) {
                                $successMsg .= "<div style='color:#ef4444; padding:6px 0; border-bottom:1px solid #f3f4f6;'>";
                                $successMsg .= "❌ <strong>" . htmlspecialchars($detail['contact']) . "</strong> - " . htmlspecialchars($detail['message']);
                                $successMsg .= "</div>";
                            }
                            $successMsg .= "</div></details>";
                        }
                    }
                    
                    if (!empty($contactsBlacklistes)) {
                        $successMsg .= "<br>⚠️ " . count($contactsBlacklistes) . " contact(s) blacklistés exclus";
                    }
                    
                    $success = $successMsg;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Envoyer WhatsApp - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            color: #1f2937;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        .select2-dropdown {
            border-radius: 0.5rem;
            border-color: #d1d5db;
        }
        .select2-search__field {
            border-radius: 0.5rem !important;
            border: 1px solid #d1d5db !important;
            padding: 6px !important;
        }
        .select2-results__option--highlighted {
            background-color: #22c55e !important;
        }
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.3s ease-out;
        }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast-notification .toast-content {
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
            font-weight: 500;
        }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        .type-envoi-option {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .type-envoi-option:hover {
            transform: translateY(-2px);
        }
        
        .recording-active {
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .btn-loading {
            opacity: 0.7;
            cursor: not-allowed;
            position: relative;
            pointer-events: none;
        }
        
        .btn-loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9998;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .loading-overlay.active {
            visibility: visible;
            opacity: 1;
        }
        
        .loading-spinner {
            background: white;
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            text-align: center;
            min-width: 350px;
        }
        
        .loading-spinner i {
            font-size: 48px;
            color: #22c55e;
            animation: spin 1s linear infinite;
            margin-bottom: 10px;
        }
        
        .loading-spinner p {
            margin: 0;
            color: #333;
            font-size: 14px;
        }
        
        .progress-bar-container {
            width: 100%;
            margin-top: 15px;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: #22c55e;
            width: 0%;
            transition: width 0.3s ease;
        }
        
        .resultats-detail {
            max-height: 300px;
            overflow-y: auto;
            text-align: left;
            margin-top: 15px;
            font-size: 12px;
        }
        
        .resultat-succes {
            color: #10b981;
            padding: 5px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .resultat-erreur {
            color: #ef4444;
            padding: 5px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .campagne-info {
            background: #f3e8ff;
            border: 1px solid #d8b4fe;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .campagne-info-title {
            font-size: 14px;
            font-weight: 600;
            color: #6b21a5;
            margin-bottom: 8px;
        }
        
        .delay-input {
            width: 120px;
            text-align: center;
        }
        
        #fileUploadArea, #audioRecordArea {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        #fileUploadArea.drag-over {
            border-color: #22c55e;
            background-color: #f0fdf4;
        }
        
        .blacklist-warning {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        
        .resultat-success {
            color: #10b981;
        }
        .resultat-error {
            color: #ef4444;
        }
        details {
            cursor: pointer;
        }
        details summary {
            padding: 8px 0;
            font-weight: 500;
        }
        
        .mic-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            display: none;
        }
        .mic-error .icon {
            color: #dc2626;
            font-size: 20px;
            margin-right: 10px;
        }
        .mic-error .title {
            font-weight: 600;
            color: #991b1b;
        }
        .mic-error .description {
            color: #7f1d1d;
            font-size: 13px;
            margin-top: 4px;
        }
        .mic-error .solutions {
            margin-top: 8px;
            padding-left: 20px;
            color: #7f1d1d;
            font-size: 13px;
        }
        .mic-error .solutions li {
            margin-bottom: 4px;
        }
    </style>
</head>
<body>

<!-- Overlay de chargement global -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner">
        <i class="fab fa-whatsapp"></i>
        <p id="loadingMessage">Envoi du message en cours...</p>
        <div class="progress-bar-container">
            <div class="progress-bar">
                <div class="progress-bar-fill" id="progressBarFill"></div>
            </div>
        </div>
        <div id="resultatsDetail" class="resultats-detail" style="display: none;"></div>
    </div>
</div>

<div class="max-w-3xl mx-auto py-8 px-4">
    <div class="flex items-center mb-6">
        <a href="javascript:history.back()" class="text-blue-600 hover:text-blue-800 mr-4">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <div class="bg-green-100 p-3 rounded-full mr-4">
            <i class="fab fa-whatsapp text-green-600 text-xl"></i>
        </div>
        <h1 class="text-2xl font-bold text-gray-800">Envoyer un message WhatsApp</h1>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <div class="campagne-info">
            <div class="campagne-info-title">
                <i class="fas fa-bullhorn mr-2"></i>
                Campagne : <?= htmlspecialchars($campagne['nom_campagne']) ?>
            </div>
        </div>
        
        <div class="bg-green-50 p-3 rounded mb-4">
            <p class="text-sm text-green-700">
                <i class="fas fa-check-circle mr-1"></i> Session active: <strong><?= htmlspecialchars($whatsappSession) ?></strong>
            </p>
        </div>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded"><?= nl2br($success) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded"><?= $error ?></div>
        <?php endif; ?>
        
        <!-- Avertissement blacklist -->
        <?php if (count($contacts) < count($tousContacts)): ?>
            <div class="blacklist-warning">
                <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>
                <span class="text-sm text-red-700">
                    <?= (count($tousContacts) - count($contacts)) ?> contact(s) blacklistés pour WhatsApp ne sont pas affichés.
                </span>
            </div>
        <?php endif; ?>
        
        <?php if (empty($contacts)): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4 rounded">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Aucun contact disponible. 
                <a href="index.php?page=contacts/ajouter" class="underline font-semibold">Ajoutez d'abord des contacts</a>
            </div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data" id="whatsappForm">
                <input type="hidden" name="campagne_config_id" value="<?= $campagneConfigId ?>">
                
                <!-- Type d'envoi -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-envelope mr-1"></i> Type d'envoi *
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <div id="typeSimple" 
                             class="type-envoi-option border-2 rounded-lg p-3 text-center cursor-pointer transition <?= (!isset($_POST['type_envoi']) || $_POST['type_envoi'] == 'simple') ? 'border-green-500 bg-green-50' : 'border-gray-200 hover:border-green-300' ?>">
                            <i class="fas fa-user text-green-600 text-xl mb-1"></i>
                            <p class="font-medium text-gray-800">Envoi unique</p>
                            <p class="text-xs text-gray-500">À un seul destinataire</p>
                        </div>
                        <div id="typeMultiple" 
                             class="type-envoi-option border-2 rounded-lg p-3 text-center cursor-pointer transition <?= (isset($_POST['type_envoi']) && $_POST['type_envoi'] == 'multiple') ? 'border-green-500 bg-green-50' : 'border-gray-200 hover:border-green-300' ?>">
                            <i class="fas fa-list text-green-600 text-xl mb-1"></i>
                            <p class="font-medium text-gray-800">Envoi par liste</p>
                            <p class="text-xs text-gray-500">À tous les contacts d'une liste</p>
                        </div>
                    </div>
                    <input type="hidden" name="type_envoi" id="type_envoi" value="<?= isset($_POST['type_envoi']) ? $_POST['type_envoi'] : 'simple' ?>">
                </div>
                
                <!-- Zone Envoi unique -->
                <div id="simpleZone" class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fab fa-whatsapp mr-1 text-green-600"></i> Destinataire *
                    </label>
                    <select name="chat_id" id="contact_search" class="w-full" style="width: 100%;">
                        <option value="">Tapez le nom, prénom ou numéro...</option>
                        <?php foreach ($contacts as $contact): 
                            $telephone = $contact['telephone'] ?? '';
                            $whatsappNumber = !empty($telephone) ? formatWhatsAppNumber($telephone) : '';
                        ?>
                            <option value="<?= htmlspecialchars($whatsappNumber . '@c.us') ?>" <?= empty($whatsappNumber) ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($contact['prenom'] . ' ' . $contact['nom']) ?>
                                <?php if (!empty($telephone)): ?>
                                    (<?= htmlspecialchars($telephone) ?>)
                                <?php else: ?>
                                    (⚠️ Pas de numéro)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-search mr-1"></i> Tapez pour rechercher par nom, prénom ou numéro
                    </p>
                </div>
                
                <!-- Zone Envoi multiple -->
                <div id="multipleZone" class="mb-4" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-list mr-1"></i> Sélectionner une liste *
                    </label>
                    <select name="liste_id" id="liste_id" class="w-full" style="width: 100%;">
                        <option value="">-- Sélectionnez une liste --</option>
                        <?php foreach ($listes as $liste): ?>
                            <option value="<?= $liste['id_liste'] ?>">
                                <?= htmlspecialchars($liste['nom_liste']) ?> (<?= $liste['nombre_contacts'] ?> contact<?= $liste['nombre_contacts'] > 1 ? 's' : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-clock mr-1"></i> Les messages seront envoyés avec un délai aléatoire configuré ci-dessous
                        <br><i class="fas fa-ban mr-1 text-red-500"></i> Les contacts blacklistés pour WhatsApp seront automatiquement exclus
                    </p>
                </div>
                
                <!-- Message -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Message <span id="messageRequired" class="text-gray-400 text-xs">(optionnel si fichier/audio)</span></label>
                    <textarea name="message" id="message" rows="4" 
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500"
                              placeholder="Votre message..."></textarea>
                    <p class="text-xs text-gray-500 mt-1" id="charCount">0 caractères</p>
                </div>
                
                <!-- Options de pièce jointe -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Pièce jointe (optionnel)</label>
                    
                    <div class="flex space-x-2 mb-3">
                        <button type="button" id="uploadFileBtn" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-upload mr-2"></i>Fichier
                        </button>
                        <button type="button" id="recordAudioBtn" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg transition">
                            <i class="fas fa-microphone mr-2"></i>Enregistrer audio
                        </button>
                    </div>
                    
                    <div id="fileUploadArea" class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hidden">
                        <input type="file" name="fichier" id="fichier" class="hidden">
                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                        <p class="text-gray-500">Cliquez ou glissez un fichier ici</p>
                        <p class="text-xs text-gray-400 mt-1">Images, vidéos, audio, PDF (Max 10 Mo)</p>
                        <div id="fileInfo" class="mt-3 text-sm hidden">
                            <i class="fas fa-file mr-1"></i> <span id="fileName"></span>
                            <button type="button" id="removeFileBtn" class="text-red-500 ml-2 hover:text-red-700">Supprimer</button>
                        </div>
                    </div>
                    
                    <div id="audioRecordArea" class="border-2 border-gray-300 rounded-lg p-6 text-center hidden">
                        <div class="mb-3">
                            <div id="recordingTimer" class="text-2xl font-mono text-gray-700 mb-2">00:00</div>
                        </div>
                        <div class="flex justify-center space-x-3">
                            <button type="button" id="startRecordBtn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition">
                                <i class="fas fa-circle mr-2"></i>Commencer
                            </button>
                            <button type="button" id="stopRecordBtn" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition hidden">
                                <i class="fas fa-stop mr-2"></i>Arrêter
                            </button>
                        </div>
                        
                        <!-- Message d'erreur microphone -->
                        <div id="micError" class="mic-error">
                            <div class="flex items-start">
                                <i class="fas fa-microphone-slash icon"></i>
                                <div class="text-left">
                                    <div class="title">⚠️ Impossible d'accéder au microphone</div>
                                    <div class="description">
                                        Pour enregistrer un message vocal, vous devez autoriser l'accès au microphone.
                                    </div>
                                    <ul class="solutions">
                                        <li>🔒 Utilisez une connexion <strong>HTTPS</strong> (ou <strong>localhost</strong> en développement)</li>
                                        <li>🌐 Vérifiez les autorisations du navigateur (cliquez sur le cadenas dans la barre d'adresse)</li>
                                        <li>🎤 Assurez-vous qu'aucun autre logiciel n'utilise le microphone</li>
                                        <li>🔄 Rafraîchissez la page et réessayez</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div id="audioPreview" class="mt-3 hidden">
                            <audio controls class="w-full"></audio>
                            <button type="button" id="removeAudioBtn" class="text-red-500 text-sm mt-2">Supprimer l'audio</button>
                        </div>
                        <input type="hidden" name="audio_data" id="audioData">
                    </div>
                </div>
                
                <!-- Délais -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-hourglass-half mr-1"></i> Délai entre les messages (secondes)
                    </label>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-600">Min :</span>
                            <input type="number" name="min_delay" id="min_delay" value="<?= isset($_POST['min_delay']) ? $_POST['min_delay'] : 60 ?>" 
                                   class="delay-input border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500" min="1" max="3600" step="1">
                            <span class="text-sm text-gray-500">sec</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-600">Max :</span>
                            <input type="number" name="max_delay" id="max_delay" value="<?= isset($_POST['max_delay']) ? $_POST['max_delay'] : 180 ?>" 
                                   class="delay-input border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-green-500" min="1" max="3600" step="1">
                            <span class="text-sm text-gray-500">sec</span>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end mt-6">
                    <button type="submit" id="submitBtn" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition">
                        <i class="fab fa-whatsapp mr-2"></i>Envoyer
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>

<script>
$(document).ready(function() {
    $('#contact_search').select2({
        placeholder: "Tapez le nom, prénom ou numéro...",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
    
    $('#liste_id').select2({
        placeholder: "-- Sélectionnez une liste --",
        allowClear: true,
        width: '100%',
        language: 'fr'
    });
});

// Gestion du type d'envoi
const typeSimple = document.getElementById('typeSimple');
const typeMultiple = document.getElementById('typeMultiple');
const simpleZone = document.getElementById('simpleZone');
const multipleZone = document.getElementById('multipleZone');
const typeEnvoiInput = document.getElementById('type_envoi');

function setTypeEnvoi(type) {
    if (type === 'simple') {
        typeSimple.classList.add('border-green-500', 'bg-green-50');
        typeSimple.classList.remove('border-gray-200');
        typeMultiple.classList.remove('border-green-500', 'bg-green-50');
        typeMultiple.classList.add('border-gray-200');
        simpleZone.style.display = 'block';
        multipleZone.style.display = 'none';
        typeEnvoiInput.value = 'simple';
        
        $('#liste_id').prop('disabled', true);
        $('#liste_id').next().css('opacity', '0.5');
        $('#contact_search').prop('disabled', false);
        $('#contact_search').next().css('opacity', '1');
    } else {
        typeMultiple.classList.add('border-green-500', 'bg-green-50');
        typeMultiple.classList.remove('border-gray-200');
        typeSimple.classList.remove('border-green-500', 'bg-green-50');
        typeSimple.classList.add('border-gray-200');
        simpleZone.style.display = 'none';
        multipleZone.style.display = 'block';
        typeEnvoiInput.value = 'multiple';
        
        $('#contact_search').prop('disabled', true);
        $('#contact_search').next().css('opacity', '0.5');
        $('#liste_id').prop('disabled', false);
        $('#liste_id').next().css('opacity', '1');
    }
}

typeSimple.addEventListener('click', () => setTypeEnvoi('simple'));
typeMultiple.addEventListener('click', () => setTypeEnvoi('multiple'));

setTypeEnvoi(typeEnvoiInput.value);

function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<div class="toast-content">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ============================================
// GESTION DU FICHIER
// ============================================
const uploadFileBtn = document.getElementById('uploadFileBtn');
const recordAudioBtn = document.getElementById('recordAudioBtn');
const fileUploadArea = document.getElementById('fileUploadArea');
const audioRecordArea = document.getElementById('audioRecordArea');
const fichierInput = document.getElementById('fichier');
const fileInfo = document.getElementById('fileInfo');
const fileNameSpan = document.getElementById('fileName');
const removeFileBtn = document.getElementById('removeFileBtn');
const messageRequired = document.getElementById('messageRequired');
const micError = document.getElementById('micError');

uploadFileBtn.addEventListener('click', () => {
    fileUploadArea.classList.remove('hidden');
    audioRecordArea.classList.add('hidden');
    resetRecording();
});

recordAudioBtn.addEventListener('click', () => {
    audioRecordArea.classList.remove('hidden');
    fileUploadArea.classList.add('hidden');
    resetFileUpload();
    // Vérifier si le navigateur supporte le microphone
    checkMicrophoneSupport();
});

async function checkMicrophoneSupport() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        micError.style.display = 'block';
        return;
    }
    
    // Vérifier si nous sommes en HTTPS ou localhost
    const isSecure = window.location.protocol === 'https:' || 
                     window.location.hostname === 'localhost' || 
                     window.location.hostname === '127.0.0.1';
    
    if (!isSecure) {
        micError.style.display = 'block';
        micError.querySelector('.description').innerHTML = 
            '⚠️ Pour des raisons de sécurité, les navigateurs exigent une connexion <strong>HTTPS</strong> pour accéder au microphone.<br>' +
            'Utilisez un serveur sécurisé ou testez en localhost.';
        return;
    }
    
    // Tester l'accès au microphone
    try {
        const testStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        testStream.getTracks().forEach(track => track.stop());
        micError.style.display = 'none';
    } catch (err) {
        micError.style.display = 'block';
        console.error('Erreur microphone:', err);
    }
}

function handleFile(file) {
    const sizeMB = (file.size / 1024 / 1024).toFixed(2);
    
    if (file.size > 10 * 1024 * 1024) {
        showToast('Le fichier est trop volumineux. Maximum 10 Mo.', 'error');
        resetFileUpload();
        return;
    }
    
    fileNameSpan.textContent = `${file.name} (${sizeMB} Mo)`;
    fileInfo.classList.remove('hidden');
    messageRequired.innerHTML = '<span class="text-green-600">(optionnel)</span>';
    
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    fichierInput.files = dataTransfer.files;
}

fileUploadArea.addEventListener('click', (e) => {
    if (e.target !== removeFileBtn && !removeFileBtn.contains(e.target)) {
        fichierInput.click();
    }
});

fichierInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        handleFile(e.target.files[0]);
    }
});

// Drag & drop
fileUploadArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    fileUploadArea.classList.add('drag-over');
});

fileUploadArea.addEventListener('dragleave', (e) => {
    e.preventDefault();
    fileUploadArea.classList.remove('drag-over');
});

fileUploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    fileUploadArea.classList.remove('drag-over');
    if (e.dataTransfer.files.length > 0) {
        handleFile(e.dataTransfer.files[0]);
    }
});

removeFileBtn.addEventListener('click', () => {
    resetFileUpload();
});

function resetFileUpload() {
    fichierInput.value = '';
    fileInfo.classList.add('hidden');
    if (!audioDataInput.value) {
        messageRequired.innerHTML = '<span class="text-gray-400 text-xs">(optionnel si fichier/audio)</span>';
    }
}

// ============================================
// ENREGISTREMENT AUDIO
// ============================================
let mediaRecorder = null;
let audioChunks = [];
let recordingTimer = null;
let recordingSeconds = 0;
let stream = null;

const startRecordBtn = document.getElementById('startRecordBtn');
const stopRecordBtn = document.getElementById('stopRecordBtn');
const recordingTimerSpan = document.getElementById('recordingTimer');
const audioPreview = document.getElementById('audioPreview');
const audioDataInput = document.getElementById('audioData');
const removeAudioBtn = document.getElementById('removeAudioBtn');

// Vérifier le support microphone au chargement
document.addEventListener('DOMContentLoaded', checkMicrophoneSupport);

async function startRecording() {
    // Vérifier à nouveau avant de démarrer
    const isSecure = window.location.protocol === 'https:' || 
                     window.location.hostname === 'localhost' || 
                     window.location.hostname === '127.0.0.1';
    
    if (!isSecure) {
        micError.style.display = 'block';
        showToast('Utilisez HTTPS ou localhost pour accéder au microphone', 'error');
        return;
    }
    
    try {
        stream = await navigator.mediaDevices.getUserMedia({ 
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            } 
        });
        
        micError.style.display = 'none';
        
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];
        
        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };
        
        mediaRecorder.onstop = () => {
            if (audioChunks.length === 0) {
                showToast('Aucun audio enregistré', 'error');
                return;
            }
            
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const audioUrl = URL.createObjectURL(audioBlob);
            const audioElement = audioPreview.querySelector('audio');
            audioElement.src = audioUrl;
            
            const reader = new FileReader();
            reader.onloadend = () => {
                audioDataInput.value = reader.result;
                messageRequired.innerHTML = '<span class="text-green-600">(optionnel)</span>';
            };
            reader.readAsDataURL(audioBlob);
            
            audioPreview.classList.remove('hidden');
            startRecordBtn.classList.remove('hidden');
            stopRecordBtn.classList.add('hidden');
            startRecordBtn.classList.remove('recording-active');
        };
        
        mediaRecorder.start();
        startRecordBtn.classList.add('hidden');
        stopRecordBtn.classList.remove('hidden');
        startRecordBtn.classList.add('recording-active');
        
        recordingSeconds = 0;
        updateTimerDisplay();
        recordingTimer = setInterval(() => {
            recordingSeconds++;
            updateTimerDisplay();
        }, 1000);
        
    } catch (err) {
        micError.style.display = 'block';
        console.error('Erreur microphone:', err);
        showToast('Impossible d\'accéder au microphone: ' + err.message, 'error');
    }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        clearInterval(recordingTimer);
    }
}

function updateTimerDisplay() {
    const minutes = Math.floor(recordingSeconds / 60);
    const seconds = recordingSeconds % 60;
    recordingTimerSpan.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

function resetRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
    clearInterval(recordingTimer);
    audioChunks = [];
    recordingSeconds = 0;
    updateTimerDisplay();
    audioPreview.classList.add('hidden');
    audioDataInput.value = '';
    startRecordBtn.classList.remove('hidden');
    stopRecordBtn.classList.add('hidden');
    startRecordBtn.classList.remove('recording-active');
    
    if (!fichierInput.files.length && !document.getElementById('message').value.trim()) {
        messageRequired.innerHTML = '<span class="text-gray-400 text-xs">(optionnel si fichier/audio)</span>';
    }
}

startRecordBtn.addEventListener('click', startRecording);
stopRecordBtn.addEventListener('click', stopRecording);

removeAudioBtn.addEventListener('click', () => {
    resetRecording();
    if (!fichierInput.files.length && !document.getElementById('message').value.trim()) {
        messageRequired.innerHTML = '<span class="text-gray-400 text-xs">(optionnel si fichier/audio)</span>';
    }
});

// Compteur de caractères
const messageTextarea = document.getElementById('message');
if (messageTextarea) {
    messageTextarea.addEventListener('input', function() {
        const countSpan = document.getElementById('charCount');
        if (countSpan) countSpan.textContent = this.value.length + ' caractères';
        if (this.value.trim() === '') {
            if (!fichierInput.files.length && !audioDataInput.value) {
                messageRequired.innerHTML = '<span class="text-gray-400 text-xs">(optionnel si fichier/audio)</span>';
            }
        } else {
            messageRequired.innerHTML = '<span class="text-green-600">(optionnel)</span>';
        }
    });
}

// Variables pour l'overlay de chargement
const submitBtn = document.getElementById('submitBtn');
const loadingOverlay = document.getElementById('loadingOverlay');
const whatsappForm = document.getElementById('whatsappForm');
const progressBarFill = document.getElementById('progressBarFill');
const loadingMessage = document.getElementById('loadingMessage');
const resultatsDetail = document.getElementById('resultatsDetail');

function setLoading(loading) {
    if (loading) {
        submitBtn.classList.add('btn-loading');
        submitBtn.disabled = true;
        const originalContent = submitBtn.innerHTML;
        submitBtn.setAttribute('data-original-content', originalContent);
        submitBtn.innerHTML = '<i class="fab fa-whatsapp fa-spin mr-2"></i>Envoi en cours...';
        loadingOverlay.classList.add('active');
        loadingMessage.innerHTML = 'Envoi en cours...<br><span class="text-sm text-gray-500">L\'API gère les délais automatiquement</span>';
    } else {
        submitBtn.classList.remove('btn-loading');
        submitBtn.disabled = false;
        const originalContent = submitBtn.getAttribute('data-original-content');
        if (originalContent) {
            submitBtn.innerHTML = originalContent;
        }
        loadingOverlay.classList.remove('active');
    }
}

// Validation et soumission
whatsappForm.addEventListener('submit', function(e) {
    const type_envoi = document.getElementById('type_envoi').value;
    const message = messageTextarea?.value.trim() || '';
    const hasFile = fichierInput.files.length > 0;
    const hasAudio = audioDataInput.value !== '';
    let hasRecipients = false;
    
    if (type_envoi === 'simple') {
        const chatId = $('#contact_search').val();
        hasRecipients = chatId && chatId !== '';
    } else {
        const liste = $('#liste_id').val();
        hasRecipients = liste && liste !== '';
    }
    
    if (!hasRecipients) {
        e.preventDefault();
        showToast('Veuillez sélectionner un destinataire ou une liste', 'error');
        return false;
    }
    
    if (!message && !hasFile && !hasAudio) {
        e.preventDefault();
        showToast('Veuillez saisir un message ou joindre un fichier/audio', 'error');
        return false;
    }
    
    const minDelay = parseInt(document.getElementById('min_delay').value);
    const maxDelay = parseInt(document.getElementById('max_delay').value);
    
    if (minDelay < 1 || maxDelay < 1) {
        e.preventDefault();
        showToast('Les délais doivent être supérieurs à 0', 'error');
        return false;
    }
    
    if (minDelay > maxDelay) {
        e.preventDefault();
        showToast('Le délai minimum ne peut pas être supérieur au délai maximum', 'error');
        return false;
    }
    
    setLoading(true);
});

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

</body>
</html>