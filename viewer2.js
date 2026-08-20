'use strict';

/* =========================================================
   Dependency check
========================================================= */
function libsReady() {
  return (
    typeof window.cornerstone !== 'undefined' &&
    typeof window.cornerstoneTools !== 'undefined' &&
    typeof window.cornerstoneWADOImageLoader !== 'undefined' &&
    typeof window.dicomParser !== 'undefined'
  );
}

/* =========================================================
   Global state
========================================================= */
const state = {
  activeViewportIndex: 0,
  layout: '1x1',
  viewports: [],
  activeTool: 'Length',

  cineRunning: false,
  cineInterval: null,
  cineFps: 15,

  autoStartCine: false,
  splashPreloading: false,

  seriesData: Array.isArray(window.DICOM_STUDY?.series) ? window.DICOM_STUDY.series : []
};

let toolsRegistered = false;
let bootCompleted = false;
let splashTimer = null;
let splashPct = 0;
let splashShownAt = 0;
let viewportsReady = false;

const MIN_SPLASH_MS = 1200;

/* =========================================================
   Startup
========================================================= */
document.addEventListener('DOMContentLoaded', () => {
  startSplashProgress();
  bootViewerWhenReady(50);
});

/* =========================================================
   Splash screen helpers
========================================================= */
function showSplash(statusText = '[SYS_BOOT] INITIALIZING DIAGNOSTIC VIEWER...') {
  const splash = document.getElementById('pacs-splash-screen');
  if (!splash) return;
  splash.classList.remove('hidden');
  splash.style.opacity = '1';
  splash.style.pointerEvents = 'auto';
  setSplashProgress(Math.max(1, splashPct), statusText);
}

function setSplashProgress(pct, text = '') {
  const p = Math.max(0, Math.min(100, Number(pct) || 0));
  splashPct = p;

  const bar = document.getElementById('pacs-splash-progress');
  const per = document.getElementById('pacs-splash-percentage');
  const st = document.getElementById('pacs-splash-status');

  if (bar) bar.style.width = `${p.toFixed(2)}%`;
  if (per) per.textContent = `${p.toFixed(2)}%`;
  if (text && st) st.textContent = text;
}

function startSplashProgress() {
  splashShownAt = Date.now();
  setSplashProgress(1, '[SYS_BOOT] INITIALIZING DIAGNOSTIC VIEWER...');

  splashTimer = setInterval(() => {
    if (bootCompleted) return;
    if (state.splashPreloading) return;

    const target = 94;
    if (splashPct < target) {
      setSplashProgress(
        splashPct + Math.random() * 1.2,
        '[PACS_NET] CONNECTING IMAGE PIPELINE...'
      );
    }
  }, 90);
}

async function finishSplashAndShowViewer() {
  if (bootCompleted) return;
  bootCompleted = true;

  if (splashTimer) {
    clearInterval(splashTimer);
    splashTimer = null;
  }

  setSplashProgress(100, '[SYS_READY] SYSTEM ONLINE. OPENING VIEWER...');

  const elapsed = Date.now() - splashShownAt;
  const waitMs = Math.max(0, MIN_SPLASH_MS - elapsed);
  if (waitMs > 0) await new Promise(r => setTimeout(r, waitMs));
  
  const modal = document.getElementById('reportModalOverlay');
    if (modal && !modal.classList.contains('active')) {
      modal.style.pointerEvents = 'none';
      modal.style.display = 'none';
    }
  
  const shell = document.getElementById('viewerShell');
  if (shell) shell.classList.add('ready');

  await new Promise(r => setTimeout(r, 350));
  await activateViewports();
  
  const splash = document.getElementById('pacs-splash-screen');
  if (splash) splash.style.pointerEvents = 'none';
  
  setTimeout(() => hideSplash(), 120);
  
  ['pacs-splash-screen', 'reportModalOverlay'].forEach(id => {
  const el = document.getElementById(id);
  if (!el) return;
  if (id === 'reportModalOverlay' && el.classList.contains('active')) return;
  el.style.pointerEvents = 'none';
  if (id === 'pacs-splash-screen') {
    el.style.display = 'none';
    el.style.zIndex = '-1';
  } else {
    el.style.display = 'none';
  }
});
  
}

/* =========================================================
   Boot & init
========================================================= */
function bootViewerWhenReady(retriesLeft) {
  if (!libsReady()) {
    if (retriesLeft <= 0) {
      console.error('Cornerstone libraries failed to load.');
      finishSplashAndShowViewer();
      return;
    }
    setTimeout(() => bootViewerWhenReady(retriesLeft - 1), 120);
    return;
  }

  setSplashProgress(20, '[SEC_STOR] LOADING STUDY METADATA...');

  cornerstoneWADOImageLoader.external.cornerstone = cornerstone;
  cornerstoneWADOImageLoader.external.dicomParser = dicomParser;
  cornerstoneTools.external.cornerstone = cornerstone;
  cornerstoneTools.external.Hammer = window.Hammer || null;
  if (typeof window.cornerstoneMath !== 'undefined') {
    cornerstoneTools.external.cornerstoneMath = cornerstoneMath;
  }

  cornerstoneWADOImageLoader.configure({
    useWebWorkers: true,
    decodeConfig: { usePDFJS: false, strict: false }
  });

  cornerstoneTools.init({
      mouseEnabled: true,
      touchEnabled: true,
      globalToolSyncEnabled: true,
      showSVGCursors: true,
      preventDefaultOutsideViewport: false
    });

  init();
}

