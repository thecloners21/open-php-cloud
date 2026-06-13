<?php
// index.php - Versione completa con tutte le funzionalità
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = getUserId();

// Leggi i parametri GET
$filter = $_GET['filter'] ?? '';
$category = $_GET['category'] ?? '';
$folder_id = $_GET['folder'] ?? 0;

// Determina se siamo nel cestino
$is_trash = ($filter == 'trash');

// Prepara la query base - Include filtro folder_id
$params = [$user_id];
$where_conditions = [];

if ($is_trash) {
    // File nel cestino (ignora folder)
    $where_conditions = ["is_deleted = 1"];
} else {
    $where_conditions = ["(is_deleted = 0 OR is_deleted IS NULL)"];
    
    if ($filter == 'favorites') {
        // File preferiti
        $where_conditions[] = "is_favorite = 1";
    } elseif ($filter == 'recent') {
        // File recenti (ultimi 7 giorni)
        $where_conditions[] = "created_at >= datetime('now', '-7 days')";
    } elseif ($category) {
        // File per categoria
        $where_conditions[] = "category = ?";
        $params[] = $category;
    }
    
    // Aggiungi filtro cartella se specificato
    if ($folder_id > 0) {
        $where_conditions[] = "folder_id = ?";
        $params[] = $folder_id;
    } else {
        // Root - cartella 0 (default)
        $where_conditions[] = "(folder_id = 0 OR folder_id IS NULL)";
    }
}

// Costruisci la query
$sql = "SELECT * FROM files WHERE user_id = ?";
if (!empty($where_conditions)) {
    $sql .= " AND " . implode(" AND ", $where_conditions);
}

