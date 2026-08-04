document.addEventListener('DOMContentLoaded', () => {
    const fileTreeContainer = document.getElementById('file-tree');
    const searchInput = document.getElementById('search-input');
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

    const userMode = window.USER_MODE || 'readonly';
    const isEditAllowed = (userMode === 'edit');

    let allFilesData = [];
    let serverVaultPath = '';
    let currentFilePath = null;
    let rawNoteContent = '';
    let isEditingMode = false;
    let isUnsaved = false;

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
            const response = await fetch('file.php?action=list');
            if (!response.ok) {
                if (response.status === 403) {
                    window.location.href = 'login.php';
                    return;
                }
                throw new Error(`HTTP ${response.status}: Nepodařilo se načíst seznam.`);
            }

            const data = await response.json();

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
                list.push({ value: item.path, label: `📁 ${item.path}` });
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
                        <svg class="item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                        </svg>
                        <span>${escapeHtml(item.name)}</span>
                    </div>
                    ${actionButtonsHtml}
                `;

                const folderContent = document.createElement('div');
                folderContent.className = 'folder-content';

                // Tapping folder label: Expands/collapses folder AND activates action buttons for mobile
                folderHeader.querySelector('.folder-header-label').addEventListener('click', () => {
                    fileTreeContainer.querySelectorAll('.touch-selected').forEach(el => el.classList.remove('touch-selected'));
                    folderHeader.classList.add('touch-selected');
                    folderContent.classList.toggle('collapsed');
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

    // Search filter
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const allItems = fileTreeContainer.querySelectorAll('.tree-item');

            allItems.forEach(item => {
                const text = item.textContent.toLowerCase();
                if (text.includes(query)) {
                    item.style.display = 'flex';
                    let parentContent = item.closest('.folder-content');
                    while (parentContent) {
                        parentContent.classList.remove('collapsed');
                        parentContent = parentContent.parentElement.closest('.folder-content');
                    }
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }

    // Select and load note
    async function selectFile(filePath) {
        currentFilePath = filePath;
        highlightActiveFile(filePath);

        noteHeader.style.display = 'flex';
        notePathSpan.textContent = filePath;

        try {
            const response = await fetch(`file.php?f=${encodeURIComponent(filePath)}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const isMarkdown = filePath.toLowerCase().endsWith('.md');
            if (isMarkdown) {
                rawNoteContent = await response.text();

                if (isEditingMode && isEditAllowed) {
                    showEditorView();
                } else {
                    showPreviewView();
                }
            } else if (/\.(jpg|jpeg|png|gif|webp|svg)$/i.test(filePath)) {
                hideEditorAndPreview();
                const imgUrl = `file.php?f=${encodeURIComponent(filePath)}`;
                noteView.innerHTML = `<img src="${imgUrl}" alt="${escapeHtml(filePath)}" style="max-width: 100%; height: auto; border-radius: 8px;">`;
                noteView.style.display = 'block';
                emptyState.style.display = 'none';
            } else if (/\.(m4a|mp3|ogg|wav)$/i.test(filePath)) {
                hideEditorAndPreview();
                const audioUrl = `file.php?f=${encodeURIComponent(filePath)}`;
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

    // Save note via AJAX
    async function saveCurrentNote() {
        if (!isEditAllowed || !currentFilePath) return;

        updateSaveStatus('Ukládám...', true);

        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('f', currentFilePath);
        formData.append('content', rawNoteContent);

        try {
            const response = await fetch('file.php?action=save', {
                method: 'POST',
                body: formData
            });

            const res = await response.json();
            if (res.success) {
                isUnsaved = false;
                updateSaveStatus('Uloženo ✔', false);
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
            { value: '', label: '📂 (Kořenový adresář Vaultu)' }
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

    // Markdown Parser
    function parseObsidianMarkdown(markdown, filePath) {
        const noteTitle = getFileNameWithoutExtension(filePath);
        const lines = markdown.replace(/\r\n/g, '\n').split('\n');

        let htmlBlocks = [];
        htmlBlocks.push(`<h1>${escapeHtml(noteTitle)}</h1>`);

        let paragraphLines = [];

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

        for (let i = 0; i < lines.length; i++) {
            const rawLine = lines[i];
            const trimmed = rawLine.trim();

            if (trimmed === '---') {
                flushParagraph();
                htmlBlocks.push('<hr>');
                continue;
            }

            if (/^#\s+(.+)$/.test(rawLine)) {
                flushParagraph();
                const match = rawLine.match(/^#\s+(.+)$/);
                htmlBlocks.push(`<h1>${inlineFormat(escapeHtml(match[1]), filePath)}</h1>`);
                continue;
            }

            if (/^##\s+(.+)$/.test(rawLine)) {
                flushParagraph();
                const match = rawLine.match(/^##\s+(.+)$/);
                htmlBlocks.push(`<h2>${inlineFormat(escapeHtml(match[1]), filePath)}</h2>`);
                continue;
            }

            if (/^###\s+(.+)$/.test(rawLine)) {
                flushParagraph();
                const match = rawLine.match(/^###\s+(.+)$/);
                htmlBlocks.push(`<h3>${inlineFormat(escapeHtml(match[1]), filePath)}</h3>`);
                continue;
            }

            const wikilinkMatch = trimmed.match(/^!\[\[([^\]]+)\]\]$/);
            if (wikilinkMatch) {
                flushParagraph();
                const embedHtml = renderObsidianEmbed(wikilinkMatch[1], filePath);
                htmlBlocks.push(embedHtml);
                continue;
            }

            const standardImgMatch = trimmed.match(/^!\[(.*?)\]\((.*?)\)$/);
            if (standardImgMatch) {
                flushParagraph();
                const alt = standardImgMatch[1];
                const url = standardImgMatch[2];
                const fileUrl = `file.php?f=${encodeURIComponent(resolveEmbedPath(url, filePath))}`;
                htmlBlocks.push(`<img src="${fileUrl}" alt="${escapeHtml(alt)}" style="max-width: 100%; height: auto; border-radius: 8px;">`);
                continue;
            }

            if (/^[\-\*]\s+(.+)$/.test(trimmed)) {
                flushParagraph();
                const listText = trimmed.replace(/^[\-\*]\s+/, '');
                htmlBlocks.push(`<ul><li>${inlineFormat(escapeHtml(listText), filePath)}</li></ul>`);
                continue;
            }

            if (trimmed === '') {
                flushParagraph();
                continue;
            }

            paragraphLines.push(escapeHtml(rawLine));
        }

        flushParagraph();
        return htmlBlocks.join('\n');
    }

    function inlineFormat(text, currentFilePath) {
        if (!text) return '';

        text = text.replace(/!\[\[([^\]]+)\]\]/g, (match, embedPath) => {
            return renderObsidianEmbed(embedPath, currentFilePath);
        });

        text = text.replace(/!\[(.*?)\]\((.*?)\)/g, (match, alt, url) => {
            const fileUrl = `file.php?f=${encodeURIComponent(resolveEmbedPath(url, currentFilePath))}`;
            return `<img src="${fileUrl}" alt="${escapeHtml(alt)}" style="max-width: 100%; height: auto; border-radius: 8px;">`;
        });

        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

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
        const fileUrl = `file.php?f=${encodeURIComponent(resolvedPath)}`;
        const ext = cleanName.split('.').pop().toLowerCase();
        const styleAttr = widthStyle ? `style="${widthStyle} height: auto; border-radius: 8px;"` : `style="max-width: 100%; height: auto; border-radius: 8px;"`;

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

    // Initialize application
    loadFileTree();
});