async function init() {
  let forcedCloseTimer = null;

  try {
    forcedCloseTimer = setTimeout(() => {
      console.warn('Forced splash close fallback');
      finishSplashAndShowViewer();
    }, 8000);

    setCineButtonEnabled(false);

    setSplashProgress(30, '[GPU_VPORT] PREPARING VIEWPORT ENGINE...');
    renderSeriesList();
    prepareViewports('1x1');

    if (state.seriesData.length > 0) {
      setSplashProgress(50, '[DICOM_IO] PRE-CACHING FIRST IMAGE...');
      try {
        const firstId = getInstanceImageId(state.seriesData[0].instances[0].id);
        await cornerstone.loadAndCacheImage(firstId);
      } catch (e) {
        console.warn('Pre-cache first image failed:', e);
      }

      state.splashPreloading = true;
      showSplash('[CACHE] PRELOADING FIRST SERIES...');
      await preloadActiveSeriesPriority(0, 200);
      state.splashPreloading = false;
    } else {
      setSplashProgress(90, '[SYS_READY] NO SERIES FOUND');
    }

    bindToolbarEvents();
    bindSeriesEdgeToggle();
    bindCineControls();
    bindScrollerEvents();
    bindKeyboardShortcuts();

    [0, 1, 2, 3].forEach(i => showLoading(i, false));

    setSplashProgress(96, '[SYS_READY] FINALIZING...');
    await finishSplashAndShowViewer();

    setCineButtonEnabled(true);
    updateCounters();

    loadAllSeriesThumbnails();
    preloadRemainingSeriesInBackground().catch(err => {
      console.warn('Background preload error:', err);
    });
  } catch (e) {
    console.error('init failed:', e);
    finishSplashAndShowViewer();
    setCineButtonEnabled(true);
  } finally {
    state.splashPreloading = false;
    if (forcedCloseTimer) clearTimeout(forcedCloseTimer);
  }
}

function bindSeriesEdgeToggle() {
  const btn = document.getElementById('seriesEdgeToggle');
  if (!btn) return;

  const saved = localStorage.getItem('seriesCollapsed');
  if (saved === '1') document.body.classList.add('series-collapsed');

  const icon = btn.querySelector('i');

  const refreshIcon = () => {
    const collapsed = document.body.classList.contains('series-collapsed');
    if (!icon) return;
    icon.classList.remove('fa-chevron-left', 'fa-chevron-right');
    icon.classList.add(collapsed ? 'fa-chevron-right' : 'fa-chevron-left');
  };

  const refreshLayout = () => {
    setTimeout(() => {
      state.viewports.forEach(vp => {
        if (vp?.element) {
          try { cornerstone.resize(vp.element, true); } catch {}
        }
      });
    }, 260);
  };

  refreshIcon();

  btn.addEventListener('click', () => {
    document.body.classList.toggle('series-collapsed');
    const collapsed = document.body.classList.contains('series-collapsed');
    localStorage.setItem('seriesCollapsed', collapsed ? '1' : '0');
    refreshIcon();
    refreshLayout();
  });
}

/* =========================================================
   Thumbnails
========================================================= */
function drawThumbnail(canvasEl, cornerstoneImage) {
  if (!canvasEl || !cornerstoneImage) return;

  const rect = canvasEl.getBoundingClientRect();
  const cw = Math.max(1, Math.round(rect.width)) || 160;
  const ch = Math.max(1, Math.round(rect.height)) || 120;
  canvasEl.width = cw;
  canvasEl.height = ch;

  const ctx = canvasEl.getContext('2d');
  const imgRows = cornerstoneImage.rows || 1;
  const imgCols = cornerstoneImage.columns || 1;

  const scale = Math.min(cw / imgCols, ch / imgRows);
  const drawW = imgCols * scale;
  const drawH = imgRows * scale;
  const offX = (cw - drawW) / 2;
  const offY = (ch - drawH) / 2;

  ctx.fillStyle = '#000';
  ctx.fillRect(0, 0, cw, ch);

  const pixelData = cornerstoneImage.getPixelData();
  const isColor = cornerstoneImage.color === true;

  const wc = cornerstoneImage.windowCenter || 128;
  const ww = cornerstoneImage.windowWidth || 256;
  const lo = wc - ww / 2;
  const hi = wc + ww / 2;
  const range = Math.max(1, hi - lo);

  const tmpCanvas = document.createElement('canvas');
  tmpCanvas.width = imgCols;
  tmpCanvas.height = imgRows;
  const tmpCtx = tmpCanvas.getContext('2d');
  const tmpImg = tmpCtx.createImageData(imgCols, imgRows);

  if (isColor) {
    for (let i = 0, j = 0; i < pixelData.length && j < tmpImg.data.length; i += 3, j += 4) {
      tmpImg.data[j] = pixelData[i];
      tmpImg.data[j + 1] = pixelData[i + 1];
      tmpImg.data[j + 2] = pixelData[i + 2];
      tmpImg.data[j + 3] = 255;
    }
  } else {
    for (let i = 0, j = 0; i < pixelData.length && j < tmpImg.data.length; i++, j += 4) {
      const raw = pixelData[i];
      const norm = Math.max(0, Math.min(255, ((raw - lo) / range) * 255));
      tmpImg.data[j] = norm;
      tmpImg.data[j + 1] = norm;
      tmpImg.data[j + 2] = norm;
      tmpImg.data[j + 3] = 255;
    }
  }

  tmpCtx.putImageData(tmpImg, 0, 0);
  ctx.drawImage(tmpCanvas, 0, 0, imgCols, imgRows, offX, offY, drawW, drawH);
}

async function loadAllSeriesThumbnails() {
  const items = document.querySelectorAll('#seriesList .series-item');
  const concurrency = 2;
  let cursor = 0;

  async function worker() {
    while (cursor < items.length) {
      const idx = cursor++;
      const s = state.seriesData[idx];
      if (!s?.instances?.length) continue;

      const canvasEl = items[idx].querySelector('.series-thumb-canvas');
      if (!canvasEl) continue;

      const imageId = getInstanceImageId(s.instances[0].id);
      try {
        const image = await cornerstone.loadAndCacheImage(imageId);
        drawThumbnail(canvasEl, image);
      } catch (e) {
        console.warn(`Thumb load failed series ${idx}:`, e);
      }

      await new Promise(r => setTimeout(r, 0));
    }
  }

  await Promise.all(Array.from({ length: concurrency }, worker));
}

