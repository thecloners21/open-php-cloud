<!-- modals.php -->
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
                    <label style="display: block; margin-bottom: 8px; color: var(--text-color);">Cartella destinazione</label>
                    <select name="folder_id" id="uploadFolder" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-color); color: var(--text-color);">
                        <option value="0">Tutti i file (root)</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-color);">Seleziona file</label>
                    <div style="border: 2px dashed var(--border-color); border-radius: 8px; padding: 40px 20px; text-align: center; cursor: pointer;" 
                         onclick="document.getElementById('fileInput').click()"
                         id="dropZone">
                        <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: var(--primary-color); margin-bottom: 15px;"></i>
                        <p>Trascina i file qui o clicca per selezionarli</p>
                        <p style="font-size: 0.9rem; color: #888;">Supporta tutti i tipi di file (max 100MB ciascuno)</p>
                    </div>
                    <input type="file" name="file[]" id="fileInput" multiple style="display: none;" onchange="updateFileList()">
                </div>
                
                <div id="fileList" style="margin-bottom: 20px; max-height: 200px; overflow-y: auto;"></div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: var(--text-color);">Tags (opzionale)</label>
                    <input type="text" name="tags" placeholder="es: lavoro, vacanze, importante" 
                           style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-color); color: var(--text-color);">
                </div>
                
                <div class="progress-container" id="uploadProgress" style="display: none;">
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

<!-- MODAL ANTEPRIMA -->
<div class="modal preview-modal" id="previewModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Anteprima</h2>
            <button class="action-btn" id="closePreviewModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="previewModalContent">
            <!-- Contenuto dinamico -->
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

<script>
// Script per upload con drag & drop
let uploadedFiles = [];

document.getElementById('dropZone').addEventListener('dragover', (e) => {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.style.borderColor = 'var(--primary-color)';
    e.currentTarget.style.backgroundColor = 'var(--hover-bg)';
});

document.getElementById('dropZone').addEventListener('dragleave', (e) => {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.style.borderColor = 'var(--border-color)';
    e.currentTarget.style.backgroundColor = '';
});

document.getElementById('dropZone').addEventListener('drop', (e) => {
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.style.borderColor = 'var(--border-color)';
    e.currentTarget.style.backgroundColor = '';
    
    const files = e.dataTransfer.files;
    document.getElementById('fileInput').files = files;
    updateFileList();
});

function updateFileList() {
    const fileInput = document.getElementById('fileInput');
    const fileList = document.getElementById('fileList');
    const files = fileInput.files;
    
    fileList.innerHTML = '';
    
    if (files.length === 0) {
        fileList.innerHTML = '<p style="color: #888; text-align: center;">Nessun file selezionato</p>';
        return;
    }
    
    let totalSize = 0;
    const list = document.createElement('div');
    
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        totalSize += file.size;
        
        const item = document.createElement('div');
        item.style.display = 'flex';
        item.style.justifyContent = 'space-between';
        item.style.alignItems = 'center';
        item.style.padding = '10px';
        item.style.borderBottom = '1px solid var(--border-color)';
        
        const info = document.createElement('div');
        info.innerHTML = `
            <div style="font-weight: 500;">${file.name}</div>
            <div style="font-size: 0.8rem; color: #888;">${formatBytes(file.size)}</div>
        `;
        
        const removeBtn = document.createElement('button');
        removeBtn.innerHTML = '<i class="fas fa-times"></i>';
        removeBtn.className = 'action-btn';
        removeBtn.type = 'button';
        removeBtn.onclick = () => {
            const dt = new DataTransfer();
            for (let j = 0; j < files.length; j++) {
                if (j !== i) dt.items.add(files[j]);
            }
            fileInput.files = dt.files;
            updateFileList();
        };
        
        item.appendChild(info);
        item.appendChild(removeBtn);
        list.appendChild(item);
    }
    
    const total = document.createElement('div');
    total.style.padding = '10px';
    total.style.fontWeight = '600';
    total.style.borderTop = '2px solid var(--border-color)';
    total.textContent = `${files.length} file - ${formatBytes(totalSize)} totali`;
    list.appendChild(total);
    
    fileList.appendChild(list);
}

// Gestione upload con supporto multiplo
document.getElementById('uploadForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const fileInput = document.getElementById('fileInput');
    const files = fileInput.files;
    
    if (files.length === 0) {
        alert('Seleziona almeno un file');
        return;
    }

    const uploadProgress = document.getElementById('uploadProgress');
    const progressBar = document.getElementById('progressBar');
    const progressPercent = document.getElementById('progressPercent');
    const tagsInput = document.querySelector('input[name="tags"]');
    const folderSelect = document.getElementById('uploadFolder');
    
    uploadProgress.style.display = 'block';
    
    let successCount = 0;
    let errorCount = 0;
    
    // Carica i file uno alla volta
    for (let i = 0; i < files.length; i++) {
        const formData = new FormData();
        formData.append('file', files[i]);
        formData.append('tags', tagsInput.value);
        formData.append('folder_id', folderSelect.value);
        
        try {
            const xhr = new XMLHttpRequest();
            
            // Progress per file singolo
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                    const fileProgress = (e.loaded / e.total) * 100;
                    const totalProgress = ((i + (fileProgress / 100)) / files.length) * 100;
                    progressBar.style.width = totalProgress + '%';
                    progressPercent.textContent = Math.round(totalProgress) + '%';
                }
            };

            await new Promise((resolve, reject) => {
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                successCount++;
                            } else {
                                errorCount++;
                            }
                        } catch (e) {
                            errorCount++;
                        }
                        resolve();
                    } else {
                        errorCount++;
                        resolve();
                    }
                };
                
                xhr.onerror = function() {
                    errorCount++;
                    resolve();
                };

                xhr.open('POST', 'api/files.php?action=upload');
                xhr.send(formData);
            });

        } catch (error) {
            console.error('Errore:', error);
            errorCount++;
        }
    }
    
    // Mostra risultato
    if (successCount > 0) {
        alert(`${successCount} file caricati con successo${errorCount > 0 ? `, ${errorCount} errori` : ''}`);
        location.reload();
    } else {
        alert('Errore nel caricamento dei file');
        uploadProgress.style.display = 'none';
    }
});

// Gestione chiusura modali
document.getElementById('closeUploadModal')?.addEventListener('click', () => {
    document.getElementById('uploadModal').style.display = 'none';
});

document.getElementById('closePreviewModal')?.addEventListener('click', () => {
    document.getElementById('previewModal').style.display = 'none';
});

document.getElementById('closeShareModal')?.addEventListener('click', () => {
    document.getElementById('shareModal').style.display = 'none';
});

// Chiudi modali cliccando fuori
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
});

// Apri modal upload
document.getElementById('uploadBtn')?.addEventListener('click', () => {
    document.getElementById('uploadModal').style.display = 'flex';
});

// Funzione per formattare bytes
function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
</script>
