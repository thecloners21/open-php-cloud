// users_system.js - Gestione utenti e impostazioni sistema - VERSIONE 2

// ============================================
// GESTIONE UTENTI
// ============================================

async function loadUsers() {
    try {
        const response = await fetch('api/users.php?action=list');
        const data = await response.json();
        
        if (data.success) {
            displayUsers(data.users);
        } else {
            alert(data.error || 'Errore nel caricamento degli utenti');
        }
    } catch (error) {
        console.error('Errore:', error);
        alert('Errore nel caricamento degli utenti');
    }
}

function displayUsers(users) {
    const grid = document.getElementById('usersGrid');
    if (!grid) return;
    
    grid.innerHTML = users.map(user => `
        <div class="user-card">
            <div class="user-card-header">
                <img src="${user.avatar || 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'60\' height=\'60\'%3E%3Crect fill=\'%23ddd\' width=\'60\' height=\'60\'/%3E%3Ctext fill=\'%23999\' font-family=\'Arial\' font-size=\'30\' x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\'%3E👤%3C/text%3E%3C/svg%3E'}" alt="${user.username}" class="user-avatar">
                <div class="user-info">
                    <h3>${user.full_name || user.username}</h3>
                    <p>${user.email}</p>
                </div>
            </div>
            <div>
                <span class="user-badge ${user.role}">${user.role === 'admin' ? 'Admin' : 'Utente'}</span>
                ${user.is_active == 0 ? '<span class="user-badge" style="background: #ffebee; color: #c62828;">Disattivato</span>' : ''}
            </div>
            <div style="font-size: 0.85rem; color: #888;">
                <div>Creato: ${new Date(user.created_at).toLocaleDateString('it-IT')}</div>
                ${user.last_login ? `<div>Ultimo accesso: ${new Date(user.last_login).toLocaleDateString('it-IT')}</div>` : ''}
            </div>
            <div class="user-actions">
                <button class="btn btn-sm btn-secondary" onclick="editUser(${user.id})">
                    <i class="fas fa-edit"></i> Modifica
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteUser(${user.id}, '${user.username.replace(/'/g, "\\'")}')">
                    <i class="fas fa-trash"></i> Elimina
                </button>
            </div>
        </div>
    `).join('');
}

function openCreateUserModal() {
    document.getElementById('createUserModal').style.display = 'flex';
}

function closeCreateUserModal() {
    document.getElementById('createUserModal').style.display = 'none';
    document.getElementById('createUserForm').reset();
}

async function editUser(userId) {
    try {
        const response = await fetch('api/users.php?action=list');
        const data = await response.json();
        
        if (data.success) {
            const user = data.users.find(u => u.id == userId);
            if (user) {
                document.getElementById('editUserId').value = user.id;
                document.getElementById('editUsername').value = user.username;
                document.getElementById('editEmail').value = user.email;
                document.getElementById('editFullName').value = user.full_name || '';
                document.getElementById('editRole').value = user.role;
                document.getElementById('editIsActive').value = user.is_active;
                document.getElementById('editUserModal').style.display = 'flex';
            }
        }
    } catch (error) {
        console.error('Errore:', error);
        alert('Errore nel caricamento dell\'utente');
    }
}

function closeEditUserModal() {
    document.getElementById('editUserModal').style.display = 'none';
}

async function deleteUser(userId, username) {
    if (!confirm(`Sei sicuro di voler eliminare l'utente "${username}"?\nQuesta azione eliminerà anche tutti i suoi file e cartelle.`)) {
        return;
    }
    
    try {
        const response = await fetch(`api/users.php?action=delete&id=${userId}`);
        const data = await response.json();
        
        if (data.success) {
            showNotification('Utente eliminato con successo', 'success');
            loadUsers();
        } else {
            alert(data.error || 'Errore nell\'eliminazione dell\'utente');
        }
    } catch (error) {
        console.error('Errore:', error);
        alert('Errore nell\'eliminazione dell\'utente');
    }
}

// ============================================
// GESTIONE PROFILO
// ============================================