// Ordina
if ($is_trash) {
    $sql .= " ORDER BY deleted_at DESC";
} else {
    $sql .= " ORDER BY created_at DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcolo spazio utilizzato (solo file non eliminati)
$stmt = $pdo->prepare("SELECT SUM(filesize) as total_size FROM files WHERE user_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)");
$stmt->execute([$user_id]);
$storage = $stmt->fetch(PDO::FETCH_ASSOC);
$used_space = $storage['total_size'] ?? 0;
$total_space = 15 * 1024 * 1024 * 1024; // 15GB
$percent_used = min(100, ($used_space / $total_space) * 100);

// Conta file nel cestino
$stmt = $pdo->prepare("SELECT COUNT(*) as trash_count FROM files WHERE user_id = ? AND is_deleted = 1");
$stmt->execute([$user_id]);
$trash_count = $stmt->fetch(PDO::FETCH_ASSOC)['trash_count'] ?? 0;

// Recupera info utente
$stmt = $pdo->prepare("SELECT username, full_name, avatar, role FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenCloud - File Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0066cc;
            --sidebar-width: 250px;
            --header-height: 60px;
            --shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        body {
            display: flex;
            min-height: 100vh;
            background-color: #f5f7fa;
            color: #333;
        }

        /* SIDEBAR - Desktop */
        .sidebar {
            width: var(--sidebar-width);
            background: white;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: transform 0.3s ease;
        }

        .logo {
            padding: 20px;
            border-bottom: 1px solid #eee;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo i {
            font-size: 1.8rem;
        }

        .nav-section {
            padding: 20px 0;
            border-bottom: 1px solid #eee;
        }

        .nav-title {
            padding: 0 20px 10px;
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #444;
            text-decoration: none;
            transition: background 0.2s;
            cursor: pointer;
        }

        .nav-item:hover {
            background-color: #f0f5ff;
            color: var(--primary-color);
        }

        .nav-item.active {
            background-color: #e6f0ff;
            color: var(--primary-color);
            border-left: 3px solid var(--primary-color);
        }

        .nav-item i {
            width: 20px;
            text-align: center;
        }

        /* MAIN CONTENT */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            margin-top: var(--header-height);
        }

        /* HEADER */
        .header {
            position: fixed;
            top: 0;
            left: 250px;
            right: 0;
            height: var(--header-height);
            background: white;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 15px;
            z-index: 100;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.3rem;
            color: #555;
            cursor: pointer;
        }

        .search-bar {
            flex: 1;
            max-width: 500px;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            background-color: #f9f9f9;
        }

        .search-bar i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
        }

        .user-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .selection-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            padding-right: 15px;
            border-right: 1px solid #eee;
        }

        .selection-count {
            font-weight: 600;
            color: var(--primary-color);
            margin-right: 10px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #0052b3;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        /* CONTENT AREA */
        .content {
            flex: 1;
            padding: 25px;
            overflow-y: auto;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #222;
        }

        .filter-select {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background-color: white;
            color: #555;
        }

        /* FILE TABLE - Desktop */
        .file-table {
            width: 100%;
            background: white;
            border-radius: 10px;
            box-shadow: var(--shadow);
            overflow: hidden;
            border-collapse: collapse;
        }

        .file-table thead {
            background-color: #f8f9fa;
            border-bottom: 2px solid #eee;
        }

        .file-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 0.9rem;
        }

        .file-table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
        }

        .file-table tbody tr:hover {
            background-color: #f9fbfd;
        }

        .file-name {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .file-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .txt { background-color: #4CAF50; }
        .jpg, .jpeg { background-color: #FF9800; }
        .png { background-color: #2196F3; }
        .pdf { background-color: #F44336; }
        .doc, .docx { background-color: #2196F3; }
        .xls, .xlsx { background-color: #217346; }
        .ppt, .pptx { background-color: #D24726; }
        .zip, .rar { background-color: #9C27B0; }
        .mp3, .wav { background-color: #FF5722; }
        .mp4, .avi { background-color: #E91E63; }

        .file-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
        }

        .action-btn:hover {
            background-color: #f0f0f0;
            color: var(--primary-color);
        }

        .file-checkbox {
            cursor: pointer;
            width: 18px;
            height: 18px;
        }

        /* FILE CARDS - Mobile */
        .file-cards {
            display: none;
            flex-direction: column;
            gap: 15px;
        }

        .file-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }

        .file-card-info {
            flex: 1;
        }

        .file-card-name {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .file-card-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: #777;
        }

        /* BREADCRUMB NAVIGATION */
        .breadcrumb-container {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 0;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .breadcrumb-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
            color: #666;
            cursor: pointer;
            transition: all 0.2s;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .breadcrumb-item:hover {
            background-color: #f0f5ff;
            color: var(--primary-color);
        }

        .breadcrumb-item.active {
            color: #333;
            font-weight: 500;
            cursor: default;
        }

        .breadcrumb-item.active:hover {
            background-color: transparent;
        }

        .breadcrumb-separator {
            color: #bbb;
            font-size: 0.75rem;
        }

        /* Cartelle nella griglia */
        .folder-row {
            background: #fafbff !important;
            cursor: pointer !important;
        }

        .folder-row:hover {
            background: #f0f5ff !important;
        }

        .folder-badge-dynamic {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            display: inline-block;
            transition: all 0.3s;
        }

        /* FOOTER */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 15px 25px;
            border-top: 1px solid #eee;
            background-color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
            color: #666;
            z-index: 40;
        }
        
        /* Compensa spazio footer fisso */
        .content {
            padding-bottom: 70px;
        }

        .version {
            font-weight: 500;
        }

        /* MODALI */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 20px;
        }

        /* UTILITY */
        .text-warning { color: #FF9800; }
        .text-muted { color: #999; }
        .text-danger { color: #dc3545; }
        
        /* NOTIFICHE */
        .notification {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }
        
        .notification.info {
            background-color: #e6f7ff;
            border: 1px solid #bae7ff;
            color: #006d75;
        }
        
        .notification.warning {
            background-color: #fffbe6;
            border: 1px solid #ffe58f;
            color: #874d00;
        }
        
        .notification.danger {
            background-color: #fff2f0;
            border: 1px solid #ffccc7;
            color: #a8071a;
        }

        /* TEMI COMPLETI E CORRETTI */

        /* TEMA CHIARO (default) */
        body.light-theme {
            --primary-color: #0066cc;
            --secondary-color: #5c6bc0;
            --bg-color: #f5f7fa;
            --sidebar-bg: white;
            --header-bg: white;
            --card-bg: white;
            --text-color: #333333;
            --text-secondary: #666666;
            --border-color: #dddddd;
            --hover-bg: #f0f5ff;
            --table-header-bg: #f8f9fa;
            --table-row-hover: #f9fbfd;
            --modal-bg: white;
            --input-bg: white;
            --shadow-color: rgba(0, 0, 0, 0.1);
        }

        /* TEMA SCURO */
        body.dark-theme {
            --primary-color: #4dabf7;
            --secondary-color: #7986cb;
            --bg-color: #121212;
            --sidebar-bg: #1e1e1e;
            --header-bg: #1e1e1e;
            --card-bg: #1e1e1e;
            --text-color: #e0e0e0;
            --text-secondary: #aaaaaa;
            --border-color: #333333;
            --hover-bg: #2d3748;
            --table-header-bg: #2d2d2d;
            --table-row-hover: #2d2d2d;
            --modal-bg: #1e1e1e;
            --input-bg: #2d2d2d;
            --shadow-color: rgba(0, 0, 0, 0.3);
        }

        /* TEMA BLU */
        body.blue-theme {
            --primary-color: #1e88e5;
            --secondary-color: #1565c0;
            --bg-color: #e3f2fd;
            --sidebar-bg: #bbdefb;
            --header-bg: #90caf9;
            --card-bg: white;
            --text-color: #0d47a1;
            --text-secondary: #1565c0;
            --border-color: #64b5f6;
            --hover-bg: #bbdefb;
            --table-header-bg: #e3f2fd;
            --table-row-hover: #f5faff;
            --modal-bg: white;
            --input-bg: white;
            --shadow-color: rgba(30, 136, 229, 0.2);
        }

        /* TEMA VERDE */
        body.green-theme {
            --primary-color: #2e7d32;
            --secondary-color: #1b5e20;
            --bg-color: #e8f5e9;
            --sidebar-bg: #c8e6c9;
            --header-bg: #a5d6a7;
            --card-bg: white;
            --text-color: #1b5e20;
            --text-secondary: #2e7d32;
            --border-color: #81c784;
            --hover-bg: #c8e6c9;
            --table-header-bg: #e8f5e9;
            --table-row-hover: #f1f8e9;
            --modal-bg: white;
            --input-bg: white;
            --shadow-color: rgba(46, 125, 50, 0.2);
        }

        /* APPLICAZIONE DEI TEMI A TUTTI GLI ELEMENTI */
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }

        .sidebar {
            background: var(--sidebar-bg);
            box-shadow: 0 2px 10px var(--shadow-color);
        }

        .logo {
            color: var(--primary-color);
            border-bottom-color: var(--border-color);
        }

        .nav-section {
            border-bottom-color: var(--border-color);
        }

        .nav-title {
            color: var(--text-secondary);
        }

        .nav-item {
            color: var(--text-color);
        }

        .nav-item:hover {
            background-color: var(--hover-bg);
            color: var(--primary-color);
        }

        .nav-item.active {
            background-color: var(--hover-bg);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
        }

        .header {
            background: var(--header-bg);
            box-shadow: 0 2px 10px var(--shadow-color);
        }

        .search-bar input {
            background-color: var(--input-bg);
            border-color: var(--border-color);
            color: var(--text-color);
        }

        .search-bar input::placeholder {
            color: var(--text-secondary);
        }

        .search-bar i {
            color: var(--text-secondary);
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
        }

        .btn-secondary {
            background-color: var(--text-secondary);
            color: white;
        }

        .btn-secondary:hover {
            background-color: var(--text-color);
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .content {
            background-color: var(--bg-color);
        }

        .page-title {
            color: var(--text-color);
        }

        .filter-select {
            background-color: var(--input-bg);
            border-color: var(--border-color);
            color: var(--text-color);
        }

        .file-table {
            background: var(--card-bg);
            color: var(--text-color);
            box-shadow: 0 2px 10px var(--shadow-color);
        }

        .file-table thead {
            background-color: var(--table-header-bg);
            border-bottom-color: var(--border-color);
        }

        .file-table th {
            color: var(--text-color);
        }

        .file-table td {
            border-bottom-color: var(--border-color);
            color: var(--text-color);
        }

        .file-table tbody tr:hover {
            background-color: var(--table-row-hover);
        }

        .file-name-text {
            color: var(--text-color);
        }

        .action-btn {
            color: var(--text-secondary);
        }

        .action-btn:hover {
            background-color: var(--hover-bg);
            color: var(--primary-color);
        }

        .file-card {
            background: var(--card-bg);
            color: var(--text-color);
            box-shadow: 0 2px 10px var(--shadow-color);
        }

        .file-card-name {
            color: var(--text-color);
        }

        .file-card-meta {
            color: var(--text-secondary);
        }

        .footer {
            background-color: var(--header-bg);
            color: var(--text-color);
            border-top-color: var(--border-color);
        }

        .modal-content {
            background: var(--modal-bg);
            color: var(--text-color);
        }

        .modal-header {
            border-bottom-color: var(--border-color);
        }

        .notification.info {
            background-color: rgba(77, 171, 247, 0.1);
            border-color: rgba(77, 171, 247, 0.3);
            color: var(--primary-color);
        }

        .notification.warning {
            background-color: rgba(255, 193, 7, 0.1);
            border-color: rgba(255, 193, 7, 0.3);
            color: #ffc107;
        }

        .notification.danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-color: rgba(220, 53, 69, 0.3);
            color: #dc3545;
        }

        /* CORREZIONI SPECIFICHE PER TEMA SCURO */
        body.dark-theme .file-icon {
            opacity: 0.9;
        }

        body.dark-theme .txt { background-color: #4CAF50; }
        body.dark-theme .jpg, 
        body.dark-theme .jpeg { background-color: #FF9800; }
        body.dark-theme .png { background-color: #2196F3; }
        body.dark-theme .pdf { background-color: #F44336; }
        body.dark-theme .doc, 
        body.dark-theme .docx { background-color: #2196F3; }
        body.dark-theme .xls, 
        body.dark-theme .xlsx { background-color: #217346; }
        body.dark-theme .ppt, 
        body.dark-theme .pptx { background-color: #D24726; }
        body.dark-theme .zip, 
        body.dark-theme .rar { background-color: #9C27B0; }
        body.dark-theme .mp3, 
        body.dark-theme .wav { background-color: #FF5722; }
        body.dark-theme .mp4, 
        body.dark-theme .avi { background-color: #E91E63; }

        /* Stili per i pulsanti delle cartelle */
        .folder-actions {
            margin-left: auto;
            display: flex;
            gap: 5px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .nav-item:hover .folder-actions {
            opacity: 1;
        }

        .folder-actions .action-btn {
            padding: 4px 8px;
            font-size: 0.8rem;
            background-color: transparent;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .folder-actions .action-btn:hover {
            background-color: var(--hover-bg);
            color: var(--primary-color);
        }

        /* Stili per il selettore del tema */
        .theme-selector select {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--border-color);
            padding: 5px;
            border-radius: 4px;
        }

        .theme-selector select option {
            background-color: var(--modal-bg);
            color: var(--text-color);
        }

        /* Stili per la barra di scorrimento */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-color);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        /* Stili per l'anteprima */
        #previewModal .modal-content {
            max-width: 90vw;
            max-height: 90vh;
            width: auto;
            min-width: 500px;
        }

        .preview-container {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .preview-image {
            max-width: 100%;
            max-height: 70vh;
            object-fit: contain;
        }

        .preview-document {
            width: 100%;
            height: 70vh;
            border: none;
        }

        .preview-text {
            max-height: 70vh;
            overflow: auto;
            padding: 20px;
            background-color: var(--input-bg);
            border-radius: 5px;
            white-space: pre-wrap;
            font-family: monospace;
        }

        /* RESPONSIVE DESIGN */
        @media (max-width: 992px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: var(--sidebar-width);
                height: 100vh;
                overflow-y: auto;
                overflow-x: hidden;
                transform: translateX(-100%);
                z-index: 200;
                -webkit-overflow-scrolling: touch;
            }

            .sidebar.open {
                transform: translateX(0);
            }
            
            /* Assicura che tutte le sezioni siano visibili */
            .nav-section {
                flex-shrink: 0;
            }
            
            /* Padding extra in fondo per iOS */
            .sidebar::after {
                content: '';
                display: block;
                height: 30px;
            }
            
            /* Scrollbar personalizzata per sidebar mobile */
            .sidebar::-webkit-scrollbar {
                width: 5px;
            }
            
            .sidebar::-webkit-scrollbar-track {
                background: transparent;
            }
            
            .sidebar::-webkit-scrollbar-thumb {
                background: rgba(0, 0, 0, 0.2);
                border-radius: 3px;
            }
            
            .sidebar::-webkit-scrollbar-thumb:hover {
                background: rgba(0, 0, 0, 0.4);
            }
            
            /* Padding extra in fondo alla sidebar per vedere tutto */
            .sidebar .nav-section:last-child {
                padding-bottom: 50px;
            }

            .menu-toggle {
                display: block;
            }

            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 150;
            }

            .overlay.open {
                display: block;
            }
            
            /* Previeni scroll del body quando sidebar è aperta */
            body.sidebar-open {
                overflow: hidden;
                position: fixed;
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            /* Breadcrumb mobile */
            .breadcrumb-container {
                padding: 8px 0;
                margin-bottom: 10px;
                gap: 4px;
            }
            
            .breadcrumb-item {
                font-size: 0.8rem;
                padding: 3px 6px;
            }
            
            .file-table {
                display: none;
            }

            .file-cards {
                display: flex;
            }

            .content-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .filter-select {
                align-self: flex-start;
            }

            .selection-actions {
                flex-wrap: wrap;
            }

            .selection-actions .btn span {
                display: none;
            }

            /* Ottimizzazione card mobile con menu contestuale */
            .file-card {
                flex-direction: column;
                align-items: stretch;
                padding: 12px;
                gap: 12px;
            }

            .file-card > div:first-child {
                width: 100%;
                display: flex;
                align-items: center;
                gap: 12px;
                min-width: 0;
            }

            .file-card-info {
                flex: 1;
                min-width: 0;
                overflow: hidden;
                padding-right: 40px;
            }

            .file-card-name {
                font-size: 0.95rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                margin-bottom: 4px;
            }

            .file-card-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                font-size: 0.75rem;
                color: var(--text-secondary);
            }

            /* Nascondi le azioni standard su mobile */
            .file-card .file-actions {
                display: none;
            }

            /* Menu contestuale mobile */
            .mobile-context-menu {
                position: absolute;
                top: 12px;
                right: 12px;
/*                z-index: 10; */
            }

            .mobile-menu-trigger {
                position: relative;
                width: 36px;
                height: 36px;
                border-radius: 8px;
                background-color: var(--hover-bg);
                border: 1px solid var(--border-color);
                color: var(--text-color);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                font-size: 1.1rem;
                z-index: 1000;
            }

            .mobile-menu-trigger:hover {
                background-color: var(--primary-color);
                color: white;
            }

            .mobile-actions-dropdown {
                display: none;
                position: absolute;
                top: 100%;
                right: 0;
                background-color: var(--card-bg);
                border: 1px solid var(--border-color);
                border-radius: 8px;
                box-shadow: 0 4px 15px var(--shadow-color);
                z-index: 1000;
                min-width: 180px;
                padding: 8px 0;
                margin-top: 5px;
                flex-direction: column;
            }

            .mobile-actions-trigger {
                padding: 8px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #666;
                transition: all 0.2s;
                border-radius: 50%;
                width: 36px;
                height: 36px;
                flex-shrink: 0;
            }

            .mobile-actions-trigger:hover {
                background-color: rgba(0, 0, 0, 0.05);
                color: var(--primary-color);
            }

            .mobile-actions-trigger:active {
                background-color: rgba(0, 0, 0, 0.1);
                transform: scale(0.95);
            }

            .mobile-actions-dropdown.show {
                display: flex;
                z-index: 1001;  // <-- AGGIUNGI QUESTA LINEA
            }

            .mobile-actions-dropdown .action-btn {
                width: 100%;
                padding: 10px 15px;
                border-radius: 0;
                background-color: transparent;
                color: var(--text-color);
                text-align: left;
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 0.9rem;
                border: none;
                cursor: pointer;
            }

            .mobile-actions-dropdown .action-btn:hover {
                background-color: var(--hover-bg);
                color: var(--primary-color);
            }

            .mobile-actions-dropdown .action-btn i {
                width: 20px;
                text-align: center;
            }

            /* Per i bottoni nella tabella (se ancora visibile su alcuni dispositivi) */
            .file-table .file-actions {
                flex-wrap: wrap;
                justify-content: center;
                gap: 4px;
            }

            .file-table .action-btn {
                width: 36px;
                height: 36px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            /* Per l'anteprima */
            #previewModal .modal-content {
                max-width: 90vw;
                max-height: 90vh;
                width: auto;
                min-width: 500px;
                margin: 0 auto;  // <-- AGGIUNGI QUESTA LINEA
                padding: 0;  // <-- AGGIUNGI QUESTA LINEA
            }
            
            .preview-image, .preview-document {
                max-height: 60vh;
                max-width: 100%;  // <-- AGGIUNGI QUESTA LINEA
            }

            /* Aggiungi anche questa regola per il corpo del modal */
            #previewModal .modal-body {
                padding: 10px;  // <-- AGGIUNGI QUESTA REGOLA
                overflow: hidden;
            }

        }

        @media (max-width: 576px) {
            .header {
                padding: 0 15px; left: 0px;
            }

            .content {
                padding: 15px;
            }

            .btn span {
                display: none;
            }

            .btn i {
                margin: 0;
            }

            .footer {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            /* Ulteriori ottimizzazioni per schermi piccoli */
            .file-card {
                padding: 10px;
                gap: 10px;
            }

            .file-icon {
                width: 32px !important;
                height: 32px !important;
                font-size: 1rem !important;
            }

            .file-card-info {
                padding-right: 36px;
            }

            .mobile-menu-trigger {
                width: 32px;
                height: 32px;
                font-size: 1rem;
            }

            .mobile-actions-dropdown {
                min-width: 160px;
            }

            .mobile-actions-dropdown .action-btn {
                padding: 8px 12px;
                font-size: 0.85rem;
            }

            .file-card-meta {
                font-size: 0.7rem;
            }
        }

        /* Per evitare problemi con alcuni dispositivi touch */
        @supports (-webkit-touch-callout: none) {
            @media (max-width: 768px) {
                .mobile-actions-dropdown {
                    -webkit-overflow-scrolling: touch;
                }
            }
        }

        /* Per schermi molto piccoli */
        @media (max-width: 375px) {
            .file-card {
                padding: 8px;
            }

            .file-card-info {
                padding-right: 32px;
            }

            .mobile-menu-trigger {
                width: 30px;
                height: 30px;
                font-size: 0.9rem;
            }

            .mobile-actions-dropdown {
                min-width: 140px;
            }

            .mobile-actions-dropdown .action-btn {
                padding: 6px 10px;
                font-size: 0.8rem;
            }

            .file-card-name {
                font-size: 0.9rem;
            }

            /* Anteprima ancora più stretta su schermi piccoli */
            #previewModal .modal-content {
                width: 88vw;
                max-width: 88vw;
                margin: 0 auto;
            }
            
            #previewModal .modal-body {
                padding: 5px;
            }

        }
    </style>
    <script src="theme.js"></script>
</head>
<body class="light-theme">
    <!-- SIDEBAR NAVIGATION -->
    <nav class="sidebar" id="sidebar">
        <div class="logo">
<!--            <i class="fas fa-cloud"></i> -->
            <img src="openCloud-php.png" width="64px" >
            <span>OpenCloud</span>
        </div>
        
        <div class="nav-section">
            <div class="nav-title">Personale</div>
            <a href="index.php" class="nav-item <?php echo (!$filter && !$category && !$folder_id) ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="index.php?filter=favorites" class="nav-item <?php echo ($filter == 'favorites') ? 'active' : ''; ?>">
                <i class="fas fa-star"></i>
                <span>Preferiti</span>
            </a>
            <a href="index.php?filter=recent" class="nav-item <?php echo ($filter == 'recent') ? 'active' : ''; ?>">
                <i class="fas fa-clock"></i>
                <span>Recenti</span>
            </a>
            <a href="index.php?filter=trash" class="nav-item <?php echo ($filter == 'trash') ? 'active' : ''; ?>">
                <i class="fas fa-trash"></i>
                <span>Cestino</span>
                <?php if ($trash_count > 0): ?>
                    <span style="margin-left: auto; background-color: var(--primary-color); color: white; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem;">
                        <?php echo $trash_count; ?>
                    </span>
                <?php endif; ?>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-title">Cartelle</div>
            <a href="#" class="nav-item" onclick="showCreateFolderModal()" style="color: var(--primary-color);">
                <i class="fas fa-plus-circle"></i>
                <span>Nuova cartella</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-title">Categorie</div>
            <a href="index.php?category=documents" class="nav-item <?php echo ($category == 'documents') ? 'active' : ''; ?>">
                <i class="fas fa-file-text"></i>
                <span>Documenti</span>
            </a>
            <a href="index.php?category=images" class="nav-item <?php echo ($category == 'images') ? 'active' : ''; ?>">
                <i class="fas fa-image"></i>
                <span>Immagini</span>
            </a>
            <a href="index.php?category=videos" class="nav-item <?php echo ($category == 'videos') ? 'active' : ''; ?>">
                <i class="fas fa-film"></i>
                <span>Video</span>
            </a>
            <a href="index.php?category=audio" class="nav-item <?php echo ($category == 'audio') ? 'active' : ''; ?>">
                <i class="fas fa-music"></i>
                <span>Audio</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-title">Spazio</div>
            <div style="padding: 0 20px 15px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <span style="font-size: 0.9rem;"><?php echo formatBytes($used_space); ?> di 15 GB usati</span>
                    <span style="font-size: 0.9rem; font-weight: 600;"><?php echo round($percent_used, 1); ?>%</span>
                </div>
                <div style="height: 6px; background-color: #eee; border-radius: 3px; overflow: hidden;">
                    <div style="width: <?php echo $percent_used; ?>%; height: 100%; background-color: var(--primary-color);"></div>
                </div>
            </div>
        </div>


        <div class="nav-section">
            <div class="nav-title">Utente</div>
            <a href="#" class="nav-item" onclick="showPage('profile'); return false;">
                <i class="fas fa-user-circle"></i>
                <span>Il Mio Profilo</span>
            </a>
            <?php if (isAdmin()): ?>
            <a href="#" class="nav-item" onclick="showPage('users'); return false;">
                <i class="fas fa-users"></i>
                <span>Gestione Utenti</span>
            </a>
            <a href="#" class="nav-item" onclick="showPage('system'); return false;">
                <i class="fas fa-cog"></i>
                <span>Impostazioni Sistema</span>
            </a>
            <?php endif; ?>
        </div>

        <div class="nav-section">
            <div class="nav-title">Account</div>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout (<?php echo htmlspecialchars($user['username']); ?>)</span>
            </a>
        </div>
    </nav>

    <!-- OVERLAY PER MOBILE -->
    <div class="overlay" id="overlay"></div>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <!-- HEADER -->
        <header class="header">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Cerca file...">
            </div>
            
            <div class="user-actions">
                <div class="selection-actions" style="display: none;" id="selectionActions">
                    <span class="selection-count"></span>
                    <?php if ($is_trash): ?>
                        <button class="btn btn-secondary" onclick="restoreSelected()">
                            <i class="fas fa-undo"></i>
                            <span>Ripristina</span>
                        </button>
                        <button class="btn btn-danger" onclick="permanentDeleteSelected()">
                            <i class="fas fa-times"></i>
                            <span>Elimina</span>
                        </button>
                    <?php else: ?>
                        <button class="btn btn-secondary" onclick="moveSelected()">
                            <i class="fas fa-folder"></i>
                            <span>Sposta</span>
                        </button>
                        <button class="btn btn-danger" onclick="deleteSelected()">
                            <i class="fas fa-trash"></i>
                            <span>Elimina</span>
                        </button>
                    <?php endif; ?>
                </div>
                
                <?php if ($is_trash): ?>
                    <button class="btn btn-danger" id="emptyTrashBtn">
                        <i class="fas fa-trash"></i>
                        <span>Svuota cestino</span>
                    </button>
                <?php else: ?>
                    <button class="btn btn-primary" id="uploadBtn">
                        <i class="fas fa-upload"></i>
                        <span>Carica</span>
                    </button>
                <?php endif; ?>
                
                <div class="theme-selector" style="margin-left: 10px;">
                    <select id="themeSelector" style="padding: 5px; border-radius: 4px; border: 1px solid var(--border-color); background-color: var(--input-bg); color: var(--text-color);">
                        <option value="light">🌞 Chiaro</option>
                        <option value="dark">🌙 Scuro</option>
                        <option value="blue">🔵 Blu</option>
                        <option value="green">🟢 Verde</option>
                    </select>
                </div>
                
                <div id="userAvatarBtn" style="width: 35px; height: 35px; border-radius: 50%; background-color: #e6f0ff; display: flex; align-items: center; justify-content: center; color: var(--primary-color); cursor: pointer; overflow: hidden; border: 2px solid var(--primary-color);" title="<?php echo htmlspecialchars($user['username']); ?>">
                    <i class="fas fa-user" id="userAvatarIcon"></i>
                    <img id="userAvatarImg" src="" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; display: none;">
                </div>
            </div>
        </header>

        <!-- CONTENT -->
        <div class="content">
            <div class="content-header">
                <h1 class="page-title" id="pageTitle">
                    <?php
                    if ($is_trash) {
                        echo 'Cestino';
                    } elseif ($filter == 'favorites') {
                        echo 'Preferiti';
                    } elseif ($filter == 'recent') {
                        echo 'Recenti';
                    } elseif ($category) {
                        $category_names = [
                            'documents' => 'Documenti',
                            'images' => 'Immagini',
                            'videos' => 'Video',
                            'audio' => 'Audio'
                        ];
                        echo $category_names[$category] ?? ucfirst($category);
                    } elseif ($folder_id > 0) {
                        // Nome cartella sarà caricato via JS
                        echo 'Caricamento...';
                    } else {
                        echo 'Tutti i file';
                    }
                    ?>
                </h1>
                
                <?php if (!$is_trash): ?>
                    <select class="filter-select" id="filterSelect" onchange="if(this.value.startsWith('filter:')) { window.location.href = 'index.php?filter=' + this.value.replace('filter:', ''); } else if(this.value) { window.location.href = 'index.php?category=' + this.value; } else { window.location.href = 'index.php'; }">
                        <option value="">Tutti i file</option>
                        <option value="filter:favorites" <?php echo ($filter == 'favorites') ? 'selected' : ''; ?>>Preferiti</option>
                        <option value="filter:recent" <?php echo ($filter == 'recent') ? 'selected' : ''; ?>>Recenti</option>
                        <optgroup label="Categorie">
                            <option value="documents" <?php echo ($category == 'documents') ? 'selected' : ''; ?>>Documenti</option>
                            <option value="images" <?php echo ($category == 'images') ? 'selected' : ''; ?>>Immagini</option>
                            <option value="videos" <?php echo ($category == 'videos') ? 'selected' : ''; ?>>Video</option>
                            <option value="audio" <?php echo ($category == 'audio') ? 'selected' : ''; ?>>Audio</option>
                        </optgroup>
                    </select>
                <?php endif; ?>
            </div>

            <!-- Breadcrumb Navigation -->
            <?php if (!$is_trash && !$filter && !$category): ?>
            <div class="breadcrumb-container" id="breadcrumbNav" style="display: none;"></div>
            <?php endif; ?>

            <!-- DESKTOP TABLE -->
            <table class="file-table">
                <thead>
                    <tr>
                        <th style="width: 30px;">
                            <input type="checkbox" id="selectAllCheckbox">
                        </th>
                        <th>Nome</th>
                        <th>Dimensioni</th>
                        <th>Tags</th>
                        <th><?php echo $is_trash ? 'Eliminato il' : 'Modificato'; ?></th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody id="filesTable">
                    <?php if (empty($files)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                                <i class="fas fa-<?php echo $is_trash ? 'trash' : 'folder-open'; ?>" style="font-size: 3rem; margin-bottom: 15px; display: block;"></i>
                                <?php echo $is_trash ? 'Il cestino è vuoto' : 'Cartella vuota'; ?><br>
                                <small>
                                    <?php if ($is_trash): ?>
                                        I file eliminati appariranno qui
                                    <?php else: ?>
                                        Clicca su "Carica" per aggiungere il tuo primo file
                                    <?php endif; ?>
                                </small>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($files as $file): ?>
                            <?php
                            $ext = pathinfo($file['filename'], PATHINFO_EXTENSION);
                            $icon = getFileIcon($ext);
                            $size = formatBytes($file['filesize']);
                            
                            if ($is_trash) {
                                $date = isset($file['deleted_at']) ? date('d/m/Y H:i', strtotime($file['deleted_at'])) : 'Data sconosciuta';
                            } else {
                                $date = date('d/m/Y H:i', strtotime($file['created_at']));
                            }
                            
                            $category_type = getCategoryFromExtension($ext);
                            ?>
                            <tr data-file-id="<?php echo $file['id']; ?>" data-category="<?php echo $category_type; ?>" data-favorite="<?php echo $file['is_favorite']; ?>">
                                <td>
                                    <input type="checkbox" class="file-checkbox" value="<?php echo $file['id']; ?>">
                                </td>
                                <td>
                                    <div class="file-name">
                                        <div class="file-icon <?php echo $ext; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </div>
                                        <div>
                                            <div class="file-name-text"><?php echo htmlspecialchars($file['original_name']); ?></div>
                                            <div style="font-size: 0.85rem; color: #888;"><?php echo htmlspecialchars($file['filetype']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo $size; ?></td>
                                <td>
                                    <?php if ($file['tags']): ?>
                                        <span style="background-color: #e6f7ff; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;"><?php echo htmlspecialchars($file['tags']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($file['is_favorite'] && !$is_trash): ?>
                                        <i class="fas fa-star text-warning" title="Preferito"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $date; ?></td>
                                <td>
                                    <div class="file-actions">
                                        <?php if ($is_trash): ?>
                                            <button class="action-btn" title="Ripristina" onclick="restoreFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                            <button class="action-btn" title="Elimina definitivamente" onclick="permanentDelete(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                                <i class="fas fa-times text-danger"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="action-btn favorite-btn" title="Preferiti" onclick="toggleFavorite(<?php echo $file['id']; ?>, this)" data-file-id="<?php echo $file['id']; ?>">
                                                <i class="fas fa-star <?php echo $file['is_favorite'] ? 'text-warning' : 'text-muted'; ?>"></i>
                                            </button>
                                            <button class="action-btn" title="Scarica" onclick="downloadFile(<?php echo $file['id']; ?>)">
                                                <i class="fas fa-download"></i>
                                            </button>
                                            <button class="action-btn" title="Sposta" onclick="moveSingleFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                                <i class="fas fa-folder-open"></i>
                                            </button>
                                            <button class="action-btn" title="Rinomina" onclick="renameFileSingle(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn" title="Condividi" onclick="shareFile(<?php echo $file['id']; ?>)">
                                                <i class="fas fa-share-alt"></i>
                                            </button>
                                            <button class="action-btn" title="Anteprima" onclick="previewFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>', '<?php echo htmlspecialchars(addslashes($file['filename'])); ?>', '<?php echo htmlspecialchars(addslashes($file['filetype'])); ?>')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-btn" title="Elimina" onclick="deleteFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- MOBILE CARDS -->
            <div class="file-cards" id="fileCards">
                <?php if (empty($files)): ?>
                    <div style="text-align: center; padding: 40px; color: #999;">
                        <i class="fas fa-<?php echo $is_trash ? 'trash' : 'folder-open'; ?>" style="font-size: 3rem; margin-bottom: 15px; display: block;"></i>
                        <?php echo $is_trash ? 'Il cestino è vuoto' : 'Cartella vuota'; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($files as $file): ?>
                        <?php
                        $ext = pathinfo($file['filename'], PATHINFO_EXTENSION);
                        $icon = getFileIcon($ext);
                        $size = formatBytes($file['filesize']);
                        
                        if ($is_trash) {
                            $date = isset($file['deleted_at']) ? date('d/m/Y H:i', strtotime($file['deleted_at'])) : 'Data sconosciuta';
                        } else {
                            $date = date('d/m/Y H:i', strtotime($file['created_at']));
                        }
                        
                        $category_type = getCategoryFromExtension($ext);
                        ?>
                        <div class="file-card" data-file-id="<?php echo $file['id']; ?>" data-category="<?php echo $category_type; ?>" data-favorite="<?php echo $file['is_favorite']; ?>">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" class="file-checkbox" value="<?php echo $file['id']; ?>">
                                <div class="file-icon <?php echo $ext; ?>">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div class="file-card-info" style="padding-right: 40px;">
                                    <div class="file-card-name"><?php echo htmlspecialchars($file['original_name']); ?></div>
                                    <div class="file-card-meta">
                                        <span><?php echo $size; ?></span>
                                        <span><?php echo $date; ?></span>
                                        <?php if ($file['is_favorite'] && !$is_trash): ?>
                                            <i class="fas fa-star text-warning"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="mobile-context-menu">
                                <button class="mobile-menu-trigger" onclick="toggleMobileMenu(this)">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div class="mobile-actions-dropdown">
                                    <?php if ($is_trash): ?>
                                        <button class="action-btn" onclick="restoreFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                            <i class="fas fa-undo"></i>
                                            <span>Ripristina</span>
                                        </button>
                                        <button class="action-btn" onclick="permanentDelete(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                            <i class="fas fa-times text-danger"></i>
                                            <span>Elimina definitivamente</span>
                                        </button>
                                    <?php else: ?>
                                        <button class="action-btn" onclick="downloadFile(<?php echo $file['id']; ?>)">
                                            <i class="fas fa-download"></i>
                                            <span>Scarica</span>
                                        </button>
                                        <button class="action-btn" onclick="moveSingleFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                            <i class="fas fa-folder-open"></i>
                                            <span>Sposta</span>
                                        </button>
                                        <button class="action-btn" onclick="renameFileSingle(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                            <i class="fas fa-edit"></i>
                                            <span>Rinomina</span>
                                        </button>
                                        <button class="action-btn" onclick="shareFile(<?php echo $file['id']; ?>)">
                                            <i class="fas fa-share-alt"></i>
                                            <span>Condividi</span>
                                        </button>
                                        <button class="action-btn" onclick="previewFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>', '<?php echo htmlspecialchars(addslashes($file['filename'])); ?>', '<?php echo htmlspecialchars(addslashes($file['filetype'])); ?>')">
                                            <i class="fas fa-eye"></i>
                                            <span>Anteprima</span>
                                        </button>
                                        <button class="action-btn" onclick="deleteFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['original_name'])); ?>')">
                                            <i class="fas fa-trash"></i>
                                            <span>Elimina</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div style="margin-top: 25px; padding: 15px; background-color: #f8f9fa; border-radius: 8px; font-size: 0.9rem; color: #666;">
                <strong id="fileCount"><?php echo count($files); ?> elementi</strong> 
                <?php if (!$is_trash): ?>
                    con <?php echo formatBytes($used_space); ?> in totale
                <?php endif; ?>
            </div>
            
            <?php if ($is_trash && !empty($files)): ?>
                <div style="margin-top: 20px; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; font-size: 0.9rem; color: #856404;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Nota:</strong> I file nel cestino verranno eliminati automaticamente dopo 30 giorni.
                </div>
            <?php endif; ?>
        </div>

        <!-- FOOTER -->
        <footer class="footer">
            <div class="version">OpenCloud 5.0.1 rolling</div>
            <div>OpenCloud 5.0.1 rolling | Aggiornato <span id="currentDate"></span> | Utente: <?php echo htmlspecialchars($user['username']); ?></div>
        </footer>

        <!-- PAGINE PROFILO/UTENTI/SISTEMA - DENTRO IL MAIN -->
        <?php include 'users_system_pages.html'; ?>
        
    </main>

    <!-- MODAL CARICA FILE -->
    <div class="modal" id="uploadModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Carica File</h2>
                <button class="action-btn" id="closeUploadModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="uploadForm" enctype="multipart/form-data">
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px;">Cartella di destinazione</label>
                        <select name="folder_id" id="uploadFolder" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: var(--input-bg); color: var(--text-color);">
                            <option value="0">Tutti i file (root)</option>
                            <!-- Le cartelle verranno caricate dinamicamente -->
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px;">Seleziona file</label>
                        <input type="file" name="file" id="fileInput" multiple style="width: 100%; padding: 10px; border: 2px dashed var(--border-color); border-radius: 8px; background-color: var(--input-bg);">
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px;">Tags (opzionale)</label>
                        <input type="text" name="tags" placeholder="es: lavoro, vacanze, importante" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: var(--input-bg); color: var(--text-color);">
                    </div>
                    
                    <div id="uploadProgress" style="display: none; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span>Caricamento in corso...</span>
                            <span id="progressPercent">0%</span>
                        </div>
                        <div style="height: 10px; background-color: var(--border-color); border-radius: 5px; overflow: hidden;">
                            <div id="progressBar" style="width: 0%; height: 100%; background-color: var(--primary-color); transition: width 0.3s;"></div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-upload"></i>
                        Carica File
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL CONDIVISIONE -->
    <div class="modal" id="shareModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Condividi File</h2>
                <button class="action-btn" id="closeShareModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="shareModalContent">
                <!-- Contenuto dinamico -->
            </div>
        </div>
    </div>

    <!-- MODAL NUOVA CARTELLA -->
    <div class="modal" id="folderModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Nuova Cartella</h2>
                <button class="action-btn" id="closeFolderModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="folderForm">
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px;">Nome cartella</label>
                        <input type="text" id="folderName" placeholder="Nome della cartella" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: var(--input-bg); color: var(--text-color);" required>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px;">Cartella parent (opzionale)</label>
                        <select id="folderParent" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: var(--input-bg); color: var(--text-color);">
                            <option value="0">Root (nessuna cartella parent)</option>
                        </select>
                        <small style="color: #888; display: block; margin-top: 5px;">
                            Seleziona dove creare la cartella. Se sei dentro una cartella, verrà creata lì automaticamente.
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-folder-plus"></i>
                        Crea Cartella
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL RINOMINA CARTELLA -->
    <div class="modal" id="renameFolderModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Rinomina Cartella</h2>
                <button class="action-btn" id="closeRenameFolderModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="renameFolderForm">
                    <input type="hidden" id="renameFolderId">
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px;">Nuovo nome</label>
                        <input type="text" id="renameFolderName" placeholder="Nuovo nome della cartella" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: var(--input-bg); color: var(--text-color);" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-edit"></i>
                        Rinomina
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL SPOSTA CARTELLA -->
    <div class="modal" id="moveFolderModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Sposta Cartella</h2>
                <button class="action-btn" id="closeMoveFolderModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="moveFolderForm">
                    <input type="hidden" id="moveFolderId">
                    <div style="margin-bottom: 15px; padding: 10px; background: #f0f5ff; border-radius: 6px;">
                        <strong>Cartella da spostare:</strong>
                        <div id="moveFolderName" style="font-size: 1.1rem; color: var(--primary-color); margin-top: 5px;"></div>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px;">Nuova posizione</label>
                        <select id="moveFolderDestination" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: var(--input-bg); color: var(--text-color);">
                            <option value="0">Root (cartella principale)</option>
                        </select>
                        <small style="color: #888; display: block; margin-top: 5px;">
                            Seleziona dove spostare la cartella
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-folder-open"></i>
                        Sposta Cartella
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL RINOMINA FILE -->
    <div class="modal" id="renameFileModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Rinomina File</h2>
                <button class="action-btn" id="closeRenameFileModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="renameFileForm">
                    <input type="hidden" id="renameFileId">
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px;">Nuovo nome</label>
                        <input type="text" id="renameFileName" placeholder="Nuovo nome del file" 
                               style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: var(--input-bg); color: var(--text-color);" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-edit"></i>
                        Rinomina File
                    </button>
                </form>
            </div>
        </div>
    </div>

<!-- MODAL ANTEPRIMA -->
<div class="modal" id="previewModal">
    <div class="modal-content" style="max-width: 90%; max-height: 90%; width: auto; min-width: 500px;">
        <div class="modal-header">
            <h2 id="previewTitle">Anteprima</h2>
            <button class="action-btn" id="closePreviewModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="previewModalContent" style="padding: 0; overflow: auto; max-height: 70vh; display: flex; flex-direction: column; align-items: center; justify-content: center;">
            <div style="padding: 20px; text-align: center; color: var(--text-secondary);" id="previewLoading">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p style="margin-top: 10px;">Caricamento anteprima...</p>
            </div>
            <div id="previewContent" style="width: 100%; display: none;">
                <!-- Contenuto dinamico -->
            </div>
        </div>
        <div class="modal-footer" style="padding: 15px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <button class="btn btn-primary" onclick="downloadFromPreview()" id="previewDownloadBtn" style="display: none;">
                    <i class="fas fa-download"></i> Scarica
                </button>
            </div>
            <div>
                <button class="btn btn-secondary" onclick="closePreview()">
                    <i class="fas fa-times"></i> Chiudi
                </button>
            </div>
        </div>
    </div>
</div>

    <script>
        // Variabili globali
        const baseUrl = '<?php echo BASE_URL; ?>';
        const isTrash = <?php echo $is_trash ? 'true' : 'false'; ?>;
        let selectedFiles = new Set();

        // Elementi DOM
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menuToggle');
        const overlay = document.getElementById('overlay');
        const uploadModal = document.getElementById('uploadModal');
        const shareModal = document.getElementById('shareModal');
        const folderModal = document.getElementById('folderModal');
        const renameFolderModal = document.getElementById('renameFolderModal');
        const renameFileModal = document.getElementById('renameFileModal');
        const uploadForm = document.getElementById('uploadForm');
        const uploadProgress = document.getElementById('uploadProgress');
        const progressBar = document.getElementById('progressBar');
        const progressPercent = document.getElementById('progressPercent');
        const searchInput = document.getElementById('searchInput');


        // ============================================
        // GESTIONE SELEZIONE FILE - VERSIONE CORRETTA
        // ============================================

        // Funzione per sincronizzare checkbox duplicate
        function syncCheckboxState(fileId, isChecked) {
            const checkboxes = document.querySelectorAll(`.file-checkbox[value="${fileId}"]`);
            checkboxes.forEach(cb => {
                cb.checked = isChecked;
            });
        }

        // Gestisce il cambiamento di una singola checkbox
        function handleCheckboxChange(checkbox) {
            const fileId = checkbox.value;
            const isChecked = checkbox.checked;

            // Sincronizza tutte le checkbox per questo file
            syncCheckboxState(fileId, isChecked);

            // Aggiorna il Set selectedFiles
            if (isChecked) {
                selectedFiles.add(fileId);
            } else {
                selectedFiles.delete(fileId);
            }

            // Aggiorna l'interfaccia
            updateSelectionUI();
        }

        // Seleziona/Deseleziona tutte le checkbox - VERSIONE CORRETTA
        function toggleSelectAll(checkbox) {
            const allCheckboxes = document.querySelectorAll('.file-checkbox');
            const isChecked = checkbox.checked;

            // Svuota SEMPRE il Set prima di procedere
            selectedFiles.clear();

            // Aggiorna visualmente e logicamente
            allCheckboxes.forEach(cb => {
                cb.checked = isChecked;
                if (isChecked) {
                    selectedFiles.add(cb.value);
                }
            });

            // Aggiorna l'interfaccia
            updateSelectionUI();
        }

        // Aggiorna l'interfaccia utente della selezione
        function updateSelectionUI() {
            const selectionActions = document.getElementById('selectionActions');
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const allCheckboxes = document.querySelectorAll('.file-checkbox');
            const totalCheckboxes = allCheckboxes.length;
            const selectedCount = selectedFiles.size;

            // Mostra/nascondi la barra delle azioni
            if (selectionActions) {
                if (selectedCount > 0) {
                    selectionActions.style.display = 'flex';
                    const countSpan = selectionActions.querySelector('.selection-count');
                    if (countSpan) {
                        countSpan.textContent = `${selectedCount} selezionati`;
                    }
                } else {
                    selectionActions.style.display = 'none';
                }
            }

            // Aggiorna la checkbox "Seleziona tutto"
            if (selectAllCheckbox) {
                if (selectedCount === 0) {
                    // Nessuna selezionata
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                } else if (selectedCount === totalCheckboxes) {
                    // Tutte selezionate
                    selectAllCheckbox.checked = true;
                    selectAllCheckbox.indeterminate = false;
                } else {
                    // Alcune selezionate (stato indeterminato)
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = true;
                }
            }
        }

// Variabile per memorizzare l'ID del file in anteprima
let previewFileId = null;

// Funzione per aprire l'anteprima del file
function previewFile(fileId, fileName, filePath, fileType) {
    previewFileId = fileId;
    
    // Mostra il modal
    const modal = document.getElementById('previewModal');
    const title = document.getElementById('previewTitle');
    const loading = document.getElementById('previewLoading');
    const content = document.getElementById('previewContent');
    const downloadBtn = document.getElementById('previewDownloadBtn');
    
    modal.style.display = 'flex';
    title.textContent = `Anteprima: ${fileName}`;
    loading.style.display = 'block';
    content.style.display = 'none';
    downloadBtn.style.display = 'none';
    
    // Determina il tipo di file in base all'estensione
    const extension = filePath.split('.').pop().toLowerCase();
    const previewUrl = `api/files.php?action=preview&id=${fileId}&t=${Date.now()}`; // Aggiungi timestamp per evitare cache
    
    // Categorie di file
    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
    const pdfExtensions = ['pdf'];
    const videoExtensions = ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'webm'];
    const audioExtensions = ['mp3', 'wav', 'ogg', 'flac', 'm4a'];
    const textExtensions = ['txt', 'md', 'html', 'css', 'js', 'json', 'xml'];
    const officeExtensions = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    
    // Nascondi loading dopo 2 secondi massimo
    const loadingTimeout = setTimeout(() => {
        loading.style.display = 'none';
        content.innerHTML = `
            <div style="padding: 40px; text-align: center; color: var(--text-secondary);">
                <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 20px; color: #ff9800;"></i>
                <p>Il caricamento dell'anteprima sta impiegando più del previsto.</p>
                <button class="btn btn-primary" onclick="downloadFromPreview()" style="margin-top: 15px;">
                    <i class="fas fa-download"></i> Scarica file
                </button>
            </div>
        `;
        content.style.display = 'block';
        downloadBtn.style.display = 'inline-block';
    }, 5000);
    
    // Test se l'URL dell'anteprima funziona
    fetch(previewUrl, { method: 'HEAD' })
        .then(response => {
            clearTimeout(loadingTimeout);
            
            if (!response.ok) {
                throw new Error('Errore nel caricamento dell\'anteprima');
            }
            
            loading.style.display = 'none';
            content.style.display = 'block';
            downloadBtn.style.display = 'inline-block';
            
            // Crea l'elemento di anteprima in base al tipo di file
            if (imageExtensions.includes(extension)) {
                // Anteprima immagine
                const img = document.createElement('img');
                img.src = previewUrl;
                img.alt = fileName;
                img.style.maxWidth = '100%';
                img.style.maxHeight = '65vh';
                img.style.objectFit = 'contain';
                img.style.display = 'block';
                img.style.margin = '0 auto';
                
                img.onload = () => {
                    content.innerHTML = '';
                    content.appendChild(img);
                    
                    // Aggiungi informazioni
                    const info = document.createElement('div');
                    info.style.padding = '10px';
                    info.style.textAlign = 'center';
                    info.style.color = 'var(--text-secondary)';
                    info.style.fontSize = '0.9rem';
                    info.innerHTML = `${fileName} (${fileType})`;
                    content.appendChild(info);
                };
                
                img.onerror = () => showFileNotSupported(content, fileName, fileType);
            } 
            else if (videoExtensions.includes(extension)) {
                // Anteprima video
                content.innerHTML = `
                    <video controls 
                           style="max-width: 100%; max-height: 65vh; display: block; margin: 0 auto;"
                           onerror="this.parentElement.innerHTML = '<div style=\\'padding: 40px; text-align: center;\\'><i class=\\'fas fa-exclamation-triangle\\'></i><p>Formato video non supportato per l\\'anteprima</p></div>'">
                        <source src="${previewUrl}" type="${fileType}">
                        Il tuo browser non supporta la riproduzione di video.
                    </video>
                    <div style="padding: 10px; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                        ${fileName} (${fileType})
                    </div>
                `;
            }
            else if (audioExtensions.includes(extension)) {
                // Anteprima audio
                content.innerHTML = `
                    <div style="padding: 40px 20px; text-align: center;">
                        <i class="fas fa-music" style="font-size: 3rem; color: var(--primary-color); margin-bottom: 20px;"></i>
                        <audio controls style="width: 100%;">
                            <source src="${previewUrl}" type="${fileType}">
                            Il tuo browser non supporta la riproduzione di audio.
                        </audio>
                    </div>
                    <div style="padding: 10px; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                        ${fileName} (${fileType})
                    </div>
                `;
            }
            else if (extension === 'pdf') {
                // Anteprima PDF
                content.innerHTML = `
                    <iframe src="${previewUrl}" 
                           style="width: 100%; height: 65vh; border: none;"
                           title="Anteprima PDF: ${fileName}">
                    </iframe>
                    <div style="padding: 10px; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                        ${fileName} (${fileType})
                    </div>
                `;
            }
            else if (textExtensions.includes(extension)) {
                // Anteprima testo
                fetch(previewUrl)
                    .then(response => {
                        if (!response.ok) throw new Error('Errore nel caricamento del testo');
                        return response.text();
                    })
                    .then(text => {
                        const maxLength = 10000;
                        const isTruncated = text.length > maxLength;
                        const displayText = isTruncated ? text.substring(0, maxLength) : text;
                        
                        content.innerHTML = `
                            <div style="padding: 15px; background-color: var(--input-bg); border-radius: 5px; max-height: 65vh; overflow: auto; font-family: monospace; white-space: pre-wrap; word-wrap: break-word;">
                                ${escapeHtml(displayText)}
                                ${isTruncated ? '\n\n... (contenuto troncato, scarica il file per vedere tutto)' : ''}
                            </div>
                            <div style="padding: 10px; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                                ${fileName} (${fileType}) - ${text.length} caratteri
                                ${isTruncated ? '- Anteprima limitata ai primi 10.000 caratteri' : ''}
                            </div>
                        `;
                    })
                    .catch(error => {
                        console.error('Errore caricamento testo:', error);
                        showFileNotSupported(content, fileName, fileType, 'Impossibile caricare l\'anteprima del testo.');
                    });
            }
            else if (officeExtensions.includes(extension)) {
                // Documenti Office - Icona specifica per tipo
                let iconClass = 'fa-file';
                let iconColor = 'var(--primary-color)';
                
                if (['xls', 'xlsx'].includes(extension)) {
                    iconClass = 'fa-file-excel';
                    iconColor = '#217346';
                } else if (['doc', 'docx'].includes(extension)) {
                    iconClass = 'fa-file-word';
                    iconColor = '#2196F3';
                } else if (['ppt', 'pptx'].includes(extension)) {
                    iconClass = 'fa-file-powerpoint';
                    iconColor = '#D24726';
                }
                
                content.innerHTML = `
                    <div style="padding: 60px 20px; text-align: center;">
                        <i class="fas ${iconClass}" style="font-size: 5rem; color: ${iconColor}; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 10px;">${fileName}</h3>
                        <p style="color: var(--text-secondary); margin-bottom: 20px;">${fileType}</p>
                        <p style="margin-bottom: 20px;">L'anteprima diretta non è disponibile per i file Office.</p>
                        <button class="btn btn-primary" onclick="downloadFromPreview()" style="margin-top: 15px;">
                            <i class="fas fa-download"></i> Scarica File
                        </button>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 20px;">
                            Dopo il download, puoi aprire il file con Microsoft Office, LibreOffice o Excel/Word online.
                        </p>
                    </div>
                `;
            }
            else {
                // Tipo non supportato
                showFileNotSupported(content, fileName, fileType);
            }
        })
        .catch(error => {
            clearTimeout(loadingTimeout);
            loading.style.display = 'none';
            content.style.display = 'block';
            
            content.innerHTML = `
                <div style="padding: 40px; text-align: center; color: var(--text-secondary);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 20px; color: #ff9800;"></i>
                    <h3>Errore nel caricamento</h3>
                    <p>Impossibile caricare l'anteprima del file.</p>
                    <p style="font-size: 0.9rem; margin-top: 10px;">${error.message}</p>
                    <button class="btn btn-primary" onclick="downloadFromPreview()" style="margin-top: 15px;">
                        <i class="fas fa-download"></i> Scarica file
                    </button>
                </div>
            `;
        });
}

// Funzione helper per mostrare messaggio file non supportato
function showFileNotSupported(content, fileName, fileType, customMessage = null) {
    content.innerHTML = `
        <div style="padding: 60px 20px; text-align: center;">
            <i class="fas fa-file" style="font-size: 5rem; color: var(--text-secondary); margin-bottom: 20px;"></i>
            <h3 style="margin-bottom: 10px;">${fileName}</h3>
            <p style="color: var(--text-secondary); margin-bottom: 20px;">${fileType}</p>
            <p>${customMessage || 'Anteprima non disponibile per questo tipo di file.'}</p>
        </div>
    `;
}


// Funzione per scaricare il file dall'anteprima
function downloadFromPreview() {
    if (previewFileId) {
        downloadFile(previewFileId);
    }
}

// Funzione per chiudere l'anteprima
function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    previewFileId = null;
}

// Chiudi modale anteprima
document.getElementById('closePreviewModal')?.addEventListener('click', closePreview);

// Chiudi modale anteprima cliccando fuori
document.getElementById('previewModal')?.addEventListener('click', (e) => {
    if (e.target === document.getElementById('previewModal')) {
        closePreview();
    }
});

        // Inizializza gli event listener per le checkbox
        function initCheckboxListeners() {
            // Rimuovi event listener precedenti dalla checkbox "Seleziona tutto"
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const newSelectAll = selectAllCheckbox.cloneNode(true);
            selectAllCheckbox.parentNode.replaceChild(newSelectAll, selectAllCheckbox);
            
            // Aggiungi nuovo event listener alla checkbox "Seleziona tutto"
            newSelectAll.addEventListener('change', function() {
                toggleSelectAll(this);
            });

            // Per tutte le checkbox individuali
            const checkboxes = document.querySelectorAll('.file-checkbox');
            checkboxes.forEach(checkbox => {
                // Rimuovi event listener precedenti
                const newCheckbox = checkbox.cloneNode(true);
                checkbox.parentNode.replaceChild(newCheckbox, checkbox);
                
                // Aggiungi nuovo event listener
                newCheckbox.addEventListener('change', function() {
                    handleCheckboxChange(this);
                });
            });
        }

        // Menu mobile
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
            document.body.classList.toggle('sidebar-open');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
            document.body.classList.remove('sidebar-open');
        });

        // Apri modal upload
        document.getElementById('uploadBtn')?.addEventListener('click', () => {
            populateFolderDropdown('uploadFolder');
            uploadModal.style.display = 'flex';
        });

        // Svuota cestino
        document.getElementById('emptyTrashBtn')?.addEventListener('click', () => {
            if (confirm('Sei sicuro di voler svuotare il cestino? Tutti i file verranno eliminati definitivamente.')) {
                emptyTrash();
            }
        });

        // Chiudi modali
        document.getElementById('closeUploadModal')?.addEventListener('click', () => {
            uploadModal.style.display = 'none';
        });

        document.getElementById('closeShareModal')?.addEventListener('click', () => {
            shareModal.style.display = 'none';
        });

        document.getElementById('closeFolderModal')?.addEventListener('click', () => {
            folderModal.style.display = 'none';
        });

        document.getElementById('closeRenameFolderModal')?.addEventListener('click', () => {
            renameFolderModal.style.display = 'none';
        });

        document.getElementById('closeRenameFileModal')?.addEventListener('click', () => {
            renameFileModal.style.display = 'none';
        });

        document.getElementById('closeMoveFolderModal')?.addEventListener('click', () => {
            document.getElementById('moveFolderModal').style.display = 'none';
        });

        // Form sposta cartella
        document.getElementById('moveFolderForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            performMoveFolder();
        });

        // Chiudi modali cliccando fuori
        const moveFolderModal = document.getElementById('moveFolderModal');
        [uploadModal, shareModal, folderModal, renameFolderModal, renameFileModal, moveFolderModal].forEach(modal => {
            modal?.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });

        // Funzione per popolare il dropdown delle cartelle
// Funzione per popolare il dropdown delle cartelle
async function populateFolderDropdown(dropdownId) {
    try {
        const response = await fetch('api/folders.php?action=list');
        
        if (!response.ok) return;
        
        const data = await response.json();
        
        if (data.success) {
            const dropdown = document.getElementById(dropdownId);
            if (dropdown) {
                dropdown.innerHTML = '<option value="0">Tutti i file (root)</option>';
                
                data.folders.forEach(folder => {
                    if (folder.id !== 0) {
                        const option = document.createElement('option');
                        option.value = folder.id;
                        option.textContent = folder.name;
                        dropdown.appendChild(option);
                    }
                });
                
                // Se siamo in una cartella specifica, selezionala di default
                const urlParams = new URLSearchParams(window.location.search);
                const currentFolder = urlParams.get('folder');
                if (currentFolder && currentFolder !== '0') {
                    dropdown.value = currentFolder;
                }
            }
        }
    } catch (error) {
        // In caso di errore, lascia solo l'opzione root
        const dropdown = document.getElementById(dropdownId);
        if (dropdown) {
            dropdown.innerHTML = '<option value="0">Tutti i file (root)</option>';
        }
    }
}

        // Upload file - VERSIONE CORRETTA
        uploadForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const fileInput = document.getElementById('fileInput');
            const files = fileInput.files;
            
            if (files.length === 0) {
                alert('Seleziona almeno un file');
                return;
            }

            uploadProgress.style.display = 'block';
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            
            let uploadedCount = 0;
            let errorCount = 0;

            for (let i = 0; i < files.length; i++) {
                const formData = new FormData();
                formData.append('file', files[i]);
                formData.append('tags', uploadForm.tags.value);
                const folderSelect = document.getElementById('uploadFolder');
                formData.append('folder_id', folderSelect ? folderSelect.value : '0');

                try {
                    const response = await new Promise((resolve, reject) => {
                        const xhr = new XMLHttpRequest();
                        
                        xhr.upload.addEventListener('progress', (e) => {
                            if (e.lengthComputable) {
                                const fileProgress = (e.loaded / e.total) * 100;
                                const totalProgress = ((i + (fileProgress / 100)) / files.length) * 100;
                                progressBar.style.width = totalProgress + '%';
                                progressPercent.textContent = Math.round(totalProgress) + '%';
                            }
                        });

                        xhr.onload = function() {
                            if (xhr.status === 200) {
                                try {
                                    const data = JSON.parse(xhr.responseText);
                                    resolve(data);
                                } catch (e) {
                                    reject(new Error('Errore nel parsing della risposta'));
                                }
                            } else {
                                reject(new Error('Errore HTTP: ' + xhr.status));
                            }
                        };
                        
                        xhr.onerror = function() {
                            reject(new Error('Errore di rete'));
                        };

                        xhr.open('POST', 'api/files.php?action=upload');
                        xhr.send(formData);
                    });
                    
                    if (response.success) {
                        uploadedCount++;
                    } else {
                        errorCount++;
                        console.error('Errore upload:', response.error);
                    }

                } catch (error) {
                    console.error('Errore upload file:', error);
                    errorCount++;
                }
            }
            
            // Mostra risultato finale
            uploadProgress.style.display = 'none';
            
            if (uploadedCount > 0) {
                alert(`Caricati ${uploadedCount} file con successo${errorCount > 0 ? ` (${errorCount} errori)` : ''}`);
                location.reload();
            } else {
                alert('Errore nel caricamento dei file');
            }
        });

        // Funzioni per gestione file
        async function toggleFavorite(fileId, element) {
            try {
                const response = await fetch(`api/files.php?action=toggle_favorite&id=${fileId}`);
                if (response.ok) {
                    const icon = element.querySelector('i');
                    icon.classList.toggle('text-warning');
                    icon.classList.toggle('text-muted');
                }
            } catch (error) {
                console.error('Errore:', error);
            }
        }

        function downloadFile(fileId) {
            window.open(`api/files.php?action=download&id=${fileId}`, '_blank');
        }

        // Funzione per aprire il modale di rinomina file
        function renameFileSingle(fileId, currentName) {
            document.getElementById('renameFileId').value = fileId;
            document.getElementById('renameFileName').value = currentName;
            document.getElementById('renameFileModal').style.display = 'flex';
            
            // Focus sull'input
            setTimeout(() => {
                document.getElementById('renameFileName').focus();
                document.getElementById('renameFileName').select();
            }, 100);
        }

        // Funzione per eseguire la rinomina del file
        async function performRenameFile() {
            const fileId = document.getElementById('renameFileId').value;
            const newName = document.getElementById('renameFileName').value;
            
            if (!newName.trim()) {
                alert('Inserisci un nome per il file');
                return;
            }
            
            try {
                const response = await fetch('api/files.php?action=rename', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        file_id: fileId,
                        new_name: newName
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Chiudi il modal
                    document.getElementById('renameFileModal').style.display = 'none';
                    
                    // Mostra notifica
                    showNotification('File rinominato con successo!', 'info');
                    
                    // Ricarica la pagina dopo breve delay
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    alert('Errore nella rinomina: ' + data.error);
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nella rinomina del file');
            }
        }

        async function shareFile(fileId) {
            console.log('shareFile chiamata con ID:', fileId);
            
            try {
                const url = `api/files.php?action=share&id=${fileId}`;
                console.log('Chiamata URL:', url);
                
                const response = await fetch(url);
                console.log('Response status:', response.status);
                
                const data = await response.json();
                console.log('Response data:', data);
                
                if (data.success) {
                    document.getElementById('shareModalContent').innerHTML = `
                        <div style="margin-bottom: 20px;">
                            <p style="margin-bottom: 15px; color: var(--text-color);">Il file è stato condiviso con successo!</p>
                            <p style="margin-bottom: 10px; font-weight: 600;">Link di condivisione:</p>
                            <div style="display: flex; gap: 10px; margin-top: 10px;">
                                <input type="text" id="shareLink" value="${data.share_url}" readonly 
                                       style="flex: 1; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background-color: var(--input-bg); color: var(--text-color);">
                                <button class="btn btn-primary" onclick="copyShareLink()">
                                    <i class="fas fa-copy"></i> Copia
                                </button>
                            </div>
                            <div style="margin-top: 15px;">
                                <button class="btn btn-secondary" onclick="window.open('${data.share_url}', '_blank')" style="width: 100%;">
                                    <i class="fas fa-external-link-alt"></i> Apri link
                                </button>
                            </div>
                        </div>
                        <div style="background: var(--hover-bg); padding: 15px; border-radius: 8px; margin-top: 20px;">
                            <p><i class="fas fa-info-circle"></i> <strong>Nota:</strong> Il link scadrà tra 7 giorni</p>
                        </div>
                    `;
                    
                    const modal = document.getElementById('shareModal');
                    if (modal) {
                        modal.style.display = 'flex';
                        console.log('Modal mostrato');
                    } else {
                        console.error('Modal shareModal non trovato');
                    }
                } else {
                    console.error('Errore dalla risposta:', data.error);
                    alert('Errore nella condivisione del file: ' + (data.error || 'Errore sconosciuto'));
                }
            } catch (error) {
                console.error('Errore nella funzione shareFile:', error);
                alert('Errore nella condivisione del file: ' + error.message);
            }
        }

        async function deleteFile(fileId, fileName) {
            if (!confirm(`Sei sicuro di voler spostare "${fileName}" nel cestino?`)) return;
            
            try {
                const response = await fetch(`api/files.php?action=delete&id=${fileId}`, {
                    method: 'DELETE'
                });
                
                if (response.ok) {
                    location.reload();
                } else {
                    alert('Errore nell\'eliminazione del file');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nell\'eliminazione del file');
            }
        }

        async function restoreFile(fileId, fileName) {
            if (!confirm(`Sei sicuro di voler ripristinare "${fileName}"?`)) return;
            
            try {
                const response = await fetch(`api/files.php?action=restore&id=${fileId}`);
                if (response.ok) {
                    location.reload();
                } else {
                    alert('Errore nel ripristino del file');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nel ripristino del file');
            }
        }

        async function permanentDelete(fileId, fileName) {
            if (!confirm(`Sei sicuro di voler eliminare definitivamente "${fileName}"?\n\nQuesta azione è irreversibile!`)) return;
            
            try {
                const response = await fetch(`api/files.php?action=permanent_delete&id=${fileId}`, {
                    method: 'DELETE'
                });
                if (response.ok) {
                    location.reload();
                } else {
                    alert('Errore nell\'eliminazione definitiva del file');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nell\'eliminazione definitiva del file');
            }
        }

        async function emptyTrash() {
            try {
                const response = await fetch(`api/files.php?action=empty_trash`, {
                    method: 'DELETE'
                });
                if (response.ok) {
                    location.reload();
                } else {
                    alert('Errore nello svuotamento del cestino');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nello svuotamento del cestino');
            }
        }

        // Funzione per copiare link
        window.copyShareLink = function() {
            const shareLink = document.getElementById('shareLink');
            shareLink.select();
            document.execCommand('copy');
            alert('Link copiato negli appunti!');
        };

        // Azioni multiple
        async function deleteSelected() {
            if (selectedFiles.size === 0) return;
            
            if (!confirm(`Sei sicuro di voler spostare ${selectedFiles.size} file nel cestino?`)) return;
            
            try {
                const promises = Array.from(selectedFiles).map(fileId => 
                    fetch(`api/files.php?action=delete&id=${fileId}`, { method: 'DELETE' })
                );
                
                await Promise.all(promises);
                showNotification(`${selectedFiles.size} file spostati nel cestino`, 'info');
                selectedFiles.clear();
                updateSelectionUI();
                location.reload();
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nell\'eliminazione dei file');
            }
        }

        async function restoreSelected() {
            if (selectedFiles.size === 0) return;
            
            if (!confirm(`Sei sicuro di voler ripristinare ${selectedFiles.size} file?`)) return;
            
            try {
                const promises = Array.from(selectedFiles).map(fileId => 
                    fetch(`api/files.php?action=restore&id=${fileId}`)
                );
                
                await Promise.all(promises);
                showNotification(`${selectedFiles.size} file ripristinati`, 'info');
                selectedFiles.clear();
                updateSelectionUI();
                location.reload();
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nel ripristino dei file');
            }
        }

        async function permanentDeleteSelected() {
            if (selectedFiles.size === 0) return;
            
            if (!confirm(`Sei sicuro di voler eliminare definitivamente ${selectedFiles.size} file?\n\nQuesta azione è irreversibile!`)) return;
            
            try {
                const promises = Array.from(selectedFiles).map(fileId => 
                    fetch(`api/files.php?action=permanent_delete&id=${fileId}`, { method: 'DELETE' })
                );
                
                await Promise.all(promises);
                showNotification(`${selectedFiles.size} file eliminati definitivamente`, 'info');
                selectedFiles.clear();
                updateSelectionUI();
                location.reload();
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nell\'eliminazione definitiva dei file');
            }
        }

        // Funzione per spostare file selezionati
        function moveSelected() {
            if (selectedFiles.size === 0) return;
            showMoveModal(Array.from(selectedFiles));
        }

        // Funzione per spostare singolo file
        async function moveSingleFile(fileId, fileName) {
            showMoveModal([fileId], fileName);
        }

        // Modale per spostamento file
        function showMoveModal(fileIds, fileName = null) {
            // Assicurati che fileIds sia un array
            if (!Array.isArray(fileIds)) {
                fileIds = [fileIds];
            }
            
            const modalHtml = `
                <div class="modal" id="moveModal" style="display: flex;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>${fileName ? `Sposta "${escapeHtml(fileName)}"` : `Sposta ${fileIds.length} file`}</h2>
                            <button class="action-btn" onclick="closeMoveModal()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p>Seleziona la cartella di destinazione:</p>
                            <select id="moveFolderSelect" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; margin-bottom: 20px; background-color: var(--input-bg); color: var(--text-color);">
                                <option value="0">Tutti i file (root)</option>
                            </select>
                            <button class="btn btn-primary" onclick="performMove([${fileIds.join(',')}])" style="width: 100%;">
                                <i class="fas fa-folder-open"></i>
                                Sposta file
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Rimuovi modale esistente se c'è
            const existingModal = document.getElementById('moveModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Aggiungi modale al body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Popola dropdown cartelle
            populateMoveFolderDropdown();
        }

        function closeMoveModal() {
            document.getElementById('moveModal')?.remove();
        }

        async function populateMoveFolderDropdown() {
            try {
                const response = await fetch('api/folders.php?action=list');
                
                if (!response.ok) {
                    console.error('Errore HTTP:', response.status);
                    return;
                }
                
                const text = await response.text();
                let data;
                
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Errore parsing JSON:', e);
                    return;
                }
                
                if (data.success) {
                    const dropdown = document.getElementById('moveFolderSelect');
                    if (dropdown) {
                        // Pulisci le opzioni esistenti (mantenendo solo la prima)
                        while (dropdown.options.length > 1) {
                            dropdown.remove(1);
                        }
                        
                        data.folders.forEach(folder => {
                            if (folder.id !== 0) {
                                const option = document.createElement('option');
                                option.value = folder.id;
                                option.textContent = folder.name;
                                dropdown.appendChild(option);
                            }
                        });
                    }
                }
            } catch (error) {
                console.error('Errore nel caricamento cartelle:', error);
            }
        }

        async function performMove(fileIds) {
            const folderId = document.getElementById('moveFolderSelect').value;
            
            console.log('performMove chiamata');
            console.log('File IDs:', fileIds);
            console.log('Folder ID:', folderId);
            
            try {
                // Converti fileIds se è una stringa
                if (typeof fileIds === 'string') {
                    try {
                        fileIds = JSON.parse(fileIds);
                    } catch (e) {
                        // Se non è JSON, usa come array con un elemento
                        fileIds = [parseInt(fileIds) || fileIds];
                    }
                }
                
                // Assicurati che fileIds sia un array
                if (!Array.isArray(fileIds)) {
                    fileIds = [fileIds];
                }
                
                console.log('File IDs processati:', fileIds);
                
                const payload = {
                    file_ids: fileIds,
                    folder_id: parseInt(folderId)
                };
                
                console.log('Payload da inviare:', JSON.stringify(payload));
                
                const response = await fetch('api/folders.php?action=move_files', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });
                
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                
                const responseText = await response.text();
                console.log('Response text:', responseText);
                
                if (!responseText.trim()) {
                    throw new Error('Risposta vuota dal server');
                }
                
                let data;
                try {
                    data = JSON.parse(responseText);
                    console.log('Response data parsed:', data);
                } catch (jsonError) {
                    console.error('Errore parsing JSON:', jsonError);
                    console.error('Testo ricevuto:', responseText);
                    throw new Error('Risposta non valida dal server: ' + responseText.substring(0, 100));
                }
                
                if (data.success) {
                    showNotification(`${data.moved || fileIds.length} file spostati`, 'info');
                    closeMoveModal();
                    selectedFiles.clear();
                    updateSelectionUI();
                    
                    // Ricarica la pagina
                    setTimeout(() => {
                        location.reload();
                    }, 500);
                } else {
                    alert('Errore nello spostamento: ' + (data.error || 'Errore sconosciuto'));
                }
            } catch (error) {
                console.error('Errore completo:', error);
                alert('Errore nello spostamento dei file: ' + error.message);
            }
        }

        // Funzioni per gestione cartelle
async function loadFolders() {
    try {
        const response = await fetch('api/folders.php?action=list');
        
        if (!response.ok) {
            throw new Error(`Errore HTTP: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            renderFolders(data.folders);
        } else {
            // Se l'API restituisce un errore
            renderFolders([]);
        }
    } catch (error) {
        // In caso di errore, mostra solo cartella root
        renderFolders([]);
    }
}

function renderFolders(folders) {
    const container = document.getElementById('foldersList');
    if (!container) return;
    
    container.innerHTML = '';
    
    // Assicurati che folders sia un array
    if (!folders || !Array.isArray(folders)) {
        folders = [];
    }
    
    // Aggiungi sempre la cartella root
    if (!folders.find(f => f.id === 0)) {
        folders.unshift({ id: 0, name: 'Tutti i file (root)', color: '#0066cc' });
    }
    
    folders.forEach(folder => {
        const item = document.createElement('a');
        item.href = '#';
        item.className = 'nav-item';
        
        // Evidenzia la cartella corrente
        const urlParams = new URLSearchParams(window.location.search);
        const currentFolder = urlParams.get('folder') || '0';
        if (currentFolder == folder.id) {
            item.classList.add('active');
        }
        
        item.innerHTML = `
            <i class="fas fa-folder"></i>
            <span>${folder.name}</span>
            ${folder.id !== 0 ? `
                <span class="folder-actions">
                    <button onclick="event.stopPropagation(); renameFolder(${folder.id}, '${escapeJs(folder.name)}')" 
                            class="action-btn" 
                            title="Rinomina">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="event.stopPropagation(); deleteFolder(${folder.id}, '${escapeJs(folder.name)}')" 
                            class="action-btn" 
                            title="Elimina">
                        <i class="fas fa-times"></i>
                    </button>
                </span>
            ` : ''}
        `;
        
        item.onclick = (e) => {
            if (e.target.closest('.folder-actions')) return;
            e.preventDefault();
            navigateToFolder(folder.id, folder.name);
        };
        
        container.appendChild(item);
    });
}

        async function showCreateFolderModal() {
            // Popola il select delle cartelle parent
            const parentSelect = document.getElementById('folderParent');
            parentSelect.innerHTML = '<option value="0">Root (nessuna cartella parent)</option>';
            
            try {
                const response = await fetch('api/folders.php?action=list');
                const data = await response.json();
                
                if (data.success && data.folders) {
                    data.folders.forEach(folder => {
                        if (folder.id !== 0) { // Escludi la root fittizia
                            const option = document.createElement('option');
                            option.value = folder.id;
                            option.textContent = folder.name;
                            parentSelect.appendChild(option);
                        }
                    });
                }
            } catch (error) {
                console.error('Errore caricamento cartelle:', error);
            }
            
            // Preseleziona la cartella corrente se siamo dentro una cartella
            const urlParams = new URLSearchParams(window.location.search);
            const currentFolder = urlParams.get('folder');
            if (currentFolder) {
                parentSelect.value = currentFolder;
            }
            
            folderModal.style.display = 'flex';
        }

        async function createFolder() {
            const name = document.getElementById('folderName').value;
            const parentId = document.getElementById('folderParent').value;
            
            if (!name.trim()) {
                alert('Inserisci un nome per la cartella');
                return;
            }
            
            try {
                const response = await fetch('api/folders.php?action=create', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ 
                        name: name,
                        parent_id: parentId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    folderModal.style.display = 'none';
                    document.getElementById('folderName').value = '';
                    showNotification('Cartella creata con successo!', 'info');
                    // Ricarica la pagina per mostrare la nuova cartella
                    location.reload();
                } else {
                    alert(data.error || 'Errore nella creazione della cartella');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nella creazione della cartella');
            }
        }

        // Funzioni per rinominare cartelle
        function renameFolder(folderId, currentName) {
            // Chiudi tutti i menu mobile aperti
            document.querySelectorAll('.mobile-actions-dropdown.show').forEach(d => {
                d.classList.remove('show');
            });
            
            document.getElementById('renameFolderId').value = folderId;
            document.getElementById('renameFolderName').value = currentName;
            document.getElementById('renameFolderModal').style.display = 'flex';
            // Focus sull'input
            setTimeout(() => {
                document.getElementById('renameFolderName').focus();
                document.getElementById('renameFolderName').select();
            }, 100);
        }

        async function performRename() {
            const folderId = document.getElementById('renameFolderId').value;
            const newName = document.getElementById('renameFolderName').value;
            
            if (!newName.trim()) {
                alert('Inserisci un nome per la cartella');
                return;
            }
            
            try {
                const response = await fetch('api/folders.php?action=rename', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        folder_id: folderId,
                        new_name: newName
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Chiudi il modal
                    document.getElementById('renameFolderModal').style.display = 'none';
                    
                    // Aggiorna visivamente il nome nelle righe desktop
                    const rows = document.querySelectorAll('.folder-row');
                    rows.forEach(row => {
                        if (row.onclick && row.onclick.toString().includes(`folder=${folderId}`)) {
                            const nameElement = row.querySelector('.file-name-text');
                            if (nameElement) {
                                nameElement.textContent = newName;
                            }
                        }
                    });
                    
                    // Aggiorna visivamente il nome nelle card mobile
                    const cards = document.querySelectorAll('.folder-card-mobile');
                    cards.forEach(card => {
                        if (card.onclick && card.onclick.toString().includes(`folder=${folderId}`)) {
                            const nameElement = card.querySelector('.file-card-name');
                            if (nameElement) {
                                nameElement.textContent = newName;
                            }
                        }
                    });
                    
                    // Mostra notifica
                    showNotification('Cartella rinominata con successo!', 'info');
                    
                    // Se siamo nella cartella rinominata, aggiorna il titolo e breadcrumb
                    const urlParams = new URLSearchParams(window.location.search);
                    const currentFolder = urlParams.get('folder');
                    
                    if (currentFolder && currentFolder == folderId) {
                        document.getElementById('pageTitle').textContent = newName;
                        loadBreadcrumb(); // Aggiorna il breadcrumb
                    }
                } else {
                    alert('Errore nella rinomina: ' + data.error);
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nella rinomina della cartella');
            }
        }

        async function deleteFolder(folderId, folderName) {
            // Chiudi tutti i menu mobile aperti
            document.querySelectorAll('.mobile-actions-dropdown.show').forEach(d => {
                d.classList.remove('show');
            });
            
            if (!confirm(`Sei sicuro di voler eliminare la cartella "${folderName}"?\nI file al suo interno verranno spostati nella root.`)) return;
            
            try {
                const response = await fetch(`api/folders.php?action=delete&id=${folderId}`, {
                    method: 'DELETE'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Rimuovi visivamente le righe e le card
                    const rows = document.querySelectorAll(`.folder-row[data-folder-id="${folderId}"], .folder-card-mobile`);
                    rows.forEach(el => {
                        // Verifica che sia la cartella giusta (per le card senza data-folder-id)
                        if (el.classList.contains('folder-card-mobile')) {
                            if (el.onclick && el.onclick.toString().includes(`folder=${folderId}`)) {
                                el.style.transition = 'opacity 0.3s, transform 0.3s';
                                el.style.opacity = '0';
                                el.style.transform = 'translateX(-20px)';
                                setTimeout(() => el.remove(), 300);
                            }
                        } else {
                            el.style.transition = 'opacity 0.3s';
                            el.style.opacity = '0';
                            setTimeout(() => el.remove(), 300);
                        }
                    });
                    
                    showNotification('Cartella eliminata con successo!', 'info');
                } else {
                    alert(data.error || 'Errore nell\'eliminazione della cartella');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nell\'eliminazione della cartella');
            }
        }

        // Sposta cartella
        async function moveFolder(folderId, folderName) {
            // Chiudi tutti i menu mobile aperti
            document.querySelectorAll('.mobile-actions-dropdown.show').forEach(d => {
                d.classList.remove('show');
            });
            
            document.getElementById('moveFolderId').value = folderId;
            document.getElementById('moveFolderName').textContent = folderName;
            
            // Popola il select delle destinazioni
            const destSelect = document.getElementById('moveFolderDestination');
            destSelect.innerHTML = '<option value="0">Root (cartella principale)</option>';
            
            try {
                const response = await fetch('api/folders.php?action=list');
                const data = await response.json();
                
                if (data.success && data.folders) {
                    data.folders.forEach(folder => {
                        // Escludi la cartella stessa e Root fittizia
                        if (folder.id !== 0 && folder.id != folderId) {
                            const option = document.createElement('option');
                            option.value = folder.id;
                            option.textContent = folder.name;
                            destSelect.appendChild(option);
                        }
                    });
                }
            } catch (error) {
                console.error('Errore caricamento cartelle:', error);
            }
            
            document.getElementById('moveFolderModal').style.display = 'flex';
        }

        async function performMoveFolder() {
            const folderId = document.getElementById('moveFolderId').value;
            const newParentId = document.getElementById('moveFolderDestination').value;
            const folderName = document.getElementById('moveFolderName').textContent;
            
            try {
                const response = await fetch('api/folders.php?action=move', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        folder_id: folderId,
                        new_parent_id: newParentId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('moveFolderModal').style.display = 'none';
                    
                    // Rimuovi visivamente la cartella (righe e card)
                    const rows = document.querySelectorAll(`.folder-row[data-folder-id="${folderId}"], .folder-card-mobile`);
                    rows.forEach(el => {
                        if (el.classList.contains('folder-card-mobile')) {
                            if (el.onclick && el.onclick.toString().includes(`folder=${folderId}`)) {
                                el.style.transition = 'opacity 0.3s, transform 0.3s';
                                el.style.opacity = '0';
                                el.style.transform = 'translateX(20px)';
                                setTimeout(() => el.remove(), 300);
                            }
                        } else {
                            el.style.transition = 'opacity 0.3s';
                            el.style.opacity = '0';
                            setTimeout(() => el.remove(), 300);
                        }
                    });
                    
                    showNotification('Cartella spostata con successo!', 'info');
                } else {
                    alert(data.error || 'Errore nello spostamento');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nello spostamento della cartella');
            }
        }

        function navigateToFolder(folderId, folderName) {
            const url = new URL(window.location);
            if (folderId == 0) {
                url.searchParams.delete('folder');
            } else {
                url.searchParams.set('folder', folderId);
            }
            url.searchParams.delete('filter');
            url.searchParams.delete('category');
            
            window.location.href = url;
        }

        async function loadFilesForFolder(folderId) {
            try {
                const response = await fetch(`api/files.php?action=get_by_folder&folder_id=${folderId}`);
                const data = await response.json();
                
                if (data.success) {
                    updateFileTable(data.files);
                    updateFileCards(data.files);
                    
                    const fileCountElement = document.getElementById('fileCount');
                    if (fileCountElement) {
                        fileCountElement.innerHTML = `<strong>${data.files.length} elementi</strong>`;
                    }
                }
            } catch (error) {
                console.error('Errore nel caricamento file:', error);
            }
        }

        function updateFileTable(files) {
            const tbody = document.querySelector('#filesTable');
            if (!tbody) return;
            
            if (files.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                            <i class="fas fa-folder-open" style="font-size: 3rem; margin-bottom: 15px; display: block;"></i>
                            Cartella vuota<br>
                            <small>Non ci sono file in questa cartella</small>
                        </td>
                    </tr>
                `;
                return;
            }
            
            let html = '';
            
            files.forEach(file => {
                const ext = file.filename.split('.').pop().toLowerCase();
                const icon = getFileIcon(ext);
                const size = formatBytes(file.filesize);
                const date = isTrash && file.deleted_at 
                    ? formatDate(file.deleted_at)
                    : formatDate(file.created_at);
                const category_type = getCategoryFromExtension(ext);
                
                html += `
                    <tr data-file-id="${file.id}" data-category="${category_type}" data-favorite="${file.is_favorite}">
                        <td>
                            <input type="checkbox" class="file-checkbox" value="${file.id}">
                        </td>
                        <td>
                            <div class="file-name">
                                <div class="file-icon ${ext}">
                                    <i class="fas ${icon}"></i>
                                </div>
                                <div>
                                    <div class="file-name-text">${escapeHtml(file.original_name)}</div>
                                    <div style="font-size: 0.85rem; color: #888;">${escapeHtml(file.filetype)}</div>
                                </div>
                            </div>
                        </td>
                        <td>${size}</td>
                        <td>
                            ${file.tags ? `<span style="background-color: #e6f7ff; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">${escapeHtml(file.tags)}</span>` : ''}
                            ${file.is_favorite && !isTrash ? '<i class="fas fa-star text-warning" title="Preferito"></i>' : ''}
                        </td>
                        <td>${date}</td>
                        <td>
                            <div class="file-actions">
                                ${isTrash ? `
                                    <button class="action-btn" title="Ripristina" onclick="restoreFile(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                    <button class="action-btn" title="Elimina definitivamente" onclick="permanentDelete(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-times text-danger"></i>
                                    </button>
                                ` : `
                                    <button class="action-btn favorite-btn" title="Preferiti" onclick="toggleFavorite(${file.id}, this)" data-file-id="${file.id}">
                                        <i class="fas fa-star ${file.is_favorite ? 'text-warning' : 'text-muted'}"></i>
                                    </button>
                                    <button class="action-btn" title="Scarica" onclick="downloadFile(${file.id})">
                                        <i class="fas fa-download"></i>
                                    </button>
                                    <button class="action-btn" title="Sposta" onclick="moveSingleFile(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-folder-open"></i>
                                    </button>
                                    <button class="action-btn" title="Rinomina" onclick="renameFileSingle(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="action-btn" title="Condividi" onclick="shareFile(${file.id})">
                                        <i class="fas fa-share-alt"></i>
                                    </button>
                                    <button class="action-btn" title="Elimina" onclick="deleteFile(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                `}
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
            
            // Re-attach event listeners after updating table
            initCheckboxListeners();
        }

        function updateFileCards(files) {
            const container = document.getElementById('fileCards');
            if (!container) return;
            
            if (files.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #999;">
                        <i class="fas fa-folder-open" style="font-size: 3rem; margin-bottom: 15px; display: block;"></i>
                        Cartella vuota
                    </div>
                `;
                return;
            }
            
            let html = '';
            
            files.forEach(file => {
                const ext = file.filename.split('.').pop().toLowerCase();
                const icon = getFileIcon(ext);
                const size = formatBytes(file.filesize);
                const date = isTrash && file.deleted_at 
                    ? formatDate(file.deleted_at)
                    : formatDate(file.created_at);
                
                html += `
                    <div class="file-card" data-file-id="${file.id}" data-category="${getCategoryFromExtension(ext)}" data-favorite="${file.is_favorite}">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" class="file-checkbox" value="${file.id}">
                            <div class="file-icon ${ext}">
                                <i class="fas ${icon}"></i>
                            </div>
                            <div class="file-card-info" style="padding-right: 40px;">
                                <div class="file-card-name">${escapeHtml(file.original_name)}</div>
                                <div class="file-card-meta">
                                    <span>${size}</span>
                                    <span>${date}</span>
                                    ${file.is_favorite && !isTrash ? '<i class="fas fa-star text-warning"></i>' : ''}
                                </div>
                            </div>
                        </div>
                        <div class="mobile-context-menu">
                            <button class="mobile-menu-trigger" onclick="toggleMobileMenu(this)">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div class="mobile-actions-dropdown">
                                ${isTrash ? `
                                    <button class="action-btn" onclick="restoreFile(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-undo"></i>
                                        <span>Ripristina</span>
                                    </button>
                                    <button class="action-btn" onclick="permanentDelete(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-times text-danger"></i>
                                        <span>Elimina definitivamente</span>
                                    </button>
                                ` : `
                                    <button class="action-btn" onclick="downloadFile(${file.id})">
                                        <i class="fas fa-download"></i>
                                        <span>Scarica</span>
                                    </button>
                                    <button class="action-btn" onclick="moveSingleFile(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-folder-open"></i>
                                        <span>Sposta</span>
                                    </button>
                                    <button class="action-btn" onclick="renameFileSingle(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-edit"></i>
                                        <span>Rinomina</span>
                                    </button>
                                    <button class="action-btn" onclick="shareFile(${file.id})">
                                        <i class="fas fa-share-alt"></i>
                                        <span>Condividi</span>
                                    </button>
                                    <button class="action-btn" onclick="previewFile(${file.id}, '${escapeJs(file.original_name)}', '${escapeJs(file.filename)}', '${escapeJs(file.filetype)}')">
                                        <i class="fas fa-eye"></i>
                                        <span>Anteprima</span>
                                    </button>
                                    <button class="action-btn" onclick="deleteFile(${file.id}, '${escapeJs(file.original_name)}')">
                                        <i class="fas fa-trash"></i>
                                        <span>Elimina</span>
                                    </button>
                                `}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            
            // Re-attach event listeners after updating cards
            initCheckboxListeners();
        }

        // Gestione tema (ora gestito da theme.js)

        // Funzioni helper
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function escapeJs(text) {
            if (!text) return '';
            return text.replace(/'/g, "\\'").replace(/"/g, '\\"').replace(/\n/g, '\\n');
        }

        function formatDate(dateString) {
            if (!dateString) return 'Data sconosciuta';
            const date = new Date(dateString);
            return date.toLocaleDateString('it-IT', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        function getFileIcon(ext) {
            const icons = {
                'txt': 'fa-file-alt',
                'pdf': 'fa-file-pdf',
                'doc': 'fa-file-word',
                'docx': 'fa-file-word',
                'xls': 'fa-file-excel',
                'xlsx': 'fa-file-excel',
                'jpg': 'fa-image',
                'jpeg': 'fa-image',
                'png': 'fa-image',
                'gif': 'fa-image',
                'zip': 'fa-file-archive',
                'rar': 'fa-file-archive',
                'mp3': 'fa-file-audio',
                'wav': 'fa-file-audio',
                'mp4': 'fa-file-video',
                'avi': 'fa-file-video',
                'mkv': 'fa-file-video',
                'ppt': 'fa-file-powerpoint',
                'pptx': 'fa-file-powerpoint'
            };
            return icons[ext.toLowerCase()] || 'fa-file';
        }

        function getCategoryFromExtension(ext) {
            const image = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
            const video = ['mp4', 'avi', 'mkv', 'mov', 'wmv'];
            const audio = ['mp3', 'wav', 'ogg', 'flac'];
            const document = ['txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
            
            ext = ext.toLowerCase();
            if (image.includes(ext)) return 'images';
            if (video.includes(ext)) return 'videos';
            if (audio.includes(ext)) return 'audio';
            if (document.includes(ext)) return 'documents';
            return 'other';
        }

        // Notifiche
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                    <span>${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" style="margin-left: auto; background: none; border: none; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            const contentHeader = document.querySelector('.content-header');
            contentHeader.parentNode.insertBefore(notification, contentHeader.nextSibling);
            
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Ricerca file
        function handleSearch() {
            const term = this.value.toLowerCase();
            const rows = document.querySelectorAll('#filesTable tr[data-file-id], .file-card');
            const fileCountElement = document.getElementById('fileCount');
            
            let visibleCount = 0;
            
            rows.forEach(row => {
                const fileName = row.querySelector('.file-name-text, .file-card-name')?.textContent.toLowerCase() || '';
                
                if (fileName.includes(term)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (fileCountElement) {
                fileCountElement.innerHTML = `<strong>${visibleCount} elementi</strong>`;
            }
        }

        // Data nel footer
        const now = new Date();
        const currentDateElement = document.getElementById('currentDate');
        if (currentDateElement) {
            currentDateElement.textContent = now.toLocaleDateString('it-IT', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
        }

        // Gestione form cartella
        document.getElementById('folderForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            createFolder();
        });

        // Gestione form rinomina cartella
        document.getElementById('renameFolderForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            performRename();
        });

        // Gestione form rinomina file
        document.getElementById('renameFileForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            performRenameFile();
        });

        // Tema gestito da theme.js (non serve event listener qui)

        // Funzione per mostrare pagine diverse (profilo, utenti, sistema)
        function showPage(page) {
            // Nascondi tutte le content-page
            document.querySelectorAll('.content-page').forEach(p => {
                p.style.display = 'none';
            });
            
            // Nascondi il contenuto principale
            const mainContent = document.querySelector('.content');
            if (mainContent) {
                const originalDisplay = mainContent.style.display;
                mainContent.style.display = 'none';
            }
            
            // Mostra la pagina richiesta
            switch(page) {
                case 'profile':
                    const profilePage = document.getElementById('profilePage');
                    if (profilePage) {
                        profilePage.style.display = 'block';
                        // Carica dati profilo se la funzione esiste
                        if (typeof loadProfile === 'function') {
                            loadProfile();
                        }
                    } else {
                        console.error('Pagina profilo non trovata. Assicurati che users_system_pages.html sia incluso.');
                    }
                    break;
                    
                case 'users':
                    const usersPage = document.getElementById('usersPage');
                    if (usersPage) {
                        usersPage.style.display = 'block';
                        if (typeof loadUsers === 'function') {
                            loadUsers();
                        }
                    } else {
                        console.error('Pagina utenti non trovata.');
                    }
                    break;
                    
                case 'system':
                    const systemPage = document.getElementById('systemPage');
                    if (systemPage) {
                        systemPage.style.display = 'block';
                        if (typeof loadSystemConfig === 'function') {
                            loadSystemConfig();
                        }
                        if (typeof loadSystemStats === 'function') {
                            loadSystemStats();
                        }
                    } else {
                        console.error('Pagina sistema non trovata.');
                    }
                    break;
                    
                case 'home':
                default:
                    // Mostra il contenuto principale
                    if (mainContent) {
                        mainContent.style.display = 'block';
                    }
            }
        }

        // Gestione menu contestuale mobile
        // Funzione per caricare avatar utente nell'header
        async function loadUserAvatar() {
            try {
                const response = await fetch('api/users.php?action=get_profile');
                const data = await response.json();
                
                if (data.success && data.user && data.user.avatar) {
                    const avatarImg = document.getElementById('userAvatarImg');
                    const avatarIcon = document.getElementById('userAvatarIcon');
                    
                    if (avatarImg && avatarIcon) {
                        avatarImg.src = data.user.avatar + '?t=' + Date.now();
                        avatarImg.style.display = 'block';
                        avatarIcon.style.display = 'none';
                    }
                }
            } catch (error) {
                console.error('Errore caricamento avatar:', error);
                // In caso di errore, mostra l'icona di default
            }
        }

        function toggleMobileMenu(button) {
            const dropdown = button.nextElementSibling;
            dropdown.classList.toggle('show');
            
            // Chiudi altri dropdown aperti
            document.querySelectorAll('.mobile-actions-dropdown.show').forEach(otherDropdown => {
                if (otherDropdown !== dropdown) {
                    otherDropdown.classList.remove('show');
                }
            });
            
            // Ferma la propagazione dell'evento
            event.stopPropagation();
        }

        // Chiudi i dropdown quando si clicca altrove
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mobile-context-menu')) {
                document.querySelectorAll('.mobile-actions-dropdown.show').forEach(dropdown => {
                    dropdown.classList.remove('show');
                });
            }
        });

        // Supporto per tasto ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = [
                    'uploadModal', 'shareModal', 'folderModal', 
                    'renameFolderModal', 'renameFileModal', 'moveModal',
                    'previewModal'
                ];
                
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (modal && modal.style.display === 'flex') {
                        modal.style.display = 'none';
                    }
                });
            }
        });

        // Inizializzazione
        document.addEventListener('DOMContentLoaded', function() {
            // Carica tema
            // loadTheme(); // Gestito da theme.js
            
            // Carica avatar utente nell'header
            loadUserAvatar();
            
            // Carica cartelle
            loadFolders();
            
            // Inizializza i listener delle checkbox
            initCheckboxListeners();
            
            // Aggiungi listener per ricerca
            if (searchInput) {
                searchInput.addEventListener('input', handleSearch);
            }
            
            // Controlla cartella corrente
            const urlParams = new URLSearchParams(window.location.search);
            const folderId = urlParams.get('folder');
            
            if (folderId && folderId !== '0') {
                // Aggiorna titolo con il nome della cartella
                fetch('api/folders.php?action=list')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const folder = data.folders.find(f => f.id == folderId);
                            if (folder) {
                                document.getElementById('pageTitle').textContent = folder.name;
                            }
                        }
                    });
            }
        });
    </script>

    <!-- Breadcrumb Navigation -->
    <script>
    if (document.getElementById('breadcrumbNav')) {
        // Breadcrumb semplice
        async function loadBreadcrumb() {
            const container = document.getElementById('breadcrumbNav');
            if (!container) return;
            
            const urlParams = new URLSearchParams(window.location.search);
            const folderId = urlParams.get('folder');
            
            if (!folderId || folderId === '0') {
                container.innerHTML = '<div class="breadcrumb-item active"><i class="fas fa-home"></i> <span>I miei file</span></div>';
                container.style.display = 'flex';
                return;
            }
            
            try {
                const response = await fetch(`api/folders.php?action=get_path&id=${folderId}`);
                const data = await response.json();
                
                if (data.success) {
                    let html = '<div class="breadcrumb-item" onclick="window.location.href=\'index.php\'"><i class="fas fa-home"></i> <span>I miei file</span></div>';
                    
                    data.path.forEach((folder, index) => {
                        const isLast = index === data.path.length - 1;
                        html += '<span class="breadcrumb-separator"><i class="fas fa-chevron-right"></i></span>';
                        html += `<div class="breadcrumb-item ${isLast ? 'active' : ''}" onclick="window.location.href='index.php?folder=${folder.id}'"><i class="fas fa-folder"></i> <span>${folder.name}</span></div>`;
                    });
                    
                    container.innerHTML = html;
                    container.style.display = 'flex';
                }
            } catch (error) {
                console.error('Errore breadcrumb:', error);
            }
        }
        
        // Carica cartelle nella griglia
        async function loadFoldersInGrid() {
            const urlParams = new URLSearchParams(window.location.search);
            const parentId = urlParams.get('folder') || 0;
            
            // Verifica che non siamo in una vista filtrata
            if (urlParams.has('filter') || urlParams.has('category')) {
                return;
            }
            
            try {
                const response = await fetch(`api/folders.php?action=list_in_folder&parent_id=${parentId}`);
                const data = await response.json();
                
                if (data.success && data.folders && data.folders.length > 0) {
                    const tableBody = document.getElementById('filesTable');
                    const cardsContainer = document.getElementById('fileCards');
                    
                    data.folders.forEach(folder => {
                        // CARD MOBILE
                        if (cardsContainer) {
                            const card = document.createElement('div');
                            card.className = 'file-card folder-card-mobile';
                            card.onclick = () => window.location.href = `index.php?folder=${folder.id}`;
                            card.innerHTML = `
                                <div style="display: flex; align-items: center; gap: 12px; width: 100%;">
                                    <div class="file-icon folder-icon-dynamic" style="flex-shrink: 0;">
                                        <i class="fas fa-folder" style="color: white;"></i>
                                    </div>
                                    <div class="file-card-info" style="flex: 1; min-width: 0;">
                                        <div class="file-card-name" style="font-weight: 600;">${folder.name}</div>
                                        <div class="file-card-meta">
                                            <span class="folder-badge-dynamic" style="font-size: 0.75rem; padding: 2px 6px; border-radius: 8px; display: inline-block;">
                                                <i class="fas fa-file"></i> ${folder.files_count || 0} file
                                            </span>
                                        </div>
                                    </div>
                                    <div style="position: relative;">
                                        <div class="mobile-actions-trigger" onclick="event.stopPropagation(); toggleMobileActions(this)">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </div>
                                        <div class="mobile-actions-dropdown">
                                            <button class="action-btn" onclick="event.stopPropagation(); renameFolder(${folder.id}, '${folder.name.replace(/'/g, "\\'")}')">
                                                <i class="fas fa-edit"></i> Rinomina
                                            </button>
                                            <button class="action-btn" onclick="event.stopPropagation(); moveFolder(${folder.id}, '${folder.name.replace(/'/g, "\\'")}')">
                                                <i class="fas fa-folder-open"></i> Sposta
                                            </button>
                                            <button class="action-btn" onclick="event.stopPropagation(); deleteFolder(${folder.id}, '${folder.name.replace(/'/g, "\\'")}')">
                                                <i class="fas fa-trash"></i> Elimina
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `;
                            cardsContainer.insertBefore(card, cardsContainer.firstChild);
                        }
                        
                        // RIGA DESKTOP (tabella)
                        if (tableBody) {
                            const row = document.createElement('tr');
                            row.className = 'folder-row';
                            row.setAttribute('data-folder-id', folder.id);
                            row.onclick = () => window.location.href = `index.php?folder=${folder.id}`;
                            row.innerHTML = `
                                <td></td>
                                <td>
                                    <div class="file-name">
                                        <div class="file-icon folder-icon-dynamic">
                                            <i class="fas fa-folder" style="color: white;"></i>
                                        </div>
                                        <div>
                                            <div class="file-name-text">${folder.name}</div>
                                            <div style="font-size: 0.85rem; color: #888;">Cartella</div>
                                        </div>
                                    </div>
                                </td>
                                <td>—</td>
                                <td><span class="folder-badge-dynamic"><i class="fas fa-file"></i> ${folder.files_count || 0}</span></td>
                                <td>${new Date(folder.created_at).toLocaleDateString('it-IT')}</td>
                                <td>
                                    <button class="action-btn" onclick="event.stopPropagation(); renameFolder(${folder.id}, '${folder.name.replace(/'/g, "\\'")}')"><i class="fas fa-edit"></i><span>Rinomina</span></button>
                                    <button class="action-btn" onclick="event.stopPropagation(); moveFolder(${folder.id}, '${folder.name.replace(/'/g, "\\'")}')"><i class="fas fa-folder-open"></i><span>Sposta</span></button>
                                    <button class="action-btn" onclick="event.stopPropagation(); deleteFolder(${folder.id}, '${folder.name.replace(/'/g, "\\'")}')"><i class="fas fa-trash"></i><span>Elimina</span></button>
                                </td>
                            `;
                            tableBody.insertBefore(row, tableBody.firstChild);
                        }
                    });
                    
                    // Applica i colori dinamici
                    updateFolderColors();
                }
            } catch (error) {
                console.error('Errore caricamento cartelle:', error);
            }
        }
        
        // Ottieni colore cartella basato sul tema
        function getFolderColorForTheme() {
            const body = document.body;
            if (body.classList.contains('dark-theme')) {
                return 'linear-gradient(135deg, #4a5568 0%, #2d3748 100%)';
            } else if (body.classList.contains('blue-theme')) {
                return 'linear-gradient(135deg, #0066cc 0%, #004d99 100%)';
            } else if (body.classList.contains('green-theme')) {
                return 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
            } else {
                // light-theme
                return 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
            }
        }
        
        // Aggiorna colori cartelle quando cambia il tema
        function updateFolderColors() {
            const folderColor = getFolderColorForTheme();
            const badgeColor = getBadgeColorForTheme();
            
            document.querySelectorAll('.folder-icon-dynamic').forEach(icon => {
                icon.style.background = folderColor;
            });
            
            document.querySelectorAll('.folder-badge-dynamic').forEach(badge => {
                badge.style.background = badgeColor.bg;
                badge.style.color = badgeColor.color;
            });
        }
        
        // Rendi updateFolderColors globale per theme.js
        window.updateFolderColors = updateFolderColors;
        
        // Funzione per toggle menu azioni mobile
        function toggleMobileActions(trigger) {
            console.log('toggleMobileActions chiamata', trigger);
            
            // Trova il dropdown (nextElementSibling)
            const dropdown = trigger.nextElementSibling;
            console.log('Dropdown trovato:', dropdown);
            
            if (!dropdown || !dropdown.classList.contains('mobile-actions-dropdown')) {
                console.error('Dropdown non trovato o non valido');
                return;
            }
            
            const isCurrentlyShown = dropdown.classList.contains('show');
            
            // Chiudi TUTTI i dropdown aperti
            document.querySelectorAll('.mobile-actions-dropdown.show').forEach(d => {
                d.classList.remove('show');
            });
            
            // Se non era aperto, aprilo
            if (!isCurrentlyShown) {
                dropdown.classList.add('show');
                console.log('Dropdown aperto');
                
                // Chiudi quando clicchi fuori
                setTimeout(() => {
                    const closeHandler = (e) => {
                        if (!dropdown.contains(e.target) && !trigger.contains(e.target)) {
                            dropdown.classList.remove('show');
                            document.removeEventListener('click', closeHandler);
                            console.log('Dropdown chiuso');
                        }
                    };
                    document.addEventListener('click', closeHandler);
                }, 100);
            } else {
                console.log('Dropdown chiuso');
            }
        }
        
        // Rendi globale
        window.toggleMobileActions = toggleMobileActions;
        
        // Observer per cambio classe tema sul body
        const bodyObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.attributeName === 'class') {
                    // Il tema è cambiato, aggiorna i colori
                    updateFolderColors();
                }
            });
        });
        
        // Avvia observer
        bodyObserver.observe(document.body, { attributes: true });
        
        function getBadgeColorForTheme() {
            const body = document.body;
            if (body.classList.contains('dark-theme')) {
                return { bg: '#374151', color: '#9ca3af' };
            } else if (body.classList.contains('blue-theme')) {
                return { bg: '#dbeafe', color: '#0066cc' };
            } else if (body.classList.contains('green-theme')) {
                return { bg: '#d1fae5', color: '#059669' };
            } else {
                return { bg: '#f0f5ff', color: '#0066cc' };
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            loadBreadcrumb();
            loadFoldersInGrid();
        });
    }
    </script>

    <script>
    // Funzione globale per mostrare/nascondere password
    function togglePasswordVisibility(inputId, button) {
        const input = document.getElementById(inputId);
        const icon = button.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    </script>

    <script src="users_system.js"></script>
</body>
</html>
