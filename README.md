# Open PhP Cloud

Clone open source di una piattaforma di cloud storage e gestione file, in stile OpenCloud / Nextcloud. Scritto in **PHP puro + SQLite**, senza dipendenze server complesse: si installa su un qualsiasi hosting PHP (anche shared come Altervista).

Parte della collezione [**The Cloners**](https://thecloners.altervista.org) — repliche open source di software professionali.

## Demo

![Open PhP Cloud — interfaccia](openCloud-php.png)

> 🔎 **Anteprima statica dell'interfaccia** (solo UI, senza backend): **https://thecloners21.github.io/open-php-cloud/**
>
> Demo completa funzionante (con backend PHP): in fase di pubblicazione su [thecloners.altervista.org](https://thecloners.altervista.org).

## Funzionalità

- 📁 Gestione file e cartelle (upload, spostamento, rinomina, eliminazione)
- 👥 Sistema utenti multi-account con ruoli
- 🔗 Condivisione file tramite link con token
- 🖼️ Generazione thumbnail per le immagini
- 🎨 Tema chiaro/scuro
- 👤 Profilo utente con avatar
- 🗃️ Database SQLite (nessun MySQL richiesto)

## Requisiti

- PHP 7.4+ con estensioni `pdo_sqlite` e `gd` (per le thumbnail)
- Un hosting con permessi di scrittura sulle cartelle dati

## Installazione

1. Carica i file sul server (o clona il repo).
2. Modifica `app_config.json` con i percorsi assoluti corretti per il tuo hosting:
   ```json
   {
     "db_path": "/percorso/assoluto/sb/database.db",
     "db_dir": "/percorso/assoluto",
     "upload_dir": "/percorso/assoluto/uploads/",
     "thumbnail_dir": "/percorso/assoluto/thumbnails/"
   }
   ```
3. Dai permessi di **scrittura** alle cartelle `sb/`, `uploads/`, `avatars/`, `thumbnails/`.
4. Apri il sito nel browser: al primo avvio il database viene creato automaticamente
   (oppure esegui `migrate_database.php`).
5. Accedi con l'utente admin di default e **cambia subito la password**.

## ⚠️ Sicurezza

- L'admin di default viene creato con la password `admin123`: **cambiala immediatamente** dopo il primo accesso.
- Non lasciare sul server pubblico i file di amministrazione del DB (es. `phpliteadmin`) né file di test: sono esclusi da questo pacchetto tramite `.gitignore`.
- La cartella `sb/` è protetta da un `.htaccess` che blocca l'accesso diretto al database.

## Struttura

```
index.php              # Applicazione principale
index.html             # Interfaccia file manager
login.php / logout.php # Autenticazione
config.php             # Configurazione e bootstrap DB
app_config.json        # Percorsi assoluti (da adattare all'hosting)
api/                   # Endpoint: files, folders, users, settings, system
sb/                    # Database SQLite (.htaccess protetto)
uploads/ avatars/ thumbnails/  # Dati runtime
```

## Licenza

Vedi il progetto The Cloners. Codice rilasciato come open source.
