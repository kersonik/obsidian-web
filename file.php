<?php
// file.php - Secure File Controller, Fulltext Search, Conflict Detection, and Audit Logging

ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
umask(0002);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// 1. Authenticate user
$currentUser = getCurrentUser();
if (!$currentUser) {
    if (ob_get_level()) ob_clean();
    http_response_code(401);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["success" => false, "error" => "401 Unauthorized", "message" => "Uživatel není přihlášen."]);
    exit;
}

$userId = $currentUser['id'];
$isAdmin = $currentUser['is_admin'];
$username = $currentUser['username'];

// 2. Determine requested vault
$userVaults = getUserAccessibleVaults($userId, $isAdmin);
if (empty($userVaults)) {
    if (ob_get_level()) ob_clean();
    http_response_code(403);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["success" => false, "error" => "403 Forbidden", "message" => "Nemáte přiřazen žádný přístup k vaultům."]);
    exit;
}

$requestedVault = trim($_GET['vault'] ?? $_POST['vault'] ?? '');
if (empty($requestedVault) || !isset($userVaults[$requestedVault])) {
    // Default to the first accessible vault
    $requestedVault = array_key_first($userVaults);
}

$userPermission = $userVaults[$requestedVault] ?? null;
if (!$userPermission) {
    auditLog($requestedVault ?: 'unknown', 'DENIED', "Access denied to vault: {$requestedVault}", $username);
    if (ob_get_level()) ob_clean();
    http_response_code(403);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["success" => false, "error" => "403 Forbidden", "message" => "Nemáte oprávnění k vaultu '{$requestedVault}'."]);
    exit;
}

$realVaultBase = getVaultPath($requestedVault);
if (!$realVaultBase || !is_dir($realVaultBase)) {
    if (ob_get_level()) ob_clean();
    http_response_code(404);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["success" => false, "error" => "404 Not Found", "message" => "Složka vaultu nebyla na serveru nalezena."]);
    exit;
}

// Helper: Recursively search for missing file in vault
function findFileInVault($dir, $targetFilename) {
    if (!is_dir($dir)) return false;
    $items = @scandir($dir);
    if ($items === false) return false;

    foreach ($items as $item) {
        if (isIgnoredSyncOrSystemItem($item)) continue;
        $fullPath = $dir . '/' . $item;

        if (is_file($fullPath) && strcasecmp($item, $targetFilename) === 0) {
            return $fullPath;
        }
        if (is_dir($fullPath)) {
            $found = findFileInVault($fullPath, $targetFilename);
            if ($found) return $found;
        }
    }
    return false;
}

// Helper: Recursive delete file or folder safely (with strict Syncthing protection)
function deleteRecursive($dir) {
    if (isIgnoredSyncOrSystemItem(basename($dir))) {
        return false; // Protect .stfolder, .stversions, .obsidian, etc.
    }
    if (!is_dir($dir)) return @unlink($dir);
    $items = @scandir($dir);
    if ($items === false) return false;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (isIgnoredSyncOrSystemItem($item)) continue; // Never delete Syncthing sync markers
        if (!deleteRecursive($dir . "/" . $item)) return false;
    }
    return @rmdir($dir);
}

// 3. Action Dispatcher
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Check CSRF token on mutating requests
$mutatingActions = ['save', 'upload', 'create_file', 'create_folder', 'delete', 'rename', 'move'];
if (in_array($action, $mutatingActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken()) {
        auditLog($requestedVault, 'DENIED', "CSRF verification failed for action: {$action}", $username);
        if (ob_get_level()) ob_clean();
        http_response_code(403);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["success" => false, "error" => "403 Forbidden", "message" => "Neplatný bezpečnostní CSRF token. Obnovte prosím stránku."]);
        exit;
    }

    if ($userPermission !== 'write') {
        auditLog($requestedVault, 'DENIED', "Write permission denied for action: {$action}", $username);
        if (ob_get_level()) ob_clean();
        http_response_code(403);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["success" => false, "error" => "403 Forbidden", "message" => "Pro tento vault máte pouze oprávnění ke čtení."]);
        exit;
    }

    // Protect Syncthing and hidden metadata on all mutating actions
    $checkTarget = $_POST['f'] ?? $_POST['path'] ?? $_POST['sourcePath'] ?? $_POST['oldPath'] ?? $_POST['name'] ?? '';
    if (!empty($checkTarget)) {
        $basenameCheck = basename(str_replace(['\\', '../', '..\\'], ['/', '', ''], $checkTarget));
        if (isIgnoredSyncOrSystemItem($basenameCheck) || stripos($checkTarget, '/.st') !== false || stripos($checkTarget, '.syncthing') !== false) {
            auditLog($requestedVault, 'DENIED', "Blocked action on protected sync/system item: {$checkTarget}", $username);
            if (ob_get_level()) ob_clean();
            http_response_code(403);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["success" => false, "error" => "403 Forbidden", "message" => "Systémové soubory a metadata synchronizace Syncthing nelze upravovat."]);
            exit;
        }
    }
}

