<?php
require_once __DIR__ . '/../config.php';

class Database {
    private $url;
    private $apiKey;
    
    public function __construct() {
        $this->url = rtrim(SUPABASE_URL, '/');
        $this->apiKey = SUPABASE_KEY;
    }

    public function deleteWithConditions($table, $conditions) {
        $query = "";
        foreach ($conditions as $col => $value) {
            if (!empty($query)) {
                $query .= "&";
            }
            if ($col === 'user') {
                $col = '"user"';
            }
            $query .= $col . "=eq." . urlencode($value);
        }
        
        $endpoint = $table . '?' . $query;
        return $this->request('DELETE', $endpoint);
    }
    
    private function request($method, $endpoint, $data = null) {
        if (!function_exists('curl_init')) {
            throw new Exception("L'extension PHP curl n'est pas activée.");
        }
        
        $ch = curl_init();
        $url = $this->url . '/rest/v1/' . $endpoint;
        
        $headers = [
            'apikey: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        if (DEBUG_MODE) {
            error_log("Supabase API Call: " . $method . " " . $url);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("CURL Error: " . $error);
        }
        
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("API Error (" . $httpCode . "): " . $response);
        }
        
        return json_decode($response, true);
    }
    
    public function select($table, $conditions = [], $select = '*') {
    $query = "select=" . $select;
    
    foreach ($conditions as $col => $value) {
        if ($col === 'user') {
            $query .= '&"user"=eq.' . urlencode($value);
        } else {
            $query .= "&" . $col . "=eq." . urlencode($value);
        }
    }
    
    $endpoint = $table . '?' . $query;
    return $this->request('GET', $endpoint);
}
    
    public function insert($table, $data) {
        return $this->request('POST', $table, $data);
    }
    
    /**
     * Insère un enregistrement et retourne l'ID créé
     */
    public function insertAndGetId($table, $data) {
        $result = $this->request('POST', $table, $data);
        
        // Déterminer le nom du champ ID
        $idField = 'id_' . $table;
        
        // Supabase retourne le tableau des données insérées
        if (is_array($result) && isset($result[0][$idField])) {
            return $result[0][$idField];
        } elseif (is_array($result) && isset($result[$idField])) {
            return $result[$idField];
        }
        
        // Fallback: chercher le dernier enregistrement
        $lastRecord = $this->select($table, [], '*', 'date_creation.desc');
        if (!empty($lastRecord) && isset($lastRecord[0][$idField])) {
            return $lastRecord[0][$idField];
        }
        
        return null;
    }
    
    public function update($table, $data, $conditions) {
        $query = "";
        foreach ($conditions as $col => $value) {
            if (!empty($query)) {
                $query .= "&";
            }
            if ($col === 'user') {
                $col = '"user"';
            }
            $query .= $col . "=eq." . urlencode($value);
        }
        
        $endpoint = $table . '?' . $query;
        return $this->request('PATCH', $endpoint, $data);
    }
    
    public function delete($table, $id, $idField) {
        $endpoint = $table . '?' . $idField . '=eq.' . $id;
        return $this->request('DELETE', $endpoint);
    }
}

$db = new Database();

// Fonctions utilitaires
function getCompteByUser($user) {
    global $db;
    $result = $db->select('compte', ['user' => $user]);
    return $result ? $result[0] : null;
}

function getContactsByCompte($idCompte) {
    global $db;
    return $db->select('contact', ['id_compte' => $idCompte]);
}

function getListesByCompte($idCompte) {
    global $db;
    return $db->select('liste', ['id_compte' => $idCompte]);
}

function getCreditsDisponibles($idCompte) {
    global $db;
    $result = $db->select('compte', ['id_compte' => $idCompte], 'credits_total');
    return $result ? floatval($result[0]['credits_total']) : 0;
}

function getTypesMessage() {
    global $db;
    
    try {
        $result = $db->select('type_message');
        if (!empty($result)) {
            return $result;
        }
        
        try {
            $db->insert('type_message', ['id_type_message' => 1, 'libelle_type' => 'SMS']);
            $db->insert('type_message', ['id_type_message' => 2, 'libelle_type' => 'Email']);
            $db->insert('type_message', ['id_type_message' => 3, 'libelle_type' => 'WhatsApp']);
            $db->insert('type_message', ['id_type_message' => 4, 'libelle_type' => 'Audio']);
            $result = $db->select('type_message');
            return $result;
        } catch (Exception $e) {
            // Ignorer
        }
        
        return $result ?: [];
    } catch (Exception $e) {
        error_log("getTypesMessage error: " . $e->getMessage());
        return [];
    }
}

// Récupérer la session WhatsApp active de l'utilisateur
function getWhatsAppSession($idCompte) {
    global $db;
    $result = $db->select('whatsapp_sessions', [
        'id_compte' => $idCompte,
        'est_active' => true
    ]);
    return $result ? $result[0]['nom_session'] : null;
}

// Récupérer toutes les sessions WhatsApp d'un utilisateur
function getAllWhatsAppSessions($idCompte) {
    global $db;
    return $db->select('whatsapp_sessions', ['id_compte' => $idCompte], '*', 'created_at.desc');
}

// Activer une session (désactiver les autres)
function setActiveWhatsAppSession($idCompte, $sessionName) {
    global $db;
    
    // Désactiver toutes les sessions
    $db->update('whatsapp_sessions', ['est_active' => false], ['id_compte' => $idCompte]);
    
    // Activer la session choisie
    $db->update('whatsapp_sessions', ['est_active' => true], [
        'id_compte' => $idCompte,
        'nom_session' => $sessionName
    ]);
}
?>