function bindPinchZoom(vpIndex) {
  const vp = state.viewports[vpIndex];
  if (!vp?.element) return;
  const el = vp.element;

  // avoid duplicate listeners on rebuild
  if (el._pinchHandlersBound) return;
  el._pinchHandlersBound = true;

  let pinchActive = false;
  let lastPinchDist = 0;

  el.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 2) {
      pinchActive = false;
      return;
    }

    const rect = el.getBoundingClientRect();
    const t1 = e.touches[0];
    const t2 = e.touches[1];

    const inBounds =
      t1.clientX >= rect.left && t1.clientX <= rect.right &&
      t1.clientY >= rect.top  && t1.clientY <= rect.bottom &&
      t2.clientX >= rect.left && t2.clientX <= rect.right &&
      t2.clientY >= rect.top  && t2.clientY <= rect.bottom;

    if (!inBounds) {
      pinchActive = false;
      return;
    }

    pinchActive = true;
    e.preventDefault();

    const dx = t1.clientX - t2.clientX;
    const dy = t1.clientY - t2.clientY;
    lastPinchDist = Math.max(1, Math.hypot(dx, dy));
  }, { passive: false });

  el.addEventListener('touchmove', (e) => {
    if (!pinchActive || e.touches.length !== 2) return;

    e.preventDefault();
    e.stopPropagation();

    const liveVp = state.viewports[vpIndex];
    if (!liveVp?.loaded) return;

    try {
      const csVp = cornerstone.getViewport(liveVp.element);
      const dx = e.touches[0].clientX - e.touches[1].clientX;
      const dy = e.touches[0].clientY - e.touches[1].clientY;
      const dist = Math.max(1, Math.hypot(dx, dy));

      const scale = dist / lastPinchDist;
      csVp.scale = Math.max(0.05, csVp.scale * scale);
      cornerstone.setViewport(liveVp.element, csVp);

      lastPinchDist = dist;
    } catch {}
  }, { passive: false });

  el.addEventListener('touchend', () => {
    pinchActive = false;
  }, { passive: false });

  el.addEventListener('touchcancel', () => {
    pinchActive = false;
  }, { passive: false });
}

/* =========================================================
   Viewports
========================================================= */
async function activateViewports() {
  if (viewportsReady) return;
  viewportsReady = true;

  for (let i = 0; i < state.viewports.length; i++) {
    const vp = state.viewports[i];
    if (!vp?.element) continue;

    try { cornerstone.enable(vp.element); } catch (e) {
      console.error('cornerstone.enable failed for vp', i, e);
      continue;
    }

    try { vp.element.style.touchAction = 'none'; } catch {}

    // ✅ Shared pinch binding (works on all viewports)
    bindPinchZoom(i);

    const w = vp.element.clientWidth;
    const h = vp.element.clientHeight;
    if (w < 2 || h < 2) {
      console.error(`Viewport ${i} has invalid dimensions: ${w}×${h}. Skipping.`);
      continue;
    }

    bindMouseWheelScroll(i);
    bindDoubleClickMaximize(i);

    vp.element.addEventListener('pointerdown', () => setActiveViewport(i), { passive: true });

    vp.element.addEventListener(cornerstone.EVENTS.NEW_IMAGE, (evt) => onNewImage(evt, i));
    vp.element.addEventListener(cornerstone.EVENTS.IMAGE_RENDERED, () => {
      showLoading(i, false);
      updateScrollerPosition(i);
      updateCounters();
      updateViewportOverlays(i);
    });
  }

  registerTools();
  setActiveViewport(0);
  activateTool(state.activeTool);

  if (state.seriesData.length > 0) {
    await loadSeriesIntoViewport(0, 0);
  }

  updateCounters();
}

function prepareViewports(layout) {
  const grid = document.getElementById('viewportsGrid');
  if (!grid) return;

  state.layout = layout;
  grid.dataset.layout = layout;

  const map = { '1x1': 1, '1x2': 2, '2x2': 4, '1x3': 3 };
  const total = map[layout] || 1;

  for (let i = 0; i < 4; i++) {
    const cell = document.getElementById(`vp${i}`);
    if (cell) cell.style.display = i < total ? '' : 'none';
  }

  stopCine();
  state.viewports = [];
  for (let i = 0; i < total; i++) {
    state.viewports.push(createViewportState(i, 0));
  }
  viewportsReady = false;
}

function rebuildViewports(layout) {
    
  const grid = document.getElementById('viewportsGrid');
  if (!grid) return;

  state.layout = layout;
  grid.dataset.layout = layout;

  const map = { '1x1': 1, '1x2': 2, '2x2': 4, '1x3': 3 };
  const total = map[layout] || 1;

  for (let i = 0; i < 4; i++) {
    const cell = document.getElementById(`vp${i}`);
    if (cell) cell.style.display = i < total ? '' : 'none';
  }

  stopCine();

  // disable old enabled elements before rebuild
  state.viewports.forEach(vp => {
    if (vp?.element) {
      try { cornerstone.disable(vp.element); } catch {}
    }
  });

  state.viewports = [];

  requestAnimationFrame(() => {
    requestAnimationFrame(async () => {
      for (let i = 0; i < total; i++) {
        const el = document.getElementById(`csElement${i}`);
        if (!el) continue;

        // assign different series across layout slots
        const seriesIndex = Math.min(i, Math.max(0, state.seriesData.length - 1));
        const vp = createViewportState(i, seriesIndex);
        state.viewports.push(vp);

        try { cornerstone.enable(el); } catch (e) {
          console.error('enable failed vp', i, e);
          continue;
        }
        try { el.style.touchAction = 'none'; } catch {}

        // ✅ critical: bind pinch on rebuilt viewports too
        bindPinchZoom(i);

        bindMouseWheelScroll(i);
        bindDoubleClickMaximize(i);

        el.addEventListener('pointerdown', () => setActiveViewport(i), { passive: true });

        el.addEventListener(cornerstone.EVENTS.NEW_IMAGE, (evt) => onNewImage(evt, i));
        el.addEventListener(cornerstone.EVENTS.IMAGE_RENDERED, () => {
          showLoading(i, false);
          updateScrollerPosition(i);
          updateCounters();
          updateViewportOverlays(i);
        });
      }

      registerTools();
      setActiveViewport(0);
      activateTool(state.activeTool);

      for (let i = 0; i < state.viewports.length; i++) {
        const seriesIndex = Math.min(i, Math.max(0, state.seriesData.length - 1));
        if (state.seriesData[seriesIndex]?.instances?.length) {
          await loadSeriesIntoViewport(i, seriesIndex);
        }
      }

      setActiveViewport(0);
      activateTool(state.activeTool);
      updateCounters();
    });
  });
}

