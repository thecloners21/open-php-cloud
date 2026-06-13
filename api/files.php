<?php
// api/files.php - Versione corretta
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Output buffering per catturare eventuali output indesiderati
ob_start();

// Includi config.php
require_once __DIR__ . '/../config.php';

// Pulisci il buffer e setta header JSON
ob_end_clean();
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
    case 'upload':
        handleUpload();
        break;
    case 'download':
        handleDownload();
        break;
    case 'share':
        handleShare();
        break;
    case 'delete':
        handleDelete();
        break;
    case 'toggle_favorite':
        handleToggleFavorite();
        break;
    case 'get_recent':
        handleGetRecent();
        break;
    case 'get_trash':
        handleGetTrash();
        break;
    case 'restore':
        handleRestore();
        break;
    case 'permanent_delete':
        handlePermanentDelete();
        break;
    case 'empty_trash':
        handleEmptyTrash();
        break;
    case 'get_by_folder':
        handleGetByFolder();
        break;
    case 'rename':
        handleRenameFile();
        break;
    case 'preview':
        handlePreview();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Azione non valida']);
        exit;
}

// ============================================
// FUNZIONI HANDLER
// ============================================

function handleUpload() {
    global $pdo, $user_id;
    
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Metodo non consentito']);
            exit;
        }
        
        if (!isset($_FILES['file'])) {
            echo json_encode(['error' => 'Nessun file caricato']);
            exit;
        }
        
        $file = $_FILES['file'];
        $folder_id = $_POST['folder_id'] ?? 0;
        $tags = $_POST['tags'] ?? '';
        
        // Validazione
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'Errore nel caricamento: ' . $file['error']]);
            exit;
        }
        
        // Verifica che UPLOAD_DIR esista
        if (!file_exists(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0777, true);
        }
        
        // Genera nome file univoco
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $destination = UPLOAD_DIR . $filename;
        
        // Sposta il file caricato
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Verifica che folder_id sia valido
            if ($folder_id > 0) {
                $stmt = $pdo->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
                $stmt->execute([$folder_id, $user_id]);
                if (!$stmt->fetch()) {
                    // Cartella non trovata, usa la root
                    $folder_id = 0;
                }
            }
            
            // Salva nel database
            $stmt = $pdo->prepare("
                INSERT INTO files 
                (user_id, filename, original_name, filepath, filesize, filetype, category, tags, folder_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $category = getCategoryFromExtension($extension);
            
            $stmt->execute([
                $user_id,
                $filename,
                $file['name'],
                $destination,
                $file['size'],
                $file['type'],
                $category,
                $tags,
                $folder_id
            ]);
            
            echo json_encode([
                'success' => true,
                'file_id' => $pdo->lastInsertId(),
                'filename' => $filename
            ]);
        } else {
            echo json_encode(['error' => 'Errore nello spostamento del file. Verifica i permessi della cartella uploads.']);
        }
    } catch (Exception $e) {
        echo json_encode([
            'error' => 'Errore durante l\'upload: ' . $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
    exit;
}

function handleDownload() {
    global $pdo, $user_id;
    
    $fileId = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($file) {
        if (!empty($file['filepath']) && file_exists($file['filepath'])) {
            $filePath = $file['filepath'];
        } else {
            $filePath = UPLOAD_DIR . $file['filename'];
            
            if (!file_exists($filePath)) {
                $filePath = dirname(__DIR__) . '/uploads/' . $file['filename'];
            }
        }
        
        if (file_exists($filePath)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
    
    http_response_code(404);
    echo 'File non trovato';
    exit;
}

function handleShare() {
    global $pdo, $user_id;
    
    $fileId = $_GET['id'] ?? 0;
    
    try {
        // Verifica che il file esista
        $stmt = $pdo->prepare("SELECT id FROM files WHERE id = ? AND user_id = ?");
        $stmt->execute([$fileId, $user_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => 'File non trovato']);
            exit;
        }
        
        // Genera un token di condivisione univoco
        $token = bin2hex(random_bytes(16));
        $expiry = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        // Aggiorna il file
        $stmt = $pdo->prepare("UPDATE files SET is_shared = 1, shared_token = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$token, $fileId, $user_id]);
        
        // Prova ad aggiornare anche shared_expiry se la colonna esiste
        try {
            $stmt = $pdo->prepare("UPDATE files SET shared_expiry = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$expiry, $fileId, $user_id]);
        } catch (PDOException $e) {
            // Ignora se la colonna non esiste
        }
        
        // Costruisci URL corretto per shared.php (nella root, non in api/)
        $base = rtrim(BASE_URL, '/');
        // Rimuovi /api se presente nell'URL
        $base = preg_replace('#/api$#', '', $base);
        $share_url = $base . '/shared.php?token=' . $token;
        
        echo json_encode([
            'success' => true,
            'share_url' => $share_url,
            'expiry' => $expiry
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleDelete() {
    global $pdo, $user_id;
    
    $fileId = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("UPDATE files SET is_deleted = 1, deleted_at = datetime('now') WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

function handleToggleFavorite() {
    global $pdo, $user_id;
    
    $fileId = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("UPDATE files SET is_favorite = NOT is_favorite WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

function handleGetRecent() {
    global $pdo, $user_id;
    
    $stmt = $pdo->prepare("
        SELECT * FROM files 
        WHERE user_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([$user_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

function handleGetTrash() {
    global $pdo, $user_id;
    
    $stmt = $pdo->prepare("
        SELECT * FROM files 
        WHERE user_id = ? AND is_deleted = 1
        ORDER BY deleted_at DESC
    ");
    $stmt->execute([$user_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

function handleRestore() {
    global $pdo, $user_id;
    
    $fileId = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("UPDATE files SET is_deleted = 0, deleted_at = NULL WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

function handlePermanentDelete() {
    global $pdo, $user_id;
    
    $fileId = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($file) {
        $filePath = UPLOAD_DIR . $file['filename'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        
        $stmt = $pdo->prepare("DELETE FROM files WHERE id = ? AND user_id = ?");
        $stmt->execute([$fileId, $user_id]);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

function handleEmptyTrash() {
    global $pdo, $user_id;
    
    $stmt = $pdo->prepare("SELECT * FROM files WHERE user_id = ? AND is_deleted = 1");
    $stmt->execute([$user_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($files as $file) {
        $filePath = UPLOAD_DIR . $file['filename'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
    
    $stmt = $pdo->prepare("DELETE FROM files WHERE user_id = ? AND is_deleted = 1");
    $stmt->execute([$user_id]);
    
    echo json_encode(['success' => true, 'deleted' => count($files)]);
    exit;
}

function handleGetByFolder() {
    global $pdo, $user_id;
    
    $folder_id = $_GET['folder_id'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT * FROM files 
        WHERE user_id = ? AND folder_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user_id, $folder_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

function handleRenameFile() {
    global $pdo, $user_id;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $file_id = $data['file_id'] ?? 0;
    $new_name = $data['new_name'] ?? '';
    
    if (empty($new_name)) {
        echo json_encode(['error' => 'Il nome non può essere vuoto']);
        exit;
    }
    
    $stmt = $pdo->prepare("UPDATE files SET original_name = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$new_name, $file_id, $user_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

function handlePreview() {
    global $pdo, $user_id;
    
    $fileId = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'File non trovato';
        exit;
    }
    
    if (!empty($file['filepath']) && file_exists($file['filepath'])) {
        $filePath = $file['filepath'];
    } else {
        $possiblePaths = [
            UPLOAD_DIR . $file['filename'],
            dirname(__DIR__) . '/uploads/' . $file['filename'],
            __DIR__ . '/../uploads/' . $file['filename']
        ];
        
        $foundPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $foundPath = $path;
                break;
            }
        }
        
        if (!$foundPath) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'File fisico non trovato';
            exit;
        }
        
        $filePath = $foundPath;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);
    
    if (!$mimeType || $mimeType === 'application/octet-stream') {
        $mimeType = $file['filetype'];
    }
    
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . $file['original_name'] . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: public, max-age=3600');
    
    readfile($filePath);
    exit;
}
?>
