/**
 * DICOM Viewer Pro — Tools Extension
 * Additional measurement utilities, pixel statistics, calibration
 */

'use strict';

// ============================================================
//  Calibration Tool (custom distance calibration)
// ============================================================
const CalibrationManager = {
    factor: null,  // mm per pixel

    calibrate(pixelLength, realMm) {
        this.factor = realMm / pixelLength;
        showToast(`Calibration set: 1px = ${this.factor.toFixed(4)} mm`, 'success');
    },

    toMm(pixels) {
        return this.factor ? pixels * this.factor : null;
    }
};

// ============================================================
//  ROI Statistics Helper
// ============================================================
function computeROIStats(image, points) {
    if (!image || !points || points.length < 3) return null;

    const pixelData = image.getPixelData();
    const cols      = image.columns;
    const rows      = image.rows;
    const slope     = image.slope     ?? 1;
    const intercept = image.intercept ?? 0;

    // Get bounding box
    const xs = points.map(p => p.x);
    const ys = points.map(p => p.y);
    const x0 = Math.max(0, Math.floor(Math.min(...xs)));
    const x1 = Math.min(cols-1, Math.ceil(Math.max(...xs)));
    const y0 = Math.max(0, Math.floor(Math.min(...ys)));
    const y1 = Math.min(rows-1, Math.ceil(Math.max(...ys)));

    const values = [];
    for (let y = y0; y <= y1; y++) {
        for (let x = x0; x <= x1; x++) {
            if (pointInPolygon({ x, y }, points)) {
                const raw = pixelData[y * cols + x];
                values.push(raw * slope + intercept);
            }
        }
    }

    if (values.length === 0) return null;
    const sum  = values.reduce((a,b) => a+b, 0);
    const mean = sum / values.length;
    const min  = Math.min(...values);
    const max  = Math.max(...values);
    const variance = values.reduce((a,b) => a + (b-mean)**2, 0) / values.length;
    const stdDev   = Math.sqrt(variance);
    return { count: values.length, mean, min, max, stdDev };
}

function pointInPolygon(point, vs) {
    let inside = false;
    const { x, y } = point;
    for (let i = 0, j = vs.length-1; i < vs.length; j = i++) {
        const xi = vs[i].x, yi = vs[i].y;
        const xj = vs[j].x, yj = vs[j].y;
        const intersect = ((yi > y) !== (yj > y)) &&
            (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
        if (intersect) inside = !inside;
    }
    return inside;
}

// ============================================================
//  Histogram Widget
// ============================================================
class HistogramWidget {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.canvas    = null;
    }

    render(image, roi = null) {
        if (!image || !this.container) return;

        if (!this.canvas) {
            this.canvas = document.createElement('canvas');
            this.canvas.width  = this.container.clientWidth  || 260;
            this.canvas.height = 80;
            this.canvas.style.cssText = 'width:100%;background:#0a0c0f;border-radius:4px;margin-top:8px';
            this.container.appendChild(this.canvas);
        }

        const pixelData = image.getPixelData();
        const slope     = image.slope     ?? 1;
        const intercept = image.intercept ?? 0;

        // Build histogram
        const bins  = 256;
        const hist  = new Array(bins).fill(0);
        let minVal  = Infinity, maxVal = -Infinity;
        for (let i = 0; i < pixelData.length; i++) {
            const v = pixelData[i] * slope + intercept;
            if (v < minVal) minVal = v;
            if (v > maxVal) maxVal = v;
        }
        const range = maxVal - minVal || 1;
        for (let i = 0; i < pixelData.length; i++) {
            const v   = pixelData[i] * slope + intercept;
            const bin = Math.min(bins-1, Math.floor((v - minVal) / range * (bins-1)));
            hist[bin]++;
        }
        const maxCount = Math.max(...hist);

        // Draw
        const ctx = this.canvas.getContext('2d');
        const w   = this.canvas.width;
        const h   = this.canvas.height;
        ctx.clearRect(0, 0, w, h);

        const grad = ctx.createLinearGradient(0,0,w,0);
        grad.addColorStop(0, '#1e3a5f');
        grad.addColorStop(1, '#60a5fa');
        ctx.fillStyle = grad;

        const bw = w / bins;
        for (let i = 0; i < bins; i++) {
            const barH = (hist[i] / maxCount) * h;
            ctx.fillRect(i * bw, h - barH, bw, barH);
        }

        // Axes
        ctx.strokeStyle = '#3d4560';
        ctx.lineWidth   = 1;
        ctx.beginPath(); ctx.moveTo(0,h-1); ctx.lineTo(w,h-1); ctx.stroke();
        ctx.fillStyle   = '#5a6280';
        ctx.font        = '9px monospace';
        ctx.fillText(Math.round(minVal), 2, h-2);
        ctx.fillText(Math.round(maxVal), w-30, h-2);
    }

    destroy() {
        if (this.canvas) {
            this.canvas.remove();
            this.canvas = null;
        }
    }
}

