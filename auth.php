<?php
// auth.php - Authentication, Session security, CSRF protection, and Audit Logging

require_once __DIR__ . '/db.php';

// Configure and start session if not started
if (session_status() === PHP_SESSION_NONE) {
    // Session cookie parameters for security
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Generate CSRF token if missing
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CSRF Validation helper for POST/PUT/DELETE requests
function verifyCsrfToken(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Get client IP address
function getClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    // Sanitize IP
    $filtered = filter_var($ip, FILTER_VALIDATE_IP);
    return $filtered ? $filtered : '127.0.0.1';
}

// Get currently authenticated user data from DB
// Validates that user still exists and their session version matches!
function getCurrentUser(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    try {
        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT id, username, is_admin, session_version FROM users WHERE id = :id");
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            // User was deleted! Invalidate session immediately
            logoutUser();
            return null;
        }

        // Check session version (for remote logout / session invalidation)
        $currentSessionVersion = (int)($_SESSION['session_version'] ?? 1);
        if ((int)$user['session_version'] !== $currentSessionVersion) {
            logoutUser();
            return null;
        }

        return [
            'id'       => (int)$user['id'],
            'username' => $user['username'],
            'is_admin' => (bool)$user['is_admin'],
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

// Logout and clear session
function logoutUser(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

// Require authenticated user (redirects or returns 401 JSON)
function requireAuth(bool $isApi = false): array {
    $user = getCurrentUser();
    if (!$user) {
        if ($isApi) {
            http_response_code(401);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["success" => false, "error" => "401 Unauthorized", "message" => "Uživatel není přihlášen."]);
            exit;
        }
        header("Location: login.php");
        exit;
    }
    return $user;
}

// Require admin user
function requireAdmin(bool $isApi = false): array {
    $user = requireAuth($isApi);
    if (!$user['is_admin']) {
        if ($isApi) {
            http_response_code(403);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["success" => false, "error" => "403 Forbidden", "message" => "Přístup odepřen: Vyžadováno oprávnění administrátora."]);
            exit;
        }
        http_response_code(403);
        die("403 Forbidden - Vyžadováno oprávnění administrátora.");
    }
    return $user;
}

// Audit Log into /logs/log-<vault>.txt
// Structured line:
// [YYYY-MM-DD HH:MM:SS] | USER: <username> | IP: <ip> | ACTION: <CREATE/UPDATE/DELETE/RENAME/DENIED> | FILE: <relativni-cesta-k-souboru>
function auditLog(string $vaultName, string $action, string $filePath, ?string $username = null, ?string $ip = null): void {
    $logsDir = __DIR__ . '/logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0775, true);
    }

    // Protect logs directory from direct web browser access with .htaccess
    $htaccess = $logsDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    $safeVault = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $vaultName) ?: 'unknown';
    $logFile = $logsDir . '/log-' . $safeVault . '.txt';

    $user = $username ?: ($_SESSION['username'] ?? 'anonymous');
    $clientIp = $ip ?: getClientIp();
    $timestamp = date('Y-m-d H:i:s');
    $cleanPath = str_replace(["\r", "\n"], '', $filePath);
    $cleanAction = strtoupper(trim($action));

    $line = "[{$timestamp}] | USER: {$user} | IP: {$clientIp} | ACTION: {$cleanAction} | FILE: {$cleanPath}\n";

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// Check path traversal security: verify path is strictly inside the vault's canonical base
function isPathInsideVault(string $path, string $realVaultBase): bool {
    $normVaultBase = str_replace('\\', '/', $realVaultBase);
    $normPath = str_replace('\\', '/', $path);
    if ($normPath === $normVaultBase) {
        return true;
    }
    return strpos($normPath, rtrim($normVaultBase, '/') . '/') === 0;
}
