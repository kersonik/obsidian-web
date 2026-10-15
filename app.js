document.addEventListener('DOMContentLoaded', () => {
    const fileTreeContainer = document.getElementById('file-tree');
    const searchInput = document.getElementById('search-input');
    const searchResultsContainer = document.getElementById('search-results');
    const vaultSelect = document.getElementById('vault-select');
    const noteView = document.getElementById('note-view');
    const noteEditor = document.getElementById('note-editor');
    const emptyState = document.getElementById('empty-state');
    const noteHeader = document.getElementById('note-header');
    const notePathSpan = document.getElementById('note-path');
    const sidebar = document.getElementById('sidebar');
    const toggleSidebarBtn = document.getElementById('toggle-sidebar');
    const sidebarBackdrop = document.getElementById('sidebar-backdrop');
    
    // Header & Editor Controls
    const btnSaveNote = document.getElementById('btn-save-note');
    const btnToggleView = document.getElementById('btn-toggle-view');
    const btnToggleEdit = document.getElementById('btn-toggle-edit');
    const saveStatus = document.getElementById('save-status');
    const editorToolbar = document.getElementById('editor-toolbar');
    
    // Sidebar Edit Controls
    const btnNewNote = document.getElementById('btn-new-note');
    const btnNewFolder = document.getElementById('btn-new-folder');

    // File Upload Pickers
    const btnUploadImage = document.getElementById('btn-upload-image');
    const btnUploadAudio = document.getElementById('btn-upload-audio');
    const btnUploadFile  = document.getElementById('btn-upload-file');

    const inputFileImage = document.getElementById('input-file-image');
    const inputFileAudio = document.getElementById('input-file-audio');
    const inputFileAny   = document.getElementById('input-file-any');

    // Custom Modal Elements
    const modalBackdrop = document.getElementById('custom-modal-backdrop');
    const modalTitle = document.getElementById('modal-title');
    const modalSubtitle = document.getElementById('modal-subtitle');
    const modalInput = document.getElementById('modal-input');
    const modalSelect = document.getElementById('modal-select');
    const modalBtnCancel = document.getElementById('modal-btn-cancel');
    const modalBtnConfirm = document.getElementById('modal-btn-confirm');

    let currentVault = window.CURRENT_VAULT || 'default';
    let userMode = window.USER_MODE || 'readonly';
    let isEditAllowed = (userMode === 'edit');

    let allFilesData = [];
    let serverVaultPath = '';
    let currentFilePath = null;
    let rawNoteContent = '';
    let isEditingMode = false;
    let isUnsaved = false;
    let currentFileLastModified = 0;

    // Vault Selector Change Event
    if (vaultSelect) {
        vaultSelect.addEventListener('change', () => {
            const newVault = vaultSelect.value;
            window.location.href = `index.php?vault=${encodeURIComponent(newVault)}`;
        });
    }

    // Custom In-App Modal Dialog Prompts
    function showCustomPrompt(title, defaultValue = '', placeholder = '') {
        return new Promise((resolve) => {
            modalTitle.textContent = title;
            modalSubtitle.style.display = 'none';
            modalSelect.style.display = 'none';
            modalInput.style.display = 'block';
            modalInput.value = defaultValue;
            modalInput.placeholder = placeholder || 'Napište text...';
            modalBackdrop.style.display = 'flex';
            modalInput.focus();

            function onConfirm() {
                cleanup();
                resolve(modalInput.value.trim());
            }

            function onCancel() {
                cleanup();
                resolve(null);
            }

            function onKey(e) {
                if (e.key === 'Enter') onConfirm();
                if (e.key === 'Escape') onCancel();
            }

            function cleanup() {
                modalBackdrop.style.display = 'none';
                modalBtnConfirm.removeEventListener('click', onConfirm);
                modalBtnCancel.removeEventListener('click', onCancel);
                modalInput.removeEventListener('keydown', onKey);
            }

            modalBtnConfirm.addEventListener('click', onConfirm);
            modalBtnCancel.addEventListener('click', onCancel);
            modalInput.addEventListener('keydown', onKey);
        });
    }

    function showCustomConfirm(title, subtitle = '') {
        return new Promise((resolve) => {
            modalTitle.textContent = title;
            if (subtitle) {
                modalSubtitle.textContent = subtitle;
                modalSubtitle.style.display = 'block';
            } else {
                modalSubtitle.style.display = 'none';
            }
            modalInput.style.display = 'none';
            modalSelect.style.display = 'none';
            modalBackdrop.style.display = 'flex';

            function onConfirm() {
                cleanup();
                resolve(true);
            }

            function onCancel() {
                cleanup();
                resolve(false);
            }

            function cleanup() {
                modalBackdrop.style.display = 'none';
                modalBtnConfirm.removeEventListener('click', onConfirm);
                modalBtnCancel.removeEventListener('click', onCancel);
            }

            modalBtnConfirm.addEventListener('click', onConfirm);
            modalBtnCancel.addEventListener('click', onCancel);
        });
    }

    function showCustomSelectPrompt(title, subtitle, options) {
        return new Promise((resolve) => {
            modalTitle.textContent = title;
            if (subtitle) {
                modalSubtitle.textContent = subtitle;
                modalSubtitle.style.display = 'block';
            } else {
                modalSubtitle.style.display = 'none';
            }
            modalInput.style.display = 'none';
            modalSelect.style.display = 'block';
            modalSelect.innerHTML = '';

            options.forEach(opt => {
                const el = document.createElement('option');
                el.value = opt.value;
                el.textContent = opt.label;
                modalSelect.appendChild(el);
            });

            modalBackdrop.style.display = 'flex';

            function onConfirm() {
                cleanup();
                resolve(modalSelect.value);
            }

            function onCancel() {
                cleanup();
                resolve(null);
            }

            function cleanup() {
                modalBackdrop.style.display = 'none';
                modalBtnConfirm.removeEventListener('click', onConfirm);
                modalBtnCancel.removeEventListener('click', onCancel);
            }

            modalBtnConfirm.addEventListener('click', onConfirm);
            modalBtnCancel.addEventListener('click', onCancel);
        });
    }

    // Toggle Sidebar (Mobile & Desktop)
    function toggleSidebar() {
        sidebar.classList.toggle('open');
        sidebarBackdrop.classList.toggle('active');
    }

    if (toggleSidebarBtn) {
        toggleSidebarBtn.addEventListener('click', toggleSidebar);
    }
    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', toggleSidebar);
    }

    // Fetch file tree from server
    async function loadFileTree() {
        try {
            const response = await fetch(`file.php?action=list&vault=${encodeURIComponent(currentVault)}`);
            if (!response.ok) {
                if (response.status === 401 || response.status === 403) {
                    window.location.href = 'login.php';
                    return;
                }
                throw new Error(`HTTP ${response.status}: Nepodařilo se načíst seznam.`);
            }

            const data = await response.json();

            if (data && data.vault) {
                currentVault = data.vault;
            }
            if (data && data.csrfToken) {
                window.CSRF_TOKEN = data.csrfToken;
            }
            if (data && data.permission) {
                userMode = (data.permission === 'write') ? 'edit' : 'readonly';
                isEditAllowed = (userMode === 'edit');
            }

            if (Array.isArray(data)) {
                allFilesData = data;
            } else if (data && data.tree) {
                allFilesData = data.tree;
                serverVaultPath = data.vaultPath || '';
            } else {
                allFilesData = [];
            }

            renderFileTree(allFilesData, fileTreeContainer);

            if (currentFilePath) {
                highlightActiveFile(currentFilePath);
            } else {
                const firstFile = findFirstFile(allFilesData);
                if (firstFile) {
                    selectFile(firstFile.path);
                }
            }
        } catch (error) {
            console.error('Error loading file tree:', error);
            fileTreeContainer.innerHTML = `
                <div style="padding: 16px; color: #eb5757; font-size: 0.82rem; text-align: center; line-height: 1.4;">
                    <p style="font-weight: 600; margin-bottom: 6px;">Chyba načítání</p>
                    <p>${escapeHtml(error.message)}</p>
                </div>`;
        }
    }

    function findFirstFile(items) {
        if (!items || !items.length) return null;
        for (const item of items) {
            if (item.type === 'file') return item;
            if (item.type === 'folder' && item.children) {
                const childFile = findFirstFile(item.children);
                if (childFile) return childFile;
            }
        }
        return null;
    }

    function highlightActiveFile(filePath) {
        const allItems = fileTreeContainer.querySelectorAll('.tree-item');
        allItems.forEach(el => {
            if (el.dataset.path === filePath) {
                el.classList.add('active');
                el.classList.add('touch-selected');
            } else {
                el.classList.remove('active');
            }
        });
    }

    // Helper: Collect all folders in vault tree
    function getAllFolders(items, list = []) {
        items.forEach(item => {
            if (item.type === 'folder') {
                list.push({ value: item.path, label: item.path });
                if (item.children) {
                    getAllFolders(item.children, list);
                }
            }
        });
        return list;
    }

    // Render tree structure in sidebar
    function renderFileTree(items, containerElement, level = 0) {
        containerElement.innerHTML = '';

        if (!items || items.length === 0) {
            if (level === 0) {
                const pathNotice = serverVaultPath ? `<p style="font-size: 0.75rem; color: #666; margin-top: 6px; word-break: break-all;">Cesta: <code>${escapeHtml(serverVaultPath)}</code></p>` : '';
                containerElement.innerHTML = `
                    <div style="padding: 20px 16px; color: #8e8e93; font-size: 0.84rem; text-align: center; line-height: 1.5;">
                        <p style="margin-bottom: 8px; color: #dcddde; font-weight: 500;">Složka trezoru je prázdná</p>
                        <p>Nahrávejte své <code>.md</code> soubory do složky <code>vault/</code> na serveru.</p>
                        ${pathNotice}
                    </div>`;
            }
            return;
        }

        items.forEach(item => {
            if (item.type === 'folder') {
                const folderGroup = document.createElement('div');
                folderGroup.className = 'folder-group';

                const folderHeader = document.createElement('div');
                folderHeader.className = 'folder-header';
                folderHeader.dataset.folderPath = item.path;

                const folderKey = `${currentVault}:${item.path}`;
                const collapsedMap = JSON.parse(localStorage.getItem('obsidian_collapsed_folders') || '{}');
                const isCollapsed = Boolean(collapsedMap[folderKey]);

                if (isCollapsed) {
                    folderHeader.classList.add('collapsed');
                }

                if (isEditAllowed) {
                    folderHeader.setAttribute('draggable', 'true');
                    attachDragAndDropHandlers(folderHeader, item.path, true);
                }

                let actionButtonsHtml = '';
                if (isEditAllowed) {
                    actionButtonsHtml = `
                        <div class="tree-item-actions">
                            <button class="icon-btn btn-add-in-folder" title="Přidat soubor do složky" data-path="${escapeHtml(item.path)}">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            </button>
                            <button class="icon-btn btn-move" title="Přesunout složku" data-path="${escapeHtml(item.path)}">
                                ⇄
                            </button>
                            <button class="icon-btn btn-rename" title="Přejmenovat složku" data-path="${escapeHtml(item.path)}" data-name="${escapeHtml(item.name)}">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                            </button>
                            <button class="icon-btn danger btn-delete" title="Smazat složku" data-path="${escapeHtml(item.path)}">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </div>`;
                }

                folderHeader.innerHTML = `
                    <div class="folder-header-label">
                        <span class="folder-chevron">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"></polyline>
                            </svg>
                        </span>
                        <svg class="item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                        </svg>
                        <span>${escapeHtml(item.name)}</span>
                    </div>
                    ${actionButtonsHtml}
                `;

                const folderContent = document.createElement('div');
                folderContent.className = 'folder-content';
                if (isCollapsed) {
                    folderContent.classList.add('collapsed');
                }

                // Tapping folder label: Expands/collapses folder, toggles chevron, persists to localStorage
                folderHeader.querySelector('.folder-header-label').addEventListener('click', () => {
                    fileTreeContainer.querySelectorAll('.touch-selected').forEach(el => el.classList.remove('touch-selected'));
                    folderHeader.classList.add('touch-selected');

                    const nowCollapsed = folderContent.classList.toggle('collapsed');
                    folderHeader.classList.toggle('collapsed', nowCollapsed);

                    const map = JSON.parse(localStorage.getItem('obsidian_collapsed_folders') || '{}');
                    if (nowCollapsed) {
                        map[folderKey] = true;
                    } else {
                        delete map[folderKey];
                    }
                    localStorage.setItem('obsidian_collapsed_folders', JSON.stringify(map));
                });

                folderGroup.appendChild(folderHeader);
                folderGroup.appendChild(folderContent);
                containerElement.appendChild(folderGroup);

                renderFileTree(item.children, folderContent, level + 1);
            } else {
                // File item
                const fileItem = document.createElement('div');
                fileItem.className = 'tree-item';
                fileItem.dataset.path = item.path;

                if (isEditAllowed) {
                    fileItem.setAttribute('draggable', 'true');
                    attachDragAndDropHandlers(fileItem, item.path, false);
                }

                const isMarkdown = item.ext === 'md';
                const iconSvg = isMarkdown ? `
                    <svg class="item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>` : `
                    <svg class="item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>`;

                const displayName = isMarkdown ? item.name.replace(/\.md$/i, '') : item.name;

                let actionButtonsHtml = '';
                if (isEditAllowed) {
                    actionButtonsHtml = `
                        <div class="tree-item-actions">
                            <button class="icon-btn btn-move" title="Přesunout soubor" data-path="${escapeHtml(item.path)}">
                                ⇄
                            </button>
                            <button class="icon-btn btn-rename" title="Přejmenovat" data-path="${escapeHtml(item.path)}" data-name="${escapeHtml(item.name)}">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                            </button>
                            <button class="icon-btn danger btn-delete" title="Smazat" data-path="${escapeHtml(item.path)}">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </div>`;
                }

                fileItem.innerHTML = `
                    <div class="tree-item-label">
                        ${iconSvg}
                        <span>${escapeHtml(displayName)}</span>
                    </div>
                    ${actionButtonsHtml}
                `;

                // Tapping file label: Opens note AND activates action buttons right next to filename
                fileItem.querySelector('.tree-item-label').addEventListener('click', (e) => {
                    e.preventDefault();
                    fileTreeContainer.querySelectorAll('.touch-selected').forEach(el => el.classList.remove('touch-selected'));
                    fileItem.classList.add('touch-selected');
                    selectFile(item.path);
                });

                containerElement.appendChild(fileItem);
            }
        });

        if (isEditAllowed) {
            containerElement.querySelectorAll('.btn-delete').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    deleteItem(btn.dataset.path);
                });
            });

            containerElement.querySelectorAll('.btn-rename').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    renameItem(btn.dataset.path, btn.dataset.name);
                });
            });

            containerElement.querySelectorAll('.btn-move').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    promptMoveItem(btn.dataset.path);
                });
            });

            containerElement.querySelectorAll('.btn-add-in-folder').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    createNewFile(btn.dataset.path);
                });
            });
        }
    }

    // Attach HTML5 Drag & Drop handlers for moving files/folders
    function attachDragAndDropHandlers(element, path, isFolder) {
        element.addEventListener('dragstart', (e) => {
            e.stopPropagation();
            e.dataTransfer.setData('text/plain', path);
            e.dataTransfer.effectAllowed = 'move';
            element.style.opacity = '0.5';
        });

        element.addEventListener('dragend', () => {
            element.style.opacity = '1';
        });

        if (isFolder) {
            element.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                element.classList.add('drag-over');
            });

            element.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                element.classList.remove('drag-over');
            });

            element.addEventListener('drop', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                element.classList.remove('drag-over');

                const sourcePath = e.dataTransfer.getData('text/plain');
                if (sourcePath && sourcePath !== path) {
                    await moveItem(sourcePath, path);
                }
            });
        }
    }

    // Fulltext search with debouncing
    let searchDebounceTimeout = null;

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            if (searchDebounceTimeout) clearTimeout(searchDebounceTimeout);

            if (!query) {
                if (searchResultsContainer) {
                    searchResultsContainer.style.display = 'none';
                    searchResultsContainer.innerHTML = '';
                }
                fileTreeContainer.style.display = 'block';
                return;
            }

            searchDebounceTimeout = setTimeout(async () => {
                await executeSearch(query);
            }, 250);
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                searchInput.value = '';
                if (searchResultsContainer) {
                    searchResultsContainer.style.display = 'none';
                    searchResultsContainer.innerHTML = '';
                }
                fileTreeContainer.style.display = 'block';
            }
        });
    }

    async function executeSearch(query) {
        if (!searchResultsContainer) return;

        fileTreeContainer.style.display = 'none';
        searchResultsContainer.style.display = 'block';
        searchResultsContainer.innerHTML = `
            <div style="padding: 18px; text-align: center; color: var(--text-secondary); font-size: 0.84rem;">
                Hledám "${escapeHtml(query)}"...
            </div>`;

        try {
            const response = await fetch(`file.php?action=search&vault=${encodeURIComponent(currentVault)}&q=${encodeURIComponent(query)}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            if (!data.results || data.results.length === 0) {
                searchResultsContainer.innerHTML = `
                    <div class="search-summary-badge">
                        <span>Výsledky vyhledávání</span>
                        <span>0 nalezeno</span>
                    </div>
                    <div style="padding: 24px 12px; text-align: center; color: var(--text-secondary); font-size: 0.84rem;">
                        Žádné poznámky neodpovídají dotazu "${escapeHtml(query)}".
                    </div>`;
                return;
            }

            let html = `
                <div class="search-summary-badge">
                    <span>Nalezeno <strong>${data.results.length}</strong> výsledků</span>
                    <span style="font-size: 0.72rem; opacity: 0.75;">${data.engine === 'ripgrep' ? 'ripgrep' : 'PHP'}</span>
                </div>`;

            data.results.forEach(res => {
                const displayName = res.filename.replace(/\.md$/i, '');
                const escapedSnippet = escapeHtml(res.snippet);
                const reg = new RegExp('(' + escapeRegex(query) + ')', 'gi');
                const highlightedSnippet = escapedSnippet.replace(reg, '<mark>$1</mark>');

                html += `
                    <div class="search-result-item" data-path="${escapeHtml(res.path)}">
                        <div class="search-result-title">
                            <svg class="item-icon" style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                            </svg>
                            <span>${escapeHtml(displayName)}</span>
                        </div>
                        <div class="search-result-path">${escapeHtml(res.path)}${res.line ? ` (řádek ${res.line})` : ''}</div>
                        <div class="search-result-snippet">${highlightedSnippet}</div>
                    </div>`;
            });

            searchResultsContainer.innerHTML = html;

            searchResultsContainer.querySelectorAll('.search-result-item').forEach(item => {
                item.addEventListener('click', () => {
                    const path = item.dataset.path;
                    if (sidebar.classList.contains('open')) {
                        toggleSidebar();
                    }
                    selectFile(path);
                });
            });
        } catch (err) {
            console.error('Search error:', err);
            searchResultsContainer.innerHTML = `
                <div style="padding: 16px; color: #eb5757; font-size: 0.82rem; text-align: center;">
                    Chyba při vyhledávání: ${escapeHtml(err.message)}
                </div>`;
        }
    }

    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Select and load note
    async function selectFile(filePath) {
        currentFilePath = filePath;
        highlightActiveFile(filePath);

        noteHeader.style.display = 'flex';
        notePathSpan.textContent = filePath;

        try {
            const response = await fetch(`file.php?f=${encodeURIComponent(filePath)}&vault=${encodeURIComponent(currentVault)}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            // Capture last_modified header for conflict detection
            const mtimeHeader = response.headers.get('X-Last-Modified');
            currentFileLastModified = mtimeHeader ? parseInt(mtimeHeader, 10) : 0;

            const isMarkdown = filePath.toLowerCase().endsWith('.md');
            if (isMarkdown) {
                rawNoteContent = await response.text();

                if (isEditingMode && isEditAllowed) {
                    showEditorView();
                } else {
                    showPreviewView();
                }
            } else if (/\.svg$/i.test(filePath)) {
                hideEditorAndPreview();
                const svgText = await response.text();
                noteView.innerHTML = `<div class="svg-container" style="background: #111114; border: 1px solid var(--border-color); border-radius: 8px; padding: 24px; text-align: center; overflow: auto;">${svgText}</div>`;
                noteView.style.display = 'block';
                emptyState.style.display = 'none';
            } else if (/\.(jpg|jpeg|png|gif|webp)$/i.test(filePath)) {
                hideEditorAndPreview();
                const imgUrl = `file.php?f=${encodeURIComponent(filePath)}&vault=${encodeURIComponent(currentVault)}`;
                noteView.innerHTML = `<img src="${imgUrl}" alt="${escapeHtml(filePath)}" style="max-width: 100%; height: auto; border-radius: 8px;">`;
                noteView.style.display = 'block';
                emptyState.style.display = 'none';
            } else if (/\.(m4a|mp3|ogg|wav)$/i.test(filePath)) {
                hideEditorAndPreview();
                const audioUrl = `file.php?f=${encodeURIComponent(filePath)}&vault=${encodeURIComponent(currentVault)}`;
                noteView.innerHTML = `<audio controls src="${audioUrl}" style="width: 100%; margin-top: 20px;"></audio>`;
                noteView.style.display = 'block';
                emptyState.style.display = 'none';
            } else {
                hideEditorAndPreview();
                const text = await response.text();
                noteView.innerHTML = `<pre><code>${escapeHtml(text)}</code></pre>`;
                noteView.style.display = 'block';
                emptyState.style.display = 'none';
            }
        } catch (error) {
            console.error('Error fetching file:', error);
            noteView.innerHTML = `<div style="color: #eb5757; padding: 20px;">Nepodařilo se načíst soubor: ${escapeHtml(error.message)}</div>`;
            noteView.style.display = 'block';
            emptyState.style.display = 'none';
        }
    }

    function hideEditorAndPreview() {
        noteView.style.display = 'none';
        if (noteEditor) noteEditor.style.display = 'none';
        if (editorToolbar) editorToolbar.style.display = 'none';
        if (btnSaveNote) btnSaveNote.style.display = 'none';
    }

    function resolveNotePath(name) {
        if (!name) return null;
        let clean = name.trim().replace(/^\[\[/, '').replace(/\]\]$/, '');
        if (!clean.toLowerCase().endsWith('.md')) clean += '.md';
        const targetBase = clean.split('/').pop().toLowerCase();

        function search(items) {
            for (const item of items) {
                if (item.type === 'file') {
                    if (item.path.toLowerCase() === clean.toLowerCase() || item.name.toLowerCase() === targetBase) {
                        return item.path;
                    }
                } else if (item.children) {
                    const f = search(item.children);
                    if (f) return f;
                }
            }
            return null;
        }
        return search(allFilesData) || clean;
    }

    window.openWikilink = function(target) {
        const fullPath = resolveNotePath(target);
        if (fullPath) {
            if (sidebar.classList.contains('open')) {
                toggleSidebar();
            }
            selectFile(fullPath);
        }
    };

    function showPreviewView() {
        isEditingMode = false;
        if (btnToggleView) btnToggleView.classList.add('active');
        if (btnToggleEdit) btnToggleEdit.classList.remove('active');

        if (noteEditor) noteEditor.style.display = 'none';
        if (editorToolbar) editorToolbar.style.display = 'none';

        const parsedHtml = parseObsidianMarkdown(rawNoteContent, currentFilePath);
        noteView.innerHTML = parsedHtml;
        noteView.style.display = 'block';
        emptyState.style.display = 'none';

        if (btnSaveNote) btnSaveNote.style.display = 'none';

        // Post-render: Interactive task list checkboxes (just like Obsidian!)
        if (isEditAllowed) {
            const taskCheckboxes = noteView.querySelectorAll('.task-list-item input[type="checkbox"]');
            taskCheckboxes.forEach((cb, idx) => {
                cb.removeAttribute('disabled');
                cb.style.cursor = 'pointer';
                cb.addEventListener('change', async (e) => {
                    e.stopPropagation();
                    const isChecked = cb.checked;
                    const li = cb.closest('.task-list-item');
                    if (li) {
                        li.classList.toggle('completed', isChecked);
                    }
                    let matchIndex = 0;
                    const taskRegex = /^([ \t]*[-*]\s+\[)([ xX])(\]\s+.*)$/gm;
                    rawNoteContent = rawNoteContent.replace(taskRegex, (match, prefix, state, suffix) => {
                        if (matchIndex === idx) {
                            matchIndex++;
                            return `${prefix}${isChecked ? 'x' : ' '}${suffix}`;
                        }
                        matchIndex++;
                        return match;
                    });
                    if (noteEditor) noteEditor.value = rawNoteContent;
                    await saveCurrentNote();
                });
            });
        }

        // Post-render: Syntax highlighting
        if (typeof hljs !== 'undefined') {
            noteView.querySelectorAll('pre code').forEach(block => {
                hljs.highlightElement(block);
            });
        }

        // Post-render: Mermaid diagrams
        if (typeof mermaid !== 'undefined') {
            try {
                mermaid.run({ nodes: noteView.querySelectorAll('.mermaid') });
            } catch (e) {
                console.warn('Mermaid rendering error:', e);
            }
        }

        // Post-render: KaTeX math
        if (typeof katex !== 'undefined') {
            noteView.querySelectorAll('[data-math-block]').forEach(el => {
                try {
                    katex.render(el.getAttribute('data-math-block'), el, { displayMode: true, throwOnError: false });
                } catch (e) {
                    el.textContent = el.getAttribute('data-math-block');
                }
            });
            noteView.querySelectorAll('[data-math-inline]').forEach(el => {
                try {
                    katex.render(el.getAttribute('data-math-inline'), el, { displayMode: false, throwOnError: false });
                } catch (e) {
                    el.textContent = el.getAttribute('data-math-inline');
                }
            });
        }

        // Post-render: Transcluded Notes preview (![[Note]])
        noteView.querySelectorAll('[data-embed-note]').forEach(async (el) => {
            const embedTarget = el.getAttribute('data-embed-note');
            const targetPath = resolveNotePath(embedTarget);
            if (targetPath && targetPath !== currentFilePath) {
                try {
                    const resp = await fetch(`file.php?f=${encodeURIComponent(targetPath)}&vault=${encodeURIComponent(currentVault)}`);
                    if (resp.ok) {
                        const embedText = await resp.text();
                        el.innerHTML = parseObsidianMarkdown(embedText, targetPath);
                    } else {
                        el.innerHTML = `<em style="color: var(--text-secondary);">Poznámka "${escapeHtml(embedTarget)}" nenalezena.</em>`;
                    }
                } catch (err) {
                    el.innerHTML = `<em style="color: #eb5757;">Chyba načítání vložené poznámky.</em>`;
                }
            }
        });
    }

    function showEditorView() {
        if (!isEditAllowed) return;

        isEditingMode = true;
        if (btnToggleEdit) btnToggleEdit.classList.add('active');
        if (btnToggleView) btnToggleView.classList.remove('active');

        noteView.style.display = 'none';
        emptyState.style.display = 'none';

        noteEditor.value = rawNoteContent;
        noteEditor.style.display = 'block';
        if (editorToolbar) editorToolbar.style.display = 'flex';
        if (btnSaveNote) btnSaveNote.style.display = 'flex';

        updateSaveStatus('Uloženo', false);
    }

    // Toggle Preview / Edit Mode Tabs
    if (btnToggleView) {
        btnToggleView.addEventListener('click', () => {
            if (isUnsaved && noteEditor.style.display !== 'none') {
                rawNoteContent = noteEditor.value;
            }
            showPreviewView();
        });
    }

    if (btnToggleEdit) {
        btnToggleEdit.addEventListener('click', () => {
            showEditorView();
        });
    }

    // Textarea input tracker
    if (noteEditor) {
        noteEditor.addEventListener('input', () => {
            rawNoteContent = noteEditor.value;
            isUnsaved = true;
            updateSaveStatus('Neuložené změny', true);
        });

        noteEditor.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                saveCurrentNote();
            }
        });

        // Drag & Drop File Upload directly onto Editor Area
        noteEditor.addEventListener('dragover', (e) => {
            e.preventDefault();
            noteEditor.style.borderColor = '#7f6df2';
        });

        noteEditor.addEventListener('dragleave', (e) => {
            e.preventDefault();
            noteEditor.style.borderColor = 'transparent';
        });

        noteEditor.addEventListener('drop', async (e) => {
            e.preventDefault();
            noteEditor.style.borderColor = 'transparent';

            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                for (const file of e.dataTransfer.files) {
                    await handleFileUpload(file);
                }
            }
        });
    }

    if (btnSaveNote) {
        btnSaveNote.addEventListener('click', () => {
            saveCurrentNote();
        });
    }

    // Save note via AJAX with Conflict Detection
    async function saveCurrentNote(force = false) {
        if (!isEditAllowed || !currentFilePath) return;

        updateSaveStatus('Ukládám...', true);

        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('vault', currentVault);
        formData.append('csrf_token', window.CSRF_TOKEN || '');
        formData.append('f', currentFilePath);
        formData.append('content', rawNoteContent);
        formData.append('last_modified', currentFileLastModified);
        if (force) {
            formData.append('force', '1');
        }

        try {
            const response = await fetch('file.php?action=save', {
                method: 'POST',
                body: formData
            });

            if (response.status === 409) {
                // Conflict detected!
                const res = await response.json();
                updateSaveStatus('Konflikt!', true);

                const overwrite = await showCustomConfirm(
                    'Konflikt při ukládání',
                    'Soubor byl na serveru mezitím změněn jiným uživatelem nebo procesem.\nChcete své změny přesto uložit (přepsat verzi na serveru)?'
                );

                if (overwrite) {
                    await saveCurrentNote(true);
                } else {
                    const reload = await showCustomConfirm(
                        'Obnovit soubor ze serveru?',
                        'Chcete načíst novou verzi ze serveru? (Vaše neuložené změny v editoru budou zahozeny)'
                    );
                    if (reload) {
                        await selectFile(currentFilePath);
                    }
                }
                return;
            }

            const res = await response.json();
            if (res.success) {
                isUnsaved = false;
                if (res.last_modified) {
                    currentFileLastModified = res.last_modified;
                }
                updateSaveStatus('Uloženo', false);
            } else {
                updateSaveStatus('Chyba uložení!', true);
                alert(res.message || 'Nepodařilo se uložit soubor.');
            }
        } catch (error) {
            console.error('Error saving note:', error);
            updateSaveStatus('Chyba uložení!', true);
            alert('Chyba při komunikaci se serverem.');
        }
    }

    function updateSaveStatus(text, warning) {
        if (!saveStatus) return;
        saveStatus.textContent = text;
        saveStatus.style.display = 'inline';
        saveStatus.style.color = warning ? '#eb5757' : '#8e8e93';
    }

    // NATIVE FILE EXPLORER PICKERS & UPLOAD
    if (btnUploadImage && inputFileImage) {
        btnUploadImage.addEventListener('click', () => inputFileImage.click());
        inputFileImage.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFileUpload(e.target.files[0]);
                inputFileImage.value = '';
            }
        });
    }

    if (btnUploadAudio && inputFileAudio) {
        btnUploadAudio.addEventListener('click', () => inputFileAudio.click());
        inputFileAudio.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFileUpload(e.target.files[0]);
                inputFileAudio.value = '';
            }
        });
    }

    if (btnUploadFile && inputFileAny) {
        btnUploadFile.addEventListener('click', () => inputFileAny.click());
        inputFileAny.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFileUpload(e.target.files[0]);
                inputFileAny.value = '';
            }
        });
    }

    async function handleFileUpload(file) {
        if (!isEditAllowed) return;

        updateSaveStatus('Nahrávám soubor...', true);

        let targetDir = '';
        if (currentFilePath && currentFilePath.includes('/')) {
            const parts = currentFilePath.split('/');
            parts.pop();
            targetDir = parts.join('/');
        }

        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('vault', currentVault);
        formData.append('csrf_token', window.CSRF_TOKEN || '');
        formData.append('file', file);
        formData.append('targetDir', targetDir);

        try {
            const response = await fetch('file.php?action=upload', {
                method: 'POST',
                body: formData
            });

            const res = await response.json();
            if (res.success) {
                const filename = res.filename;
                insertTextAtCursor(`![[${filename}]]`);
                await saveCurrentNote();
                await loadFileTree();
            } else {
                alert(res.message || 'Nepodařilo se nahrát soubor.');
                updateSaveStatus('Chyba nahrávání!', true);
            }
        } catch (error) {
            console.error('Error uploading file:', error);
            alert('Chyba při nahrávání souboru na server.');
            updateSaveStatus('Chyba nahrávání!', true);
        }
    }

    function insertTextAtCursor(insertText) {
        if (!noteEditor) return;
        const start = noteEditor.selectionStart || 0;
        const end = noteEditor.selectionEnd || 0;
        const text = noteEditor.value;

        noteEditor.value = text.substring(0, start) + insertText + text.substring(end);
        rawNoteContent = noteEditor.value;
        isUnsaved = true;
        noteEditor.focus();
        noteEditor.setSelectionRange(start + insertText.length, start + insertText.length);
        updateSaveStatus('Neuložené změny', true);
    }

    // Editor formatting toolbar buttons
    if (editorToolbar) {
        editorToolbar.querySelectorAll('.tool-btn[data-tool]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tool = btn.dataset.tool;
                await insertFormatting(tool);
            });
        });

        const toolColorSelect = document.getElementById('tool-color-select');
        if (toolColorSelect) {
            toolColorSelect.addEventListener('change', () => {
                const color = toolColorSelect.value;
                if (!color || !noteEditor) return;
                const start = noteEditor.selectionStart;
                const end = noteEditor.selectionEnd;
                const text = noteEditor.value;
                const selected = text.substring(start, end);
                const replacement = `<font color="${color}">${selected || 'Barevný text'}</font>`;
                noteEditor.value = text.substring(0, start) + replacement + text.substring(end);
                rawNoteContent = noteEditor.value;
                isUnsaved = true;
                updateSaveStatus('Neuložené změny', true);
                noteEditor.focus();
                toolColorSelect.value = '';
            });
        }
    }

    async function insertFormatting(tool) {
        if (!noteEditor) return;

        const start = noteEditor.selectionStart;
        const end = noteEditor.selectionEnd;
        const text = noteEditor.value;
        const selected = text.substring(start, end);

        let replacement = '';
        let cursorOffset = 0;

        switch (tool) {
            case 'bold':
                replacement = `**${selected || 'tučný text'}**`;
                cursorOffset = 2;
                break;
            case 'italic':
                replacement = `*${selected || 'kurzíva'}*`;
                cursorOffset = 1;
                break;
            case 'h1':
                replacement = `# ${selected || 'Nadpis 1'}`;
                cursorOffset = 2;
                break;
            case 'h2':
                replacement = `## ${selected || 'Nadpis 2'}`;
                cursorOffset = 3;
                break;
            case 'highlight':
                replacement = `==${selected || 'zvýrazněný text'}==`;
                cursorOffset = 2;
                break;
            case 'strike':
                replacement = `~~${selected || 'přeškrtnutý text'}~~`;
                cursorOffset = 2;
                break;
            case 'wikilink':
                replacement = `[[${selected || 'Název poznámky'}]]`;
                cursorOffset = 2;
                break;
            case 'callout':
                replacement = `\n> [!NOTE] Poznámka\n> ${selected || 'Text poznámky...'}\n`;
                cursorOffset = 18;
                break;
            case 'table':
                replacement = `\n| Záhlaví 1 | Záhlaví 2 |\n| :--- | :--- |\n| ${selected || 'Položka 1'} | Položka 2 |\n`;
                cursorOffset = replacement.length;
                break;
            case 'hr':
                replacement = `\n---\n`;
                cursorOffset = replacement.length;
                break;
            case 'task':
                replacement = `- [ ] ${selected || 'Nová úloha'}`;
                cursorOffset = 6;
                break;
        }

        if (replacement) {
            noteEditor.value = text.substring(0, start) + replacement + text.substring(end);
            rawNoteContent = noteEditor.value;
            isUnsaved = true;
            updateSaveStatus('Neuložené změny', true);

            noteEditor.focus();
            noteEditor.setSelectionRange(start + cursorOffset, start + replacement.length - cursorOffset);
        }
    }

    // Move Item Prompt (via Modal Select)
    async function promptMoveItem(sourcePath) {
        if (!isEditAllowed || !sourcePath) return;

        const options = [
            { value: '', label: '(Kořenový adresář Vaultu)' }
        ];

        getAllFolders(allFilesData, options);

        const targetFolder = await showCustomSelectPrompt(
            'Přesunout položku',
            `Vyberte cílovou složku pro "${sourcePath}":`,
            options
        );

        if (targetFolder !== null) {
            await moveItem(sourcePath, targetFolder);
        }
    }

    // Execute Move AJAX request
    async function moveItem(sourcePath, targetFolder) {
        if (!isEditAllowed || !sourcePath) return;

        const formData = new FormData();
        formData.append('action', 'move');
        formData.append('vault', currentVault);
        formData.append('csrf_token', window.CSRF_TOKEN || '');
        formData.append('sourcePath', sourcePath);
        formData.append('targetFolder', targetFolder);

        try {
            const response = await fetch('file.php?action=move', {
                method: 'POST',
                body: formData
            });

            const res = await response.json();
            if (res.success) {
                if (currentFilePath === sourcePath) {
                    currentFilePath = res.newPath;
                }
                await loadFileTree();
            } else {
                alert(res.message || 'Nepodařilo se přesunout položku.');
            }
        } catch (error) {
            console.error('Error moving item:', error);
            alert('Chyba při přesouvání souboru.');
        }
    }

    // Create New File
    if (btnNewNote) {
        btnNewNote.addEventListener('click', () => {
            createNewFile('');
        });
    }

    async function createNewFile(parentFolder = '') {
        if (!isEditAllowed) return;

        const name = await showCustomPrompt('Nová poznámka', '', 'Zadejte název poznámky (např. MojePoznamka)...');
        if (!name) return;

        const formData = new FormData();
        formData.append('action', 'create_file');
        formData.append('vault', currentVault);
        formData.append('csrf_token', window.CSRF_TOKEN || '');
        formData.append('name', name);
        formData.append('parent', parentFolder);

        try {
            const response = await fetch('file.php?action=create_file', {
                method: 'POST',
                body: formData
            });

            const res = await response.json();
            if (res.success) {
                await loadFileTree();
                selectFile(res.path);
            } else {
                alert(res.message || 'Nepodařilo se vytvořit soubor.');
            }
        } catch (error) {
            alert('Chyba při vytváření souboru.');
        }
    }

    // Create New Folder
    if (btnNewFolder) {
        btnNewFolder.addEventListener('click', async () => {
            if (!isEditAllowed) return;

            const name = await showCustomPrompt('Nová složka', '', 'Zadejte název složky...');
            if (!name) return;

            const formData = new FormData();
            formData.append('action', 'create_folder');
            formData.append('vault', currentVault);
            formData.append('csrf_token', window.CSRF_TOKEN || '');
            formData.append('name', name);

            try {
                const response = await fetch('file.php?action=create_folder', {
                    method: 'POST',
                    body: formData
                });

                const res = await response.json();
                if (res.success) {
                    await loadFileTree();
                } else {
                    alert(res.message || 'Nepodařilo se vytvořit složku.');
                }
            } catch (error) {
                alert('Chyba při vytváření složky.');
            }
        });
    }

    // Delete item
    async function deleteItem(itemPath) {
        if (!isEditAllowed || !itemPath) return;

        const confirmed = await showCustomConfirm('Smazat položku?', `Opravdu chcete smazat "${itemPath}"?`);
        if (!confirmed) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('vault', currentVault);
        formData.append('csrf_token', window.CSRF_TOKEN || '');
        formData.append('path', itemPath);

        try {
            const response = await fetch('file.php?action=delete', {
                method: 'POST',
                body: formData
            });

            const res = await response.json();
            if (res.success) {
                // Clear active note if deleted item IS active note OR contains active note inside folder
                if (currentFilePath && (currentFilePath === itemPath || currentFilePath.startsWith(itemPath + '/'))) {
                    currentFilePath = null;
                    hideEditorAndPreview();
                    noteHeader.style.display = 'none';
                    emptyState.style.display = 'flex';
                }
                await loadFileTree();
            } else {
                alert(res.message || 'Nepodařilo se smazat položku.');
            }
        } catch (error) {
            alert('Chyba při mazání.');
        }
    }

    // Rename item
    async function renameItem(oldPath, oldName) {
        if (!isEditAllowed || !oldPath) return;

        const newName = await showCustomPrompt('Přejmenovat položku', oldName, 'Zadejte nový název...');
        if (!newName || newName === oldName) return;

        const formData = new FormData();
        formData.append('action', 'rename');
        formData.append('vault', currentVault);
        formData.append('csrf_token', window.CSRF_TOKEN || '');
        formData.append('oldPath', oldPath);
        formData.append('newName', newName);

        try {
            const response = await fetch('file.php?action=rename', {
                method: 'POST',
                body: formData
            });

            const res = await response.json();
            if (res.success) {
                if (currentFilePath === oldPath) {
                    currentFilePath = res.newPath;
                }
                await loadFileTree();
            } else {
                alert(res.message || 'Nepodařilo se přejmenovat položku.');
            }
        } catch (error) {
            alert('Chyba při přejmenování.');
        }
    }

    function getFileNameWithoutExtension(filePath) {
        const parts = filePath.split('/');
        const basename = parts[parts.length - 1];
        return basename.replace(/\.[^/.]+$/, '');
    }

    function resolveEmbedPath(targetFileName, currentNotePath) {
        if (targetFileName.includes('/')) {
            return targetFileName;
        }

        const currentDirParts = currentNotePath.split('/');
        currentDirParts.pop();
        const currentDir = currentDirParts.join('/');

        if (currentDir) {
            return `${currentDir}/${targetFileName}`;
        }
        return targetFileName;
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // === COMPREHENSIVE OBSIDIAN MARKDOWN PARSER ===
    function parseObsidianMarkdown(markdown, filePath) {
        const noteTitle = getFileNameWithoutExtension(filePath);
        // Remove Obsidian comments %%...%%
        markdown = markdown.replace(/%%[\s\S]*?%%/g, '');

        const lines = markdown.replace(/\r\n/g, '\n').split('\n');
        let htmlBlocks = [];
        htmlBlocks.push(`<h1>${escapeHtml(noteTitle)}</h1>`);

        // Collect footnotes
        let footnotes = {};
        lines.forEach(line => {
            const fnMatch = line.match(/^\[\^(\w+)\]:\s*(.+)$/);
            if (fnMatch) {
                footnotes[fnMatch[1]] = fnMatch[2];
            }
        });

        let paragraphLines = [];
        let i = 0;

        function flushParagraph() {
            if (paragraphLines.length > 0) {
                const pText = paragraphLines.join('<br>');
                const formattedP = inlineFormat(pText, filePath);
                if (formattedP.trim()) {
                    htmlBlocks.push(`<p>${formattedP}</p>`);
                }
                paragraphLines = [];
            }
        }

        while (i < lines.length) {
            const rawLine = lines[i];
            const trimmed = rawLine.trim();

            // Skip footnote definitions (rendered at bottom)
            if (/^\[\^\w+\]:\s*.+$/.test(trimmed)) {
                i++;
                continue;
            }

            // --- Raw SVG blocks (<svg ...> ... </svg>) ---
            if (trimmed.startsWith('<svg') || /<svg[^>]*>/i.test(trimmed)) {
                flushParagraph();
                let svgLines = [];
                while (i < lines.length) {
                    svgLines.push(lines[i]);
                    if (lines[i].includes('</svg>')) {
                        i++;
                        break;
                    }
                    i++;
                }
                htmlBlocks.push(`<div class="svg-inline-container">${svgLines.join('\n')}</div>`);
                continue;
            }

            // --- Fenced code blocks (``` or ~~~) ---
            if (/^```(\w*)/.test(trimmed) || /^~~~(\w*)/.test(trimmed)) {
                flushParagraph();
                const fence = trimmed.startsWith('```') ? '```' : '~~~';
                const langMatch = trimmed.match(/^(?:```|~~~)(\w*)/);
                const lang = langMatch ? langMatch[1] : '';
                let codeLines = [];
                i++;
                while (i < lines.length && !lines[i].trim().startsWith(fence)) {
                    codeLines.push(lines[i]);
                    i++;
                }
                i++; // skip closing fence
                const codeContent = escapeHtml(codeLines.join('\n'));

                if (lang === 'mermaid') {
                    htmlBlocks.push(`<div class="mermaid-container"><pre class="mermaid">${codeLines.join('\n')}</pre></div>`);
                } else {
                    const langLabel = lang ? `<span class="code-lang-label">${escapeHtml(lang)}</span>` : '';
                    const langClass = lang ? ` class="language-${escapeHtml(lang)}"` : '';
                    htmlBlocks.push(`<div class="code-block-wrapper">${langLabel}<button class="code-copy-btn" onclick="navigator.clipboard.writeText(this.parentElement.querySelector('code').textContent).then(()=>{this.textContent='Zkopírováno!';setTimeout(()=>this.textContent='Kopírovat',1500)})">Kopírovat</button><pre><code${langClass}>${codeContent}</code></pre></div>`);
                }
                continue;
            }

            // --- Math block $$ ---
            if (trimmed === '$$') {
                flushParagraph();
                let mathLines = [];
                i++;
                while (i < lines.length && lines[i].trim() !== '$$') {
                    mathLines.push(lines[i]);
                    i++;
                }
                i++; // skip closing $$
                const mathContent = mathLines.join('\n');
                htmlBlocks.push(`<div class="math-block" data-math-block="${escapeHtml(mathContent)}"></div>`);
                continue;
            }

            // --- Horizontal rules ---
            if (/^(---+|\*\*\*+|___+)$/.test(trimmed)) {
                flushParagraph();
                htmlBlocks.push('<hr>');
                i++;
                continue;
            }

            // --- Callouts: > [!TYPE] title ---
            const calloutMatch = trimmed.match(/^>\s*\[!([\w-]+)\]\s*(.*)?$/);
            if (calloutMatch) {
                flushParagraph();
                const calloutType = calloutMatch[1].toLowerCase();
                const calloutTitle = calloutMatch[2] || calloutType.charAt(0).toUpperCase() + calloutType.slice(1);
                let contentLines = [];
                i++;
                while (i < lines.length && /^>/.test(lines[i])) {
                    contentLines.push(lines[i].replace(/^>\s?/, ''));
                    i++;
                }
                const calloutIcons = {
                    'note': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
                    'info': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
                    'tip': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
                    'hint': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
                    'important': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
                    'warning': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                    'caution': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                    'danger': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                    'bug': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="8" height="14" x="8" y="6" rx="4"/><path d="m19 7-3 2"/><path d="m5 7 3 2"/><path d="m19 19-3-2"/><path d="m5 19 3-2"/><path d="M20 13h-4"/><path d="M4 13h4"/><path d="m10 4 1 2"/><path d="m14 4-1 2"/></svg>',
                    'example': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
                    'quote': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/></svg>',
                    'cite': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/></svg>',
                    'question': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                    'faq': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                    'success': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                    'check': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                    'done': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                    'failure': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                    'fail': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                    'missing': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                    'abstract': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
                    'summary': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
                    'tldr': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
                    'todo': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><polyline points="9 11 12 14 22 4"/></svg>'
                };
                const icon = calloutIcons[calloutType] || calloutIcons['note'];
                const contentHtml = contentLines.map(l => `<p>${inlineFormat(escapeHtml(l), filePath)}</p>`).join('\n');
                htmlBlocks.push(`<div class="callout callout-${escapeHtml(calloutType)}">
                    <div class="callout-title"><span class="callout-title-icon">${icon}</span> ${escapeHtml(calloutTitle)}</div>
                    <div class="callout-content">${contentHtml}</div>
                </div>`);
                continue;
            }

            // --- Regular blockquotes ---
            if (/^>\s?/.test(trimmed)) {
                flushParagraph();
                let quoteLines = [];
                while (i < lines.length && /^>/.test(lines[i].trim())) {
                    quoteLines.push(lines[i].trim().replace(/^>\s?/, ''));
                    i++;
                }
                const quoteHtml = quoteLines.map(l => `<p>${inlineFormat(escapeHtml(l), filePath)}</p>`).join('\n');
                htmlBlocks.push(`<blockquote>${quoteHtml}</blockquote>`);
                continue;
            }

            // --- Tables ---
            if (/^\|(.+)\|\s*$/.test(trimmed) && i + 1 < lines.length && /^\|[\s:|-]+\|\s*$/.test(lines[i + 1].trim())) {
                flushParagraph();
                // Parse header
                const headerCells = trimmed.split('|').filter(c => c.trim() !== '').map(c => c.trim());
                i++; // skip separator
                // Parse alignment from separator
                const sepLine = lines[i].trim();
                const sepCells = sepLine.split('|').filter(c => c.trim() !== '');
                const alignments = sepCells.map(cell => {
                    const t = cell.trim();
                    if (t.startsWith(':') && t.endsWith(':')) return 'center';
                    if (t.endsWith(':')) return 'right';
                    return 'left';
                });
                i++; // skip separator line

                let tableRows = [];
                while (i < lines.length && /^\|(.+)\|\s*$/.test(lines[i].trim())) {
                    const cells = lines[i].trim().split('|').filter(c => c.trim() !== '').map(c => c.trim());
                    tableRows.push(cells);
                    i++;
                }

                let tableHtml = '<table><thead><tr>';
                headerCells.forEach((cell, idx) => {
                    const align = alignments[idx] ? ` style="text-align:${alignments[idx]}"` : '';
                    tableHtml += `<th${align}>${inlineFormat(escapeHtml(cell), filePath)}</th>`;
                });
                tableHtml += '</tr></thead><tbody>';
                tableRows.forEach(row => {
                    tableHtml += '<tr>';
                    row.forEach((cell, idx) => {
                        const align = alignments[idx] ? ` style="text-align:${alignments[idx]}"` : '';
                        tableHtml += `<td${align}>${inlineFormat(escapeHtml(cell), filePath)}</td>`;
                    });
                    tableHtml += '</tr>';
                });
                tableHtml += '</tbody></table>';
                htmlBlocks.push(tableHtml);
                continue;
            }

            // --- Headings h1-h6 ---
            const headingMatch = rawLine.match(/^(#{1,6})\s+(.+)$/);
            if (headingMatch) {
                flushParagraph();
                const level = headingMatch[1].length;
                const text = headingMatch[2];
                htmlBlocks.push(`<h${level}>${inlineFormat(escapeHtml(text), filePath)}</h${level}>`);
                i++;
                continue;
            }

            // --- Task list items ---
            const taskMatch = trimmed.match(/^[-*]\s+\[([ xX])\]\s+(.+)$/);
            if (taskMatch) {
                flushParagraph();
                const checked = taskMatch[1] !== ' ';
                const taskText = taskMatch[2];
                const checkedAttr = checked ? ' checked disabled' : ' disabled';
                const completedClass = checked ? ' completed' : '';
                htmlBlocks.push(`<ul class="task-list"><li class="task-list-item${completedClass}"><input type="checkbox"${checkedAttr}><span class="task-text">${inlineFormat(escapeHtml(taskText), filePath)}</span></li></ul>`);
                i++;
                continue;
            }

            // --- Ordered lists ---
            const olMatch = trimmed.match(/^(\d+)\.\s+(.+)$/);
            if (olMatch) {
                flushParagraph();
                let olItems = [];
                while (i < lines.length) {
                    const olLineMatch = lines[i].trim().match(/^(\d+)\.\s+(.+)$/);
                    if (olLineMatch) {
                        olItems.push(olLineMatch[2]);
                        i++;
                    } else {
                        break;
                    }
                }
                let olHtml = '<ol>';
                olItems.forEach(item => {
                    olHtml += `<li>${inlineFormat(escapeHtml(item), filePath)}</li>`;
                });
                olHtml += '</ol>';
                htmlBlocks.push(olHtml);
                continue;
            }

            // --- Unordered lists (collect consecutive) ---
            if (/^[-*+]\s+(.+)$/.test(trimmed)) {
                flushParagraph();
                let ulItems = [];
                while (i < lines.length && /^[-*+]\s+(.+)$/.test(lines[i].trim())) {
                    const listText = lines[i].trim().replace(/^[-*+]\s+/, '');
                    ulItems.push(listText);
                    i++;
                }
                let ulHtml = '<ul>';
                ulItems.forEach(item => {
                    ulHtml += `<li>${inlineFormat(escapeHtml(item), filePath)}</li>`;
                });
                ulHtml += '</ul>';
                htmlBlocks.push(ulHtml);
                continue;
            }

            // --- Standalone embed on its own line ---
            const wikilinkMatch = trimmed.match(/^!\[\[([^\]]+)\]\]$/);
            if (wikilinkMatch) {
                flushParagraph();
                const embedHtml = renderObsidianEmbed(wikilinkMatch[1], filePath);
                htmlBlocks.push(embedHtml);
                i++;
                continue;
            }

            // --- Standard markdown image on its own line ---
            const standardImgMatch = trimmed.match(/^!\[(.*?)\]\((.*?)\)$/);
            if (standardImgMatch) {
                flushParagraph();
                const alt = standardImgMatch[1];
                const url = standardImgMatch[2];
                const fileUrl = url.startsWith('http') ? url : `file.php?f=${encodeURIComponent(resolveEmbedPath(url, filePath))}&vault=${encodeURIComponent(currentVault)}`;
                htmlBlocks.push(`<img src="${fileUrl}" alt="${escapeHtml(alt)}" style="max-width: 100%; height: auto; border-radius: 8px;">`);
                i++;
                continue;
            }

            // --- Empty line ---
            if (trimmed === '') {
                flushParagraph();
                i++;
                continue;
            }

            // --- Regular text line (collect into paragraph) ---
            paragraphLines.push(escapeHtml(rawLine));
            i++;
        }

        flushParagraph();

        // Append footnotes section if any
        const footnoteKeys = Object.keys(footnotes);
        if (footnoteKeys.length > 0) {
            let fnHtml = '<div class="footnotes-section"><hr><h4>Poznámky pod čarou</h4>';
            footnoteKeys.forEach(key => {
                fnHtml += `<div class="footnote-item" id="fn-${escapeHtml(key)}"><sup>${escapeHtml(key)}</sup> ${inlineFormat(escapeHtml(footnotes[key]), filePath)}</div>`;
            });
            fnHtml += '</div>';
            htmlBlocks.push(fnHtml);
        }

        return htmlBlocks.join('\n');
    }

    // === COMPREHENSIVE INLINE FORMATTER ===
    function inlineFormat(text, currentFilePath) {
        if (!text) return '';

        // Obsidian embeds ![[...]]
        text = text.replace(/!\[\[([^\]]+)\]\]/g, (match, embedPath) => {
            return renderObsidianEmbed(embedPath, currentFilePath);
        });

        // Standard markdown images ![alt](url)
        text = text.replace(/!\[(.*?)\]\((.*?)\)/g, (match, alt, url) => {
            const fileUrl = url.startsWith('http') ? url : `file.php?f=${encodeURIComponent(resolveEmbedPath(url, currentFilePath))}&vault=${encodeURIComponent(currentVault)}`;
            return `<img src="${fileUrl}" alt="${escapeHtml(alt)}" style="max-width: 100%; height: auto; border-radius: 8px;">`;
        });

        // Wiki links [[note]] and [[note|display]]
        text = text.replace(/\[\[([^\]]+?)\|([^\]]+?)\]\]/g, (match, target, display) => {
            const cleanTarget = target.trim();
            return `<a class="obsidian-wikilink" href="#" onclick="event.preventDefault(); window.openWikilink('${escapeHtml(cleanTarget)}')" title="${escapeHtml(cleanTarget)}">${escapeHtml(display)}</a>`;
        });
        text = text.replace(/\[\[([^\]|]+?)\]\]/g, (match, target) => {
            const cleanTarget = target.trim();
            return `<a class="obsidian-wikilink" href="#" onclick="event.preventDefault(); window.openWikilink('${escapeHtml(cleanTarget)}')" title="${escapeHtml(cleanTarget)}">${escapeHtml(cleanTarget)}</a>`;
        });

        // Custom Color Syntax: [text]{color: red} or [text]{#ff5555}
        text = text.replace(/\[([^\]]+)\]\{color:\s*([^}]+)\}/gi, '<span style="color: $2;">$1</span>');
        text = text.replace(/\[([^\]]+)\]\{(#(?:[0-9a-fA-F]{3}){1,2}|[a-zA-Z]+)\}/g, '<span style="color: $2;">$1</span>');

        // Standard markdown links [text](url)
        text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, linkText, url) => {
            const target = url.startsWith('http') ? ' target="_blank" rel="noopener"' : '';
            return `<a href="${url}"${target} style="color: var(--accent-color); text-decoration: underline;">${linkText}</a>`;
        });

        // Footnote references [^1]
        text = text.replace(/\[\^(\w+)\]/g, (match, key) => {
            return `<a class="footnote-ref" href="#fn-${escapeHtml(key)}" title="Poznámka pod čarou ${escapeHtml(key)}">[${escapeHtml(key)}]</a>`;
        });

        // Inline math $...$
        text = text.replace(/\$([^$]+?)\$/g, (match, math) => {
            return `<span class="math-inline" data-math-inline="${escapeHtml(math)}"></span>`;
        });

        // Highlight ==text==
        text = text.replace(/==(.*?)==/g, '<mark class="obsidian-highlight">$1</mark>');

        // Strikethrough ~~text~~
        text = text.replace(/~~(.*?)~~/g, '<del>$1</del>');

        // Bold **text**
        text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

        // Italic *text* (but not inside ** which was already processed)
        text = text.replace(/(?<!\*)\*([^*]+)\*(?!\*)/g, '<em>$1</em>');

        // Inline code `text`
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Tags #tag (but not heading markers, not hex colors)
        text = text.replace(/(^|\s)#([a-zA-Z\u00C0-\u024F][a-zA-Z0-9\u00C0-\u024F_/-]*)/g, (match, prefix, tag) => {
            return `${prefix}<span class="obsidian-tag" onclick="if(typeof filterByTag==='function') filterByTag('${escapeHtml(tag)}')">#${escapeHtml(tag)}</span>`;
        });

        // Allowed inline HTML: <font color="..."> </font>, <mark>, <kbd>, <u>, <sub>, <sup>, <br>, <s>
        // Unescape font tags with any attributes
        text = text.replace(/&lt;font(\s+[^&>]*)?&gt;/gi, (match, attrs) => {
            if (!attrs) return '<font>';
            return `<font${attrs.replace(/&quot;/g, '"')}>`;
        });
        text = text.replace(/&lt;\/font&gt;/gi, '</font>');

        // Unescape span tags with style or class
        text = text.replace(/&lt;span(\s+[^&>]*)?&gt;/gi, (match, attrs) => {
            if (!attrs) return '<span>';
            return `<span${attrs.replace(/&quot;/g, '"')}>`;
        });
        text = text.replace(/&lt;\/span&gt;/gi, '</span>');

        // Unescape div/p with style
        text = text.replace(/&lt;div(\s+[^&>]*)?&gt;/gi, (match, attrs) => {
            if (!attrs) return '<div>';
            return `<div${attrs.replace(/&quot;/g, '"')}>`;
        });
        text = text.replace(/&lt;\/div&gt;/gi, '</div>');

        // Unescape basic inline HTML
        text = text.replace(/&lt;mark&gt;/gi, '<mark>');
        text = text.replace(/&lt;\/mark&gt;/gi, '</mark>');
        text = text.replace(/&lt;kbd&gt;/gi, '<kbd>');
        text = text.replace(/&lt;\/kbd&gt;/gi, '</kbd>');
        text = text.replace(/&lt;u&gt;/gi, '<u>');
        text = text.replace(/&lt;\/u&gt;/gi, '</u>');
        text = text.replace(/&lt;sub&gt;/gi, '<sub>');
        text = text.replace(/&lt;\/sub&gt;/gi, '</sub>');
        text = text.replace(/&lt;sup&gt;/gi, '<sup>');
        text = text.replace(/&lt;\/sup&gt;/gi, '</sup>');
        text = text.replace(/&lt;br\s*\/?&gt;/gi, '<br>');
        text = text.replace(/&lt;s&gt;/gi, '<s>');
        text = text.replace(/&lt;\/s&gt;/gi, '</s>');

        // Unescape inline SVG elements
        text = text.replace(/&lt;(\/?(?:svg|path|rect|circle|line|polygon|polyline|ellipse|text|g|defs|linearGradient|radialGradient|stop|clipPath|mask|use)[^&>]*)&gt;/gi, (match, inner) => {
            return `<${inner.replace(/&quot;/g, '"')}>`;
        });

        return text;
    }

    function renderObsidianEmbed(rawEmbedText, currentFilePath) {
        let cleanName = rawEmbedText.trim();
        let widthStyle = '';
        let altText = '';

        if (cleanName.includes('|')) {
            const parts = cleanName.split('|');
            cleanName = parts[0].trim();
            const param = parts[1].trim();

            if (/^\d+(x\d+)?$/i.test(param)) {
                const width = param.split(/x/i)[0];
                widthStyle = `width: ${width}px; max-width: 100%;`;
            } else {
                altText = param;
            }
        }

        const resolvedPath = resolveEmbedPath(cleanName, currentFilePath);
        const fileUrl = `file.php?f=${encodeURIComponent(resolvedPath)}&vault=${encodeURIComponent(currentVault)}`;
        const ext = cleanName.split('.').pop().toLowerCase();
        const styleAttr = widthStyle ? `style="${widthStyle} height: auto; border-radius: 8px;"` : `style="max-width: 100%; height: auto; border-radius: 8px;"`;

        // Transcluded note preview (![[Note]] or ![[Note.md]])
        if (ext === 'md' || !cleanName.includes('.')) {
            const noteName = cleanName.replace(/\.md$/i, '');
            return `<div class="embedded-note">
                <div class="embedded-note-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 4px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><a class="obsidian-wikilink" href="#" onclick="event.preventDefault(); window.openWikilink('${escapeHtml(noteName)}')">${escapeHtml(noteName)}</a></div>
                <div class="embedded-note-preview" data-embed-note="${escapeHtml(noteName)}"><em>Načítání vložené poznámky...</em></div>
            </div>`;
        }

        if (['m4a', 'mp3', 'ogg', 'wav', 'aac', 'flac'].includes(ext)) {
            return `<audio controls src="${fileUrl}" style="max-width: 100%;"></audio>`;
        }

        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) {
            return `<img src="${fileUrl}" alt="${escapeHtml(altText || cleanName)}" ${styleAttr}>`;
        }

        if (['mp4', 'webm'].includes(ext)) {
            return `<video controls src="${fileUrl}" style="max-width: 100%; height: auto; border-radius: 8px;"></video>`;
        }

        return `<a href="${fileUrl}" target="_blank" style="color: var(--accent-color);">${escapeHtml(cleanName)}</a>`;
    }

    // Helper: Filter sidebar tree by tag
    window.filterByTag = function(tag) {
        if (!searchInput) return;
        searchInput.value = `#${tag}`;
        searchInput.dispatchEvent(new Event('input'));
        if (!sidebar.classList.contains('open')) {
            toggleSidebar();
        }
    };

    // === MOBILE BOTTOM NAVIGATION BAR ===
    const mBtnFiles = document.getElementById('m-btn-files');
    const mBtnSearch = document.getElementById('m-btn-search');
    const mBtnGraph = document.getElementById('m-btn-graph');
    const mBtnMode = document.getElementById('m-btn-mode');
    const mBtnNewNote = document.getElementById('m-btn-new-note');

    if (mBtnFiles) {
        mBtnFiles.addEventListener('click', () => {
            toggleSidebar();
        });
    }

    if (mBtnSearch) {
        mBtnSearch.addEventListener('click', () => {
            if (!sidebar.classList.contains('open')) {
                toggleSidebar();
            }
            setTimeout(() => {
                if (searchInput) searchInput.focus();
            }, 300);
        });
    }

    if (mBtnMode) {
        mBtnMode.addEventListener('click', () => {
            if (!currentFilePath) return;
            if (isEditingMode) {
                showPreviewView();
            } else if (isEditAllowed) {
                showEditorView();
            }
        });
    }

    if (mBtnNewNote) {
        mBtnNewNote.addEventListener('click', () => {
            createNewFile('');
        });
    }

    // === OBSIDIAN INTERACTIVE GRAPH VIEW ===
    const btnOpenGraph = document.getElementById('btn-open-graph');
    const graphModal = document.getElementById('graph-modal');
    const graphBtnClose = document.getElementById('graph-btn-close');
    const graphBtnFit = document.getElementById('graph-btn-fit');
    const graphSearch = document.getElementById('graph-search');
    const graphCanvas = document.getElementById('graph-canvas');
    const graphStats = document.getElementById('graph-stats');

    let graphData = { nodes: [], links: [] };
    let graphAnimationId = null;
    let graphTransform = { x: 0, y: 0, k: 1 };
    let isDraggingGraph = false;
    let draggedNode = null;
    let dragStartPos = { x: 0, y: 0 };
    let hoveredNode = null;
    let graphFilterQuery = '';

    if (btnOpenGraph) {
        btnOpenGraph.addEventListener('click', openGraphView);
    }
    if (mBtnGraph) {
        mBtnGraph.addEventListener('click', openGraphView);
    }
    if (graphBtnClose) {
        graphBtnClose.addEventListener('click', closeGraphView);
    }
    if (graphBtnFit) {
        graphBtnFit.addEventListener('click', resetGraphView);
    }
    if (graphSearch) {
        graphSearch.addEventListener('input', (e) => {
            graphFilterQuery = e.target.value.toLowerCase().trim();
        });
    }

    // Keyboard shortcut Ctrl+G for Graph View
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'g') {
            e.preventDefault();
            if (graphModal.style.display === 'flex') {
                closeGraphView();
            } else {
                openGraphView();
            }
        }
        if (e.key === 'Escape' && graphModal.style.display === 'flex') {
            closeGraphView();
        }
    });

    async function openGraphView() {
        graphModal.style.display = 'flex';
        resizeGraphCanvas();

        try {
            const resp = await fetch(`file.php?action=graph_data&vault=${encodeURIComponent(currentVault)}`);
            if (!resp.ok) throw new Error('Nepodařilo se načíst data grafu.');
            const data = await resp.json();

            if (data.success) {
                initGraphSimulation(data.nodes, data.links);
            }
        } catch (err) {
            console.error('Graph data error:', err);
        }
    }

    function closeGraphView() {
        graphModal.style.display = 'none';
        if (graphAnimationId) {
            cancelAnimationFrame(graphAnimationId);
            graphAnimationId = null;
        }
    }

    function resizeGraphCanvas() {
        if (!graphCanvas) return;
        const rect = graphCanvas.parentElement.getBoundingClientRect();
        graphCanvas.width = rect.width * (window.devicePixelRatio || 1);
        graphCanvas.height = rect.height * (window.devicePixelRatio || 1);
        graphCanvas.style.width = `${rect.width}px`;
        graphCanvas.style.height = `${rect.height}px`;
    }

    window.addEventListener('resize', () => {
        if (graphModal && graphModal.style.display === 'flex') {
            resizeGraphCanvas();
        }
    });

    function initGraphSimulation(rawNodes, rawLinks) {
        const width = graphCanvas.parentElement.clientWidth;
        const height = graphCanvas.parentElement.clientHeight;

        graphTransform = { x: width / 2, y: height / 2, k: 1 };

        // Position nodes randomly in a disk around center
        const nodeMap = new Map();
        const nodes = rawNodes.map((n, i) => {
            const angle = (i / rawNodes.length) * 2 * Math.PI;
            const r = 80 + Math.random() * Math.min(width, height) * 0.3;
            const node = {
                ...n,
                x: Math.cos(angle) * r,
                y: Math.sin(angle) * r,
                vx: 0,
                vy: 0,
                radius: Math.max(4, Math.min(12, 4 + (n.size || 1) * 1.5)),
                isCurrent: (currentFilePath && (n.path === currentFilePath || currentFilePath.endsWith(n.path)))
            };
            nodeMap.set(n.id, node);
            return node;
        });

        // Resolve link references to node objects
        const links = [];
        rawLinks.forEach(l => {
            const s = nodeMap.get(l.source);
            const t = nodeMap.get(l.target);
            if (s && t) {
                links.push({ source: s, target: t });
            }
        });

        graphData = { nodes, links };
        if (graphStats) {
            graphStats.textContent = `${nodes.length} poznámek, ${links.length} vazeb`;
        }

        setupGraphInteractions();
        runGraphSimulation();
    }

    function resetGraphView() {
        const width = graphCanvas.parentElement.clientWidth;
        const height = graphCanvas.parentElement.clientHeight;
        graphTransform = { x: width / 2, y: height / 2, k: 1 };
        if (graphSearch) {
            graphSearch.value = '';
            graphFilterQuery = '';
        }
    }

    function setupGraphInteractions() {
        let isPointerDown = false;
        let startPointer = { x: 0, y: 0 };
        let hasMoved = false;
        let initialPinchDistance = null;
        let initialScale = 1;

        const getCanvasPoint = (clientX, clientY) => {
            const rect = graphCanvas.getBoundingClientRect();
            const sx = clientX - rect.left;
            const sy = clientY - rect.top;
            return {
                x: (sx - graphTransform.x) / graphTransform.k,
                y: (sy - graphTransform.y) / graphTransform.k
            };
        };

        const findNodeAt = (wx, wy) => {
            for (let i = graphData.nodes.length - 1; i >= 0; i--) {
                const n = graphData.nodes[i];
                const dx = n.x - wx;
                const dy = n.y - wy;
                const hitRadius = (n.radius + 6) / graphTransform.k;
                if (dx * dx + dy * dy <= hitRadius * hitRadius) {
                    return n;
                }
            }
            return null;
        };

        // Pointer down
        const onDown = (clientX, clientY) => {
            isPointerDown = true;
            hasMoved = false;
            startPointer = { x: clientX, y: clientY };
            const p = getCanvasPoint(clientX, clientY);
            draggedNode = findNodeAt(p.x, p.y);
            dragStartPos = { ...p };
        };

        // Pointer move
        const onMove = (clientX, clientY) => {
            const dx = clientX - startPointer.x;
            const dy = clientY - startPointer.y;
            if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
                hasMoved = true;
            }

            if (isPointerDown) {
                if (draggedNode) {
                    const p = getCanvasPoint(clientX, clientY);
                    draggedNode.x = p.x;
                    draggedNode.y = p.y;
                    draggedNode.vx = 0;
                    draggedNode.vy = 0;
                } else {
                    graphTransform.x += dx;
                    graphTransform.y += dy;
                    startPointer = { x: clientX, y: clientY };
                }
            } else {
                const p = getCanvasPoint(clientX, clientY);
                hoveredNode = findNodeAt(p.x, p.y);
                graphCanvas.style.cursor = hoveredNode ? 'pointer' : 'grab';
            }
        };

        // Pointer up
        const onUp = (clientX, clientY) => {
            if (!hasMoved && draggedNode) {
                // Clicked a node! Open note!
                const clickedPath = draggedNode.path;
                closeGraphView();
                selectFile(clickedPath);
            }
            isPointerDown = false;
            draggedNode = null;
            initialPinchDistance = null;
        };

        // Mouse events
        graphCanvas.onmousedown = (e) => onDown(e.clientX, e.clientY);
        window.onmousemove = (e) => {
            if (graphModal.style.display === 'flex') onMove(e.clientX, e.clientY);
        };
        window.onmouseup = (e) => {
            if (graphModal.style.display === 'flex') onUp(e.clientX, e.clientY);
        };

        // Mouse Wheel Zoom
        graphCanvas.onwheel = (e) => {
            e.preventDefault();
            const rect = graphCanvas.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;

            const zoomFactor = e.deltaY < 0 ? 1.15 : 0.87;
            const newK = Math.max(0.15, Math.min(5, graphTransform.k * zoomFactor));

            graphTransform.x = mouseX - (mouseX - graphTransform.x) * (newK / graphTransform.k);
            graphTransform.y = mouseY - (mouseY - graphTransform.y) * (newK / graphTransform.k);
            graphTransform.k = newK;
        };

        // Touch events for mobile phones (Touch drag + pinch zoom)
        graphCanvas.ontouchstart = (e) => {
            if (e.touches.length === 1) {
                const t = e.touches[0];
                onDown(t.clientX, t.clientY);
            } else if (e.touches.length === 2) {
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                initialPinchDistance = Math.hypot(dx, dy);
                initialScale = graphTransform.k;
            }
        };

        graphCanvas.ontouchmove = (e) => {
            e.preventDefault();
            if (e.touches.length === 1 && initialPinchDistance === null) {
                const t = e.touches[0];
                onMove(t.clientX, t.clientY);
            } else if (e.touches.length === 2 && initialPinchDistance !== null) {
                const dx = e.touches[0].clientX - e.touches[1].clientX;
                const dy = e.touches[0].clientY - e.touches[1].clientY;
                const dist = Math.hypot(dx, dy);
                const zoomFactor = dist / initialPinchDistance;
                const newK = Math.max(0.2, Math.min(4, initialScale * zoomFactor));

                const midX = (e.touches[0].clientX + e.touches[1].clientX) / 2;
                const midY = (e.touches[0].clientY + e.touches[1].clientY) / 2;
                const rect = graphCanvas.getBoundingClientRect();
                const sx = midX - rect.left;
                const sy = midY - rect.top;

                graphTransform.x = sx - (sx - graphTransform.x) * (newK / graphTransform.k);
                graphTransform.y = sy - (sy - graphTransform.y) * (newK / graphTransform.k);
                graphTransform.k = newK;
            }
        };

        graphCanvas.ontouchend = (e) => {
            if (e.changedTouches.length > 0) {
                const t = e.changedTouches[0];
                onUp(t.clientX, t.clientY);
            }
        };
    }

    function runGraphSimulation() {
        const ctx = graphCanvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;

        function step() {
            if (graphModal.style.display !== 'flex') return;

            const { nodes, links } = graphData;

            // Physics step
            // 1. Repulsion between all nodes
            for (let i = 0; i < nodes.length; i++) {
                for (let j = i + 1; j < nodes.length; j++) {
                    const n1 = nodes[i];
                    const n2 = nodes[j];
                    const dx = n2.x - n1.x;
                    const dy = n2.y - n1.y;
                    const distSq = dx * dx + dy * dy || 1;
                    const dist = Math.sqrt(distSq);

                    if (dist < 400) {
                        const force = 350 / distSq;
                        const fx = (dx / dist) * force;
                        const fy = (dy / dist) * force;

                        if (n1 !== draggedNode) {
                            n1.vx -= fx;
                            n1.vy -= fy;
                        }
                        if (n2 !== draggedNode) {
                            n2.vx += fx;
                            n2.vy += fy;
                        }
                    }
                }
            }

            // 2. Spring force along links
            for (const link of links) {
                const s = link.source;
                const t = link.target;
                const dx = t.x - s.x;
                const dy = t.y - s.y;
                const dist = Math.hypot(dx, dy) || 1;
                const targetDist = 70;
                const springForce = (dist - targetDist) * 0.04;

                const fx = (dx / dist) * springForce;
                const fy = (dy / dist) * springForce;

                if (s !== draggedNode) {
                    s.vx += fx;
                    s.vy += fy;
                }
                if (t !== draggedNode) {
                    t.vx -= fx;
                    t.vy -= fy;
                }
            }

            // 3. Center gravity and damping
            for (const n of nodes) {
                if (n !== draggedNode) {
                    n.vx -= n.x * 0.002;
                    n.vy -= n.y * 0.002;

                    n.vx *= 0.88;
                    n.vy *= 0.88;

                    n.x += n.vx;
                    n.y += n.vy;
                }
            }

            // Render step
            ctx.save();
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, graphCanvas.width, graphCanvas.height);

            // Apply canvas camera transform
            ctx.scale(dpr, dpr);
            ctx.translate(graphTransform.x, graphTransform.y);
            ctx.scale(graphTransform.k, graphTransform.k);

            // Draw Links
            ctx.lineWidth = 1;
            for (const l of links) {
                const isHighlighted = (hoveredNode && (l.source === hoveredNode || l.target === hoveredNode));
                ctx.strokeStyle = isHighlighted ? 'rgba(168, 130, 255, 0.7)' : 'rgba(70, 70, 85, 0.4)';
                ctx.beginPath();
                ctx.moveTo(l.source.x, l.source.y);
                ctx.lineTo(l.target.x, l.target.y);
                ctx.stroke();
            }

            // Draw Nodes
            for (const n of nodes) {
                let isMatch = true;
                if (graphFilterQuery) {
                    isMatch = n.title.toLowerCase().includes(graphFilterQuery) ||
                              (n.tags && n.tags.some(t => t.toLowerCase().includes(graphFilterQuery)));
                }

                const isHovered = (hoveredNode === n);
                ctx.globalAlpha = isMatch ? 1 : 0.2;

                // Node color
                let fill = '#7f6df2';
                if (n.isCurrent) {
                    fill = '#ffd700'; // Current active note is gold!
                } else if (n.unresolved) {
                    fill = '#55555e'; // Unresolved
                } else if (isHovered) {
                    fill = '#a882ff';
                }

                // Node Circle
                ctx.beginPath();
                ctx.arc(n.x, n.y, isHovered ? n.radius + 3 : n.radius, 0, 2 * Math.PI);
                ctx.fillStyle = fill;
                ctx.fill();

                // Glow for active note or hovered
                if (n.isCurrent || isHovered) {
                    ctx.strokeStyle = n.isCurrent ? 'rgba(255, 215, 0, 0.4)' : 'rgba(168, 130, 255, 0.4)';
                    ctx.lineWidth = 4;
                    ctx.stroke();
                }

                // Label
                ctx.fillStyle = isHovered ? '#ffffff' : (n.isCurrent ? '#ffd700' : '#dcddde');
                ctx.font = `${Math.max(10, Math.min(13, 11 / Math.sqrt(graphTransform.k)))}px -apple-system, sans-serif`;
                ctx.fillText(n.title, n.x + n.radius + 4, n.y + 4);

                ctx.globalAlpha = 1;
            }

            ctx.restore();
            graphAnimationId = requestAnimationFrame(step);
        }

        if (graphAnimationId) cancelAnimationFrame(graphAnimationId);
        graphAnimationId = requestAnimationFrame(step);
    }

    // Initialize Mermaid
    if (typeof mermaid !== 'undefined') {
        mermaid.initialize({ startOnLoad: false, theme: 'dark', securityLevel: 'loose' });
    }

    // Initialize application
    loadFileTree();
});
