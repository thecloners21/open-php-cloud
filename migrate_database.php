<?php
// migrate_database.php - Script di migrazione database
// Esegui questo file UNA SOLA VOLTA per aggiornare il database esistente

require_once 'config.php';

echo "<h2>Migrazione Database OpenCloud</h2>";
echo "<pre>";

try {
    // Backup del database corrente
    $backup_file = DB_PATH . '.backup_' . date('Y-m-d_H-i-s');
    if (file_exists(DB_PATH)) {
        copy(DB_PATH, $backup_file);
        echo "✓ Backup creato: $backup_file\n\n";
    }
    
    // Verifica colonne tabella users
    echo "Controllo tabella users...\n";
    $stmt = $pdo->query("PRAGMA table_info(users)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $existing_columns = array_column($columns, 'name');
    
    $columns_to_add = [
        'full_name' => "ALTER TABLE users ADD COLUMN full_name TEXT",
        'avatar' => "ALTER TABLE users ADD COLUMN avatar TEXT",
        'role' => "ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'user'",
        'is_active' => "ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1",
        'last_login' => "ALTER TABLE users ADD COLUMN last_login DATETIME",
        'updated_at' => "ALTER TABLE users ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP"
    ];
    
    foreach ($columns_to_add as $col => $sql) {
        if (!in_array($col, $existing_columns)) {
            $pdo->exec($sql);
            echo "✓ Aggiunta colonna: $col\n";
        } else {
            echo "- Colonna già esistente: $col\n";
        }
    }
    
    // Rimuovi colonna theme se esiste (non più usata)
    if (in_array('theme', $existing_columns)) {
        echo "\n! La colonna 'theme' esiste ancora ma non verrà più usata\n";
        echo "  (il tema ora è gestito nel browser)\n";
    }
    
    // Verifica e aggiorna admin esistente
    echo "\nControllo utente admin...\n";
    $stmt = $pdo->query("SELECT id, role FROM users WHERE username = 'admin' OR username = 'demo' LIMIT 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        if (empty($admin['role']) || $admin['role'] != 'admin') {
            $pdo->exec("UPDATE users SET role = 'admin' WHERE id = " . $admin['id']);
            echo "✓ Utente admin aggiornato con ruolo 'admin'\n";
        } else {
            echo "- Utente admin già configurato\n";
        }
    } else {
        // Crea nuovo admin
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, email, password, full_name, role) 
                    VALUES ('admin', 'admin@opencloud.local', '$hash', 'Administrator', 'admin')");
        echo "✓ Creato nuovo utente admin (password: admin123)\n";
    }
    
    // Aggiorna tutti gli utenti senza ruolo
    $pdo->exec("UPDATE users SET role = 'user' WHERE role IS NULL OR role = ''");
    echo "✓ Aggiornati utenti senza ruolo\n";
    
    // Verifica tabella files
    echo "\nControllo tabella files...\n";
    $stmt = $pdo->query("PRAGMA table_info(files)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $existing_columns = array_column($columns, 'name');
    
    if (!in_array('shared_expiry', $existing_columns)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN shared_expiry DATETIME");
        echo "✓ Aggiunta colonna: shared_expiry\n";
    } else {
        echo "- Colonna già esistente: shared_expiry\n";
    }
    
    if (!in_array('folder_id', $existing_columns)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN folder_id INTEGER DEFAULT 0");
        echo "✓ Aggiunta colonna: folder_id\n";
    } else {
        echo "- Colonna già esistente: folder_id\n";
    }
    
    // IMPORTANTE: Rimuovi temporaneamente i vincoli foreign key problematici
    // SQLite non supporta ALTER per modificare foreign key, quindi disabilitiamole
    $pdo->exec("PRAGMA foreign_keys = OFF");
    echo "✓ Foreign keys temporaneamente disabilitate\n";
    
    // Imposta folder_id a 0 per tutti i file con folder_id NULL o invalidi
    $pdo->exec("UPDATE files SET folder_id = 0 WHERE folder_id IS NULL");
    echo "✓ Normalizzati folder_id NULL\n";
    
    // Imposta folder_id a 0 per file con folder_id che non esistono
    $pdo->exec("UPDATE files SET folder_id = 0 WHERE folder_id NOT IN (SELECT id FROM folders) AND folder_id != 0");
    echo "✓ Corretti folder_id invalidi\n";
    
    // Riabilita foreign keys
    $pdo->exec("PRAGMA foreign_keys = ON");
    echo "✓ Foreign keys riabilitate\n";
    
    // Verifica tabella folders
    echo "\nControllo tabella folders...\n";
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='folders'");
    if (!$stmt->fetch()) {
        $pdo->exec("CREATE TABLE folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            parent_id INTEGER DEFAULT 0,
            color TEXT DEFAULT '#0066cc',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        echo "✓ Creata tabella folders\n";
    } else {
        echo "- Tabella folders già esistente\n";
    }
    
    echo "\n";
    echo "==========================================\n";
    echo "✓✓✓ MIGRAZIONE COMPLETATA CON SUCCESSO ✓✓✓\n";
    echo "==========================================\n\n";
    echo "IMPORTANTE:\n";
    echo "1. Backup salvato in: $backup_file\n";
    echo "2. Credenziali admin: admin / admin123\n";
    echo "3. Il tema è ora gestito nel browser (localStorage)\n";
    echo "4. Puoi eliminare questo file (migrate_database.php) dopo la migrazione\n\n";
    echo "<a href='index.php'>← Torna alla home</a>\n";
    
} catch (PDOException $e) {
    echo "\n";
    echo "==========================================\n";
    echo "✗✗✗ ERRORE DURANTE LA MIGRAZIONE ✗✗✗\n";
    echo "==========================================\n\n";
    echo "Errore: " . $e->getMessage() . "\n\n";
    
    if (isset($backup_file) && file_exists($backup_file)) {
        echo "Il backup è disponibile in: $backup_file\n";
        echo "Puoi ripristinarlo rinominandolo in 'database.db'\n";
    }
}

echo "</pre>";
?>
