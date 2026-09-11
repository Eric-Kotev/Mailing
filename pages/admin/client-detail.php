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
// LISTMONK FUNCTIONS 
// ============================================

function generateUuidV4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function normalizeBaseUrl($url) {
    return rtrim(trim($url), '/');
}

function authHeader($username, $password) {
    return 'Basic ' . base64_encode($username . ':' . $password);
}

function callListmonk($conn, $options) {
    $baseUrl = normalizeBaseUrl($conn['baseUrl']);
    $path = $options['path'] ?? '';
    $method = $options['method'] ?? 'GET';
    $headers = $options['headers'] ?? [];
    $body = $options['body'] ?? null;
    
    $url = $baseUrl . $path;
    
    $ch = curl_init($url);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $allHeaders = [
        'Authorization: ' . authHeader($conn['username'], $conn['password']),
        'Accept: application/json'
    ];
    
    foreach ($headers as $key => $value) {
        $allHeaders[] = $key . ': ' . $value;
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
    
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('Erreur de connexion à Listmonk: ' . $curlError);
    }
    
    $parsed = null;
    if (!empty($response)) {
        $parsed = json_decode($response, true);
        if ($parsed === null && json_last_error() !== JSON_ERROR_NONE) {
            $parsed = null;
        }
    }
    
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = '';
        if ($parsed && isset($parsed['message'])) {
            $message = $parsed['message'];
        } elseif (!empty($response)) {
            $message = substr($response, 0, 300);
        } else {
            $message = 'HTTP ' . $httpCode;
        }
        throw new Exception('Listmonk ' . $httpCode . ': ' . $message);
    }
    
    return $parsed;
}

function getListmonkSettings($conn) {
    $json = callListmonk($conn, ['path' => '/api/settings', 'method' => 'GET']);
    
    $settings = $json['data'] ?? null;
    if (!$settings || !is_array($settings)) {
        throw new Exception('Réponse inattendue de Listmonk : champ "data" absent.');
    }
    
    $smtp = isset($settings['smtp']) && is_array($settings['smtp']) ? $settings['smtp'] : [];
    
    $smtpList = [];
    foreach ($smtp as $i => $s) {
        $smtpList[] = [
            'index' => $i,
            'name' => $s['name'] ?? '',
            'host' => $s['host'] ?? '',
            'port' => $s['port'] ?? 0,
            'enabled' => isset($s['enabled']) && $s['enabled'] === true,
            'username' => $s['username'] ?? '',
        ];
    }
    
    return [
        'smtp' => $smtpList,
        'smtpCount' => count($smtp),
        'settingsKeys' => count(array_keys($settings)),
        'rawSettings' => $settings
    ];
}

function addSmtpToListmonk($conn, $smtp, $replaceExistingByName = true) {
    $settingsData = getListmonkSettings($conn);
    $settings = $settingsData['rawSettings'];
    
    if (!$settings || !is_array($settings)) {
        throw new Exception("Impossible de lire les settings Listmonk (champ 'data' absent).");
    }
    
    $current = isset($settings['smtp']) && is_array($settings['smtp']) ? $settings['smtp'] : [];
    
    $existingIdx = -1;
    $smtpNameLower = strtolower($smtp['name']);
    foreach ($current as $idx => $s) {
        $currentName = isset($s['name']) ? strtolower($s['name']) : '';
        if ($currentName === $smtpNameLower) {
            $existingIdx = $idx;
            break;
        }
    }
    
    $action = 'added';
    if ($existingIdx >= 0 && $replaceExistingByName) {
        if (isset($current[$existingIdx]['uuid'])) {
            $smtp['uuid'] = $current[$existingIdx]['uuid'];
        } else {
            $smtp['uuid'] = generateUuidV4();
        }
        $current[$existingIdx] = $smtp;
        $action = 'replaced';
    } else {
        $smtp['uuid'] = generateUuidV4();
        $current[] = $smtp;
        $action = 'added';
    }
    
    $hasEnabled = false;
    foreach ($current as $s) {
        if (isset($s['enabled']) && $s['enabled'] === true) {
            $hasEnabled = true;
            break;
        }
    }
    if (!$hasEnabled) {
        throw new Exception("Au moins un serveur SMTP doit être activé.");
    }
    
    $sanitized = [];
    foreach ($current as $s) {
        $sanitizedItem = $s;
        if (isset($sanitizedItem['password']) && is_string($sanitizedItem['password'])) {
            if (preg_match('/^[•*]+$/', $sanitizedItem['password'])) {
                $sanitizedItem['password'] = '';
            }
        }
        $sanitized[] = $sanitizedItem;
    }
    
    $payload = $settings;
    $payload['smtp'] = $sanitized;
    
    $jsonData = json_encode($payload);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Erreur de génération JSON: ' . json_last_error_msg());
    }
    
    callListmonk($conn, [
        'path' => '/api/settings',
        'method' => 'PUT',
        'headers' => ['Content-Type' => 'application/json'],
        'body' => $jsonData
    ]);
    
    $names = array_map(function($s) {
        return $s['name'] ?? '';
    }, $sanitized);
    
    return [
        'action' => $action,
        'smtpCount' => count($sanitized),
        'names' => $names
    ];
}

function deleteSmtpFromListmonk($conn, $name) {
    $settingsData = getListmonkSettings($conn);
    $settings = $settingsData['rawSettings'];
    
    if (!$settings || !is_array($settings)) {
        throw new Exception("Impossible de lire les settings Listmonk.");
    }
    
    $current = isset($settings['smtp']) && is_array($settings['smtp']) ? $settings['smtp'] : [];
    
    $nameLower = strtolower($name);
    $newSmtp = array_filter($current, function($s) use ($nameLower) {
        $currentName = isset($s['name']) ? strtolower($s['name']) : '';
        return $currentName !== $nameLower;
    });
    
    $newSmtp = array_values($newSmtp);
    
    if (count($newSmtp) === count($current)) {
        throw new Exception("Aucun serveur SMTP nommé '$name' trouvé");
    }
    
    $hasEnabled = false;
    foreach ($newSmtp as $s) {
        if (isset($s['enabled']) && $s['enabled'] === true) {
            $hasEnabled = true;
            break;
        }
    }
    if (!$hasEnabled && count($newSmtp) > 0) {
        $newSmtp[0]['enabled'] = true;
    }
    
    $payload = $settings;
    $payload['smtp'] = $newSmtp;
    
    $jsonData = json_encode($payload);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Erreur de génération JSON: ' . json_last_error_msg());
    }
    
    callListmonk($conn, [
        'path' => '/api/settings',
        'method' => 'PUT',
        'headers' => ['Content-Type' => 'application/json'],
        'body' => $jsonData
    ]);
    
    return [
        'success' => true,
        'smtpCount' => count($newSmtp),
        'deleted' => $name
    ];
}

function removeSmtpFromListmonk($conn, $name) {
    return deleteSmtpFromListmonk($conn, $name);
}

function getDefaultListmonkConn() {
    return [
        'baseUrl' => 'http://164.68.103.147:9005',
        'username' => 'test',
        'password' => 'lqXJrA1sfE1YobhQ0CyP9UiMpi1MOsb83p554Uuc1IRDKVRR'
    ];
}

function extractClientId($input) {
    if (is_string($input) && !empty($input)) {
        return trim($input);
    }
    
    if (is_array($input)) {
        if (isset($input['id_compte'])) {
            return trim($input['id_compte']);
        }
        if (isset($input['id'])) {
            return trim($input['id']);
        }
        if (!empty($input)) {
            $first = reset($input);
            return trim($first);
        }
        return '';
    }
    
    if (is_object($input)) {
        if (isset($input->id_compte)) {
            return trim($input->id_compte);
        }
        if (isset($input->id)) {
            return trim($input->id);
        }
        return '';
    }
    
    return trim((string)$input);
}