async function loadProfile() {
    try {
        const response = await fetch('api/users.php?action=get_profile');
        const data = await response.json();
        
        if (data.success) {
            const user = data.user;
            document.getElementById('profileFullName').value = user.full_name || '';
            document.getElementById('profileEmail').value = user.email || '';
            document.getElementById('profileUsername').value = user.username || '';
            
            // Info header
            document.getElementById('profileDisplayName').textContent = user.full_name || user.username;
            document.getElementById('profileDisplayEmail').textContent = user.email;
            document.getElementById('profileRoleBadge').textContent = user.role === 'admin' ? 'Amministratore' : 'Utente';
            
            // Data iscrizione
            if (user.created_at) {
                const date = new Date(user.created_at);
                document.getElementById('profileMemberSince').textContent = 'Membro da ' + date.toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });
            }
            
            // Avatar
            if (user.avatar) {
                document.getElementById('profileAvatar').src = user.avatar + '?t=' + Date.now();
            }
            
            // Carica statistiche utente
            loadUserStats();
            
            // Carica attività recente
            loadRecentActivity();
            
            // Imposta tema corrente nel selettore
            if (window.themeManager) {
                const currentTheme = window.themeManager.getTheme();
                const selector = document.getElementById('profileThemeSelector');
                if (selector) {
                    selector.value = currentTheme;
                }
            }
        } else {
            alert(data.error || 'Errore nel caricamento del profilo');
        }
    } catch (error) {
        console.error('Errore:', error);
        alert('Errore nel caricamento del profilo');
    }
}

async function loadUserStats() {
    try {
        const response = await fetch('api/users.php?action=get_stats');
        const data = await response.json();
        
        if (data.success) {
            const stats = data.stats;
            document.getElementById('statTotalFiles').textContent = stats.total_files || 0;
            document.getElementById('statTotalFolders').textContent = stats.total_folders || 0;
            document.getElementById('statTotalSize').textContent = formatBytes(stats.total_size || 0);
            document.getElementById('statFavorites').textContent = stats.favorites || 0;
        }
    } catch (error) {
        console.error('Errore nel caricamento statistiche:', error);
    }
}

async function loadRecentActivity() {
    try {
        const response = await fetch('api/users.php?action=get_activity');
        const data = await response.json();
        
        if (data.success && data.activities) {
            const container = document.getElementById('recentActivity');
            
            if (data.activities.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #888; padding: 20px;">Nessuna attività recente</p>';
                return;
            }
            
            container.innerHTML = data.activities.map(activity => `
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas ${getActivityIcon(activity.type)}"></i>
                    </div>
                    <div class="activity-info">
                        <strong>${activity.description}</strong>
                        <small>${timeAgo(activity.created_at)}</small>
                    </div>
                </div>
            `).join('');
        }
    } catch (error) {
        console.error('Errore nel caricamento attività:', error);
    }
}

function getActivityIcon(type) {
    const icons = {
        'upload': 'fa-upload',
        'download': 'fa-download',
        'delete': 'fa-trash',
        'share': 'fa-share'
    };
    return icons[type] || 'fa-file';
}

function timeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'Pochi secondi fa';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' minuti fa';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' ore fa';
    if (seconds < 604800) return Math.floor(seconds / 86400) + ' giorni fa';
    
    return date.toLocaleDateString('it-IT');
}

// ============================================
// GESTIONE SISTEMA
// ============================================

async function loadSystemConfig() {
    try {
        const response = await fetch('api/system.php?action=get_config');
        const data = await response.json();
        
        if (data.success) {
            const config = data.config;
            document.getElementById('dbPath').value = config.db_path || '';
            document.getElementById('uploadDir').value = config.upload_dir || '';
            document.getElementById('thumbnailDir').value = config.thumbnail_dir || '';
        } else {
            console.error('Errore nel caricamento configurazione:', data.error);
        }
    } catch (error) {
        console.error('Errore:', error);
    }
}

