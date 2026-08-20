/**
 * DICOM Viewer Pro — Upload Engine
 */
'use strict';

const UploadManager = {
    queue: [],
    uploading: false,
    stats: { success: 0, duplicate: 0, error: 0 },
    concurrency: 1,

    init() {
        this.bindDropZone();
        this.bindFileInputs();
        this.bindButtons();
        this.bindResultActions();
    },

    bindDropZone() {
        const zone = document.getElementById('dropZone');
        if (!zone) return;

        ['dragenter', 'dragover'].forEach(evt => {
            zone.addEventListener(evt, e => {
                e.preventDefault();
                zone.classList.add('drag-over');
            });
        });

        ['dragleave', 'dragend', 'drop'].forEach(evt => {
            zone.addEventListener(evt, () => zone.classList.remove('drag-over'));
        });

        zone.addEventListener('drop', e => {
            e.preventDefault();
            const files = [...(e.dataTransfer?.files || [])];
            this.addFiles(files);
        });
    },

    bindFileInputs() {
        const fileInput = document.getElementById('fileInput');
        const folderInput = document.getElementById('folderInput');

        fileInput?.addEventListener('change', e => {
            this.addFiles([...(e.target.files || [])]);
            e.target.value = '';
        });

        folderInput?.addEventListener('change', e => {
            this.addFiles([...(e.target.files || [])]);
            e.target.value = '';
        });
    },

    bindButtons() {
        document.getElementById('startUpload')?.addEventListener('click', () => this.startUpload());
        document.getElementById('clearQueue')?.addEventListener('click', () => this.clearQueue());
    },

    bindResultActions() {
        // Keep patient fields, reset only queue/progress/results + image type
        document.getElementById('uploadAnotherType')?.addEventListener('click', () => {
          resetUpload();
          document.getElementById('imageType').value = '';
          const startBtn = document.getElementById('startUpload');
          if (startBtn) startBtn.disabled = false;
        });
    },

    validateMeta() {
        const patientName = document.getElementById('patientName')?.value.trim() || '';
        const patientAge = document.getElementById('patientAge')?.value.trim() || '';
        const patientWeight = document.getElementById('patientWeight')?.value.trim() || '';
        const imageType = document.getElementById('imageType')?.value.trim() || '';

        if (!patientName || !patientAge || !patientWeight || !imageType) {
            throw new Error('Please fill patient name, age, weight, and image type before upload.');
        }

        const ageNum = Number(patientAge);
        const weightNum = Number(patientWeight);
        
        const patientSex = document.getElementById('patientSex')?.value.trim() || '';
        
        const doctorId = document.getElementById('doctorId')?.value.trim() || '';
        if (!doctorId || !patientName || !patientAge || !patientWeight || !imageType || !patientSex) {
          throw new Error('Please select doctor, sex, and fill patient name, age, weight, and image type before upload.');
        }
        
        if (!['M','F'].includes(patientSex)) {
          throw new Error('Invalid sex value.');
        }
        
        if (!Number.isFinite(ageNum) || ageNum < 0 || ageNum > 130) {
            throw new Error('Age must be between 0 and 130.');
        }

        if (!Number.isFinite(weightNum) || weightNum <= 0 || weightNum > 500) {
            throw new Error('Weight must be between 0 and 500 kg.');
        }

        return { doctorId, patientName, patientAge, patientWeight, imageType, patientSex };
    },

    addFiles(files) {
        if (!Array.isArray(files) || files.length === 0) return;

        const filtered = files.filter(f => {
            if (!f || !f.name) return false;
            const hasDot = f.name.includes('.');
            const ext = hasDot ? f.name.split('.').pop().toLowerCase() : '';
            return ['dcm', 'dicom', 'ima', ''].includes(ext) || !hasDot;
        });

        filtered.forEach(file => {
            this.queue.push({
                id: cryptoRandomId(),
                file,
                status: 'pending',
                error: ''
            });
        });
        
        if (filtered.length > 0) {
          const startBtn = document.getElementById('startUpload');
          if (startBtn) startBtn.disabled = false;
        }
        
        this.renderQueue();
        this.setStartButtonState();
    },

    renderQueue() {
        const queueEl = document.getElementById('fileQueue');
        const listEl = document.getElementById('queueList');
        const countEl = document.getElementById('queueCount');

        if (!queueEl || !listEl || !countEl) return;

        if (this.queue.length === 0) {
            queueEl.style.display = 'none';
            listEl.innerHTML = '';
            countEl.textContent = '0';
            return;
        }

        queueEl.style.display = 'block';
        countEl.textContent = String(this.queue.length);

        listEl.innerHTML = this.queue.map(item => `
            <div class="queue-item" id="qi-${item.id}">
                <i class="fas fa-file-medical queue-item-icon"></i>
                <div class="queue-item-main">
                    <span class="queue-item-name" title="${escHtml(item.file.name)}">${escHtml(item.file.name)}</span>
                    ${item.status === 'error' && item.error ? `<small class="queue-item-error">${escHtml(item.error)}</small>` : ''}
                </div>
                <span class="queue-item-size">${formatBytes(item.file.size)}</span>
                <span class="queue-item-status" id="qs-${item.id}">
                    ${this.statusIcon(item.status)}
                </span>
            </div>
        `).join('');
    },

    statusIcon(status) {
        const icons = {
            pending: '<i class="fas fa-clock status-pending"></i>',
            uploading: '<i class="fas fa-spinner status-loading fa-spin"></i>',
            success: '<i class="fas fa-check-circle status-success"></i>',
            duplicate: '<i class="fas fa-copy status-dup"></i>',
            error: '<i class="fas fa-times-circle status-error"></i>',
        };
        return icons[status] || icons.pending;
    },

    setStartButtonState() {
        const btn = document.getElementById('startUpload');
        if (!btn) return;
        btn.disabled = this.uploading || this.queue.length === 0;
    },

    lockMeta(disabled) {
        ['doctorId', 'patientName', 'patientAge', 'patientWeight', 'patientSex', 'imageType', 'fileInput', 'folderInput']
            .forEach(id => {
                const el = document.getElementById(id);
                if (el) el.disabled = disabled;
            });
    },

    async startUpload() {
        if (this.uploading || this.queue.length === 0) return;

        try {
            this.validateMeta();
        } catch (err) {
            alert(err.message);
            return;
        }

        this.uploading = true;
        this.stats = { success: 0, duplicate: 0, error: 0 };
        this.setStartButtonState();
        this.lockMeta(true);

        const progressSection = document.getElementById('progressSection');
        const results = document.getElementById('uploadResults');
        if (progressSection) progressSection.style.display = 'block';
        if (results) results.style.display = 'none';

        const total = this.queue.length;
        let done = 0;
        const startTime = Date.now();

        // Upload only pending/error items; keep success/duplicate untouched
        const pending = this.queue.filter(i => i.status === 'pending' || i.status === 'error');

        const processNext = async () => {
            while (pending.length > 0) {
                const item = pending.shift();
                if (!item) continue;

                item.status = 'uploading';
                item.error = '';
                this.updateItemStatus(item.id, 'uploading');

                try {
                    const result = await this.uploadFile(item.file);
                    if (result.duplicate) {
                        item.status = 'duplicate';
                        this.stats.duplicate++;
                    } else {
                        item.status = 'success';
                        this.stats.success++;
                        item.studyId = result.studyId;
                    }
                } catch (e) {
                    item.status = 'error';
                    item.error = e?.message || 'Upload failed';
                    this.stats.error++;
                    console.error('Upload failed:', item.file.name, item.error);
                }

                this.updateItemStatus(item.id, item.status, item.error);
                done++;
                this.updateProgress(done, total, startTime, item.file.name);
            }
        };

        const workers = [];
        for (let i = 0; i < this.concurrency; i++) workers.push(processNext());
        await Promise.all(workers);

        this.uploading = false;
        this.lockMeta(false);
        this.setStartButtonState();
        this.showResults();
    },

    async uploadFile(file) {
        const meta = this.validateMeta();

        const formData = new FormData();
        formData.append('file', file);
        formData.append('patientName', meta.patientName);
        formData.append('patientAge', meta.patientAge);
        formData.append('doctorId', meta.doctorId);
        formData.append('patientWeight', meta.patientWeight);
        formData.append('imageType', meta.imageType);
        formData.append('clientFileName', file.name || '');
        formData.append('patientSex', meta.patientSex);
        formData.append('clientRelativePath', file.webkitRelativePath || file.name || '');

        const resp = await fetch('api.php?action=upload', {
            method: 'POST',
            body: formData
        });

        const raw = await resp.text();

        let data;
        try {
            data = JSON.parse(raw);
        } catch {
            throw new Error(`Server returned non-JSON: ${raw.substring(0, 250)}`);
        }

        if (!resp.ok || data.success === false) {
            throw new Error(data.message || `HTTP ${resp.status}`);
        }

        return data;
    },

    updateItemStatus(id, status, errText = '') {
        const statusEl = document.getElementById(`qs-${id}`);
        if (statusEl) statusEl.innerHTML = this.statusIcon(status);

        if (errText && status === 'error') {
            const row = document.getElementById(`qi-${id}`);
            const main = row?.querySelector('.queue-item-main');
            if (main && !main.querySelector('.queue-item-error')) {
                const small = document.createElement('small');
                small.className = 'queue-item-error';
                small.textContent = errText;
                main.appendChild(small);
            }
        }
    },

    updateProgress(done, total, startTime, currentFile) {
        const pct = total > 0 ? Math.round((done / total) * 100) : 0;
        const elapsed = Math.max(1, (Date.now() - startTime) / 1000);
        const speed = done / elapsed;
        const remaining = speed > 0 ? (total - done) / speed : Infinity;

        const bar = document.getElementById('progressBar');
        const count = document.getElementById('progressCount');
        const percent = document.getElementById('progressPercent');
        const current = document.getElementById('progressCurrent');
        const speedEl = document.getElementById('progressSpeed');

        if (bar) bar.style.width = `${pct}%`;
        if (count) count.textContent = `${done} / ${total}`;
        if (percent) percent.textContent = `${pct}%`;
        if (current) current.textContent = currentFile || 'Processing...';
        if (speedEl) speedEl.textContent = `${speed.toFixed(1)} files/s — ETA ${formatSeconds(remaining)}`;
    },

    showResults() {
        document.getElementById('progressSection')?.style.setProperty('display', 'none');
        document.getElementById('fileQueue')?.style.setProperty('display', 'none');
        document.getElementById('uploadResults')?.style.setProperty('display', 'block');
    
        const successEl = document.getElementById('successCount');
        const dupEl = document.getElementById('dupCount');
        const errorEl = document.getElementById('errorCount');
    
        if (successEl) successEl.textContent = String(this.stats.success);
        if (dupEl) dupEl.textContent = String(this.stats.duplicate);
        if (errorEl) errorEl.textContent = String(this.stats.error);
    
        this.uploading = false;
    
        const startBtn = document.getElementById('startUpload');
        if (startBtn) startBtn.disabled = false;
    },

    clearQueue() {
        this.queue = [];
        this.uploading = false;
        this.stats = { success: 0, duplicate: 0, error: 0 };

        this.renderQueue();
        document.getElementById('progressSection')?.style.setProperty('display', 'none');
        document.getElementById('uploadResults')?.style.setProperty('display', 'none');

        this.setStartButtonState();
    },
};

