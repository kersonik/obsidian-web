<?php
// admin.php - Administrator Dashboard for Obsidian Web

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$currentUser = requireAdmin(false);
$pdo = getDb();

// 1. AJAX Endpoint for Inline Matrix Permission Changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_set_permission') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Neplatný CSRF token']);
        exit;
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    $vaultName = trim($_POST['vault_name'] ?? '');
    $permission = trim($_POST['permission'] ?? 'none');
    $cleanVault = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $vaultName);

    if ($userId <= 0 || empty($cleanVault)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Neplatné parametry požadavku']);
        exit;
    }

    try {
        if ($permission === 'none') {
            $stmt = $pdo->prepare("DELETE FROM vault_permissions WHERE user_id = :user_id AND vault_name = :vault");
            $stmt->execute([':user_id' => $userId, ':vault' => $cleanVault]);
        } elseif ($permission === 'read' || $permission === 'write') {
            $stmt = $pdo->prepare("
                INSERT INTO vault_permissions (user_id, vault_name, permission)
                VALUES (:user_id, :vault, :perm)
                ON CONFLICT(user_id, vault_name) DO UPDATE SET permission = excluded.permission
            ");
            $stmt->execute([':user_id' => $userId, ':vault' => $cleanVault, ':perm' => $permission]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Neznámá úroveň oprávnění']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Oprávnění bylo uloženo']);
        exit;
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Chyba databáze: ' . $e->getMessage()]);
        exit;
    }
}

// 2. AJAX Endpoint for Dynamically Creating Vaults
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_create_vault') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Neplatný CSRF token']);
        exit;
    }
    $vaultName = trim($_POST['vault_name'] ?? '');
    $cleanVault = preg_replace('/[^a-zA-Z0-9_\-]/', '', $vaultName);

    if (empty($cleanVault)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Název vaultu může obsahovat pouze písmena, čísla, podtržítka a pomlčky.']);
        exit;
    }

    $rootDir = getVaultsRootDir();
    $targetDir = $rootDir . '/' . $cleanVault;
    if (is_dir($targetDir)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Vault s názvem '{$cleanVault}' již existuje."]);
        exit;
    }

    if (@mkdir($targetDir, 0775, true)) {
        @chmod($targetDir, 0775);
        echo json_encode([
            'success' => true,
            'vault'   => $cleanVault,
            'path'    => $targetDir,
            'message' => "Nový vault '{$cleanVault}' byl úspěšně vytvořen."
        ]);
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Nepodařilo se vytvořit složku vaultu. Zkontrolujte oprávnění.']);
        exit;
    }
}

$message = '';
$messageType = 'success'; // 'success' or 'error'

$activeTab = trim($_POST['tab'] ?? ($_GET['tab'] ?? 'tab-user-perms'));
if (!in_array($activeTab, ['tab-user-perms', 'tab-vault-perms', 'tab-users', 'tab-vaults', 'tab-logs'])) {
    $activeTab = 'tab-user-perms';
}

