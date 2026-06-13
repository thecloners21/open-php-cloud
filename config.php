<?php
// config.php - Sistema multi-utente con database configurabile
session_start();

// Carica configurazioni personalizzate se esistono
$config_file = __DIR__ . '/app_config.json';
if (file_exists($config_file)) {
    $app_config = json_decode(file_get_contents($config_file), true);
} else {
    // Configurazioni di default
    $app_config = [
        'db_path' => __DIR__ . '/database.db',
        'db_dir' => __DIR__,
        'upload_dir' => __DIR__ . '/uploads/',
        'thumbnail_dir' => __DIR__ . '/thumbnails/'
    ];
    file_put_contents($config_file, json_encode($app_config, JSON_PRETTY_PRINT));
}

// Configurazioni di base
define('BASE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/');
define('UPLOAD_DIR', $app_config['upload_dir']);
define('THUMBNAIL_DIR', $app_config['thumbnail_dir']);
define('DB_PATH', $app_config['db_path']);

// Crea cartelle se non esistono
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}
if (!file_exists(THUMBNAIL_DIR)) {
    mkdir(THUMBNAIL_DIR, 0777, true);
}

// Crea la directory del database se specificata e non esiste
$db_dir = dirname(DB_PATH);
if (!file_exists($db_dir)) {
    mkdir($db_dir, 0777, true);
}

// Connessione SQLite
try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Disabilita foreign keys per evitare problemi
    $pdo->exec('PRAGMA foreign_keys = OFF');
    
    // Crea tabella users con campi aggiuntivi
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        full_name TEXT,
        avatar TEXT,
        role TEXT DEFAULT 'user',
        is_active INTEGER DEFAULT 1,
        last_login DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Crea tabella folders
    $pdo->exec("CREATE TABLE IF NOT EXISTS folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        parent_id INTEGER DEFAULT 0,
        color TEXT DEFAULT '#0066cc',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Crea tabella files
    $pdo->exec("CREATE TABLE IF NOT EXISTS files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        filename TEXT NOT NULL,
        original_name TEXT NOT NULL,
        filepath TEXT NOT NULL,
        thumbnail TEXT,
        filesize INTEGER NOT NULL,
        filetype TEXT NOT NULL,
        category TEXT DEFAULT 'other',
        tags TEXT DEFAULT '',
        folder_id INTEGER DEFAULT 0,
        is_favorite INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0,
        is_shared INTEGER DEFAULT 0,
        shared_token TEXT,
        shared_expiry DATETIME,
        view_count INTEGER DEFAULT 0,
        last_viewed DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME
    )");
    
    // Verifica se esiste almeno un utente admin
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        // Crea utente admin di default
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, email, password, full_name, role) 
                    VALUES ('admin', 'admin@opencloud.local', '$hash', 'Administrator', 'admin')");
    }
    
} catch (PDOException $e) {
    die("Errore database: " . $e->getMessage());
}

// Funzioni helper
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getUser() {
    global $pdo;
    if (!isLoggedIn()) return null;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([getUserId()]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function isAdmin() {
    $user = getUser();
    return $user && $user['role'] === 'admin';
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getFileIcon($ext) {
    $icons = [
        'txt' => 'fa-file-alt',
        'pdf' => 'fa-file-pdf',
        'doc' => 'fa-file-word',
        'docx' => 'fa-file-word',
        'xls' => 'fa-file-excel',
        'xlsx' => 'fa-file-excel',
        'jpg' => 'fa-image',
        'jpeg' => 'fa-image',
        'png' => 'fa-image',
        'gif' => 'fa-image',
        'zip' => 'fa-file-archive',
        'rar' => 'fa-file-archive',
        'mp3' => 'fa-file-audio',
        'wav' => 'fa-file-audio',
        'mp4' => 'fa-file-video',
        'avi' => 'fa-file-video',
        'mkv' => 'fa-file-video',
        'ppt' => 'fa-file-powerpoint',
        'pptx' => 'fa-file-powerpoint'
    ];
    return $icons[strtolower($ext)] ?? 'fa-file';
}

function getCategoryFromExtension($ext) {
    $image = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $video = ['mp4', 'avi', 'mkv', 'mov', 'wmv'];
    $audio = ['mp3', 'wav', 'ogg', 'flac'];
    $document = ['txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    
    $ext = strtolower($ext);
    if (in_array($ext, $image)) return 'images';
    if (in_array($ext, $video)) return 'videos';
    if (in_array($ext, $audio)) return 'audio';
    if (in_array($ext, $document)) return 'documents';
    return 'other';
}

function updateUserLastLogin($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?");
    $stmt->execute([$user_id]);
}
?>