// ============================================
// GESTION DES OPÉRATEURS ASSOCIÉS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_associate_provider'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $providerId = intval($_POST['id_provider'] ?? 0);
        $estActif = isset($_POST['est_actif']) && $_POST['est_actif'] === 'true';
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if ($providerId <= 0) {
            throw new Exception('ID opérateur invalide');
        }
        
        $provider = $db->select('provider', ['id_provider' => $providerId]);
        if (empty($provider)) {
            throw new Exception('Opérateur non trouvé');
        }
        
        $existing = $db->select('client_provider', [
            'id_compte' => $clientId,
            'id_provider' => $providerId
        ]);
        
        if (!empty($existing)) {
            throw new Exception('Cet opérateur est déjà associé à ce client');
        }
        
        $data = [
            'id_compte' => $clientId,
            'id_provider' => $providerId,
            'est_actif' => $estActif,
            'date_association' => date('Y-m-d H:i:s')
        ];
        
        $result = $db->insert('client_provider', $data);
        
        if ($result) {
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
                    'min_tarif' => $providerInfo['min_tarif'] ?? 0,
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_detach_provider'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $idClientProvider = intval($_POST['id_client_provider'] ?? 0);
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if ($idClientProvider <= 0) {
            throw new Exception('ID association invalide');
        }
        
        $existing = $db->select('client_provider', [
            'id_client_provider' => $idClientProvider,
            'id_compte' => $clientId
        ]);
        
        if (empty($existing)) {
            throw new Exception('Association non trouvée');
        }
        
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_toggle_provider_status'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $idClientProvider = intval($_POST['id_client_provider'] ?? 0);
        $nouveauStatut = $_POST['est_actif'] === 'true';
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if ($idClientProvider <= 0) {
            throw new Exception('ID association invalide');
        }
        
        $existing = $db->select('client_provider', [
            'id_client_provider' => $idClientProvider,
            'id_compte' => $clientId
        ]);
        
        if (empty($existing)) {
            throw new Exception('Association non trouvée');
        }
        
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
// GESTION DES TARIFS PERSONNALISÉS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_client_tarif'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $providerId = intval($_POST['id_provider'] ?? 0);
        $nouveauPrix = floatval($_POST['prix'] ?? 0);
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if ($providerId <= 0) {
            throw new Exception('ID opérateur invalide');
        }
        if ($nouveauPrix < 0) {
            throw new Exception('Le prix ne peut pas être négatif');
        }

        // Vérifier le tarif minimum du provider
        $provider = $db->select('provider', ['id_provider' => $providerId]);
        if (empty($provider)) {
            throw new Exception('Opérateur non trouvé');
        }

        $minTarif = floatval($provider[0]['min_tarif'] ?? 0);
        if ($nouveauPrix < $minTarif) {
            throw new Exception('Le tarif ne peut pas être inférieur au tarif minimum (' . number_format($minTarif, 3) . ' €)');
        }

        $association = $db->select('client_provider', [
            'id_compte' => $clientId,
            'id_provider' => $providerId
        ]);
        
        if (empty($association)) {
            throw new Exception('Cet opérateur n\'est pas associé à ce client');
        }
        
        $existingTarif = $db->select('tarif', [
            'id_compte' => $clientId,
            'id_provider' => $providerId
        ]);
        
        if (!empty($existingTarif)) {
            $result = $db->update('tarif', 
                ['prix' => $nouveauPrix],
                ['id_tarif' => $existingTarif[0]['id_tarif']]
            );
            $action = 'updated';
        } else {
            $data = [
                'id_compte' => $clientId,
                'id_provider' => $providerId,
                'prix' => $nouveauPrix,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $result = $db->insert('tarif', $data);
            $action = 'created';
        }
        
        if ($result !== false) {
            echo json_encode([
                'success' => true,
                'message' => 'Tarif mis à jour avec succès',
                'action' => $action,
                'prix' => number_format($nouveauPrix, 3)
            ]);
        } else {
            throw new Exception('Erreur lors de la mise à jour du tarif');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_get_whatsapp_sessions'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_whatsapp_session'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $sessionId = trim($_POST['session_id'] ?? '');
        $sessionName = trim($_POST['session_name'] ?? '');
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (empty($sessionId)) {
            throw new Exception('ID de session requis');
        }
        
        $session = $db->select('whatsapp_sessions', [
            'id_session' => $sessionId,
            'id_compte' => $clientId
        ]);
        
        if (empty($session)) {
            throw new Exception('Session non trouvée');
        }
        
        $sessionName = $session[0]['nom_session'];
        $isActive = $session[0]['est_active'];
        
        $allSessions = $db->select('whatsapp_sessions', ['id_compte' => $clientId]);
        
        if ($isActive && count($allSessions) > 1) {
            foreach ($allSessions as $s) {
                if ($s['id_session'] !== $sessionId) {
                    $db->update('whatsapp_sessions', ['est_active' => true], ['id_session' => $s['id_session']]);
                    break;
                }
            }
        }
        
        $result = $db->delete('whatsapp_sessions', $sessionId, 'id_session');
        
        if ($result) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_get_sms_appareils'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
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
                'device_number' => $appareil['device_number'] ?? '',
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_sms_appareil'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $device_id = trim($_POST['device_id'] ?? '');
        $device_name = trim($_POST['device_name'] ?? '');
        $device_number = trim($_POST['device_number'] ?? '');
        // Nettoyer le numéro (garder uniquement les chiffres, +, espaces, tirets)
        $device_number = preg_replace('/[^0-9+\s\-()]/', '', $device_number);
        // Limiter la longueur
        if (strlen($device_number) > 20) {
            $device_number = substr($device_number, 0, 20);
        }
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
                'device_number' => $device_number ?: null,
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
                'device_number' => $device_number ?: null,   
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
            'device_name' => $device_name ?: 'Appareil',
            'device_number' => $device_number ?: null,
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_whatsapp_session'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
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
        
        $wahaUrl = 'http://164.68.103.147:8081/api/controller.php/sessions/' . urlencode($nom_session) . '/restart';
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
        
        $wahaUrl = 'http://164.68.103.147:8081/api/controller.php/sessions/request-code';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_activate_sms_appareil'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
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
            $_SESSION['sms_device_number'] = $appareilInfo[0]['device_number'] ?? '';
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
// GESTION DES COMPTES EMAIL
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_get_email_accounts'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        
        $accounts = $db->select('email_accounts', ['id_compte' => $clientId], '*', 'est_actif DESC, created_at DESC');
        
        $accountList = [];
        foreach ($accounts as $account) {
            $accountList[] = [
                'id_email_account' => $account['id_email_account'],
                'name' => $account['name'],
                'username' => $account['username'],
                'password' => $account['password'],
                'host' => $account['host'],
                'from_address' => $account['from_address'],
                'port' => $account['port'],
                'auth_protocol' => $account['auth_protocol'],
                'hello_hostname' => $account['hello_hostname'],
                'tls_type' => $account['tls_type'],
                'tls_skip_verify' => $account['tls_skip_verify'],
                'max_conns' => $account['max_conns'],
                'idle_timeout' => $account['idle_timeout'],
                'wait_timeout' => $account['wait_timeout'],
                'max_msg_retries' => $account['max_msg_retries'],
                'msg_retry_delay' => $account['msg_retry_delay'],
                'est_actif' => $account['est_actif'],
                'created_at' => date('d/m/Y H:i', strtotime($account['created_at']))
            ];
        }
        
        echo json_encode([
            'success' => true,
            'accounts' => $accountList
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR get_email_accounts: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_get_listmonk_settings'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $conn = getDefaultListmonkConn();
        $settings = getListmonkSettings($conn);
        
        echo json_encode([
            'success' => true,
            'smtp' => $settings['smtp'],
            'smtpCount' => $settings['smtpCount'],
            'settingsKeys' => $settings['settingsKeys']
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR get_listmonk_settings: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_smtp_server'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $from_address = trim($_POST['from_address'] ?? '');
        $replaceExisting = isset($_POST['replace_existing']) && $_POST['replace_existing'] === 'true';
        
        $host = trim($_POST['host'] ?? 'smtp.gmail.com');
        $port = intval($_POST['port'] ?? 465);
        $auth_protocol = trim($_POST['auth_protocol'] ?? 'login');
        $hello_hostname = trim($_POST['hello_hostname'] ?? '');
        $tls_type = trim($_POST['tls_type'] ?? 'TLS');
        $tls_skip_verify = isset($_POST['tls_skip_verify']) && $_POST['tls_skip_verify'] === 'true';
        $max_conns = intval($_POST['max_conns'] ?? 10);
        $idle_timeout = trim($_POST['idle_timeout'] ?? '15s');
        $wait_timeout = trim($_POST['wait_timeout'] ?? '5s');
        $max_msg_retries = intval($_POST['max_msg_retries'] ?? 2);
        $msg_retry_delay = trim($_POST['msg_retry_delay'] ?? '10ms');
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (empty($name)) {
            throw new Exception('Nom du compte requis');
        }
        if (empty($username)) {
            throw new Exception('Nom d\'utilisateur requis');
        }
        if (empty($password)) {
            throw new Exception('Mot de passe requis');
        }
        if (empty($from_address)) {
            throw new Exception('Adresse email expéditeur requise');
        }
        if (empty($host)) {
            throw new Exception('Hôte SMTP requis');
        }
        if ($port <= 0 || $port > 65535) {
            throw new Exception('Port SMTP invalide (1-65535)');
        }
        
        $conn = getDefaultListmonkConn();
        
        $nouveauBloc = [
            'enabled' => true,
            'host' => $host,
            'port' => $port,
            'auth_protocol' => $auth_protocol,
            'username' => $username,
            'password' => $password,
            'hello_hostname' => $hello_hostname,
            'tls_type' => $tls_type,
            'tls_skip_verify' => $tls_skip_verify,
            'max_conns' => $max_conns,
            'idle_timeout' => $idle_timeout,
            'wait_timeout' => $wait_timeout,
            'max_msg_retries' => $max_msg_retries,
            'msg_retry_delay' => $msg_retry_delay,
            'name' => $name,
            'email_headers' => [],
            'from_addresses' => [$from_address]
        ];
        
        $result = addSmtpToListmonk($conn, $nouveauBloc, $replaceExisting);
        
        $existing = $db->select('email_accounts', [
            'id_compte' => $clientId,
            'name' => $name
        ]);
        
        if (!empty($existing) && $replaceExisting) {
            $db->update('email_accounts', [
                'username' => $username,
                'password' => $password,
                'from_address' => $from_address,
                'host' => $host,
                'port' => $port,
                'auth_protocol' => $auth_protocol,
                'hello_hostname' => $hello_hostname,
                'tls_type' => $tls_type,
                'tls_skip_verify' => $tls_skip_verify,
                'max_conns' => $max_conns,
                'idle_timeout' => $idle_timeout,
                'wait_timeout' => $wait_timeout,
                'max_msg_retries' => $max_msg_retries,
                'msg_retry_delay' => $msg_retry_delay,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id_email_account' => $existing[0]['id_email_account']]);
            $accountId = $existing[0]['id_email_account'];
            $message = "Compte email mis à jour avec succès";
        } else {
            $accountData = [
                'id_compte' => $clientId,
                'name' => $name,
                'username' => $username,
                'password' => $password,
                'host' => $host,
                'from_address' => $from_address,
                'port' => $port,
                'auth_protocol' => $auth_protocol,
                'hello_hostname' => $hello_hostname,
                'tls_type' => $tls_type,
                'tls_skip_verify' => $tls_skip_verify,
                'max_conns' => $max_conns,
                'idle_timeout' => $idle_timeout,
                'wait_timeout' => $wait_timeout,
                'max_msg_retries' => $max_msg_retries,
                'msg_retry_delay' => $msg_retry_delay,
                'est_actif' => true,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $accountId = $db->insert('email_accounts', $accountData);
            $message = "Compte email créé avec succès";
        }
        
        if (!$accountId) {
            throw new Exception('Erreur lors de l\'enregistrement en base de données');
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message . ' (Action: ' . $result['action'] . ', ' . $result['smtpCount'] . ' serveurs)',
            'account_id' => $accountId,
            'name' => $name,
            'username' => $username,
            'from_address' => $from_address,
            'host' => $host,
            'port' => $port,
            'action' => $result['action'],
            'smtpCount' => $result['smtpCount']
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR add_smtp_server: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_smtp_server'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $accountId = trim($_POST['account_id'] ?? '');
        $accountName = trim($_POST['account_name'] ?? '');
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (empty($accountId)) {
            throw new Exception('ID compte invalide');
        }
        if (empty($accountName)) {
            throw new Exception('Nom du compte requis');
        }
        
        $existing = $db->select('email_accounts', [
            'id_email_account' => $accountId,
            'id_compte' => $clientId
        ]);
        
        if (empty($existing)) {
            throw new Exception('Compte non trouvé');
        }
        
        try {
            $conn = getDefaultListmonkConn();
            $deleteResult = deleteSmtpFromListmonk($conn, $accountName);
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la suppression dans Listmonk: ' . $e->getMessage());
        }
        
        $result = $db->delete('email_accounts', $accountId, 'id_email_account');
        
        if (!$result) {
            error_log("ERREUR: Suppression en base échouée pour account_id: $accountId");
            echo json_encode([
                'success' => true,
                'message' => 'Compte supprimé de Listmonk mais erreur en base de données. Veuillez contacter l\'administrateur.',
                'deleted' => $accountName,
                'warning' => true
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'Compte email supprimé avec succès de la base et de Listmonk',
                'deleted' => $accountName,
                'smtpCount' => $deleteResult['smtpCount']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("ERREUR delete_smtp_server: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_activate_email_account'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $accountId = trim($_POST['account_id'] ?? '');
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (empty($accountId)) {
            throw new Exception('ID compte invalide');
        }
        
        $account = $db->select('email_accounts', [
            'id_email_account' => $accountId,
            'id_compte' => $clientId
        ]);
        
        if (empty($account)) {
            throw new Exception('Compte non trouvé');
        }
        
        $db->update('email_accounts', ['est_actif' => false], ['id_compte' => $clientId]);
        $db->update('email_accounts', ['est_actif' => true], ['id_email_account' => $accountId]);
        
        $accountInfo = $db->select('email_accounts', ['id_email_account' => $accountId]);
        if (!empty($accountInfo)) {
            $_SESSION['email_account_name'] = $accountInfo[0]['name'];
            $_SESSION['email_username'] = $accountInfo[0]['username'];
            $_SESSION['email_from_address'] = $accountInfo[0]['from_address'];
            $_SESSION['email_host'] = $accountInfo[0]['host'];
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Compte email activé avec succès'
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR activate_email_account: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// GESTION DES CONFIGURATIONS OCTOPUSH
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_get_octopush_configs'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        
        $configs = $db->select('octopush_config', ['id_compte' => $clientId], '*', 'created_at DESC');
        
        $configList = [];
        foreach ($configs as $config) {
            $configList[] = [
                'id_config' => $config['id_config'],
                'nom_config' => $config['nom_config'],
                'api_login' => $config['api_login'],
                'api_key' => $config['api_key'],
                'sender_name' => $config['sender_name'],
                'type' => $config['type'],
                'purpose' => $config['purpose'],
                'est_active' => $config['est_active'],
                'created_at' => date('d/m/Y H:i', strtotime($config['created_at']))
            ];
        }
        
        echo json_encode([
            'success' => true,
            'configs' => $configList
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR get_octopush_configs: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_octopush_config'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $idConfig = $_POST['id_config'] ?? null;
        $nom_config = trim($_POST['nom_config'] ?? '');
        $api_login = trim($_POST['api_login'] ?? '');
        $api_key = trim($_POST['api_key'] ?? '');
        $sender_name = trim($_POST['sender_name'] ?? 'IFB');
        $type = $_POST['type'] ?? 'sms_premium';
        $purpose = $_POST['purpose'] ?? 'alert';
        $est_active = isset($_POST['est_active']) && $_POST['est_active'] === 'true' ? 1 : 0;
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientId)) {
            throw new Exception('ID client invalide (format UUID attendu, reçu: ' . $clientId . ')');
        }

        if (empty($nom_config)) {
            throw new Exception('Nom de la configuration requis');
        }
        if (empty($api_login)) {
            throw new Exception('API Login requis');
        }
        if (empty($api_key)) {
            throw new Exception('API Key requise');
        }
        if (empty($sender_name)) {
            throw new Exception('Nom de l\'expéditeur requis');
        }
        
        $data = [
            'id_compte' => $clientId,
            'nom_config' => $nom_config,
            'api_login' => $api_login,
            'api_key' => $api_key,
            'sender_name' => $sender_name,
            'type' => $type,
            'purpose' => $purpose,
            'est_active' => $est_active,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if ($idConfig) {
            $existing = $db->select('octopush_config', [
                'id_config' => $idConfig,
                'id_compte' => $clientId
            ]);
            if (empty($existing)) {
                throw new Exception('Configuration non trouvée');
            }
            $db->update('octopush_config', $data, ['id_config' => $idConfig]);
            $message = 'Configuration Octopush mise à jour avec succès';
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $idConfig = $db->insert('octopush_config', $data);
            $message = 'Configuration Octopush créée avec succès';
        }

        if (is_array($idConfig)) {
            $row = isset($idConfig[0]) && is_array($idConfig[0]) ? $idConfig[0] : $idConfig;
            $idConfig = $row['id_config'] ?? null;
        }

        if (empty($idConfig)) {
            throw new Exception("Erreur: impossible de récupérer l'ID de la configuration créée");
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR save_octopush_config: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_octopush_config'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $idConfig = $_POST['id_config'] ?? null;
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        if (empty($idConfig)) {
            throw new Exception('ID configuration invalide');
        }
        
        $existing = $db->select('octopush_config', [
            'id_config' => $idConfig,
            'id_compte' => $clientId
        ]);
        
        if (empty($existing)) {
            throw new Exception('Configuration non trouvée');
        }
        
        $db->delete('octopush_config', $idConfig, 'id_config');
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuration Octopush supprimée avec succès'
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR delete_octopush_config: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// GESTION DES TRANSACTIONS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_get_transactions'])) {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        
        $transactions = $db->select('transactions', ['id_compte' => $clientId], '*', 'created_at DESC');
        
        $transactionList = [];
        foreach ($transactions as $transaction) {
            $typeLabel = $transaction['type_transaction'] === 'credit' ? 'Crédit' : 'Débit';
            $typeColor = $transaction['type_transaction'] === 'credit' ? 'text-green-600' : 'text-red-600';
            $typeIcon = $transaction['type_transaction'] === 'credit' ? 'fa-arrow-up' : 'fa-arrow-down';
            
            $providerName = '';
            if (!empty($transaction['id_provider'])) {
                $provider = $db->select('provider', ['id_provider' => $transaction['id_provider']]);
                if (!empty($provider)) {
                    $providerName = $provider[0]['nom_providers'];
                }
            }
            
            $auteurUser = 'Système';
            $auteurRole = 'system';
            $auteurInitiales = 'SY';
            
            if (!empty($transaction['id_compte_auteur'])) {
                $auteur = $db->select('compte', ['id_compte' => $transaction['id_compte_auteur']]);
                if (!empty($auteur)) {
                    $a = $auteur[0];
                    $auteurUser = $a['user'] ?? 'Inconnu';
                    $auteurRole = $a['role'] ?? 'user';
                    
                    $prenom = trim($a['prenom'] ?? '');
                    $nom = trim($a['nom'] ?? '');
                    if ($prenom || $nom) {
                        $auteurInitiales = strtoupper(
                            mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1)
                        );
                    } else {
                        $auteurInitiales = strtoupper(mb_substr($auteurUser, 0, 2));
                    }
                    if (empty($auteurInitiales)) $auteurInitiales = 'U';
                }
            }
            
            $transactionList[] = [
                'id_transaction' => $transaction['id_transaction'],
                'type' => $transaction['type_transaction'],
                'type_label' => $typeLabel,
                'type_color' => $typeColor,
                'type_icon' => $typeIcon,
                'montant' => number_format($transaction['montant'], 3),
                'description' => $transaction['description'] ?? '',
                'solde_avant' => number_format($transaction['solde_avant'], 3),
                'solde_apres' => number_format($transaction['solde_apres'], 3),
                'provider_name' => $providerName,
                'auteur_user' => $auteurUser,
                'auteur_role' => $auteurRole,
                'auteur_initiales' => $auteurInitiales,
                'created_at' => date('d/m/Y H:i', strtotime($transaction['created_at']))
            ];
        }
        
        echo json_encode([
            'success' => true,
            'transactions' => $transactionList,
            'total' => count($transactionList)
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR get_transactions: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_credit'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
        $nouveauCredit = floatval($_POST['credit'] ?? 0);
        
        if (empty($clientId)) {
            throw new Exception('ID client invalide');
        }
        
        if ($nouveauCredit < 0) {
            throw new Exception('Le crédit ne peut pas être négatif');
        }
        
        $clientActuel = $db->select('compte', ['id_compte' => $clientId]);
        if (empty($clientActuel)) {
            throw new Exception('Client non trouvé');
        }
        
        $ancienSolde = floatval($clientActuel[0]['credits_total'] ?? 0);
        $montantAjoute = $nouveauCredit - $ancienSolde;
        
        if ($montantAjoute <= 0) {
            throw new Exception('Le nouveau crédit doit être supérieur à l\'ancien');
        }
        
        $db->update('compte', ['credits_total' => $nouveauCredit], ['id_compte' => $clientId]);
        
        $transactionData = [
            'id_compte' => $clientId,
            'id_provider' => null,
            'type_transaction' => 'credit',
            'montant' => $montantAjoute,
            'description' => 'Recharge manuelle',
            'id_compte_auteur' => $_SESSION['user_id'] ?? null,
            'solde_avant' => $ancienSolde,
            'solde_apres' => $nouveauCredit,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $db->insert('transactions', $transactionData);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Crédit mis à jour avec succès',
            'credit' => number_format($nouveauCredit, 3),
            'montant_ajoute' => number_format($montantAjoute, 3)
        ]);
        
    } catch (Exception $e) {
        error_log("ERREUR mise à jour crédit: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// RÉCUPÉRATION DES DONNÉES
// ============================================

$providersAssocies = $db->select('client_provider', ['id_compte' => $id]);
$providerIds = array_column($providersAssocies, 'id_provider');

$tarifsClient = [];
if (!empty($providerIds)) {
    $tarifsData = $db->select('tarif', ['id_compte' => $id]);
    foreach ($tarifsData as $t) {
        $tarifsClient[$t['id_provider']] = $t['prix'];
    }
}

$associations = [];
if (!empty($providersAssocies)) {
    foreach ($providersAssocies as $assoc) {
        $provider = $db->select('provider', ['id_provider' => $assoc['id_provider']]);
        if (!empty($provider)) {
            $providerInfo = $provider[0];
            $canal = $db->select('type_message', ['id_type_message' => $providerInfo['id_type_message']]);
            
            $tarif = $tarifsClient[$assoc['id_provider']] ?? $providerInfo['tarif'];

            $associations[] = [
                'id_client_provider' => $assoc['id_client_provider'],
                'id_provider' => $providerInfo['id_provider'],
                'nom_providers' => $providerInfo['nom_providers'],
                'description' => $providerInfo['description'],
                'canal' => !empty($canal) ? $canal[0]['libelle_type'] : 'Inconnu',
                'tarif' => (float)$tarif,
                'tarif_par_defaut' => (float)$providerInfo['tarif'],
                'min_tarif' => (float)($providerInfo['min_tarif'] ?? 0),
                'est_actif' => $assoc['est_actif'],
                'date_association' => $assoc['date_association'],
                'a_tarif_personnalise' => isset($tarifsClient[$assoc['id_provider']])
            ];
        }
    }
}

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
            'tarif' => (float)$provider['tarif']
        ];
    }
}

$transactionsBrutes = $db->select('transactions', ['id_compte' => $id], '*', 'created_at DESC');

// Préparer les données d'auteur pour chaque transaction
$transactions = [];
foreach ($transactionsBrutes as $t) {
    $auteurNom = 'Système';
    $auteurRole = 'system';
    $auteurInitiales = 'SY';

    if (!empty($t['id_compte_auteur'])) {
        $a = $db->select('compte', ['id_compte' => $t['id_compte_auteur']]);
        if (!empty($a)) {
            $p = trim($a[0]['prenom'] ?? '');
            $n = trim($a[0]['nom'] ?? '');
            if ($p || $n) {
                $auteurNom = trim($p . ' ' . $n);
                $auteurInitiales = strtoupper(mb_substr($p, 0, 1) . mb_substr($n, 0, 1));
            } else {
                $auteurNom = $a[0]['user'] ?? 'Inconnu';
                $auteurInitiales = strtoupper(mb_substr($auteurNom, 0, 2));
            }
            $auteurRole = $a[0]['role'] ?? 'user';
            if (empty($auteurInitiales)) $auteurInitiales = 'U';
        }
    }

    $t['auteur_nom'] = $auteurNom;
    $t['auteur_role'] = $auteurRole;
    $t['auteur_initiales'] = $auteurInitiales;
    $transactions[] = $t;
}

// ============================================
// STATISTIQUES
// ============================================

$nombreOperateurs = count($associations);

$nombreOperateursActifs = 0;
foreach ($associations as $assoc) {
    if ($assoc['est_actif']) {
        $nombreOperateursActifs++;
    }
}

$operateursParCanal = [];
foreach ($associations as $assoc) {
    $canal = $assoc['canal'];
    if (!isset($operateursParCanal[$canal])) {
        $operateursParCanal[$canal] = 0;
    }
    $operateursParCanal[$canal]++;
}

$totalCredits = 0;
$totalDebits = 0;
foreach ($transactions as $transaction) {
    if ($transaction['type_transaction'] === 'credit') {
        $totalCredits += $transaction['montant'];
    } else {
        $totalDebits += $transaction['montant'];
    }
}

// ============================================
// TRAITEMENT DES AUTRES ACTIONS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_toggle_status'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_info'])) {
    ob_clean();
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
    
    try {
        $clientId = extractClientId($_POST['id_compte'] ?? '');
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
        .statut-badge { display: inline-flex; align-items: center; padding: 6px 14px; border-radius: 9999px; font-size: 13px; font-weight: 600; }
        .statut-badge i { margin-right: 6px; }
        .statut-badge.actif { background: #dcfce7; color: #166534; }
        .statut-badge.inactif { background: #fee2e2; color: #991b1b; }
        .btn-toggle { padding: 8px 20px; border-radius: 10px; font-weight: 700; font-size: 13px; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-toggle:hover { transform: scale(1.05); }
        .btn-toggle.activer { background: #22c55e; color: white; }
        .btn-toggle.activer:hover { background: #16a34a; }
        .btn-toggle.desactiver { background: #ef4444; color: white; }
        .btn-toggle.desactiver:hover { background: #dc2626; }
        .info-card { background: white; border-radius: 14px; border: 1px solid #e5e7eb; overflow: hidden; }
        .info-card-header { padding: 14px 20px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
        .info-card-header .title { font-weight: 700; font-size: 14px; color: #1f2937; }
        .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; padding: 16px 20px; }
        .info-item .label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #6b7280; letter-spacing: 0.03em; }
        .info-item .value { font-size: 14px; margin-top: 4px; color: #1f2937; }
        .stat-card { background: white; border-radius: 14px; padding: 16px 20px; border: 1px solid #e5e7eb; }
        .stat-card .stat-label { font-size: 12px; font-weight: 600; color: #6b7280; }
        .stat-card .stat-value { font-size: 20px; font-weight: 700; margin-top: 4px; color: #1f2937; }
        .stat-card .stat-value .sub-value { font-size: 14px; font-weight: 400; color: #6b7280; }
        .edit-icon { color: #3b82f6; font-size: 13px; cursor: pointer; font-weight: 600; transition: color 0.2s; }
        .edit-icon:hover { color: #1d4ed8; }
        .save-icon { color: #22c55e; font-size: 13px; cursor: pointer; font-weight: 600; transition: color 0.2s; }
        .save-icon:hover { color: #16a34a; }
        .cancel-icon { color: #6b7280; font-size: 13px; cursor: pointer; font-weight: 600; transition: color 0.2s; }
        .cancel-icon:hover { color: #4b5563; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(20, 20, 40, 0.45); display: flex; align-items: center; justify-content: center; z-index: 50; backdrop-filter: blur(4px); }
        .modal-card { background: white; border-radius: 16px; padding: 28px; width: 480px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25); }
        .toast-notification { position: fixed; top: 20px; right: 20px; z-index: 9999; animation: slideInRight 0.3s ease-out; }
        @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .toast-notification .toast-content { color: white; padding: 12px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); font-size: 14px; font-weight: 500; }
        .toast-notification.success .toast-content { background: #10b981; }
        .toast-notification.error .toast-content { background: #ef4444; }
        .toast-notification.info .toast-content { background: #3b82f6; }
        .input-edit { width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid #d1d5db; font-size: 14px; outline: none; transition: border-color 0.2s; }
        .input-edit:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .btn-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #6b7280; cursor: pointer; transition: color 0.2s; text-decoration: none; }
        .btn-back:hover { color: #1f2937; }
        .btn-action-blue { padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; background: #3b82f6; color: white; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-action-blue:hover { background: #2563eb; transform: scale(1.05); }
        .btn-action-green { padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; background: #22c55e; color: white; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-action-green:hover { background: #16a34a; transform: scale(1.05); }
        .btn-action-red { padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; background: #ef4444; color: white; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-action-red:hover { background: #dc2626; transform: scale(1.05); }
        .btn-action-purple { padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; background: #5d3aad; color: white; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-action-purple:hover { background: #7c3aed; transform: scale(1.05); }
        .btn-action-purple:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-sm { padding: 4px 12px; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .btn-sm:hover { transform: scale(1.05); }
        .btn-sm-success { background: #dcfce7; color: #166534; }
        .btn-sm-success:hover { background: #bbf7d0; }
        .btn-sm-danger { background: #fee2e2; color: #991b1b; }
        .btn-sm-danger:hover { background: #fecaca; }
        .btn-sm-warning { background: #fef3c7; color: #92400e; }
        .btn-sm-warning:hover { background: #fde68a; }
        .btn-sm-secondary { background: #f3f4f6; color: #4b5563; }
        .btn-sm-secondary:hover { background: #e5e7eb; }
        .btn-sm-info { background: #dbeafe; color: #1e40af; }
        .btn-sm-info:hover { background: #bfdbfe; }
        .btn-sm-whatsapp { background: #25D366; color: white; }
        .btn-sm-whatsapp:hover { background: #1da851; }
        .btn-sm-restart { background: #f59e0b; color: white; }
        .btn-sm-restart:hover { background: #d97706; }
        .btn-sm-sms { background: #3b82f6; color: white; }
        .btn-sm-sms:hover { background: #2563eb; }
        .btn-sm-email { background: #8b5cf6; color: white; }
        .btn-sm-email:hover { background: #7c3aed; }
        .provider-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 10px; border: 1px solid #e5e7eb; transition: all 0.2s; margin-bottom: 8px; }
        .provider-item:hover { border-color: #8b5cf6; background: #faf5ff; }
        .provider-item .provider-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .provider-item .provider-icon.whatsapp { background: #d1fae5; color: #065f46; }
        .provider-item .provider-icon.sms { background: #dbeafe; color: #1e40af; }
        .provider-item .provider-icon.email { background: #fef3c7; color: #92400e; }
        .provider-item .provider-icon.default { background: #f3f4f6; color: #6b7280; }
        .empty-providers { text-align: center; padding: 30px 20px; color: #9ca3af; }
        .empty-providers i { font-size: 32px; margin-bottom: 10px; display: block; }
        .provider-select-option { display: flex; align-items: center; gap: 10px; padding: 8px 12px; }
        .provider-select-option .canal-badge { font-size: 11px; padding: 2px 10px; border-radius: 12px; background: #f3f4f6; color: #4b5563; }
        .modal-close-btn { background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; transition: color 0.2s; padding: 4px 8px; border-radius: 8px; line-height: 1; }
        .modal-close-btn:hover { color: #ef4444; background: #fee2e2; }
        .session-list-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 10px; border: 1px solid #e5e7eb; transition: all 0.2s; margin-bottom: 6px; }
        .session-list-item:hover { border-color: #8b5cf6; background: #faf5ff; }
        .session-list-item.active { border-color: #22c55e; background: #f0fdf4; }
        .session-list-item .session-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; background: #d1fae5; color: #065f46; }
        .session-list-item .session-icon.inactive { background: #f3f4f6; color: #9ca3af; }
        .session-status-badge { font-size: 11px; padding: 2px 10px; border-radius: 12px; font-weight: 600; transition: all 0.3s ease; }
        .session-status-badge.active { background: #dcfce7; color: #166534; }
        .session-status-badge.inactive { background: #f3f4f6; color: #6b7280; }
        .session-status-badge.working { background: #dcfce7; color: #166534; }
        .session-status-badge.scanning { background: #fef3c7; color: #92400e; animation: pulse 1.5s ease-in-out infinite; }
        .session-status-badge.stopped { background: #f3f4f6; color: #6b7280; }
        .session-status-badge.failed { background: #fee2e2; color: #991b1b; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .code-display { font-size: 32px; font-weight: 700; text-align: center; padding: 20px; background: #f3f4f6; border-radius: 10px; border: 2px dashed #d1d5db; letter-spacing: 6px; color: #1f2937; font-family: 'Courier New', monospace; }
        .modal-card-code { max-width: 550px; width: 90%; }
        .modal-card-session { max-width: 750px; width: 95%; }
        .session-list-item.scanning { border-color: #f59e0b; background: #fffbeb; }
        .session-list-item.working { border-color: #22c55e; background: #f0fdf4; }
        .session-list-item.failed { border-color: #ef4444; background: #fef2f2; }
        .session-list-item .btn-sm-whatsapp { min-width: 85px; justify-content: center; }
        .modal-card-sms { max-width: 600px; width: 95%; }
        .modal-card-email { max-width: 820px; width: 96%; padding: 32px 36px; }
        .modal-card-email .smtp-section { background: #f8fafc; border-radius: 12px; padding: 20px 24px; margin-top: 14px; border: 1px solid #e5e7eb; }
        .modal-card-email .smtp-section .section-title { font-size: 14px; font-weight: 700; color: #1e293b; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .modal-card-email .smtp-section .section-title i { color: #8b5cf6; }
        .modal-card-email .smtp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; }
        .modal-card-email .smtp-grid .full-width { grid-column: 1 / -1; }
        .modal-card-email .smtp-grid .form-group { display: flex; flex-direction: column; gap: 4px; }
        .modal-card-email .smtp-grid .form-group label { font-size: 12px; font-weight: 600; color: #475569; letter-spacing: 0.02em; }
        .modal-card-email .smtp-grid .form-group label .required { color: #ef4444; }
        .modal-card-email .smtp-grid .form-group .form-input { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 12px; font-size: 13px; outline: none; transition: border-color 0.2s, box-shadow 0.2s; background: white; }
        .modal-card-email .smtp-grid .form-group .form-input:focus { border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.12); }
        .modal-card-email .smtp-grid .form-group .form-input[type="number"] { width: 100%; }
        .modal-card-email .smtp-grid .form-group .form-select { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 12px; font-size: 13px; outline: none; transition: border-color 0.2s, box-shadow 0.2s; background: white; appearance: auto; }
        .modal-card-email .smtp-grid .form-group .form-select:focus { border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.12); }
        .modal-card-email .smtp-grid .form-group .form-check { display: flex; align-items: center; gap: 10px; padding-top: 6px; }
        .modal-card-email .smtp-grid .form-group .form-check input[type="checkbox"] { width: 18px; height: 18px; accent-color: #8b5cf6; cursor: pointer; }
        .modal-card-email .smtp-grid .form-group .form-check label { font-size: 13px; font-weight: 400; color: #475569; cursor: pointer; }
        .modal-card-email .smtp-grid .form-group .help-text { font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .modal-card-email .smtp-grid .form-group .help-text i { margin-right: 4px; }
        .modal-card-email .form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .modal-card-email .password-container { position: relative; }
        .modal-card-email .password-container input { padding-right: 45px; }
        .modal-card-email .toggle-password { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #94a3b8; background: transparent; border: none; font-size: 1rem; padding: 4px; }
        .modal-card-email .toggle-password:hover { color: #8b5cf6; }
        .password-container { position: relative; }
        .password-container input { padding-right: 45px; }
        .toggle-password { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #9ca3af; background: transparent; border: none; font-size: 1.1rem; }
        .toggle-password:hover { color: #3b82f6; }
        .device-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 10px; border: 1px solid #e5e7eb; transition: all 0.2s; margin-bottom: 8px; cursor: pointer; }
        .device-item:hover { border-color: #3b82f6; background: #eff6ff; }
        .device-item.active { border-color: #3b82f6; background: #dbeafe; }
        .device-item .device-icon { width: 36px; height: 36px; border-radius: 8px; background: #dbeafe; color: #1e40af; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .device-item .device-icon.inactive { background: #f3f4f6; color: #9ca3af; }
        .device-item .device-icon.email-icon { background: #f3e8ff; color: #7c3aed; }
        .device-item .device-icon.email-icon.inactive { background: #f3f4f6; color: #9ca3af; }
        .device-item.email-active { border-color: #8b5cf6; background: #faf5ff; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; padding: 16px 20px; background: #f8fafc; border-radius: 12px; border: 1px solid #e5e7eb; margin-bottom: 20px; }
        .stat-item { text-align: center; padding: 12px; background: white; border-radius: 10px; border: 1px solid #f1f5f9; }
        .stat-item .stat-number { font-size: 28px; font-weight: 700; color: #1f2937; }
        .stat-item .stat-label { font-size: 16px; font-weight: 600; color: #6b7280; letter-spacing: 0.03em; margin-top: 4px; }
        .stat-item .stat-detail { font-size: 13px; color: #4b5563; margin-top: 6px; padding-top: 6px; border-top: 1px solid #f1f5f9; }
        .stat-item .stat-detail .canal-item { display: inline-block; margin: 2px 4px; padding: 2px 8px; background: #f1f5f9; border-radius: 12px; font-size: 11px; }
        .stat-item .stat-detail .canal-item.whatsapp { background: #d1fae5; color: #065f46; }
        .stat-item .stat-detail .canal-item.sms { background: #dbeafe; color: #1e40af; }
        .stat-item .stat-detail .canal-item.email { background: #fef3c7; color: #92400e; }
        .info-card table { border-collapse: collapse; }
        .info-card table thead th { font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em; }
        .info-card table tbody tr:last-child { border-bottom: none; }
        .info-card table .provider-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
        .info-card table .provider-icon.whatsapp { background: #d1fae5; color: #065f46; }
        .info-card table .provider-icon.sms { background: #dbeafe; color: #1e40af; }
        .info-card table .provider-icon.email { background: #fef3c7; color: #92400e; }
        .info-card table .provider-icon.default { background: #f3f4f6; color: #6b7280; }
        .tarif-cell { cursor: pointer; transition: all 0.2s; padding: 4px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; }
        .tarif-cell:hover { background: #f3f4f6; }
        .tarif-cell .fa-edit { font-size: 11px; color: #6b7280; opacity: 0; transition: opacity 0.2s; }
        .tarif-cell:hover .fa-edit { opacity: 1; }
        .tarif-cell.personnalise { color: #0f0f0f; font-weight: 600; }
        .tarif-cell.personnalise .fa-edit { color: #7c3aed; }
        .tarif-cell .tarif-indicator { font-size: 12px; color: #1d1c1f; }
        @media (max-width: 768px) {
            .info-card table { font-size: 13px; }
            .info-card table th, .info-card table td { padding: 10px 8px; }
            .info-card table .btn-sm { padding: 4px 8px; font-size: 11px; }
            .modal-card-email { max-width: 98%; width: 98%; padding: 20px 16px; }
            .modal-card-email .smtp-grid { grid-template-columns: 1fr; gap: 10px; }
            .modal-card-email .smtp-grid .full-width { grid-column: 1; }
            .modal-card-email .smtp-section { padding: 14px 16px; }
        }
        .modal-card-tarif { max-width: 420px; width: 90%; }
        .transaction-row { transition: all 0.2s; }
        .transaction-row:hover { background: #f8fafc; }
        .transaction-row .montant-credit { color: #16a34a; font-weight: 600; }
        .transaction-row .montant-debit { color: #dc2626; font-weight: 600; }
        .transaction-type-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .transaction-type-badge.credit { background: #dcfce7; color: #166534; }
        .transaction-type-badge.debit { background: #fee2e2; color: #991b1b; }
        .transaction-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; padding: 12px 16px; background: #f8fafc; border-radius: 10px; margin-bottom: 12px; }
        .transaction-summary .summary-item { text-align: center; }
        .transaction-summary .summary-item .value { font-size: 18px; font-weight: 700; }
        .transaction-summary .summary-item .label { font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; }
        .transaction-list { max-height: 400px; overflow-y: auto; }
        .transaction-list table { width: 100%; font-size: 13px; }
        .transaction-list table th { text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; color: #6b7280; font-weight: 600; background: #f8fafc; position: sticky; top: 0; border-bottom: 2px solid #e5e7eb; }
        .transaction-list table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
        .transaction-list table tr:last-child td { border-bottom: none; }
        .empty-transactions { text-align: center; padding: 30px 20px; color: #9ca3af; }
        .empty-transactions i { font-size: 32px; margin-bottom: 10px; display: block; }
    </style>
</head>
<body>

<!-- MODALES (identiques à avant) -->
<!-- ... je vais te les donner avec la modale tarif corrigée -->

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
            <p class="text-sm text-gray-600">Solde actuel : <strong><?= number_format($client['credits_total'] ?? 0, 3) ?> €</strong></p>
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
                Êtes-vous sûr de vouloir retirer l'opérateur 
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
                <i class="fas fa-unlink"></i> Retirer
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
<!-- MODALE EMAIL -->
<!-- ============================================ -->
<div id="emailModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-email" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800" id="emailModalTitle">✉️ Gestion des comptes email</h3>
                <p class="text-sm text-gray-500" id="emailModalSubtitle"><?= htmlspecialchars($client['entreprise']) ?></p>
            </div>
            <button onclick="closeEmailModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <input type="hidden" id="emailProviderId" value="">
        
        <div id="emailContent">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-purple-600"></i>
                <p class="text-gray-500 mt-2">Chargement...</p>
            </div>
        </div>
        
        <div class="mt-4 flex justify-end gap-2" id="emailFooter">
            <button onclick="closeEmailModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Fermer
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE DE CRÉATION DE COMPTE EMAIL -->
<!-- ============================================ -->
<div id="createEmailAccountModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-email" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800">✉️ Créer un compte email</h3>
                <p class="text-sm text-gray-500">Configurez un nouvel expéditeur email</p>
            </div>
            <button onclick="closeCreateEmailAccountModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <form id="createEmailForm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom du compte *</label>
                    <input type="text" id="email_name" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition"
                           placeholder="Ex: email-client-1">
                    <p class="text-xs text-gray-400 mt-1">Identifiant unique</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Adresse expéditeur *</label>
                    <input type="email" id="email_from_address" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition"
                           placeholder="Ex: email@gmail.com">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom d'utilisateur (email) *</label>
                    <input type="text" id="email_username" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition"
                           placeholder="Ex: email@gmail.com">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe *</label>
                    <div class="password-container">
                        <input type="password" id="email_password" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition"
                               placeholder="Mot de passe d'application">
                        <button type="button" class="toggle-password" onclick="togglePassword('email_password', this)">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="smtp-section mt-4">
                <div class="section-title">
                    <i class="fas fa-server"></i> Paramètres SMTP
                </div>
                
                <div class="smtp-grid">
                    <div class="form-group">
                        <label>Hôte SMTP <span class="required">*</span></label>
                        <input type="text" id="smtp_host" class="form-input" value="smtp.gmail.com">
                    </div>
                    <div class="form-group">
                        <label>Port <span class="required">*</span></label>
                        <input type="number" id="smtp_port" class="form-input" value="465" min="1" max="65535">
                    </div>
                    <div class="form-group">
                        <label>Protocole d'authentification</label>
                        <select id="smtp_auth_protocol" class="form-select">
                            <option value="login">login</option>
                            <option value="plain">plain</option>
                            <option value="cram-md5">cram-md5</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Type TLS</label>
                        <select id="smtp_tls_type" class="form-select">
                            <option value="TLS">TLS</option>
                            <option value="STARTTLS">STARTTLS</option>
                            <option value="none">none</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Hello Hostname</label>
                        <input type="text" id="smtp_hello_hostname" class="form-input" value="">
                    </div>
                    <div class="form-group">
                        <label>Connexions max</label>
                        <input type="number" id="smtp_max_conns" class="form-input" value="10" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label>Timeout d'inactivité</label>
                        <input type="text" id="smtp_idle_timeout" class="form-input" value="15s">
                    </div>
                    <div class="form-group">
                        <label>Timeout d'attente</label>
                        <input type="text" id="smtp_wait_timeout" class="form-input" value="5s">
                    </div>
                    <div class="form-group">
                        <label>Tentatives max</label>
                        <input type="number" id="smtp_max_msg_retries" class="form-input" value="2" min="0" max="10">
                    </div>
                    <div class="form-group">
                        <label>Délai entre tentatives</label>
                        <input type="text" id="smtp_msg_retry_delay" class="form-input" value="10ms">
                    </div>
                    <div class="form-group full-width">
                        <div class="form-check">
                            <input type="checkbox" id="smtp_tls_skip_verify">
                            <label for="smtp_tls_skip_verify">Ignorer la vérification TLS</label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-4">
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                    <input type="checkbox" id="replace_existing" checked class="w-4 h-4 text-purple-600 rounded">
                    <span>Remplacer si un bloc porte déjà ce nom</span>
                </label>
            </div>
            
            <div class="form-actions">
                <button type="button" onclick="closeCreateEmailAccountModal()" 
                        class="px-5 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition font-medium">
                    Annuler
                </button>
                <button type="submit" 
                        class="bg-purple-600 hover:bg-purple-700 text-white px-5 py-2 rounded-lg transition font-medium flex items-center gap-2">
                    <i class="fas fa-plus-circle"></i> Créer le compte
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE OCTOPUSH -->
<!-- ============================================ -->
<div id="octopushModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-sms" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800" id="octopushModalTitle">⚡ Gestion Octopush</h3>
                <p class="text-sm text-gray-500" id="octopushModalSubtitle"><?= htmlspecialchars($client['entreprise']) ?></p>
            </div>
            <button onclick="closeOctopushModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <input type="hidden" id="octopushProviderId" value="">
        
        <div id="octopushContent">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-orange-500"></i>
                <p class="text-gray-500 mt-2">Chargement...</p>
            </div>
        </div>
        
        <div class="mt-4 flex justify-end gap-2" id="octopushFooter">
            <button onclick="closeOctopushModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Fermer
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE CONFIG OCTOPUSH -->
<!-- ============================================ -->
<div id="createOctopushConfigModal" class="modal-overlay" style="display: none;">
    <div class="modal-card" style="max-width: 500px;" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800" id="octopushConfigModalTitle">⚡ Nouvelle configuration Octopush</h3>
                <p class="text-sm text-gray-500">Configurez un compte Octopush pour l'envoi de SMS</p>
            </div>
            <button onclick="closeCreateOctopushConfigModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <form id="createOctopushConfigForm">
            <input type="hidden" id="octopush_config_id" value="">
            <input type="hidden" id="octopush_type" name="octopush_type" value="sms_premium">
            <input type="hidden" id="octopush_purpose" name="octopush_purpose" value="marketing">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom de la configuration *</label>
                    <input type="text" id="octopush_nom_config" 
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition"
                        placeholder="Ex: Octopush Principal">
                </div>
                
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">API Login *</label>
                    <input type="text" id="octopush_api_login" 
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition"
                        placeholder="Ex: ifb_1b2@agent.sub-accounts.com">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">API Key *</label>
                    <div class="password-container">
                        <input type="password" id="octopush_api_key" 
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition"
                            placeholder="Entrez votre clé API Octopush">
                        <button type="button" class="toggle-password" onclick="togglePassword('octopush_api_key', this)">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom de l'expéditeur *</label>
                    <input type="text" id="octopush_sender_name" 
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-200 transition"
                        placeholder="Ex: IFB" value="IFB">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                        <div class="locked-field">
                            <span class="locked-field-value">SMS Premium</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Purpose</label>
                        <div class="locked-field">
                            <span class="locked-field-value">Marketing</span>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                        <input type="checkbox" id="octopush_est_active" checked class="w-4 h-4 text-orange-600 rounded">
                        <span>Configuration active</span>
                    </label>
                </div>
            </div>
            
            <div class="flex justify-end space-x-2 mt-6">
                <button type="button" onclick="closeCreateOctopushConfigModal()" 
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    Annuler
                </button>
                <button type="submit" 
                        class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg transition flex items-center gap-2">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE CODE APPAIRAGE -->
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
        </div>
        
        <div class="flex gap-2 mt-2">
            <button onclick="copyCode()" class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition flex items-center justify-center gap-2">
                <i class="fas fa-copy"></i> Copier le code
            </button>
            <button onclick="closeCodeModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Fermer
            </button>
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
<!-- MODALE SMS API -->
<!-- ============================================ -->
<div id="smsApiModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-sms" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800"> Connexion à l'API SMS</h3>
                <p class="text-sm text-gray-500">Entrez vos identifiants pour récupérer vos appareils</p>
            </div>
            <button onclick="closeSmsApiModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <form id="smsLoginForm">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Nom d'utilisateur API *</label>
                <input type="text" id="api_username" 
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Numero de téléphone*</label>
                <input type="text" id="api_numero_telephone" 
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe API *</label>
                <div class="password-container">
                    <input type="password" id="api_password" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition">
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
<!-- MODALE SÉLECTION APPAREIL SMS -->
<!-- ============================================ -->
<div id="smsDeviceModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-sms" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800"> Choisir un appareil SMS</h3>
            </div>
            <button onclick="closeSmsDeviceModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <div id="deviceListContainer"></div>
        
        <div class="flex justify-end mt-6">
            <button type="button" onclick="closeSmsDeviceModal()" 
                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALE CONFIRMATION GÉNÉRIQUE -->
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
<!-- MODALE TARIF PERSONNALISÉ -->
<!-- ============================================ -->
<div id="tarifModal" class="modal-overlay" style="display: none;">
    <div class="modal-card modal-card-tarif" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-800"> Modifier le tarif</h3>
                <p class="text-sm text-gray-500" id="tarifModalSubtitle">
                    <span id="tarifClientName"><?= htmlspecialchars($client['entreprise']) ?></span> x 
                    <span id="tarifProviderName">Opérateur</span>
                </p>
            </div>
            <button onclick="closeTarifModal()" class="modal-close-btn">&times;</button>
        </div>
        
        <form id="tarifForm">
            <input type="hidden" id="tarifProviderId" value="">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Tarif par envoi (€)
                </label>
                <p class="text-xs text-gray-400 mt-1">
                    <span>Pas en dessous de </span>
                    <span id="tarifMinPrice">Tarif minimum : 0.000 €</span>
                </p>
                <div class="flex items-center gap-3">
                    <div class="relative flex-1">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 font-medium">€</span>
                        <input type="number" id="tarifPrice" step="0.001" min="0" 
                               class="w-full border border-gray-300 rounded-lg pl-8 pr-4 py-2.5 focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition"
                               placeholder="0.000">
                    </div>
                </div>
                <p id="tarifError" class="text-xs text-red-600 mt-2" style="display: none;"></p>
            </div>
            
            <div class="flex justify-end gap-2 mt-6 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeTarifModal()" 
                        class="px-5 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition font-medium">
                    Annuler
                </button>
                <button type="submit" 
                        class="px-5 py-2 bg-purple-900 hover:bg-purple-700 text-white rounded-lg transition font-medium flex items-center gap-2">
                    <i class="fas fa-check"></i> Valider
                </button>
            </div>
        </form>
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
                <p class="text-gray-500 text-sm"><?= htmlspecialchars($client['entreprise']) ?></p>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars($client['user']) ?></p>
            </div>
        </div>
        
        <div class="flex gap-2">
            <button onclick="toggleStatus()" id="toggleStatusBtn" 
                    class="<?= $client['actif'] ? 'btn-action-red' : 'btn-action-green' ?>">
                <i class="fas <?= $client['actif'] ? 'fa-pause' : 'fa-play' ?>"></i>
                <?= $client['actif'] ? 'Désactiver' : 'Activer' ?>
            </button>
            
            <button onclick="openRechargeModal()" class="btn-action-purple">
                <i class="fas fa-plus"></i> Recharger le crédit
            </button>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-item" id="creditStatItem">
            <div class="stat-label">Solde crédit</div>
            <div class="stat-number" id="creditStatDisplay"><?= number_format($client['credits_total'] ?? 0, 3) ?> €</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Opérateurs associés</div>
            <div class="stat-number"><?= $nombreOperateurs ?></div>
            <?php if ($nombreOperateurs > 0): ?>
                <div class="stat-detail">
                    <span class="text-green-600"><?= $nombreOperateursActifs ?> actif<?= $nombreOperateursActifs > 1 ? 's' : '' ?></span>
                    <?php if ($nombreOperateursActifs < $nombreOperateurs): ?>
                        <span class="text-red-500">| <?= $nombreOperateurs - $nombreOperateursActifs ?> inactif<?= $nombreOperateurs - $nombreOperateursActifs > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="stat-item">
            <div class="stat-label">Client depuis</div>
            <div class="stat-number"><?= date('Y', strtotime($client['date_creation'])) ?></div>
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
                </div>
            </div>
        </div>
    </div>

    <div class="info-card mb-6">
        <div class="info-card-header">
            <span class="title"><i class="fas fa-link mr-2 text-gray-400"></i>Opérateurs & tarifs</span>
            <button onclick="openAssociateModal()" class="btn-action-purple">
                <i class="fas fa-plus-circle"></i> Associer un opérateur
            </button>
        </div>
        
        <div class="p-4">
            <?php if (empty($associations)): ?>
                <div class="empty-providers">
                    <i class="fas fa-users-slash"></i>
                    <p>Aucun opérateur associé à ce client</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200">
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Opérateur</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Canal</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Tarif client</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Statut</th>
                                <th class="text-center py-3 px-4 font-semibold text-gray-600"></th>
                            </tr>
                        </thead>
                        <tbody id="providersList">
                            <?php foreach ($associations as $assoc): ?>
                                <?php
                                $iconClass = 'default';
                                $canalLower = strtolower($assoc['canal']);
                                if (in_array($canalLower, ['whatsapp', 'sms', 'email'])) {
                                    $iconClass = $canalLower;
                                }
                                $tarif = $assoc['tarif'];
                                $isPersonnalise = $assoc['a_tarif_personnalise'];
                                $minTarifSafe = (float)($assoc['min_tarif'] ?? 0);
                                $tarifDefSafe = (float)$assoc['tarif_par_defaut'];
                                $tarifSafe = (float)$assoc['tarif'];
                                ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition" id="provider_<?= $assoc['id_client_provider'] ?>">
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-3">
                                            <div class="provider-icon <?= $iconClass ?>">
                                                <i class="fas <?= $iconClass === 'whatsapp' ? 'fa-mobile-alt' : ($iconClass === 'sms' ? 'fa-sms' : ($iconClass === 'email' ? 'fa-envelope' : 'fa-plug')) ?>"></i>
                                            </div>
                                            <div>
                                                <div class="font-medium text-gray-800"><?= htmlspecialchars($assoc['nom_providers']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-600">
                                            <?= htmlspecialchars($assoc['canal']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="tarif-cell <?= $isPersonnalise ? 'personnalise' : '' ?>"
                                              data-provider-id="<?= (int)$assoc['id_provider'] ?>"
                                              data-provider-name="<?= htmlspecialchars($assoc['nom_providers'], ENT_QUOTES) ?>"
                                              data-tarif-def="<?= $tarifDefSafe ?>"
                                              data-min-tarif="<?= $minTarifSafe ?>"
                                              style="<?= $isPersonnalise ? 'text-decoration: underline dotted; text-underline-offset: 6px;' : '' ?>">
                                            <?= number_format($tarifSafe, 3) ?>€
                                            <?php if ($isPersonnalise): ?>
                                                <span class="tarif-indicator">/envoi</span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="text-xs font-medium px-2 py-1 rounded-full <?= $assoc['est_actif'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                                            <i class="fas <?= $assoc['est_actif'] ? 'fa-check-circle' : 'fa-circle' ?>"></i>
                                            <?= $assoc['est_actif'] ? 'Actif' : 'Inactif' ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center justify-center gap-2">
                                            <button onclick="openProviderModal(<?= $assoc['id_provider'] ?>, '<?= htmlspecialchars($assoc['nom_providers'], ENT_QUOTES) ?>', '<?= htmlspecialchars($assoc['canal'], ENT_QUOTES) ?>')" 
                                                    class="btn-sm btn-sm-info" title="Gérer">
                                                <i class="fas fa-cog"></i>
                                            </button>
                                            <button onclick="toggleProviderStatus(<?= $assoc['id_client_provider'] ?>, <?= $assoc['est_actif'] ? 'false' : 'true' ?>)" 
                                                    class="btn-sm <?= $assoc['est_actif'] ? 'btn-sm-warning' : 'btn-sm-success' ?>" title="<?= $assoc['est_actif'] ? 'Désactiver' : 'Activer' ?>">
                                                <i class="fas <?= $assoc['est_actif'] ? 'fa-pause' : 'fa-play' ?>"></i>
                                            </button>
                                            <button onclick="openDetachConfirm(<?= $assoc['id_client_provider'] ?>, '<?= htmlspecialchars($assoc['nom_providers'], ENT_QUOTES) ?>')" 
                                                    class="btn-sm btn-sm-danger" title="Dissocier">
                                                <i class="fas fa-unlink"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- HISTORIQUE DES TRANSACTIONS -->
    <div class="info-card">
        <div class="info-card-header">
            <span class="title"><i class="fas fa-scroll mr-2 text-gray-400"></i>Historique des transactions</span>
            <button onclick="refreshTransactions()" class="btn-sm btn-sm-info">
                <i class="fas fa-sync-alt"></i> Rafraîchir
            </button>
        </div>
        <div class="p-4" id="transactionsContainer">
            <?php if (empty($transactions)): ?>
                <div class="empty-transactions">
                    <i class="fas fa-coins"></i>
                    <p>Aucune transaction enregistrée</p>
                </div>
            <?php else: ?>
                <div class="transaction-summary">
                    <div class="summary-item">
                        <div class="value text-gray-800"><?= count($transactions) ?></div>
                        <div class="label">Total transactions</div>
                    </div>
                    <div class="summary-item">
                        <div class="value text-green-600">+<?= number_format($totalCredits, 3) ?> €</div>
                        <div class="label">Crédits</div>
                    </div>
                    <div class="summary-item">
                        <div class="value text-red-600">-<?= number_format($totalDebits, 3) ?> €</div>
                        <div class="label">Débits</div>
                    </div>
                </div>
                
                <div class="transaction-list">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Montant</th>
                                <th>Solde avant</th>
                                <th>Solde après</th>
                                <th>Description</th>
                                <th>Auteur</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php 
                                $isCredit = $transaction['type_transaction'] === 'credit';
                                $typeLabel = $isCredit ? 'Crédit' : 'Débit';
                                $typeClass = $isCredit ? 'credit' : 'debit';
                                $montantClass = $isCredit ? 'montant-credit' : 'montant-debit';
                                $montantDisplay = ($isCredit ? '+' : '-') . number_format($transaction['montant'], 3) . ' €';
                                ?>
                                <tr class="transaction-row">
                                    <td class="text-gray-600"><?= date('d/m/Y H:i', strtotime($transaction['created_at'])) ?></td>
                                    <td>
                                        <span class="transaction-type-badge <?= $typeClass ?>">
                                            <i class="fas <?= $isCredit ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i>
                                            <?= $typeLabel ?>
                                        </span>
                                    </td>
                                    <td class="<?= $montantClass ?>"><?= $montantDisplay ?></td>
                                    <td><?= number_format($transaction['solde_avant'], 3) ?> €</td>
                                    <td><?= number_format($transaction['solde_apres'], 3) ?> €</td>
                                    <td class="text-gray-600 text-sm"><?= htmlspecialchars($transaction['description'] ?? '') ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: white; flex-shrink: 0; text-transform: uppercase;
                                                background: <?= $transaction['auteur_role'] === 'admin' ? 'linear-gradient(135deg,#8b5cf6,#7c3aed)' : ($transaction['auteur_role'] === 'system' ? 'linear-gradient(135deg,#9ca3af,#6b7280)' : 'linear-gradient(135deg,#3b82f6,#2563eb)') ?>;">
                                                <?= htmlspecialchars($transaction['auteur_initiales']) ?>
                                            </span>
                                            <div style="display: flex; flex-direction: column; gap: 1px; min-width: 0;">
                                                <span style="font-size: 12px; font-weight: 600; color: #1f2937; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px;">
                                                    <?= htmlspecialchars($transaction['auteur_nom']) ?>
                                                </span>
                                                <?php if ($transaction['auteur_role'] === 'admin'): ?>
                                                    <span style="font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 8px; background: #ede9fe; color: #6d28d9; text-transform: uppercase; align-self: flex-start;">Admin</span>
                                                <?php elseif ($transaction['auteur_role'] === 'system'): ?>
                                                    <span style="font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 8px; background: #f3f4f6; color: #6b7280; text-transform: uppercase; align-self: flex-start;">Système</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
let currentApiNumber = '';
let confirmCallback = null;

const WAHA_STATUS = {
    'WORKING': 'Connecté',
    'SCAN_QR_CODE': 'Scan QR Code en cours...',
    'STOPPED': 'Arrêté',
    'FAILED': 'Échec',
    'UNKNOWN': 'En attente...'
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
// LISTENER GLOBAL POUR LES CELLULES TARIF
// ============================================
document.addEventListener('click', function(e) {
    const cell = e.target.closest('.tarif-cell');
    if (!cell) return;

    const id = cell.dataset.providerId;
    const name = cell.dataset.providerName || '';
    const def = parseFloat(cell.dataset.tarifDef || 0);
    const min = parseFloat(cell.dataset.minTarif || 0);
    const current = parseFloat(cell.textContent.replace(/[^\d.-]/g, '')) || def;
    const isPerso = cell.classList.contains('personnalise');

    openTarifModal(id, name, def, min, current, isPerso);
});

// ============================================
// CHANGEMENT DE STATUT DU CLIENT
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
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
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
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ============================================
// MODALE RECHARGE
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
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('Crédit rechargé avec succès', 'success');
            
            const creditStatDisplay = document.getElementById('creditStatDisplay');
            if (creditStatDisplay) creditStatDisplay.textContent = result.credit + ' €';
            
            const modalCreditDisplay = document.querySelector('#rechargeModal strong');
            if (modalCreditDisplay) modalCreditDisplay.textContent = result.credit + ' €';
            
            closeRechargeModal();
            refreshTransactions();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ============================================
// HISTORIQUE DES TRANSACTIONS
// ============================================
async function refreshTransactions() {
    const container = document.getElementById('transactionsContainer');
    if (!container) return;
    
    container.innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i><p class="text-gray-500 mt-2">Chargement...</p></div>`;
    
    try {
        const formData = new FormData();
        formData.append('action_get_transactions', '1');
        formData.append('id_compte', clientId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (result.transactions.length === 0) {
                container.innerHTML = `<div class="empty-transactions"><i class="fas fa-coins"></i><p>Aucune transaction enregistrée</p></div>`;
                return;
            }
            
            let totalCredits = 0;
            let totalDebits = 0;
            result.transactions.forEach(t => {
                if (t.type === 'credit') totalCredits += parseFloat(t.montant);
                else totalDebits += parseFloat(t.montant);
            });
            
            let html = `
                <div class="transaction-summary">
                    <div class="summary-item"><div class="value text-gray-800">${result.transactions.length}</div><div class="label">Total transactions</div></div>
                    <div class="summary-item"><div class="value text-green-600">+${totalCredits.toFixed(3)} €</div><div class="label">Crédits</div></div>
                    <div class="summary-item"><div class="value text-red-600">-${totalDebits.toFixed(3)} €</div><div class="label">Débits</div></div>
                </div>
                <div class="transaction-list"><table><thead><tr>
                    <th>Date</th><th>Type</th><th>Montant</th><th>Solde avant</th><th>Solde après</th><th>Description</th></th><th>Auteur</th>
                </tr></thead><tbody>
            `;
            
            result.transactions.forEach(t => {
                const isCredit = t.type === 'credit';
                const typeClass = isCredit ? 'credit' : 'debit';
                const montantClass = isCredit ? 'montant-credit' : 'montant-debit';
                const montantDisplay = (isCredit ? '+' : '-') + t.montant + ' €';
                
                const auteurRole = t.auteur_role || 'system';
                const auteurNom = t.auteur_user || 'Système';
                const auteurInitiales = t.auteur_initiales || 'SY';
                
                let roleBadge = '';
                if (auteurRole === 'admin') {
                    roleBadge = '<span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;background:#ede9fe;color:#6d28d9;text-transform:uppercase;align-self:flex-start;">Admin</span>';
                } else if (auteurRole === 'system') {
                    roleBadge = '<span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;background:#f3f4f6;color:#6b7280;text-transform:uppercase;align-self:flex-start;">Système</span>';
                }
                
                const avatarBg = auteurRole === 'admin' 
                    ? 'linear-gradient(135deg,#8b5cf6,#7c3aed)' 
                    : (auteurRole === 'system' ? 'linear-gradient(135deg,#9ca3af,#6b7280)' : 'linear-gradient(135deg,#3b82f6,#2563eb)');
                
                html += `<tr class="transaction-row">
                    <td class="text-gray-600">${t.created_at}</td>
                    <td><span class="transaction-type-badge ${typeClass}"><i class="fas ${isCredit ? 'fa-arrow-up' : 'fa-arrow-down'}"></i> ${t.type_label}</span></td>
                    <td class="${montantClass}">${montantDisplay}</td>
                    <td>${t.solde_avant} €</td>
                    <td>${t.solde_apres} €</td>
                    <td class="text-gray-600 text-sm">${escapeHtml(t.description)}</td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:white;flex-shrink:0;text-transform:uppercase;background:${avatarBg};">
                                ${escapeHtml(auteurInitiales)}
                            </span>
                            <div style="display:flex;flex-direction:column;gap:1px;min-width:0;">
                                <span style="font-size:12px;font-weight:600;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;">
                                    ${escapeHtml(auteurNom)}
                                </span>
                                ${roleBadge}
                            </div>
                        </div>
                    </td>
                </tr>`;
            });
            
            html += `</tbody></table></div>`;
            container.innerHTML = html;
        } else {
            container.innerHTML = `<div class="text-center py-8"><i class="fas fa-exclamation-circle text-3xl text-red-500"></i><p class="text-gray-600 mt-2">Erreur: ${escapeHtml(result.error || 'Impossible de charger')}</p></div>`;
        }
    } catch (error) {
        container.innerHTML = `<div class="text-center py-8"><i class="fas fa-exclamation-circle text-3xl text-red-500"></i><p class="text-gray-600 mt-2">Erreur réseau: ${escapeHtml(error.message)}</p></div>`;
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
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            
            const fields = ['entreprise', 'nom', 'prenom', 'telephone', 'adresse', 'code_postal', 'ville', 'user'];
            fields.forEach(field => {
                const displayEl = document.getElementById('display_' + field);
                const editEl = document.getElementById('edit_' + field);
                if (displayEl && editEl) displayEl.textContent = editEl.value || '-';
            });
            
            cancelEditInfo();
            
            const prenom = document.getElementById('edit_prenom').value;
            const nom = document.getElementById('edit_nom').value;
            
            document.querySelector('h1').textContent = prenom + ' ' + nom;
            const initials = (prenom.charAt(0) + nom.charAt(0)).toUpperCase();
            document.querySelector('.w-14.h-14').textContent = initials;
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ============================================
// GESTION DES OPÉRATEURS ASSOCIÉS
// ============================================
function openAssociateModal() {
    document.getElementById('associateProviderModal').style.display = 'flex';
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
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const text = await response.text();
        let result;
        try { result = JSON.parse(text); }
        catch (e) { throw new Error('Réponse JSON invalide'); }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeAssociateModal();
            
            const assoc = result.association;
            let clientProviderId = assoc.id_client_provider;
            
            if (Array.isArray(clientProviderId)) clientProviderId = clientProviderId[0];
            if (typeof clientProviderId === 'object' && clientProviderId !== null) {
                clientProviderId = clientProviderId.id_client_provider || clientProviderId.id || Object.values(clientProviderId)[0];
            }
            clientProviderId = parseInt(clientProviderId);
            
            if (!clientProviderId || isNaN(clientProviderId)) {
                showToast('Erreur: ID invalide', 'error');
                return;
            }
            
            let providersList = document.getElementById('providersList');
            const emptyDiv = document.querySelector('.empty-providers');
            if (emptyDiv) {
                const infoCard = emptyDiv.closest('.info-card');
                const pDiv = infoCard.querySelector('.p-4');
                pDiv.innerHTML = `
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200">
                                    <th class="text-left py-3 px-4 font-semibold text-gray-600">Opérateur</th>
                                    <th class="text-left py-3 px-4 font-semibold text-gray-600">Canal</th>
                                    <th class="text-left py-3 px-4 font-semibold text-gray-600">Tarif</th>
                                    <th class="text-left py-3 px-4 font-semibold text-gray-600">Statut</th>
                                    <th class="text-center py-3 px-4 font-semibold text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="providersList"></tbody>
                        </table>
                    </div>
                `;
                providersList = document.getElementById('providersList');
            }
            
            const canalLower = (assoc.canal || '').toLowerCase();
            let iconClass = 'default';
            let icon = 'fa-plug';
            if (canalLower === 'whatsapp') { iconClass = 'whatsapp'; icon = 'fa-mobile-alt'; }
            else if (canalLower === 'sms') { iconClass = 'sms'; icon = 'fa-sms'; }
            else if (canalLower === 'email') { iconClass = 'email'; icon = 'fa-envelope'; }
            
            const isActive = assoc.est_actif === true || assoc.est_actif === 1 || assoc.est_actif === 'true';
            const statusClass = isActive ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500';
            const statusIcon = isActive ? 'fa-check-circle' : 'fa-circle';
            const statusLabel = isActive ? 'Actif' : 'Inactif';
            const toggleAction = isActive ? 'false' : 'true';
            const toggleClass = isActive ? 'btn-sm-warning' : 'btn-sm-success';
            const toggleIcon = isActive ? 'fa-pause' : 'fa-play';
            const toggleLabel = isActive ? 'Désactiver' : 'Activer';
            
            const tarif = parseFloat(assoc.tarif) || 0;
            const minTarif = parseFloat(assoc.min_tarif) || 0;
            const nomSafe = escapeHtml(assoc.nom_providers || '');
            const canalSafe = escapeHtml(assoc.canal || 'Inconnu');
            const descSafe = escapeHtml(assoc.description || '');
            
            const html = `
                <tr class="border-b border-gray-100 hover:bg-gray-50 transition" id="provider_${clientProviderId}">
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-3">
                            <div class="provider-icon ${iconClass}"><i class="fas ${icon}"></i></div>
                            <div>
                                <div class="font-medium text-gray-800">${nomSafe}</div>
                                <div class="text-xs text-gray-500">${descSafe}</div>
                            </div>
                        </div>
                    </td>
                    <td class="py-3 px-4">
                        <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-600">${canalSafe}</span>
                    </td>
                    <td class="py-3 px-4">
                        <span class="tarif-cell" 
                              data-provider-id="${assoc.id_provider}"
                              data-provider-name="${nomSafe.replace(/"/g, '&quot;')}"
                              data-tarif-def="${tarif}"
                              data-min-tarif="${minTarif}">
                            ${tarif.toFixed(3)} €
                            <i class="fas fa-edit"></i>
                        </span>
                    </td>
                    <td class="py-3 px-4">
                        <span class="text-xs font-medium px-2 py-1 rounded-full ${statusClass}">
                            <i class="fas ${statusIcon}"></i> ${statusLabel}
                        </span>
                    </td>
                    <td class="py-3 px-4">
                        <div class="flex items-center justify-center gap-2">
                            <button onclick="openProviderModal(${assoc.id_provider}, '${nomSafe.replace(/'/g, "\\'")}', '${canalSafe.replace(/'/g, "\\'")}')" 
                                    class="btn-sm btn-sm-info" title="Gérer"><i class="fas fa-cog"></i></button>
                            <button onclick="toggleProviderStatus(${clientProviderId}, ${toggleAction})" 
                                    class="btn-sm ${toggleClass}" title="${toggleLabel}"><i class="fas ${toggleIcon}"></i></button>
                            <button onclick="openDetachConfirm(${clientProviderId}, '${nomSafe.replace(/'/g, "\\'")}')" 
                                    class="btn-sm btn-sm-danger" title="Dissocier"><i class="fas fa-unlink"></i></button>
                        </div>
                    </td>
                </tr>
            `;
            
            if (providersList) providersList.insertAdjacentHTML('beforeend', html);
            
            const select = document.getElementById('providerSelect');
            if (select) {
                const option = select.querySelector(`option[value="${providerId}"]`);
                if (option) option.remove();
                const remainingOptions = select.querySelectorAll('option:not([value=""])');
                if (remainingOptions.length === 0) document.getElementById('associateBtn').disabled = true;
            }
            
            updateProviderStats();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

function updateProviderStats() {
    const providersList = document.getElementById('providersList');
    if (!providersList) return;
    
    const rows = providersList.querySelectorAll('tr');
    const total = rows.length;
    
    let active = 0;
    rows.forEach(row => {
        const statusSpan = row.querySelector('td:nth-child(4) .text-xs');
        if (statusSpan && statusSpan.textContent.trim() === 'Actif') active++;
    });
    
    const statOperateurs = document.querySelector('.stat-item:nth-child(2) .stat-number');
    if (statOperateurs) statOperateurs.textContent = total;
    
    const statDetail = document.querySelector('.stat-item:nth-child(2) .stat-detail');
    if (statDetail) {
        const inactive = total - active;
        let html = `<span class="text-green-600">${active} actif${active > 1 ? 's' : ''}</span>`;
        if (inactive > 0) html += ` <span class="text-red-500">| ${inactive} inactif${inactive > 1 ? 's' : ''}</span>`;
        statDetail.innerHTML = html;
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
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const text = await response.text();
        let result;
        try { result = JSON.parse(text); }
        catch (e) { throw new Error('Réponse JSON invalide'); }
        
        if (result.success) {
            showToast(result.message, 'success');
            closeDetachConfirmModal();
            
            const row = document.getElementById(`provider_${idClientProvider}`);
            let providerId = null;
            let providerName = '';
            let providerCanal = '';
            let providerDescription = '';
            
            if (row) {
                const nameCell = row.querySelector('td:nth-child(1) .font-medium');
                if (nameCell) providerName = nameCell.textContent.trim();
                const descCell = row.querySelector('td:nth-child(1) .text-xs.text-gray-500');
                if (descCell) providerDescription = descCell.textContent.trim();
                const canalCell = row.querySelector('td:nth-child(2) .text-xs');
                if (canalCell) providerCanal = canalCell.textContent.trim();
                
                const manageBtn = row.querySelector('.btn-sm-info');
                if (manageBtn) {
                    const onclickAttr = manageBtn.getAttribute('onclick');
                    if (onclickAttr) {
                        const match = onclickAttr.match(/openProviderModal\((\d+),/);
                        if (match) providerId = match[1];
                    }
                }
                
                row.remove();
            }
            
            if (providerId && providerName) {
                const select = document.getElementById('providerSelect');
                if (select) {
                    const existingOption = select.querySelector(`option[value="${providerId}"]`);
                    if (!existingOption) {
                        const option = document.createElement('option');
                        option.value = providerId;
                        const canalDisplay = providerCanal || 'Inconnu';
                        const descDisplay = providerDescription ? ` (${providerDescription})` : '';
                        option.textContent = `${canalDisplay} - ${providerName}${descDisplay}`;
                        select.appendChild(option);
                    }
                    document.getElementById('associateBtn').disabled = false;
                }
            }
            
            const providersList = document.getElementById('providersList');
            if (providersList) {
                const remainingRows = providersList.querySelectorAll('tr');
                if (remainingRows.length === 0) {
                    const infoCard = providersList.closest('.info-card');
                    const pDiv = infoCard.querySelector('.p-4');
                    pDiv.innerHTML = `
                        <div class="empty-providers">
                            <i class="fas fa-users-slash"></i>
                            <p>Aucun opérateur associé à ce client</p>
                        </div>
                    `;
                }
            }
            
            updateProviderStats();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
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
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            
            const item = document.getElementById(`provider_${idClientProvider}`);
            if (item) {
                const statusSpan = item.querySelector('td:nth-child(4) .text-xs.font-medium');
                const toggleBtn = item.querySelector('td:nth-child(5) .btn-sm:not(.btn-sm-info):not(.btn-sm-danger)');
                
                if (newStatus) {
                    if (statusSpan) {
                        statusSpan.className = 'text-xs font-medium px-2 py-1 rounded-full bg-green-100 text-green-700';
                        statusSpan.innerHTML = '<i class="fas fa-check-circle"></i> Actif';
                    }
                    if (toggleBtn) {
                        toggleBtn.className = 'btn-sm btn-sm-warning';
                        toggleBtn.innerHTML = '<i class="fas fa-pause"></i>';
                        toggleBtn.setAttribute('title', 'Désactiver');
                        toggleBtn.setAttribute('onclick', `toggleProviderStatus(${idClientProvider}, false)`);
                    }
                } else {
                    if (statusSpan) {
                        statusSpan.className = 'text-xs font-medium px-2 py-1 rounded-full bg-gray-100 text-gray-500';
                        statusSpan.innerHTML = '<i class="fas fa-circle"></i> Inactif';
                    }
                    if (toggleBtn) {
                        toggleBtn.className = 'btn-sm btn-sm-success';
                        toggleBtn.innerHTML = '<i class="fas fa-play"></i>';
                        toggleBtn.setAttribute('title', 'Activer');
                        toggleBtn.setAttribute('onclick', `toggleProviderStatus(${idClientProvider}, true)`);
                    }
                }
                
                updateProviderStats();
            }
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ============================================
// GESTION DES TARIFS PERSONNALISÉS
// ============================================

function openTarifModal(providerId, providerName, defaultPrice, minPrice, currentPrice, isPersonnalise) {
    const elProviderId   = document.getElementById('tarifProviderId');
    const elProviderName = document.getElementById('tarifProviderName');
    const elMinPrice     = document.getElementById('tarifMinPrice');
    const elPrice        = document.getElementById('tarifPrice');
    const elError        = document.getElementById('tarifError');
    const elModal        = document.getElementById('tarifModal');

    if (!elProviderId || !elProviderName || !elMinPrice || !elPrice || !elModal) {
        console.error('❌ openTarifModal : éléments manquants');
        return;
    }

    elProviderId.value = providerId;
    elProviderName.textContent = providerName;

    const minNum = parseFloat(minPrice);
    const defNum = parseFloat(defaultPrice);
    const curNum = parseFloat(currentPrice);

    elMinPrice.textContent = 'Tarif minimum : ' + (isNaN(minNum) ? '0.000' : minNum.toFixed(3)) + ' €';

    // Stocker TOUT dans le dataset
    elPrice.dataset.minTarif     = isNaN(minNum) ? 0 : minNum;
    elPrice.dataset.defaultPrice = isNaN(defNum) ? 0 : defNum;
    elPrice.min = (isNaN(minNum) ? 0 : minNum).toFixed(3);

    if (isPersonnalise && !isNaN(curNum) && curNum !== defNum) {
        elPrice.value = curNum.toFixed(3);
    } else {
        elPrice.value = '';
    }

    if (elError) {
        elError.style.display = 'none';
        elError.textContent = '';
    }

    elModal.style.display = 'flex';
    elPrice.focus();
}

function closeTarifModal() {
    document.getElementById('tarifModal').style.display = 'none';
}

document.getElementById('tarifForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const providerId = document.getElementById('tarifProviderId').value;
    const priceInput = document.getElementById('tarifPrice');
    const price = parseFloat(priceInput.value);
    const minTarif = parseFloat(priceInput.dataset.minTarif || 0);
    const defaultPrice = parseFloat(priceInput.dataset.defaultPrice || 0);
    const providerName = document.getElementById('tarifProviderName').textContent;
    
    if (isNaN(price) || price < 0) {
        showToast('Veuillez entrer un prix valide (0 ou plus)', 'error');
        return;
    }
    
    if (price < minTarif) {
        showToast(`Le tarif ne peut pas être inférieur au minimum (${minTarif.toFixed(3)} €)`, 'error');
        const errorEl = document.getElementById('tarifError');
        if (errorEl) {
            errorEl.textContent = `Le tarif ne peut pas être inférieur au minimum (${minTarif.toFixed(3)} €)`;
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const btn = this.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...';
    btn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action_update_client_tarif', '1');
        formData.append('id_compte', clientId);
        formData.append('id_provider', providerId);
        formData.append('prix', price);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            
            // Mise à jour via data-provider-id
            const cell = document.querySelector(`.tarif-cell[data-provider-id="${providerId}"]`);
            
            if (cell) {
                const isPerso = Math.abs(price - defaultPrice) > 0.0001;

                cell.dataset.tarifDef = defaultPrice;
                cell.dataset.minTarif = minTarif;

                cell.innerHTML = '';
                cell.classList.toggle('personnalise', isPerso);
                cell.style.textDecoration = isPerso ? 'underline dotted' : '';
                cell.style.textUnderlineOffset = isPerso ? '6px' : '';

                cell.appendChild(document.createTextNode(price.toFixed(3) + '€'));

                if (isPerso) {
                    const indicator = document.createElement('span');
                    indicator.className = 'tarif-indicator';
                    indicator.textContent = '/envoi';
                    cell.appendChild(indicator);
                }

                cell.style.transition = 'background-color 0.4s ease';
                cell.style.backgroundColor = '#dcfce7';
                setTimeout(() => { cell.style.backgroundColor = ''; }, 800);
            } else {
                console.warn('⚠️ Cellule tarif introuvable pour provider ID:', providerId);
            }
            
            closeTarifModal();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
});

// ============================================
// FONCTIONS SESSIONS (WhatsApp/SMS)
// ============================================
async function openSessionModal(providerId, providerName, providerType) {
    const container = document.getElementById('sessionContent');
    const footer = document.getElementById('sessionFooter');
    const modal = document.getElementById('sessionModal');
    const title = document.getElementById('sessionModalTitle');
    const subtitle = document.getElementById('sessionModalSubtitle');
    
    if (!container || !footer || !modal || !title || !subtitle) {
        showToast('Erreur: éléments de la modale manquants', 'error');
        return;
    }
    
    container.innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-3xl text-purple-600"></i><p class="text-gray-500 mt-2">Chargement...</p></div>`;
    
    title.textContent = `Gestion des sessions - ${providerName}`;
    subtitle.textContent = `Client: <?= htmlspecialchars($client['entreprise']) ?>`;
    document.getElementById('sessionProviderId').value = providerId;
    document.getElementById('sessionProviderType').value = providerType;
    
    modal.style.display = 'flex';
    
    const typeLower = providerType.toLowerCase().trim();
    if (typeLower === 'whatsapp') await loadWhatsAppSessions();
    else if (typeLower === 'sms') await loadSmsAppareils();
    else {
        container.innerHTML = `<div class="text-center py-8"><i class="fas fa-info-circle text-3xl text-amber-500"></i><p class="text-gray-600 mt-2">Type non supporté: ${escapeHtml(providerType)}</p></div>`;
    }
}

function closeSessionModal() {
    const modal = document.getElementById('sessionModal');
    if (modal) modal.style.display = 'none';
    stopStatusPolling();
}

async function loadSmsAppareils() {
    const container = document.getElementById('sessionContent');
    const footer = document.getElementById('sessionFooter');
    
    container.innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i><p class="text-gray-500 mt-2">Chargement...</p></div>`;
    
    try {
        const formData = new FormData();
        formData.append('action_get_sms_appareils', '1');
        formData.append('id_compte', clientId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            let html = `<div class="mb-4"><div class="flex items-center justify-between mb-2"><label class="text-sm font-medium text-gray-700"> Appareils SMS (${result.appareils.length})</label></div><div class="space-y-2 max-h-60 overflow-y-auto">`;
            
            if (result.appareils.length === 0) {
                html += `<div class="text-center text-gray-500 py-6 bg-gray-50 rounded-lg"><i class="fas fa-mobile-alt text-4xl mb-3 text-gray-300"></i><p>Aucun appareil SMS configuré</p></div>`;
            } else {
                result.appareils.forEach(appareil => {
                    const isActive = appareil.est_actif;
                    const escapedName = escapeHtml(appareil.device_name);
                    const escapedNumber = escapeHtml(appareil.device_number || '');
                    
                    html += `<div class="device-item ${isActive ? 'active' : ''}" onclick="activateSmsAppareil('${appareil.id_appareil}')">
                        <div class="flex items-center gap-3 flex-1">
                            <div class="device-icon ${isActive ? '' : 'inactive'}"><i class="fas fa-mobile-alt"></i></div>
                            <div class="flex-1">
                                <p class="font-medium text-gray-800">${escapedName}</p>
                                ${escapedNumber 
                                    ? `<p class="text-sm font-bold text-blue-700 mt-1 flex items-center gap-1">
                                        <i class="fas fa-phone-alt text-blue-500"></i>
                                        ${escapedNumber}
                                    </p>` 
                                    : `<p class="text-xs italic text-amber-600 mt-1">
                                        <i class="fas fa-exclamation-triangle"></i> Numéro non renseigné
                                    </p>`}
                                <p class="text-xs text-gray-400 mt-1">ID: ${escapeHtml(appareil.device_id)} · Créé le ${appareil.created_at}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-medium px-2 py-1 rounded-full ${isActive ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500'}">${isActive ? 'Actif' : 'Inactif'}</span>
                            <button onclick="event.stopPropagation(); deleteSmsAppareil('${appareil.id_appareil}', '${escapedName.replace(/'/g, "\\'")}')" class="btn-sm btn-sm-danger"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>`;
                });
            }
            
            html += `</div></div><div class="border-t pt-4 mt-2"><button onclick="openSmsApiModal()" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition flex items-center justify-center gap-2"><i class="fas fa-plus-circle"></i> Ajouter (1puce/appareil)</button></div>`;
            
            container.innerHTML = html;
            footer.innerHTML = `<button onclick="closeSessionModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">Fermer</button>`;
        } else {
            container.innerHTML = `<div class="text-center py-8"><i class="fas fa-exclamation-circle text-3xl text-red-500"></i><p class="text-gray-600 mt-2">Erreur: ${escapeHtml(result.error || 'Impossible')}</p></div>`;
        }
    } catch (error) {
        container.innerHTML = `<div class="text-center py-8"><i class="fas fa-exclamation-circle text-3xl text-red-500"></i><p class="text-gray-600 mt-2">Erreur réseau: ${escapeHtml(error.message)}</p></div>`;
    }
}

async function activateSmsAppareil(appareilId) {
    try {
        const formData = new FormData();
        formData.append('action_activate_sms_appareil', '1');
        formData.append('id_compte', clientId);
        formData.append('appareil_id', appareilId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            await loadSmsAppareils();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

function deleteSmsAppareil(appareilId, appareilName) {
    showConfirmModal(
        `Supprimer l'appareil <strong>${escapeHtml(appareilName)}</strong> ?<br><span class="text-sm text-red-600">Action irréversible.</span>`,
        async () => {
            try {
                const formData = new FormData();
                formData.append('action_delete_sms_appareil', '1');
                formData.append('appareil_id', appareilId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    await loadSmsAppareils();
                } else {
                    showToast(result.error || 'Erreur', 'error');
                }
            } catch (error) {
                showToast('Erreur réseau: ' + error.message, 'error');
            }
        }
    );
}

function openSmsApiModal() {
    document.getElementById('smsApiModal').style.display = 'flex';
    document.getElementById('api_username').value = '';
    document.getElementById('api_password').value = '';
    document.getElementById('api_numero_telephone').value = '';
    // Réinitialiser aussi les variables globales
    currentApiUsername = '';
    currentApiPassword = '';
    currentApiNumber = '';
}

function closeSmsApiModal() {
    document.getElementById('smsApiModal').style.display = 'none';
}

function closeSmsDeviceModal() {
    document.getElementById('smsDeviceModal').style.display = 'none';
}

document.getElementById('smsLoginForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const api_username = document.getElementById('api_username').value.trim();
    const api_numero_telephone = document.getElementById('api_numero_telephone').value.trim();
    const api_password = document.getElementById('api_password').value.trim();
    
    if (!api_username || !api_password || !api_numero_telephone) {
        showToast('Veuillez entrer vos identifiants', 'error');
        return;
    }
    
    currentApiUsername = api_username;
    currentApiPassword = api_password;
     currentApiNumber = api_numero_telephone;
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    submitBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action_fetch_sms_devices', '1');
        formData.append('api_username', api_username);
        formData.append('api_password', api_password);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const responseText = await response.text();
        
        if (responseText.trim().startsWith('<!DOCTYPE') || responseText.trim().startsWith('<html')) {
            throw new Error('Erreur serveur');
        }
        
        let result;
        try { result = JSON.parse(responseText); }
        catch (e) { throw new Error('Réponse invalide'); }
        
        if (result.success && result.devices) {
            if (Array.isArray(result.devices) && result.devices.length > 0) {
                closeSmsApiModal();
                const container = document.getElementById('deviceListContainer');
                container.innerHTML = '';
                result.devices.forEach(device => {
                    const div = document.createElement('div');
                    div.className = 'device-item';
                    div.innerHTML = `<div class="flex items-center gap-3"><div class="device-icon"><i class="fas fa-mobile-alt"></i></div><div><p class="font-medium text-gray-800">${escapeHtml(device.name || 'Appareil')}</p><p class="text-xs text-gray-500">ID: ${escapeHtml(device.id)}</p></div></div><i class="fas fa-chevron-right text-gray-400"></i>`;
                    div.onclick = () => saveSmsAppareil(device.id, device.name || 'Appareil', currentApiNumber);
                    container.appendChild(div);
                });
                document.getElementById('smsDeviceModal').style.display = 'flex';
            } else {
                showToast('Aucun appareil trouvé', 'warning');
            }
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

async function saveSmsAppareil(deviceId, deviceName) {
    try {
        const formData = new FormData();
        formData.append('action_save_sms_appareil', '1');
        formData.append('id_compte', clientId);
        formData.append('device_id', deviceId);
        formData.append('device_name', deviceName);
        formData.append('device_number',currentApiNumber || '');
        formData.append('api_username', currentApiUsername);
        formData.append('api_password', currentApiPassword);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            closeSmsDeviceModal();
            await loadSmsAppareils();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

// ============================================
// WHATSAPP SESSIONS
// ============================================
async function loadWhatsAppSessions() {
    const container = document.getElementById('sessionContent');
    const footer = document.getElementById('sessionFooter');
    
    container.innerHTML = `<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-3xl text-green-600"></i><p class="text-gray-500 mt-2">Chargement...</p></div>`;
    
    try {
        const formData = new FormData();
        formData.append('action_get_whatsapp_sessions', '1');
        formData.append('id_compte', clientId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            let html = `<div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-2">Sessions WhatsApp</label><div class="space-y-2 max-h-60 overflow-y-auto">`;
            
            if (result.sessions.length === 0) {
                html += `<div class="text-center text-gray-500 py-4"><p>Aucune session</p></div>`;
            } else {
                result.sessions.forEach(session => {
                    const isActive = session.est_active;
                    const statusLabel = isActive ? 'Connecté' : '🔗 Connecter';
                    const statusClass = isActive ? 'working' : 'inactive';
                    const itemClass = isActive ? 'working' : '';
                    const connectBtnStyle = isActive ? 'display: none;' : '';
                    const sessionName = escapeHtml(session.nom_session);
                    
                    html += `<div class="session-list-item ${itemClass}" data-session="${sessionName}">
                        <div class="flex items-center gap-3 flex-1">
                            <div class="session-icon ${isActive ? '' : 'inactive'}"><i class="fas fa-mobile-alt"></i></div>
                            <div>
                                <p class="font-medium text-gray-800 session-name">${sessionName}</p>
                                <p class="text-xs text-gray-500">Créée le ${session.created_at}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="session-status-badge ${statusClass}">${statusLabel}</span>
                            <button onclick="checkSessionStatus('${sessionName.replace(/'/g, "\\'")}')" class="btn-sm btn-sm-info"><i class="fas fa-sync-alt"></i></button>
                            <button onclick="connectSession('${sessionName.replace(/'/g, "\\'")}')" class="btn-sm btn-sm-whatsapp" style="${connectBtnStyle}"><i class="fas fa-link"></i> Connecter</button>
                            <button onclick="deleteWhatsAppSession('${session.id_session}', '${sessionName.replace(/'/g, "\\'")}')" class="btn-sm btn-sm-danger"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>`;
                });
            }
            
            html += `</div></div><div class="border-t pt-4 mt-2"><label class="block text-sm font-medium text-gray-700 mb-2">Créer une nouvelle session</label><div class="flex gap-2"><input type="text" id="newSessionName" placeholder="Nom..." class="flex-1 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:border-purple-500"><button onclick="createWhatsAppSession()" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition flex items-center gap-2"><i class="fas fa-plus"></i> Créer</button></div></div>`;
            
            container.innerHTML = html;
            footer.innerHTML = `<button onclick="closeSessionModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">Fermer</button>`;
        } else {
            container.innerHTML = `<div class="text-center py-8"><i class="fas fa-exclamation-circle text-3xl text-red-500"></i><p>${escapeHtml(result.error || 'Erreur')}</p></div>`;
        }
    } catch (error) {
        container.innerHTML = `<div class="text-center py-8"><i class="fas fa-exclamation-circle text-3xl text-red-500"></i><p>${escapeHtml(error.message)}</p></div>`;
    }
}

async function createWhatsAppSession() {
    const nomSession = document.getElementById('newSessionName').value.trim();
    if (!nomSession) { showToast('Entrez un nom', 'error'); return; }
    
    try {
        const formData = new FormData();
        formData.append('action_create_whatsapp_session', '1');
        formData.append('id_compte', clientId);
        formData.append('nom_session', nomSession);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            await loadWhatsAppSessions();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

function deleteWhatsAppSession(sessionId, sessionName) {
    showConfirmModal(
        `Supprimer la session <strong>${escapeHtml(sessionName)}</strong> ?`,
        async () => {
            try {
                const formData = new FormData();
                formData.append('action_delete_whatsapp_session', '1');
                formData.append('id_compte', clientId);
                formData.append('session_id', sessionId);
                formData.append('session_name', sessionName);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    await loadWhatsAppSessions();
                } else {
                    showToast(result.error || 'Erreur', 'error');
                }
            } catch (error) {
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
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success && result.isConnected) {
            stopStatusPolling();
            stopWaitingTimer();
            closeCodeModal();
            updateSessionInDatabase(sessionName, true);
            updateSessionStatusOnly(sessionName, true);
        }
    } catch (error) {}
}

function updateSessionStatusOnly(sessionName, isConnected) {
    const sessionItems = document.querySelectorAll('.session-list-item');
    sessionItems.forEach(item => {
        const nameElement = item.querySelector('.session-name');
        if (nameElement && nameElement.textContent.trim() === sessionName) {
            const statusBadge = item.querySelector('.session-status-badge');
            if (isConnected) {
                if (statusBadge) {
                    statusBadge.className = 'session-status-badge working';
                    statusBadge.textContent = 'Connecté';
                }
                item.classList.add('working');
                const connectBtn = item.querySelector('.btn-sm-whatsapp');
                if (connectBtn) connectBtn.style.display = 'none';
            }
        }
    });
}

async function connectSession(sessionName) {
    showToast('🔄 Connexion...', 'info');
    
    try {
        const restartFormData = new FormData();
        restartFormData.append('action_restart_whatsapp_session', '1');
        restartFormData.append('nom_session', sessionName);
        
        const restartResponse = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: restartFormData
        });
        
        const restartResult = await restartResponse.json();
        if (!restartResult.success) {
            showToast('Erreur: ' + (restartResult.error || 'Redémarrage échoué'), 'error');
            return;
        }
        
        let phoneNumber = document.getElementById('edit_telephone')?.value || '';
        if (!phoneNumber) {
            const num = prompt('📱 Numéro de téléphone (sans +) :');
            if (!num) return;
            phoneNumber = num;
        }
        
        await requestCode(sessionName, phoneNumber);
        startWaitingAfterCode(sessionName);
    } catch (error) {
        showToast('Erreur: ' + error.message, 'error');
    }
}

async function requestCode(sessionName, phoneNumber) {
    document.getElementById('codeSessionName').textContent = sessionName;
    document.getElementById('codeModal').style.display = 'flex';
    document.getElementById('codeDisplay').textContent = 'Demande...';
    
    try {
        const formData = new FormData();
        formData.append('action_request_code', '1');
        formData.append('nom_session', sessionName);
        formData.append('phone_number', phoneNumber);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            document.getElementById('codeDisplay').textContent = result.code || 'N/A';
            showToast('Code obtenu !', 'success');
        } else {
            document.getElementById('codeDisplay').textContent = 'Erreur';
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        document.getElementById('codeDisplay').textContent = 'Erreur';
    }
}

function closeCodeModal() {
    document.getElementById('codeModal').style.display = 'none';
    stopWaitingTimer();
}

function copyCode() {
    const code = document.getElementById('codeDisplay').textContent;
    if (code && !['Demande...', 'Erreur', 'Erreur réseau'].includes(code)) {
        navigator.clipboard.writeText(code).then(() => showToast('Code copié !', 'success'));
    } else {
        showToast('Aucun code à copier', 'error');
    }
}

function startWaitingAfterCode(sessionName) {
    stopWaitingTimer();
    waitingSeconds = 60;
    document.getElementById('waitingProgress').style.display = 'block';
    document.getElementById('progressBar').style.width = '0%';
    document.getElementById('waitingSeconds').textContent = waitingSeconds;
    
    waitingInterval = setInterval(function() {
        waitingSeconds--;
        if (waitingSeconds <= 0) {
            clearInterval(waitingInterval);
            waitingInterval = null;
            checkSessionStatusAfterWait(sessionName);
            return;
        }
        document.getElementById('waitingSeconds').textContent = waitingSeconds;
        document.getElementById('progressBar').style.width = ((60 - waitingSeconds) / 60 * 100) + '%';
    }, 2000);
    
    startStatusPolling(sessionName);
}

function startStatusPolling(sessionName) {
    stopStatusPolling();
    currentSessionName = sessionName;
    checkSessionStatus(sessionName);
    statusPollingInterval = setInterval(() => checkSessionStatus(sessionName), 5000);
}

function stopStatusPolling() {
    if (statusPollingInterval) {
        clearInterval(statusPollingInterval);
        statusPollingInterval = null;
    }
    stopWaitingTimer();
}

function stopWaitingTimer() {
    if (waitingInterval) {
        clearInterval(waitingInterval);
        waitingInterval = null;
    }
    const wp = document.getElementById('waitingProgress');
    if (wp) wp.style.display = 'none';
}

async function checkSessionStatusAfterWait(sessionName) {
    try {
        const formData = new FormData();
        formData.append('action_check_session_status', '1');
        formData.append('nom_session', sessionName);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success && result.isConnected) {
            stopStatusPolling();
            closeCodeModal();
            updateSessionInDatabase(sessionName, true);
            showToast('Session connectée ! 🎉', 'success');
            updateSessionStatusOnly(sessionName, true);
        }
    } catch (error) {}
}

async function updateSessionInDatabase(sessionName, isConnected) {
    try {
        const formData = new FormData();
        formData.append('action_update_session_status', '1');
        formData.append('nom_session', sessionName);
        formData.append('est_active', isConnected ? '1' : '0');
        await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
    } catch (error) {}
}

// ============================================
// EMAIL - COMPTES
// ============================================
async function loadEmailAccounts() {
    const container = document.getElementById('emailContent');
    const footer = document.getElementById('emailFooter');
    
    try {
        const settingsFormData = new FormData();
        settingsFormData.append('action_get_listmonk_settings', '1');
        
        const settingsResponse = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: settingsFormData
        });
        
        const settingsResult = await settingsResponse.json();
        if (!settingsResult.success) throw new Error(settingsResult.error);
        
        const accountsFormData = new FormData();
        accountsFormData.append('action_get_email_accounts', '1');
        accountsFormData.append('id_compte', clientId);
        
        const accountsResponse = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: accountsFormData
        });
        
        const accountsResult = await accountsResponse.json();
        if (!accountsResult.success) throw new Error(accountsResult.error);
        
        let html = `<div class="border-t pt-4 mt-2"><div class="flex items-center justify-between mb-2"><label class="text-sm font-medium text-gray-700">Comptes email (${accountsResult.accounts.length})</label><button onclick="openCreateEmailAccountModal()" class="text-xs bg-purple-100 hover:bg-purple-200 text-purple-700 px-2 py-1 rounded-lg"><i class="fas fa-plus"></i> Ajouter</button></div><div class="space-y-2 max-h-60 overflow-y-auto">`;
        
        if (accountsResult.accounts.length === 0) {
            html += `<div class="text-center text-gray-500 py-2 text-sm bg-gray-50 rounded-lg">Aucun compte configuré</div>`;
        } else {
            accountsResult.accounts.forEach(account => {
                const isActive = account.est_actif;
                const escapedName = escapeHtml(account.name);
                
                html += `<div class="device-item ${isActive ? 'email-active' : ''}" onclick="activateEmailAccount('${account.id_email_account}')">
                    <div class="flex items-center gap-3 flex-1">
                        <div class="device-icon email-icon ${isActive ? '' : 'inactive'}"><i class="fas fa-envelope"></i></div>
                        <div class="flex-1">
                            <p class="font-medium text-gray-800">${escapedName}</p>
                            <p class="text-xs text-gray-500">${escapeHtml(account.username)}</p>
                            <p class="text-xs text-gray-400">${escapeHtml(account.from_address)}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium px-2 py-1 rounded-full ${isActive ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'}">${isActive ? 'Actif' : 'Inactif'}</span>
                        <button data-account-id="${account.id_email_account}" data-account-name="${escapedName}" class="btn-sm btn-sm-danger delete-email-btn"><i class="fas fa-trash"></i></button>
                    </div>
                </div>`;
            });
        }
        
        html += `</div></div>`;
        
        container.innerHTML = html;
        footer.innerHTML = `<button onclick="closeEmailModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">Fermer</button>`;
        
        document.querySelectorAll('.delete-email-btn').forEach(btn => {
            btn.addEventListener('click', function(event) {
                event.stopPropagation();
                deleteEmailAccount(this.getAttribute('data-account-id'), this.getAttribute('data-account-name'));
            });
        });
    } catch (error) {
        container.innerHTML = `<div class="text-center py-8"><p>Erreur: ${escapeHtml(error.message)}</p></div>`;
    }
}

function closeEmailModal() {
    document.getElementById('emailModal').style.display = 'none';
}

async function activateEmailAccount(accountId) {
    try {
        const formData = new FormData();
        formData.append('action_activate_email_account', '1');
        formData.append('id_compte', clientId);
        formData.append('account_id', accountId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            await loadEmailAccounts();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    }
}

function deleteEmailAccount(accountId, accountName) {
    showConfirmModal(
        `Supprimer le compte <strong>${escapeHtml(accountName)}</strong> ?`,
        async () => {
            try {
                const formData = new FormData();
                formData.append('action_delete_smtp_server', '1');
                formData.append('account_id', accountId);
                formData.append('account_name', accountName);
                formData.append('id_compte', clientId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    await loadEmailAccounts();
                } else {
                    showToast(result.error || 'Erreur', 'error');
                }
            } catch (error) {
                showToast('Erreur réseau: ' + error.message, 'error');
            }
        }
    );
}

function openCreateEmailAccountModal() {
    document.getElementById('createEmailAccountModal').style.display = 'flex';
    document.getElementById('createEmailForm').reset();
}

function closeCreateEmailAccountModal() {
    document.getElementById('createEmailAccountModal').style.display = 'none';
}

document.getElementById('createEmailForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const name = document.getElementById('email_name').value.trim();
    const username = document.getElementById('email_username').value.trim();
    const password = document.getElementById('email_password').value.trim();
    const from_address = document.getElementById('email_from_address').value.trim();
    const replaceExisting = document.getElementById('replace_existing').checked;
    
    const host = document.getElementById('smtp_host').value.trim();
    const port = parseInt(document.getElementById('smtp_port').value) || 465;
    const auth_protocol = document.getElementById('smtp_auth_protocol').value;
    const hello_hostname = document.getElementById('smtp_hello_hostname').value.trim();
    const tls_type = document.getElementById('smtp_tls_type').value;
    const tls_skip_verify = document.getElementById('smtp_tls_skip_verify').checked;
    const max_conns = parseInt(document.getElementById('smtp_max_conns').value) || 10;
    const idle_timeout = document.getElementById('smtp_idle_timeout').value.trim() || '15s';
    const wait_timeout = document.getElementById('smtp_wait_timeout').value.trim() || '5s';
    const max_msg_retries = parseInt(document.getElementById('smtp_max_msg_retries').value) || 2;
    const msg_retry_delay = document.getElementById('smtp_msg_retry_delay').value.trim() || '10ms';
    
    if (!name || !username || !password || !from_address || !host) {
        showToast('Veuillez remplir tous les champs requis', 'error');
        return;
    }
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    submitBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action_add_smtp_server', '1');
        formData.append('id_compte', clientId);
        formData.append('name', name);
        formData.append('username', username);
        formData.append('password', password);
        formData.append('from_address', from_address);
        formData.append('replace_existing', replaceExisting ? 'true' : 'false');
        formData.append('host', host);
        formData.append('port', port);
        formData.append('auth_protocol', auth_protocol);
        formData.append('hello_hostname', hello_hostname);
        formData.append('tls_type', tls_type);
        formData.append('tls_skip_verify', tls_skip_verify ? 'true' : 'false');
        formData.append('max_conns', max_conns);
        formData.append('idle_timeout', idle_timeout);
        formData.append('wait_timeout', wait_timeout);
        formData.append('max_msg_retries', max_msg_retries);
        formData.append('msg_retry_delay', msg_retry_delay);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            closeCreateEmailAccountModal();
            await loadEmailAccounts();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// OCTOPUSH
// ============================================
async function loadOctopushConfigs() {
    const container = document.getElementById('octopushContent');
    const footer = document.getElementById('octopushFooter');
    
    try {
        const formData = new FormData();
        formData.append('action_get_octopush_configs', '1');
        formData.append('id_compte', clientId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            let html = `<div class="mb-4"><label class="block text-sm font-medium text-gray-700 mb-2">Configurations Octopush</label><div class="space-y-2 max-h-60 overflow-y-auto">`;
            
            if (result.configs.length === 0) {
                html += `<div class="text-center text-gray-500 py-4"><p>Aucune configuration</p></div>`;
            } else {
                result.configs.forEach(config => {
                    const isActive = config.est_active;
                    html += `<div class="device-item ${isActive ? 'email-active' : ''}" onclick="activateOctopushConfig('${config.id_config}')">
                        <div class="flex items-center gap-3 flex-1">
                            <div class="device-icon email-icon ${isActive ? '' : 'inactive'}"><i class="fas fa-bolt"></i></div>
                            <div class="flex-1">
                                <p class="font-medium text-gray-800">${escapeHtml(config.nom_config)}</p>
                                <p class="text-xs text-gray-500">Login: ${escapeHtml(config.api_login)}</p>
                                <p class="text-xs text-gray-400">Expéditeur: ${escapeHtml(config.sender_name)}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-medium px-2 py-1 rounded-full ${isActive ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-500'}">${isActive ? 'Actif' : 'Inactif'}</span>
                            <button onclick="event.stopPropagation(); editOctopushConfig('${config.id_config}')" class="btn-sm btn-sm-warning"><i class="fas fa-edit"></i></button>
                            <button onclick="event.stopPropagation(); deleteOctopushConfig('${config.id_config}', '${escapeHtml(config.nom_config).replace(/'/g, "\\'")}')" class="btn-sm btn-sm-danger"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>`;
                });
            }
            
            html += `</div></div><div class="border-t pt-4 mt-2"
            ><button onclick="openCreateOctopushConfigModal()" class="w-full px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-lg transition flex items-center justify-center gap-2">
            <i class="fas fa-plus-circle"></i> Ajouter une configuration</button></div>`;
            
            container.innerHTML = html;
            footer.innerHTML = `<button onclick="closeOctopushModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">Fermer</button>`;
        }
    } catch (error) {
        container.innerHTML = `<div class="text-center py-8"><p>Erreur: ${escapeHtml(error.message)}</p></div>`;
    }
}

function closeOctopushModal() {
    document.getElementById('octopushModal').style.display = 'none';
}

function openCreateOctopushConfigModal(configData = null) {
    const modal = document.getElementById('createOctopushConfigModal');
    const title = document.getElementById('octopushConfigModalTitle');
    
    if (configData) {
        title.textContent = 'Modifier la configuration';
        document.getElementById('octopush_config_id').value = configData.id_config || '';
        document.getElementById('octopush_nom_config').value = configData.nom_config || '';
        document.getElementById('octopush_api_login').value = configData.api_login || '';
        document.getElementById('octopush_api_key').value = configData.api_key || '';
        document.getElementById('octopush_sender_name').value = configData.sender_name || 'IFB';
        document.getElementById('octopush_est_active').checked = configData.est_active === true || configData.est_active === 1;
    } else {
        title.textContent = '⚡ Nouvelle configuration';
        document.getElementById('createOctopushConfigForm').reset();
        document.getElementById('octopush_config_id').value = '';
        document.getElementById('octopush_sender_name').value = 'IFB';
        document.getElementById('octopush_est_active').checked = true;
    }
    
    modal.style.display = 'flex';
}

function closeCreateOctopushConfigModal() {
    document.getElementById('createOctopushConfigModal').style.display = 'none';
}

async function editOctopushConfig(configId) {
    try {
        const formData = new FormData();
        formData.append('action_get_octopush_configs', '1');
        formData.append('id_compte', clientId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            const config = result.configs.find(c => c.id_config == configId);
            if (config) openCreateOctopushConfigModal(config);
        }
    } catch (error) {}
}

function deleteOctopushConfig(configId, configName) {
    showConfirmModal(
        `Supprimer la configuration <strong>${escapeHtml(configName)}</strong> ?`,
        async () => {
            try {
                const formData = new FormData();
                formData.append('action_delete_octopush_config', '1');
                formData.append('id_compte', clientId);
                formData.append('id_config', configId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    await loadOctopushConfigs();
                }
            } catch (error) {}
        }
    );
}

async function activateOctopushConfig(configId) {
    try {
        const formData = new FormData();
        formData.append('action_get_octopush_configs', '1');
        formData.append('id_compte', clientId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            const config = result.configs.find(c => c.id_config == configId);
            if (!config) return;
            
            const saveFormData = new FormData();
            saveFormData.append('action_save_octopush_config', '1');
            saveFormData.append('id_compte', clientId);
            saveFormData.append('id_config', configId);
            saveFormData.append('nom_config', config.nom_config);
            saveFormData.append('api_login', config.api_login);
            saveFormData.append('api_key', config.api_key);
            saveFormData.append('sender_name', config.sender_name);
            saveFormData.append('type', config.type);
            saveFormData.append('purpose', config.purpose);
            saveFormData.append('est_active', 'true');
            
            const saveResponse = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: saveFormData
            });
            
            const saveResult = await saveResponse.json();
            if (saveResult.success) {
                showToast('Configuration activée', 'success');
                await loadOctopushConfigs();
            }
        }
    } catch (error) {}
}

document.getElementById('createOctopushConfigForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const idConfig = document.getElementById('octopush_config_id').value;
    const nom_config = document.getElementById('octopush_nom_config').value.trim();
    const api_login = document.getElementById('octopush_api_login').value.trim();
    const api_key = document.getElementById('octopush_api_key').value.trim();
    const sender_name = document.getElementById('octopush_sender_name').value.trim();
    const type = document.getElementById('octopush_type').value;
    const purpose = document.getElementById('octopush_purpose').value;
    const est_active = document.getElementById('octopush_est_active').checked;
    
    if (!nom_config || !api_login || !api_key || !sender_name) {
        showToast('Veuillez remplir tous les champs', 'error');
        return;
    }
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    submitBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action_save_octopush_config', '1');
        formData.append('id_compte', clientId);
        if (idConfig) formData.append('id_config', idConfig);
        formData.append('nom_config', nom_config);
        formData.append('api_login', api_login);
        formData.append('api_key', api_key);
        formData.append('sender_name', sender_name);
        formData.append('type', type);
        formData.append('purpose', purpose);
        formData.append('est_active', est_active ? 'true' : 'false');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            closeCreateOctopushConfigModal();
            await loadOctopushConfigs();
        } else {
            showToast(result.error || 'Erreur', 'error');
        }
    } catch (error) {
        showToast('Erreur réseau: ' + error.message, 'error');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

// ============================================
// ROUTEUR PROVIDER
// ============================================
function openProviderModal(providerId, providerName, providerType) {
    const typeLower = providerType.toLowerCase();
    
    if (typeLower === 'whatsapp') {
        openSessionModal(providerId, providerName, providerType);
    } else if (typeLower === 'sms') {
        if (providerName.toLowerCase().includes('octopush')) {
            document.getElementById('octopushModalTitle').textContent = `⚡ Gestion Octopush - ${providerName}`;
            document.getElementById('octopushModalSubtitle').textContent = `Client: <?= htmlspecialchars($client['entreprise']) ?>`;
            document.getElementById('octopushProviderId').value = providerId;
            document.getElementById('octopushModal').style.display = 'flex';
            loadOctopushConfigs();
        } else {
            openSessionModal(providerId, providerName, providerType);
        }
    } else if (typeLower === 'email') {
        document.getElementById('emailModalTitle').textContent = `✉️ Gestion des emails - ${providerName}`;
        document.getElementById('emailModalSubtitle').textContent = `Client: <?= htmlspecialchars($client['entreprise']) ?>`;
        document.getElementById('emailProviderId').value = providerId;
        document.getElementById('emailModal').style.display = 'flex';
        loadEmailAccounts();
    } else {
        showToast('Type non supporté: ' + providerType, 'error');
    }
}

// ============================================
// UTILITAIRES
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

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
    if (typeof confirmCallback === 'function') confirmCallback();
    closeConfirmModal();
});

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
// FERMETURE DES MODALES
// ============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRechargeModal();
        closeAssociateModal();
        closeDetachConfirmModal();
        closeSessionModal();
        closeEmailModal();
        closeCodeModal();
        closeSmsApiModal();
        closeSmsDeviceModal();
        closeConfirmModal();
        closeCreateEmailAccountModal();
        closeTarifModal();
        closeOctopushModal();
        closeCreateOctopushConfigModal();
    }
});

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            const id = this.id;
            if (id === 'rechargeModal') closeRechargeModal();
            else if (id === 'associateProviderModal') closeAssociateModal();
            else if (id === 'detachConfirmModal') closeDetachConfirmModal();
            else if (id === 'sessionModal') closeSessionModal();
            else if (id === 'emailModal') closeEmailModal();
            else if (id === 'codeModal') closeCodeModal();
            else if (id === 'smsApiModal') closeSmsApiModal();
            else if (id === 'smsDeviceModal') closeSmsDeviceModal();
            else if (id === 'confirmModal') closeConfirmModal();
            else if (id === 'createEmailAccountModal') closeCreateEmailAccountModal();
            else if (id === 'tarifModal') closeTarifModal();
            else if (id === 'octopushModal') closeOctopushModal();
            else if (id === 'createOctopushConfigModal') closeCreateOctopushConfigModal();
        }
    });
});

document.getElementById('rechargeAmount')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') confirmRecharge();
});

// ============================================
// VALIDATION LIVE DU TARIF MINIMUM
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const priceInput = document.getElementById('tarifPrice');
    const errorEl = document.getElementById('tarifError');
    
    if (priceInput && errorEl) {
        priceInput.addEventListener('input', function() {
            const minTarif = parseFloat(this.dataset.minTarif || 0);
            const value = parseFloat(this.value);
            
            if (!isNaN(value) && value < minTarif) {
                errorEl.textContent = `Le tarif ne peut pas être inférieur au minimum (${minTarif.toFixed(3)} €)`;
                errorEl.style.display = 'block';
                this.classList.add('border-red-500');
            } else {
                errorEl.style.display = 'none';
                this.classList.remove('border-red-500');
            }
        });
    }
});
</script>

</body>
</html>