// ============================================================
//  Pixel Probe Enhanced
// ============================================================
function enhancedProbeDisplay(vpIndex) {
    const vp = typeof state !== 'undefined' ? state.viewports[vpIndex] : null;
    if (!vp || !vp.loaded) return;

    try {
        const toolState = cornerstoneTools.getToolState(vp.element, 'Probe');
        if (!toolState || !toolState.data || toolState.data.length === 0) return;

        const lastProbe = toolState.data[toolState.data.length - 1];
        const image     = cornerstone.getImage(vp.element);
        if (!image || !lastProbe.handles) return;

        const { x, y } = lastProbe.handles.end;
        const cols      = image.columns;
        const rows      = image.rows;
        if (x < 0 || y < 0 || x >= cols || y >= rows) return;

        const pixelData = image.getPixelData();
        const raw       = pixelData[Math.round(y) * cols + Math.round(x)];
        const slope     = image.slope ?? 1;
        const intercept = image.intercept ?? 0;
        const hu        = Math.round(raw * slope + intercept);

        // Update status bar
        document.getElementById('sbPixelVal').textContent =
            `Pixel: ${hu} HU  (${Math.round(x)}, ${Math.round(y)})`;
    } catch(e) {}
}

// ============================================================
//  Ruler with real-world calibration
// ============================================================
function formatLength(lengthMm) {
    if (lengthMm == null) return '—';
    if (lengthMm >= 10) return `${lengthMm.toFixed(1)} mm`;
    return `${(lengthMm).toFixed(2)} mm`;
}

function formatArea(areaMm2) {
    if (areaMm2 == null) return '—';
    if (areaMm2 >= 100) return `${(areaMm2/100).toFixed(2)} cm²`;
    return `${areaMm2.toFixed(2)} mm²`;
}

// ============================================================
//  Screenshot / Export
// ============================================================
function captureViewportScreenshot(vpIndex) {
    const vp = state.viewports[vpIndex];
    if (!vp || !vp.loaded) { showToast('No image loaded', 'warning'); return; }

    try {
        const csCanvas = vp.element.querySelector('canvas');
        if (!csCanvas) { showToast('Cannot capture viewport', 'error'); return; }

        // Create composite canvas with overlays
        const outCanvas = document.createElement('canvas');
        outCanvas.width  = csCanvas.width;
        outCanvas.height = csCanvas.height;
        const ctx = outCanvas.getContext('2d');
        ctx.drawImage(csCanvas, 0, 0);

        // Draw patient info overlay
        ctx.fillStyle = 'rgba(0,0,0,0.4)';
        ctx.fillRect(0, 0, outCanvas.width, 52);
        ctx.fillStyle = '#e8ecf4';
        ctx.font = 'bold 14px monospace';
        ctx.fillText(DICOM_STUDY.patientName, 8, 20);
        ctx.font = '11px monospace';
        ctx.fillStyle = '#a0aec0';
        ctx.fillText(`ID: ${DICOM_STUDY.patientId}  |  ${DICOM_STUDY.studyDate || ''}`, 8, 40);

        // Watermark
        ctx.fillStyle = 'rgba(255,255,255,0.15)';
        ctx.font = '11px sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText('DICOM Viewer Pro', outCanvas.width - 8, outCanvas.height - 8);

        // Download
        const link = document.createElement('a');
        link.download = `${DICOM_STUDY.patientId}_${Date.now()}.png`;
        link.href     = outCanvas.toDataURL('image/png');
        link.click();
        showToast('Screenshot saved', 'success');
    } catch(e) {
        showToast('Screenshot failed: ' + e.message, 'error');
    }
}

// ============================================================
//  DICOM Tag Formatter (for display panel)
// ============================================================
function formatDicomTagValue(vr, value) {
    if (!value && value !== 0) return '—';
    switch (vr) {
        case 'DA': {
            if (value.length === 8) {
                return `${value.substring(0,4)}-${value.substring(4,6)}-${value.substring(6,8)}`;
            }
            return value;
        }
        case 'TM': {
            if (value.length >= 6) {
                return `${value.substring(0,2)}:${value.substring(2,4)}:${value.substring(4,6)}`;
            }
            return value;
        }
        case 'DS':
        case 'IS':
            return value;
        case 'PN':
            return value.replace(/\^/g, ' ').trim();
        default:
            return String(value).trim();
    }
}