async function loadSystemStats() {
    try {
        const response = await fetch('api/system.php?action=get_stats');
        const data = await response.json();
        
        if (data.success) {
            const stats = data.stats;
            
            // Aggiorna le statistiche
            document.getElementById('systemUsersCount').textContent = stats.users || 0;
            document.getElementById('systemFilesCount').textContent = stats.files || 0;
            document.getElementById('systemFoldersCount').textContent = stats.folders || 0;
            document.getElementById('systemSpaceUsed').textContent = formatBytes(stats.total_size || 0);
            document.getElementById('systemDeletedCount').textContent = stats.deleted_files || 0;
            document.getElementById('systemDbSize').textContent = formatBytes(stats.db_size || 0);
        } else {
            console.error('Errore nel caricamento statistiche:', data.error);
        }
    } catch (error) {
        console.error('Errore:', error);
    }
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// ============================================
// EVENT LISTENERS
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    // Form creazione utente
    const createUserForm = document.getElementById('createUserForm');
    if (createUserForm) {
        createUserForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(createUserForm);
            const data = Object.fromEntries(formData);
            
            try {
                const response = await fetch('api/users.php?action=create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Utente creato con successo', 'success');
                    closeCreateUserModal();
                    loadUsers();
                } else {
                    alert(result.error || 'Errore nella creazione dell\'utente');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nella creazione dell\'utente');
            }
        });
    }
    
    // Form modifica utente
    const editUserForm = document.getElementById('editUserForm');
    if (editUserForm) {
        editUserForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(editUserForm);
            const data = Object.fromEntries(formData);
            
            try {
                const response = await fetch('api/users.php?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Utente aggiornato con successo', 'success');
                    closeEditUserModal();
                    loadUsers();
                } else {
                    alert(result.error || 'Errore nell\'aggiornamento dell\'utente');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nell\'aggiornamento dell\'utente');
            }
        });
    }
    
    // Form profilo
    const profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const data = {
                full_name: document.getElementById('profileFullName').value,
                email: document.getElementById('profileEmail').value
            };
            
            try {
                const response = await fetch('api/users.php?action=update_profile', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Profilo aggiornato con successo', 'success');
                    // Ricarica profilo per aggiornare i dati visualizzati
                    loadProfile();
                } else {
                    alert(result.error || 'Errore nell\'aggiornamento del profilo');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nell\'aggiornamento del profilo');
            }
        });
    }
    
    // Form cambio password
    const passwordForm = document.getElementById('passwordForm');
    if (passwordForm) {
        passwordForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            if (newPassword !== confirmPassword) {
                alert('Le password non corrispondono');
                return;
            }
            
            if (newPassword.length < 8) {
                alert('La password deve essere di almeno 8 caratteri');
                return;
            }
            
            const data = {
                current_password: document.getElementById('currentPassword').value,
                new_password: newPassword
            };
            
            try {
                const response = await fetch('api/users.php?action=change_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Password cambiata con successo', 'success');
                    passwordForm.reset();
                    // Nascondi indicator forza password
                    document.getElementById('passwordStrength').style.display = 'none';
                } else {
                    alert(result.error || 'Errore nel cambio password');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nel cambio password');
            }
        });
    }
    
    // Upload avatar
    const avatarInput = document.getElementById('avatarInput');
    if (avatarInput) {
        avatarInput.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            // Verifica dimensione (max 2MB)
            if (file.size > 2 * 1024 * 1024) {
                alert('Il file è troppo grande. Dimensione massima: 2MB');
                return;
            }
            
            // Verifica tipo
            if (!file.type.startsWith('image/')) {
                alert('Il file deve essere un\'immagine');
                return;
            }
            
            const formData = new FormData();
            formData.append('avatar', file);
            
            try {
                const response = await fetch('api/users.php?action=upload_avatar', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Aggiorna avatar nella pagina profilo
                    document.getElementById('profileAvatar').src = result.avatar_url + '?t=' + Date.now();
                    
                    // Aggiorna anche l'avatar nell'header
                    const avatarImg = document.getElementById('userAvatarImg');
                    const avatarIcon = document.getElementById('userAvatarIcon');
                    if (avatarImg && avatarIcon) {
                        avatarImg.src = result.avatar_url + '?t=' + Date.now();
                        avatarImg.style.display = 'block';
                        avatarIcon.style.display = 'none';
                    }
                    
                    showNotification('Avatar aggiornato con successo', 'success');
                } else {
                    alert(result.error || 'Errore nell\'upload dell\'avatar');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nell\'upload dell\'avatar');
            }
        });
    }
    
    // Form configurazione database
    const dbConfigForm = document.getElementById('dbConfigForm');
    if (dbConfigForm) {
        dbConfigForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const data = {
                db_path: document.getElementById('dbPath').value,
                upload_dir: document.getElementById('uploadDir').value,
                thumbnail_dir: document.getElementById('thumbnailDir').value
            };
            
            // Validazione base
            if (!data.db_path || !data.upload_dir || !data.thumbnail_dir) {
                alert('Tutti i campi sono obbligatori');
                return;
            }
            
            try {
                const response = await fetch('api/system.php?action=update_config', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(result.message || 'Configurazione salvata con successo');
                } else {
                    alert(result.error || 'Errore nel salvataggio della configurazione');
                }
            } catch (error) {
                console.error('Errore:', error);
                alert('Errore nel salvataggio della configurazione');
            }
        });
    }
});

// Funzione per mostrare/nascondere password
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
