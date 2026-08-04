<?php
session_start();

if (empty($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

$userMode = $_SESSION['mode'] ?? 'readonly';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obsidian Viewer</title>
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

        .folder-content {
            padding-left: 12px;
            display: block;
        }

        .folder-content.collapsed {
            display: none;
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

        @media (max-width: 768px) {
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
                padding: 24px 16px;
            }
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
                Obsidian Vault
            </div>
            <span class="mode-badge <?php echo $userMode; ?>">
                <?php echo $userMode === 'edit' ? 'Úpravy' : 'Čtení'; ?>
            </span>
        </div>
        <div class="header-right">
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
                            💾 Uložit
                        </button>
                        <button id="btn-toggle-view" class="tab-btn active">
                            👁️ Náhled
                        </button>
                        <button id="btn-toggle-edit" class="tab-btn">
                            ✏️ Úpravy
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
                    <button class="tool-btn" data-tool="hr" title="Dělící čára (---)">—</button>
                    <button class="tool-btn" data-tool="task" title="Úkol (- [ ])">☑</button>

                    <!-- Native File Explorer Pickers -->
                    <button id="btn-upload-image" class="tool-btn" title="Vložit obrázek z počítače">🖼️ Vložit obrázek</button>
                    <button id="btn-upload-audio" class="tool-btn" title="Vložit zvuk z počítače">🎵 Vložit zvuk</button>
                    <button id="btn-upload-file" class="tool-btn" title="Nahrát libovolný soubor z počítače">📁 Nahrát soubor</button>

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

    <!-- Pass user mode to JS -->
    <script>
        window.USER_MODE = "<?php echo $userMode; ?>";
    </script>
    <script src="app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
