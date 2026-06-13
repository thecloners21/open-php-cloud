<?php
// update_database.php
session_start();

// Configurazioni
$db_path = __DIR__ . '/database.db';

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Aggiornamento database in corso...<br>";
    
    // Aggiungi colonne mancanti alla tabella files
    $columns_to_add = [
        'is_deleted' => 'BOOLEAN DEFAULT 0',
        'category' => 'TEXT DEFAULT "other"',
        'thumbnail' => 'TEXT',
        'is_favorite' => 'BOOLEAN DEFAULT 0',
        'deleted_at' => 'TIMESTAMP',
        'view_count' => 'INTEGER DEFAULT 0',
        'last_viewed' => 'TIMESTAMP'
    ];
    
    // Verifica quali colonne esistono già
    $stmt = $pdo->query("PRAGMA table_info(files)");
    $existing_columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_columns[] = $row['name'];
    }
    
    // Aggiungi colonne mancanti
    foreach ($columns_to_add as $column => $type) {
        if (!in_array($column, $existing_columns)) {
            $sql = "ALTER TABLE files ADD COLUMN $column $type";
            $pdo->exec($sql);
            echo "Aggiunta colonna: $column ($type)<br>";
        } else {
            echo "Colonna già esistente: $column<br>";
        }
    }
    
    // Verifica colonne della tabella users
    $stmt = $pdo->query("PRAGMA table_info(users)");
    $existing_columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_columns[] = $row['name'];
    }
    
    // Aggiungi colonna theme se non esiste
    if (!in_array('theme', $existing_columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme TEXT DEFAULT 'light'");
        echo "Aggiunta colonna: theme<br>";
    } else {
        echo "Colonna già esistente: theme<br>";
    }
    
    // Crea cartelle se non esistono
    if (!file_exists(__DIR__ . '/uploads')) {
        mkdir(__DIR__ . '/uploads', 0777, true);
        echo "Creata cartella: uploads<br>";
    }
    
    if (!file_exists(__DIR__ . '/thumbnails')) {
        mkdir(__DIR__ . '/thumbnails', 0777, true);
        echo "Creata cartella: thumbnails<br>";
    }
    
    echo "<br><strong>Aggiornamento completato con successo!</strong><br>";
    echo "<a href='index.php'>Vai all'applicazione</a>";
    
} catch (PDOException $e) {
    echo "Errore: " . $e->getMessage();
}
?>