// --- API: Save note content with Conflict Detection (EDIT/WRITE ONLY) ---
if ($action === 'save') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    $filePath = $_POST['f'] ?? '';
    $content  = $_POST['content'] ?? '';
    $clientMtime = (int)($_POST['last_modified'] ?? 0);
    $forceSave   = !empty($_POST['force']);

    if (empty($filePath)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Chybějící název souboru."]);
        exit;
    }

    $filePath = str_replace(['\\', '../', '..\\'], ['/', '', ''], $filePath);
    $fullPath = $realVaultBase . '/' . ltrim($filePath, '/');

    if (!isPathInsideVault($fullPath, $realVaultBase)) {
        auditLog($requestedVault, 'DENIED', "Path traversal attempt: {$filePath}", $username);
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatná cesta k souboru."]);
        exit;
    }

    // Conflict detection: Check if file was modified after client fetched it
    if (file_exists($fullPath) && !$forceSave && $clientMtime > 0) {
        clearstatcache(true, $fullPath);
        $serverMtime = filemtime($fullPath);
        if ($serverMtime > $clientMtime) {
            http_response_code(409); // Conflict
            echo json_encode([
                "success"      => false,
                "conflict"     => true,
                "server_mtime" => $serverMtime,
                "client_mtime" => $clientMtime,
                "message"      => "Konflikt: Soubor byl na disku mezitím změněn jiným uživatelem. Obnovte soubor nebo potvrďte přepsání."
            ]);
            exit;
        }
    }

    $dir = dirname($fullPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (file_exists($fullPath) && !is_writable($fullPath)) {
        @chmod($fullPath, 0664);
    }

    $result = @file_put_contents($fullPath, $content);

    if ($result !== false) {
        @chmod($fullPath, 0664);
        clearstatcache(true, $fullPath);
        $newMtime = filemtime($fullPath);

        auditLog($requestedVault, 'UPDATE', $filePath, $username);

        echo json_encode([
            "success"       => true,
            "last_modified" => $newMtime,
            "message"       => "Soubor byl úspěšně uložen na server."
        ]);
    } else {
        $lastError = error_get_last();
        $errorMsg = $lastError ? $lastError['message'] : 'Chyba oprávnění zápisu.';
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Chyba při zápisu do souboru: " . $errorMsg]);
    }
    exit;
}

