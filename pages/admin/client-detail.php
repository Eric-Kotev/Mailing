<?php
// Vérification que l'utilisateur est admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

global $db;

// Récupérer l'ID du client
$id = isset($_GET['id']) ? $_GET['id'] : '';

if (empty($id)) {
    header('Location: index.php?page=admin/clients');
    exit;
}

$id = (string)$id;

// Récupérer les données du client (dans la table compte)
$clientData = $db->select('compte', ['id_compte' => $id, 'role' => 'client']);
if (empty($clientData)) {
    header('Location: index.php?page=admin/clients');
    exit;
}
$client = $clientData[0];

// ============================================
// GESTION DES OPÉRATEURS ASSOCIÉS
// ============================================

// --- Associer un opérateur (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_associate_provider'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $providerId = intval($_POST['id_provider'] ?? 0);
        $estActif = isset($_POST['est_actif']) && $_POST['est_actif'] === 'true';
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if ($providerId <= 0) {
            throw new Exception('ID opérateur invalide');
        }
        
        // Vérifier que l'opérateur existe
        $provider = $db->select('provider', ['id_provider' => $providerId]);
        if (empty($provider)) {
            throw new Exception('Opérateur non trouvé');
        }
        
        // Vérifier que l'association n'existe pas déjà
        $existing = $db->select('client_provider', [
            'id_compte' => $clientId,
            'id_provider' => $providerId
        ]);
        
        if (!empty($existing)) {
            throw new Exception('Cet opérateur est déjà associé à ce client');
        }
        
        // Créer l'association
        $data = [
            'id_compte' => $clientId,
            'id_provider' => $providerId,
            'est_actif' => $estActif,
            'date_association' => date('Y-m-d H:i:s')
        ];
        
        $result = $db->insert('client_provider', $data);
        
        if ($result) {
            // Récupérer les infos de l'opérateur pour le retour
            $providerInfo = $provider[0];
            $canal = $db->select('type_message', ['id_type_message' => $providerInfo['id_type_message']]);
            $canalName = !empty($canal) ? $canal[0]['libelle_type'] : 'Inconnu';
            
            echo json_encode([
                'success' => true,
                'message' => 'Opérateur associé avec succès',
                'association' => [
                    'id_client_provider' => $result,
                    'id_provider' => $providerInfo['id_provider'],
                    'nom_providers' => $providerInfo['nom_providers'],
                    'description' => $providerInfo['description'],
                    'canal' => $canalName,
                    'est_actif' => $estActif,
                    'tarif' => $providerInfo['tarif'],
                    'date_association' => date('d/m/Y H:i')
                ]
            ]);
        } else {
            throw new Exception('Erreur lors de l\'association');
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Dissocier un opérateur (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_detach_provider'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $idClientProvider = intval($_POST['id_client_provider'] ?? 0);
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if ($idClientProvider <= 0) {
            throw new Exception('ID association invalide');
        }
        
        // Vérifier que l'association existe et appartient au client
        $existing = $db->select('client_provider', [
            'id_client_provider' => $idClientProvider,
            'id_compte' => $clientId
        ]);
        
        if (empty($existing)) {
            throw new Exception('Association non trouvée');
        }
        
        // Supprimer l'association
        $result = $db->delete('client_provider', $idClientProvider, 'id_client_provider');
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Opérateur dissocié avec succès'
            ]);
        } else {
            throw new Exception('Erreur lors de la dissociation');
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Changer le statut d'une association (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_toggle_provider_status'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $idClientProvider = intval($_POST['id_client_provider'] ?? 0);
        $nouveauStatut = $_POST['est_actif'] === 'true';
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if ($idClientProvider <= 0) {
            throw new Exception('ID association invalide');
        }
        
        // Vérifier que l'association existe et appartient au client
        $existing = $db->select('client_provider', [
            'id_client_provider' => $idClientProvider,
            'id_compte' => $clientId
        ]);
        
        if (empty($existing)) {
            throw new Exception('Association non trouvée');
        }
        
        // Mettre à jour le statut
        $result = $db->update('client_provider', 
            ['est_actif' => $nouveauStatut],
            ['id_client_provider' => $idClientProvider]
        );
        
        if ($result !== false) {
            echo json_encode([
                'success' => true,
                'message' => 'Statut mis à jour avec succès',
                'est_actif' => $nouveauStatut
            ]);
        } else {
            throw new Exception('Erreur lors de la mise à jour du statut');
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// GESTION DES SESSIONS OPÉRATEUR
// ============================================

// --- Récupérer les sessions WhatsApp du client (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_get_whatsapp_sessions'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        
        // Récupérer les sessions WhatsApp du client
        $sessions = $db->select('whatsapp_sessions', ['id_compte' => $clientId], '*', 'created_at.desc');
        
        $sessionList = [];
        foreach ($sessions as $session) {
            $sessionList[] = [
                'id_session' => $session['id_session'],
                'nom_session' => $session['nom_session'],
                'est_active' => $session['est_active'],
                'created_at' => date('d/m/Y H:i', strtotime($session['created_at']))
            ];
        }
        
        echo json_encode([
            'success' => true,
            'sessions' => $sessionList
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Supprimer une session WhatsApp (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_whatsapp_session'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $sessionId = trim($_POST['session_id'] ?? '');
        $sessionName = trim($_POST['session_name'] ?? '');
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (empty($sessionId)) {
            throw new Exception('ID de session requis');
        }
        
        // Vérifier que la session existe et appartient au client
        $session = $db->select('whatsapp_sessions', [
            'id_session' => $sessionId,
            'id_compte' => $clientId
        ]);
        
        if (empty($session)) {
            throw new Exception('Session non trouvée');
        }
        
        $sessionName = $session[0]['nom_session'];
        $isActive = $session[0]['est_active'];
        
        // Récupérer toutes les sessions du client
        $allSessions = $db->select('whatsapp_sessions', ['id_compte' => $clientId]);
        
        // Si la session à supprimer est active, activer une autre session (s'il y en a)
        if ($isActive && count($allSessions) > 1) {
            foreach ($allSessions as $s) {
                if ($s['id_session'] !== $sessionId) {
                    $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $s['id_session']]);
                    break;
                }
            }
        }
        
        // Supprimer la session de la base de données
        $result = $db->delete('whatsapp_sessions', $sessionId, 'id_session');
        
        if ($result) {
            // Appel à l'API Waha pour supprimer la session
            try {
                $wahaUrl = 'http://164.68.103.147:8081/api/controller.php/sessions/' . urlencode($sessionName);
                $wahaKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
                
                $ch = curl_init($wahaUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'X-Controller-Key: ' . $wahaKey,
                    'Accept: application/json'
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                
                $wahaResponse = curl_exec($ch);
                $wahaHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    error_log("WAHA DELETE ERROR: " . $curlError);
                }
            } catch (Exception $e) {
                error_log("WAHA DELETE EXCEPTION: " . $e->getMessage());
                // On continue même si Waha échoue
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Session supprimée avec succès'
            ]);
        } else {
            throw new Exception('Erreur lors de la suppression');
        }
        
    } catch (Exception $e) {
        error_log("ERREUR delete_whatsapp_session: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Récupérer les appareils SMS depuis l'API externe (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_fetch_sms_devices'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $api_username = trim($_POST['api_username'] ?? '');
        $api_password = trim($_POST['api_password'] ?? '');
        
        if (empty($api_username) || empty($api_password)) {
            throw new Exception('Identifiants requis');
        }
        
        // Appel à l'API SMS Gateway
        $smsApiUrl = 'http://164.68.103.147:8085/devices.php';
        $postData = json_encode([
            'api_username' => $api_username,
            'api_password' => $api_password
        ]);
        
        $ch = curl_init($smsApiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('Erreur de connexion à l\'API SMS: ' . $curlError);
        }
        
        if (empty($response)) {
            throw new Exception('La réponse de l\'API est vide');
        }
        
        $data = json_decode($response, true);
        
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Erreur de décodage JSON: ' . json_last_error_msg());
        }
        
        if ($httpCode === 200 && isset($data['status']) && $data['status'] === 'ok') {
            $devices = $data['devices'] ?? [];
            if (!is_array($devices)) {
                $devices = [];
            }
            
            echo json_encode([
                'success' => true,
                'devices' => $devices
            ]);
        } else {
            $errorMsg = $data['message'] ?? 'Erreur inconnue';
            throw new Exception('Erreur API: ' . $errorMsg);
        }
        
    } catch (Exception $e) {
        error_log("SMS API Exception: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Récupérer les appareils SMS du client (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_get_sms_appareils'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        
        $appareils = $db->select('sms_appareils', ['id_compte' => $clientId], '*', 'est_actif DESC, created_at DESC');
        
        $appareilList = [];
        foreach ($appareils as $appareil) {
            $appareilList[] = [
                'id_appareil' => $appareil['id_appareil'],
                'device_id' => $appareil['device_id'],
                'device_name' => $appareil['device_name'] ?: 'Appareil',
                'est_actif' => $appareil['est_actif'],
                'api_username' => $appareil['api_username'] ?? '',
                'api_password' => $appareil['api_password'] ?? '',
                'created_at' => date('d/m/Y H:i', strtotime($appareil['created_at']))
            ];
        }
        
        echo json_encode([
            'success' => true,
            'appareils' => $appareilList
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR get_sms_appareils: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Enregistrer un appareil SMS (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_sms_appareil'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $device_id = trim($_POST['device_id'] ?? '');
        $device_name = trim($_POST['device_name'] ?? '');
        $api_username = trim($_POST['api_username'] ?? '');
        $api_password = trim($_POST['api_password'] ?? '');
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (empty($device_id)) {
            throw new Exception('ID appareil requis');
        }
        
        $existing = $db->select('sms_appareils', [
            'id_compte' => $clientId,
            'device_id' => $device_id
        ]);
        
        if (!empty($existing)) {
            $db->update('sms_appareils', [
                'est_actif' => true,
                'device_name' => $device_name ?: 'Appareil',
                'api_username' => $api_username,
                'api_password' => $api_password,
            ], ['id_appareil' => $existing[0]['id_appareil']]);
            $message = "Appareil existant réactivé";
            $appareilId = $existing[0]['id_appareil'];
        } else {
            $data = [
                'id_compte' => $clientId,
                'device_id' => $device_id,
                'device_name' => $device_name ?: 'Appareil',
                'api_username' => $api_username,
                'api_password' => $api_password,
                'est_actif' => true,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $appareilId = $db->insert('sms_appareils', $data);
            $message = "Nouvel appareil ajouté avec succès";
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'appareil_id' => $appareilId,
            'device_id' => $device_id,
            'device_name' => $device_name ?: 'Appareil'
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR save_sms_appareil: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Mettre à jour le statut d'une session en BDD (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_session_status'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $nom_session = $_POST['nom_session'] ?? '';
        $est_active = isset($_POST['est_active']) && $_POST['est_active'] == '1';
        
        if (empty($nom_session)) {
            throw new Exception('Nom de session requis');
        }
        
        $result = $db->update('whatsapp_sessions', 
            ['est_active' => $est_active],
            ['nom_session' => $nom_session]
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Statut mis à jour'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Créer une session WhatsApp (AJAX) AVEC APPEL À WAHA (IP 192.168.88.116) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_whatsapp_session'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $nom_session = trim($_POST['nom_session'] ?? '');
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (empty($nom_session)) {
            throw new Exception('Nom de session requis');
        }
        
        $existing = $db->select('whatsapp_sessions', [
            'id_compte' => $clientId,
            'nom_session' => $nom_session
        ]);
        
        $wahaUrl = 'http://164.68.103.147:8081/api/controller.php/sessions';
        $wahaKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
        $postData = json_encode(['name' => $nom_session]);
        
        $ch = curl_init($wahaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Controller-Key: ' . $wahaKey,
            'Accept: application/json',
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $wahaResponse = curl_exec($ch);
        $wahaHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $wahaSuccess = false;
        $wahaMessage = '';
        
        if ($curlError) {
            $wahaMessage = 'Erreur de connexion à Waha: ' . $curlError;
            error_log("WAHA CURL ERROR: " . $curlError);
        } else {
            if ($wahaHttpCode === 200 || $wahaHttpCode === 201) {
                $wahaData = json_decode($wahaResponse, true);
                if (isset($wahaData['ok']) && $wahaData['ok'] === true) {
                    $wahaSuccess = true;
                    $wahaMessage = $wahaData['message'] ?? 'Session créée sur Waha';
                } else {
                    $wahaMessage = $wahaData['message'] ?? 'Erreur Waha inconnue';
                }
            } else {
                $wahaMessage = 'Erreur HTTP ' . $wahaHttpCode;
            }
        }
        
        if (!empty($existing)) {
            $db->update('whatsapp_sessions', ['est_active' => false], ['id_session' => $existing[0]['id_session']]);
            $result = [
                'success' => true, 
                'message' => 'Session existante réinitialisée' . ($wahaSuccess ? ' ✅ et créée sur Waha' : ' ⚠️ (Waha: ' . $wahaMessage . ')'), 
                'existing' => true,
                'waha' => $wahaSuccess
            ];
        } else {
            $data = [
                'id_compte' => $clientId,
                'nom_session' => $nom_session,
                'est_active' => false,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $db->insert('whatsapp_sessions', $data);
            $result = [
                'success' => true, 
                'message' => 'Session créée avec succès' . ($wahaSuccess ? ' ✅ et sur Waha' : ' ⚠️ (Waha: ' . $wahaMessage . ')'), 
                'existing' => false,
                'waha' => $wahaSuccess
            ];
        }
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        error_log("CREATE SESSION ERROR: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// NOUVELLES FONCTIONS : RESTART, REQUEST-CODE ET STATUS
// ============================================

// --- Redémarrer une session WhatsApp (AJAX) (IP 192.168.88.116) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_restart_whatsapp_session'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $nom_session = trim($_POST['nom_session'] ?? '');
        
        if (empty($nom_session)) {
            throw new Exception('Nom de session requis');
        }
        
        $wahaUrl = 'http://192.168.88.116:8081/api/controller.php/sessions/' . urlencode($nom_session) . '/restart';
        $wahaKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
        
        $ch = curl_init($wahaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Controller-Key: ' . $wahaKey,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $wahaResponse = curl_exec($ch);
        $wahaHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('Erreur de connexion à Waha: ' . $curlError);
        }
        
        if ($wahaHttpCode === 200) {
            $wahaData = json_decode($wahaResponse, true);
            echo json_encode([
                'success' => true,
                'message' => 'Session redémarrée avec succès',
                'data' => $wahaData
            ]);
        } else {
            throw new Exception('Erreur Waha: HTTP ' . $wahaHttpCode);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Demander un code d'appairage (AJAX) (IP 192.168.88.116) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request_code'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $nom_session = trim($_POST['nom_session'] ?? '');
        $phoneNumber = trim($_POST['phone_number'] ?? '');
        
        if (empty($nom_session)) {
            throw new Exception('Nom de session requis');
        }
        if (empty($phoneNumber)) {
            throw new Exception('Numéro de téléphone requis');
        }
        
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (empty($phoneNumber)) {
            throw new Exception('Numéro de téléphone invalide');
        }
        
        $wahaUrl = 'http://192.168.88.116:8081/api/controller.php/sessions/request-code';
        $wahaKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
        $postData = json_encode([
            'session' => $nom_session,
            'phoneNumber' => $phoneNumber
        ]);
        
        $ch = curl_init($wahaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Controller-Key: ' . $wahaKey,
            'Accept: application/json',
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $wahaResponse = curl_exec($ch);
        $wahaHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('Erreur de connexion à Waha: ' . $curlError);
        }
        
        if ($wahaHttpCode === 200) {
            $wahaData = json_decode($wahaResponse, true);
            echo json_encode([
                'success' => true,
                'message' => 'Code d\'appairage demandé avec succès',
                'code' => $wahaData['code'] ?? 'N/A',
                'data' => $wahaData
            ]);
        } else {
            throw new Exception('Erreur Waha: HTTP ' . $wahaHttpCode);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Vérifier le statut d'une session WhatsApp (AJAX) (IP 164.68.103.147) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_check_session_status'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $nom_session = trim($_POST['nom_session'] ?? '');
        
        if (empty($nom_session)) {
            throw new Exception('Nom de session requis');
        }
        
        $wahaUrl = 'http://164.68.103.147:8081/api/controller.php/sessions/' . urlencode($nom_session) . '/status';
        $wahaKey = '29f51fbe00e64ac5a5e3ce6eefbb79b5';
        
        $ch = curl_init($wahaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Controller-Key: ' . $wahaKey,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $wahaResponse = curl_exec($ch);
        $wahaHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('Erreur de connexion à Waha: ' . $curlError);
        }
        
        if ($wahaHttpCode === 200) {
            $wahaData = json_decode($wahaResponse, true);
            $status = $wahaData['status'] ?? 'UNKNOWN';
            $isConnected = ($status === 'WORKING');
            
            $dbSession = $db->select('whatsapp_sessions', ['nom_session' => $nom_session]);
            $dbConnected = !empty($dbSession) && $dbSession[0]['est_active'] === true;
            
            echo json_encode([
                'success' => true,
                'status' => $status,
                'isConnected' => $isConnected || $dbConnected,
                'data' => $wahaData,
                'db_active' => $dbConnected
            ]);
        } else {
            throw new Exception('Erreur Waha: HTTP ' . $wahaHttpCode);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Supprimer un appareil SMS (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_sms_appareil'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $appareilId = trim($_POST['appareil_id'] ?? '');
        
        if (empty($appareilId)) {
            throw new Exception('ID appareil invalide');
        }
        
        $existing = $db->select('sms_appareils', ['id_appareil' => $appareilId]);
        if (empty($existing)) {
            throw new Exception('Appareil non trouvé');
        }
        
        $result = $db->delete('sms_appareils', $appareilId, 'id_appareil');
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Appareil supprimé avec succès'
            ]);
        } else {
            throw new Exception('Erreur lors de la suppression');
        }
        
    } catch (Exception $e) {
        error_log("ERREUR delete_sms_appareil: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- Activer un appareil SMS (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_activate_sms_appareil'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $appareilId = trim($_POST['appareil_id'] ?? '');
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (empty($appareilId)) {
            throw new Exception('ID appareil invalide');
        }
        
        $appareil = $db->select('sms_appareils', [
            'id_appareil' => $appareilId,
            'id_compte' => $clientId
        ]);
        if (empty($appareil)) {
            throw new Exception('Appareil non trouvé');
        }
        
        $db->update('sms_appareils', ['est_actif' => false], ['id_compte' => $clientId]);
        $db->update('sms_appareils', ['est_actif' => true], ['id_appareil' => $appareilId]);
        
        $appareilInfo = $db->select('sms_appareils', ['id_appareil' => $appareilId]);
        if (!empty($appareilInfo)) {
            $_SESSION['sms_device_id'] = $appareilInfo[0]['device_id'];
            $_SESSION['sms_device_name'] = $appareilInfo[0]['device_name'];
            $_SESSION['sms_api_username'] = $appareilInfo[0]['api_username'];
            $_SESSION['sms_api_password'] = $appareilInfo[0]['api_password'];
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Appareil activé avec succès'
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR activate_sms_appareil: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// RÉCUPÉRATION DES DONNÉES
// ============================================

// Récupérer les opérateurs associés au client
$providersAssocies = $db->select('client_provider', ['id_compte' => $id]);
$providerIds = array_column($providersAssocies, 'id_provider');

// Récupérer les détails des opérateurs associés
$associations = [];
if (!empty($providersAssocies)) {
    foreach ($providersAssocies as $assoc) {
        $provider = $db->select('provider', ['id_provider' => $assoc['id_provider']]);
        if (!empty($provider)) {
            $providerInfo = $provider[0];
            $canal = $db->select('type_message', ['id_type_message' => $providerInfo['id_type_message']]);
            $associations[] = [
                'id_client_provider' => $assoc['id_client_provider'],
                'id_provider' => $providerInfo['id_provider'],
                'nom_providers' => $providerInfo['nom_providers'],
                'description' => $providerInfo['description'],
                'canal' => !empty($canal) ? $canal[0]['libelle_type'] : 'Inconnu',
                'tarif' => $providerInfo['tarif'],
                'est_actif' => $assoc['est_actif'],
                'date_association' => $assoc['date_association']
            ];
        }
    }
}

// Récupérer les opérateurs disponibles (non associés)
$availableProviders = [];
$allProviders = $db->select('provider', [], '*', 'nom_providers ASC');

foreach ($allProviders as $provider) {
    if (!in_array($provider['id_provider'], $providerIds)) {
        $canal = $db->select('type_message', ['id_type_message' => $provider['id_type_message']]);
        $availableProviders[] = [
            'id_provider' => $provider['id_provider'],
            'nom_providers' => $provider['nom_providers'],
            'description' => $provider['description'],
            'canal' => !empty($canal) ? $canal[0]['libelle_type'] : 'Inconnu',
            'tarif' => $provider['tarif']
        ];
    }
}

// ============================================
// TRAITEMENT DES AUTRES ACTIONS
// ============================================

// --- Changement de statut (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_toggle_status'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $newStatut = $_POST['statut'] ?? 'actif';
        
        if (empty($clientId)) {
            echo json_encode(['success' => false, 'error' => 'ID client invalide']);
            exit;
        }
        
        $actif = ($newStatut === 'actif') ? true : false;
        $db->update('compte', ['actif' => $actif], ['id_compte' => $clientId]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Statut mis à jour avec succès',
            'statut' => $newStatut
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR changement statut: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- Mise à jour du crédit (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_credit'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $nouveauCredit = floatval($_POST['credit'] ?? 0);
        
        if (empty($clientId)) {
            echo json_encode(['success' => false, 'error' => 'ID client invalide']);
            exit;
        }
        
        if ($nouveauCredit < 0) {
            echo json_encode(['success' => false, 'error' => 'Le crédit ne peut pas être négatif']);
            exit;
        }
        
        $db->update('compte', ['credits_total' => $nouveauCredit], ['id_compte' => $clientId]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Crédit mis à jour avec succès',
            'credit' => number_format($nouveauCredit, 2)
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR mise à jour crédit: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- Modification des informations (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_info'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = $_POST['id_compte'] ?? '';
        $entreprise = trim($_POST['entreprise'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $code_postal = trim($_POST['code_postal'] ?? '');
        $ville = trim($_POST['ville'] ?? '');
        $mot_de_passe = $_POST['mot_de_passe'] ?? '';
        
        $errors = [];
        if (empty($entreprise)) $errors[] = "L'entreprise est requise";
        if (empty($nom)) $errors[] = "Le nom est requis";
        if (empty($prenom)) $errors[] = "Le prénom est requis";
        if (empty($user)) $errors[] = "L'email est requis";
        
        if (!empty($user)) {
            $existing = $db->select('compte', ['user' => $user]);
            if (!empty($existing) && $existing[0]['id_compte'] != $clientId) {
                $errors[] = "Cet email est déjà utilisé par un autre compte";
            }
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        $data = [
            'entreprise' => $entreprise,
            'nom' => $nom,
            'prenom' => $prenom,
            'user' => $user,
            'telephone' => $telephone,
            'adresse' => $adresse,
            'code_postal' => $code_postal,
            'ville' => $ville
        ];
        
        if (!empty($mot_de_passe)) {
            if (strlen($mot_de_passe) < 6) {
                echo json_encode(['success' => false, 'error' => 'Le mot de passe doit contenir au moins 6 caractères']);
                exit;
            }
            $data['password'] = password_hash($mot_de_passe, PASSWORD_DEFAULT);
        }
        
        $db->update('compte', $data, ['id_compte' => $clientId]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Informations mises à jour avec succès'
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR mise à jour informations: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================
// FONCTIONS UTILITAIRES
// ======================

function getStatutBadge($actif) {
    if ($actif) {
        return [
            'label' => 'Actif', 
            'class' => 'bg-green-100 text-green-800',
            'icon' => 'fa-check-circle'
        ];
    } else {
        return [
            'label' => 'Inactif', 
            'class' => 'bg-red-100 text-red-800',
            'icon' => 'fa-times-circle'
        ];
    }
}

function formatDate($date) {
    if (empty($date)) return '-';
    return date('d/m/Y', strtotime($date));
}

function getInitials($prenom, $nom) {
    return strtoupper(substr($prenom, 0, 1) . substr($nom, 0, 1));
}

$statut = getStatutBadge($client['actif']);
$initials = getInitials($client['prenom'], $client['nom']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails du client - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .statut-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .statut-badge i {
            margin-right: 6px;
        }
        
        .statut-badge.actif {
            background: #dcfce7;
            color: #166534;
        }
        
        .statut-badge.inactif {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .btn-toggle {
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-toggle:hover {
            transform: scale(1.05);
        }
        
        .btn-toggle.activer {
            background: #22c55e;
            color: white;
        }
        
        .btn-toggle.activer:hover {
            background: #16a34a;
        }
        
        .btn-toggle.desactiver {
            background: #ef4444;
            color: white;
        }
        
        .btn-toggle.desactiver:hover {
            background: #dc2626;
        }
        
        .info-card {
            background: white;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .info-card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .info-card-header .title {
            font-weight: 700;
            font-size: 14px;
            color: #1f2937;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            padding: 16px 20px;
        }
        
        .info-item .label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.03em;
        }
        
        .info-item .value {
            font-size: 14px;
            margin-top: 4px;
            color: #1f2937;
        }
        
        .stat-card {
            background: white;
            border-radius: 14px;
            padding: 16px 20px;
            border: 1px solid #e5e7eb;
        }
        
        .stat-card .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
        }
        
        .stat-card .stat-value {
            font-size: 20px;
            font-weight: 700;
            margin-top: 4px;
            color: #1f2937;
        }
        
        .edit-icon {
            color: #3b82f6;
            font-size: 13px;
            cursor: pointer;
            font-weight: 600;
            transition: color 0.2s;
        }
        
        .edit-icon:hover {
            color: #1d4ed8;
        }
        
        .save-icon {
            color: #22c55e;
            font-size: 13px;
            cursor: pointer;
            font-weight: 600;
            transition: color 0.2s;
        }
        
        .save-icon:hover {
            color: #16a34a;
        }
        
        .cancel-icon {
            color: #6b7280;
            font-size: 13px;
            cursor: pointer;
            font-weight: 600;
            transition: color 0.2s;
        }
        
        .cancel-icon:hover {
            color: #4b5563;
        }
        
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(20, 20, 40, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 50;
            backdrop-filter: blur(4px);
        }
        
        .modal-card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            width: 480px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
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
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            font-size: 14px;
            font-weight: 500;
        }
        
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        
        .input-edit {
            width: 100%;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        
        .input-edit:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: color 0.2s;
            text-decoration: none;
        }
        
        .btn-back:hover {
            color: #1f2937;
        }
        
        .btn-action-blue {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            background: #3b82f6;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-action-blue:hover {
            background: #2563eb;
            transform: scale(1.05);
        }
        
        .btn-action-green {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            background: #22c55e;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-action-green:hover {
            background: #16a34a;
            transform: scale(1.05);
        }
        
        .btn-action-red {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            background: #ef4444;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-action-red:hover {
            background: #dc2626;
            transform: scale(1.05);
        }
        
        .btn-action-purple {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            background: #8b5cf6;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-action-purple:hover {
            background: #7c3aed;
            transform: scale(1.05);
        }
        
        .btn-action-purple:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-sm {
            padding: 4px 12px;
            font-size: 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-sm:hover {
            transform: scale(1.05);
        }
        
        .btn-sm-success {
            background: #dcfce7;
            color: #166534;
        }
        
        .btn-sm-success:hover {
            background: #bbf7d0;
        }
        
        .btn-sm-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .btn-sm-danger:hover {
            background: #fecaca;
        }
        
        .btn-sm-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .btn-sm-warning:hover {
            background: #fde68a;
        }
        
        .btn-sm-secondary {
            background: #f3f4f6;
            color: #4b5563;
        }
        
        .btn-sm-secondary:hover {
            background: #e5e7eb;
        }
        
        .btn-sm-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .btn-sm-info:hover {
            background: #bfdbfe;
        }
        
        .btn-sm-whatsapp {
            background: #25D366;
            color: white;
        }
        .btn-sm-whatsapp:hover {
            background: #1da851;
        }
        
        .btn-sm-restart {
            background: #f59e0b;
            color: white;
        }
        .btn-sm-restart:hover {
            background: #d97706;
        }
        
        .btn-sm-sms {
            background: #3b82f6;
            color: white;
        }
        .btn-sm-sms:hover {
            background: #2563eb;
        }
        
        .provider-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s;
            margin-bottom: 8px;
        }
        
        .provider-item:hover {
            border-color: #8b5cf6;
            background: #faf5ff;
        }
        
        .provider-item .provider-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .provider-item .provider-icon.whatsapp {
            background: #d1fae5;
            color: #065f46;
        }
        
        .provider-item .provider-icon.sms {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .provider-item .provider-icon.email {
            background: #fef3c7;
            color: #92400e;
        }
        
        .provider-item .provider-icon.default {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .empty-providers {
            text-align: center;
            padding: 30px 20px;
            color: #9ca3af;
        }
        
        .empty-providers i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }
        
        .provider-select-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
        }
        
        .provider-select-option .canal-badge {
            font-size: 11px;
            padding: 2px 10px;
            border-radius: 12px;
            background: #f3f4f6;
            color: #4b5563;
        }
        
        .modal-close-btn {
            background: none;
            border: none;
            font-size: 24px;
            color: #9ca3af;
            cursor: pointer;
            transition: color 0.2s;
            padding: 4px 8px;
            border-radius: 8px;
            line-height: 1;
        }
        
        .modal-close-btn:hover {
            color: #ef4444;
            background: #fee2e2;
        }
        
        .session-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s;
            margin-bottom: 6px;
        }
        
        .session-list-item:hover {
            border-color: #8b5cf6;
            background: #faf5ff;
        }
        
        .session-list-item.active {
            border-color: #22c55e;
            background: #f0fdf4;
        }
        
        .session-list-item .session-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            background: #d1fae5;
            color: #065f46;
        }
        
        .session-list-item .session-icon.inactive {
            background: #f3f4f6;
            color: #9ca3af;
        }
        
        .session-status-badge {
            font-size: 11px;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .session-status-badge.active {
            background: #dcfce7;
            color: #166534;
        }
        
        .session-status-badge.inactive {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .session-status-badge.working {
            background: #dcfce7;
            color: #166534;
        }
        
        .session-status-badge.scanning {
            background: #fef3c7;
            color: #92400e;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        .session-status-badge.stopped {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .session-status-badge.failed {
            background: #fee2e2;
            color: #991b1b;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .code-display {
            font-size: 32px;
            font-weight: 700;
            text-align: center;
            padding: 20px;
            background: #f3f4f6;
            border-radius: 10px;
            border: 2px dashed #d1d5db;
            letter-spacing: 6px;
            color: #1f2937;
            font-family: 'Courier New', monospace;
        }
        
        .modal-card-code {
            max-width: 550px;
            width: 90%;
        }
        
        .modal-card-session {
            max-width: 750px;
            width: 95%;
        }
        
        .session-list-item.scanning {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        
        .session-list-item.working {
            border-color: #22c55e;
            background: #f0fdf4;
        }
        
        .session-list-item.failed {
            border-color: #ef4444;
            background: #fef2f2;
        }
        
        .session-list-item .btn-sm-whatsapp {
            min-width: 85px;
            justify-content: center;
        }
        
        .modal-card-sms {
            max-width: 600px;
            width: 95%;
        }
        
        .password-container {
            position: relative;
        }
        .password-container input {
            padding-right: 45px;
        }
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            background: transparent;
            border: none;
            font-size: 1.1rem;
        }
        .toggle-password:hover {
            color: #3b82f6;
        }
        
        .device-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s;
            margin-bottom: 8px;
            cursor: pointer;
        }
        
        .device-item:hover {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        
        .device-item.active {
            border-color: #3b82f6;
            background: #dbeafe;
        }
        
        .device-item .device-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #dbeafe;
            color: #1e40af;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .device-item .device-icon.inactive {
            background: #f3f4f6;
            color: #9ca3af;
        }
    </style>
</head>
<body>

<!-- ============================================ -->
<!-- MODALE DE RECHARGE DE CRÉDIT -->
<!-- ============================================ -->
<div id="rechargeModal" class="modal-overlay" style="display: none;">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Recharger le crédit</h3>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($client['entreprise']) ?></p>
            </div>
            <button onclick="closeRechargeModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <div class="mb-4">
            <p class="text-sm text-gray-600">Solde actuel : <strong><?= number_format($client['credits_total'] ?? 0, 2) ?> €</strong></p>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Montant à ajouter (€)</label>
            <input type="number" id="rechargeAmount" step="0.01" min="0.01" 
                   class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:border-blue-500"
                   placeholder="Ex: 100">
        </div>
        
        <div class="mt-6 flex justify-end gap-2">
            <button onclick="closeRechargeModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </button>
            <button onclick="confirmRecharge()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                <i class="fas fa-plus mr-2"></i>Recharger
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE D'ASSOCIATION D'OPÉRATEUR -->
<!-- ============================================ -->
<div id="associateProviderModal" class="modal-overlay" style="display: none;">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Associer un opérateur</h3>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($client['entreprise']) ?></p>
            </div>
            <button onclick="closeAssociateModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionnez un opérateur</label>
            <select id="providerSelect" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:border-purple-500">
                <option value="">Choisissez un opérateur...</option>
                <?php foreach ($availableProviders as $provider): ?>
                    <option value="<?= $provider['id_provider'] ?>">
                        <?= htmlspecialchars($provider['canal']) ?> - <?= htmlspecialchars($provider['nom_providers']) ?> 
                        (<?= htmlspecialchars($provider['description']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            
            <?php if (empty($availableProviders)): ?>
                <p class="text-sm text-amber-600 mt-2">
                    <i class="fas fa-info-circle"></i> Aucun opérateur disponible à associer.
                </p>
            <?php endif; ?>
        </div>
        
        <div class="mt-4">
            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                <input type="checkbox" id="associateActive" checked class="w-4 h-4 text-purple-600 rounded">
                <span>Activer immédiatement</span>
            </label>
        </div>
        
        <div class="mt-6 flex justify-end gap-2">
            <button onclick="closeAssociateModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </button>
            <button onclick="confirmAssociate()" id="associateBtn" 
                    class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition flex items-center gap-2"
                    <?= empty($availableProviders) ? 'disabled' : '' ?>>
                <i class="fas fa-link"></i> Associer
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE DE CONFIRMATION DE DISSOCIATION -->
<!-- ============================================ -->
<div id="detachConfirmModal" class="modal-overlay" style="display: none;">
    <div class="modal-card" style="max-width: 400px;" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800">Confirmer la dissociation</h3>
            <button onclick="closeDetachConfirmModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <div class="mb-4">
            <p class="text-gray-600">
                Êtes-vous sûr de vouloir dissocier l'opérateur 
                <strong id="detachProviderName"></strong> du client 
                <strong><?= htmlspecialchars($client['entreprise']) ?></strong> ?
            </p>
            <p class="text-sm text-red-600 mt-2">
                <i class="fas fa-exclamation-triangle"></i> Cette action est irréversible.
            </p>
        </div>
        
        <input type="hidden" id="detachIdClientProvider" value="">
        
        <div class="flex justify-end gap-2">
            <button onclick="closeDetachConfirmModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </button>
            <button id="confirmDetachBtn" onclick="confirmDetach()" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition flex items-center gap-2">
                <i class="fas fa-unlink"></i> Dissocier
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE DE CRÉATION DE SESSION -->
<!-- ============================================ -->
<div id="sessionModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-session" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800" id="sessionModalTitle">Gestion des sessions</h3>
                <p class="text-sm text-gray-500" id="sessionModalSubtitle"><?= htmlspecialchars($client['entreprise']) ?></p>
            </div>
            <button onclick="closeSessionModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <input type="hidden" id="sessionProviderId" value="">
        <input type="hidden" id="sessionProviderType" value="">
        
        <div id="sessionContent">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-purple-600"></i>
                <p class="text-gray-500 mt-2">Chargement...</p>
            </div>
        </div>
        
        <div class="mt-4 flex justify-end gap-2" id="sessionFooter">
            <button onclick="closeSessionModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Fermer
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE POUR LE CODE D'APPAIRAGE -->
<!-- ============================================ -->
<div id="codeModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-code" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800">🔐 Code d'appairage WhatsApp</h3>
                <p class="text-sm text-gray-500" id="codeModalSubtitle">Pour la session: <strong id="codeSessionName"></strong></p>
            </div>
            <button onclick="closeCodeModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <div class="mb-4">
            <p class="text-sm text-gray-600 mb-2">Demandez à votre client de saisir ce code dans WhatsApp :</p>
            <div class="code-display" id="codeDisplay">ABCD1234</div>
            <p class="text-xs text-gray-500 mt-2 text-center">
                <i class="fas fa-info-circle"></i> Le code est valable pendant une durée limitée.
            </p>
        </div>
        
        <div class="flex gap-2 mt-2">
            <button onclick="copyCode()" class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition flex items-center justify-center gap-2">
                <i class="fas fa-copy"></i> Copier le code
            </button>
            <button onclick="closeCodeModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Fermer
            </button>
        </div>
        
        <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
            <p class="text-sm text-yellow-800">
                <i class="fas fa-exclamation-triangle"></i>
                Instructions: Ouvrez WhatsApp → Paramètres → Appareils associés → Associer un appareil → Saisissez le code
            </p>
        </div>
        
        <div id="waitingProgress" style="display: none;" class="mt-4">
            <p class="text-sm text-gray-600 text-center">
                <i class="fas fa-spinner fa-spin mr-2"></i>
                Attente de connexion... (<span id="waitingSeconds">60</span>s)
            </p>
            <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                <div id="progressBar" class="bg-green-500 h-2 rounded-full transition-all duration-1000" style="width: 0%;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE SMS POUR LA CONNEXION API -->
<!-- ============================================ -->
<div id="smsApiModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-sms" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800">📱 Connexion à l'API SMS</h3>
                <p class="text-sm text-gray-500">Entrez vos identifiants pour récupérer vos appareils</p>
            </div>
            <button onclick="closeSmsApiModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <form id="smsLoginForm">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Nom d'utilisateur API *
                </label>
                <input type="text" id="api_username" 
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition"
                       placeholder="Entrez votre nom d'utilisateur">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Mot de passe API *
                </label>
                <div class="password-container">
                    <input type="password" id="api_password" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition"
                           placeholder="Entrez votre mot de passe">
                    <button type="button" class="toggle-password" onclick="togglePassword('api_password', this)">
                        <i class="far fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="flex justify-end space-x-2 mt-6">
                <button type="button" onclick="closeSmsApiModal()" 
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Annuler
                </button>
                <button type="submit" 
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-sign-in-alt mr-2"></i>Se connecter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE DE SÉLECTION D'APPAREIL SMS -->
<!-- ============================================ -->
<div id="smsDeviceModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-sms" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800">📱 Choisir un appareil SMS</h3>
                <p class="text-sm text-gray-500">Sélectionnez l'appareil à utiliser</p>
            </div>
            <button onclick="closeSmsDeviceModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <div id="deviceListContainer">
            <div class="text-center text-gray-500 py-4">
                <i class="fas fa-spinner fa-spin mr-2"></i>
                Chargement...
            </div>
        </div>
        
        <div class="flex justify-end mt-6">
            <button type="button" onclick="closeSmsDeviceModal()" 
                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE DE CONFIRMATION DE SUPPRESSION -->
<!-- ============================================ -->
<div id="confirmModal" class="modal-overlay" style="display: none;">
    <div class="modal-card" style="max-width: 400px;" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div class="flex items-center">
                <div class="bg-red-100 p-2 rounded-full mr-3">
                    <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-800">Confirmer</h3>
            </div>
            <button onclick="closeConfirmModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <p class="text-gray-600 mb-4" id="confirmMessage">Êtes-vous sûr de vouloir effectuer cette action ?</p>
        
        <div class="flex justify-end gap-2">
            <button onclick="closeConfirmModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </button>
            <button id="confirmActionBtn" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition">
                <i class="fas fa-trash-alt mr-2"></i>Confirmer
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- CONTENU PRINCIPAL -->
<!-- ============================================ -->
<div class="p-6">

    <div class="mb-6">
        <a href="?page=admin/clients" class="btn-back">
            <i class="fas fa-arrow-left"></i> Retour aux clients
        </a>
    </div>

    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-2xl">
                <?= $initials ?>
            </div>
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-gray-800">
                        <?= htmlspecialchars($client['prenom']) ?> <?= htmlspecialchars($client['nom']) ?>
                    </h1>
                    <span class="statut-badge <?= $client['actif'] ? 'actif' : 'inactif' ?>">
                        <i class="fas <?= $statut['icon'] ?>"></i>
                        <?= $statut['label'] ?>
                    </span>
                </div>
                <p class="text-gray-500 text-sm">
                    <?= htmlspecialchars($client['entreprise']) ?>
                </p>
            </div>
        </div>
        
        <div class="flex gap-2">
            <button onclick="toggleStatus()" id="toggleStatusBtn" 
                    class="<?= $client['actif'] ? 'btn-action-red' : 'btn-action-green' ?>">
                <i class="fas <?= $client['actif'] ? 'fa-pause' : 'fa-play' ?>"></i>
                <?= $client['actif'] ? 'Désactiver' : 'Activer' ?>
            </button>
            
            <button onclick="openRechargeModal()" class="btn-action-blue">
                <i class="fas fa-plus"></i> Recharger le crédit
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="stat-card">
            <div class="stat-label">Crédit disponible</div>
            <div class="stat-value">
                <span id="creditDisplay"><?= number_format($client['credits_total'] ?? 0, 2) ?></span> €
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Date d'inscription</div>
            <div class="stat-value"><?= formatDate($client['date_creation']) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Email</div>
            <div class="stat-value text-base truncate"><?= htmlspecialchars($client['user']) ?></div>
        </div>
    </div>

    <div class="info-card mb-6">
        <div class="info-card-header">
            <span class="title"><i class="fas fa-user mr-2 text-gray-400"></i>Informations du client</span>
            <div id="infoActions">
                <span onclick="enableEditInfo()" id="editInfoBtn" class="edit-icon">
                    <i class="fas fa-edit mr-1"></i> Modifier
                </span>
                <span id="saveInfoBtn" style="display:none;">
                    <span onclick="saveInfo()" class="save-icon mr-3">
                        <i class="fas fa-save mr-1"></i> Enregistrer
                    </span>
                    <span onclick="cancelEditInfo()" class="cancel-icon">
                        <i class="fas fa-times mr-1"></i> Annuler
                    </span>
                </span>
            </div>
        </div>
        
        <div id="infoDisplay" class="info-grid">
            <div class="info-item">
                <div class="label">Entreprise</div>
                <div class="value" id="display_entreprise"><?= htmlspecialchars($client['entreprise']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Nom</div>
                <div class="value" id="display_nom"><?= htmlspecialchars($client['nom']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Prénom</div>
                <div class="value" id="display_prenom"><?= htmlspecialchars($client['prenom']) ?></div>
            </div>
            <div class="info-item">
                <div class="label">Téléphone</div>
                <div class="value" id="display_telephone"><?= htmlspecialchars($client['telephone'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="label">Adresse</div>
                <div class="value" id="display_adresse"><?= htmlspecialchars($client['adresse'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="label">Code postal</div>
                <div class="value" id="display_code_postal"><?= htmlspecialchars($client['code_postal'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="label">Ville</div>
                <div class="value" id="display_ville"><?= htmlspecialchars($client['ville'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="label">Email</div>
                <div class="value" id="display_user"><?= htmlspecialchars($client['user']) ?></div>
            </div>
        </div>
        
        <div id="infoEdit" style="display:none;" class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Entreprise</label>
                    <input type="text" id="edit_entreprise" value="<?= htmlspecialchars($client['entreprise']) ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nom</label>
                    <input type="text" id="edit_nom" value="<?= htmlspecialchars($client['nom']) ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Prénom</label>
                    <input type="text" id="edit_prenom" value="<?= htmlspecialchars($client['prenom']) ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Téléphone</label>
                    <input type="text" id="edit_telephone" value="<?= htmlspecialchars($client['telephone'] ?? '') ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Adresse</label>
                    <input type="text" id="edit_adresse" value="<?= htmlspecialchars($client['adresse'] ?? '') ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Code postal</label>
                    <input type="text" id="edit_code_postal" value="<?= htmlspecialchars($client['code_postal'] ?? '') ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Ville</label>
                    <input type="text" id="edit_ville" value="<?= htmlspecialchars($client['ville'] ?? '') ?>" class="input-edit">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email</label>
                    <input type="email" id="edit_user" value="<?= htmlspecialchars($client['user']) ?>" class="input-edit">
                </div>
                <div class="col-span-4">
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nouveau mot de passe</label>
                    <input type="password" id="edit_mot_de_passe" placeholder="Laisser vide pour ne pas changer" class="input-edit">
                    <p class="text-xs text-gray-500 mt-1">Laissez vide pour conserver le mot de passe actuel</p>
                </div>
            </div>
        </div>
    </div>

    <div class="info-card">
        <div class="info-card-header">
            <span class="title"><i class="fas fa-link mr-2 text-gray-400"></i>Opérateurs associés</span>
            <button onclick="openAssociateModal()" class="btn-action-purple">
                <i class="fas fa-plus-circle"></i> Associer un opérateur
            </button>
        </div>
        
        <div class="p-4">
            <?php if (empty($associations)): ?>
                <div class="empty-providers">
                    <i class="fas fa-users-slash"></i>
                    <p>Aucun opérateur associé à ce client</p>
                    <p class="text-sm mt-1">Cliquez sur "Associer un opérateur" pour en ajouter un</p>
                </div>
            <?php else: ?>
                <div class="space-y-2" id="providersList">
                    <?php foreach ($associations as $assoc): ?>
                        <?php
                        $iconClass = 'default';
                        $canalLower = strtolower($assoc['canal']);
                        if (in_array($canalLower, ['whatsapp', 'sms', 'email'])) {
                            $iconClass = $canalLower;
                        }
                        ?>
                        <div class="provider-item" id="provider_<?= $assoc['id_client_provider'] ?>">
                            <div class="flex items-center gap-3 flex-1">
                                <div class="provider-icon <?= $iconClass ?>">
                                    <i class="fas <?= $iconClass === 'whatsapp' ? 'fa-mobile-alt' : ($iconClass === 'sms' ? 'fa-sms' : ($iconClass === 'email' ? 'fa-envelope' : 'fa-plug')) ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($assoc['nom_providers']) ?></span>
                                        <span class="text-xs text-gray-400">|</span>
                                        <span class="text-xs text-gray-500"><?= htmlspecialchars($assoc['description']) ?></span>
                                    </div>
                                    <div class="flex items-center gap-3 mt-1">
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">
                                            <?= htmlspecialchars($assoc['canal']) ?>
                                        </span>
                                        <span class="text-xs text-gray-400">
                                            Tarif: <?= number_format($assoc['tarif'], 2) ?> €
                                        </span>
                                        <span class="text-xs text-gray-400">
                                            Associé le: <?= date('d/m/Y', strtotime($assoc['date_association'])) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium px-2 py-1 rounded-full <?= $assoc['est_actif'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                                    <i class="fas <?= $assoc['est_actif'] ? 'fa-check-circle' : 'fa-circle' ?>"></i>
                                    <?= $assoc['est_actif'] ? 'Actif' : 'Inactif' ?>
                                </span>
                                <button onclick="openSessionModal(<?= $assoc['id_provider'] ?>, '<?= htmlspecialchars($assoc['nom_providers']) ?>', '<?= htmlspecialchars($assoc['canal']) ?>')" 
                                        class="btn-sm btn-sm-info">
                                    <i class="fas fa-cog"></i>
                                    Gérer
                                </button>
                                <button onclick="toggleProviderStatus(<?= $assoc['id_client_provider'] ?>, <?= $assoc['est_actif'] ? 'false' : 'true' ?>)" 
                                        class="btn-sm <?= $assoc['est_actif'] ? 'btn-sm-warning' : 'btn-sm-success' ?>">
                                    <i class="fas <?= $assoc['est_actif'] ? 'fa-pause' : 'fa-play' ?>"></i>
                                    <?= $assoc['est_actif'] ? 'Désactiver' : 'Activer' ?>
                                </button>
                                <button onclick="openDetachConfirm(<?= $assoc['id_client_provider'] ?>, '<?= htmlspecialchars($assoc['nom_providers']) ?>')" 
                                        class="btn-sm btn-sm-danger">
                                    <i class="fas fa-unlink"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
// ============================================
// VARIABLES
// ============================================
const clientId = '<?= $id ?>';
let waitingTimer = null;
let waitingInterval = null;
let waitingSeconds = 60;
let statusPollingInterval = null;
let currentSessionName = '';
let isPolling = false;
let connectionCheckCount = 0;
let currentApiUsername = '';
let currentApiPassword = '';
let confirmCallback = null;

const WAHA_STATUS = {
    'WORKING': '✅ Connecté',
    'SCAN_QR_CODE': '📱 Scan QR Code en cours...',
    'STOPPED': '⏹️ Arrêté',
    'FAILED': '❌ Échec',
    'UNKNOWN': '🔄 En attente...'
};

const WAHA_STATUS_CLASS = {
    'WORKING': 'working',
    'SCAN_QR_CODE': 'scanning',
    'STOPPED': 'stopped',
    'FAILED': 'failed',
    'UNKNOWN': 'unknown'
};

// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type = 'success') {
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    const colors = { success: '#10b981', error: '#ef4444', info: '#3b82f6' };
    toast.innerHTML = `<div class="toast-content" style="background: ${colors[type] || colors.success};">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ============================================
// CHANGEMENT DE STATUT DU CLIENT (AJAX)
// ============================================
async function toggleStatus() {
    const currentStatut = <?= $client['actif'] ? 'true' : 'false' ?>;
    const newStatut = currentStatut ? 'inactif' : 'actif';
    
    try {
        const formData = new FormData();
        formData.append('action_toggle_status', '1');
        formData.append('id_compte', clientId);
        formData.append('statut', newStatut);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            
            const statutBadges = document.querySelectorAll('.statut-badge');
            const toggleBtn = document.getElementById('toggleStatusBtn');
            
            if (newStatut === 'actif') {
                statutBadges.forEach(badge => {
                    badge.className = 'statut-badge actif';
                    badge.innerHTML = '<i class="fas fa-check-circle"></i> Actif';
                });
                toggleBtn.className = 'btn-action-red';
                toggleBtn.innerHTML = '<i class="fas fa-pause"></i> Désactiver';
            } else {
                statutBadges.forEach(badge => {
                    badge.className = 'statut-badge inactif';
                    badge.innerHTML = '<i class="fas fa-times-circle"></i> Inactif';
                });
                toggleBtn.className = 'btn-action-green';
                toggleBtn.innerHTML = '<i class="fas fa-play"></i> Activer';
            }
        } else {
            showToast(result.error || 'Erreur lors du changement de statut', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ============================================
// MODALE DE RECHARGE DE CRÉDIT
// ============================================
function openRechargeModal() {
    document.getElementById('rechargeModal').style.display = 'flex';
    document.getElementById('rechargeAmount').value = '';
    document.getElementById('rechargeAmount').focus();
}

function closeRechargeModal() {
    document.getElementById('rechargeModal').style.display = 'none';
}

async function confirmRecharge() {
    const amount = parseFloat(document.getElementById('rechargeAmount').value);
    
    if (!amount || amount <= 0) {
        showToast('Veuillez entrer un montant valide', 'error');
        return;
    }
    
    const currentCredit = <?= $client['credits_total'] ?? 0 ?>;
    const newCredit = currentCredit + amount;
    
    try {
        const formData = new FormData();
        formData.append('action_update_credit', '1');
        formData.append('id_compte', clientId);
        formData.append('credit', newCredit);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('Crédit rechargé avec succès', 'success');
            document.querySelectorAll('#creditDisplay').forEach(el => {
                el.textContent = result.credit;
            });
            closeRechargeModal();
        } else {
            showToast(result.error || 'Erreur lors du rechargement', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ============================================
// ÉDITION DES INFORMATIONS
// ============================================
function enableEditInfo() {
    document.getElementById('infoDisplay').style.display = 'none';
    document.getElementById('infoEdit').style.display = 'block';
    document.getElementById('editInfoBtn').style.display = 'none';
    document.getElementById('saveInfoBtn').style.display = 'inline';
}

function cancelEditInfo() {
    document.getElementById('infoDisplay').style.display = 'grid';
    document.getElementById('infoEdit').style.display = 'none';
    document.getElementById('editInfoBtn').style.display = 'inline';
    document.getElementById('saveInfoBtn').style.display = 'none';
}

async function saveInfo() {
    const formData = new FormData();
    formData.append('action_update_info', '1');
    formData.append('id_compte', clientId);
    formData.append('entreprise', document.getElementById('edit_entreprise').value);
    formData.append('nom', document.getElementById('edit_nom').value);
    formData.append('prenom', document.getElementById('edit_prenom').value);
    formData.append('telephone', document.getElementById('edit_telephone').value);
    formData.append('adresse', document.getElementById('edit_adresse').value);
    formData.append('code_postal', document.getElementById('edit_code_postal').value);
    formData.append('ville', document.getElementById('edit_ville').value);
    formData.append('user', document.getElementById('edit_user').value);
    formData.append('mot_de_passe', document.getElementById('edit_mot_de_passe').value);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            
            const fields = ['entreprise', 'nom', 'prenom', 'telephone', 'adresse', 'code_postal', 'ville', 'user'];
            fields.forEach(field => {
                const displayEl = document.getElementById('display_' + field);
                const editEl = document.getElementById('edit_' + field);
                if (displayEl && editEl) {
                    displayEl.textContent = editEl.value || '-';
                }
            });
            
            cancelEditInfo();
            
            const prenom = document.getElementById('edit_prenom').value;
            const nom = document.getElementById('edit_nom').value;
            const entreprise = document.getElementById('edit_entreprise').value;
            
            document.querySelector('h1').textContent = prenom + ' ' + nom;
            document.querySelector('.text-gray-500.text-sm').textContent = entreprise;
            
            const initials = (prenom.charAt(0) + nom.charAt(0)).toUpperCase();
            document.querySelector('.w-14.h-14').textContent = initials;
            
        } else {
            showToast(result.error || 'Erreur lors de la mise à jour', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ============================================
// GESTION DES OPÉRATEURS ASSOCIÉS
// ============================================

function openAssociateModal() {
    document.getElementById('associateProviderModal').style.display = 'flex';
    document.getElementById('providerSelect').focus();
}

function closeAssociateModal() {
    document.getElementById('associateProviderModal').style.display = 'none';
}

async function confirmAssociate() {
    const providerId = document.getElementById('providerSelect').value;
    const estActif = document.getElementById('associateActive').checked;
    const btn = document.getElementById('associateBtn');
    const originalText = btn.innerHTML;
    
    if (!providerId) {
        showToast('Veuillez sélectionner un opérateur', 'error');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Association...';
    
    try {
        const formData = new FormData();
        formData.append('action_associate_provider', '1');
        formData.append('id_compte', clientId);
        formData.append('id_provider', providerId);
        formData.append('est_actif', estActif ? 'true' : 'false');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const text = await response.text();
        
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            console.error('Réponse brute:', text);
            throw new Error('La réponse du serveur n\'est pas du JSON valide');
        }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeAssociateModal();
            
            const assoc = result.association;
            const providersList = document.getElementById('providersList');
            
            const emptyDiv = providersList.querySelector('.empty-providers');
            if (emptyDiv) {
                emptyDiv.remove();
            }
            
            const canalLower = (assoc.canal || '').toLowerCase();
            let iconClass = 'default';
            let icon = 'fa-plug';
            if (canalLower === 'whatsapp') {
                iconClass = 'whatsapp';
                icon = 'fa-whatsapp';
            } else if (canalLower === 'sms') {
                iconClass = 'sms';
                icon = 'fa-sms';
            } else if (canalLower === 'email') {
                iconClass = 'email';
                icon = 'fa-envelope';
            }
            
            const isActive = assoc.est_actif === true || assoc.est_actif === 1 || assoc.est_actif === 'true';
            const statusClass = isActive ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500';
            const statusIcon = isActive ? 'fa-check-circle' : 'fa-circle';
            const statusLabel = isActive ? 'Actif' : 'Inactif';
            const toggleAction = isActive ? 'false' : 'true';
            const toggleLabel = isActive ? 'Désactiver' : 'Activer';
            const toggleClass = isActive ? 'btn-sm-warning' : 'btn-sm-success';
            const toggleIcon = isActive ? 'fa-pause' : 'fa-play';
            
            const html = `
                <div class="provider-item" id="provider_${assoc.id_client_provider}">
                    <div class="flex items-center gap-3 flex-1">
                        <div class="provider-icon ${iconClass}">
                            <i class="fas ${icon}"></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-800">${escapeHtml(assoc.nom_providers || '')}</span>
                                <span class="text-xs text-gray-400">|</span>
                                <span class="text-xs text-gray-500">${escapeHtml(assoc.description || '')}</span>
                            </div>
                            <div class="flex items-center gap-3 mt-1">
                                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">
                                    ${escapeHtml(assoc.canal || 'Inconnu')}
                                </span>
                                <span class="text-xs text-gray-400">
                                    Tarif: ${(parseFloat(assoc.tarif) || 0).toFixed(2)} €
                                </span>
                                <span class="text-xs text-gray-400">
                                    Associé le: ${assoc.date_association || ''}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium px-2 py-1 rounded-full ${statusClass}">
                            <i class="fas ${statusIcon}"></i>
                            ${statusLabel}
                        </span>
                        <button onclick="openSessionModal(${assoc.id_provider}, '${escapeHtml(assoc.nom_providers || '')}', '${escapeHtml(assoc.canal || '')}')" 
                                class="btn-sm btn-sm-info">
                            <i class="fas fa-cog"></i>
                            Gérer
                        </button>
                        <button onclick="toggleProviderStatus(${assoc.id_client_provider}, ${toggleAction})" 
                                class="btn-sm ${toggleClass}">
                            <i class="fas ${toggleIcon}"></i>
                            ${toggleLabel}
                        </button>
                        <button onclick="openDetachConfirm(${assoc.id_client_provider}, '${escapeHtml(assoc.nom_providers || '')}')" 
                                class="btn-sm btn-sm-danger">
                            <i class="fas fa-unlink"></i>
                        </button>
                    </div>
                </div>
            `;
            
            providersList.insertAdjacentHTML('beforeend', html);
            
            const select = document.getElementById('providerSelect');
            if (select) {
                const option = select.querySelector(`option[value="${providerId}"]`);
                if (option) {
                    option.remove();
                }
                const remainingOptions = select.querySelectorAll('option:not([value=""])');
                if (remainingOptions.length === 0) {
                    document.getElementById('associateBtn').disabled = true;
                }
            }
        } else {
            showToast(result.error || 'Erreur lors de l\'association', 'error');
        }
    } catch (error) {
        console.error('Erreur détaillée:', error);
        showToast('Erreur: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

function openDetachConfirm(idClientProvider, providerName) {
    document.getElementById('detachIdClientProvider').value = idClientProvider;
    document.getElementById('detachProviderName').textContent = providerName;
    document.getElementById('detachConfirmModal').style.display = 'flex';
}

function closeDetachConfirmModal() {
    document.getElementById('detachConfirmModal').style.display = 'none';
}

async function confirmDetach() {
    const idClientProvider = document.getElementById('detachIdClientProvider').value;
    const btn = document.getElementById('confirmDetachBtn');
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Dissociation...';
    
    try {
        const formData = new FormData();
        formData.append('action_detach_provider', '1');
        formData.append('id_compte', clientId);
        formData.append('id_client_provider', idClientProvider);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const text = await response.text();
        
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            console.error('Réponse brute:', text);
            throw new Error('La réponse du serveur n\'est pas du JSON valide');
        }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeDetachConfirmModal();
            
            const element = document.getElementById(`provider_${idClientProvider}`);
            if (element) {
                element.remove();
            }
            
            const providersList = document.getElementById('providersList');
            const remainingItems = providersList.querySelectorAll('.provider-item');
            if (remainingItems.length === 0) {
                providersList.innerHTML = `
                    <div class="empty-providers">
                        <i class="fas fa-users-slash"></i>
                        <p>Aucun opérateur associé à ce client</p>
                        <p class="text-sm mt-1">Cliquez sur "Associer un opérateur" pour en ajouter un</p>
                    </div>
                `;
            }
            
            document.getElementById('associateBtn').disabled = false;
        } else {
            showToast(result.error || 'Erreur lors de la dissociation', 'error');
        }
    } catch (error) {
        console.error('Erreur détaillée:', error);
        showToast('Erreur: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function toggleProviderStatus(idClientProvider, newStatus) {
    try {
        const formData = new FormData();
        formData.append('action_toggle_provider_status', '1');
        formData.append('id_compte', clientId);
        formData.append('id_client_provider', idClientProvider);
        formData.append('est_actif', newStatus ? 'true' : 'false');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            
            const item = document.getElementById(`provider_${idClientProvider}`);
            if (item) {
                const statusSpan = item.querySelector('.text-xs.font-medium.px-2.py-1.rounded-full');
                const toggleBtn = item.querySelector('.btn-sm:not(.btn-sm-danger):not(.btn-sm-info)');
                
                if (newStatus) {
                    statusSpan.className = 'text-xs font-medium px-2 py-1 rounded-full bg-green-100 text-green-700';
                    statusSpan.innerHTML = '<i class="fas fa-check-circle"></i> Actif';
                    toggleBtn.className = 'btn-sm btn-sm-warning';
                    toggleBtn.innerHTML = '<i class="fas fa-pause"></i> Désactiver';
                    toggleBtn.setAttribute('onclick', `toggleProviderStatus(${idClientProvider}, false)`);
                } else {
                    statusSpan.className = 'text-xs font-medium px-2 py-1 rounded-full bg-gray-100 text-gray-500';
                    statusSpan.innerHTML = '<i class="fas fa-circle"></i> Inactif';
                    toggleBtn.className = 'btn-sm btn-sm-success';
                    toggleBtn.innerHTML = '<i class="fas fa-play"></i> Activer';
                    toggleBtn.setAttribute('onclick', `toggleProviderStatus(${idClientProvider}, true)`);
                }
            }
        } else {
            showToast(result.error || 'Erreur lors du changement de statut', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ============================================
// GESTION DES SESSIONS OPÉRATEUR
// ============================================

async function openSessionModal(providerId, providerName, providerType) {
    document.getElementById('sessionModalTitle').textContent = `Gestion des sessions - ${providerName}`;
    document.getElementById('sessionModalSubtitle').textContent = `Client: <?= htmlspecialchars($client['entreprise']) ?>`;
    document.getElementById('sessionProviderId').value = providerId;
    document.getElementById('sessionProviderType').value = providerType;
    
    document.getElementById('sessionModal').style.display = 'flex';
    
    if (providerType.toLowerCase() === 'whatsapp') {
        await loadWhatsAppSessions();
    } else if (providerType.toLowerCase() === 'sms') {
        await loadSmsAppareils();
    } else {
        document.getElementById('sessionContent').innerHTML = `
            <div class="text-center py-8">
                <i class="fas fa-info-circle text-3xl text-amber-500"></i>
                <p class="text-gray-600 mt-2">Type d'opérateur non supporté pour la création de session</p>
                <p class="text-sm text-gray-500 mt-1">Type actuel: ${escapeHtml(providerType)}</p>
            </div>
        `;
        document.getElementById('sessionFooter').innerHTML = `
            <button onclick="closeSessionModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Fermer
            </button>
        `;
    }
}

function closeSessionModal() {
    document.getElementById('sessionModal').style.display = 'none';
    document.getElementById('sessionContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-purple-600"></i>
            <p class="text-gray-500 mt-2">Chargement...</p>
        </div>
    `;
    document.getElementById('sessionFooter').innerHTML = `
        <button onclick="closeSessionModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
            Fermer
        </button>
    `;
}

// ============================================
// FONCTIONS SMS - GESTION DES APPAREILS
// ============================================

async function loadSmsAppareils() {
    const container = document.getElementById('sessionContent');
    const footer = document.getElementById('sessionFooter');
    
    try {
        const formData = new FormData();
        formData.append('action_get_sms_appareils', '1');
        formData.append('id_compte', clientId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            let html = `
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Appareils SMS disponibles</label>
                    <div class="space-y-2 max-h-60 overflow-y-auto">
            `;
            
            if (result.appareils.length === 0) {
                html += `
                    <div class="text-center text-gray-500 py-4">
                        <i class="fas fa-info-circle mb-2"></i>
                        <p>Aucun appareil configuré</p>
                        <p class="text-sm mt-1">Ajoutez un nouvel appareil ci-dessous</p>
                    </div>
                `;
            } else {
                result.appareils.forEach(appareil => {
                    const isActive = appareil.est_actif;
                    const cleanName = appareil.device_name.replace(/['"\\]/g, '').replace(/\s+/g, ' ');
                    const escapedName = escapeHtml(cleanName);
                    
                    html += `
                        <div class="device-item ${isActive ? 'active' : ''}" 
                             onclick="activateSmsAppareil('${appareil.id_appareil}')">
                            <div class="flex items-center gap-3">
                                <div class="device-icon ${isActive ? '' : 'inactive'}">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-800">${escapedName}</p>
                                    <p class="text-xs text-gray-500">ID: ${escapeHtml(appareil.device_id)}</p>
                                    <p class="text-xs text-gray-400">Créé le ${appareil.created_at}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium px-2 py-1 rounded-full ${isActive ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500'}">
                                    ${isActive ? 'Actif' : 'Inactif'}
                                </span>
                                <button data-appareil-id="${appareil.id_appareil}" 
                                        data-appareil-name="${escapedName}"
                                        class="btn-sm btn-sm-danger delete-device-btn">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
            }
            
            html += `
                    </div>
                </div>
                <div class="border-t pt-4 mt-2">
                    <button onclick="openSmsApiModal()" 
                            class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition flex items-center justify-center gap-2">
                        <i class="fas fa-plus-circle"></i> Ajouter un appareil
                    </button>
                    <p class="text-xs text-gray-500 mt-2 text-center">
                        <i class="fas fa-info-circle"></i> Connectez-vous à l'API SMS pour récupérer vos appareils
                    </p>
                </div>
            `;
            
            container.innerHTML = html;
            footer.innerHTML = `
                <button onclick="closeSessionModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Fermer
                </button>
            `;
            
            document.querySelectorAll('.delete-device-btn').forEach(btn => {
                btn.addEventListener('click', function(event) {
                    event.stopPropagation();
                    const appareilId = this.getAttribute('data-appareil-id');
                    const appareilName = this.getAttribute('data-appareil-name') || 'cet appareil';
                    deleteSmsAppareil(appareilId, appareilName);
                });
            });
            
        } else {
            container.innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-exclamation-circle text-3xl text-red-500"></i>
                    <p class="text-gray-600 mt-2">Erreur: ${escapeHtml(result.error || 'Impossible de charger les appareils')}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Erreur:', error);
        container.innerHTML = `
            <div class="text-center py-8">
                <i class="fas fa-exclamation-circle text-3xl text-red-500"></i>
                <p class="text-gray-600 mt-2">Erreur réseau: ${escapeHtml(error.message)}</p>
            </div>
        `;
    }
}

async function activateSmsAppareil(appareilId) {
    try {
        showToast('Activation de l\'appareil...', 'info');
        
        const formData = new FormData();
        formData.append('action_activate_sms_appareil', '1');
        formData.append('id_compte', clientId);
        formData.append('appareil_id', appareilId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            await loadSmsAppareils();
        } else {
            showToast(result.error || 'Erreur lors de l\'activation', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

function deleteSmsAppareil(appareilId, appareilName) {
    const cleanName = (appareilName || 'cet appareil').replace(/['"\\]/g, '');
    
    const modalHtml = `
        <div id="deleteConfirmModal" class="modal-overlay" style="display: flex;">
            <div class="modal-card" style="max-width: 400px;" onclick="event.stopPropagation()">
                <div class="flex justify-between items-center mb-4">
                    <div class="flex items-center">
                        <div class="bg-red-100 p-2 rounded-full mr-3">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-800">Confirmer la suppression</h3>
                    </div>
                    <button onclick="closeDeleteConfirmModal()" class="modal-close-btn">&times;</button>
                </div>
                
                <p class="text-gray-600 mb-4">
                    Êtes-vous sûr de vouloir supprimer l'appareil <strong>${escapeHtml(cleanName)}</strong> ?
                </p>
                <p class="text-sm text-red-600 mb-4">Cette action est irréversible.</p>
                
                <div class="flex justify-end gap-2">
                    <button onclick="closeDeleteConfirmModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                        Annuler
                    </button>
                    <button onclick="confirmDeleteAppareil('${appareilId}')" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition">
                        <i class="fas fa-trash-alt mr-2"></i>Supprimer
                    </button>
                </div>
            </div>
        </div>
    `;
    
    const oldModal = document.getElementById('deleteConfirmModal');
    if (oldModal) oldModal.remove();
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeDeleteConfirmModal() {
    const modal = document.getElementById('deleteConfirmModal');
    if (modal) modal.remove();
}

async function confirmDeleteAppareil(appareilId) {
    closeDeleteConfirmModal();
    
    try {
        showToast('Suppression en cours...', 'info');
        
        const formData = new FormData();
        formData.append('action_delete_sms_appareil', '1');
        formData.append('appareil_id', appareilId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            await loadSmsAppareils();
        } else {
            showToast(result.error || 'Erreur lors de la suppression', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

function openSmsApiModal() {
    document.getElementById('smsApiModal').style.display = 'flex';
    document.getElementById('api_username').value = '';
    document.getElementById('api_password').value = '';
}

function closeSmsApiModal() {
    document.getElementById('smsApiModal').style.display = 'none';
}

function openSmsDeviceModal(devices) {
    const container = document.getElementById('deviceListContainer');
    container.innerHTML = '';
    
    if (devices.length === 0) {
        container.innerHTML = `
            <div class="text-center text-gray-500 py-4">
                <i class="fas fa-info-circle mb-2"></i>
                <p>Aucun appareil trouvé</p>
                <p class="text-sm mt-1">Vérifiez vos identifiants ou créez un appareil sur l'API SMS</p>
            </div>
        `;
    } else {
        devices.forEach(device => {
            const div = document.createElement('div');
            div.className = 'device-item';
            div.innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="device-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <div>
                        <p class="font-medium text-gray-800">${escapeHtml(device.name || 'Appareil')}</p>
                        <p class="text-xs text-gray-500">ID: ${escapeHtml(device.id)}</p>
                    </div>
                </div>
                <i class="fas fa-chevron-right text-gray-400"></i>
            `;
            div.onclick = () => saveSmsAppareil(device.id, device.name || 'Appareil');
            container.appendChild(div);
        });
    }
    
    document.getElementById('smsDeviceModal').style.display = 'flex';
}

function closeSmsDeviceModal() {
    document.getElementById('smsDeviceModal').style.display = 'none';
}

async function saveSmsAppareil(deviceId, deviceName) {
    try {
        showToast('Enregistrement de l\'appareil...', 'info');
        
        const formData = new FormData();
        formData.append('action_save_sms_appareil', '1');
        formData.append('id_compte', clientId);
        formData.append('device_id', deviceId);
        formData.append('device_name', deviceName);
        formData.append('api_username', currentApiUsername);
        formData.append('api_password', currentApiPassword);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            closeSmsDeviceModal();
            await loadSmsAppareils();
        } else {
            showToast(result.error || 'Erreur lors de l\'enregistrement', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// --- GESTIONNAIRE DE FORMULAIRE SMS ---
document.getElementById('smsLoginForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const api_username = document.getElementById('api_username').value.trim();
    const api_password = document.getElementById('api_password').value.trim();
    
    if (!api_username || !api_password) {
        showToast('Veuillez entrer vos identifiants', 'error');
        return;
    }
    
    currentApiUsername = api_username;
    currentApiPassword = api_password;
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Connexion...';
    submitBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action_fetch_sms_devices', '1');
        formData.append('api_username', api_username);
        formData.append('api_password', api_password);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const responseText = await response.text();
        console.log('Réponse brute:', responseText);
        
        if (responseText.trim().startsWith('<!DOCTYPE') || responseText.trim().startsWith('<html')) {
            console.error('Réponse HTML reçue:', responseText);
            let errorMsg = 'Erreur serveur. Vérifiez les logs.';
            const errorMatch = responseText.match(/Fatal error: ([^<]+)/);
            if (errorMatch) {
                errorMsg = 'Erreur PHP: ' + errorMatch[1];
            } else if (responseText.includes('Warning')) {
                const warningMatch = responseText.match(/Warning: ([^<]+)/);
                if (warningMatch) {
                    errorMsg = 'Avertissement PHP: ' + warningMatch[1];
                }
            }
            throw new Error(errorMsg);
        }
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('Erreur de parsing JSON:', parseError);
            throw new Error('La réponse du serveur n\'est pas du JSON valide. Vérifiez les logs.');
        }
        
        if (result.success && result.devices) {
            if (Array.isArray(result.devices) && result.devices.length > 0) {
                closeSmsApiModal();
                openSmsDeviceModal(result.devices);
            } else {
                showToast('Aucun appareil trouvé pour ce compte', 'warning');
            }
        } else {
            showToast(result.error || 'Erreur lors de la récupération des appareils', 'error');
        }
    } catch (error) {
        console.error('Erreur détaillée:', error);
        showToast('Erreur: ' + error.message, 'error');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// FONCTIONS WHATSAPP
// ============================================

// --- Supprimer une session WhatsApp ---
function deleteWhatsAppSession(sessionId, sessionName) {
    showConfirmModal(
        `Êtes-vous sûr de vouloir supprimer la session <strong>${escapeHtml(sessionName)}</strong> ?<br><span class="text-sm text-red-600">Cette action est irréversible.</span>`,
        async () => {
            try {
                showToast('Suppression en cours...', 'info');
                
                const formData = new FormData();
                formData.append('action_delete_whatsapp_session', '1');
                formData.append('id_compte', clientId);
                formData.append('session_id', sessionId);
                formData.append('session_name', sessionName);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    // Recharger la liste des sessions
                    await loadWhatsAppSessions();
                } else {
                    showToast(result.error || 'Erreur lors de la suppression', 'error');
                }
            } catch (error) {
                console.error('Erreur:', error);
                showToast('Erreur réseau: ' + error.message, 'error');
            }
        }
    );
}

async function checkSessionStatus(sessionName) {
    try {
        const formData = new FormData();
        formData.append('action_check_session_status', '1');
        formData.append('nom_session', sessionName);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            const isConnected = result.isConnected;
            const status = result.status || 'UNKNOWN';
            
            updateSessionStatusUI(sessionName, status, isConnected, result.data || {});
            
            if (isConnected) {
                stopStatusPolling();
                stopWaitingTimer();
                closeCodeModal();
                updateSessionInDatabase(sessionName, true);
                updateSessionStatusOnly(sessionName, true);
            }
        }
    } catch (error) {
        console.error('Erreur:', error);
    }
}

function startStatusPolling(sessionName) {
    stopStatusPolling();
    currentSessionName = sessionName;
    isPolling = true;
    connectionCheckCount = 0;
    checkSessionStatus(sessionName);
    statusPollingInterval = setInterval(function() {
        connectionCheckCount++;
        checkSessionStatus(sessionName);
        if (connectionCheckCount >= 24) {
            stopStatusPolling();
            showToast('⏰ Délai dépassé. Vérifiez manuellement.', 'info');
        }
    }, 5000);
}

function startWaitingAfterCode(sessionName) {
    stopWaitingTimer();
    waitingSeconds = 60;
    const progressBar = document.getElementById('progressBar');
    const waitingSecondsSpan = document.getElementById('waitingSeconds');
    const waitingDiv = document.getElementById('waitingProgress');
    waitingDiv.style.display = 'block';
    progressBar.style.width = '0%';
    waitingSecondsSpan.textContent = waitingSeconds;
    
    waitingInterval = setInterval(function() {
        waitingSeconds--;
        if (waitingSeconds <= 0) {
            waitingSeconds = 0;
            waitingSecondsSpan.textContent = '0';
            clearInterval(waitingInterval);
            waitingInterval = null;
            checkSessionStatusAfterWait(sessionName);
            return;
        }
        waitingSecondsSpan.textContent = waitingSeconds;
        const progress = ((60 - waitingSeconds) / 60) * 100;
        progressBar.style.width = progress + '%';
    }, 2000);
    
    startStatusPolling(sessionName);
}

function stopWaitingTimer() {
    if (waitingInterval) {
        clearInterval(waitingInterval);
        waitingInterval = null;
    }
    document.getElementById('waitingProgress').style.display = 'none';
}

async function checkSessionStatusAfterWait(sessionName) {
    try {
        const formData = new FormData();
        formData.append('action_check_session_status', '1');
        formData.append('nom_session', sessionName);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            const isConnected = result.isConnected;
            
            if (isConnected) {
                stopStatusPolling();
                stopWaitingTimer();
                closeCodeModal();
                updateSessionInDatabase(sessionName, true);
                showToast('✅ Session connectée avec succès ! 🎉', 'success');
                playNotificationSound();
                updateSessionStatusOnly(sessionName, true);
            } else {
                showToast('⏳ La session n\'est pas encore connectée. Vérifiez le code.', 'info');
            }
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur lors de la vérification: ' + error.message, 'error');
    }
}

function updateSessionStatusOnly(sessionName, isConnected) {
    const sessionItems = document.querySelectorAll('.session-list-item');
    let found = false;
    
    sessionItems.forEach(item => {
        const nameElement = item.querySelector('.session-name');
        if (nameElement && nameElement.textContent.trim() === sessionName) {
            found = true;
            
            let statusBadge = item.querySelector('.session-status-badge');
            if (!statusBadge) {
                statusBadge = document.createElement('span');
                statusBadge.className = 'session-status-badge';
                const actionsDiv = item.querySelector('.flex.items-center.gap-2');
                if (actionsDiv) {
                    actionsDiv.prepend(statusBadge);
                }
            }
            
            if (isConnected) {
                statusBadge.className = 'session-status-badge working';
                statusBadge.textContent = '✅ Connecté';
                item.classList.add('working');
                item.classList.remove('scanning', 'failed', 'stopped');
                const connectBtn = item.querySelector('.btn-sm-whatsapp');
                if (connectBtn) {
                    connectBtn.style.display = 'none';
                }
                const icon = item.querySelector('.session-icon');
                if (icon) {
                    icon.classList.remove('inactive');
                }
            } else {
                statusBadge.className = 'session-status-badge inactive';
                statusBadge.textContent = '🔗 Connecter';
                item.classList.remove('working');
                const connectBtn = item.querySelector('.btn-sm-whatsapp');
                if (connectBtn) {
                    connectBtn.style.display = 'inline-flex';
                }
                const icon = item.querySelector('.session-icon');
                if (icon) {
                    icon.classList.add('inactive');
                }
            }
        }
    });
    
    if (!found) {
        loadWhatsAppSessions();
    }
}

function updateSessionStatusUI(sessionName, status, isConnected, data) {
    updateSessionStatusOnly(sessionName, isConnected);
}

async function updateSessionInDatabase(sessionName, isConnected) {
    try {
        const formData = new FormData();
        formData.append('action_update_session_status', '1');
        formData.append('nom_session', sessionName);
        formData.append('est_active', isConnected ? '1' : '0');
        await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
    } catch (error) {
        console.error('Erreur mise à jour base:', error);
    }
}

function stopStatusPolling() {
    if (statusPollingInterval) {
        clearInterval(statusPollingInterval);
        statusPollingInterval = null;
    }
    isPolling = false;
    currentSessionName = '';
    stopWaitingTimer();
}

function playNotificationSound() {
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator1 = audioContext.createOscillator();
        const gainNode1 = audioContext.createGain();
        oscillator1.connect(gainNode1);
        gainNode1.connect(audioContext.destination);
        oscillator1.frequency.value = 800;
        oscillator1.type = 'sine';
        gainNode1.gain.setValueAtTime(0.3, audioContext.currentTime);
        gainNode1.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
        oscillator1.start(audioContext.currentTime);
        oscillator1.stop(audioContext.currentTime + 0.3);
        setTimeout(() => {
            const oscillator2 = audioContext.createOscillator();
            const gainNode2 = audioContext.createGain();
            oscillator2.connect(gainNode2);
            gainNode2.connect(audioContext.destination);
            oscillator2.frequency.value = 1000;
            oscillator2.type = 'sine';
            gainNode2.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode2.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
            oscillator2.start(audioContext.currentTime);
            oscillator2.stop(audioContext.currentTime + 0.3);
        }, 200);
    } catch (error) {}
}

async function loadWhatsAppSessions() {
    const container = document.getElementById('sessionContent');
    const footer = document.getElementById('sessionFooter');
    
    try {
        const formData = new FormData();
        formData.append('action_get_whatsapp_sessions', '1');
        formData.append('id_compte', clientId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            let html = `
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Sessions WhatsApp disponibles</label>
                    <div class="space-y-2 max-h-60 overflow-y-auto">
            `;
            
            if (result.sessions.length === 0) {
                html += `
                    <div class="text-center text-gray-500 py-4">
                        <i class="fas fa-info-circle mb-2"></i>
                        <p>Aucune session configurée</p>
                        <p class="text-sm mt-1">Créez une nouvelle session ci-dessous</p>
                    </div>
                `;
            } else {
                result.sessions.forEach(session => {
                    const isActive = session.est_active;
                    const statusLabel = isActive ? '✅ Connecté' : '🔗 Connecter';
                    const statusClass = isActive ? 'working' : 'inactive';
                    const itemClass = isActive ? 'working' : '';
                    const connectBtnStyle = isActive ? 'display: none;' : '';
                    
                    html += `
                        <div class="session-list-item ${itemClass}" data-session="${escapeHtml(session.nom_session)}">
                            <div class="flex items-center gap-3 flex-1">
                                <div class="session-icon ${isActive ? '' : 'inactive'}">
                                    <i class="fab fa-whatsapp"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-800 session-name">${escapeHtml(session.nom_session)}</p>
                                    <p class="text-xs text-gray-500">Créée le ${session.created_at}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="session-status-badge ${statusClass}">
                                    ${statusLabel}
                                </span>
                                <button onclick="checkSessionStatus('${escapeHtml(session.nom_session)}')" 
                                        class="btn-sm btn-sm-info" title="Vérifier le statut">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <button onclick="connectSession('${escapeHtml(session.nom_session)}')" 
                                        class="btn-sm btn-sm-whatsapp" title="Connecter la session" style="${connectBtnStyle}">
                                    <i class="fas fa-link"></i> Connecter
                                </button>
                                <button onclick="deleteWhatsAppSession('${session.id_session}', '${escapeHtml(session.nom_session)}')" 
                                        class="btn-sm btn-sm-danger" title="Supprimer la session">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
            }
            
            html += `
                    </div>
                </div>
                <div class="border-t pt-4 mt-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Créer une nouvelle session</label>
                    <div class="flex gap-2">
                        <input type="text" id="newSessionName" placeholder="Nom de la session..." 
                               class="flex-1 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500">
                        <button onclick="createWhatsAppSession()" 
                                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition flex items-center gap-2">
                            <i class="fas fa-plus"></i> Créer
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        <i class="fas fa-info-circle"></i> La session sera automatiquement créée sur le serveur Waha.
                    </p>
                </div>
            `;
            
            container.innerHTML = html;
            footer.innerHTML = `
                <button onclick="closeSessionModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Fermer
                </button>
            `;
            
            document.getElementById('newSessionName').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    createWhatsAppSession();
                }
            });
        } else {
            container.innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-exclamation-circle text-3xl text-red-500"></i>
                    <p class="text-gray-600 mt-2">Erreur: ${escapeHtml(result.error || 'Impossible de charger les sessions')}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Erreur:', error);
        container.innerHTML = `
            <div class="text-center py-8">
                <i class="fas fa-exclamation-circle text-3xl text-red-500"></i>
                <p class="text-gray-600 mt-2">Erreur réseau: ${escapeHtml(error.message)}</p>
            </div>
        `;
    }
}

async function createWhatsAppSession() {
    const nomSession = document.getElementById('newSessionName').value.trim();
    const btn = document.querySelector('#sessionContent .bg-green-600');
    const originalText = btn.innerHTML;
    
    if (!nomSession) {
        showToast('Veuillez entrer un nom de session', 'error');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Création...';
    
    try {
        const formData = new FormData();
        formData.append('action_create_whatsapp_session', '1');
        formData.append('id_compte', clientId);
        formData.append('nom_session', nomSession);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, result.waha ? 'success' : 'info');
            await loadWhatsAppSessions();
            document.getElementById('newSessionName').value = '';
        } else {
            showToast(result.error || 'Erreur lors de la création', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function connectSession(sessionName) {
    showToast('🔄 Connexion de la session en cours...', 'info');
    
    try {
        const restartFormData = new FormData();
        restartFormData.append('action_restart_whatsapp_session', '1');
        restartFormData.append('nom_session', sessionName);
        
        const restartResponse = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: restartFormData
        });
        
        const restartResult = await restartResponse.json();
        
        if (!restartResult.success) {
            showToast('Erreur: ' + (restartResult.error || 'Redémarrage échoué'), 'error');
            return;
        }
        
        showToast('✅ Session redémarrée', 'success');
        
        let phoneNumber = document.getElementById('edit_telephone')?.value || '';
        if (!phoneNumber) {
            const num = prompt('📱 Entrez le numéro de téléphone du client (sans le +) :');
            if (!num) {
                showToast('Numéro requis pour la connexion', 'error');
                return;
            }
            phoneNumber = num;
        }
        
        await requestCode(sessionName, phoneNumber);
        startWaitingAfterCode(sessionName);
    } catch (error) {
        console.error('Erreur:', error);
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

async function requestCode(sessionName, phoneNumber) {
    const codeDisplay = document.getElementById('codeDisplay');
    const codeModal = document.getElementById('codeModal');
    
    document.getElementById('codeSessionName').textContent = sessionName;
    codeModal.style.display = 'flex';
    codeDisplay.textContent = 'Demande en cours...';
    
    try {
        const formData = new FormData();
        formData.append('action_request_code', '1');
        formData.append('nom_session', sessionName);
        formData.append('phone_number', phoneNumber);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            codeDisplay.textContent = result.code || 'N/A';
            showToast('✅ Code d\'appairage obtenu !', 'success');
        } else {
            codeDisplay.textContent = '❌ Erreur';
            showToast(result.error || 'Erreur lors de la demande du code', 'error');
        }
    } catch (error) {
        console.error('Erreur:', error);
        codeDisplay.textContent = '❌ Erreur réseau';
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

function closeCodeModal() {
    document.getElementById('codeModal').style.display = 'none';
    stopWaitingTimer();
}

function copyCode() {
    const code = document.getElementById('codeDisplay').textContent;
    if (code && code !== 'Demande en cours...' && code !== '❌ Erreur' && code !== '❌ Erreur réseau') {
        navigator.clipboard.writeText(code).then(() => {
            showToast('Code copié !', 'success');
        }).catch(() => {
            const textarea = document.createElement('textarea');
            textarea.value = code;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            showToast('Code copié !', 'success');
        });
    } else {
        showToast('Aucun code valide à copier', 'error');
    }
}

function togglePassword(inputId, buttonElement) {
    const passwordInput = document.getElementById(inputId);
    const icon = buttonElement.querySelector('i');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// ============================================
// FONCTIONS UTILITAIRES
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// MODALE DE CONFIRMATION GÉNÉRIQUE
// ============================================
function showConfirmModal(message, callback) {
    document.getElementById('confirmMessage').innerHTML = message;
    document.getElementById('confirmModal').style.display = 'flex';
    confirmCallback = callback;
}

function closeConfirmModal() {
    document.getElementById('confirmModal').style.display = 'none';
    confirmCallback = null;
}

document.getElementById('confirmActionBtn').addEventListener('click', function() {
    if (typeof confirmCallback === 'function') {
        confirmCallback();
    }
    closeConfirmModal();
});

// ============================================
// FERMETURE DES MODALES
// ============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRechargeModal();
        closeAssociateModal();
        closeDetachConfirmModal();
        closeSessionModal();
        closeCodeModal();
        closeSmsApiModal();
        closeSmsDeviceModal();
        closeConfirmModal();
        stopWaitingTimer();
    }
});

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            if (this.id === 'rechargeModal') closeRechargeModal();
            else if (this.id === 'associateProviderModal') closeAssociateModal();
            else if (this.id === 'detachConfirmModal') closeDetachConfirmModal();
            else if (this.id === 'sessionModal') closeSessionModal();
            else if (this.id === 'codeModal') { closeCodeModal(); stopWaitingTimer(); }
            else if (this.id === 'smsApiModal') closeSmsApiModal();
            else if (this.id === 'smsDeviceModal') closeSmsDeviceModal();
            else if (this.id === 'confirmModal') closeConfirmModal();
        }
    });
});

document.getElementById('rechargeAmount').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        confirmRecharge();
    }
});
</script>
</body>
</html>