/* =========================================================
   Tools
========================================================= */
function registerTools() {
  if (toolsRegistered) return;

  const defs = [
    ['Pan', cornerstoneTools.PanTool],
    ['Zoom', cornerstoneTools.ZoomTool],
    ['PanMultiTouch', cornerstoneTools.PanMultiTouchTool],
    ['Wwwc', cornerstoneTools.WwwcTool],
    ['StackScroll', cornerstoneTools.StackScrollTool],
    ['Length', cornerstoneTools.LengthTool],
    ['Angle', cornerstoneTools.AngleTool],
    ['EllipticalRoi', cornerstoneTools.EllipticalRoiTool],
    ['RectangleRoi', cornerstoneTools.RectangleRoiTool],
    ['FreehandRoi', cornerstoneTools.FreehandRoiTool],
    ['Probe', cornerstoneTools.ProbeTool],
    ['TextMarker', cornerstoneTools.TextMarkerTool]
  ];

  defs.forEach(([name, Tool]) => {
    if (!Tool) {
      console.warn(`Tool constructor missing: ${name}`);
      return;
    }
    try {
      cornerstoneTools.addTool(Tool, { name });
    } catch (e) {
      if (!String(e?.message || '').includes('already added')) {
        console.error(`Failed to add tool ${name}:`, e);
      }
    }
  });

  toolsRegistered = true;
}

function activateTool(name) {
  const allTools = [
    'Pan','Zoom','Wwwc','StackScroll',
    'Length','Angle','EllipticalRoi','RectangleRoi','FreehandRoi','Probe','TextMarker'
  ];

  if (!allTools.includes(name)) name = 'Length';
  state.activeTool = name;

  allTools.forEach(t => {
    try { cornerstoneTools.setToolDisabled(t); } catch {}
  });

  try { cornerstoneTools.setToolActive(name, { mouseButtonMask: 1 }); }
  catch (e) { console.error('Primary tool activation failed:', name, e); }

  try { cornerstoneTools.setToolActive('StackScroll', { mouseButtonMask: 4 }); } catch {}
  try { cornerstoneTools.setToolActive('StackScroll', { isTouchActive: true }); } catch {}
  try { cornerstoneTools.setToolActive('PanMultiTouch', { isTouchActive: true }); } catch {}

  // FIX: Prevent TextMarker from hijacking the double-click
  try { cornerstoneTools.setToolDisabled('TextMarker', { mouseButtonMask: 2 }); } catch {}

  document.querySelectorAll('.tool-btn[data-tool]').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tool === name);
  });
}

function hideSplash() {
  const splash = document.getElementById('pacs-splash-screen');
  if (!splash) return;
  splash.classList.add('hidden');
  splash.style.pointerEvents = 'none';
  splash.style.display = 'none';
  splash.style.zIndex = '-1';
}

/* =========================================================
   Series + image loading
========================================================= */
function getInstanceImageId(instanceId) {
  return `wadouri:api.php?action=wado&instance=${instanceId}`;
}

function renderSeriesList() {
  const list = document.getElementById('seriesList');
  if (!list) return;
  list.innerHTML = '';

  if (!state.seriesData.length) {
    list.innerHTML = '<div class="meas-empty" style="padding:12px;">No series found.</div>';
    return;
  }

  state.seriesData.forEach((s, idx) => {
    const item = document.createElement('div');
    item.className = `series-item ${idx === 0 ? 'active' : ''}`;
    item.dataset.seriesIndex = String(idx);

    item.innerHTML = `
      <div class="series-thumb-wrap">
        <canvas class="series-thumb-canvas" width="160" height="120"></canvas>
        <span class="series-thumb-count">${(s.instances || []).length}</span>
      </div>
      <div class="series-info">
        <span class="series-num">S${s.number ?? (idx + 1)}</span>
        <span class="series-desc">${escapeHtml(s.description || 'Series')}</span>
        <span class="series-mod">${escapeHtml(s.modality || '')}</span>
      </div>
    `;

    item.addEventListener('click', async () => {
      stopCine();
      document.querySelectorAll('#seriesList .series-item').forEach(el => el.classList.remove('active'));
      item.classList.add('active');
      await loadSeriesIntoViewport(state.activeViewportIndex, idx);
    });

    list.appendChild(item);
  });
}

function createViewportState(vpIndex, seriesIndex) {
  const series = state.seriesData[seriesIndex] || null;
  const instances = series?.instances || [];
  return {
    index: vpIndex,
    element: document.getElementById(`csElement${vpIndex}`),
    series,
    instances,
    currentImageIndex: 0,
    stack: instances.map(inst => getInstanceImageId(inst.id)),
    loaded: false
  };
}

