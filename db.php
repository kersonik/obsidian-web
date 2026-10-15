<?php
// db.php - Database connection, migrations, and permission helpers

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // Ensure umask allows group write for SQLite WAL/SHM file creation
    umask(0002);

    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
        @chmod($dataDir, 02775);
    }

    // Protect data folder with .htaccess for Apache
    $htaccess = $dataDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    $dbFile = $dataDir . '/obsidian.sqlite';
    $isNew = !file_exists($dbFile);

    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);

    // WAL mode for improved concurrency & performance
    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // Maintain group writable permissions on database and WAL files
    if (file_exists($dbFile)) {
        @chmod($dbFile, 0664);
    }
    if (file_exists($dbFile . '-wal')) {
        @chmod($dbFile . '-wal', 0664);
    }
    if (file_exists($dbFile . '-shm')) {
        @chmod($dbFile . '-shm', 0664);
    }

    initDatabaseSchema($pdo);

    return $pdo;
}

function initDatabaseSchema(PDO $pdo): void {
    // 1. Users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            session_version INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // 2. Vault permissions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vault_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            vault_name TEXT NOT NULL,
            permission TEXT NOT NULL CHECK(permission IN ('read', 'write')),
            UNIQUE(user_id, vault_name),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    // 3. Ensure default admin user exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $userCount = (int)$stmt->fetchColumn();

    if ($userCount === 0) {
        // Default admin: username 'admin', password 'admin123'
        $defaultPassHash = password_hash('admin123', PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, is_admin, session_version)
            VALUES (:username, :hash, 1, 1)
        ");
        $insertStmt->execute([
            ':username' => 'admin',
            ':hash'     => $defaultPassHash
        ]);
    }
}

// Helper to detect hidden, system, or Syncthing sync files/directories
function isIgnoredSyncOrSystemItem(string $name): bool {
    if ($name === '' || $name === '.' || $name === '..') {
        return true;
    }
    // Any dot-prefixed item (.stfolder, .stversions, .obsidian, .trash, .git, .htaccess)
    if ($name[0] === '.') {
        return true;
    }
    // Syncthing temporary files or conflict copies
    if (stripos($name, '.syncthing.') !== false ||
        stripos($name, '~syncthing~') !== false ||
        (stripos($name, 'syncthing') !== false && stripos($name, '.tmp') !== false)) {
        return true;
    }
    return false;
}

// Vault directories helper
function getVaultsRootDir(): string {
    $dir = __DIR__ . '/vaults';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    // Protect vaults folder with .htaccess for Apache
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    // Auto-migrate legacy single vault if vaults/ has no subfolders
    $existingItems = @scandir($dir);
    $hasSubfolders = false;
    if ($existingItems !== false) {
        foreach ($existingItems as $item) {
            if (!isIgnoredSyncOrSystemItem($item) && is_dir($dir . '/' . $item)) {
                $hasSubfolders = true;
                break;
            }
        }
    }

    $legacyVault = __DIR__ . '/vault';
    if (!$hasSubfolders && is_dir($legacyVault)) {
        $defaultVault = $dir . '/default';
        if (!is_dir($defaultVault)) {
            @mkdir($defaultVault, 0775, true);
            copyRecursive($legacyVault, $defaultVault);
        }
    }

    // Ensure at least one vault exists ('default')
    $defaultVault = $dir . '/default';
    if (!is_dir($defaultVault)) {
        @mkdir($defaultVault, 0775, true);
    }

    return realpath($dir) ?: $dir;
}

function copyRecursive(string $src, string $dst): void {
    $dir = @opendir($src);
    if (!$dir) return;
    @mkdir($dst, 0775, true);
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') continue;
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;
        if (is_dir($srcPath)) {
            copyRecursive($srcPath, $dstPath);
        } else {
            @copy($srcPath, $dstPath);
        }
    }
    closedir($dir);
}

// List all vault folder names in vaults root
function getAllVaults(): array {
    $rootDir = getVaultsRootDir();
    $items = @scandir($rootDir);
    $vaults = [];
    if ($items !== false) {
        foreach ($items as $item) {
            if (isIgnoredSyncOrSystemItem($item)) continue;
            if (is_dir($rootDir . '/' . $item)) {
                $vaults[] = $item;
            }
        }
    }
    sort($vaults, SORT_NATURAL | SORT_FLAG_CASE);
    return $vaults;
}

// Resolve realpath for a specific vault by name
function getVaultPath(string $vaultName): ?string {
    $cleanName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $vaultName);
    if (empty($cleanName) || $cleanName === '.' || $cleanName === '..') {
        return null;
    }
    $rootDir = getVaultsRootDir();
    $vaultDir = $rootDir . '/' . $cleanName;
    if (!is_dir($vaultDir)) {
        return null;
    }
    $real = realpath($vaultDir);
    $realRoot = realpath($rootDir);
    if ($real && $realRoot && strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $realRoot)) === 0) {
        return $real;
    }
    return null;
}

// Check vault permissions for a user
function getUserVaultPermission(int $userId, bool $isAdmin, string $vaultName): ?string {
    if ($isAdmin) {
        return 'write';
    }

    $pdo = getDb();
    $stmt = $pdo->prepare("
        SELECT permission FROM vault_permissions
        WHERE user_id = :user_id AND vault_name = :vault_name
    ");
    $stmt->execute([
        ':user_id'    => $userId,
        ':vault_name' => $vaultName
    ]);
    $perm = $stmt->fetchColumn();
    return $perm ? (string)$perm : null;
}

// Get all accessible vaults for a user with their permission ('read' or 'write')
function getUserAccessibleVaults(int $userId, bool $isAdmin): array {
    $allVaults = getAllVaults();
    $accessible = [];

    if ($isAdmin) {
        foreach ($allVaults as $v) {
            $accessible[$v] = 'write';
        }
        return $accessible;
    }

    $pdo = getDb();
    $stmt = $pdo->prepare("
        SELECT vault_name, permission FROM vault_permissions
        WHERE user_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll();

    $permMap = [];
    foreach ($rows as $r) {
        $permMap[$r['vault_name']] = $r['permission'];
    }

    foreach ($allVaults as $v) {
        if (isset($permMap[$v]) && ($permMap[$v] === 'read' || $permMap[$v] === 'write')) {
            $accessible[$v] = $permMap[$v];
        }
    }

    return $accessible;
}
