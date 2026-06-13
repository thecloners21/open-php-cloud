<?php
// shared.php - Versione migliorata con design elegante
error_reporting(0);

// Percorso assoluto per config.php
$config_path = __DIR__ . '/config.php';
if (!file_exists($config_path)) {
    die('Configurazione non trovata');
}

require_once $config_path;

// Controlla se è una richiesta di download diretto
$is_download = isset($_GET['download']) && $_GET['download'] == '1';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    showErrorPage('Link di condivisione non valido', 'Il token di condivisione è mancante o scaduto.');
}

try {
    // Cerca il file con il token
    $stmt = $pdo->prepare("SELECT 
        f.id,
        f.original_name, 
        f.filepath, 
        f.filetype,
        f.filesize,
        f.view_count,
        f.is_shared,
        f.shared_token,
        f.shared_expiry,
        f.created_at,
        u.username as owner
    FROM files f
    LEFT JOIN users u ON f.user_id = u.id
    WHERE f.shared_token = ? 
    AND f.is_shared = 1
    AND (f.shared_expiry IS NULL OR f.shared_expiry > datetime('now'))");
    
    $stmt->execute([$token]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        showErrorPage('File non trovato', 'Il link di condivisione potrebbe essere scaduto o il file potrebbe essere stato rimosso.');
    }

    // Verifica che il file esista fisicamente
    if (!file_exists($file['filepath'])) {
        showErrorPage('File non disponibile', 'Il file non è più disponibile sul server.');
    }

    // Incrementa il contatore di visualizzazioni SOLO alla prima visualizzazione
    if (!$is_download) {
        $stmt = $pdo->prepare("UPDATE files SET view_count = view_count + 1, last_viewed = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$file['id']]);
    }

    // Se è una richiesta di download, forza il download
    if ($is_download) {
        forceDownload($file);
    }

    // Altrimenti mostra la pagina di preview
    showSharePage($file, $token);
    
} catch (Exception $e) {
    showErrorPage('Errore del server', 'Si è verificato un errore durante il recupero del file.');
}

/**
 * Mostra la pagina di condivisione con interfaccia elegante
 */
function showSharePage($file, $token) {
    $file_ext = pathinfo($file['original_name'], PATHINFO_EXTENSION);
    $file_size = formatBytes($file['filesize']);
    $file_icon = getFileIcon($file_ext);
    $file_type = getFileTypeDescription($file_ext);
    $created_at = date('d/m/Y H:i', strtotime($file['created_at']));
    $expiry_date = $file['shared_expiry'] ? date('d/m/Y H:i', strtotime($file['shared_expiry'])) : 'Mai';
    $download_url = "./shared.php?token=" . urlencode($token) . "&download=1";
    $can_preview = canPreviewFile($file_ext);
    $preview_content = $can_preview ? getFilePreview($file) : null;
    
    // Determina il colore dell'icona basato sull'estensione
    $icon_color = getIconColor($file_ext);
    
    // HTML della pagina
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenCloud - File Condiviso: <?php echo htmlspecialchars($file['original_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #5c6bc0;
            --success-color: #2e7d32;
            --warning-color: #ff9800;
            --danger-color: #f44336;
            --dark-color: #2c3e50;
            --light-color: #f8f9fa;
            --gray-color: #6c757d;
            --border-color: #e0e0e0;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            --radius: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--dark-color);
        }
        
        .share-container {
            width: 100%;
            max-width: 800px;
            background: white;
            border-radius: var(--radius);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        
        .share-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .share-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            opacity: 0.1;
        }
        
        .header-content {
            position: relative;
            z-index: 1;
        }
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
            color: white;
            font-size: 1.8rem;
            font-weight: 700;
        }
        
        .share-icon {
            font-size: 3.5rem;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .share-header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .share-header p {
            font-size: 1rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .share-body {
            padding: 40px;
        }
        
        .file-card {
            background: var(--light-color);
            border-radius: var(--radius);
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }
        
        .file-info {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .file-icon-container {
            width: 80px;
            height: 80px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: white;
            font-size: 2.5rem;
        }
        
        .file-details {
            flex: 1;
        }
        
        .file-name {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 8px;
            word-break: break-word;
        }
        
        .file-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 0.9rem;
            color: var(--gray-color);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .meta-item i {
            width: 16px;
            text-align: center;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 25px;
        }
        
        .info-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .info-label {
            font-size: 0.85rem;
            color: var(--gray-color);
            margin-bottom: 5px;
        }
        
        .info-value {
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 16px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 102, 204, 0.3);
        }
        
        .btn-secondary {
            background: white;
            color: var(--dark-color);
            border: 2px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background: var(--light-color);
            border-color: var(--primary-color);
        }
        
        .preview-container {
            margin-top: 30px;
        }
        
        .preview-content {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            border: 2px dashed var(--border-color);
            text-align: center;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .preview-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .preview-text {
            max-height: 300px;
            overflow-y: auto;
            text-align: left;
            padding: 15px;
            background: var(--light-color);
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            word-break: break-word;
        }
        
        .no-preview {
            color: var(--gray-color);
            font-style: italic;
        }
        
        .share-footer {
            padding: 25px 40px;
            background: var(--light-color);
            border-top: 1px solid var(--border-color);
            text-align: center;
            font-size: 0.9rem;
            color: var(--gray-color);
        }
        
        .share-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }
        
        .share-footer a:hover {
            text-decoration: underline;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .badge-popular {
            background: #e8f5e9;
            color: var(--success-color);
        }
        
        @media (max-width: 768px) {
            .share-header {
                padding: 30px 20px;
            }
            
            .share-body {
                padding: 25px;
            }
            
            .file-info {
                flex-direction: column;
                text-align: center;
            }
            
            .file-icon-container {
                width: 100px;
                height: 100px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="share-container">
        <div class="share-header">
            <div class="header-content">
                <div class="logo">
                    <i class="fas fa-cloud"></i>
                    <span>OpenCloud</span>
                </div>
                <i class="fas fa-share-alt share-icon"></i>
                <h1>File Condiviso</h1>
                <p>Questo file è stato condiviso con te tramite OpenCloud</p>
            </div>
        </div>
        
        <div class="share-body">
            <div class="file-card">
                <div class="file-info">
                    <div class="file-icon-container" style="background: <?php echo $icon_color; ?>;">
                        <i class="fas <?php echo $file_icon; ?>"></i>
                    </div>
                    <div class="file-details">
                        <div class="file-name">
                            <?php echo htmlspecialchars($file['original_name']); ?>
                            <?php if ($file['view_count'] > 10): ?>
                                <span class="badge badge-popular">Popolare</span>
                            <?php endif; ?>
                        </div>
                        <div class="file-meta">
                            <span class="meta-item">
                                <i class="fas fa-hdd"></i> <?php echo $file_size; ?>
                            </span>
                            <span class="meta-item">
                                <i class="fas fa-file"></i> <?php echo $file_type; ?>
                            </span>
                            <span class="meta-item">
                                <i class="fas fa-download"></i> <?php echo $file['view_count']; ?> download
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Proprietario</div>
                        <div class="info-value"><?php echo htmlspecialchars($file['owner']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Caricato il</div>
                        <div class="info-value"><?php echo $created_at; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Tipo file</div>
                        <div class="info-value"><?php echo strtoupper($file_ext); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Scade il</div>
                        <div class="info-value"><?php echo $expiry_date; ?></div>
                    </div>
                </div>
            </div>
            
            <div class="actions-grid">
                <a href="<?php echo $download_url; ?>" class="btn btn-primary">
                    <i class="fas fa-download"></i>
                    Scarica File
                </a>
                
                <button onclick="copyToClipboard()" class="btn btn-secondary">
                    <i class="fas fa-copy"></i>
                    Copia Link
                </button>
                
                <?php if ($can_preview): ?>
                <button onclick="togglePreview()" class="btn btn-secondary" id="previewToggle">
                    <i class="fas fa-eye"></i>
                    Anteprima
                </button>
                <?php endif; ?>
            </div>
            
            <?php if ($can_preview && $preview_content): ?>
            <div class="preview-container" id="previewContainer" style="display: none;">
                <div class="preview-content">
                    <?php echo $preview_content; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="share-footer">
            <p>
                <i class="fas fa-shield-alt"></i> File condiviso in modo sicuro tramite 
                <a href="<?php echo BASE_URL; ?>">OpenCloud</a>
            </p>
            <p style="margin-top: 8px; font-size: 0.85rem;">
                Questa condivisione scadrà il <?php echo $expiry_date; ?>
            </p>
        </div>
    </div>

    <script>
        // Copia link alla clipboard
        function copyToClipboard() {
            const currentUrl = window.location.href;
            navigator.clipboard.writeText(currentUrl).then(() => {
                alert('Link copiato negli appunti!');
            }).catch(err => {
                console.error('Errore nella copia: ', err);
                alert('Impossibile copiare il link');
            });
        }
        
        // Mostra/nascondi anteprima
        function togglePreview() {
            const previewContainer = document.getElementById('previewContainer');
            const previewToggle = document.getElementById('previewToggle');
            
            if (previewContainer.style.display === 'none') {
                previewContainer.style.display = 'block';
                previewToggle.innerHTML = '<i class="fas fa-eye-slash"></i> Nascondi Anteprima';
                previewToggle.classList.remove('btn-secondary');
                previewToggle.classList.add('btn-primary');
            } else {
                previewContainer.style.display = 'none';
                previewToggle.innerHTML = '<i class="fas fa-eye"></i> Anteprima';
                previewToggle.classList.remove('btn-primary');
                previewToggle.classList.add('btn-secondary');
            }
        }
        
        // Auto-download opzionale dopo 10 secondi
        setTimeout(() => {
            const shouldAutoDownload = confirm('Vuoi scaricare automaticamente il file?');
            if (shouldAutoDownload) {
                window.location.href = '<?php echo $download_url; ?>';
            }
        }, 10000);
    </script>
</body>
</html>
    <?php
    exit;
}

/**
 * Forza il download del file
 */
function forceDownload($file) {
    // Determina il MIME type corretto
    $mime_type = $file['filetype'] ?: mime_content_type($file['filepath']);
    if (empty($mime_type)) {
        $mime_type = 'application/octet-stream';
    }

    // Forza il download con il nome originale
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . basename($file['original_name']) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . $file['filesize']);
    
    // Pulizia buffer output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    readfile($file['filepath']);
    exit;
}

/**
 * Mostra pagina di errore
 */
function showErrorPage($title, $message) {
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenCloud - Errore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0066cc;
            --danger-color: #f44336;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        .error-container {
            background: white;
            border-radius: 20px;
            padding: 50px 40px;
            text-align: center;
            max-width: 500px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }
        
        .error-icon {
            font-size: 5rem;
            color: var(--danger-color);
            margin-bottom: 25px;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 1.8rem;
        }
        
        p {
            color: #6c757d;
            margin-bottom: 30px;
            font-size: 1.1rem;
        }
        
        .btn {
            display: inline-block;
            padding: 14px 30px;
            background: linear-gradient(135deg, var(--primary-color) 0%, #0099ff 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 102, 204, 0.3);
        }
        
        .btn i {
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <h1><?php echo htmlspecialchars($title); ?></h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        <a href="<?php echo BASE_URL; ?>" class="btn">
            <i class="fas fa-home"></i> Torna alla Home
        </a>
    </div>
</body>
</html>
    <?php
    exit;
}

/**
 * Funzioni helper
 */
function getIconColor($ext) {
    $colors = [
        'txt' => '#4CAF50',
        'pdf' => '#F44336',
        'doc' => '#2196F3', 'docx' => '#2196F3',
        'xls' => '#4CAF50', 'xlsx' => '#4CAF50',
        'jpg' => '#FF9800', 'jpeg' => '#FF9800',
        'png' => '#2196F3',
        'gif' => '#E91E63',
        'zip' => '#9C27B0', 'rar' => '#9C27B0',
        'mp3' => '#FF5722', 'wav' => '#FF5722',
        'mp4' => '#E91E63', 'avi' => '#E91E63', 'mkv' => '#E91E63',
        'ppt' => '#FF9800', 'pptx' => '#FF9800'
    ];
    return $colors[strtolower($ext)] ?? '#607D8B';
}

function getFileTypeDescription($ext) {
    $types = [
        'txt' => 'Documento di testo',
        'pdf' => 'Documento PDF',
        'doc' => 'Documento Word', 'docx' => 'Documento Word',
        'xls' => 'Foglio Excel', 'xlsx' => 'Foglio Excel',
        'jpg' => 'Immagine JPEG', 'jpeg' => 'Immagine JPEG',
        'png' => 'Immagine PNG',
        'gif' => 'Immagine animata GIF',
        'zip' => 'Archivio compresso', 'rar' => 'Archivio compresso',
        'mp3' => 'File audio MP3', 'wav' => 'File audio WAV',
        'mp4' => 'Video MP4', 'avi' => 'Video AVI',
        'ppt' => 'Presentazione PowerPoint', 'pptx' => 'Presentazione PowerPoint'
    ];
    return $types[strtolower($ext)] ?? 'File ' . strtoupper($ext);
}

function canPreviewFile($ext) {
    $previewable = ['txt', 'pdf', 'jpg', 'jpeg', 'png', 'gif'];
    return in_array(strtolower($ext), $previewable);
}

function getFilePreview($file) {
    $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
    
    switch ($ext) {
        case 'txt':
            $content = file_get_contents($file['filepath']);
            $content = htmlspecialchars(substr($content, 0, 5000));
            return '<div class="preview-text">' . $content . '</div>';
            
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
            $file_url = htmlspecialchars($file['filepath']);
            return '<img src="data:' . $file['filetype'] . ';base64,' . base64_encode(file_get_contents($file['filepath'])) . '" class="preview-image" alt="Anteprima">';
            
        case 'pdf':
            return '<div class="no-preview">
                <i class="fas fa-file-pdf" style="font-size: 3rem; color: #F44336; margin-bottom: 15px;"></i>
                <p>Anteprima PDF non disponibile in questa versione.</p>
                <p>Scarica il file per visualizzarlo.</p>
            </div>';
            
        default:
            return '<div class="no-preview">
                <i class="fas fa-eye-slash" style="font-size: 3rem; color: #6c757d; margin-bottom: 15px;"></i>
                <p>Anteprima non disponibile per questo tipo di file.</p>
            </div>';
    }
}
?>
