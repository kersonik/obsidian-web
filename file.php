<?php
// Prevent any PHP warning/error output from corrupting JSON responses
ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

session_start();

// 1. Security Check: verify active PHP session
if (empty($_SESSION['logged_in'])) {
    if (ob_get_level()) ob_clean();
    http_response_code(403);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["error" => "403 Forbidden", "message" => "Uživatel není přihlášen."]);
    exit;
}

$userMode = $_SESSION['mode'] ?? 'readonly';

// Determine vault directory location
$vaultBaseDir = __DIR__ . '/vault';

if (!is_dir($vaultBaseDir)) {
    if (isset($_SERVER['DOCUMENT_ROOT']) && is_dir($_SERVER['DOCUMENT_ROOT'] . '/vault')) {
        $vaultBaseDir = $_SERVER['DOCUMENT_ROOT'] . '/vault';
    } else {
        @mkdir($vaultBaseDir, 0777, true);
    }
}

$realVaultBase = realpath($vaultBaseDir) ?: $vaultBaseDir;

// Helper: Recursively search for missing file in vault
function findFileInVault($dir, $targetFilename) {
    if (!is_dir($dir)) return false;
    $items = @scandir($dir);
    if ($items === false) return false;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
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

// Helper: Recursive delete file or folder safely
function deleteRecursive($dir) {
    if (!is_dir($dir)) return @unlink($dir);
    $items = @scandir($dir);
    if ($items === false) return false;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (!deleteRecursive($dir . "/" . $item)) return false;
    }
    return @rmdir($dir);
}

// Helper: Verify path is inside vault
function isPathInsideVault($path, $realVaultBase) {
    $normVaultBase = str_replace('\\', '/', $realVaultBase);
    $normPath = str_replace('\\', '/', $path);
    return strpos($normPath, $normVaultBase) === 0;
}

// 2. Action Handlers
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// --- API: Save file content (EDIT MODE ONLY) ---
if ($action === 'save') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    if ($userMode !== 'edit') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Režim čtení. Úpravy nejsou povoleny."]);
        exit;
    }

    $filePath = $_POST['f'] ?? $_GET['f'] ?? '';
    $content  = $_POST['content'] ?? '';

    if (empty($filePath)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Chybějící název souboru."]);
        exit;
    }

    $filePath = str_replace(['\\', '../', '..\\'], ['/', '', ''], $filePath);
    $fullPath = $realVaultBase . '/' . ltrim($filePath, '/');

    if (!isPathInsideVault($fullPath, $realVaultBase)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatná cesta k souboru."]);
        exit;
    }

    $dir = dirname($fullPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    if (file_exists($fullPath) && !is_writable($fullPath)) {
        @chmod($fullPath, 0666);
    }

    $result = @file_put_contents($fullPath, $content);

    if ($result !== false) {
        echo json_encode(["success" => true, "message" => "Soubor byl úspěšně uložen na server."]);
    } else {
        $lastError = error_get_last();
        $errorMsg = $lastError ? $lastError['message'] : 'Chyba oprávnění zápisu.';
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Chyba při zápisu do souboru: " . $errorMsg]);
    }
    exit;
}

// --- API: Move file or folder between directories (EDIT MODE ONLY) ---
if ($action === 'move') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    if ($userMode !== 'edit') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Režim čtení. Přesouvání není povoleno."]);
        exit;
    }

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
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Zdrojový soubor nebyl nalezen."]);
        exit;
    }

    $itemName = basename($realSourcePath);
    $fullTargetDir = empty($targetFolder) ? $realVaultBase : $realVaultBase . '/' . ltrim($targetFolder, '/');

    if (!is_dir($fullTargetDir)) {
        @mkdir($fullTargetDir, 0777, true);
    }

    $fullTargetPath = $fullTargetDir . '/' . $itemName;

    if (!isPathInsideVault($fullTargetPath, $realVaultBase)) {
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
        echo json_encode(["success" => true, "newPath" => $newRel, "message" => "Soubor byl úspěšně přesunut."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Nepodařilo se přesunout soubor na serveru."]);
    }
    exit;
}

// --- API: Upload file via native file explorer (EDIT MODE ONLY) ---
if ($action === 'upload') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    if ($userMode !== 'edit') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Režim čtení. Nahrávání souborů není povoleno."]);
        exit;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['file']['error'] ?? 'no_file';
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Chyba nahrávání (Kód chyby: {$errCode})."]);
        exit;
    }

    $uploadedFile = $_FILES['file'];
    $originalName = basename($uploadedFile['name']);
    $originalName = preg_replace('/[^\w\-\.\ \(\)áeěiíóúůýčďňřšťžÁÉĚÍÓÚŮÝČĎŇŘŠŤŽ]/u', '_', $originalName);

    $targetSubDir = trim($_POST['targetDir'] ?? '');
    $targetSubDir = str_replace(['\\', '../', '..\\'], ['/', '', ''], $targetSubDir);

    $targetDir = empty($targetSubDir) ? $realVaultBase : $realVaultBase . '/' . ltrim($targetSubDir, '/');
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    $targetPath = $targetDir . '/' . $originalName;

    if (!isPathInsideVault($targetPath, $realVaultBase)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatná cesta pro uložení nahrávaného souboru."]);
        exit;
    }

    if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath) || @copy($uploadedFile['tmp_name'], $targetPath)) {
        @chmod($targetPath, 0666);
        echo json_encode([
            "success"  => true,
            "filename" => $originalName,
            "message"  => "Soubor byl úspěšně nahrán na server."
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Nepodařilo se uložit nahrávaný soubor na disk serveru. Zkontrolujte oprávnění složky vault."]);
    }
    exit;
}

// --- API: Create new file (EDIT MODE ONLY) ---
if ($action === 'create_file') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    if ($userMode !== 'edit') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Režim čtení. Vytváření souborů není povoleno."]);
        exit;
    }

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
        @chmod($fullPath, 0666);
        $relPath = ltrim(str_replace([$realVaultBase, '\\'], ['', '/'], $fullPath), '/');
        echo json_encode(["success" => true, "path" => $relPath, "message" => "Soubor vytvořen."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Nepodařilo se vytvořit soubor."]);
    }
    exit;
}