async function loadSeriesIntoViewport(vpIndex, seriesIndex) {
  const vp = state.viewports[vpIndex];
  const series = state.seriesData[seriesIndex];
  if (!vp || !series || !Array.isArray(series.instances) || series.instances.length === 0) return;

  try {
    cornerstone.getEnabledElement(vp.element);
  } catch {
    console.warn(`Viewport ${vpIndex} not yet enabled — skipping loadSeriesIntoViewport`);
    return;
  }

  stopCine();

  vp.series = series;
  vp.instances = series.instances;
  vp.currentImageIndex = 0;
  vp.stack = vp.instances.map(inst => getInstanceImageId(inst.id));
  vp.loaded = false;

  showLoading(vpIndex, true);

  try {
    try { cornerstone.resize(vp.element); } catch {}

    const image = await cornerstone.loadAndCacheImage(vp.stack[0]);
    cornerstone.displayImage(vp.element, image);
    applyInitialFitViewport(vp.element);

    try {
      cornerstoneTools.clearToolState(vp.element, 'stack');
      cornerstoneTools.addToolState(vp.element, 'stack', {
        imageIds: vp.stack,
        currentImageIdIndex: 0
      });
    } catch {}

    vp.loaded = true;
    updateScrollerRange(vpIndex);
    updateScrollerPosition(vpIndex);
    updateCounters();
    updateViewportOverlays(vpIndex);
  } catch (e) {
    console.error('loadSeriesIntoViewport error:', e);
  } finally {
    showLoading(vpIndex, false);
  }
}

async function scrollToImage(vpIndex, index) {
  const vp = state.viewports[vpIndex];
  if (!vp || !vp.stack || index < 0 || index >= vp.stack.length) return;

  vp.currentImageIndex = index;
  showLoading(vpIndex, true);

  try {
    const image = await cornerstone.loadAndCacheImage(vp.stack[index]);
    cornerstone.displayImage(vp.element, image);

    const stackState = cornerstoneTools.getToolState(vp.element, 'stack');
    if (stackState?.data?.[0]) stackState.data[0].currentImageIdIndex = index;

    updateScrollerPosition(vpIndex);
    updateCounters();
    updateViewportOverlays(vpIndex);
  } catch (e) {
    console.error('scrollToImage error:', e);
  } finally {
    showLoading(vpIndex, false);
  }
}

function applyInitialFitViewport(element) {
  try {
    const ee = cornerstone.getEnabledElement(element);
    if (!ee || !ee.image) return;

    // make sure the canvas matches its DOM box before we compute the fit
    cornerstone.resize(element, false);

    // build a FRESH viewport for this image instead of inheriting the old one
    const fresh = cornerstone.getDefaultViewport(ee.canvas, ee.image);

    // the 8% margin is now applied once, to a freshly fitted scale
    fresh.scale = Math.max(0.1, fresh.scale * 0.92);

    cornerstone.setViewport(element, fresh);
  } catch {}
}

function onNewImage(evt, vpIndex) {
  const vp = state.viewports[vpIndex];
  if (!vp) return;

  const imageId = evt?.detail?.image?.imageId || evt?.detail?.imageId;
  if (!imageId) return;

  const idx = vp.stack.indexOf(imageId);
  if (idx >= 0) vp.currentImageIndex = idx;
}

/* =========================================================
   Preload
========================================================= */
function setCineButtonEnabled(enabled) {
  const btn = document.getElementById('btnCinePlay');
  if (!btn) return;
  btn.disabled = !enabled;
  btn.style.opacity = enabled ? '1' : '0.5';
  btn.style.pointerEvents = enabled ? 'auto' : 'none';
}

async function preloadActiveSeriesPriority(vpIndex = 0, maxImages = 200) {
  const series = state.seriesData[0];
  if (!series?.instances?.length) return;

  const stack = series.instances.map(inst => getInstanceImageId(inst.id));
  const total = Math.min(stack.length, Math.max(1, maxImages));
  const concurrency = 4;
  let done = 0;
  let cursor = 0;
  const base = Math.max(55, splashPct);

  async function worker() {
    while (cursor < total) {
      const i = cursor++;
      try { await cornerstone.loadAndCacheImage(stack[i]); } catch {}
      done++;
      const pct = base + (done / total) * (95 - base);
      setSplashProgress(pct, `[CACHE] PRELOADING FIRST SERIES ${done}/${total}...`);
    }
  }

  await Promise.all(Array.from({ length: concurrency }, worker));
}

async function preloadRemainingSeriesInBackground() {
  const activeSeriesId = state.seriesData[0]?.id ?? null;
  const ids = [];

  const vp0 = state.viewports[0];
  if (vp0?.stack?.length > 200) ids.push(...vp0.stack.slice(200));

  for (const s of (state.seriesData || [])) {
    if (s?.id === activeSeriesId) continue;
    for (const inst of (s.instances || [])) {
      if (inst?.id) ids.push(getInstanceImageId(inst.id));
    }
  }

  const concurrency = 2;
  let cursor = 0;

  async function worker() {
    while (cursor < ids.length) {
      const i = cursor++;
      try { await cornerstone.loadAndCacheImage(ids[i]); } catch {}
      await new Promise(r => setTimeout(r, 0));
    }
  }

  await Promise.all(Array.from({ length: concurrency }, worker));
}

