<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$currentUser = requireAuth(false);
$userId = $currentUser['id'];
$isAdmin = $currentUser['is_admin'];
$username = $currentUser['username'];

$userVaults = getUserAccessibleVaults($userId, $isAdmin);
if (empty($userVaults)) {
    die("Nemáte přiřazen přístup k žádnému vaultu. Kontaktujte administrátora.");
}

$activeVault = trim($_GET['vault'] ?? array_key_first($userVaults));
if (!isset($userVaults[$activeVault])) {
    $activeVault = array_key_first($userVaults);
}

$userPermission = $userVaults[$activeVault];
$userMode = ($userPermission === 'write') ? 'edit' : 'readonly';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obsidian Viewer</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
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
            --bg-active: #28282e;
            --border-color: #1f1f24;
            --border-hr: #2a2a30;
            --text-primary: #dcddde;
            --text-secondary: #8e8e93;
            --text-heading: #ffffff;
            --accent-color: #7f6df2;
            --accent-hover: #6b59e6;
            --sidebar-width: 290px;
            --header-height: 52px;
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* Top Header Bar */
        .app-header {
            height: var(--header-height);
            background-color: var(--bg-sidebar);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 100;
            flex-shrink: 0;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .toggle-sidebar-btn {
            background: none;
            border: none;
            color: var(--text-primary);
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
        }

        .toggle-sidebar-btn:hover {
            background-color: var(--bg-hover);
        }

        .app-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.01em;
        }

        .app-title svg {
            width: 20px;
            height: 20px;
        }

        .mode-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .mode-badge.readonly {
            background-color: rgba(142, 142, 147, 0.15);
            color: #8e8e93;
            border: 1px solid rgba(142, 142, 147, 0.3);
        }

        .mode-badge.edit {
            background-color: #7f6df2;
            color: #ffffff;
            border: 1px solid #7f6df2;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logout-btn {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.85rem;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            transition: all 0.2s;
        }

        .logout-btn:hover {
            color: #eb5757;
            border-color: rgba(235, 87, 87, 0.4);
            background-color: rgba(235, 87, 87, 0.08);
        }

        /* Layout Container */
        .app-container {
            display: flex;
            flex: 1;
            height: calc(100vh - var(--header-height));
            overflow: hidden;
            position: relative;
        }

        /* Sidebar Styling */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            transition: transform 0.25s cubic-bezier(0.1, 0.9, 0.2, 1.0);
            flex-shrink: 0;
            z-index: 90;
        }

        .sidebar-header-actions {
            padding: 12px 12px 8px 12px;
            display: flex;
            gap: 8px;
            border-bottom: 1px solid var(--border-color);
            background-color: #121214;
        }

        .action-btn {
            flex: 1;
            background-color: #7f6df2;
            border: none;
            color: #ffffff;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 8px 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.15s;
            box-shadow: 0 2px 8px rgba(127, 109, 242, 0.3);
        }

        .action-btn:hover {
            background-color: #6b59e6;
        }

        .sidebar-search {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .search-input {
            width: 100%;
            background-color: #141416;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 0.85rem;
            padding: 7px 10px;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-input:focus {
            border-color: var(--accent-color);
        }

        .file-tree-container {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
        }

        .file-tree-container::-webkit-scrollbar {
            width: 5px;
        }

        .file-tree-container::-webkit-scrollbar-thumb {
            background-color: #222226;
            border-radius: 3px;
        }

        .tree-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 12px;
            color: var(--text-primary);
            font-size: 0.88rem;
            cursor: pointer;
            user-select: none;
            text-decoration: none;
            transition: background-color 0.15s, color 0.15s;
            position: relative;
            border-radius: 4px;
            margin: 1px 4px;
        }

        .tree-item-label {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            flex: 1;
        }

        .tree-item-actions {
            display: none;
            gap: 4px;
            align-items: center;
        }

        /* Show action buttons on HOVER or when touch-selected / active on mobile & desktop */
        .tree-item:hover .tree-item-actions,
        .tree-item.active .tree-item-actions,
        .tree-item.touch-selected .tree-item-actions,
        .folder-header:hover .tree-item-actions,
        .folder-header.touch-selected .tree-item-actions {
            display: flex !important;
        }

        .icon-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.85;
            transition: all 0.15s;
            font-size: 0.95rem;
        }

        .icon-btn:hover, .icon-btn:active {
            opacity: 1;
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.2);
        }

        .icon-btn.danger:hover, .icon-btn.danger:active {
            color: #eb5757;
            background-color: rgba(235, 87, 87, 0.25);
        }

        .tree-item:hover {
            background-color: var(--bg-hover);
        }

        .tree-item.active, .tree-item.touch-selected {
            background-color: var(--bg-active);
            color: var(--accent-color);
            font-weight: 500;
            border-left: 2px solid var(--accent-color);
            padding-left: 10px;
        }

        .drag-over {
            outline: 2px dashed #7f6df2 !important;
            background-color: rgba(127, 109, 242, 0.15) !important;
        }

        .folder-group {
            display: flex;
            flex-direction: column;
        }

        .folder-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 12px;
            color: var(--text-secondary);
            font-size: 0.86rem;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.15s, color 0.15s;
            border-radius: 4px;
            margin: 1px 4px;
        }

        .folder-header.touch-selected {
            background-color: var(--bg-hover);
            color: var(--text-primary);
        }

        .folder-header-label {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .folder-header:hover {
            background-color: var(--bg-hover);
            color: var(--text-primary);
        }

        .folder-chevron {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 16px;
            height: 16px;
            margin-right: 2px;
            color: var(--text-secondary);
            transition: transform 0.2s cubic-bezier(0.2, 0, 0, 1);
            transform: rotate(90deg); /* Expanded = ▾ */
            flex-shrink: 0;
        }

        .folder-header.collapsed .folder-chevron,
        .folder-group.is-collapsed .folder-chevron {
            transform: rotate(0deg); /* Collapsed = ▸ */
        }

        .folder-content {
            padding-left: 12px;
            display: block;
        }

        .folder-content.collapsed,
        .folder-group.is-collapsed > .folder-content {
            display: none !important;
        }

        /* Search Results Panel */
        .search-results-container {
            flex: 1;
            overflow-y: auto;
            padding: 8px 10px;
        }

        .search-results-container::-webkit-scrollbar {
            width: 5px;
        }

        .search-results-container::-webkit-scrollbar-thumb {
            background-color: #222226;
            border-radius: 3px;
        }

        .search-summary-badge {
            font-size: 0.76rem;
            color: var(--text-secondary);
            padding: 4px 6px 8px 6px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .search-result-item {
            background-color: #121214;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 10px;
            margin-bottom: 6px;
            cursor: pointer;
            transition: all 0.15s;
        }

        .search-result-item:hover {
            background-color: var(--bg-hover);
            border-color: var(--accent-color);
        }

        .search-result-title {
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 3px;
        }

        .search-result-path {
            font-size: 0.72rem;
            color: var(--text-secondary);
            margin-bottom: 4px;
            font-family: monospace;
            word-break: break-all;
        }

        .search-result-snippet {
            font-size: 0.78rem;
            color: var(--text-primary);
            line-height: 1.35;
            background: #09090a;
            padding: 4px 6px;
            border-radius: 4px;
            border-left: 2px solid var(--accent-color);
        }

        .search-result-snippet mark {
            background-color: rgba(127, 109, 242, 0.35);
            color: #ffffff;
            font-weight: 600;
            padding: 0 2px;
            border-radius: 2px;
        }

        /* Header Vault select & Admin button */
        .header-vault-select {
            background-color: #141416;
            border: 1px solid var(--border-color);
            color: var(--text-heading);
            font-size: 0.82rem;
            font-weight: 500;
            padding: 4px 8px;
            border-radius: 6px;
            outline: none;
            cursor: pointer;
            transition: border-color 0.2s;
        }

        .header-vault-select:focus {
            border-color: var(--accent-color);
        }

        .admin-nav-btn {
            background: rgba(127, 109, 242, 0.15);
            color: var(--accent-color);
            border: 1px solid rgba(127, 109, 242, 0.35);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s;
        }

        .admin-nav-btn:hover {
            background: var(--accent-color);
            color: #ffffff;
        }

        .header-user-tag {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-right: 4px;
        }

        .item-icon {
            width: 15px;
            height: 15px;
            flex-shrink: 0;
            opacity: 0.75;
        }

        /* Main Viewport */
        .main-viewport {
            flex: 1;
            background-color: var(--bg-main);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .note-header-bar {
            height: 44px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            font-size: 0.82rem;
            color: var(--text-secondary);
            background-color: #050505;
            flex-shrink: 0;
        }

        .note-path-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 500;
            color: var(--text-primary);
        }

        .note-header-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tab-btn {
            background: none;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 4px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.78rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
        }

        .tab-btn:hover {
            color: #fff;
            border-color: #444;
        }

        .tab-btn.active {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            color: #fff;
        }

        .save-btn {
            background-color: #7f6df2;
            border: 1px solid #7f6df2;
            color: #fff;
            padding: 4px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.78rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .save-btn:hover {
            background-color: #6b59e6;
        }

        .save-status {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-right: 4px;
        }

        /* Formatting Toolbar */
        .editor-toolbar {
            background-color: #0d0d0e;
            border-bottom: 1px solid var(--border-color);
            padding: 6px 20px;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }

        .tool-btn {
            background-color: #161619;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 4px;
            font-size: 0.75rem;
            padding: 4px 8px;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .tool-btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--accent-color);
            color: #fff;
        }

        /* Main Note Scroll Area & Editor */
        .note-scroll-area {
            flex: 1;
            overflow-y: auto;
            padding: 36px 48px;
            display: flex;
            justify-content: center;
            position: relative;
        }

        .note-scroll-area::-webkit-scrollbar {
            width: 8px;
        }

        .note-scroll-area::-webkit-scrollbar-thumb {
            background-color: #222226;
            border-radius: 4px;
        }

        .note-content {
            width: 100%;
            max-width: 800px;
            line-height: 1.7;
            font-size: 1rem;
        }

        .editor-textarea {
            width: 100%;
            max-width: 800px;
            height: 100%;
            min-height: 500px;
            background-color: #000000;
            border: none;
            outline: none;
            color: var(--text-primary);
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 0.95rem;
            line-height: 1.6;
            resize: none;
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-secondary);
            gap: 12px;
            text-align: center;
            padding: 20px;
        }

        .empty-state svg {
            width: 48px;
            height: 48px;
            opacity: 0.4;
        }

        /* Markdown Rendered Styling */
        .markdown-rendered h1 {
            font-size: 2.1rem;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
            line-height: 1.3;
        }

        .markdown-rendered h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-heading);
            margin-top: 32px;
            margin-bottom: 16px;
            line-height: 1.35;
        }

        .markdown-rendered h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-heading);
            margin-top: 24px;
            margin-bottom: 12px;
        }

        .markdown-rendered p {
            margin-bottom: 16px;
            color: var(--text-primary);
        }

        .markdown-rendered hr {
            border: none;
            height: 1px;
            background-color: var(--border-hr);
            margin: 28px 0;
        }

        .markdown-rendered img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            margin: 16px 0;
            display: block;
        }

        .markdown-rendered audio {
            max-width: 100%;
            width: 100%;
            margin: 16px 0;
            border-radius: 8px;
            outline: none;
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
        }

        .markdown-rendered video {
            max-width: 100%;
            width: 100%;
            border-radius: 8px;
            margin: 16px 0;
            border: 1px solid var(--border-color);
        }

        .markdown-rendered ul, .markdown-rendered ol {
            margin-left: 24px;
            margin-bottom: 16px;
        }

        .markdown-rendered li {
            margin-bottom: 6px;
        }

        .markdown-rendered code {
            background-color: #1a1a1e;
            color: #e2c08d;
            padding: 3px 6px;
            border-radius: 4px;
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 0.9em;
        }

        .markdown-rendered pre {
            background-color: #121215;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.6);
            z-index: 80;
        }

        /* Custom Modal Styling */
        .modal-backdrop {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(0, 0, 0, 0.75);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(3px);
        }

        .modal-card {
            background-color: #141416;
            border: 1px solid #28282e;
            border-radius: 12px;
            padding: 24px;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.8);
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .modal-card h3 {
            font-size: 1.1rem;
            color: #ffffff;
            font-weight: 600;
        }

        .modal-card p {
            font-size: 0.88rem;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        .modal-input, .modal-select {
            background-color: #000000;
            border: 1px solid #333333;
            border-radius: 6px;
            color: #ffffff;
            padding: 10px 12px;
            font-size: 0.95rem;
            outline: none;
            width: 100%;
        }

        .modal-input:focus, .modal-select:focus {
            border-color: #7f6df2;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 6px;
        }

        .modal-btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }

        .modal-btn.cancel {
            background-color: #222226;
            color: #cccccc;
        }

        .modal-btn.cancel:hover {
            background-color: #333338;
            color: #ffffff;
        }

        .modal-btn.confirm {
            background-color: #7f6df2;
            color: #ffffff;
        }

        .modal-btn.confirm:hover {
            background-color: #6b59e6;
        }

        /* Header icon button */
        .header-icon-btn {
            background-color: #1a1a1e;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .header-icon-btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--accent-color);
            color: #fff;
        }

        /* Mobile Bottom Bar (Obsidian Mobile Ribbon) */
        .mobile-bottom-bar {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 56px;
            background-color: rgba(13, 13, 14, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-top: 1px solid var(--border-color);
            z-index: 150;
            align-items: center;
            justify-content: space-around;
            padding: 0 4px;
            padding-bottom: env(safe-area-inset-bottom, 0px);
        }
        .mobile-nav-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            font-size: 0.68rem;
            font-weight: 500;
            padding: 6px 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s;
            flex: 1;
            max-width: 72px;
        }
        .mobile-nav-btn svg {
            transition: transform 0.15s;
        }
        .mobile-nav-btn:active {
            transform: scale(0.92);
        }
        .mobile-nav-btn:hover, .mobile-nav-btn.active {
            color: var(--accent-color);
        }
        .mobile-nav-btn.active-accent {
            color: #fff;
            background: rgba(127, 109, 242, 0.15);
        }

        /* Obsidian Interactive Graph View Modal */
        .graph-modal-backdrop {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .graph-modal-content {
            width: 95vw;
            height: 90vh;
            max-width: 1400px;
            background-color: #0b0b0d;
            border: 1px solid #28282e;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.9);
            position: relative;
        }
        .graph-modal-header {
            height: 54px;
            background-color: #121215;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            flex-shrink: 0;
            gap: 12px;
        }
        .graph-title-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .graph-title-group h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
        }
        .graph-badge {
            background-color: rgba(127, 109, 242, 0.2);
            color: #a882ff;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            border: 1px solid rgba(127, 109, 242, 0.3);
        }
        .graph-header-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .graph-search-input {
            background-color: #1a1a1e;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: #fff;
            padding: 6px 10px;
            font-size: 0.82rem;
            outline: none;
            width: 160px;
            transition: border-color 0.2s, width 0.2s;
        }
        .graph-search-input:focus {
            border-color: var(--accent-color);
            width: 200px;
        }
        .graph-ctrl-btn {
            background-color: #1a1a1e;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.15s;
        }
        .graph-ctrl-btn:hover {
            background-color: #28282e;
            color: #fff;
        }
        .graph-close-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.15s;
            line-height: 1;
        }
        .graph-close-btn:hover {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .graph-canvas-container {
            flex: 1;
            position: relative;
            background-color: #070708;
            overflow: hidden;
            cursor: grab;
        }
        .graph-canvas-container:active {
            cursor: grabbing;
        }
        #graph-canvas {
            width: 100%;
            height: 100%;
            display: block;
        }
        .graph-legend {
            position: absolute;
            bottom: 14px;
            left: 18px;
            background-color: rgba(18, 18, 21, 0.85);
            backdrop-filter: blur(8px);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 6px 12px;
            display: flex;
            gap: 12px;
            font-size: 0.72rem;
            color: var(--text-secondary);
            pointer-events: none;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .legend-dot.active { background-color: #ffd700; box-shadow: 0 0 6px #ffd700; }
        .legend-dot.normal { background-color: #7f6df2; }
        .legend-dot.unresolved { background-color: #55555e; }

        /* SVG and Tool Select */
        .svg-inline-container, .svg-container {
            display: inline-block;
            max-width: 100%;
            margin: 16px 0;
            overflow-x: auto;
            text-align: center;
        }
        .svg-inline-container svg, .svg-container svg {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }
        .tool-select {
            background-color: #161619;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 4px;
            font-size: 0.75rem;
            padding: 4px 6px;
            cursor: pointer;
            outline: none;
        }
        .tool-select:hover, .tool-select:focus {
            border-color: var(--accent-color);
            color: #fff;
        }

        @media (max-width: 768px) {
            .mobile-bottom-bar {
                display: flex;
            }
            .app-container {
                height: calc(100vh - var(--header-height) - 56px);
            }
            .sidebar {
                position: absolute;
                top: 0;
                bottom: 0;
                left: 0;
                transform: translateX(-100%);
                box-shadow: 4px 0 20px rgba(0, 0, 0, 0.8);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar-backdrop.active {
                display: block;
            }

            .note-scroll-area {
                padding: 18px 14px 40px 14px;
            }

            .editor-toolbar {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                white-space: nowrap;
                padding: 8px 12px;
                gap: 8px;
            }
            .editor-toolbar::-webkit-scrollbar {
                height: 3px;
            }
            .tool-btn {
                padding: 6px 10px;
                font-size: 0.82rem;
                flex-shrink: 0;
            }

            .graph-modal-content {
                width: 100vw;
                height: 100vh;
                border-radius: 0;
                border: none;
            }
            .graph-search-input {
                width: 100px;
            }
            .graph-search-input:focus {
                width: 120px;
            }
            .graph-legend {
                display: none;
            }
            .logout-btn {
                padding: 4px 8px;
                font-size: 0.78rem;
            }
        }
        /* === Obsidian Callouts/Admonitions === */
        .callout {
            border-radius: 8px;
            padding: 12px 16px;
            margin: 16px 0;
            border-left: 4px solid;
            background-color: rgba(255,255,255,0.03);
        }
        .callout-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 6px;
        }
        .callout-title-icon {
            font-size: 1.1rem;
            line-height: 1;
        }
        .callout-content {
            font-size: 0.92rem;
            line-height: 1.6;
            color: var(--text-primary);
        }
        .callout-content p { margin-bottom: 8px; }
        .callout-content p:last-child { margin-bottom: 0; }

        .callout-note { border-color: #448aff; background-color: rgba(68,138,255,0.06); }
        .callout-note .callout-title { color: #448aff; }
        .callout-info { border-color: #448aff; background-color: rgba(68,138,255,0.06); }
        .callout-info .callout-title { color: #448aff; }
        .callout-tip { border-color: #00bfa5; background-color: rgba(0,191,165,0.06); }
        .callout-tip .callout-title { color: #00bfa5; }
        .callout-important { border-color: #ab47bc; background-color: rgba(171,71,188,0.06); }
        .callout-important .callout-title { color: #ab47bc; }
        .callout-warning { border-color: #ff9100; background-color: rgba(255,145,0,0.06); }
        .callout-warning .callout-title { color: #ff9100; }
        .callout-caution { border-color: #ff5252; background-color: rgba(255,82,82,0.06); }
        .callout-caution .callout-title { color: #ff5252; }
        .callout-danger { border-color: #ff5252; background-color: rgba(255,82,82,0.06); }
        .callout-danger .callout-title { color: #ff5252; }
        .callout-bug { border-color: #ff5252; background-color: rgba(255,82,82,0.06); }
        .callout-bug .callout-title { color: #ff5252; }
        .callout-example { border-color: #7c4dff; background-color: rgba(124,77,255,0.06); }
        .callout-example .callout-title { color: #7c4dff; }
        .callout-quote { border-color: #9e9e9e; background-color: rgba(158,158,158,0.06); }
        .callout-quote .callout-title { color: #9e9e9e; }
        .callout-question { border-color: #ff9100; background-color: rgba(255,145,0,0.06); }
        .callout-question .callout-title { color: #ff9100; }
        .callout-success { border-color: #00c853; background-color: rgba(0,200,83,0.06); }
        .callout-success .callout-title { color: #00c853; }
        .callout-failure { border-color: #ff5252; background-color: rgba(255,82,82,0.06); }
        .callout-failure .callout-title { color: #ff5252; }
        .callout-abstract { border-color: #00b0ff; background-color: rgba(0,176,255,0.06); }
        .callout-abstract .callout-title { color: #00b0ff; }
        .callout-todo { border-color: #448aff; background-color: rgba(68,138,255,0.06); }
        .callout-todo .callout-title { color: #448aff; }

        /* === Code Blocks === */
        .code-block-wrapper {
            position: relative;
            margin: 16px 0;
        }
        .code-block-wrapper .code-lang-label {
            position: absolute;
            top: 0;
            right: 0;
            background-color: #1e1e22;
            color: #8e8e93;
            font-size: 0.72rem;
            padding: 4px 10px;
            border-radius: 0 8px 0 6px;
            border-bottom: 1px solid var(--border-color);
            border-left: 1px solid var(--border-color);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        .code-block-wrapper .code-copy-btn {
            position: absolute;
            top: 6px;
            right: 70px;
            background-color: #222226;
            color: #8e8e93;
            border: 1px solid #333;
            border-radius: 4px;
            padding: 3px 8px;
            font-size: 0.7rem;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .code-block-wrapper:hover .code-copy-btn {
            opacity: 1;
        }
        .code-block-wrapper .code-copy-btn:hover {
            background-color: #333;
            color: #fff;
        }
        .markdown-rendered pre code {
            background: none;
            padding: 0;
            border-radius: 0;
            font-size: 0.88em;
            color: #d4d4d4;
        }

        /* === Mermaid Diagrams === */
        .mermaid-container {
            background-color: #1a1a1e;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            margin: 16px 0;
            overflow-x: auto;
            text-align: center;
        }
        .mermaid-container svg {
            max-width: 100%;
            height: auto;
        }

        /* === Highlight / Mark === */
        .markdown-rendered mark, .obsidian-highlight {
            background-color: rgba(255, 208, 0, 0.25);
            color: inherit;
            padding: 1px 4px;
            border-radius: 3px;
        }

        /* === Tags === */
        .obsidian-tag {
            display: inline-block;
            background-color: rgba(127, 109, 242, 0.12);
            color: #7f6df2;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 0.85em;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid rgba(127, 109, 242, 0.25);
            cursor: pointer;
            transition: all 0.15s;
        }
        .obsidian-tag:hover {
            background-color: rgba(127, 109, 242, 0.25);
            border-color: rgba(127, 109, 242, 0.5);
        }

        /* === Checkboxes / Task Lists === */
        .task-list {
            list-style: none;
            margin-left: 0;
            padding-left: 0;
        }
        .task-list-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 6px;
            padding: 4px 0;
        }
        .task-list-item input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            min-width: 18px;
            border: 2px solid #555;
            border-radius: 4px;
            background-color: transparent;
            cursor: pointer;
            margin-top: 2px;
            position: relative;
            transition: all 0.15s;
        }
        .task-list-item input[type="checkbox"]:checked {
            background-color: #7f6df2;
            border-color: #7f6df2;
        }
        .task-list-item input[type="checkbox"]:checked::after {
            content: '\2713';
            position: absolute;
            top: -1px;
            left: 2px;
            color: #fff;
            font-size: 12px;
            font-weight: bold;
        }
        .task-list-item.completed > .task-text {
            text-decoration: line-through;
            color: var(--text-secondary);
            opacity: 0.7;
        }

        /* === Tables === */
        .markdown-rendered table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 0.92rem;
        }
        .markdown-rendered th {
            background-color: #1a1a1e;
            color: var(--text-heading);
            font-weight: 600;
            padding: 10px 14px;
            text-align: left;
            border: 1px solid #2a2a30;
        }
        .markdown-rendered td {
            padding: 8px 14px;
            border: 1px solid #2a2a30;
            color: var(--text-primary);
        }
        .markdown-rendered tr:nth-child(even) {
            background-color: rgba(255,255,255,0.02);
        }
        .markdown-rendered tr:hover {
            background-color: rgba(127, 109, 242, 0.05);
        }

        /* === Blockquotes === */
        .markdown-rendered blockquote {
            border-left: 3px solid #7f6df2;
            margin: 16px 0;
            padding: 8px 16px;
            color: var(--text-secondary);
            background-color: rgba(127, 109, 242, 0.04);
            border-radius: 0 6px 6px 0;
        }
        .markdown-rendered blockquote p {
            margin-bottom: 6px;
        }
        .markdown-rendered blockquote p:last-child {
            margin-bottom: 0;
        }

        /* === Strikethrough === */
        .markdown-rendered del, .markdown-rendered s {
            color: var(--text-secondary);
            opacity: 0.7;
        }

        /* === Internal Wiki Links === */
        .obsidian-wikilink {
            color: #7f6df2;
            text-decoration: none;
            border-bottom: 1px solid rgba(127, 109, 242, 0.3);
            cursor: pointer;
            transition: all 0.15s;
        }
        .obsidian-wikilink:hover {
            color: #a594ff;
            border-bottom-color: #a594ff;
        }
        .obsidian-wikilink.unresolved {
            color: #8e8e93;
            border-bottom-style: dashed;
        }

        /* === Headings h4-h6 === */
        .markdown-rendered h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-heading);
            margin-top: 20px;
            margin-bottom: 10px;
        }
        .markdown-rendered h5 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-heading);
            margin-top: 18px;
            margin-bottom: 8px;
        }
        .markdown-rendered h6 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-top: 16px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        /* === Footnotes === */
        .footnote-ref {
            color: #7f6df2;
            font-size: 0.8em;
            vertical-align: super;
            text-decoration: none;
            cursor: pointer;
        }
        .footnotes-section {
            border-top: 1px solid var(--border-hr);
            margin-top: 32px;
            padding-top: 16px;
            font-size: 0.88rem;
            color: var(--text-secondary);
        }
        .footnote-item {
            margin-bottom: 8px;
        }

        /* === KaTeX Math === */
        .math-block {
            overflow-x: auto;
            padding: 12px 0;
            margin: 16px 0;
            text-align: center;
        }
        .math-inline {
            display: inline;
        }

        /* === Keyboard Shortcut === */
        .markdown-rendered kbd {
            background-color: #1a1a1e;
            border: 1px solid #333;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 0.85em;
            font-family: SFMono-Regular, Consolas, monospace;
            box-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }

        /* === Embedded Note Transclusion === */
        .embedded-note {
            background-color: rgba(127, 109, 242, 0.04);
            border: 1px solid rgba(127, 109, 242, 0.15);
            border-radius: 8px;
            padding: 16px;
            margin: 16px 0;
        }
        .embedded-note-title {
            color: #7f6df2;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* === Nested Lists === */
        .markdown-rendered ul ul, .markdown-rendered ol ol,
        .markdown-rendered ul ol, .markdown-rendered ol ul {
            margin-bottom: 0;
            margin-top: 4px;
        }

        /* === Font color inline HTML support === */
        .markdown-rendered font[color] {
            /* inherits color from attribute */
        }

        /* === Comment / Hidden text === */
        .obsidian-comment {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Top Header Bar -->
    <header class="app-header">
        <div class="header-left">
            <button id="toggle-sidebar" class="toggle-sidebar-btn" title="Přepnout postranní panel" aria-label="Toggle Sidebar">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
            <div class="app-title">
                <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M38.5 8.5L78.5 28.5L91.5 68.5L48.5 91.5L8.5 63.5L18.5 28.5L38.5 8.5Z" fill="#7F6DF2" fill-opacity="0.2" stroke="#7F6DF2" stroke-width="6" stroke-linejoin="round"/>
                    <path d="M38.5 8.5L48.5 91.5" stroke="#7F6DF2" stroke-width="4"/>
                    <path d="M18.5 28.5L78.5 28.5" stroke="#7F6DF2" stroke-width="4"/>
                    <path d="M8.5 63.5L91.5 68.5" stroke="#7F6DF2" stroke-width="4"/>
                </svg>
                Obsidian Web
            </div>

            <!-- Vault Selector -->
            <select id="vault-select" class="header-vault-select" title="Zvolit aktivní vault">
                <?php foreach ($userVaults as $vName => $vPerm): ?>
                    <option value="<?php echo htmlspecialchars($vName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $vName === $activeVault ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($vName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo $vPerm === 'write' ? 'Zápis' : 'Čtení'; ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <span class="mode-badge <?php echo $userMode; ?>">
                <?php echo $userMode === 'edit' ? 'Úpravy' : 'Pouze čtení'; ?>
            </span>
        </div>
        <div class="header-right">
            <?php if ($isAdmin): ?>
                <a href="admin.php" class="admin-nav-btn" title="Otevřít Admin Panel">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <span>Admin</span>
                </a>
            <?php endif; ?>
            <button id="btn-open-graph" class="header-icon-btn" title="Otevřít graf poznámek (Graph View)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
                <span>Graf</span>
            </button>
            <span class="header-user-tag"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 3px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
            <a href="login.php?logout=1" class="logout-btn">Odhlásit se</a>
        </div>
    </header>

    <!-- Main Container -->
    <div class="app-container">
        <div id="sidebar-backdrop" class="sidebar-backdrop"></div>

        <!-- Sidebar Navigation -->
        <aside id="sidebar" class="sidebar">
            <?php if ($userMode === 'edit'): ?>
                <div class="sidebar-header-actions">
                    <button id="btn-new-note" class="action-btn" title="Vytvořit novou poznámku">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        + Poznámka
                    </button>
                    <button id="btn-new-folder" class="action-btn" title="Vytvořit novou složku">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                        + Složka
                    </button>
                </div>
            <?php endif; ?>

            <div class="sidebar-search">
                <input type="text" id="search-input" class="search-input" placeholder="Hledat v poznámkách..." autocomplete="off">
            </div>
            <div id="search-results" class="search-results-container" style="display: none;"></div>
            <div id="file-tree" class="file-tree-container">
                <!-- Dynamically populated by app.js -->
            </div>
        </aside>

        <!-- Main Viewport -->
        <main class="main-viewport">
            <div id="note-header" class="note-header-bar" style="display: none;">
                <span id="note-path" class="note-path-title"></span>

                <div class="note-header-controls">
                    <span id="save-status" class="save-status" style="display: none;"></span>

                    <?php if ($userMode === 'edit'): ?>
                        <button id="btn-save-note" class="save-btn" style="display: none;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Uložit
                        </button>
                        <button id="btn-toggle-view" class="tab-btn active">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            Náhled
                        </button>
                        <button id="btn-toggle-edit" class="tab-btn">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            Úpravy
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($userMode === 'edit'): ?>
                <!-- Formatting & File Upload Toolbar -->
                <div id="editor-toolbar" class="editor-toolbar" style="display: none;">
                    <button class="tool-btn" data-tool="bold" title="Tučné (**text**)"><b>B</b></button>
                    <button class="tool-btn" data-tool="italic" title="Kurzíva (*text*)"><i>I</i></button>
                    <button class="tool-btn" data-tool="h1" title="Nadpis 1">H1</button>
                    <button class="tool-btn" data-tool="h2" title="Nadpis 2">H2</button>
                    <button class="tool-btn" data-tool="highlight" title="Zvýraznění (==text==)"><mark style="background: rgba(255,208,0,0.3); color: inherit; padding: 0 2px;">H</mark></button>
                    <button class="tool-btn" data-tool="strike" title="Přeškrtnutí (~~text~~)"><s>S</s></button>
                    <button class="tool-btn" data-tool="wikilink" title="Odkaz na poznámku ([[link]])">[[ ]]</button>
                    <button class="tool-btn" data-tool="callout" title="Vložit callout">Callout</button>
                    <button class="tool-btn" data-tool="table" title="Vložit tabulku">Tabulka</button>
                    <button class="tool-btn" data-tool="hr" title="Dělící čára (---)">—</button>
                    <button class="tool-btn" data-tool="task" title="Úkol (- [ ])">Úkol</button>

                    <!-- Text Color Picker -->
                    <select id="tool-color-select" class="tool-select" title="Barva vybraného textu">
                        <option value="">Barva textu...</option>
                        <option value="#ff5555">Červená</option>
                        <option value="#50fa7b">Zelená</option>
                        <option value="#8be9fd">Azurová</option>
                        <option value="#bd93f9">Fialová</option>
                        <option value="#ffb86c">Oranžová</option>
                        <option value="#f1fa8c">Žlutá</option>
                    </select>

                    <!-- Native File Explorer Pickers -->
                    <button id="btn-upload-image" class="tool-btn" title="Vložit obrázek z počítače">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        Obrázek
                    </button>
                    <button id="btn-upload-audio" class="tool-btn" title="Vložit zvuk z počítače">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                        Zvuk
                    </button>
                    <button id="btn-upload-file" class="tool-btn" title="Nahrát libovolný soubor z počítače">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                        Příloha
                    </button>

                    <!-- Hidden Native File Inputs -->
                    <input type="file" id="input-file-image" accept="image/*" style="display: none;">
                    <input type="file" id="input-file-audio" accept="audio/*,.m4a,.mp3,.wav,.ogg,.flac" style="display: none;">
                    <input type="file" id="input-file-any" accept="*/*" style="display: none;">
                </div>
            <?php endif; ?>

            <div class="note-scroll-area">
                <div id="empty-state" class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    <p>Vyberte poznámku ze seznamu vlevo</p>
                </div>

                <!-- Preview View -->
                <article id="note-view" class="note-content markdown-rendered" style="display: none;"></article>

                <!-- Edit Textarea View -->
                <textarea id="note-editor" class="editor-textarea" style="display: none;" spellcheck="false" placeholder="Zde můžete psát obsah poznámky v Markdownu..."></textarea>
            </div>
        </main>
    </div>

    <!-- Mobile Bottom Navigation Bar (Obsidian Mobile Ribbon) -->
    <nav class="mobile-bottom-bar" id="mobile-bottom-bar">
        <button class="mobile-nav-btn" id="m-btn-files" title="Soubory">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
            <span>Soubory</span>
        </button>
        <button class="mobile-nav-btn" id="m-btn-search" title="Hledat">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <span>Hledat</span>
        </button>
        <button class="mobile-nav-btn" id="m-btn-graph" title="Graf pohledu">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
            <span>Graf</span>
        </button>
        <button class="mobile-nav-btn" id="m-btn-mode" title="Přepnout režim">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            <span>Režim</span>
        </button>
        <?php if ($userMode === 'edit'): ?>
        <button class="mobile-nav-btn active-accent" id="m-btn-new-note" title="Nová poznámka">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            <span>Přidat</span>
        </button>
        <?php endif; ?>
    </nav>

    <!-- Obsidian Interactive Graph View Modal -->
    <div id="graph-modal" class="graph-modal-backdrop" style="display: none;">
        <div class="graph-modal-content">
            <div class="graph-modal-header">
                <div class="graph-title-group">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7f6df2" stroke-width="2"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
                    <h3>Graf poznámek (Graph View)</h3>
                    <span id="graph-stats" class="graph-badge">0 poznámek</span>
                </div>
                <div class="graph-header-controls">
                    <input type="text" id="graph-search" class="graph-search-input" placeholder="Hledat uzel...">
                    <button id="graph-btn-fit" class="graph-ctrl-btn" title="Vystředit graf">Reset</button>
                    <button id="graph-btn-close" class="graph-close-btn" title="Zavřít">✕</button>
                </div>
            </div>
            <div class="graph-canvas-container" id="graph-container">
                <canvas id="graph-canvas"></canvas>
                <div class="graph-legend">
                    <span class="legend-item"><span class="legend-dot active"></span> Aktivní poznámka</span>
                    <span class="legend-item"><span class="legend-dot normal"></span> Poznámka</span>
                    <span class="legend-item"><span class="legend-dot unresolved"></span> Neexistující odkaz</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom In-App Modal Dialog -->
    <div id="custom-modal-backdrop" class="modal-backdrop" style="display: none;">
        <div class="modal-card">
            <h3 id="modal-title">Názvy a Akce</h3>
            <p id="modal-subtitle" style="display: none;"></p>
            <input type="text" id="modal-input" class="modal-input" placeholder="Napište text..." autocomplete="off">
            <select id="modal-select" class="modal-select" style="display: none;"></select>
            <div class="modal-actions">
                <button id="modal-btn-cancel" class="modal-btn cancel">Zrušit</button>
                <button id="modal-btn-confirm" class="modal-btn confirm">Potvrdit</button>
            </div>
        </div>
    </div>

    <!-- Pass user info, vault, and CSRF token to JS -->
    <script>
        window.CSRF_TOKEN = "<?php echo $_SESSION['csrf_token']; ?>";
        window.CURRENT_VAULT = "<?php echo htmlspecialchars($activeVault, ENT_QUOTES, 'UTF-8'); ?>";
        window.USER_MODE = "<?php echo $userMode; ?>";
        window.IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        window.USERNAME = "<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>";
    </script>
    <script src="app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
