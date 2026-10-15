# Obsidian Vault Web Viewer & Editor

> Moderní, lehké a bezpečné webové rozhraní pro prohlížení a editaci vašich [Obsidian](https://obsidian.md/) trezorů na desktopu i mobilu. Obsahuje plnou autentizaci se SQLite, granulární správu oprávnění, redesignovaný Admin Panel s interaktivní AJAX maticí, audit logování, detekci konfliktů a bleskové fulltextové vyhledávání s přímou podporou synchronizace přes Syncthing.

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B%20%2F%208.x-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Theme](https://img.shields.io/badge/Theme-Obsidian%20Dark-7F6DF2?style=for-the-badge&logo=obsidian&logoColor=white)](https://obsidian.md/)
[![Database](https://img.shields.io/badge/Database-SQLite3-003B57?style=for-the-badge&logo=sqlite&logoColor=white)](https://www.sqlite.org/)
[![License](https://img.shields.io/badge/License-MIT-blue?style=for-the-badge)](LICENSE)

---

## Funkce (Features)

- **Obsidian Dark Vzhled bez rušivých emoji**: Čistý, minimalistický dark motiv plně ladící s nativní aplikací Obsidian na mobilu i počítači. Veškeré ikony jsou čisté SVG vektory.
- **Kompletní autentizace & SQLite databáze**:
  - Ukládání uživatelů a oprávnění v SQLite databázi (`data/obsidian.sqlite`).
  - Hashování hesel standardním `password_hash()` (Bcrypt / Argon2id).
  - Ochrana proti brute-force útokům (5 pokusů -> dočasný ban IP na 30 minut).
  - CSRF ochrana u všech stavových požadavků.
- **Granulární správa oprávnění & Interaktivní matice**:
  - Podpora více nezávislých vaultů (`vaults/<nazev-vaultu>`).
  - Úrovně přístupu: **Žádný přístup** (uživatel vault nevidí) / **Pouze čtení (Read)** / **Čtení i zápis (Write)**.
  - Administrátoři mají automaticky plný přístup ke všem trezorům.
- **Redesignovaný Admin Panel s AJAX maticí**:
  - Jednotná interaktivní matice: Uživatelé v řádcích, vaulty ve sloupcích.
  - Okamžitá editace v matici – změna oprávnění se ukládá na pozadí přes AJAX bez reloadu stránky.
  - Přehledné rozdělení do záložek: **Matice oprávnění**, **Správa uživatelů**, **Správa vaultů**, **Audit logy**.
  - Vestavěný prohlížeč logů s fixní výškou, monospace fontem a filtry podle akcí (`CREATE`, `UPDATE`, `DELETE`, `RENAME`, `DENIED`).
- **Kompatibilita se Syncthing**:
  - Složka s vaulty (`vaults/`) plně podporuje souběžný běh se službou Syncthing.
  - Aplikace automaticky chrání a ignoruje synchronizační metadata (`.stfolder`, `.stversions`, `.syncthing*`, `.obsidian`, `.trash`).
  - Konzistentní práva souborů (`umask 0002`, `chmod 0664/0775`) umožňují bezkolizní souběžnou editaci uživateli `www-data` i `ubuntu` (Syncthing).
- **Detekce konfliktů (Conflict Detection)**:
  - Přenos časových značek `X-Last-Modified` a ETagu při načítání souborů.
  - Ochrana před nechtěným přepsáním změn jiného uživatele (HTTP 409 Conflict) s možností volby přepsání či znovunačtení.
- **Bleskové fulltextové vyhledávání**:
  - Okamžité prohledávání názvů i obsahu všech poznámek v otevřeném vaultu.
  - Automatické využití `ripgrep` (`rg`), pokud je nainstalován, s fallbackem na nativní PHP rekurzi.
- **Strom složek (File Explorer)**:
  - Chevron šipka s plynulou rotací a ukládáním stavu rozbalených složek v `localStorage`.

---

## Struktura projektu

```
/var/www/obsidian/
├── index.php             # Hlavní UI editoru, přepínač vaultů, postranní panel, náhled/úpravy
├── app.js                # Klientská logika, fulltext, správa stromu, detekce konfliktů, CSRF
├── file.php              # REST API pro soubory, kontrola oprávnění, vyhledávání, audit logování
├── login.php             # Přihlašovací obrazovka, rate limiting pokusů IP, správa relací
├── admin.php             # Administrátorský panel s interaktivní AJAX maticí a záložkami
├── db.php                # SQLite vrstva, automatická inicializace schématu, helpery práv
├── auth.php              # Bezpečnost session, CSRF validace, audit logování, path traversal ochrana
├── .htaccess             # Apache zabezpečení blokující přímý přístup k databázi, logům a vaultům
├── data/                 # Složka SQLite databáze (obsidian.sqlite) - blokována před přímým webem
├── logs/                 # Složka strukturovaných audit logů (log-<vault>.txt) - blokována před webem
└── vaults/               # Složka s jednotlivými vaulty (synchronizováno přes Syncthing)
    ├── default/          # Výchozí vault
    └── Skola/            # Vault synchronizovaný přes Syncthing
```

---

## Nasazení na produkční Linux server (Ubuntu / Debian)

### 1. Potřebné balíčky
```bash
sudo apt update
sudo apt install -y php8.1-fpm php8.1-sqlite3 php8.1-mbstring sqlite3 nginx ripgrep certbot python3-certbot-nginx
```

### 2. Oprávnění složek a integrace se Syncthing
Protože Syncthing běží pod uživatelem `ubuntu` a webový server pod `www-data`:
```bash
# Přidání uživatele ubuntu do skupiny www-data a naopak
sudo usermod -a -G www-data ubuntu
sudo usermod -a -G ubuntu www-data

# Vytvoření a nastavení práv pro složku webu
sudo mkdir -p /var/www/obsidian/vaults
sudo chown -R www-data:www-data /var/www/obsidian
sudo chown -R ubuntu:www-data /var/www/obsidian/vaults
sudo chmod -R 755 /var/www/obsidian
sudo chmod -R 2775 /var/www/obsidian/vaults /var/www/obsidian/data /var/www/obsidian/logs
```

*Poznámka: Nastavení bitu SGID (`chmod 2775`) zajistí, že veškeré nové soubory a složky vytvořené v trezorech automaticky dědí skupinu `www-data` s právy pro čtení i zápis jak pro web, tak pro Syncthing.*

### 3. Konfigurace Nginx (`/etc/nginx/sites-available/obsidian`)
```nginx
server {
    listen 80;
    server_name your-domain.com;

    root /var/www/obsidian;
    index index.php index.html;

    client_max_body_size 50M;

    # Striktní zákaz přímého přístupu k databázi, logům i trezorům
    location ~ ^/(data|logs|vaults|vault)/ {
        deny all;
        return 403;
    }

    location ~ \.(sqlite|db|json|log)$ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

Aktivace a načtení konfigurace:
```bash
sudo ln -sf /etc/nginx/sites-available/obsidian /etc/nginx/sites-enabled/obsidian
sudo nginx -t && sudo systemctl reload nginx
```

### 4. Nastavení SSL certifikátu (HTTPS)
```bash
sudo certbot --nginx -d your-domain.com --non-interactive --agree-tos --redirect
```

### 5. Výchozí přihlašovací údaje
- **Uživatelské jméno:** `admin`
- **Heslo:** `admin123`

Po prvním přihlášení otevřete v horní liště **Admin** (`admin.php`), přejděte na záložku **Správa uživatelů** a změňte si heslo administrátora.