// --- API: Create new folder (EDIT MODE ONLY) ---
if ($action === 'create_folder') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    if ($userMode !== 'edit') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Režim čtení. Vytváření složek není povoleno."]);
        exit;
    }

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
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatná cesta."]);
        exit;
    }

    if (is_dir($fullPath)) {
        echo json_encode(["success" => false, "message" => "Složka již existuje."]);
        exit;
    }

    if (@mkdir($fullPath, 0777, true)) {
        echo json_encode(["success" => true, "message" => "Složka vytvořena."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Nepodařilo se vytvořit složku."]);
    }
    exit;
}

// --- API: Delete file or folder (EDIT MODE ONLY) ---
if ($action === 'delete') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    if ($userMode !== 'edit') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Režim čtení. Smazání není povoleno."]);
        exit;
    }

    $targetRel = trim($_POST['path'] ?? '');
    $targetRel = str_replace(['\\', '../', '..\\'], ['/', '', ''], $targetRel);
    $fullPath  = $realVaultBase . '/' . ltrim($targetRel, '/');
    $realTarget = realpath($fullPath);

    if ($realTarget === false || !isPathInsideVault($realTarget, $realVaultBase)) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Položka nebyla nalezena."]);
        exit;
    }

    if (deleteRecursive($realTarget)) {
        echo json_encode(["success" => true, "message" => "Smazáno."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Chyba při mazání."]);
    }
    exit;
}

// --- API: Rename file or folder (EDIT MODE ONLY) ---
if ($action === 'rename') {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    if ($userMode !== 'edit') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Režim čtení. Přejmenování není povoleno."]);
        exit;
    }

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
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Neplatný nový název."]);
        exit;
    }

    if (@rename($realOldPath, $fullNewPath)) {
        $newRel = ltrim(str_replace([$realVaultBase, '\\'], ['', '/'], $fullNewPath), '/');
        echo json_encode(["success" => true, "newPath" => $newRel, "message" => "Přejmenováno."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Chyba při přejmenování."]);
    }
    exit;
// --- API: Get Graph Data for Obsidian Graph View ---
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
            if ($item === '.' || $item === '..' || $item[0] === '.') continue;
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
                    'id' => $nodeId,
                    'path' => $rel,
                    'title' => $title,
                    'tags' => $tags,
                    'rawLinks' => $rawLinks,
                    'size' => count($rawLinks) + 1
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
                // Link to nonexistent note (unresolved)
                $unresolvedId = $targetClean;
                if (!isset($nodeMap[$targetLower])) {
                    $nodeMap[$targetLower] = $unresolvedId;
                    $nodes[] = [
                        'id' => $unresolvedId,
                        'path' => $targetClean . '.md',
                        'title' => $unresolvedId,
                        'tags' => [],
                        'rawLinks' => [],
                        'size' => 1,
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
        'nodes' => $nodes,
        'links' => $links
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 3. Endpoint: List files/folders JSON
if ($action === 'list' || !isset($_GET['f'])) {
    if (ob_get_level()) ob_clean();
    header("Content-Type: application/json; charset=utf-8");

    function getVaultFiles($dir, $subPath = '') {
        $result = [];
        if (!is_dir($dir)) return [];

        $items = @scandir($dir);
        if ($items === false) return [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item[0] === '.') continue;

            $fullPath = $dir . '/' . $item;
            $relPath = $subPath !== '' ? $subPath . '/' . $item : $item;

            if (is_dir($fullPath)) {
                $children = getVaultFiles($fullPath, $relPath);
                $result[] = [
                    'name' => $item,
                    'type' => 'folder',
                    'path' => $relPath,
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

    echo json_encode([
        'success'     => true,
        'mode'        => $userMode,
        'vaultPath'   => str_replace('\\', '/', $realVaultBase),
        'vaultExists' => is_dir($realVaultBase),
        'tree'        => $fileTree
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 4. Serve requested file content safely
$requestedFile = $_GET['f'] ?? '';
$requestedFile = str_replace('\\', '/', $requestedFile);
$requestedFile = ltrim($requestedFile, '/');

if (strpos($requestedFile, '|') !== false) {
    $requestedFile = explode('|', $requestedFile)[0];
}

if (strpos($requestedFile, '../') !== false || strpos($requestedFile, '..\\') !== false) {
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

header("Content-Type: " . $contentType);
header("Content-Length: " . filesize($realTargetPath));

readfile($realTargetPath);
exit;
