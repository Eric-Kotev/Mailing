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
        
        // Nettoyer l'URL pour éviter les problèmes
        $url = str_replace(' ', '%20', $url);
        
        $headers = [
            'apikey: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Prefer: return=representation'
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
            if ($data) {
                error_log("Data: " . json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        if ($curlError) {
            curl_close($ch);
            throw new Exception("CURL Error: " . $curlError . " - URL: " . $url);
        }
        
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("API Error (" . $httpCode . "): " . $response);
        }
        
        return json_decode($response, true);
    }
    
    public function select($table, $conditions = [], $select = '*', $orderBy = null, $limit = null, $offset = null) {
    $query = "select=" . $select;
    
    // Ajouter les conditions
    foreach ($conditions as $col => $value) {
        if ($col === 'user') {
            $query .= '&"user"=eq.' . urlencode($value);
        } else {
            // Gérer les conditions spéciales
            if (strpos($col, '!=') !== false) {
                $col = str_replace('!=', '', $col);
                $query .= "&" . $col . "=neq." . urlencode($value);
            } elseif (strpos($col, '>=') !== false) {
                $col = str_replace('>=', '', $col);
                $query .= "&" . $col . "=gte." . urlencode($value);
            } elseif (strpos($col, '<=') !== false) {
                $col = str_replace('<=', '', $col);
                $query .= "&" . $col . "=lte." . urlencode($value);
            } elseif (strpos($col, '>') !== false) {
                $col = str_replace('>', '', $col);
                $query .= "&" . $col . "=gt." . urlencode($value);
            } elseif (strpos($col, '<') !== false) {
                $col = str_replace('<', '', $col);
                $query .= "&" . $col . "=lt." . urlencode($value);
            } else {
                $query .= "&" . $col . "=eq." . urlencode($value);
            }
        }
    }
    
    // 🔥 CORRECTION : Ajouter l'ordre correctement
    if ($orderBy) {
        // Nettoyer le format
        $orderBy = trim($orderBy);
        
        // Vérifier si le format est déjà "colonne.desc" ou "colonne.asc"
        if (preg_match('/^[a-zA-Z0-9_]+\.(desc|asc)$/i', $orderBy)) {
            // Déjà au bon format, l'utiliser tel quel
            $query .= "&order=" . $orderBy;
        } else {
            // Extraire le nom de la colonne et la direction
            $parts = explode(' ', $orderBy);
            $column = $parts[0];
            $direction = isset($parts[1]) ? strtoupper($parts[1]) : 'ASC';
            
            // Valider la direction
            if ($direction !== 'ASC' && $direction !== 'DESC') {
                $direction = 'ASC';
            }
            
            $query .= "&order=" . $column . "." . strtolower($direction);
        }
    }
    
    // Ajouter la limite
    if ($limit) {
        $query .= "&limit=" . intval($limit);
    }
    
    // Ajouter l'offset
    if ($offset) {
        $query .= "&offset=" . intval($offset);
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
        $lastRecord = $this->select($table, [], '*', 'created_at.desc', 1);
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
    
    // 🔥 Méthode utilitaire pour exécuter une requête SQL personnalisée via RPC
    public function rpc($function, $params = []) {
        $endpoint = 'rpc/' . $function;
        return $this->request('POST', $endpoint, $params);
    }
}

$db = new Database();

// ============================================
// FONCTIONS UTILITAIRES DE BASE
// ============================================

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
            $db->insert('type_message', ['libelle_type' => 'SMS']);
            $db->insert('type_message', ['libelle_type' => 'Email']);
            $db->insert('type_message', ['libelle_type' => 'WhatsApp']);
            $db->insert('type_message', ['libelle_type' => 'Audio']);
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

function getWhatsAppSession($idCompte) {
    global $db;
    $result = $db->select('whatsapp_sessions', [
        'id_compte' => $idCompte,
        'est_active' => true
    ]);
    return $result ? $result[0]['nom_session'] : null;
}

function getAllWhatsAppSessions($idCompte) {
    global $db;
    return $db->select('whatsapp_sessions', ['id_compte' => $idCompte], '*', 'created_at.desc');
}

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