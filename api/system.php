<?php
// api/system.php - Gestione impostazioni sistema
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Permessi insufficienti']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_config':
        handleGetConfig();
        break;
    case 'update_config':
        handleUpdateConfig();
        break;
    case 'test_db_path':
        handleTestDbPath();
        break;
    case 'get_stats':
        handleGetStats();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Azione non valida']);
        exit;
}

function handleGetConfig() {
    $config_file = __DIR__ . '/../app_config.json';
    
    if (file_exists($config_file)) {
        $config = json_decode(file_get_contents($config_file), true);
        echo json_encode(['success' => true, 'config' => $config]);
    } else {
        echo json_encode(['error' => 'File di configurazione non trovato']);
    }
    exit;
}

function handleUpdateConfig() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $config_file = __DIR__ . '/../app_config.json';
        
        // Valida i percorsi
        if (isset($data['db_path'])) {
            $db_dir = dirname($data['db_path']);
            
            // Crea la directory se non esiste
            if (!file_exists($db_dir)) {
                if (!mkdir($db_dir, 0777, true)) {
                    echo json_encode(['error' => 'Impossibile creare la directory del database']);
                    exit;
                }
            }
            
            // Verifica che sia scrivibile
            if (!is_writable($db_dir)) {
                echo json_encode(['error' => 'Directory del database non scrivibile']);
                exit;
            }
        }
        
        // Salva la configurazione
        $current_config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
        $new_config = array_merge($current_config, $data);
        
        file_put_contents($config_file, json_encode($new_config, JSON_PRETTY_PRINT));
        
        echo json_encode([
            'success' => true,
            'message' => 'Configurazione salvata. Ricaricare la pagina per applicare le modifiche.'
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleTestDbPath() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $test_path = $data['path'] ?? '';
        
        if (empty($test_path)) {
            echo json_encode(['error' => 'Percorso non specificato']);
            exit;
        }
        
        $dir = dirname($test_path);
        
        // Verifica se la directory esiste
        if (!file_exists($dir)) {
            echo json_encode([
                'success' => true,
                'exists' => false,
                'writable' => false,
                'message' => 'Directory non esistente (verrà creata automaticamente)'
            ]);
            exit;
        }
        
        // Verifica se è scrivibile
        $writable = is_writable($dir);
        
        echo json_encode([
            'success' => true,
            'exists' => true,
            'writable' => $writable,
            'message' => $writable ? 'Percorso valido e scrivibile' : 'Percorso non scrivibile'
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleGetStats() {
    global $pdo;
    
    try {
        // Conta utenti
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $users_count = $stmt->fetchColumn();
        
        // Conta file
        $stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE is_deleted = 0");
        $files_count = $stmt->fetchColumn();
        
        // Conta file eliminati
        $stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE is_deleted = 1");
        $deleted_count = $stmt->fetchColumn();
        
        // Spazio totale usato
        $stmt = $pdo->query("SELECT SUM(filesize) FROM files WHERE is_deleted = 0");
        $total_size = $stmt->fetchColumn() ?? 0;
        
        // Cartelle
        $stmt = $pdo->query("SELECT COUNT(*) FROM folders");
        $folders_count = $stmt->fetchColumn();
        
        // Informazioni database
        $db_size = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
        
        echo json_encode([
            'success' => true,
            'stats' => [
                'users' => $users_count,
                'files' => $files_count,
                'deleted_files' => $deleted_count,
                'folders' => $folders_count,
                'total_size' => $total_size,
                'db_size' => $db_size,
                'db_path' => DB_PATH
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}
?>
