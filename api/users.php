<?php
// api/users.php - Gestione utenti
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

$user_id = getUserId();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        handleListUsers();
        break;
    case 'create':
        handleCreateUser();
        break;
    case 'update':
        handleUpdateUser();
        break;
    case 'delete':
        handleDeleteUser();
        break;
    case 'change_password':
        handleChangePassword();
        break;
    case 'update_profile':
        handleUpdateProfile();
        break;
    case 'upload_avatar':
        handleUploadAvatar();
        break;
    case 'get_profile':
        handleGetProfile();
        break;
    case 'get_stats':
        handleGetStats();
        break;
    case 'get_activity':
        handleGetActivity();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Azione non valida']);
        exit;
}

function handleListUsers() {
    global $pdo;
    
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Permessi insufficienti']);
        exit;
    }
    
    try {
        $stmt = $pdo->query("SELECT id, username, email, full_name, avatar, role, is_active, last_login, created_at FROM users ORDER BY username");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Converti avatar paths per tutti gli utenti
        foreach ($users as &$user) {
            if (!empty($user['avatar'])) {
                if (strpos($user['avatar'], '/avatars/') !== false || strpos($user['avatar'], '/') === 0) {
                    $user['avatar'] = 'avatars/' . basename($user['avatar']);
                }
            }
        }
        
        echo json_encode(['success' => true, 'users' => $users]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleCreateUser() {
    global $pdo;
    
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Permessi insufficienti']);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $username = $data['username'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $full_name = $data['full_name'] ?? '';
        $role = $data['role'] ?? 'user';
        
        if (empty($username) || empty($email) || empty($password)) {
            echo json_encode(['error' => 'Username, email e password sono obbligatori']);
            exit;
        }
        
        // Verifica se username o email esistono già
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        
        if ($stmt->fetch()) {
            echo json_encode(['error' => 'Username o email già esistenti']);
            exit;
        }
        
        // Crea l'utente
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $email, $hash, $full_name, $role]);
        
        echo json_encode([
            'success' => true,
            'user_id' => $pdo->lastInsertId()
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleUpdateUser() {
    global $pdo;
    
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Permessi insufficienti']);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $id = $data['id'] ?? 0;
        $username = $data['username'] ?? '';
        $email = $data['email'] ?? '';
        $full_name = $data['full_name'] ?? '';
        $role = $data['role'] ?? 'user';
        $is_active = $data['is_active'] ?? 1;
        $new_password = $data['new_password'] ?? '';
        
        if (empty($id)) {
            echo json_encode(['error' => 'ID utente mancante']);
            exit;
        }
        
        // Se è stata fornita una nuova password, aggiorna anche quella
        if (!empty($new_password)) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, is_active = ?, password = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$username, $email, $full_name, $role, $is_active, $password_hash, $id]);
        } else {
            // Altrimenti aggiorna solo gli altri campi
            $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, is_active = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$username, $email, $full_name, $role, $is_active, $id]);
        }
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleDeleteUser() {
    global $pdo, $user_id;
    
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Permessi insufficienti']);
        exit;
    }
    
    $id = $_GET['id'] ?? 0;
    
    try {
        // Non permettere di eliminare se stesso
        if ($id == $user_id) {
            echo json_encode(['error' => 'Non puoi eliminare il tuo account']);
            exit;
        }
        
        // Elimina l'utente (CASCADE eliminerà anche file e cartelle)
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleChangePassword() {
    global $pdo, $user_id;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $current_password = $data['current_password'] ?? '';
        $new_password = $data['new_password'] ?? '';
        $target_user_id = $data['user_id'] ?? $user_id;
        
        // Se non è admin può cambiare solo la propria password
        if (!isAdmin() && $target_user_id != $user_id) {
            http_response_code(403);
            echo json_encode(['error' => 'Permessi insufficienti']);
            exit;
        }
        
        if (empty($new_password)) {
            echo json_encode(['error' => 'La nuova password non può essere vuota']);
            exit;
        }
        
        // Se non è admin, verifica la password corrente
        if (!isAdmin() || $target_user_id == $user_id) {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$target_user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($current_password, $user['password'])) {
                echo json_encode(['error' => 'Password corrente non valida']);
                exit;
            }
        }
        
        // Aggiorna la password
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$hash, $target_user_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleUpdateProfile() {
    global $pdo, $user_id;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $full_name = $data['full_name'] ?? '';
        $email = $data['email'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$full_name, $email, $user_id]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleUploadAvatar() {
    global $pdo, $user_id;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    
    if (!isset($_FILES['avatar'])) {
        echo json_encode(['error' => 'Nessun file caricato']);
        exit;
    }
    
    $file = $_FILES['avatar'];
    
    // Validazione
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Errore nel caricamento']);
        exit;
    }
    
    // Verifica che sia un'immagine
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed)) {
        echo json_encode(['error' => 'Formato non supportato. Usa JPG, PNG, GIF o WEBP']);
        exit;
    }
    
    // Dimensione massima 2MB
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['error' => 'File troppo grande. Max 2MB']);
        exit;
    }
    
    try {
        // Elimina vecchio avatar se esiste
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $old_avatar = $stmt->fetchColumn();
        
        if ($old_avatar && file_exists($old_avatar)) {
            @unlink($old_avatar);
        }
        
        // Salva nuovo avatar
        $avatars_dir = __DIR__ . '/../avatars/';
        if (!file_exists($avatars_dir)) {
            mkdir($avatars_dir, 0777, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'avatar_' . $user_id . '_' . time() . '.' . $extension;
        $destination = $avatars_dir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $stmt = $pdo->prepare("UPDATE users SET avatar = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$destination, $user_id]);
            
            echo json_encode([
                'success' => true,
                'avatar_url' => 'avatars/' . $filename
            ]);
        } else {
            echo json_encode(['error' => 'Errore nel salvataggio del file']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleGetProfile() {
    global $pdo, $user_id;
    
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, full_name, avatar, role, last_login, created_at FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Converti avatar path assoluto in relativo
            if (!empty($user['avatar'])) {
                // Se è un path assoluto, estrai solo il filename
                if (strpos($user['avatar'], '/avatars/') !== false) {
                    $user['avatar'] = 'avatars/' . basename($user['avatar']);
                } elseif (strpos($user['avatar'], 'avatars/') !== 0) {
                    // Se non inizia già con avatars/, aggiungilo
                    $user['avatar'] = 'avatars/' . basename($user['avatar']);
                }
            }
            
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['error' => 'Utente non trovato']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}
?>

function handleGetStats() {
    global $pdo, $user_id;
    
    try {
        // Conta file totali
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM files WHERE user_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
        $stmt->execute([$user_id]);
        $total_files = $stmt->fetchColumn();
        
        // Conta cartelle
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM folders WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $total_folders = $stmt->fetchColumn();
        
        // Calcola spazio usato
        $stmt = $pdo->prepare("SELECT SUM(filesize) as total FROM files WHERE user_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
        $stmt->execute([$user_id]);
        $total_size = $stmt->fetchColumn() ?? 0;
        
        // Conta preferiti
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM files WHERE user_id = ? AND is_favorite = 1 AND (is_deleted = 0 OR is_deleted IS NULL)");
        $stmt->execute([$user_id]);
        $favorites = $stmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'stats' => [
                'total_files' => $total_files,
                'total_folders' => $total_folders,
                'total_size' => $total_size,
                'favorites' => $favorites
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}

function handleGetActivity() {
    global $pdo, $user_id;
    
    try {
        // Prendi ultimi 10 file caricati
        $stmt = $pdo->prepare("
            SELECT 'upload' as type, original_name as description, created_at 
            FROM files 
            WHERE user_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formatta le descrizioni
        foreach ($activities as &$activity) {
            $activity['description'] = 'File caricato: ' . $activity['description'];
        }
        
        echo json_encode([
            'success' => true,
            'activities' => $activities
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Errore: ' . $e->getMessage()]);
    }
    exit;
}