// ============================================================
//  Multi-frame Support (for enhanced series navigation)
// ============================================================
function getFrameCount(image) {
    if (!image) return 1;
    try {
        return parseInt(image.data?.string('x00280008') || '1') || 1;
    } catch(e) { return 1; }
}

// ============================================================
//  Auto window/level based on modality
// ============================================================
const MODALITY_WINDOWS = {
    CT: { ww: 400, wc: 40 },
    MR: { ww: 600, wc: 300 },
    CR: { ww: 2048, wc: 1024 },
    DX: { ww: 2048, wc: 1024 },
    US: { ww: 256, wc: 128 },
    PT: { ww: 4096, wc: 2048 },
    NM: { ww: 4096, wc: 2048 },
};

function applyModalityWindow(vpIndex) {
    const vp  = state.viewports[vpIndex];
    if (!vp || !vp.loaded) return;
    const mod = vp.series?.modality?.toUpperCase() || 'CT';
    const win = MODALITY_WINDOWS[mod] || MODALITY_WINDOWS.CT;
    try {
        const viewport = cornerstone.getViewport(vp.element);
        viewport.voi.windowWidth  = win.ww;
        viewport.voi.windowCenter = win.wc;
        cornerstone.setViewport(vp.element, viewport);
        showToast(`Applied ${mod} window preset (WW:${win.ww} WC:${win.wc})`, 'info');
    } catch(e) {}
}

// ============================================================
//  Series comparison (link scrolling between viewports)
// ============================================================
let syncScrollEnabled = false;

function toggleSyncScroll() {
    syncScrollEnabled = !syncScrollEnabled;
    showToast(syncScrollEnabled ? 'Scroll sync ON' : 'Scroll sync OFF',
              syncScrollEnabled ? 'success' : 'info');
}

function syncScroll(sourceVpIndex, imageIndex) {
    if (!syncScrollEnabled) return;
    state.viewports.forEach((vp, i) => {
        if (i !== sourceVpIndex && vp.loaded && vp.instances.length > imageIndex) {
            scrollToImage(i, imageIndex);
        }
    });
}

// ============================================================
//  Magnifier Glass
// ============================================================
class MagnifierGlass {
    constructor() {
        this.active  = false;
        this.canvas  = null;
        this.size    = 150;
        this.zoom    = 3;
    }

    enable(element) {
        this.active  = true;
        this.element = element;
        if (!this.canvas) {
            this.canvas = document.createElement('canvas');
            this.canvas.width  = this.size;
            this.canvas.height = this.size;
            this.canvas.style.cssText = `
                position:absolute; border-radius:50%; border:2px solid #3b82f6;
                pointer-events:none; z-index:50; display:none;
                box-shadow:0 0 20px rgba(59,130,246,.4);
            `;
            element.parentElement.appendChild(this.canvas);
        }

        element.addEventListener('mousemove', this._onMouseMove.bind(this));
        element.addEventListener('mouseleave', () => {
            if (this.canvas) this.canvas.style.display = 'none';
        });
    }

    _onMouseMove(evt) {
        if (!this.active || !this.canvas) return;
        const rect   = this.element.getBoundingClientRect();
        const x      = evt.clientX - rect.left;
        const y      = evt.clientY - rect.top;
        const half   = this.size / 2;

        this.canvas.style.display = 'block';
        this.canvas.style.left    = (x - half) + 'px';
        this.canvas.style.top     = (y - half) + 'px';

        const srcCanvas = this.element.querySelector('canvas');
        if (!srcCanvas) return;
        const ctx  = this.canvas.getContext('2d');
        ctx.clearRect(0, 0, this.size, this.size);
        ctx.save();
        ctx.beginPath();
        ctx.arc(half, half, half-2, 0, Math.PI*2);
        ctx.clip();
        ctx.drawImage(
            srcCanvas,
            x - half/this.zoom, y - half/this.zoom,
            this.size/this.zoom, this.size/this.zoom,
            0, 0, this.size, this.size
        );
        ctx.restore();
    }

    disable() {
        this.active = false;
        if (this.canvas) this.canvas.style.display = 'none';
    }
}

const magnifier = new MagnifierGlass();

// ============================================================
//  Expose global helpers
// ============================================================
window.DicomTools = {
    CalibrationManager,
    computeROIStats,
    HistogramWidget,
    enhancedProbeDisplay,
    formatLength,
    formatArea,
    captureViewportScreenshot,
    formatDicomTagValue,
    getFrameCount,
    applyModalityWindow,
    toggleSyncScroll,
    magnifier,
};