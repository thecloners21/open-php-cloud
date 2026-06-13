<?php
// api/folders.php
error_reporting(0);
ini_set('display_errors', 0);

// Includi config.php
require_once __DIR__ . '/../config.php';

// Header JSON sempre
header('Content-Type: application/json');

// Verifica che l'utente sia autenticato
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

$user_id = getUserId();


// Ottieni l'azione dalla richiesta
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        handleListFolders();
        break;
    case 'create':
        handleCreateFolder();
        break;
    case 'rename':
        handleRenameFolder();
        break;
    case 'delete':
        handleDeleteFolder();
        break;
    case 'move_files':
        handleMoveFiles();
        break;
    case 'get_path':
        handleGetPath();
        break;
    case 'list_in_folder':
        handleListInFolder();
        break;
    case 'move':
        handleMoveFolder();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Azione non valida']);
        exit;
}

// ============================================
// FUNZIONI HANDLER
// ============================================

function handleListFolders() {
    global $pdo, $user_id;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM folders WHERE user_id = ? ORDER BY name");
        $stmt->execute([$user_id]);
        $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Aggiungi sempre la cartella root
        $folders = array_merge([['id' => 0, 'name' => 'Root', 'color' => '#0066cc']], $folders);
        
        echo json_encode(['success' => true, 'folders' => $folders]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleCreateFolder() {
    global $pdo, $user_id;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = $data['name'] ?? '';
        $parent_id = $data['parent_id'] ?? 0;
        
        if (empty($name)) {
            echo json_encode(['error' => 'Il nome della cartella non può essere vuoto']);
            exit;
        }
        
        // Verifica se esiste già una cartella con lo stesso nome nello stesso parent
        $stmt = $pdo->prepare("SELECT id FROM folders WHERE user_id = ? AND name = ? AND parent_id = ?");
        $stmt->execute([$user_id, $name, $parent_id]);
        
        if ($stmt->fetch()) {
            echo json_encode(['error' => 'Una cartella con questo nome esiste già in questa posizione']);
            exit;
        }
        
        // Crea la cartella con parent_id
        $stmt = $pdo->prepare("INSERT INTO folders (user_id, name, parent_id) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $name, $parent_id]);
        
        echo json_encode([
            'success' => true,
            'folder_id' => $pdo->lastInsertId(),
            'name' => $name,
            'parent_id' => $parent_id
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleRenameFolder() {
    global $pdo, $user_id;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $folder_id = $data['folder_id'] ?? 0;
        $new_name = $data['new_name'] ?? '';
        
        if (empty($new_name)) {
            echo json_encode(['error' => 'Il nuovo nome non può essere vuoto']);
            exit;
        }
        
        // Verifica che la cartella esista e appartenga all'utente
        $stmt = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folder_id, $user_id]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['error' => 'Cartella non trovata']);
            exit;
        }
        
        // Aggiorna il nome
        $stmt = $pdo->prepare("UPDATE folders SET name = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$new_name, $folder_id, $user_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleDeleteFolder() {
    global $pdo, $user_id;
    
    $folder_id = $_GET['id'] ?? 0;
    
    try {
        if ($folder_id == 0) {
            echo json_encode(['error' => 'Non è possibile eliminare la cartella root']);
            exit;
        }
        
        // Verifica che la cartella esista e appartenga all'utente
        $stmt = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folder_id, $user_id]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['error' => 'Cartella non trovata']);
            exit;
        }
        
        // Sposta tutti i file dalla cartella alla root (folder_id = 0)
        $stmt = $pdo->prepare("UPDATE files SET folder_id = 0 WHERE folder_id = ? AND user_id = ?");
        $stmt->execute([$folder_id, $user_id]);
        
        // Elimina la cartella
        $stmt = $pdo->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folder_id, $user_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleMoveFiles() {
    global $pdo, $user_id;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['error' => 'JSON non valido: ' . json_last_error_msg()]);
            exit;
        }
        
        $file_ids = $data['file_ids'] ?? [];
        $folder_id = $data['folder_id'] ?? 0;
        
        if (empty($file_ids)) {
            echo json_encode(['error' => 'Nessun file selezionato']);
            exit;
        }
        
        // Assicurati che sia un array
        if (!is_array($file_ids)) {
            $file_ids = [$file_ids];
        }
        
        // Prepara la query per spostare i file
        $placeholders = str_repeat('?,', count($file_ids) - 1) . '?';
        $sql = "UPDATE files SET folder_id = ? WHERE id IN ($placeholders) AND user_id = ?";
        
        // Costruisci l'array di parametri
        $params = array_merge([$folder_id], $file_ids, [$user_id]);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $moved = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'moved' => $moved,
            'message' => "Spostati $moved file"
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

// Ottieni il percorso (breadcrumb) di una cartella
function handleGetPath() {
    global $pdo, $user_id;
    
    $folder_id = $_GET['id'] ?? 0;
    
    try {
        $path = [];
        $current_id = $folder_id;
        $max_depth = 50;
        $depth = 0;
        
        while ($current_id > 0 && $depth < $max_depth) {
            $stmt = $pdo->prepare("SELECT id, name, parent_id FROM folders WHERE id = ? AND user_id = ?");
            $stmt->execute([$current_id, $user_id]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$folder) break;
            
            array_unshift($path, [
                'id' => (int)$folder['id'],
                'name' => $folder['name']
            ]);
            
            $current_id = $folder['parent_id'];
            $depth++;
        }
        
        echo json_encode(['success' => true, 'path' => $path]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

// Lista cartelle in una cartella specifica (per griglia)
function handleListInFolder() {
    global $pdo, $user_id;
    
    $parent_id = $_GET['parent_id'] ?? 0;
    
    try {
        $stmt = $pdo->prepare("
            SELECT f.*, 
                   (SELECT COUNT(*) FROM files WHERE folder_id = f.id AND is_deleted = 0) as files_count
            FROM folders f 
            WHERE f.user_id = ? AND f.parent_id = ?
            ORDER BY f.name
        ");
        $stmt->execute([$user_id, $parent_id]);
        $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'folders' => $folders]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

// Sposta cartella
function handleMoveFolder() {
    global $pdo, $user_id;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $folder_id = $data['folder_id'] ?? 0;
        $new_parent_id = $data['new_parent_id'] ?? 0;
        
        if (!$folder_id) {
            echo json_encode(['error' => 'ID cartella non specificato']);
            exit;
        }
        
        // Verifica che la cartella appartenga all'utente
        $stmt = $pdo->prepare("SELECT id, parent_id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folder_id, $user_id]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$folder) {
            echo json_encode(['error' => 'Cartella non trovata']);
            exit;
        }
        
        // Verifica che non si stia spostando in se stessa
        if ($folder_id == $new_parent_id) {
            echo json_encode(['error' => 'Non puoi spostare una cartella in se stessa']);
            exit;
        }
        
        // Verifica che il nuovo parent non sia un figlio della cartella (evita loop)
        if ($new_parent_id > 0) {
            $current_parent = $new_parent_id;
            $depth = 0;
            $max_depth = 50;
            
            while ($current_parent > 0 && $depth < $max_depth) {
                if ($current_parent == $folder_id) {
                    echo json_encode(['error' => 'Non puoi spostare una cartella in una sua sottocartella']);
                    exit;
                }
                
                $stmt = $pdo->prepare("SELECT parent_id FROM folders WHERE id = ? AND user_id = ?");
                $stmt->execute([$current_parent, $user_id]);
                $parent = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$parent) break;
                $current_parent = $parent['parent_id'];
                $depth++;
            }
        }
        
        // Sposta la cartella
        $stmt = $pdo->prepare("UPDATE folders SET parent_id = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$new_parent_id, $folder_id, $user_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}
?>
