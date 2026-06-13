// theme.js - Gestione tema per utente (localStorage con username)

class ThemeManager {
    constructor() {
        this.defaultTheme = 'light';
        this.currentUsername = null;
        this.init();
    }
    
    init() {
        // Ottieni lo username dell'utente corrente dalla pagina
        this.currentUsername = this.getUsernameFromPage();
        
        if (this.currentUsername) {
            // Carica il tema salvato per questo utente
            const savedTheme = this.getTheme();
            this.applyTheme(savedTheme);
        } else {
            // Fallback al tema di default se non c'è username
            this.applyTheme(this.defaultTheme);
        }
        
        // Setup event listener per il cambio tema
        this.setupThemeSelector();
    }
    
    getUsernameFromPage() {
        // Cerca lo username nella pagina (es. nel footer o in un elemento nascosto)
        // Proviamo vari metodi per ottenere lo username
        
        // Metodo 1: Dal footer
        const footerText = document.querySelector('.footer')?.textContent || '';
        const match = footerText.match(/Utente:\s*(\w+)/);
        if (match) {
            return match[1];
        }
        
        // Metodo 2: Da un attributo data nel body
        const bodyUsername = document.body.getAttribute('data-username');
        if (bodyUsername) {
            return bodyUsername;
        }
        
        // Metodo 3: Da variabile JavaScript globale (se definita)
        if (window.currentUsername) {
            return window.currentUsername;
        }
        
        // Fallback: usa un valore generico
        console.warn('Username non trovato, uso localStorage generico');
        return 'default';
    }
    
    getStorageKey() {
        // Crea una chiave unica per questo utente
        return `opencloud_theme_${this.currentUsername}`;
    }
    
    getTheme() {
        // Leggi il tema salvato per questo utente specifico
        const storageKey = this.getStorageKey();
        return localStorage.getItem(storageKey) || this.defaultTheme;
    }
    
    setTheme(theme) {
        // Salva il tema per questo utente specifico
        const storageKey = this.getStorageKey();
        localStorage.setItem(storageKey, theme);
        this.applyTheme(theme);
        
        console.log(`Tema "${theme}" salvato per utente "${this.currentUsername}" in localStorage (${storageKey})`);
    }
    
    applyTheme(theme) {
        // Rimuovi tutte le classi tema
        document.body.classList.remove('light-theme', 'dark-theme', 'blue-theme', 'green-theme');
        
        // Aggiungi la classe del tema corrente
        document.body.classList.add(`${theme}-theme`);
        
        // Aggiorna tutti i selettori se esistono
        const selectors = document.querySelectorAll('#themeSelector, #profileThemeSelector');
        selectors.forEach(selector => {
            if (selector && selector.value !== theme) {
                selector.value = theme;
            }
        });
        
        // Aggiorna colori cartelle se la funzione esiste
        if (typeof updateFolderColors === 'function') {
            updateFolderColors();
        }
        
        console.log(`Tema "${theme}" applicato per utente "${this.currentUsername}"`);
    }
    
    setupThemeSelector() {
        // Setup per tutti i selettori di tema nella pagina
        const selectors = document.querySelectorAll('#themeSelector, #profileThemeSelector');
        
        selectors.forEach(selector => {
            if (selector) {
                // Imposta il valore corrente
                selector.value = this.getTheme();
                
                // Rimuovi event listener precedenti creando un clone
                const newSelector = selector.cloneNode(true);
                selector.parentNode.replaceChild(newSelector, selector);
                
                // Aggiungi nuovo event listener
                newSelector.addEventListener('change', (e) => {
                    this.setTheme(e.target.value);
                });
            }
        });
    }
    
    toggleTheme() {
        const currentTheme = this.getTheme();
        const themes = ['light', 'dark', 'blue', 'green'];
        const currentIndex = themes.indexOf(currentTheme);
        const nextIndex = (currentIndex + 1) % themes.length;
        this.setTheme(themes[nextIndex]);
    }
    
    // Funzione di debug per vedere tutti i temi salvati
    listAllThemes() {
        const themes = {};
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key.startsWith('opencloud_theme_')) {
                const username = key.replace('opencloud_theme_', '');
                const theme = localStorage.getItem(key);
                themes[username] = theme;
            }
        }
        console.table(themes);
        return themes;
    }
}

// Inizializza il theme manager quando il DOM è pronto
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.themeManager = new ThemeManager();
    });
} else {
    window.themeManager = new ThemeManager();
}

// Setup dei selettori quando il DOM è caricato completamente
window.addEventListener('load', () => {
    if (window.themeManager) {
        window.themeManager.setupThemeSelector();
    }
});