// --- API: Move file or folder (WRITE ONLY) ---
if ($action === 'move') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    $sourcePath   = trim($_POST['sourcePath'] ?? '');
    $targetFolder = trim($_POST['targetFolder'] ?? '');

    if (empty($sourcePath)) {
        echo json_encode(["success" => false, "message" => "Chybí zdrojový soubor."]);
        exit;
    }

    $sourcePath   = str_replace(['\\', '../', '..\\'], ['/', '', ''], $sourcePath);
    $targetFolder = str_replace(['\\', '../', '..\\'], ['/', '', ''], $targetFolder);

    $fullSourcePath = $realVaultBase . '/' . ltrim($sourcePath, '/');
    $realSourcePath = realpath($fullSourcePath);

    if ($realSourcePath === false || !isPathInsideVault($realSourcePath, $realVaultBase)) {
        auditLog($requestedVault, 'DENIED', "Invalid source path for move: {$sourcePath}", $username);
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Zdrojový soubor nebyl nalezen."]);
        exit;
    }

    $itemName = basename($realSourcePath);
    $fullTargetDir = empty($targetFolder) ? $realVaultBase : $realVaultBase . '/' . ltrim($targetFolder, '/');

    if (!is_dir($fullTargetDir)) {
        @mkdir($fullTargetDir, 0775, true);
    }

    $fullTargetPath = $fullTargetDir . '/' . $itemName;

    if (!isPathInsideVault($fullTargetPath, $realVaultBase)) {
        auditLog($requestedVault, 'DENIED', "Invalid target path for move: {$targetFolder}", $username);
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatná cílová složka."]);
        exit;
    }

    if ($realSourcePath === realpath($fullTargetPath)) {
        echo json_encode(["success" => true, "message" => "Soubor již leží v této složce."]);
        exit;
    }

    if (@rename($realSourcePath, $fullTargetPath)) {
        $newRel = ltrim(str_replace([$realVaultBase, '\\'], ['', '/'], $fullTargetPath), '/');
        auditLog($requestedVault, 'RENAME', "{$sourcePath} -> {$newRel}", $username);
        echo json_encode(["success" => true, "newPath" => $newRel, "message" => "Soubor byl úspěšně přesunut."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Nepodařilo se přesunout soubor na serveru."]);
    }
    exit;
}

// --- API: Upload file via native file explorer (WRITE ONLY) ---
if ($action === 'upload') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['file']['error'] ?? 'no_file';
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Chyba nahrávání (Kód: {$errCode})."]);
        exit;
    }

    $uploadedFile = $_FILES['file'];
    $originalName = basename($uploadedFile['name']);
    $originalName = preg_replace('/[^\w\-\.\ \(\)áeěiíóúůýčďňřšťžÁÉĚÍÓÚŮÝČĎŇŘŠŤŽ]/u', '_', $originalName);

    $targetSubDir = trim($_POST['targetDir'] ?? '');
    $targetSubDir = str_replace(['\\', '../', '..\\'], ['/', '', ''], $targetSubDir);

    $targetDir = empty($targetSubDir) ? $realVaultBase : $realVaultBase . '/' . ltrim($targetSubDir, '/');
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }

    $targetPath = $targetDir . '/' . $originalName;

    if (!isPathInsideVault($targetPath, $realVaultBase)) {
        auditLog($requestedVault, 'DENIED', "Upload path traversal attempt: {$targetPath}", $username);
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatná cesta pro uložení nahrávaného souboru."]);
        exit;
    }

    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath) || @copy($uploadedFile['tmp_name'], $targetPath)) {
        @chmod($targetPath, 0664);
        $relPath = ltrim(str_replace([$realVaultBase, '\\'], ['', '/'], $targetPath), '/');
        auditLog($requestedVault, 'CREATE', $relPath, $username);

        echo json_encode([
            "success"  => true,
            "filename" => $originalName,
            "message"  => "Soubor byl úspěšně nahrán na server."
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Nepodařilo se uložit nahrávaný soubor na disk. Zkontrolujte oprávnění."]);
    }
    exit;
}

// --- API: Create new file (WRITE ONLY) ---
if ($action === 'create_file') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    $fileName = trim($_POST['name'] ?? '');
    $parentPath = trim($_POST['parent'] ?? '');

    if (empty($fileName)) {
        echo json_encode(["success" => false, "message" => "Zadejte název souboru."]);
        exit;
    }

    if (!preg_match('/\.md$/i', $fileName)) {
        $fileName .= '.md';
    }

    $fileName = str_replace(['/', '\\', '..'], '', $fileName);
    $parentPath = str_replace(['\\', '../', '..\\'], ['/', '', ''], $parentPath);

    $targetDir = empty($parentPath) ? $realVaultBase : $realVaultBase . '/' . ltrim($parentPath, '/');
    $fullPath  = $targetDir . '/' . $fileName;

    if (!isPathInsideVault($fullPath, $realVaultBase)) {
        auditLog($requestedVault, 'DENIED', "Create file path traversal: {$fullPath}", $username);
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatná cesta."]);
        exit;
    }

    if (file_exists($fullPath)) {
        echo json_encode(["success" => false, "message" => "Soubor s tímto názvem již existuje."]);
        exit;
    }

    $titleWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
    $initialContent  = "# {$titleWithoutExt}\n\nNapište obsah své poznámky zde...\n";

    if (@file_put_contents($fullPath, $initialContent) !== false) {
        @chmod($fullPath, 0664);
        $relPath = ltrim(str_replace([$realVaultBase, '\\'], ['', '/'], $fullPath), '/');
        auditLog($requestedVault, 'CREATE', $relPath, $username);
        echo json_encode(["success" => true, "path" => $relPath, "message" => "Soubor vytvořen."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Nepodařilo se vytvořit soubor."]);
    }
    exit;
}

// --- API: Create new folder (WRITE ONLY) ---
if ($action === 'create_folder') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    $folderName = trim($_POST['name'] ?? '');
    $parentPath = trim($_POST['parent'] ?? '');

    if (empty($folderName)) {
        echo json_encode(["success" => false, "message" => "Zadejte název složky."]);
        exit;
    }

    $folderName = str_replace(['/', '\\', '..'], '', $folderName);
    $parentPath = str_replace(['\\', '../', '..\\'], ['/', '', ''], $parentPath);

    $targetDir = empty($parentPath) ? $realVaultBase : $realVaultBase . '/' . ltrim($parentPath, '/');
    $fullPath  = $targetDir . '/' . $folderName;

    if (!isPathInsideVault($fullPath, $realVaultBase)) {
        auditLog($requestedVault, 'DENIED', "Create folder path traversal: {$fullPath}", $username);
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatná cesta."]);
        exit;
    }

    if (is_dir($fullPath)) {
        echo json_encode(["success" => false, "message" => "Složka již existuje."]);
        exit;
    }

    if (@mkdir($fullPath, 0775, true)) {
        $relPath = ltrim(str_replace([$realVaultBase, '\\'], ['', '/'], $fullPath), '/');
        auditLog($requestedVault, 'CREATE', $relPath, $username);
        echo json_encode(["success" => true, "message" => "Složka vytvořena."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Nepodařilo se vytvořit složku."]);
    }
    exit;
}

// --- API: Delete file or folder (WRITE ONLY) ---
if ($action === 'delete') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    $targetRel = trim($_POST['path'] ?? '');
    $targetRel = str_replace(['\\', '../', '..\\'], ['/', '', ''], $targetRel);
    $fullPath  = $realVaultBase . '/' . ltrim($targetRel, '/');
    $realTarget = realpath($fullPath);

    if ($realTarget === false || !isPathInsideVault($realTarget, $realVaultBase)) {
        auditLog($requestedVault, 'DENIED', "Delete path traversal attempt: {$targetRel}", $username);
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Položka nebyla nalezena."]);
        exit;
    }

    if (deleteRecursive($realTarget)) {
        auditLog($requestedVault, 'DELETE', $targetRel, $username);
        echo json_encode(["success" => true, "message" => "Smazáno."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Chyba při mazání položky."]);
    }
    exit;
}

// --- API: Rename file or folder (WRITE ONLY) ---
if ($action === 'rename') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    $oldRel = trim($_POST['oldPath'] ?? '');
    $newName = trim($_POST['newName'] ?? '');

    if (empty($oldRel) || empty($newName)) {
        echo json_encode(["success" => false, "message" => "Chybějící parametr."]);
        exit;
    }

    $oldRel = str_replace(['\\', '../', '..\\'], ['/', '', ''], $oldRel);
    $fullOldPath = $realVaultBase . '/' . ltrim($oldRel, '/');
    $realOldPath = realpath($fullOldPath);

    if ($realOldPath === false || !isPathInsideVault($realOldPath, $realVaultBase)) {
        auditLog($requestedVault, 'DENIED', "Rename path traversal: {$oldRel}", $username);
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Původní soubor nebyl nalezen."]);
        exit;
    }

    $isDir = is_dir($realOldPath);
    $newName = str_replace(['/', '\\', '..'], '', $newName);

    if (!$isDir && !preg_match('/\.md$/i', $newName) && preg_match('/\.md$/i', $realOldPath)) {
        $newName .= '.md';
    }

    $parentDir = dirname($realOldPath);
    $fullNewPath = $parentDir . '/' . $newName;

    if (!isPathInsideVault($fullNewPath, $realVaultBase)) {
        auditLog($requestedVault, 'DENIED', "Rename invalid new path: {$fullNewPath}", $username);
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatný nový název."]);
        exit;
    }

    if (@rename($realOldPath, $fullNewPath)) {
        $newRel = ltrim(str_replace([$realVaultBase, '\\'], ['', '/'], $fullNewPath), '/');
        auditLog($requestedVault, 'RENAME', "{$oldRel} -> {$newRel}", $username);
        echo json_encode(["success" => true, "newPath" => $newRel, "message" => "Přejmenováno."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Chyba při přejmenování."]);
    }
    exit;
}

// --- API: Fulltext Search (READ/WRITE) ---
if ($action === 'search') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    $query = trim($_GET['q'] ?? $_POST['q'] ?? '');
    if (empty($query)) {
        echo json_encode(["success" => true, "query" => "", "results" => []]);
        exit;
    }

    $results = [];
    $maxResults = 40;

    // Check if ripgrep (rg) is installed and executable
    $hasRg = false;
    $rgPath = '';
    $checkCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where rg 2>nul' : 'which rg 2>/dev/null';
    $output = @shell_exec($checkCmd);
    if (!empty(trim((string)$output))) {
        $hasRg = true;
        $rgPath = trim(explode("\n", trim((string)$output))[0]);
    }

    if ($hasRg) {
        // Use ripgrep for ultra-fast search
        $escapedQuery = escapeshellarg($query);
        $escapedDir   = escapeshellarg($realVaultBase);
        $cmd = "{$rgPath} -i -n --no-heading --max-count 2 -g \"*.md\" --glob \"!.st*\" --glob \"!*syncthing*\" --glob \"!.obsidian*\" --glob \"!.trash*\" {$escapedQuery} {$escapedDir} 2>&1";
        $rgOutput = @shell_exec($cmd);

        if (!empty($rgOutput)) {
            $lines = explode("\n", trim($rgOutput));
            foreach ($lines as $line) {
                if (count($results) >= $maxResults) break;
                // Output format: /path/to/file.md:line:content
                $parts = explode(':', $line, 3);
                if (count($parts) >= 3) {
                    $matchedFile = $parts[0];
                    $lineNum     = (int)$parts[1];
                    $snippet     = trim($parts[2]);

                    $relPath = ltrim(str_replace([$realVaultBase, '\\'], ['', '/'], $matchedFile), '/');
                    $filename = basename($matchedFile);

                    // Truncate long snippets
                    if (mb_strlen($snippet) > 160) {
                        $snippet = mb_substr($snippet, 0, 160) . '...';
                    }

                    $results[] = [
                        'filename' => $filename,
                        'path'     => $relPath,
                        'line'     => $lineNum,
                        'snippet'  => $snippet
                    ];
                }
            }
        }
    }

    // Fallback: Native PHP recursive search (or search matching filenames)
    if (!$hasRg || count($results) < $maxResults) {
        $searchRecursive = function($dir, $baseDir) use (&$searchRecursive, &$results, $query, $maxResults) {
            if (!is_dir($dir)) return;
            $items = @scandir($dir);
            if ($items === false) return;

            foreach ($items as $item) {
                if (count($results) >= $maxResults) return;
                if (isIgnoredSyncOrSystemItem($item)) continue;

                $full = $dir . '/' . $item;
                if (is_dir($full)) {
                    $searchRecursive($full, $baseDir);
                } elseif (strtolower(pathinfo($item, PATHINFO_EXTENSION)) === 'md') {
                    $rel = ltrim(str_replace([$baseDir, '\\'], ['', '/'], $full), '/');
                    $title = pathinfo($item, PATHINFO_FILENAME);

                    // 1. Check title match
                    $hasTitleMatch = (stripos($title, $query) !== false);

                    // 2. Search lines in file
                    $content = @file_get_contents($full);
                    if ($content !== false) {
                        $lines = explode("\n", $content);
                        $foundInContent = false;

                        foreach ($lines as $idx => $lineStr) {
                            if (stripos($lineStr, $query) !== false) {
                                $cleanSnippet = trim($lineStr);
                                if (mb_strlen($cleanSnippet) > 160) {
                                    $cleanSnippet = mb_substr($cleanSnippet, 0, 160) . '...';
                                }
                                $results[] = [
                                    'filename' => $item,
                                    'path'     => $rel,
                                    'line'     => $idx + 1,
                                    'snippet'  => $cleanSnippet
                                ];
                                $foundInContent = true;
                                if (count($results) >= $maxResults) return;
                                break; // 1 match per note is enough for overview
                            }
                        }

                        if (!$foundInContent && $hasTitleMatch) {
                            $results[] = [
                                'filename' => $item,
                                'path'     => $rel,
                                'line'     => 1,
                                'snippet'  => 'Název poznámky odpovídá hledanému výrazu'
                            ];
                        }
                    }
                }
            }
        };

        // If rg didn't run, execute native search
        if (!$hasRg) {
            $searchRecursive($realVaultBase, $realVaultBase);
        }
    }

    echo json_encode([
        'success' => true,
        'query'   => $query,
        'engine'  => $hasRg ? 'ripgrep' : 'native_php',
        'count'   => count($results),
        'results' => $results
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- API: Get Graph Data (READ/WRITE) ---
if ($action === 'graph_data') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    $nodes = [];
    $links = [];
    $nodeMap = [];

    function scanVaultForGraph($dir, $realVaultBase, &$nodes, &$links, &$nodeMap) {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if ($items === false) return;

        foreach ($items as $item) {
            if (isIgnoredSyncOrSystemItem($item)) continue;
            $full = $dir . '/' . $item;
            if (is_dir($full)) {
                scanVaultForGraph($full, $realVaultBase, $nodes, $links, $nodeMap);
            } else if (strtolower(pathinfo($item, PATHINFO_EXTENSION)) === 'md') {
                $rel = ltrim(str_replace([$realVaultBase, '\\'], ['', '/'], $full), '/');
                $title = pathinfo($item, PATHINFO_FILENAME);
                $content = @file_get_contents($full) ?: '';

                // Extract tags
                preg_match_all('/(?:^|\s)#([a-zA-Z\x{0080}-\x{FFFF}][a-zA-Z0-9\x{0080}-\x{FFFF}_\/-]*)/u', $content, $tagMatches);
                $tags = array_values(array_unique($tagMatches[1] ?? []));

                // Extract wikilinks [[link]] or [[link|display]]
                preg_match_all('/\[\[([^\]|]+)(?:\|[^\]]+)?\]\]/', $content, $linkMatches);
                $rawLinks = array_values(array_unique($linkMatches[1] ?? []));

                $nodeId = $title;
                $nodeMap[strtolower($title)] = $nodeId;
                $nodeMap[strtolower($rel)] = $nodeId;

                $nodes[] = [
                    'id'       => $nodeId,
                    'path'     => $rel,
                    'title'    => $title,
                    'tags'     => $tags,
                    'rawLinks' => $rawLinks,
                    'size'     => count($rawLinks) + 1
                ];
            }
        }
    }

    scanVaultForGraph($realVaultBase, $realVaultBase, $nodes, $links, $nodeMap);

    // Build links
    foreach ($nodes as &$node) {
        $sourceId = $node['id'];
        foreach ($node['rawLinks'] as $target) {
            $targetClean = trim(str_replace('.md', '', $target));
            $targetLower = strtolower($targetClean);
            if (isset($nodeMap[$targetLower])) {
                $targetId = $nodeMap[$targetLower];
                if ($sourceId !== $targetId) {
                    $links[] = [
                        'source' => $sourceId,
                        'target' => $targetId
                    ];
                }
            } else {
                $unresolvedId = $targetClean;
                if (!isset($nodeMap[$targetLower])) {
                    $nodeMap[$targetLower] = $unresolvedId;
                    $nodes[] = [
                        'id'         => $unresolvedId,
                        'path'       => $targetClean . '.md',
                        'title'      => $unresolvedId,
                        'tags'       => [],
                        'rawLinks'   => [],
                        'size'       => 1,
                        'unresolved' => true
                    ];
                }
                $links[] = [
                    'source' => $sourceId,
                    'target' => $unresolvedId
                ];
            }
        }
        unset($node['rawLinks']);
    }

    echo json_encode([
        'success' => true,
        'nodes'   => $nodes,
        'links'   => $links
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// --- API: List files/folders JSON (READ/WRITE) ---
if ($action === 'list' || !isset($_GET['f'])) {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    function getVaultFiles($dir, $subPath = '') {
        $result = [];
        if (!is_dir($dir)) return [];

        $items = @scandir($dir);
        if ($items === false) return [];

        foreach ($items as $item) {
            if (isIgnoredSyncOrSystemItem($item)) continue;

            $fullPath = $dir . '/' . $item;
            $relPath = $subPath !== '' ? $subPath . '/' . $item : $item;

            if (is_dir($fullPath)) {
                $children = getVaultFiles($fullPath, $relPath);
                $result[] = [
                    'name'     => $item,
                    'type'     => 'folder',
                    'path'     => $relPath,
                    'children' => $children
                ];
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                $result[] = [
                    'name' => $item,
                    'type' => 'file',
                    'ext'  => $ext,
                    'path' => $relPath
                ];
            }
        }

        usort($result, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });

        return $result;
    }

    $fileTree = getVaultFiles($realVaultBase);

    // Format list of accessible vaults for the frontend vault selector
    $vaultOptions = [];
    foreach ($userVaults as $vName => $vPerm) {
        $vaultOptions[] = [
            'name'       => $vName,
            'permission' => $vPerm
        ];
    }

    echo json_encode([
        'success'          => true,
        'vault'            => $requestedVault,
        'permission'       => $userPermission, // 'read' or 'write'
        'availableVaults'  => $vaultOptions,
        'isAdmin'          => $isAdmin,
        'username'         => $username,
        'csrfToken'        => $_SESSION['csrf_token'],
        'vaultPath'        => str_replace('\\', '/', $realVaultBase),
        'vaultExists'      => is_dir($realVaultBase),
        'tree'             => $fileTree
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 4. Serve requested file content safely (READ/WRITE)
$requestedFile = $_GET['f'] ?? '';
$requestedFile = str_replace('\\', '/', $requestedFile);
$requestedFile = ltrim($requestedFile, '/');

if (strpos($requestedFile, '|') !== false) {
    $requestedFile = explode('|', $requestedFile)[0];
}

if (strpos($requestedFile, '../') !== false || strpos($requestedFile, '..\\') !== false) {
    auditLog($requestedVault, 'DENIED', "Path traversal attempt in get file: {$requestedFile}", $username);
    if (ob_get_level()) ob_clean();
    http_response_code(403);
    header("Content-Type: text/plain; charset=utf-8");
    echo "403 Forbidden - Pokus o Path Traversal.";
    exit;
}

$fullTargetPath = $realVaultBase . '/' . $requestedFile;
$realTargetPath = realpath($fullTargetPath);

if ($realTargetPath === false || !is_file($realTargetPath)) {
    $justFilename = pathinfo($requestedFile, PATHINFO_BASENAME);
    if (!empty($justFilename)) {
        $foundPath = findFileInVault($realVaultBase, $justFilename);
        if ($foundPath) {
            $realTargetPath = realpath($foundPath);
        }
    }
}

if ($realTargetPath === false || !is_file($realTargetPath) || !isPathInsideVault($realTargetPath, $realVaultBase)) {
    if (ob_get_level()) ob_clean();
    http_response_code(404);
    header("Content-Type: text/plain; charset=utf-8");
    echo "404 Not Found - Soubor nebyl nalezen (" . htmlspecialchars($requestedFile) . ").";
    exit;
}

$ext = strtolower(pathinfo($realTargetPath, PATHINFO_EXTENSION));

$mimeTypes = [
    'md'    => 'text/markdown; charset=utf-8',
    'txt'   => 'text/plain; charset=utf-8',
    'html'  => 'text/html; charset=utf-8',
    'css'   => 'text/css; charset=utf-8',
    'js'    => 'application/javascript; charset=utf-8',
    'json'  => 'application/json; charset=utf-8',

    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'png'   => 'image/png',
    'gif'   => 'image/gif',
    'webp'  => 'image/webp',
    'svg'   => 'image/svg+xml',
    'bmp'   => 'image/bmp',

    'm4a'   => 'audio/mp4',
    'mp3'   => 'audio/mpeg',
    'ogg'   => 'audio/ogg',
    'wav'   => 'audio/wav',
    'flac'  => 'audio/flac',
    'aac'   => 'audio/aac',

    'mp4'   => 'video/mp4',
    'webm'  => 'video/webm',
    'pdf'   => 'application/pdf'
];

$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

if (ob_get_level()) ob_clean();

// Send timestamp and ETag for conflict detection
header("X-Last-Modified: " . filemtime($realTargetPath));
header("ETag: \"" . md5_file($realTargetPath) . "\"");
header("Content-Type: " . $contentType);
header("Content-Length: " . filesize($realTargetPath));

readfile($realTargetPath);
exit;