function resetUpload() {
    UploadManager.uploading = false;
    UploadManager.queue = [];
    UploadManager.stats = { success: 0, duplicate: 0, error: 0 };

    const queueList = document.getElementById('queueList');
    const queueCount = document.getElementById('queueCount');

    if (queueList) queueList.innerHTML = '';
    if (queueCount) queueCount.textContent = '0';

    document.getElementById('fileQueue')?.style.setProperty('display', 'none');
    document.getElementById('progressSection')?.style.setProperty('display', 'none');
    document.getElementById('uploadResults')?.style.setProperty('display', 'none');

    const startBtn = document.getElementById('startUpload');
    if (startBtn) startBtn.disabled = false;
}

function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) return '0 B';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatSeconds(s) {
    if (!Number.isFinite(s) || s < 0) return '—';
    if (s < 60) return `${Math.round(s)}s`;
    return `${Math.floor(s / 60)}m ${Math.round(s % 60)}s`;
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function cryptoRandomId() {
    if (window.crypto?.getRandomValues) {
        const arr = new Uint32Array(2);
        window.crypto.getRandomValues(arr);
        return `${arr[0].toString(36)}${arr[1].toString(36)}`;
    }
    return Math.random().toString(36).slice(2, 11);
}

document.addEventListener('DOMContentLoaded', () => UploadManager.init());