/* =========================================================
   Overlay
========================================================= */
function updateViewportOverlays(vpIndex) {
  const vp = state.viewports[vpIndex];
  if (!vp) return;

  const tr = document.getElementById(`ovTR${vpIndex}`);
  const tl = document.getElementById(`ovTL${vpIndex}`);
  if (!tr && !tl) return;

  let image = null;
  try { image = cornerstone.getEnabledElement(vp.element)?.image || null; } catch {}

  const ds = image?.data || null;
  const dsVal = (tag) => {
    try { return ds?.string?.(tag) || ''; } catch { return ''; }
  };

  const fmtDate = (v) => (v && v.length >= 8) ? `${v.slice(0,4)}-${v.slice(4,6)}-${v.slice(6,8)}` : '-';
  const fmtTime = (v) => {
    if (!v) return '-';
    const t = String(v).replace(/[^\d]/g, '');
    return t.length >= 6 ? `${t.slice(0,2)}:${t.slice(2,4)}:${t.slice(4,6)}` : String(v);
  };

  const patientName =
    (window.DICOM_STUDY?.patient_name || '').trim() ||
    (document.querySelector('.vh-name')?.textContent || '').trim() ||
    '—';

  const studyDate = fmtDate(dsVal('x00080020') || dsVal('x00080022'));
  const acqTime = fmtTime(dsVal('x00080032') || dsVal('x00080030'));

  const totalSeries = Math.max(1, state.seriesData.length);
  const currentSeriesIndex = Math.max(0, state.seriesData.findIndex(s => s?.id === vp.series?.id));
  const seLine = `${currentSeriesIndex + 1}/${totalSeries}`;

  const totalImages = Math.max(1, vp.instances?.length || 0);
  const imLine = `${(vp.currentImageIndex || 0) + 1}/${totalImages}`;

  const sliceLoc = dsVal('x00201041') || '-';
  const laterality = dsVal('x00200060') || dsVal('x00200062') || '-';

  const reconDiam = dsVal('x00181100');
  const dfov = reconDiam && !Number.isNaN(Number(reconDiam))
    ? `${(Number(reconDiam) / 10).toFixed(1)} x ${(Number(reconDiam) / 10).toFixed(1)} cm`
    : '-';

  if (tl) tl.textContent = patientName;
  if (tr) {
    tr.textContent =
`Date:  ${studyDate}
Acq:   ${acqTime}
Se:    ${seLine}
Im:    ${imLine}
Loc:   ${sliceLoc}
Lat:   ${laterality}
DFOV:  ${dfov}`;
  }
}

/* =========================================================
   UI interactions
========================================================= */
function showLoading(vpIndex, show) {
  const el = document.getElementById(`vpLoading${vpIndex}`);
  if (!el) return;
  el.style.display = show ? 'flex' : 'none';
}

function setActiveViewport(index) {
  state.activeViewportIndex = index;
  document.querySelectorAll('.viewport-cell').forEach((cell, i) => {
    cell.classList.toggle('active-cell', i === index);
  });
  updateScrollerRange(index);
  updateCounters();
}

// Helper to keep layout buttons visually synced
function updateLayoutButtonState(layout) {
  const map = { '1x1': 'btn1x1', '1x2': 'btn1x2', '2x2': 'btn2x2', '1x3': 'btn1x3' };
  Object.values(map).forEach(id => {
    const b = document.getElementById(id);
    if (b) b.classList.remove('active');
  });
  const activeId = map[layout];
  if (activeId) {
    const b = document.getElementById(activeId);
    if (b) b.classList.add('active');
  }
}

function bindToolbarEvents() {
  if (bindToolbarEvents._bound) return;
  bindToolbarEvents._bound = true;

  document.querySelectorAll('.tool-btn[data-tool]').forEach(btn => {
    btn.addEventListener('click', () => activateTool(btn.dataset.tool));
  });

  bindClick('btn1x1', () => { rebuildViewports('1x1'); updateLayoutButtonState('1x1'); });
  bindClick('btn1x2', () => { rebuildViewports('1x2'); updateLayoutButtonState('1x2'); });
  bindClick('btn2x2', () => { rebuildViewports('2x2'); updateLayoutButtonState('2x2'); });
  bindClick('btn1x3', () => { rebuildViewports('1x3'); updateLayoutButtonState('1x3'); });

  bindClick('btnReset', () => {
    const vp = activeViewport();
    if (!vp?.loaded) return;
    
    try {
      cornerstone.reset(vp.element);
      
      const toolNames = ['Length', 'Angle', 'EllipticalRoi', 'RectangleRoi', 'FreehandRoi', 'Probe', 'TextMarker'];
      toolNames.forEach(t => {
        try { cornerstoneTools.clearToolState(vp.element, t); } catch (e) {}
      });
      
      cornerstone.updateImage(vp.element);
    } catch (e) {
      console.error('Reset failed:', e);
    }
  });

  bindClick('btnInvert', () => {
    const vp = activeViewport();
    if (!vp?.loaded) return;
    const v = cornerstone.getViewport(vp.element);
    v.invert = !v.invert;
    cornerstone.setViewport(vp.element, v);
  });

  bindClick('btnRotateL', () => rotate(-90));
  bindClick('btnRotateR', () => rotate(90));
  bindClick('btnFlipH', () => flip('h'));
  bindClick('btnFlipV', () => flip('v'));

  bindScreenshotButton();
}

