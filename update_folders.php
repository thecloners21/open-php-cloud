<?php
// update_folders.php - Aggiorna database per cartelle
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

try {
    echo "Aggiunta tabella folders...<br>";
    
    // Crea tabella folders
    $pdo->exec("CREATE TABLE IF NOT EXISTS folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        parent_id INTEGER DEFAULT 0,
        color TEXT DEFAULT '#0066cc',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    
    // Aggiungi colonna folder_id alla tabella files
    $stmt = $pdo->query("PRAGMA table_info(files)");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['name'];
    }
    
    if (!in_array('folder_id', $columns)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN folder_id INTEGER DEFAULT 0");
        echo "Aggiunta colonna folder_id a files<br>";
    }
    
    echo "<br><strong>Aggiornamento completato!</strong><br>";
    echo "<a href='index.php'>Vai all'applicazione</a>";
    
} catch (PDOException $e) {
    echo "Errore: " . $e->getMessage();
}
?>
