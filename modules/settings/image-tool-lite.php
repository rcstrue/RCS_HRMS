<?php
/**
 * RCS HRMS Pro - Lightweight Image Editor (Embeddable Modal)
 * Built with Fabric.js | Crop + Rotate + Flip only
 * 
 * This file is meant to be included inside other PHP pages (e.g., id-card.php, employee/add.php).
 * It provides a compact modal overlay for quick photo editing (crop + rotate) in the context
 * of employee management — NOT a full image editor.
 * 
 * Usage:
 *   Include this file in your PHP page (after header/footer).
 *   Call from JS: openLiteEditor(imageSrc, onSaveCallback)
 *     - imageSrc: URL of the image to edit
 *     - onSaveCallback: function(base64DataUrl) called when user clicks Save
 *   Close with: closeLiteEditor()
 * 
 * Includes:
 *   - fabric.min.js (via <script> tag)
 *   - All CSS scoped under .iel- prefix to avoid conflicts
 *   - All JS in an IIFE, exposes only openLiteEditor / closeLiteEditor globally
 */

$pageTitle = 'Image Editor Lite';

?>
<script src="assets/js/fabric.min.js"></script>

<!-- ===== Lite Editor Modal (initially hidden) ===== -->
<div id="ielModal" style="display:none;">
  <style>
    /* ===== Scoped CSS Variables ===== */
    .iel-modal-overlay {
      --iel-bg-darkest: #0a0c10;
      --iel-bg-dark: #0f1219;
      --iel-bg-main: #141821;
      --iel-bg-card: #1a1f2e;
      --iel-bg-elevated: #222839;
      --iel-bg-hover: #2a3145;
      --iel-border: #2a3145;
      --iel-border-light: #353d55;
      --iel-accent: #6366f1;
      --iel-accent-hover: #818cf8;
      --iel-accent-glow: rgba(99,102,241,0.25);
      --iel-green: #22c55e;
      --iel-red: #ef4444;
      --iel-text: #e2e8f0;
      --iel-text-secondary: #94a3b8;
      --iel-text-muted: #64748b;
      --iel-radius: 10px;
      --iel-radius-sm: 6px;
      --iel-radius-xs: 4px;
      --iel-shadow: 0 8px 40px rgba(0,0,0,0.5);
      --iel-transition: all 0.15s ease;
    }

    /* ===== Modal Overlay ===== */
    .iel-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.7);
      backdrop-filter: blur(4px);
      z-index: 100000;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      font-size: 13px;
      color: var(--iel-text);
    }

    /* ===== Modal Container ===== */
    .iel-modal {
      background: var(--iel-bg-darkest);
      border: 1px solid var(--iel-border);
      border-radius: var(--iel-radius);
      width: 660px;
      max-width: 95vw;
      max-height: 95vh;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      box-shadow: 0 24px 80px rgba(0,0,0,0.6);
      animation: ielFadeIn 0.2s ease;
    }
    @keyframes ielFadeIn {
      from { opacity: 0; transform: scale(0.96) translateY(10px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }

    /* ===== Header ===== */
    .iel-header {
      display: flex;
      align-items: center;
      background: var(--iel-bg-dark);
      border-bottom: 1px solid var(--iel-border);
      padding: 0 14px;
      height: 44px;
      gap: 10px;
      flex-shrink: 0;
    }
    .iel-header-title {
      font-weight: 700;
      font-size: 13px;
      color: var(--iel-accent);
      display: flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
    }
    .iel-header-title i { font-size: 16px; }
    .iel-header-dims {
      margin-left: 8px;
      font-size: 11px;
      color: var(--iel-text-muted);
      font-variant-numeric: tabular-nums;
    }
    .iel-header-close {
      margin-left: auto;
      width: 30px;
      height: 30px;
      border-radius: var(--iel-radius-xs);
      border: none;
      background: transparent;
      color: var(--iel-text-muted);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      transition: var(--iel-transition);
    }
    .iel-header-close:hover { background: var(--iel-bg-hover); color: var(--iel-text); }

    /* ===== Toolbar ===== */
    .iel-toolbar {
      display: flex;
      align-items: center;
      background: var(--iel-bg-dark);
      border-bottom: 1px solid var(--iel-border);
      padding: 6px 14px;
      gap: 4px;
      flex-shrink: 0;
    }
    .iel-tool-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 6px 10px;
      border-radius: var(--iel-radius-xs);
      border: 1px solid transparent;
      background: transparent;
      color: var(--iel-text-secondary);
      cursor: pointer;
      font-size: 11px;
      font-weight: 500;
      transition: var(--iel-transition);
      white-space: nowrap;
    }
    .iel-tool-btn:hover { background: var(--iel-bg-hover); color: var(--iel-text); border-color: var(--iel-border); }
    .iel-tool-btn.active { background: var(--iel-accent-glow); color: var(--iel-accent); border-color: rgba(99,102,241,0.3); }
    .iel-tool-btn i { font-size: 14px; }
    .iel-tool-sep {
      width: 1px;
      height: 22px;
      background: var(--iel-border);
      margin: 0 4px;
    }

    /* ===== Canvas Area ===== */
    .iel-canvas-area {
      background: var(--iel-bg-main);
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 300px;
      max-height: 500px;
      overflow: hidden;
    }
    /* Checkerboard for transparency */
    .iel-canvas-area::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image:
        linear-gradient(45deg, #1a1f2e 25%, transparent 25%),
        linear-gradient(-45deg, #1a1f2e 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, #1a1f2e 75%),
        linear-gradient(-45deg, transparent 75%, #1a1f2e 75%);
      background-size: 20px 20px;
      background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
      opacity: 0.5;
      z-index: 0;
    }
    .iel-canvas-wrap {
      position: relative;
      z-index: 1;
      box-shadow: var(--iel-shadow);
      border-radius: 2px;
      overflow: visible !important;
    }
    /* Fabric's own canvas-container must allow overflow for crop handles */
    .iel-canvas-wrap .canvas-container {
      overflow: visible !important;
    }

    /* ===== Crop Overlay ===== */
    .iel-crop-overlay {
      position: absolute;
      border: 2px dashed var(--iel-accent);
      background: rgba(99,102,241,0.08);
      display: none;
      z-index: 9999;
      cursor: move;
    }
    .iel-crop-overlay .crop-handle {
      position: absolute;
      width: 10px;
      height: 10px;
      background: #fff;
      border: 2px solid var(--iel-accent);
      border-radius: 2px;
      z-index: 10000;
    }
    .iel-crop-overlay .crop-handle.tl { top: -5px; left: -5px; cursor: nw-resize; }
    .iel-crop-overlay .crop-handle.tr { top: -5px; right: -5px; cursor: ne-resize; }
    .iel-crop-overlay .crop-handle.bl { bottom: -5px; left: -5px; cursor: sw-resize; }
    .iel-crop-overlay .crop-handle.br { bottom: -5px; right: -5px; cursor: se-resize; }
    .iel-crop-overlay .crop-handle.tm { top: -5px; left: calc(50% - 5px); cursor: n-resize; }
    .iel-crop-overlay .crop-handle.bm { bottom: -5px; left: calc(50% - 5px); cursor: s-resize; }
    .iel-crop-overlay .crop-handle.ml { left: -5px; top: calc(50% - 5px); cursor: w-resize; }
    .iel-crop-overlay .crop-handle.mr { right: -5px; top: calc(50% - 5px); cursor: e-resize; }
    .iel-crop-dim {
      position: absolute;
      top: -28px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--iel-accent);
      color: #fff;
      font-size: 11px;
      padding: 2px 8px;
      border-radius: 3px;
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
      pointer-events: none;
    }

    /* ===== Crop Options Panel ===== */
    .iel-crop-opts {
      display: none;
      align-items: center;
      background: var(--iel-bg-dark);
      border-top: 1px solid var(--iel-border);
      padding: 8px 14px;
      gap: 6px;
      flex-shrink: 0;
    }
    .iel-crop-opts.visible { display: flex; }
    .iel-crop-opts-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--iel-text-muted);
      margin-right: 4px;
      white-space: nowrap;
    }
    .iel-aspect-btn {
      padding: 4px 10px;
      border-radius: var(--iel-radius-xs);
      border: 1px solid var(--iel-border);
      background: var(--iel-bg-card);
      color: var(--iel-text-secondary);
      font-size: 10px;
      cursor: pointer;
      transition: var(--iel-transition);
      white-space: nowrap;
    }
    .iel-aspect-btn:hover { border-color: var(--iel-text-muted); }
    .iel-aspect-btn.active { background: var(--iel-accent); border-color: var(--iel-accent); color: #fff; }
    .iel-crop-apply {
      margin-left: auto;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 12px;
      border-radius: var(--iel-radius-xs);
      border: 1px solid var(--iel-accent);
      background: var(--iel-accent);
      color: #fff;
      cursor: pointer;
      font-size: 11px;
      font-weight: 600;
      transition: var(--iel-transition);
    }
    .iel-crop-apply:hover { background: var(--iel-accent-hover); }
    .iel-crop-apply i { font-size: 13px; }

    /* ===== Footer ===== */
    .iel-footer {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      background: var(--iel-bg-dark);
      border-top: 1px solid var(--iel-border);
      padding: 10px 14px;
      gap: 8px;
      flex-shrink: 0;
    }
    .iel-btn {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 7px 18px;
      border-radius: var(--iel-radius-sm);
      border: 1px solid var(--iel-border);
      background: var(--iel-bg-card);
      color: var(--iel-text-secondary);
      cursor: pointer;
      font-size: 12px;
      font-weight: 500;
      transition: var(--iel-transition);
      white-space: nowrap;
    }
    .iel-btn:hover { background: var(--iel-bg-hover); color: var(--iel-text); border-color: var(--iel-border-light); }
    .iel-btn.primary { background: var(--iel-accent); border-color: var(--iel-accent); color: #fff; }
    .iel-btn.primary:hover { background: var(--iel-accent-hover); }
    .iel-btn.success { background: var(--iel-green); border-color: var(--iel-green); color: #fff; }
    .iel-btn.success:hover { filter: brightness(1.1); }
  </style>

  <!-- Modal HTML -->
  <div class="iel-modal-overlay" id="ielOverlay">
    <div class="iel-modal">
      <!-- Header -->
      <div class="iel-header">
        <div class="iel-header-title">
          <i class="bi bi-crop"></i>
          <span>Edit Photo</span>
        </div>
        <span class="iel-header-dims" id="ielDims">--</span>
        <button class="iel-header-close" id="ielCloseBtn" title="Close">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>

      <!-- Toolbar -->
      <div class="iel-toolbar">
        <button class="iel-tool-btn" id="ielRotateLeft" title="Rotate Left 90°">
          <i class="bi bi-arrow-counterclockwise"></i> Left
        </button>
        <button class="iel-tool-btn" id="ielRotateRight" title="Rotate Right 90°">
          <i class="bi bi-arrow-clockwise"></i> Right
        </button>
        <div class="iel-tool-sep"></div>
        <button class="iel-tool-btn" id="ielFlipH" title="Flip Horizontal">
          <i class="bi bi-arrow-left-right"></i> Flip H
        </button>
        <button class="iel-tool-btn" id="ielFlipV" title="Flip Vertical">
          <i class="bi bi-arrow-up-down"></i> Flip V
        </button>
        <div class="iel-tool-sep"></div>
        <button class="iel-tool-btn" id="ielCropBtn" title="Crop">
          <i class="bi bi-crop"></i> Crop
        </button>
      </div>

      <!-- Canvas Area -->
      <div class="iel-canvas-area" id="ielCanvasArea">
        <div class="iel-canvas-wrap" id="ielCanvasWrap">
          <canvas id="ielCanvas"></canvas>
        </div>
        <!-- Crop overlay will be appended to Fabric's .canvas-container at runtime -->
      </div>

      <!-- Crop Options Panel (hidden by default) -->
      <div class="iel-crop-opts" id="ielCropOpts">
        <span class="iel-crop-opts-label">Ratio:</span>
        <button class="iel-aspect-btn active" data-ratio="free">Free</button>
        <button class="iel-aspect-btn" data-ratio="1:1">1:1</button>
        <button class="iel-aspect-btn" data-ratio="3:4">3:4</button>
        <button class="iel-aspect-btn" data-ratio="2:3">2:3</button>
        <button class="iel-crop-apply" id="ielApplyCrop">
          <i class="bi bi-check-lg"></i> Apply Crop
        </button>
      </div>

      <!-- Footer -->
      <div class="iel-footer">
        <button class="iel-btn" id="ielCancelBtn">
          <i class="bi bi-x-lg"></i> Cancel
        </button>
        <button class="iel-btn success" id="ielSaveBtn">
          <i class="bi bi-check-lg"></i> Save
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
    // ===== State =====
    const s = {
        fc: null,                  // Fabric canvas instance
        onSave: null,              // Save callback
        isCropping: false,
        cropRect: { x: 0, y: 0, w: 0, h: 0 },
        cropRatio: null,           // e.g., '1:1' or null for free
        cropDrag: null,
        flipH: false,
        flipV: false,
        originalImg: null,         // Original HTMLImageElement
        originalW: 0,
        originalH: 0,
        displayScale: 1,           // Current display scale factor
        originalFormat: 'jpeg'     // Original image format (jpeg/png/webp)
    };

    // Max canvas display area
    const MAX_W = 600;
    const MAX_H = 460;

    // ===== DOM helpers =====
    const $ = id => document.getElementById(id);
    const overlay = $('ielOverlay');
    const canvasArea = $('ielCanvasArea');
    const canvasWrap = $('ielCanvasWrap');
    const dimsEl = $('ielDims');
    const cropOpts = $('ielCropOpts');

    // ===== Fabric.js alphabetical → alphabetic patch =====
    function patchFabric() {
        if (typeof fabric === 'undefined') return;
        const origSetText = fabric.util.setTextStyles;
        if (origSetText) {
            fabric.util.setTextStyles = function(ctx, style, object) {
                if (style && style.textBaseline === 'alphabetical') {
                    style.textBaseline = 'alphabetic';
                }
                return origSetText.call(this, ctx, style, object);
            };
        }
    }

    // ===== Create crop overlay DOM =====
    function createCropOverlay() {
        const ov = document.createElement('div');
        ov.className = 'iel-crop-overlay';
        ov.id = 'ielCropOverlay';
        ov.innerHTML = `
            <span class="iel-crop-dim" id="ielCropDim"></span>
            <div class="crop-handle tl" data-dir="tl"></div>
            <div class="crop-handle tr" data-dir="tr"></div>
            <div class="crop-handle bl" data-dir="bl"></div>
            <div class="crop-handle br" data-dir="br"></div>
            <div class="crop-handle tm" data-dir="tm"></div>
            <div class="crop-handle bm" data-dir="bm"></div>
            <div class="crop-handle ml" data-dir="ml"></div>
            <div class="crop-handle mr" data-dir="mr"></div>
        `;
        return ov;
    }

    // ===== Initialize Fabric Canvas =====
    function initCanvas() {
        patchFabric();

        // Create the Fabric canvas
        s.fc = new fabric.Canvas('ielCanvas', {
            selection: false,
            preserveObjectStacking: true,
            backgroundColor: null
        });

        // Create and append crop overlay into Fabric's .canvas-container
        const fabricContainer = canvasWrap.querySelector('.canvas-container');
        if (fabricContainer) {
            const cropOv = createCropOverlay();
            fabricContainer.appendChild(cropOv);
        }
    }

    // ===== Load Image into Canvas =====
    function loadImage(src) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                s.originalImg = img;
                s.originalW = img.naturalWidth;
                s.originalH = img.naturalHeight;
                s.flipH = false;
                s.flipV = false;

                // Detect original format from URL extension
                var ext = (src.split('?')[0].match(/\.([a-z0-9]+)$/i) || ['',''])[1].toLowerCase();
                if (ext === 'png') s.originalFormat = 'png';
                else if (ext === 'webp') s.originalFormat = 'webp';
                else s.originalFormat = 'jpeg';

                // Also detect from data URL if needed
                if (src.startsWith('data:image/')) {
                    var m = src.match(/data:image\/(\w+)/);
                    if (m) s.originalFormat = (m[1] === 'jpeg') ? 'jpeg' : m[1];
                }

                // Calculate display scale to fit within MAX_W x MAX_H
                const scale = Math.min(MAX_W / img.naturalWidth, MAX_H / img.naturalHeight, 1);
                s.displayScale = scale;

                const dispW = Math.round(img.naturalWidth * scale);
                const dispH = Math.round(img.naturalHeight * scale);

                s.fc.setWidth(dispW);
                s.fc.setHeight(dispH);

                fabric.Image.fromURL(src, (fImg) => {
                    if (!fImg) { reject(new Error('Failed to load image')); return; }

                    s.fc.setBackgroundImage(fImg, s.fc.renderAll.bind(s.fc), {
                        scaleX: scale,
                        scaleY: scale,
                        originX: 'left',
                        originY: 'top'
                    });

                    dimsEl.textContent = img.naturalWidth + ' × ' + img.naturalHeight + ' px';
                    resolve();
                });
            };
            img.onerror = () => reject(new Error('Failed to load image'));
            img.src = src;
        });
    }

    // ===== Update dimensions display =====
    function updateDims() {
        const bgImg = s.fc.backgroundImage;
        if (bgImg) {
            const origW = bgImg.getOriginalSize().width;
            const origH = bgImg.getOriginalSize().height;
            dimsEl.textContent = origW + ' × ' + origH + ' px';
            s.originalW = origW;
            s.originalH = origH;
        }
    }

    // ===== Rotate =====
    function rotateImage(angle) {
        const bgImg = s.fc.backgroundImage;
        if (!bgImg) return;

        const fullW = bgImg.getOriginalSize().width;
        const fullH = bgImg.getOriginalSize().height;

        // Draw rotated image onto temp canvas at full resolution
        const tmpCanvas = document.createElement('canvas');
        tmpCanvas.width = (angle % 180 === 0) ? fullW : fullH;
        tmpCanvas.height = (angle % 180 === 0) ? fullH : fullW;
        const ctx = tmpCanvas.getContext('2d');

        ctx.translate(tmpCanvas.width / 2, tmpCanvas.height / 2);
        ctx.rotate(angle * Math.PI / 180);
        ctx.drawImage(bgImg.getElement(), -fullW / 2, -fullH / 2);

        const dataUrl = tmpCanvas.toDataURL('image/png');
        fabric.Image.fromURL(dataUrl, (newImg) => {
            if (!newImg) return;

            const newScale = Math.min(MAX_W / newImg.width, MAX_H / newImg.height, 1);
            s.displayScale = newScale;
            s.flipH = false;
            s.flipV = false;

            s.fc.clear();
            s.fc.setWidth(Math.round(newImg.width * newScale));
            s.fc.setHeight(Math.round(newImg.height * newScale));

            s.fc.setBackgroundImage(newImg, s.fc.renderAll.bind(s.fc), {
                scaleX: newScale,
                scaleY: newScale,
                originX: 'left',
                originY: 'top'
            });

            updateDims();
        });
    }

    // ===== Flip =====
    function flipImage(direction) {
        const bgImg = s.fc.backgroundImage;
        if (!bgImg) return;

        if (direction === 'h') {
            bgImg.set('scaleX', -bgImg.scaleX);
            s.flipH = !s.flipH;
        } else {
            bgImg.set('scaleY', -bgImg.scaleY);
            s.flipV = !s.flipV;
        }
        s.fc.renderAll();
    }

    // ===== Crop Mode =====
    function enterCropMode() {
        s.isCropping = true;
        const cw = s.fc.getWidth();
        const ch = s.fc.getHeight();

        // Initialize crop rect to 80% of canvas
        s.cropRect = {
            x: Math.round(cw * 0.1),
            y: Math.round(ch * 0.1),
            w: Math.round(cw * 0.8),
            h: Math.round(ch * 0.8)
        };

        // Update UI
        $('ielCropBtn').classList.add('active');
        cropOpts.classList.add('visible');

        // Show overlay
        const ov = $('ielCropOverlay');
        if (ov) {
            ov.style.position = 'absolute';
            ov.style.left = '0px';
            ov.style.top = '0px';
            ov.style.zIndex = '9999';
            ov.style.display = 'block';
            renderCropOverlay();
        }

        // Disable Fabric interactions
        s.fc.selection = false;
        s.fc.defaultCursor = 'crosshair';
        s.fc.forEachObject(obj => { obj.selectable = false; obj.evented = false; });
    }

    function exitCropMode() {
        s.isCropping = false;
        $('ielCropBtn').classList.remove('active');
        cropOpts.classList.remove('visible');

        const ov = $('ielCropOverlay');
        if (ov) ov.style.display = 'none';

        // Re-enable Fabric interactions
        s.fc.selection = false;
        s.fc.defaultCursor = 'default';
    }

    function renderCropOverlay() {
        const ov = $('ielCropOverlay');
        if (!ov) return;
        const r = s.cropRect;
        ov.style.left = r.x + 'px';
        ov.style.top = r.y + 'px';
        ov.style.width = r.w + 'px';
        ov.style.height = r.h + 'px';

        // Show real pixel dimensions
        const bgImg = s.fc.backgroundImage;
        if (bgImg) {
            const realW = Math.round(r.w / Math.abs(bgImg.scaleX));
            const realH = Math.round(r.h / Math.abs(bgImg.scaleY));
            $('ielCropDim').textContent = realW + ' × ' + realH;
        }
    }

    function applyCrop() {
        const bgImg = s.fc.backgroundImage;
        if (!bgImg) return;

        const r = s.cropRect;
        const sx = bgImg.scaleX;
        const sy = bgImg.scaleY;

        // Calculate source coordinates in original image space
        // Handle flipped coordinates
        let srcX, srcY, srcW, srcH;

        if (sx < 0) {
            const origDispW = s.originalW * s.displayScale;
            srcX = Math.round((origDispW - (r.x + r.w)) / Math.abs(sx));
            srcW = Math.round(r.w / Math.abs(sx));
        } else {
            srcX = Math.round(r.x / sx);
            srcW = Math.round(r.w / sx);
        }

        if (sy < 0) {
            const origDispH = s.originalH * s.displayScale;
            srcY = Math.round((origDispH - (r.y + r.h)) / Math.abs(sy));
            srcH = Math.round(r.h / Math.abs(sy));
        } else {
            srcY = Math.round(r.y / sy);
            srcH = Math.round(r.h / sy);
        }

        srcW = Math.max(1, srcW);
        srcH = Math.max(1, srcH);

        // Create a temp canvas to extract cropped region at original resolution
        const tmpCanvas = document.createElement('canvas');
        tmpCanvas.width = srcW;
        tmpCanvas.height = srcH;
        const ctx = tmpCanvas.getContext('2d');
        ctx.drawImage(bgImg.getElement(), srcX, srcY, srcW, srcH, 0, 0, srcW, srcH);

        const dataUrl = tmpCanvas.toDataURL('image/png');
        fabric.Image.fromURL(dataUrl, (newImg) => {
            if (!newImg) return;

            const newScale = Math.min(MAX_W / newImg.width, MAX_H / newImg.height, 1);
            s.displayScale = newScale;
            s.flipH = false;
            s.flipV = false;

            s.fc.clear();
            s.fc.setWidth(Math.round(newImg.width * newScale));
            s.fc.setHeight(Math.round(newImg.height * newScale));

            s.fc.setBackgroundImage(newImg, s.fc.renderAll.bind(s.fc), {
                scaleX: newScale,
                scaleY: newScale,
                originX: 'left',
                originY: 'top'
            });

            updateDims();
            exitCropMode();
        });
    }

    // ===== Crop Overlay Mouse Events =====
    function initCropEvents() {
        const ov = $('ielCropOverlay');
        if (!ov) return;

        ov.addEventListener('mousedown', (e) => {
            if (!s.isCropping) return;
            const handle = e.target.dataset?.dir;
            s.cropDrag = {
                type: handle ? 'resize' : 'move',
                dir: handle,
                startX: e.clientX,
                startY: e.clientY,
                startRect: { ...s.cropRect }
            };
            e.preventDefault();
            e.stopPropagation();
        });

        document.addEventListener('mousemove', (e) => {
            if (!s.cropDrag) return;
            const dx = e.clientX - s.cropDrag.startX;
            const dy = e.clientY - s.cropDrag.startY;
            let { x, y, w, h } = s.cropDrag.startRect;
            const cw = s.fc.getWidth();
            const ch = s.fc.getHeight();
            const dir = s.cropDrag.dir;

            if (s.cropDrag.type === 'move') {
                x = Math.max(0, Math.min(cw - w, x + dx));
                y = Math.max(0, Math.min(ch - h, y + dy));
            } else {
                // Resize with 8 handles
                if (dir === 'br') { w = Math.max(20, Math.min(cw - x, w + dx)); h = Math.max(20, Math.min(ch - y, h + dy)); }
                else if (dir === 'bl') { const nx = Math.min(x + w - 20, x + dx); w -= (nx - x); x = nx; h = Math.max(20, Math.min(ch - y, h + dy)); }
                else if (dir === 'tr') { w = Math.max(20, Math.min(cw - x, w + dx)); const ny = Math.min(y + h - 20, y + dy); h -= (ny - y); y = ny; }
                else if (dir === 'tl') { const nx = Math.min(x + w - 20, x + dx); w -= (nx - x); x = nx; const ny = Math.min(y + h - 20, y + dy); h -= (ny - y); y = ny; }
                else if (dir === 'mr') { w = Math.max(20, Math.min(cw - x, w + dx)); }
                else if (dir === 'ml') { const nx = Math.min(x + w - 20, x + dx); w -= (nx - x); x = nx; }
                else if (dir === 'bm') { h = Math.max(20, Math.min(ch - y, h + dy)); }
                else if (dir === 'tm') { const ny = Math.min(y + h - 20, y + dy); h -= (ny - y); y = ny; }

                // Apply aspect ratio constraint
                if (s.cropRatio) {
                    const [rw, rh] = s.cropRatio.split(':').map(Number);
                    const targetRatio = rw / rh;
                    if (dir === 'mr' || dir === 'ml' || dir === 'tr' || dir === 'br' || dir === 'bl' || dir === 'tl') {
                        h = w / targetRatio;
                    } else {
                        w = h * targetRatio;
                    }
                    w = Math.max(20, w);
                    h = Math.max(20, h);
                }

                x = Math.max(0, x);
                y = Math.max(0, y);
                w = Math.min(cw - x, w);
                h = Math.min(ch - y, h);
            }

            s.cropRect = { x, y, w, h };
            renderCropOverlay();
        });

        document.addEventListener('mouseup', () => {
            s.cropDrag = null;
        });
    }

    // ===== Aspect Ratio Buttons =====
    function initAspectBtns() {
        cropOpts.querySelectorAll('.iel-aspect-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                cropOpts.querySelectorAll('.iel-aspect-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                const ratio = btn.dataset.ratio;
                s.cropRatio = (ratio === 'free') ? null : ratio;

                // If crop is active and a ratio is selected, adjust current crop rect
                if (s.isCropping && s.cropRatio) {
                    const cw = s.fc.getWidth();
                    const ch = s.fc.getHeight();
                    const [rw, rh] = s.cropRatio.split(':').map(Number);
                    const targetRatio = rw / rh;

                    // Fit within current crop rect
                    let w = s.cropRect.w;
                    let h = w / targetRatio;

                    if (h > ch - s.cropRect.y) {
                        h = ch - s.cropRect.y;
                        w = h * targetRatio;
                    }
                    if (w > cw - s.cropRect.x) {
                        w = cw - s.cropRect.x;
                        h = w / targetRatio;
                    }

                    s.cropRect.w = w;
                    s.cropRect.h = h;
                    renderCropOverlay();
                }
            });
        });
    }

    // ===== Export at original resolution =====
    function exportImage() {
        const bgImg = s.fc.backgroundImage;
        if (!bgImg) return null;

        const origW = bgImg.getOriginalSize().width;
        const origH = bgImg.getOriginalSize().height;

        // Use Fabric's toDataURL with multiplier to get full resolution
        const multiplier = 1 / s.displayScale;
        return s.fc.toDataURL({
            format: s.originalFormat,
            quality: s.originalFormat === 'png' ? 1 : 0.92,
            multiplier: multiplier
        });
    }

    // ===== Toolbar Button Events =====
    function initToolbarEvents() {
        $('ielRotateLeft').addEventListener('click', () => {
            if (s.isCropping) exitCropMode();
            rotateImage(-90);
        });

        $('ielRotateRight').addEventListener('click', () => {
            if (s.isCropping) exitCropMode();
            rotateImage(90);
        });

        $('ielFlipH').addEventListener('click', () => flipImage('h'));
        $('ielFlipV').addEventListener('click', () => flipImage('v'));

        $('ielCropBtn').addEventListener('click', () => {
            if (s.isCropping) {
                exitCropMode();
            } else {
                enterCropMode();
            }
        });

        $('ielApplyCrop').addEventListener('click', () => applyCrop());
    }

    // ===== Footer Button Events =====
    function initFooterEvents() {
        $('ielCloseBtn').addEventListener('click', closeLiteEditor);
        $('ielCancelBtn').addEventListener('click', closeLiteEditor);

        $('ielSaveBtn').addEventListener('click', () => {
            // Exit crop mode if active
            if (s.isCropping) exitCropMode();

            const dataUrl = exportImage();
            if (dataUrl && typeof s.onSave === 'function') {
                s.onSave(dataUrl);
            }
            closeLiteEditor();
        });
    }

    // ===== Keyboard shortcuts =====
    function initKeyboard() {
        document.addEventListener('keydown', (e) => {
            // Only respond when modal is visible
            if (overlay.style.display === 'none' || overlay.closest('#ielModal')?.style.display === 'none') return;

            if (e.key === 'Escape') {
                if (s.isCropping) {
                    exitCropMode();
                } else {
                    closeLiteEditor();
                }
                e.preventDefault();
            }
        });
    }

    // ===== Click overlay background to close =====
    function initOverlayClick() {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                closeLiteEditor();
            }
        });
    }

    // ===== Open / Close =====
    function openLiteEditor(imageSrc, onSaveCallback) {
        s.onSave = typeof onSaveCallback === 'function' ? onSaveCallback : null;

        // Show the modal container first, then the overlay
        $('ielModal').style.display = 'block';
        overlay.style.display = 'flex';

        // Initialize canvas if needed
        if (!s.fc) {
            initCanvas();
            initCropEvents();
            initAspectBtns();
            initToolbarEvents();
            initFooterEvents();
            initKeyboard();
            initOverlayClick();
        } else {
            // Reset state
            exitCropMode();
            s.flipH = false;
            s.flipV = false;
        }

        // Load the image
        loadImage(imageSrc).catch(err => {
            console.error('Lite Editor: Failed to load image', err);
            closeLiteEditor();
        });
    }

    function closeLiteEditor() {
        if (s.isCropping) exitCropMode();
        overlay.style.display = 'none';

        // Dispose canvas to free memory
        if (s.fc) {
            s.fc.clear();
            s.fc.setWidth(0);
            s.fc.setHeight(0);
        }

        s.onSave = null;
        s.originalImg = null;
    }

    // ===== Expose globally =====
    window.openLiteEditor = openLiteEditor;
    window.closeLiteEditor = closeLiteEditor;

})();
</script>