// 3. Handle standard POST actions (fallbacks)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $message = "Chyba zabezpečení: Neplatný CSRF token.";
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // Create User
        if ($action === 'create_user') {
            $activeTab = 'tab-users';
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $isAdmin  = !empty($_POST['is_admin']) ? 1 : 0;

            if (empty($username) || empty($password)) {
                $message = "Zadejte uživatelské jméno i heslo.";
                $messageType = 'error';
            } elseif (strlen($password) < 4) {
                $message = "Heslo musí mít alespoň 4 znaky.";
                $messageType = 'error';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, password_hash, is_admin, session_version)
                        VALUES (:username, :hash, :is_admin, 1)
                    ");
                    $stmt->execute([
                        ':username' => $username,
                        ':hash'     => $hash,
                        ':is_admin' => $isAdmin
                    ]);
                    $message = "Uživatel '{$username}' byl úspěšně vytvořen.";
                    $messageType = 'success';
                } catch (\PDOException $e) {
                    if ($e->getCode() == 23000 || strpos($e->getMessage(), 'UNIQUE') !== false) {
                        $message = "Uživatel s tímto jménem již existuje.";
                    } else {
                        $message = "Chyba při vytváření uživatele: " . $e->getMessage();
                    }
                    $messageType = 'error';
                }
            }
        }

        // Change Password
        elseif ($action === 'change_password') {
            $activeTab = 'tab-users';
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = trim($_POST['new_password'] ?? '');

            if ($userId <= 0 || empty($newPassword)) {
                $message = "Vyplňte nové heslo.";
                $messageType = 'error';
            } elseif (strlen($newPassword) < 4) {
                $message = "Heslo musí mít alespoň 4 znaky.";
                $messageType = 'error';
            } else {
                try {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET password_hash = :hash, session_version = session_version + 1 
                        WHERE id = :id
                    ");
                    $stmt->execute([':hash' => $hash, ':id' => $userId]);
                    $message = "Heslo uživatele bylo úspěšně změněno (aktivní relace byly zneplatněny).";
                    $messageType = 'success';
                } catch (\PDOException $e) {
                    $message = "Chyba při změně hesla: " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }

        // Delete User
        elseif ($action === 'delete_user') {
            $activeTab = 'tab-users';
            $userId = (int)($_POST['user_id'] ?? 0);

            if ($userId === $currentUser['id']) {
                $message = "Nemůžete smazat svůj vlastní administrátorský účet.";
                $messageType = 'error';
            } elseif ($userId <= 0) {
                $message = "Neplatné ID uživatele.";
                $messageType = 'error';
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
                    $stmt->execute([':id' => $userId]);
                    $uName = $stmt->fetchColumn();

                    if (!$uName) {
                        $message = "Uživatel nebyl nalezen.";
                        $messageType = 'error';
                    } else {
                        $delStmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                        $delStmt->execute([':id' => $userId]);

                        $message = "Uživatel '{$uName}' byl smazán.";
                        $messageType = 'success';
                    }
                } catch (\PDOException $e) {
                    $message = "Chyba při mazání uživatele: " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }

        // Create New Vault Directory
        elseif ($action === 'create_vault') {
            $activeTab = 'tab-vaults';
            $vaultName = trim($_POST['vault_name'] ?? '');
            $cleanVault = preg_replace('/[^a-zA-Z0-9_\-]/', '', $vaultName);

            if (empty($cleanVault)) {
                $message = "Název vaultu může obsahovat pouze písmena, čísla, podtržítka a pomlčky.";
                $messageType = 'error';
            } else {
                $rootDir = getVaultsRootDir();
                $targetDir = $rootDir . '/' . $cleanVault;
                if (is_dir($targetDir)) {
                    $message = "Vault s názvem '{$cleanVault}' již existuje.";
                    $messageType = 'error';
                } else {
                    if (@mkdir($targetDir, 0775, true)) {
                        @chmod($targetDir, 0775);
                        $message = "Nový vault '{$cleanVault}' byl úspěšně vytvořen.";
                        $messageType = 'success';
                    } else {
                        $message = "Nepodařilo se vytvořit složku vaultu. Zkontrolujte oprávnění.";
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

// Fetch current users & vaults
$usersStmt = $pdo->query("SELECT id, username, is_admin, created_at FROM users ORDER BY is_admin DESC, username ASC");
$users = $usersStmt->fetchAll();

$allVaults = getAllVaults();
$rootDir = getVaultsRootDir();

// Count notes and check Syncthing status per vault
$vaultDetails = [];
$totalNotesCount = 0;
foreach ($allVaults as $v) {
    $vPath = $rootDir . '/' . $v;
    $hasSyncthing = is_dir($vPath . '/.stfolder');
    $noteCount = 0;
    if (is_dir($vPath)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vPath, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                if (!isIgnoredSyncOrSystemItem($file->getFilename())) {
                    $noteCount++;
                }
            }
        }
    }
    $totalNotesCount += $noteCount;
    $vaultDetails[$v] = [
        'path'         => $vPath,
        'notes'        => $noteCount,
        'hasSyncthing' => $hasSyncthing
    ];
}

// Fetch all permissions for the matrix
$permStmt = $pdo->query("SELECT user_id, vault_name, permission FROM vault_permissions");
$allPerms = $permStmt->fetchAll();
$permMatrix = [];
foreach ($allPerms as $p) {
    $permMatrix[$p['user_id']][$p['vault_name']] = $p['permission'];
}

// Selected audit log vault
$selectedLogVault = trim($_GET['log_vault'] ?? ($allVaults[0] ?? 'default'));
$safeLogVault = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $selectedLogVault);
$logFilePath = __DIR__ . '/logs/log-' . $safeLogVault . '.txt';
$logLines = [];
if (file_exists($logFilePath)) {
    $rawLog = @file_get_contents($logFilePath);
    if (!empty($rawLog)) {
        $lines = explode("\n", trim($rawLog));
        $logLines = array_reverse(array_filter($lines)); // newest first
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Obsidian Web</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg-main: #000000;
            --bg-sidebar: #0d0d0e;
            --bg-card: #141416;
            --bg-hover: #1c1c1f;
            --border-color: #1f1f24;
            --border-hr: #2a2a30;
            --text-primary: #dcddde;
            --text-secondary: #8e8e93;
            --text-heading: #ffffff;
            --accent-color: #7f6df2;
            --accent-hover: #6b59e6;
            --color-danger: #eb5757;
            --color-success: #50fa7b;
            --color-warning: #ffb86c;
            --color-info: #8be9fd;
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif;
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
        }

        /* Top Header */
        header.admin-header {
            background-color: var(--bg-sidebar);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-title-group h1 {
            font-size: 1.15rem;
            color: var(--text-heading);
            font-weight: 600;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-admin {
            background: rgba(127, 109, 242, 0.15);
            color: var(--accent-color);
            border: 1px solid rgba(127, 109, 242, 0.35);
            font-size: 0.72rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 4px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .header-nav {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-nav {
            background-color: #1a1a1e;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.84rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn-nav:hover {
            background-color: var(--bg-hover);
            color: #ffffff;
            border-color: #444;
        }

        .btn-nav.primary {
            background-color: var(--accent-color);
            color: #ffffff;
            border-color: var(--accent-color);
        }

        .btn-nav.primary:hover {
            background-color: var(--accent-hover);
        }

        /* Layout Container */
        .admin-container {
            max-width: 1240px;
            width: 100%;
            margin: 0 auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            flex: 1;
        }

        /* Alert notifications */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert.success {
            background-color: rgba(80, 250, 123, 0.1);
            border: 1px solid rgba(80, 250, 123, 0.3);
            color: #50fa7b;
        }

        .alert.error {
            background-color: rgba(235, 87, 87, 0.1);
            border: 1px solid rgba(235, 87, 87, 0.3);
            color: #eb5757;
        }

        /* Quick Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
        }

        .stat-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .stat-card .label {
            font-size: 0.78rem;
            color: var(--text-secondary);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .stat-card .value {
            font-size: 1.65rem;
            font-weight: 700;
            color: var(--text-heading);
        }

        /* Tabs Navigation */
        .tabs-header {
            display: flex;
            align-items: center;
            gap: 4px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0;
            margin-bottom: 4px;
        }

        .tab-btn {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--text-secondary);
            padding: 10px 18px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: -1px;
        }

        .tab-btn:hover {
            color: var(--text-heading);
        }

        .tab-btn.active {
            color: var(--accent-color);
            border-bottom-color: var(--accent-color);
        }

        .tab-pane {
            display: none;
            flex-direction: column;
            gap: 20px;
        }

        .tab-pane.active {
            display: flex;
        }

        /* Section Cards */
        .section-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }

        .section-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            background-color: #121214;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .section-header h2 {
            font-size: 0.95rem;
            color: var(--text-heading);
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        .section-body {
            padding: 20px;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: #0d0d0f;
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
            text-align: left;
            white-space: nowrap;
        }

        .admin-table th {
            background-color: #111114;
            color: #a1a1aa;
            font-weight: 500;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid #1a1a1e;
            white-space: nowrap;
            vertical-align: middle;
        }

        .admin-table th:last-child {
            border-right: none;
        }

        .admin-table td {
            padding: 5px 10px;
            border-bottom: 1px solid #18181c;
            border-right: 1px solid #141416;
            color: var(--text-primary);
            vertical-align: middle;
            height: 38px;
        }

        .admin-table td:last-child {
            border-right: none;
        }

        .admin-table tbody tr:last-child td {
            border-bottom: none;
        }

        .admin-table tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }

        /* Matrix Specifics */
        .vault-th {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .vault-name {
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 0.76rem;
            font-weight: 600;
            color: #f4f4f5;
            text-transform: none;
            letter-spacing: 0;
        }

        .user-th {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            white-space: nowrap;
        }

        .user-th-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-heading);
            letter-spacing: 0;
            text-transform: none;
        }

        .matrix-cell {
            min-width: 125px;
            max-width: 150px;
            padding: 4px 8px !important;
        }

        .perm-control {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .perm-select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-color: #121215;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 6px center;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: #d4d4d8;
            padding: 0 18px 0 8px;
            font-size: 0.76rem;
            font-family: inherit;
            font-weight: 500;
            height: 26px;
            line-height: 24px;
            width: 100%;
            cursor: pointer;
            outline: none;
            transition: border-color 0.15s, background-color 0.15s, box-shadow 0.2s;
        }

        .perm-select:hover {
            border-color: #3f3f46;
        }

        .perm-select:focus {
            border-color: var(--accent-color);
        }

        .perm-select.status-write {
            border-color: rgba(74, 222, 128, 0.35);
            color: #4ade80;
            background-color: rgba(74, 222, 128, 0.05);
        }

        .perm-select.status-read {
            border-color: rgba(56, 189, 248, 0.35);
            color: #38bdf8;
            background-color: rgba(56, 189, 248, 0.05);
        }

        .perm-select.status-none {
            border-color: #27272a;
            color: #71717a;
            background-color: #121215;
        }

        @keyframes permSaved {
            0% { box-shadow: 0 0 0 2px rgba(74, 222, 128, 0.6); }
            100% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); }
        }

        .perm-select.saved {
            animation: permSaved 0.6s ease-out;
        }

        .save-dot {
            position: absolute;
            right: 18px;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background-color: #4ade80;
            opacity: 0;
            transform: scale(0.4);
            pointer-events: none;
            transition: all 0.2s;
        }

        .save-dot.visible {
            opacity: 1;
            transform: scale(1);
        }

        .badge-admin-perm {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 26px;
            padding: 0 8px;
            border-radius: 4px;
            font-size: 0.74rem;
            font-weight: 500;
            color: #71717a;
            background-color: #141417;
            border: 1px solid #27272a;
            white-space: nowrap;
            width: 100%;
            letter-spacing: 0.01em;
        }

        /* Filter Pills & Panels for User/Vault Permission Categories */
        .filter-pills-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            padding: 12px 16px;
            background-color: #111114;
            border-bottom: 1px solid var(--border-color);
        }

        .filter-label {
            font-size: 0.76rem;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-right: 4px;
        }

        .entity-pill {
            background-color: #161619;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 0.8rem;
            font-weight: 500;
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s;
        }

        .entity-pill:hover {
            background-color: var(--bg-hover);
            border-color: #3f3f46;
            color: #ffffff;
        }

        .entity-pill.active {
            background-color: rgba(127, 109, 242, 0.15);
            border-color: var(--accent-color);
            color: #ffffff;
        }

        .user-perms-panel,
        .vault-perms-panel {
            display: none;
        }

        .user-perms-panel.active,
        .vault-perms-panel.active {
            display: block;
        }

        /* Checkbox toggle styling in User Permissions */
        .toggle-control {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            user-select: none;
            font-size: 0.78rem;
            color: var(--text-primary);
        }

        .toggle-control input[type="checkbox"] {
            width: 15px;
            height: 15px;
            accent-color: var(--accent-color);
            cursor: pointer;
        }

        .perm-status-badge {
            display: inline-flex;
            align-items: center;
            height: 22px;
            padding: 0 8px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .perm-status-badge.status-write {
            background: rgba(74, 222, 128, 0.12);
            color: #4ade80;
            border: 1px solid rgba(74, 222, 128, 0.3);
        }

        .perm-status-badge.status-read {
            background: rgba(56, 189, 248, 0.12);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        .perm-status-badge.status-none {
            background: #18181b;
            color: #71717a;
            border: 1px solid #27272a;
        }

        .admin-notice-box {
            background: rgba(127, 109, 242, 0.08);
            border: 1px solid rgba(127, 109, 242, 0.25);
            border-radius: 6px;
            padding: 12px 16px;
            font-size: 0.82rem;
            color: #d4d4d8;
            margin: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Forms */
        .form-row {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
            min-width: 180px;
        }

        .form-group label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        input[type="text"],
        input[type="password"],
        select {
            background-color: #0e0e11;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: #ffffff;
            padding: 4px 8px;
            font-size: 0.8125rem;
            height: 30px;
            outline: none;
            transition: border-color 0.15s;
        }

        input[type="text"]:focus,
        input[type="password"]:focus,
        select:focus {
            border-color: var(--accent-color);
        }

        .btn {
            height: 30px;
            padding: 0 12px;
            border-radius: 4px;
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: var(--accent-hover);
        }

        .btn-danger {
            background-color: rgba(235, 87, 87, 0.12);
            color: #eb5757;
            border: 1px solid rgba(235, 87, 87, 0.3);
        }
        .btn-danger:hover {
            background-color: rgba(235, 87, 87, 0.22);
        }

        .btn-sm {
            height: 24px;
            padding: 0 8px;
            font-size: 0.74rem;
        }

        /* Badges */
        .badge-role {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 22px;
            padding: 0 6px;
            border-radius: 3px;
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }

        .badge-role.admin {
            background: rgba(127, 109, 242, 0.12);
            color: #a894ff;
            border: 1px solid rgba(127, 109, 242, 0.3);
        }

        .badge-role.user {
            background: #161619;
            color: #71717a;
            border: 1px solid #27272a;
        }

        .badge-sync {
            display: inline-block;
            padding: 1px 4px;
            font-size: 0.62rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-radius: 3px;
            background: rgba(0, 191, 165, 0.12);
            color: #00bfa5;
            border: 1px solid rgba(0, 191, 165, 0.3);
            line-height: 1.2;
            vertical-align: middle;
        }

        /* Audit Log Terminal */
        .log-terminal {
            background-color: #070709;
            border: 1px solid #1a1a1e;
            border-radius: 6px;
            padding: 14px;
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 0.8rem;
            line-height: 1.6;
            height: 480px;
            overflow-y: auto;
            color: #abb2bf;
        }

        .log-entry {
            padding: 4px 0;
            border-bottom: 1px solid #121215;
            white-space: pre-wrap;
            word-break: break-all;
            display: flex;
            align-items: baseline;
            gap: 10px;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-badge {
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-block;
            min-width: 65px;
            text-align: center;
        }

        .log-action-create { background: rgba(80, 250, 123, 0.15); color: #50fa7b; border: 1px solid rgba(80, 250, 123, 0.3); }
        .log-action-update { background: rgba(139, 233, 253, 0.15); color: #8be9fd; border: 1px solid rgba(139, 233, 253, 0.3); }
        .log-action-delete { background: rgba(235, 87, 87, 0.15); color: #eb5757; border: 1px solid rgba(235, 87, 87, 0.3); }
        .log-action-rename { background: rgba(189, 147, 249, 0.15); color: #bd93f9; border: 1px solid rgba(189, 147, 249, 0.3); }
        .log-action-denied { background: rgba(255, 184, 108, 0.15); color: #ffb86c; border: 1px solid rgba(255, 184, 108, 0.3); }

        .log-empty {
            color: #55555e;
            text-align: center;
            padding: 32px 0;
            font-style: italic;
        }

        /* Log Filter Pills */
        .log-filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .filter-pill {
            background-color: #141418;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.76rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s;
        }

        .filter-pill:hover {
            color: #ffffff;
            border-color: #444;
        }

        .filter-pill.active {
            background-color: var(--accent-color);
            color: #ffffff;
            border-color: var(--accent-color);
        }

        /* Checkbox styling */
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.88rem;
            color: var(--text-primary);
            user-select: none;
        }

        .checkbox-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--accent-color);
            cursor: pointer;
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background-color: #161619;
            border: 1px solid var(--accent-color);
            color: #ffffff;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: 0 8px 24px rgba(0,0,0,0.8);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.25s cubic-bezier(0.1, 0.9, 0.2, 1.0);
            z-index: 1000;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body>
    <!-- Top Header -->
    <header class="admin-header">
        <div class="header-title-group">
            <svg width="22" height="22" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M38.5 8.5L78.5 28.5L91.5 68.5L48.5 91.5L8.5 63.5L18.5 28.5L38.5 8.5Z" fill="#7F6DF2" fill-opacity="0.2" stroke="#7F6DF2" stroke-width="6" stroke-linejoin="round"/>
                <path d="M38.5 8.5L48.5 91.5" stroke="#7F6DF2" stroke-width="4"/>
                <path d="M18.5 28.5L78.5 28.5" stroke="#7F6DF2" stroke-width="4"/>
                <path d="M8.5 63.5L91.5 68.5" stroke="#7F6DF2" stroke-width="4"/>
            </svg>
            <h1>Obsidian Web <span class="badge-admin">Admin</span></h1>
        </div>
        <div class="header-nav">
            <span style="font-size: 0.84rem; color: var(--text-secondary);">
                Uživatel: <strong style="color: var(--text-heading);"><?php echo htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </span>
            <a href="index.php" class="btn-nav primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Editor
            </a>
            <a href="login.php?logout=1" class="btn-nav">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Odhlásit
            </a>
        </div>
    </header>

    <div class="admin-container">
        <?php if (!empty($message)): ?>
            <div class="alert <?php echo $messageType; ?>">
                <span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>

        <!-- Stats Overview Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="label">Aktivní Vaulty</span>
                <span class="value" id="stat-active-vaults"><?php echo count($allVaults); ?></span>
            </div>
            <div class="stat-card">
                <span class="label">Uživatelé</span>
                <span class="value"><?php echo count($users); ?></span>
            </div>
            <div class="stat-card">
                <span class="label">Správci (Admin)</span>
                <span class="value">
                    <?php 
                    $adminCount = 0;
                    foreach ($users as $u) { if ($u['is_admin']) $adminCount++; }
                    echo $adminCount;
                    ?>
                </span>
            </div>
            <div class="stat-card">
                <span class="label">Celkem poznámek (.md)</span>
                <span class="value"><?php echo $totalNotesCount; ?></span>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <nav class="tabs-header">
            <button class="tab-btn <?php echo $activeTab === 'tab-user-perms' ? 'active' : ''; ?>" data-tab="tab-user-perms">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                Oprávnění uživatelů
            </button>
            <button class="tab-btn <?php echo $activeTab === 'tab-vault-perms' ? 'active' : ''; ?>" data-tab="tab-vault-perms">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                Oprávnění vaultů
            </button>
            <button class="tab-btn <?php echo $activeTab === 'tab-users' ? 'active' : ''; ?>" data-tab="tab-users">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                Správa uživatelů
            </button>
            <button class="tab-btn <?php echo $activeTab === 'tab-vaults' ? 'active' : ''; ?>" data-tab="tab-vaults">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                Správa vaultů
            </button>
            <button class="tab-btn <?php echo $activeTab === 'tab-logs' ? 'active' : ''; ?>" data-tab="tab-logs">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"></polyline><line x1="12" y1="19" x2="20" y2="19"></line></svg>
                Audit logy
            </button>
        </nav>

        <!-- TAB 1: User-Centric Permissions -->
        <section id="tab-user-perms" class="tab-pane <?php echo $activeTab === 'tab-user-perms' ? 'active' : ''; ?>">
            <div class="section-card">
                <div class="section-header">
                    <h2>Oprávnění podle uživatelů</h2>
                    <span style="font-size: 0.8rem; color: var(--text-secondary);">
                        Vyberte uživatele pro správu přístupů k jednotlivým vaultům.
                    </span>
                </div>

                <!-- User Selector Pills -->
                <div class="filter-pills-bar" id="user-pills-bar">
                    <span class="filter-label">Uživatel:</span>
                    <?php foreach ($users as $idx => $u): ?>
                        <button type="button" 
                                class="entity-pill user-picker-pill <?php echo $idx === 0 ? 'active' : ''; ?>" 
                                data-user-id="<?php echo $u['id']; ?>">
                            <span><?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ($u['is_admin']): ?>
                                <span class="badge-role admin">Správce</span>
                            <?php else: ?>
                                <span class="badge-role user">Uživatel</span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Panels for Each User -->
                <div class="section-body" style="padding: 0;">
                    <?php foreach ($users as $idx => $u): ?>
                        <div class="user-perms-panel <?php echo $idx === 0 ? 'active' : ''; ?>" id="user-panel-<?php echo $u['id']; ?>">
                            <?php if ($u['is_admin']): ?>
                                <div class="admin-notice-box">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7f6df2" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                    <span>Uživatel <strong><?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?></strong> má roli Správce a má automaticky plný přístup ke všem trezorům.</span>
                                </div>
                            <?php endif; ?>

                            <div class="table-responsive" style="border: none; border-radius: 0;">
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th>Název vaultu</th>
                                            <th style="width: 140px;">Stav přístupu</th>
                                            <th style="width: 130px; text-align: center;">Vidí (Číst)</th>
                                            <th style="width: 130px; text-align: center;">Píše (Zapisovat)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="user-tbody-<?php echo $u['id']; ?>">
                                        <?php foreach ($allVaults as $v): ?>
                                            <?php 
                                            $perm = $permMatrix[$u['id']][$v] ?? 'none';
                                            $canRead = ($u['is_admin'] || $perm === 'read' || $perm === 'write');
                                            $canWrite = ($u['is_admin'] || $perm === 'write');
                                            $badgeText = $u['is_admin'] ? 'Plný přístup' : ($perm === 'write' ? 'Číst i psát' : ($perm === 'read' ? 'Pouze číst' : 'Bez přístupu'));
                                            $badgeStatus = $u['is_admin'] ? 'write' : $perm;
                                            ?>
                                            <tr data-vault="<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>">
                                                <td>
                                                    <div class="vault-th">
                                                        <span class="vault-name"><?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <?php if (!empty($vaultDetails[$v]['hasSyncthing'])): ?>
                                                            <span class="badge-sync" title="Synchronizováno přes Syncthing">sync</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="perm-status-badge status-<?php echo $badgeStatus; ?>" id="badge-u-<?php echo $u['id']; ?>-<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php echo $badgeText; ?>
                                                    </span>
                                                </td>
                                                <td style="text-align: center;">
                                                    <?php if ($u['is_admin']): ?>
                                                        <span class="badge-role admin" style="height: 20px;">Ano</span>
                                                    <?php else: ?>
                                                        <label class="toggle-control">
                                                            <input type="checkbox" 
                                                                   class="perm-chk-read" 
                                                                   data-user-id="<?php echo $u['id']; ?>" 
                                                                   data-vault="<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>" 
                                                                   <?php echo $canRead ? 'checked' : ''; ?>>
                                                            <span class="chk-label">Vidí</span>
                                                        </label>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align: center;">
                                                    <?php if ($u['is_admin']): ?>
                                                        <span class="badge-role admin" style="height: 20px;">Ano</span>
                                                    <?php else: ?>
                                                        <label class="toggle-control">
                                                            <input type="checkbox" 
                                                                   class="perm-chk-write" 
                                                                   data-user-id="<?php echo $u['id']; ?>" 
                                                                   data-vault="<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>" 
                                                                   <?php echo $canWrite ? 'checked' : ''; ?>>
                                                            <span class="chk-label">Píše</span>
                                                        </label>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- TAB 2: Vault-Centric Permissions -->
        <section id="tab-vault-perms" class="tab-pane <?php echo $activeTab === 'tab-vault-perms' ? 'active' : ''; ?>">
            <div class="section-card">
                <div class="section-header">
                    <h2>Oprávnění podle vaultů</h2>
                    <span style="font-size: 0.8rem; color: var(--text-secondary);">
                        Vyberte vault pro správu oprávnění jednotlivých uživatelů.
                    </span>
                </div>

                <!-- Vault Selector Pills -->
                <div class="filter-pills-bar" id="vault-pills-bar">
                    <span class="filter-label">Vault:</span>
                    <?php foreach ($allVaults as $idx => $v): ?>
                        <button type="button" 
                                class="entity-pill vault-picker-pill <?php echo $idx === 0 ? 'active' : ''; ?>" 
                                data-vault="<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="vault-name"><?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if (!empty($vaultDetails[$v]['hasSyncthing'])): ?>
                                <span class="badge-sync">sync</span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Panels for Each Vault -->
                <div class="section-body" style="padding: 0;">
                    <?php foreach ($allVaults as $idx => $v): ?>
                        <div class="vault-perms-panel <?php echo $idx === 0 ? 'active' : ''; ?>" id="vault-panel-<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="table-responsive" style="border: none; border-radius: 0;">
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th>Uživatel</th>
                                            <th style="width: 100px;">Role</th>
                                            <th style="width: 220px;">Úroveň oprávnění</th>
                                        </tr>
                                    </thead>
                                    <tbody id="vault-tbody-<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php foreach ($users as $u): ?>
                                            <?php 
                                            $perm = $permMatrix[$u['id']][$v] ?? 'none';
                                            $statusClass = 'status-' . $perm;
                                            ?>
                                            <tr data-user-id="<?php echo $u['id']; ?>">
                                                <td>
                                                    <strong style="color: var(--text-heading); font-size: 0.82rem;"><?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                </td>
                                                <td>
                                                    <?php if ($u['is_admin']): ?>
                                                        <span class="badge-role admin">Správce</span>
                                                    <?php else: ?>
                                                        <span class="badge-role user">Uživatel</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="matrix-cell">
                                                    <?php if ($u['is_admin']): ?>
                                                        <span class="badge-admin-perm" title="Správce má automaticky plný přístup ke všem trezorům">Plný přístup</span>
                                                    <?php else: ?>
                                                        <div class="perm-control">
                                                            <select class="perm-select <?php echo $statusClass; ?>" 
                                                                    id="select-v-<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>-<?php echo $u['id']; ?>"
                                                                    data-user-id="<?php echo $u['id']; ?>" 
                                                                    data-vault="<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>">
                                                                <option value="none" <?php echo $perm === 'none' ? 'selected' : ''; ?>>Žádný přístup</option>
                                                                <option value="read" <?php echo $perm === 'read' ? 'selected' : ''; ?>>Pouze číst</option>
                                                                <option value="write" <?php echo $perm === 'write' ? 'selected' : ''; ?>>Číst a psát</option>
                                                            </select>
                                                            <span class="save-dot"></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- TAB 2: User Management -->
        <section id="tab-users" class="tab-pane <?php echo $activeTab === 'tab-users' ? 'active' : ''; ?>">
            <!-- Create User -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Vytvořit nový uživatelský účet</h2>
                </div>
                <div class="section-body">
                    <form action="admin.php" method="POST" class="form-row">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="create_user">

                        <div class="form-group">
                            <label for="new_username">Uživatelské jméno</label>
                            <input type="text" id="new_username" name="username" placeholder="např. jan_novak" required autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label for="new_password">Heslo</label>
                            <input type="password" id="new_password" name="password" placeholder="Heslo (min. 4 znaky)" required autocomplete="new-password">
                        </div>

                        <div class="form-group" style="min-width: 140px; justify-content: center;">
                            <label class="checkbox-label" style="margin-top: 20px;">
                                <input type="checkbox" name="is_admin" value="1">
                                <span>Správce (Admin)</span>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            Vytvořit uživatele
                        </button>
                    </form>
                </div>
            </div>

            <!-- Existing Users List -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Seznam uživatelů</h2>
                </div>
                <div class="section-body" style="padding: 0;">
                    <div class="table-responsive" style="border: none; border-radius: 0;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">ID</th>
                                    <th>Uživatelské jméno</th>
                                    <th style="width: 90px;">Role</th>
                                    <th>Vytvořen</th>
                                    <th>Změna hesla</th>
                                    <th style="width: 80px;">Akce</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td style="color: var(--text-secondary); font-family: monospace;">#<?php echo $u['id']; ?></td>
                                        <td><strong style="color: var(--text-heading); font-size: 0.82rem;"><?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td>
                                            <?php if ($u['is_admin']): ?>
                                                <span class="badge-role admin">Správce</span>
                                            <?php else: ?>
                                                <span class="badge-role user">Uživatel</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color: var(--text-secondary); font-size: 0.78rem;"><?php echo htmlspecialchars($u['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <form action="admin.php" method="POST" style="display: flex; gap: 6px; align-items: center;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="change_password">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <input type="password" name="new_password" placeholder="Nové heslo..." required style="height: 24px; padding: 2px 8px; font-size: 0.76rem; width: 130px;">
                                                <button type="submit" class="btn btn-primary btn-sm">Změnit</button>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if ($u['id'] !== $currentUser['id']): ?>
                                                <form action="admin.php" method="POST" onsubmit="return confirm('Opravdu chcete smazat uživatele <?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?>? Jeho session bude okamžitě zrušena.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Smazat</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="font-size: 0.75rem; color: var(--text-secondary);">(Váš účet)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB 3: Vault Management -->
        <section id="tab-vaults" class="tab-pane <?php echo $activeTab === 'tab-vaults' ? 'active' : ''; ?>">
            <!-- Create Vault -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Vytvořit nový trezor (Vault)</h2>
                </div>
                <div class="section-body">
                    <form id="create-vault-form" action="admin.php#tab-vaults" method="POST" class="form-row">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="create_vault">
                        <input type="hidden" name="tab" value="tab-vaults">

                        <div class="form-group" style="max-width: 320px;">
                            <label for="new_vault_name">Název složky vaultu</label>
                            <input type="text" id="new_vault_name" name="vault_name" placeholder="např. Osobni, Projekty" required autocomplete="off">
                        </div>

                        <button type="submit" id="btn-create-vault" class="btn btn-primary">
                            Vytvořit složku vaultu
                        </button>
                    </form>

                    <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 14px; line-height: 1.5;">
                        Adresář vaultů: <code>/var/www/obsidian/vaults/</code>. Pokud je ve složce nakonfigurována synchronizace přes Syncthing, systém automaticky respektuje a chrání synchronizační metadata (<code>.stfolder</code>, <code>.stversions</code>).
                    </p>
                </div>
            </div>

            <!-- Vaults Overview Table -->
            <div class="section-card">
                <div class="section-header">
                    <h2>Přehled vaultů na serveru</h2>
                </div>
                <div class="section-body" style="padding: 0;">
                    <div class="table-responsive" style="border: none; border-radius: 0;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Název vaultu</th>
                                    <th>Cesta na serveru</th>
                                    <th>Počet poznámek (.md)</th>
                                    <th>Syncthing</th>
                                    <th style="width: 140px;">Akce</th>
                                </tr>
                            </thead>
                            <tbody id="vaults-tbody">
                                <?php foreach ($allVaults as $v): ?>
                                    <tr>
                                        <td><strong style="color: var(--text-heading); font-family: monospace; font-size: 0.82rem;"><?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td><code style="color: var(--text-secondary); font-size: 0.78rem;"><?php echo htmlspecialchars($vaultDetails[$v]['path'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        <td><?php echo $vaultDetails[$v]['notes']; ?> poznámek</td>
                                        <td>
                                            <?php if (!empty($vaultDetails[$v]['hasSyncthing'])): ?>
                                                <span class="badge-sync">Aktivní (.stfolder)</span>
                                            <?php else: ?>
                                                <span class="badge-role user">Standardní složka</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="index.php?vault=<?php echo urlencode($v); ?>" class="btn btn-primary btn-sm" style="text-decoration: none;">
                                                Otevřít v editoru
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB 4: Audit Logs -->
        <section id="tab-logs" class="tab-pane <?php echo $activeTab === 'tab-logs' ? 'active' : ''; ?>">
            <div class="section-card">
                <div class="section-header">
                    <h2>Prohlížeč audit logů</h2>

                    <!-- Log selection form -->
                    <form action="admin.php" method="GET" style="display: flex; gap: 8px; align-items: center;">
                        <input type="hidden" name="tab" value="tab-logs">
                        <label for="log_vault_select" style="font-size: 0.82rem; color: var(--text-secondary);">Soubor logu:</label>
                        <select id="log_vault_select" name="log_vault" onchange="this.form.submit()" style="padding: 5px 10px; font-size: 0.82rem;">
                            <?php foreach ($allVaults as $v): ?>
                                <option value="<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedLogVault === $v ? 'selected' : ''; ?>>
                                    log-<?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>.txt
                                </option>
                            <?php endforeach; ?>
                            <option value="system" <?php echo $selectedLogVault === 'system' ? 'selected' : ''; ?>>
                                log-system.txt (Systém)
                            </option>
                        </select>
                    </form>
                </div>
                <div class="section-body">
                    <!-- Log Filter Bar -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 12px; flex-wrap: wrap;">
                        <div class="log-filter-group">
                            <span style="font-size: 0.78rem; color: var(--text-secondary); margin-right: 4px;">Filtr akcí:</span>
                            <button class="filter-pill active" data-filter="ALL">Všechny</button>
                            <button class="filter-pill" data-filter="CREATE">CREATE</button>
                            <button class="filter-pill" data-filter="UPDATE">UPDATE</button>
                            <button class="filter-pill" data-filter="DELETE">DELETE</button>
                            <button class="filter-pill" data-filter="RENAME">RENAME</button>
                            <button class="filter-pill" data-filter="DENIED">DENIED</button>
                        </div>
                        <input type="text" id="log-search-input" placeholder="Hledat v záznamech (uživatel, soubor, IP)..." style="width: 280px; padding: 5px 10px; font-size: 0.82rem;">
                    </div>

                    <div class="log-terminal" id="log-terminal">
                        <?php if (empty($logLines)): ?>
                            <div class="log-empty">Pro vybraný log nebyly dosud zaznamenány žádné události.</div>
                        <?php else: ?>
                            <?php foreach ($logLines as $line): 
                                $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

                                // Extract action type
                                $actType = 'OTHER';
                                if (preg_match('/ACTION:\s*(CREATE|UPDATE|DELETE|RENAME|DENIED)/i', $line, $m)) {
                                    $actType = strtoupper($m[1]);
                                }

                                // Colorize action tag
                                $colored = preg_replace_callback('/ACTION:\s*(CREATE|UPDATE|DELETE|RENAME|DENIED)/i', function($m) {
                                    $act = strtoupper($m[1]);
                                    $cls = 'log-action-' . strtolower($act);
                                    return "<span class=\"log-badge {$cls}\">{$act}</span>";
                                }, $escaped);
                            ?>
                                <div class="log-entry" data-action="<?php echo $actType; ?>">
                                    <?php echo $colored; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast">Oprávnění uloženo</div>

    <script>
        const CSRF_TOKEN = "<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>";

        // Tab Navigation
        const tabBtns = document.querySelectorAll('.tab-btn');
        const tabPanes = document.querySelectorAll('.tab-pane');

        function switchTab(tabId) {
            tabBtns.forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === tabId);
            });
            tabPanes.forEach(pane => {
                pane.classList.toggle('active', pane.id === tabId);
            });
            window.location.hash = tabId;
        }

        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => switchTab(btn.dataset.tab));
        });

        // Initialize active tab from URL query param or hash
        const urlParams = new URLSearchParams(window.location.search);
        const queryTab = urlParams.get('tab');
        const hashTab = window.location.hash.replace('#', '');
        if (queryTab && document.getElementById(queryTab)) {
            switchTab(queryTab);
        } else if (hashTab && document.getElementById(hashTab)) {
            switchTab(hashTab);
        }

        // Toast Helper
        const toast = document.getElementById('toast');
        let toastTimeout = null;
        function showToast(message, isError = false) {
            toast.textContent = message;
            toast.style.borderColor = isError ? '#eb5757' : '#7f6df2';
            toast.classList.add('show');
            if (toastTimeout) clearTimeout(toastTimeout);
            toastTimeout = setTimeout(() => {
                toast.classList.remove('show');
            }, 2500);
        }

        const USERS_LIST = <?php echo json_encode(array_map(function($u) {
            return ['id' => (int)$u['id'], 'username' => $u['username'], 'is_admin' => (int)$u['is_admin']];
        }, $users)); ?>;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Tab 1: User Picker Pills handling
        function initUserPills() {
            document.querySelectorAll('.user-picker-pill').forEach(pill => {
                pill.addEventListener('click', function() {
                    document.querySelectorAll('.user-picker-pill').forEach(p => p.classList.remove('active'));
                    this.classList.add('active');
                    const userId = this.dataset.userId;
                    document.querySelectorAll('.user-perms-panel').forEach(panel => {
                        panel.classList.toggle('active', panel.id === `user-panel-${userId}`);
                    });
                });
            });
        }
        initUserPills();

        // Tab 2: Vault Picker Pills handling
        function initVaultPills() {
            document.querySelectorAll('.vault-picker-pill').forEach(pill => {
                pill.addEventListener('click', function() {
                    document.querySelectorAll('.vault-picker-pill').forEach(p => p.classList.remove('active'));
                    this.classList.add('active');
                    const vault = this.dataset.vault;
                    document.querySelectorAll('.vault-perms-panel').forEach(panel => {
                        panel.classList.toggle('active', panel.id === `vault-panel-${vault}`);
                    });
                });
            });
        }
        initVaultPills();

        // Unified Permission Change Handler (Syncs Tab 1 and Tab 2 seamlessly)
        async function applyPermissionChange(userId, vault, newPerm, triggerElement) {
            const formData = new FormData();
            formData.append('action', 'ajax_set_permission');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('user_id', userId);
            formData.append('vault_name', vault);
            formData.append('permission', newPerm);

            try {
                const response = await fetch('admin.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await response.json();
                if (res.success) {
                    // Update Tab 1 (User Permissions)
                    const chkRead = document.querySelector(`.perm-chk-read[data-user-id="${userId}"][data-vault="${vault}"]`);
                    const chkWrite = document.querySelector(`.perm-chk-write[data-user-id="${userId}"][data-vault="${vault}"]`);
                    const badge = document.getElementById(`badge-u-${userId}-${vault}`);

                    if (chkRead) chkRead.checked = (newPerm === 'read' || newPerm === 'write');
                    if (chkWrite) chkWrite.checked = (newPerm === 'write');
                    if (badge) {
                        badge.className = `perm-status-badge status-${newPerm}`;
                        badge.textContent = (newPerm === 'write') ? 'Číst i psát' : ((newPerm === 'read') ? 'Pouze číst' : 'Bez přístupu');
                    }

                    // Update Tab 2 (Vault Permissions)
                    const select = document.getElementById(`select-v-${vault}-${userId}`);
                    if (select) {
                        select.value = newPerm;
                        select.className = `perm-select status-${newPerm}`;
                        select.classList.remove('saved');
                        void select.offsetWidth;
                        select.classList.add('saved');
                        const dot = select.closest('.perm-control')?.querySelector('.save-dot');
                        if (dot) {
                            dot.classList.add('visible');
                            setTimeout(() => dot.classList.remove('visible'), 1600);
                        }
                    }

                    showToast(`Oprávnění pro "${vault}" uloženo`);
                } else {
                    showToast(res.message || 'Chyba při ukládání', true);
                }
            } catch (err) {
                showToast('Chyba spojení se serverem', true);
            }
        }

        // Attach listeners to Checkboxes in Tab 1
        function bindCheckboxEvents(container = document) {
            container.querySelectorAll('.perm-chk-read').forEach(chk => {
                chk.addEventListener('change', function() {
                    const userId = this.dataset.userId;
                    const vault = this.dataset.vault;
                    const writeChk = document.querySelector(`.perm-chk-write[data-user-id="${userId}"][data-vault="${vault}"]`);
                    let newPerm = 'none';

                    if (this.checked) {
                        newPerm = (writeChk && writeChk.checked) ? 'write' : 'read';
                    } else {
                        if (writeChk) writeChk.checked = false;
                        newPerm = 'none';
                    }
                    applyPermissionChange(userId, vault, newPerm, this);
                });
            });

            container.querySelectorAll('.perm-chk-write').forEach(chk => {
                chk.addEventListener('change', function() {
                    const userId = this.dataset.userId;
                    const vault = this.dataset.vault;
                    const readChk = document.querySelector(`.perm-chk-read[data-user-id="${userId}"][data-vault="${vault}"]`);
                    let newPerm = 'none';

                    if (this.checked) {
                        if (readChk) readChk.checked = true;
                        newPerm = 'write';
                    } else {
                        newPerm = (readChk && readChk.checked) ? 'read' : 'none';
                    }
                    applyPermissionChange(userId, vault, newPerm, this);
                });
            });
        }
        bindCheckboxEvents();

        // Attach listeners to Selects in Tab 2
        function bindSelectEvents(container = document) {
            container.querySelectorAll('.perm-select').forEach(select => {
                select.addEventListener('change', function() {
                    const userId = this.dataset.userId;
                    const vault = this.dataset.vault;
                    applyPermissionChange(userId, vault, this.value, this);
                });
            });
        }
        bindSelectEvents();

        // Dynamic AJAX Vault Creation (Stays on Tab 4 "Správa vaultů" without reload)
        const createVaultForm = document.getElementById('create-vault-form');
        if (createVaultForm) {
            createVaultForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const input = document.getElementById('new_vault_name');
                const vaultName = (input ? input.value : '').trim();
                const submitBtn = document.getElementById('btn-create-vault');
                if (!vaultName) return;

                const formData = new FormData();
                formData.append('action', 'ajax_create_vault');
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('vault_name', vaultName);

                if (submitBtn) submitBtn.disabled = true;

                try {
                    const response = await fetch('admin.php', {
                        method: 'POST',
                        body: formData
                    });
                    const res = await response.json();
                    if (res.success) {
                        showToast(res.message);
                        if (input) input.value = '';

                        // 1. Add vault row to every user's panel in Tab 1
                        USERS_LIST.forEach(u => {
                            const tbody = document.getElementById(`user-tbody-${u.id}`);
                            if (tbody) {
                                const tr = document.createElement('tr');
                                tr.dataset.vault = res.vault;
                                tr.innerHTML = `
                                    <td>
                                        <div class="vault-th">
                                            <span class="vault-name">${escapeHtml(res.vault)}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="perm-status-badge status-${u.is_admin ? 'write' : 'none'}" id="badge-u-${u.id}-${escapeHtml(res.vault)}">
                                            ${u.is_admin ? 'Plný přístup' : 'Bez přístupu'}
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        ${u.is_admin ? '<span class="badge-role admin" style="height: 20px;">Ano</span>' : `
                                            <label class="toggle-control">
                                                <input type="checkbox" class="perm-chk-read" data-user-id="${u.id}" data-vault="${escapeHtml(res.vault)}">
                                                <span class="chk-label">Vidí</span>
                                            </label>
                                        `}
                                    </td>
                                    <td style="text-align: center;">
                                        ${u.is_admin ? '<span class="badge-role admin" style="height: 20px;">Ano</span>' : `
                                            <label class="toggle-control">
                                                <input type="checkbox" class="perm-chk-write" data-user-id="${u.id}" data-vault="${escapeHtml(res.vault)}">
                                                <span class="chk-label">Píše</span>
                                            </label>
                                        `}
                                    </td>
                                `;
                                tbody.appendChild(tr);
                                bindCheckboxEvents(tr);
                            }
                        });

                        // 2. Add vault pill and panel in Tab 2
                        const vaultPillsBar = document.getElementById('vault-pills-bar');
                        if (vaultPillsBar) {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'entity-pill vault-picker-pill';
                            btn.dataset.vault = res.vault;
                            btn.innerHTML = `<span class="vault-name">${escapeHtml(res.vault)}</span>`;
                            btn.addEventListener('click', function() {
                                document.querySelectorAll('.vault-picker-pill').forEach(p => p.classList.remove('active'));
                                this.classList.add('active');
                                document.querySelectorAll('.vault-perms-panel').forEach(panel => {
                                    panel.classList.toggle('active', panel.id === `vault-panel-${res.vault}`);
                                });
                            });
                            vaultPillsBar.appendChild(btn);

                            // Create new panel for this vault in Tab 2
                            const tab2Body = document.querySelector('#tab-vault-perms .section-body');
                            if (tab2Body) {
                                const panel = document.createElement('div');
                                panel.className = 'vault-perms-panel';
                                panel.id = `vault-panel-${res.vault}`;
                                let rowsHtml = '';
                                USERS_LIST.forEach(u => {
                                    rowsHtml += `
                                        <tr data-user-id="${u.id}">
                                            <td><strong style="color: var(--text-heading); font-size: 0.82rem;">${escapeHtml(u.username)}</strong></td>
                                            <td><span class="badge-role ${u.is_admin ? 'admin' : 'user'}">${u.is_admin ? 'Správce' : 'Uživatel'}</span></td>
                                            <td class="matrix-cell">
                                                ${u.is_admin ? '<span class="badge-admin-perm" title="Správce má automaticky plný přístup ke všem trezorům">Plný přístup</span>' : `
                                                    <div class="perm-control">
                                                        <select class="perm-select status-none" id="select-v-${escapeHtml(res.vault)}-${u.id}" data-user-id="${u.id}" data-vault="${escapeHtml(res.vault)}">
                                                            <option value="none" selected>Žádný přístup</option>
                                                            <option value="read">Pouze číst</option>
                                                            <option value="write">Číst a psát</option>
                                                        </select>
                                                        <span class="save-dot"></span>
                                                    </div>
                                                `}
                                            </td>
                                        </tr>
                                    `;
                                });
                                panel.innerHTML = `
                                    <div class="table-responsive" style="border: none; border-radius: 0;">
                                        <table class="admin-table">
                                            <thead>
                                                <tr>
                                                    <th>Uživatel</th>
                                                    <th style="width: 100px;">Role</th>
                                                    <th style="width: 220px;">Úroveň oprávnění</th>
                                                </tr>
                                            </thead>
                                            <tbody id="vault-tbody-${escapeHtml(res.vault)}">
                                                ${rowsHtml}
                                            </tbody>
                                        </table>
                                    </div>
                                `;
                                tab2Body.appendChild(panel);
                                bindSelectEvents(panel);
                            }
                        }

                        // 3. Append row to Vaults Overview Table (Tab 3)
                        const vaultsTbody = document.getElementById('vaults-tbody');
                        if (vaultsTbody) {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td><strong style="color: var(--text-heading); font-family: monospace; font-size: 0.82rem;">${escapeHtml(res.vault)}</strong></td>
                                <td><code style="color: var(--text-secondary); font-size: 0.78rem;">${escapeHtml(res.path)}</code></td>
                                <td>0 poznámek</td>
                                <td><span class="badge-role user">Standardní složka</span></td>
                                <td>
                                    <a href="index.php?vault=${encodeURIComponent(res.vault)}" class="btn btn-primary btn-sm" style="text-decoration: none;">
                                        Otevřít v editoru
                                    </a>
                                </td>
                            `;
                            vaultsTbody.appendChild(tr);
                        }

                        // 4. Add option to Log Vault Select (Tab 4)
                        const logVaultSelect = document.getElementById('log_vault_select');
                        if (logVaultSelect) {
                            const opt = document.createElement('option');
                            opt.value = res.vault;
                            opt.textContent = `log-${res.vault}.txt`;
                            logVaultSelect.insertBefore(opt, logVaultSelect.lastElementChild);
                        }

                        // 5. Update stats count
                        const statActiveVaults = document.getElementById('stat-active-vaults');
                        if (statActiveVaults) {
                            statActiveVaults.textContent = parseInt(statActiveVaults.textContent || '0', 10) + 1;
                        }
                    } else {
                        showToast(res.message || 'Chyba při vytváření vaultu', true);
                    }
                } catch (err) {
                    showToast('Chyba spojení se serverem', true);
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        }

        // Audit Log Filtering (Client-side)
        const filterPills = document.querySelectorAll('.filter-pill');
        const logSearchInput = document.getElementById('log-search-input');
        const logEntries = document.querySelectorAll('.log-entry');
        let activeFilter = 'ALL';

        function applyLogFilters() {
            const query = (logSearchInput ? logSearchInput.value : '').toLowerCase().trim();

            logEntries.forEach(entry => {
                const action = entry.dataset.action;
                const text = entry.textContent.toLowerCase();

                const matchAction = (activeFilter === 'ALL' || action === activeFilter);
                const matchQuery = (!query || text.includes(query));

                entry.style.display = (matchAction && matchQuery) ? 'flex' : 'none';
            });
        }

        filterPills.forEach(pill => {
            pill.addEventListener('click', function() {
                filterPills.forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                activeFilter = this.dataset.filter;
                applyLogFilters();
            });
        });

        if (logSearchInput) {
            logSearchInput.addEventListener('input', applyLogFilters);
        }
    </script>
</body>
</html>