// <--- UPDATED: Double-click / double-tap an image to make it 1x1 and keep zoom/pan
function bindDoubleClickMaximize(vpIndex) {
  const cell = document.getElementById(`vp${vpIndex}`);
  if (!cell) return;

  const maximizeAction = async () => {
    if (state.layout === '1x1') return;
    
    const clickedVp = state.viewports[vpIndex];
    if (!clickedVp?.loaded) return;
    
    const seriesId = clickedVp.series?.id;
    const imageIndex = clickedVp.currentImageIndex || 0;
    
    // 1. SAVE the current zoom, pan, and flip states before rebuilding
    let savedViewport = null;
    try {
      savedViewport = cornerstone.getViewport(clickedVp.element);
    } catch (e) {
      console.warn('Could not save viewport state for maximize', e);
    }
    
    // 2. Switch to 1x1 layout
    rebuildViewports('1x1');
    updateLayoutButtonState('1x1');
    
    // Wait for the DOM to settle
    await new Promise(r => setTimeout(r, 50));
    
    // 3. Load the series back into the single viewport
    if (seriesId != null) {
      const seriesIndex = state.seriesData.findIndex(s => s.id === seriesId);
      if (seriesIndex >= 0) {
        await loadSeriesIntoViewport(0, seriesIndex);
        
        // Scroll back to the exact image the user was looking at
        if (imageIndex > 0) {
          await scrollToImage(0, imageIndex);
        }
      }
    }
    
    // 4. RESTORE the saved zoom and pan state
    if (savedViewport) {
      try {
        const newVp = state.viewports[0];
        if (newVp?.loaded && newVp?.element) {
          const currentVp = cornerstone.getViewport(newVp.element);
          // Apply the old scale, translation, and flips to the new viewport
          currentVp.scale = savedViewport.scale;
          currentVp.translation = savedViewport.translation;
          currentVp.hflip = savedViewport.hflip;
          currentVp.vflip = savedViewport.vflip;
          currentVp.rotation = savedViewport.rotation;
          currentVp.invert = savedViewport.invert;
          
          cornerstone.setViewport(newVp.element, currentVp);
        }
      } catch (e) {
        console.warn('Could not restore viewport state after maximize', e);
      }
    }
  };

  // Desktop: Use native dblclick
  cell.addEventListener('dblclick', maximizeAction);

  // Mobile: Detect double-tap manually
  let lastTapTime = 0;
  let lastTapX = 0;
  let lastTapY = 0;
  const DOUBLE_TAP_DELAY = 300; 
  const DOUBLE_TAP_DISTANCE = 40; 

  cell.addEventListener('touchend', (e) => {
    const now = Date.now();
    const touch = e.changedTouches[0];
    
    if (!touch) return;

    const deltaX = Math.abs(touch.clientX - lastTapX);
    const deltaY = Math.abs(touch.clientY - lastTapY);

    if (
      now - lastTapTime < DOUBLE_TAP_DELAY &&
      deltaX < DOUBLE_TAP_DISTANCE &&
      deltaY < DOUBLE_TAP_DISTANCE
    ) {
      e.preventDefault(); 
      maximizeAction();
      lastTapTime = 0; 
    } else {
      lastTapTime = now;
    }

    lastTapX = touch.clientX;
    lastTapY = touch.clientY;
  }, { passive: false });

  // Prevent browser's native double-tap zoom on the images
  cell.addEventListener('touchstart', (e) => {
    if (e.touches.length === 1) {
      const meta = document.querySelector('meta[name="viewport"]');
      if (meta) {
        meta.setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover');
      }
    }
  }, { passive: true });
}

function bindCineControls() {
  const btn = document.getElementById('btnCinePlay');
  const speed = document.getElementById('cineSpeed');
  const fpsLabel = document.getElementById('cineFpsLabel');

  if (speed) {
    state.cineFps = parseInt(speed.value || '15', 10) || 15;
    if (fpsLabel) fpsLabel.textContent = `${state.cineFps} fps`;

    speed.addEventListener('input', () => {
      state.cineFps = parseInt(speed.value || '15', 10) || 15;
      if (fpsLabel) fpsLabel.textContent = `${state.cineFps} fps`;
      if (state.cineRunning) {
        stopCine();
        startCine();
      }
    });
  }

  if (btn) {
    btn.addEventListener('click', () => {
      if (btn.disabled) return;
      if (state.cineRunning) stopCine();
      else startCine();
    });
  }
}

function startCine() {
  const vp = activeViewport();
  if (!vp || !vp.loaded || !vp.instances || vp.instances.length < 2) return;

  stopCine();
  state.cineRunning = true;

  const btn = document.getElementById('btnCinePlay');
  if (btn) btn.innerHTML = '<i class="fas fa-pause"></i>';

  const fps = Math.max(1, Math.min(60, Number(state.cineFps) || 15));
  const delay = Math.round(1000 / fps);

  state.cineInterval = setInterval(async () => {
    const liveVp = activeViewport();
    if (!liveVp || !liveVp.instances || liveVp.instances.length < 2) return;
    const next = ((liveVp.currentImageIndex || 0) + 1) % liveVp.instances.length;
    await scrollToImage(state.activeViewportIndex, next);
  }, delay);
}

function stopCine() {
  state.cineRunning = false;
  if (state.cineInterval) clearInterval(state.cineInterval);
  state.cineInterval = null;

  const btn = document.getElementById('btnCinePlay');
  if (btn) btn.innerHTML = '<i class="fas fa-play"></i>';
}

function bindScrollerEvents() {
  const scroller = document.getElementById('imageScroller');
  if (!scroller) return;

  scroller.addEventListener('input', () => {
    if (state.cineRunning) stopCine();
    const vp = activeViewport();
    if (!vp?.loaded) return;
    scrollToImage(state.activeViewportIndex, parseInt(scroller.value, 10));
  });
}

function bindKeyboardShortcuts() {
  document.addEventListener('keydown', (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;

    const vp = activeViewport();

    if (e.key === ' ') {
      e.preventDefault();
      if (state.cineRunning) stopCine();
      else startCine();
      return;
    }

    if (!vp?.loaded) return;

    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
      e.preventDefault();
      if (state.cineRunning) stopCine();
      scrollToImage(state.activeViewportIndex, Math.min(vp.currentImageIndex + 1, vp.instances.length - 1));
    }

    if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (state.cineRunning) stopCine();
      scrollToImage(state.activeViewportIndex, Math.max(vp.currentImageIndex - 1, 0));
    }
  });
}

function bindMouseWheelScroll(vpIndex) {
  const vp = state.viewports[vpIndex];
  if (!vp?.element) return;

  if (vp.element._wheelHandler) vp.element.removeEventListener('wheel', vp.element._wheelHandler);

  vp.element._wheelAccum = 0;
  vp.element._wheelLocked = false;
  vp.element._lastWheelTs = 0;

  const WHEEL_THRESHOLD = 60;
  const LOCK_MS = 45;
  const RESET_GAP_MS = 140;

  const onWheel = (e) => {
    const liveVp = state.viewports[vpIndex];
    if (!liveVp?.loaded || !liveVp.instances?.length) return;

    e.preventDefault();
    if (state.cineRunning) stopCine();

    const now = performance.now();
    if (now - liveVp.element._lastWheelTs > RESET_GAP_MS) liveVp.element._wheelAccum = 0;
    liveVp.element._lastWheelTs = now;

    let delta = e.deltaY;
    if (e.deltaMode === 1) delta *= 16;
    if (e.deltaMode === 2) delta *= 120;
    liveVp.element._wheelAccum += delta;

    if (liveVp.element._wheelLocked) return;
    if (Math.abs(liveVp.element._wheelAccum) < WHEEL_THRESHOLD) return;

    const dir = liveVp.element._wheelAccum > 0 ? 1 : -1;
    liveVp.element._wheelAccum = 0;

    const next = Math.max(0, Math.min((liveVp.currentImageIndex || 0) + dir, liveVp.instances.length - 1));
    if (next !== liveVp.currentImageIndex) {
      liveVp.element._wheelLocked = true;
      Promise.resolve(scrollToImage(vpIndex, next)).finally(() => {
        setTimeout(() => { liveVp.element._wheelLocked = false; }, LOCK_MS);
      });
    }
  };

  vp.element._wheelHandler = onWheel;
  vp.element.addEventListener('wheel', onWheel, { passive: false });
}

/* =========================================================
   Transform actions
========================================================= */
function rotate(delta) {
  const vp = activeViewport();
  if (!vp?.loaded) return;
  const v = cornerstone.getViewport(vp.element);
  v.rotation = ((v.rotation || 0) + delta + 360) % 360;
  cornerstone.setViewport(vp.element, v);
}

function flip(dir) {
  const vp = activeViewport();
  if (!vp?.loaded) return;
  const v = cornerstone.getViewport(vp.element);
  if (dir === 'h') v.hflip = !v.hflip;
  else v.vflip = !v.vflip;
  cornerstone.setViewport(vp.element, v);
}

/* =========================================================
   Counters/scroller
========================================================= */
function updateScrollerRange(vpIndex) {
  if (vpIndex !== state.activeViewportIndex) return;
  const vp = state.viewports[vpIndex];
  const s = document.getElementById('imageScroller');
  if (!vp || !s) return;
  s.max = Math.max(0, vp.instances.length - 1);
  s.value = vp.currentImageIndex || 0;
}

function updateScrollerPosition(vpIndex) {
  if (vpIndex !== state.activeViewportIndex) return;
  const vp = state.viewports[vpIndex];
  const s = document.getElementById('imageScroller');
  if (!vp || !s) return;
  s.value = vp.currentImageIndex || 0;
}

function updateCounters() {
  const vp = activeViewport();
  if (!vp) return;
  setText('imgCurrent', String((vp.currentImageIndex || 0) + 1));
  setText('imgTotal', String(vp.instances?.length || 0));
}

function activeViewport() {
  return state.viewports[state.activeViewportIndex] || null;
}

/* =========================================================
   Helpers
========================================================= */
function bindClick(id, fn) {
  const el = document.getElementById(id);
  if (el) el.addEventListener('click', fn);
}

function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

function escapeHtml(str) {
  return String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function setInterpolation(enabled) {
  const vp = activeViewport();
  if (!vp?.loaded) return;
  const v = cornerstone.getViewport(vp.element);
  v.pixelReplication = !enabled;
  cornerstone.setViewport(vp.element, v);
}

/* =========================================================
   Screenshot
========================================================= */
function bindScreenshotButton() {
  const btn = document.getElementById('btnScreenshot');
  if (!btn) return;

  btn.addEventListener('click', async () => {
    const vp = activeViewport();
    if (!vp?.element) {
      showScreenshotToast('No active viewport found', true);
      return;
    }

    btn.style.background = 'rgba(60,208,143,0.25)';
    setTimeout(() => { btn.style.background = ''; }, 300);

    const canvas = vp.element.querySelector('canvas');
    if (!canvas) {
      showScreenshotToast('No image found to capture', true);
      return;
    }

    try {
      if (navigator.permissions) {
        try {
          const result = await navigator.permissions.query({ name: 'clipboard-write' });
          if (result.state === 'denied') {
            showScreenshotToast('Clipboard permission is denied in browser settings', true);
            return;
          }
        } catch {}
      }

      const blob = await new Promise((resolve, reject) => {
        canvas.toBlob(b => {
          if (!b) reject(new Error('Canvas toBlob failed. Image might be blocked by CORS.'));
          else resolve(b);
        }, 'image/png');
      });

      await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
      showScreenshotToast('Image copied to clipboard!', false);
    } catch (err) {
      console.error('Screenshot to clipboard failed:', err);
      if (err?.message?.includes('CORS') || err?.message?.includes('tainted') || err?.message?.includes('toBlob failed')) {
        showScreenshotToast('CORS Error: Server must allow image sharing', true);
      } else if (err?.name === 'NotAllowedError') {
        showScreenshotToast('Browser blocked clipboard access', true);
      } else {
        showScreenshotToast('Copy failed: ' + (err?.message || 'Unknown error'), true);
      }
    }
  });
}

function showScreenshotToast(message, isError = false) {
  const existing = document.querySelector('.screenshot-toast');
  if (existing) existing.remove();

  const toast = document.createElement('div');
  toast.className = `screenshot-toast ${isError ? 'error' : ''}`;
  toast.textContent = message;
  document.body.appendChild(toast);

  requestAnimationFrame(() => toast.classList.add('show'));

  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 3500);
}