<?php
/**
 * RCS HRMS Pro - Modern Image Editor
 * Built with Fabric.js | Canvas-based professional editing
 * Features: Crop, Rotate, Flip, Filters, Draw, Text, Shapes, Resize, Compress
 * 
 * Image Paths:
 * - Profile photos: /uploads/profile/
 * - Aadhaar: /uploads/aadhaar/
 * - Bank: /uploads/bank/
 */

$pageTitle = 'Image Editor';

?>
<script src="assets/js/fabric.min.js"></script>
<style>
/* ===== CSS Variables ===== */
:root {
    --bg-darkest: #0a0c10;
    --bg-dark: #0f1219;
    --bg-main: #141821;
    --bg-card: #1a1f2e;
    --bg-elevated: #222839;
    --bg-hover: #2a3145;
    --border: #2a3145;
    --border-light: #353d55;
    --accent: #6366f1;
    --accent-hover: #818cf8;
    --accent-glow: rgba(99,102,241,0.25);
    --green: #22c55e;
    --green-bg: rgba(34,197,94,0.12);
    --red: #ef4444;
    --red-bg: rgba(239,68,68,0.12);
    --amber: #f59e0b;
    --amber-bg: rgba(245,158,11,0.12);
    --cyan: #06b6d4;
    --purple: #a855f7;
    --text: #e2e8f0;
    --text-secondary: #94a3b8;
    --text-muted: #64748b;
    --radius: 10px;
    --radius-sm: 6px;
    --radius-xs: 4px;
    --shadow: 0 4px 24px rgba(0,0,0,0.3);
    --transition: all 0.15s ease;
}

/* ===== Reset & Layout ===== */
.ie-main {
    background: var(--bg-darkest);
    border-radius: var(--radius);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 140px);
    min-height: 500px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 13px;
    color: var(--text);
}

/* ===== Top Bar ===== */
.ie-topbar {
    display: flex;
    align-items: center;
    background: var(--bg-dark);
    border-bottom: 1px solid var(--border);
    padding: 0 12px;
    height: 48px;
    gap: 8px;
    flex-shrink: 0;
}

.ie-topbar-brand {
    font-weight: 700;
    font-size: 14px;
    color: var(--accent);
    margin-right: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.ie-topbar-brand i { font-size: 18px; }

.ie-folder-select {
    background: var(--bg-card);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: var(--radius-sm);
    padding: 5px 10px;
    font-size: 12px;
    outline: none;
    min-width: 140px;
    cursor: pointer;
}
.ie-folder-select:focus { border-color: var(--accent); }

.ie-topbar-divider {
    width: 1px;
    height: 24px;
    background: var(--border);
    margin: 0 4px;
}

.ie-topbar-actions {
    display: flex;
    gap: 4px;
    margin-left: auto;
}

/* ===== Buttons ===== */
.ie-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-secondary);
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    transition: var(--transition);
    white-space: nowrap;
}
.ie-btn:hover { background: var(--bg-hover); color: var(--text); border-color: var(--border-light); }
.ie-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.ie-btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.ie-btn.primary:hover { background: var(--accent-hover); }
.ie-btn.success { background: var(--green); border-color: var(--green); color: #fff; }
.ie-btn.success:hover { filter: brightness(1.1); }
.ie-btn.danger { background: var(--red-bg); border-color: rgba(239,68,68,0.3); color: var(--red); }
.ie-btn.danger:hover { background: rgba(239,68,68,0.2); }
.ie-btn.icon-only { padding: 6px 8px; }

/* ===== Main Layout ===== */
.ie-body {
    display: grid;
    grid-template-columns: 56px 1fr 260px;
    flex: 1;
    overflow: hidden;
}

/* ===== Left Toolbar ===== */
.ie-toolbar {
    background: var(--bg-dark);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 8px 0;
    gap: 2px;
    overflow-y: auto;
}

.ie-tool-btn {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    border: none;
    background: transparent;
    color: var(--text-muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    transition: var(--transition);
    position: relative;
}
.ie-tool-btn:hover { background: var(--bg-hover); color: var(--text); }
.ie-tool-btn.active { background: var(--accent-glow); color: var(--accent); }
.ie-tool-btn .tooltip {
    position: absolute;
    left: 50px;
    background: var(--bg-elevated);
    color: var(--text);
    padding: 4px 10px;
    border-radius: var(--radius-xs);
    font-size: 11px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s;
    z-index: 100;
    border: 1px solid var(--border);
}
.ie-tool-btn:hover .tooltip { opacity: 1; }

.ie-tool-sep {
    width: 28px;
    height: 1px;
    background: var(--border);
    margin: 4px 0;
}

/* ===== Canvas Area ===== */
.ie-canvas-area {
    background: var(--bg-main);
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.ie-canvas-wrap {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
}

/* Checkerboard background for transparency */
.ie-canvas-wrap::before {
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
}

.ie-canvas-container {
    position: relative;
    z-index: 1;
    box-shadow: 0 8px 40px rgba(0,0,0,0.5);
    border-radius: 2px;
}

.canvas-container { z-index: 1 !important; }

/* Ensure crop overlay and handles aren't clipped by Fabric's container */
.ie-canvas-container { overflow: visible !important; }
.ie-canvas-container .canvas-container { overflow: visible !important; }

/* ===== Crop Overlay ===== */
.ie-crop-overlay {
    position: absolute;
    border: 2px dashed var(--accent);
    background: rgba(99,102,241,0.08);
    display: none;
    z-index: 50;
    cursor: move;
}
.ie-crop-overlay .crop-handle {
    position: absolute;
    width: 10px;
    height: 10px;
    background: #fff;
    border: 2px solid var(--accent);
    border-radius: 2px;
}
.ie-crop-overlay .crop-handle.tl { top: -5px; left: -5px; cursor: nw-resize; }
.ie-crop-overlay .crop-handle.tr { top: -5px; right: -5px; cursor: ne-resize; }
.ie-crop-overlay .crop-handle.bl { bottom: -5px; left: -5px; cursor: sw-resize; }
.ie-crop-overlay .crop-handle.br { bottom: -5px; right: -5px; cursor: se-resize; }
.ie-crop-overlay .crop-handle.tm { top: -5px; left: calc(50% - 5px); cursor: n-resize; }
.ie-crop-overlay .crop-handle.bm { bottom: -5px; left: calc(50% - 5px); cursor: s-resize; }
.ie-crop-overlay .crop-handle.ml { left: -5px; top: calc(50% - 5px); cursor: w-resize; }
.ie-crop-overlay .crop-handle.mr { right: -5px; top: calc(50% - 5px); cursor: e-resize; }
.ie-crop-dim {
    position: absolute;
    top: -28px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--accent);
    color: #fff;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 3px;
    white-space: nowrap;
}

/* ===== Right Panel ===== */
.ie-panel {
    background: var(--bg-dark);
    border-left: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.ie-panel-header {
    display: flex;
    align-items: center;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    font-weight: 600;
    font-size: 13px;
    gap: 8px;
    flex-shrink: 0;
}
.ie-panel-header i { color: var(--accent); font-size: 15px; }

.ie-panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
}

.ie-section {
    margin-bottom: 16px;
}

.ie-section-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    margin-bottom: 8px;
}

/* File Browser Panel */
.ie-file-list {
    display: flex;
    flex-direction: column;
    gap: 3px;
    max-height: 100%;
    overflow-y: auto;
}

.ie-file-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    border-radius: var(--radius-xs);
    cursor: pointer;
    transition: var(--transition);
    border: 1px solid transparent;
}
.ie-file-item:hover { background: var(--bg-hover); }
.ie-file-item.active { background: var(--accent-glow); border-color: rgba(99,102,241,0.3); }
.ie-file-item img {
    width: 36px;
    height: 36px;
    object-fit: cover;
    border-radius: var(--radius-xs);
    background: var(--bg-card);
    flex-shrink: 0;
}
.ie-file-info { flex: 1; min-width: 0; }
.ie-file-name {
    font-size: 11px;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ie-file-meta { font-size: 10px; color: var(--text-muted); margin-top: 1px; }
.ie-file-meta .over { color: var(--amber); }
.ie-file-meta .ok { color: var(--green); }
.ie-file-del {
    opacity: 0;
    background: none;
    border: none;
    color: var(--red);
    cursor: pointer;
    font-size: 13px;
    padding: 2px;
    transition: var(--transition);
}
.ie-file-item:hover .ie-file-del { opacity: 1; }
.ie-file-del:hover { color: #f87171; }

/* ===== Slider Control ===== */
.ie-slider-group {
    margin-bottom: 12px;
}
.ie-slider-label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    font-size: 11px;
}
.ie-slider-label span:first-child { color: var(--text-secondary); }
.ie-slider-label span:last-child { color: var(--accent); font-weight: 600; font-variant-numeric: tabular-nums; }
.ie-slider {
    -webkit-appearance: none;
    appearance: none;
    width: 100%;
    height: 4px;
    border-radius: 2px;
    background: var(--bg-hover);
    outline: none;
}
.ie-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--accent);
    cursor: pointer;
    border: 2px solid #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
}
.ie-slider::-moz-range-thumb {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--accent);
    cursor: pointer;
    border: 2px solid #fff;
}

/* ===== Filter Presets ===== */
.ie-filter-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
}
.ie-filter-btn {
    border-radius: var(--radius-sm);
    border: 2px solid var(--border);
    overflow: hidden;
    cursor: pointer;
    transition: var(--transition);
    background: var(--bg-card);
    padding: 4px;
    text-align: center;
}
.ie-filter-btn:hover { border-color: var(--text-muted); }
.ie-filter-btn.active { border-color: var(--accent); }
.ie-filter-btn canvas {
    width: 100%;
    height: 40px;
    object-fit: cover;
    border-radius: 3px;
    display: block;
    margin-bottom: 3px;
}
.ie-filter-btn span {
    font-size: 9px;
    color: var(--text-muted);
    font-weight: 500;
}
.ie-filter-btn.active span { color: var(--accent); }

/* ===== Info Cards ===== */
.ie-info-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 10px 12px;
    margin-bottom: 8px;
}
.ie-info-row {
    display: flex;
    justify-content: space-between;
    padding: 3px 0;
    font-size: 11px;
}
.ie-info-row .label { color: var(--text-muted); }
.ie-info-row .value { color: var(--text); font-weight: 500; }
.ie-info-row .value.over { color: var(--amber); }
.ie-info-row .value.good { color: var(--green); }

/* ===== Text Input ===== */
.ie-input {
    width: 100%;
    background: var(--bg-card);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: var(--radius-xs);
    padding: 6px 10px;
    font-size: 12px;
    outline: none;
    transition: var(--transition);
}
.ie-input:focus { border-color: var(--accent); }

/* ===== Color Picker Row ===== */
.ie-color-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}
.ie-color-row label {
    font-size: 11px;
    color: var(--text-secondary);
    min-width: 50px;
}
.ie-color-row input[type="color"] {
    -webkit-appearance: none;
    width: 32px;
    height: 28px;
    border: 2px solid var(--border);
    border-radius: var(--radius-xs);
    cursor: pointer;
    background: transparent;
    padding: 1px;
}
.ie-color-row input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
.ie-color-row input[type="color"]::-webkit-color-swatch { border: none; border-radius: 2px; }

/* ===== Bottom Status ===== */
.ie-statusbar {
    display: flex;
    align-items: center;
    background: var(--bg-dark);
    border-top: 1px solid var(--border);
    padding: 0 14px;
    height: 28px;
    gap: 16px;
    font-size: 11px;
    color: var(--text-muted);
    flex-shrink: 0;
}
.ie-statusbar .status-item {
    display: flex;
    align-items: center;
    gap: 4px;
}
.ie-statusbar .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--green);
}
.ie-statusbar .zoom-ctrl {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 4px;
}
.ie-statusbar .zoom-ctrl button {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 14px;
    padding: 2px 4px;
}
.ie-statusbar .zoom-ctrl button:hover { color: var(--text); }

/* ===== Empty State ===== */
.ie-empty {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: var(--text-muted);
    z-index: 2;
    text-align: center;
    padding: 20px;
}
.ie-empty i { font-size: 48px; opacity: 0.3; }
.ie-empty h3 { font-size: 16px; font-weight: 600; color: var(--text-secondary); margin: 0; }
.ie-empty p { font-size: 12px; margin: 0; max-width: 260px; line-height: 1.5; }
.ie-empty .upload-area {
    margin-top: 8px;
    border: 2px dashed var(--border);
    border-radius: var(--radius);
    padding: 24px 32px;
    cursor: pointer;
    transition: var(--transition);
    text-align: center;
}
.ie-empty .upload-area:hover { border-color: var(--accent); background: var(--accent-glow); }
.ie-empty .upload-area i { font-size: 24px; color: var(--accent); opacity: 1; }
.ie-empty .upload-area p { color: var(--text-secondary); margin-top: 6px; }

/* ===== Toast ===== */
.ie-toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 16px;
    font-size: 12px;
    z-index: 9999;
    transform: translateY(60px);
    opacity: 0;
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: var(--shadow);
    max-width: 320px;
}
.ie-toast.show { transform: translateY(0); opacity: 1; }
.ie-toast.ok { border-color: var(--green); }
.ie-toast.ok i { color: var(--green); }
.ie-toast.err { border-color: var(--red); }
.ie-toast.err i { color: var(--red); }
.ie-toast.info { border-color: var(--accent); }
.ie-toast.info i { color: var(--accent); }

/* ===== Scrollbar ===== */
.ie-panel-body::-webkit-scrollbar,
.ie-file-list::-webkit-scrollbar { width: 4px; }
.ie-panel-body::-webkit-scrollbar-thumb,
.ie-file-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }

/* ===== Crop aspect ratio buttons ===== */
.ie-aspect-btns {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 10px;
}
.ie-aspect-btn {
    padding: 4px 10px;
    border-radius: var(--radius-xs);
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-secondary);
    font-size: 10px;
    cursor: pointer;
    transition: var(--transition);
}
.ie-aspect-btn:hover { border-color: var(--text-muted); }
.ie-aspect-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }

/* ===== Drawing options ===== */
.ie-draw-colors {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.ie-draw-color {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
    transition: var(--transition);
}
.ie-draw-color:hover { transform: scale(1.15); }
.ie-draw-color.active { border-color: #fff; box-shadow: 0 0 0 2px var(--accent); }

/* ===== History indicator ===== */
.ie-history {
    display: flex;
    gap: 4px;
    align-items: center;
}
.ie-history .count {
    font-size: 10px;
    color: var(--text-muted);
    font-variant-numeric: tabular-nums;
}

/* ===== Responsive ===== */
@media (max-width: 992px) {
    .ie-body { grid-template-columns: 48px 1fr; }
    .ie-panel { display: none; }
    .ie-topbar-brand span { display: none; }
}
@media (max-width: 576px) {
    .ie-body { grid-template-columns: 1fr; }
    .ie-toolbar {
        flex-direction: row;
        overflow-x: auto;
        padding: 4px 8px;
        border-right: none;
        border-bottom: 1px solid var(--border);
    }
    .ie-tool-sep { width: 1px; height: 28px; margin: 0 2px; }
}
</style>

<div class="card">
    <div class="card-body p-0">
        <div class="ie-main">
            <!-- ===== TOP BAR ===== -->
            <div class="ie-topbar">
                <div class="ie-topbar-brand">
                    <i class="bi bi-image"></i>
                    <span>Image Editor</span>
                </div>
                
                <select class="ie-folder-select" id="folderSelect">
                    <option value="">Loading folders...</option>
                </select>
                
                <div class="ie-topbar-divider"></div>
                
                <div class="ie-topbar-actions">
                    <div class="ie-history">
                        <button class="ie-btn icon-only" id="undoBtn" title="Undo (Ctrl+Z)" disabled><i class="bi bi-arrow-counterclockwise"></i></button>
                        <button class="ie-btn icon-only" id="redoBtn" title="Redo (Ctrl+Y)" disabled><i class="bi bi-arrow-clockwise"></i></button>
                        <span class="count" id="historyCount"></span>
                    </div>
                    <div class="ie-topbar-divider"></div>
                    <label class="ie-btn primary" style="margin:0;cursor:pointer;">
                        <i class="bi bi-upload"></i> Upload
                        <input type="file" id="uploadInput" accept="image/*" multiple hidden>
                    </label>
                    <button class="ie-btn" id="downloadBtn" disabled><i class="bi bi-download"></i> Download</button>
                    <button class="ie-btn success" id="saveBtn" disabled><i class="bi bi-check-lg"></i> Save</button>
                    <div class="ie-topbar-divider"></div>
                    <button class="ie-btn icon-only" id="prevBtn" disabled title="Previous Photo (Alt+Left)"><i class="bi bi-chevron-left"></i></button>
                    <button class="ie-btn icon-only" id="nextBtn" disabled title="Next Photo (Alt+Right)"><i class="bi bi-chevron-right"></i></button>
                    <div class="ie-topbar-divider"></div>
                    <button class="ie-btn" id="compressBtn" disabled><i class="bi bi-lightning-charge"></i> Compress</button>
                    <button class="ie-btn danger" id="deleteBtn" disabled><i class="bi bi-trash3"></i></button>
                </div>
            </div>

            <!-- ===== BODY ===== -->
            <div class="ie-body">
                <!-- LEFT TOOLBAR -->
                <div class="ie-toolbar">
                    <button class="ie-tool-btn active" data-tool="select" title="Select">
                        <i class="bi bi-cursor-fill"></i>
                        <span class="tooltip">Select (V)</span>
                    </button>
                    <button class="ie-tool-btn" data-tool="crop" title="Crop">
                        <i class="bi bi-crop"></i>
                        <span class="tooltip">Crop (C)</span>
                    </button>
                    <button class="ie-tool-btn" data-tool="draw" title="Draw">
                        <i class="bi bi-pencil"></i>
                        <span class="tooltip">Draw (D)</span>
                    </button>
                    <button class="ie-tool-btn" data-tool="eraser" title="Eraser">
                        <i class="bi bi-eraser"></i>
                        <span class="tooltip">Eraser (E)</span>
                    </button>
                    <button class="ie-tool-btn" data-tool="text" title="Text">
                        <i class="bi bi-type"></i>
                        <span class="tooltip">Text (T)</span>
                    </button>
                    <button class="ie-tool-btn" data-tool="rect" title="Rectangle">
                        <i class="bi bi-square"></i>
                        <span class="tooltip">Rectangle (R)</span>
                    </button>
                    <button class="ie-tool-btn" data-tool="circle" title="Circle">
                        <i class="bi bi-circle"></i>
                        <span class="tooltip">Circle (O)</span>
                    </button>
                    <button class="ie-tool-btn" data-tool="line" title="Line">
                        <i class="bi bi-slash-lg"></i>
                        <span class="tooltip">Line (L)</span>
                    </button>
                    
                    <div class="ie-tool-sep"></div>
                    
                    <button class="ie-tool-btn" id="rotateLeftBtn" title="Rotate Left">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        <span class="tooltip">Rotate Left</span>
                    </button>
                    <button class="ie-tool-btn" id="rotateRightBtn" title="Rotate Right">
                        <i class="bi bi-arrow-clockwise"></i>
                        <span class="tooltip">Rotate Right</span>
                    </button>
                    <button class="ie-tool-btn" id="flipHBtn" title="Flip Horizontal">
                        <i class="bi bi-arrow-left-right"></i>
                        <span class="tooltip">Flip H</span>
                    </button>
                    <button class="ie-tool-btn" id="flipVBtn" title="Flip Vertical">
                        <i class="bi bi-arrow-up-down"></i>
                        <span class="tooltip">Flip V</span>
                    </button>
                    
                    <div class="ie-tool-sep"></div>
                    
                    <button class="ie-tool-btn" id="resetBtn" title="Reset All">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        <span class="tooltip">Reset All</span>
                    </button>
                </div>

                <!-- CANVAS AREA -->
                <div class="ie-canvas-area" id="canvasArea">
                    <!-- Empty state -->
                    <div class="ie-empty" id="emptyState">
                        <i class="bi bi-image"></i>
                        <h3>No Image Loaded</h3>
                        <p>Select a file from the panel or upload a new image to start editing.</p>
                        <label class="upload-area">
                            <i class="bi bi-cloud-arrow-up"></i>
                            <p>Click or drag & drop images here</p>
                            <input type="file" accept="image/*" multiple hidden id="dropInput">
                        </label>
                    </div>
                    
                    <!-- Canvas container -->
                    <div class="ie-canvas-wrap" id="canvasWrap" style="display:none">
                        <div class="ie-canvas-container">
                            <canvas id="editorCanvas"></canvas>
                        </div>
                        <!-- Crop overlay -->
                        <div class="ie-crop-overlay" id="cropOverlay">
                            <span class="ie-crop-dim" id="cropDim"></span>
                            <div class="crop-handle tl" data-dir="tl"></div>
                            <div class="crop-handle tr" data-dir="tr"></div>
                            <div class="crop-handle bl" data-dir="bl"></div>
                            <div class="crop-handle br" data-dir="br"></div>
                            <div class="crop-handle tm" data-dir="tm"></div>
                            <div class="crop-handle bm" data-dir="bm"></div>
                            <div class="crop-handle ml" data-dir="ml"></div>
                            <div class="crop-handle mr" data-dir="mr"></div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT PANEL -->
                <div class="ie-panel">
                    <!-- File Browser -->
                    <div class="ie-panel-header">
                        <i class="bi bi-folder2-open"></i>
                        <span>Files</span>
                        <span style="margin-left:auto; font-size:11px; color:var(--text-muted);" id="fileCount">0</span>
                    </div>
                    <div style="max-height: 220px; border-bottom: 1px solid var(--border); overflow-y: auto;">
                        <div class="ie-file-list" id="fileList" style="padding: 6px;">
                            <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 11px;">
                                Select a folder
                            </div>
                        </div>
                    </div>

                    <!-- Tool Properties -->
                    <div class="ie-panel-header" style="border-top: 1px solid var(--border);">
                        <i class="bi bi-sliders" id="panelIcon"></i>
                        <span id="panelTitle">Properties</span>
                    </div>
                    <div class="ie-panel-body" id="panelBody">
                        <!-- Default: Image Info -->
                        <div id="infoPanel">
                            <div class="ie-info-card">
                                <div class="ie-info-row"><span class="label">File</span><span class="value" id="infoName">--</span></div>
                                <div class="ie-info-row"><span class="label">Size</span><span class="value" id="infoSize">--</span></div>
                                <div class="ie-info-row"><span class="label">Dimensions</span><span class="value" id="infoDim">--</span></div>
                                <div class="ie-info-row"><span class="label">Status</span><span class="value" id="infoStatus">--</span></div>
                            </div>
                        </div>
                        
                        <!-- Adjust Panel (hidden by default) -->
                        <div id="adjustPanel" style="display:none">
                            <div class="ie-section-title">Adjustments</div>
                            <div class="ie-slider-group">
                                <div class="ie-slider-label"><span>Brightness</span><span id="brightnessVal">0</span></div>
                                <input type="range" class="ie-slider" id="brightness" min="-100" max="100" value="0">
                            </div>
                            <div class="ie-slider-group">
                                <div class="ie-slider-label"><span>Contrast</span><span id="contrastVal">0</span></div>
                                <input type="range" class="ie-slider" id="contrast" min="-100" max="100" value="0">
                            </div>
                            <div class="ie-slider-group">
                                <div class="ie-slider-label"><span>Saturation</span><span id="saturationVal">0</span></div>
                                <input type="range" class="ie-slider" id="saturation" min="-100" max="100" value="0">
                            </div>
                            <div class="ie-slider-group">
                                <div class="ie-slider-label"><span>Blur</span><span id="blurVal">0</span></div>
                                <input type="range" class="ie-slider" id="blur" min="0" max="100" value="0">
                            </div>
                            <button class="ie-btn" style="width:100%; justify-content:center;" id="resetAdjustBtn">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset Adjustments
                            </button>
                        </div>
                        
                        <!-- Crop Panel -->
                        <div id="cropPanel" style="display:none">
                            <div class="ie-section-title">Aspect Ratio</div>
                            <div class="ie-aspect-btns">
                                <button class="ie-aspect-btn active" data-ratio="free">Free</button>
                                <button class="ie-aspect-btn" data-ratio="1:1">1:1</button>
                                <button class="ie-aspect-btn" data-ratio="4:3">4:3</button>
                                <button class="ie-aspect-btn" data-ratio="3:4">3:4</button>
                                <button class="ie-aspect-btn" data-ratio="16:9">16:9</button>
                                <button class="ie-aspect-btn" data-ratio="9:16">9:16</button>
                                <button class="ie-aspect-btn" data-ratio="3:2">3:2</button>
                                <button class="ie-aspect-btn" data-ratio="2:3">2:3</button>
                            </div>
                            <button class="ie-btn primary" style="width:100%; justify-content:center;" id="applyCropBtn">
                                <i class="bi bi-check-lg"></i> Apply Crop
                            </button>
                        </div>
                        
                        <!-- Draw Panel -->
                        <div id="drawPanel" style="display:none">
                            <div class="ie-section-title">Brush Color</div>
                            <div class="ie-draw-colors">
                                <div class="ie-draw-color active" data-color="#ffffff" style="background:#ffffff"></div>
                                <div class="ie-draw-color" data-color="#000000" style="background:#000000; border: 1px solid #444"></div>
                                <div class="ie-draw-color" data-color="#ef4444" style="background:#ef4444"></div>
                                <div class="ie-draw-color" data-color="#f59e0b" style="background:#f59e0b"></div>
                                <div class="ie-draw-color" data-color="#22c55e" style="background:#22c55e"></div>
                                <div class="ie-draw-color" data-color="#3b82f6" style="background:#3b82f6"></div>
                                <div class="ie-draw-color" data-color="#a855f7" style="background:#a855f7"></div>
                                <div class="ie-draw-color" data-color="#ec4899" style="background:#ec4899"></div>
                            </div>
                            <div class="ie-slider-group">
                                <div class="ie-slider-label"><span>Brush Size</span><span id="brushSizeVal">3</span></div>
                                <input type="range" class="ie-slider" id="brushSize" min="1" max="50" value="3">
                            </div>
                            <div class="ie-slider-group">
                                <div class="ie-slider-label"><span>Opacity</span><span id="brushOpacityVal">100</span></div>
                                <input type="range" class="ie-slider" id="brushOpacity" min="10" max="100" value="100">
                            </div>
                        </div>
                        
                        <!-- Text Panel -->
                        <div id="textPanel" style="display:none">
                            <div class="ie-section-title">Text Options</div>
                            <div class="ie-color-row">
                                <label>Color</label>
                                <input type="color" id="textColor" value="#ffffff">
                            </div>
                            <div class="ie-slider-group">
                                <div class="ie-slider-label"><span>Font Size</span><span id="textSizeVal">24</span></div>
                                <input type="range" class="ie-slider" id="textSize" min="8" max="120" value="24">
                            </div>
                            <div class="ie-section-title" style="margin-top:8px">Font Family</div>
                            <select class="ie-input" id="textFont">
                                <option value="Arial">Arial</option>
                                <option value="Verdana">Verdana</option>
                                <option value="Times New Roman">Times New Roman</option>
                                <option value="Georgia">Georgia</option>
                                <option value="Courier New">Courier New</option>
                                <option value="Impact">Impact</option>
                                <option value="Comic Sans MS">Comic Sans MS</option>
                            </select>
                            <div style="display:flex; gap:4px; margin-top:8px;">
                                <button class="ie-btn" id="textBold" style="flex:1;justify-content:center;font-weight:900;">B</button>
                                <button class="ie-btn" id="textItalic" style="flex:1;justify-content:center;font-style:italic;">I</button>
                                <button class="ie-btn" id="textUnderline" style="flex:1;justify-content:center;text-decoration:underline;">U</button>
                            </div>
                        </div>
                        
                        <!-- Filters Panel -->
                        <div id="filtersPanel" style="display:none">
                            <div class="ie-section-title">Filter Presets</div>
                            <div class="ie-filter-grid" id="filterGrid">
                                <!-- Generated by JS -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== STATUS BAR ===== -->
            <div class="ie-statusbar">
                <div class="status-item"><span class="dot"></span> Ready</div>
                <div class="status-item" id="statusDim">--</div>
                <div class="status-item" id="statusZoom">100%</div>
                <div class="zoom-ctrl">
                    <button id="zoomOutBtn"><i class="bi bi-dash"></i></button>
                    <span id="zoomLevel" style="min-width:36px; text-align:center;">100%</span>
                    <button id="zoomInBtn"><i class="bi bi-plus"></i></button>
                    <button id="zoomFitBtn" title="Fit to view"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="ie-toast" id="toast">
    <i class="bi bi-check-circle"></i>
    <span id="toastMsg"></span>
</div>

<script>
(() => {
    // ===== State =====
    const state = {
        activeFolder: '',
        currentFile: null,
        tool: 'select',
        history: [],
        historyIndex: -1,
        maxHistory: 30,
        isCropping: false,
        cropRect: { x: 0, y: 0, w: 0, h: 0 },
        cropRatio: null,
        cropDrag: null,
        originalImageSrc: null,
        zoom: 1,
        fileList: [],
        fileListIndex: -1
    };

    // ===== DOM Refs =====
    const $ = id => document.getElementById(id);
    const canvas = $('editorCanvas');
    let fc = null; // Fabric canvas instance

    // ===== Toast =====
    function toast(msg, type = 'ok') {
        const el = $('toast');
        $('toastMsg').textContent = msg;
        el.className = 'ie-toast ' + type;
        const icons = { ok: 'bi-check-circle', err: 'bi-exclamation-circle', info: 'bi-info-circle' };
        el.querySelector('i').className = 'bi ' + (icons[type] || icons.info);
        clearTimeout(el._t);
        requestAnimationFrame(() => el.classList.add('show'));
        el._t = setTimeout(() => el.classList.remove('show'), 3000);
    }

    // ===== API Helper =====
    async function api(action, data = {}) {
        const url = '?page=api/image-tool&ie_action=' + action;
        if (data instanceof FormData) {
            data.append('action', action);
            return await fetch(url, { method: 'POST', body: data }).then(r => r.json());
        }
        const fd = new FormData();
        fd.append('action', action);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return await fetch(url, { method: 'POST', body: fd }).then(r => r.json());
    }

    // ===== Initialize Fabric Canvas =====
    function initCanvas() {
        // Fix Fabric.js v5 'alphabetical' text baseline warning
        const origSetText = fabric.util.setTextStyles;
        if (origSetText) {
            fabric.util.setTextStyles = function(ctx, style, object) {
                if (style && style.textBaseline === 'alphabetical') {
                    style.textBaseline = 'alphabetic';
                }
                return origSetText.call(this, ctx, style, object);
            };
        }
        
        fc = new fabric.Canvas('editorCanvas', {
            selection: true,
            preserveObjectStacking: true,
            backgroundColor: null
        });
        
        fc.on('object:added', () => saveHistory());
        fc.on('object:modified', () => saveHistory());
        fc.on('object:removed', () => saveHistory());
    }

    // ===== History (Undo/Redo) =====
    function saveHistory() {
        // Trim future states
        state.history = state.history.slice(0, state.historyIndex + 1);
        const json = JSON.stringify(fc.toJSON());
        state.history.push(json);
        if (state.history.length > state.maxHistory) {
            state.history.shift();
        }
        state.historyIndex = state.history.length - 1;
        updateHistoryUI();
    }

    function undo() {
        if (state.historyIndex <= 0) return;
        state.historyIndex--;
        loadHistoryState(state.history[state.historyIndex]);
        updateHistoryUI();
    }

    function redo() {
        if (state.historyIndex >= state.history.length - 1) return;
        state.historyIndex++;
        loadHistoryState(state.history[state.historyIndex]);
        updateHistoryUI();
    }

    function loadHistoryState(json) {
        fc.loadFromJSON(json, () => {
            fc.renderAll();
        });
    }

    function updateHistoryUI() {
        $('undoBtn').disabled = state.historyIndex <= 0;
        $('redoBtn').disabled = state.historyIndex >= state.history.length - 1;
        $('historyCount').textContent = state.historyIndex + '/' + (state.history.length - 1);
    }

    // ===== Load Folders =====
    async function loadFolders() {
        const folders = await fetch('?page=api/image-tool&ie_action=folders').then(r => r.json()).catch(() => []);
        const sel = $('folderSelect');
        sel.innerHTML = '';
        folders.forEach(f => {
            const opt = document.createElement('option');
            opt.value = f.rel;
            opt.textContent = f.label;
            sel.appendChild(opt);
        });
        if (folders.length > 0) {
            sel.value = folders[0].rel;
            state.activeFolder = folders[0].rel;
            loadFiles();
        }
    }

    // ===== Load Files =====
    async function loadFiles() {
        $('fileList').innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:11px;">Loading...</div>';
        const files = await fetch(`?page=api/image-tool&ie_action=list&folder=${encodeURIComponent(state.activeFolder)}`).then(r => r.json()).catch(() => []);
        state.fileList = files;
        $('fileCount').textContent = files.length + ' files';
        
        if (!files.length) {
            $('fileList').innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:11px;">No images in this folder</div>';
            return;
        }
        
        $('fileList').innerHTML = '';
        files.forEach(f => {
            const item = document.createElement('div');
            item.className = 'ie-file-item' + (state.currentFile && state.currentFile.rel === f.rel ? ' active' : '');
            
            const imgUrl = `?page=api/image-tool&ie_action=img&file=${encodeURIComponent(f.rel)}&t=${Date.now()}`;
            item.innerHTML = `
                <img src="${imgUrl}" alt="${f.name}" loading="lazy">
                <div class="ie-file-info">
                    <div class="ie-file-name" title="${f.name}">${f.name}</div>
                    <div class="ie-file-meta ${f.ok ? 'ok' : 'over'}">${f.size}KB${f.dimensions ? ' · ' + f.dimensions : ''}</div>
                </div>
                <button class="ie-file-del" title="Delete"><i class="bi bi-x-lg"></i></button>
            `;
            
            item.addEventListener('click', (e) => {
                if (e.target.closest('.ie-file-del')) return;
                openImage(f);
            });
            
            item.querySelector('.ie-file-del').addEventListener('click', async (e) => {
                e.stopPropagation();
                if (!confirm('Delete ' + f.name + '?')) return;
                const res = await api('delete', { file: f.rel });
                if (res.ok) {
                    toast('Deleted: ' + f.name);
                    if (state.currentFile && state.currentFile.rel === f.rel) closeImage();
                    loadFiles();
                } else toast('Delete failed', 'err');
            });
            
            $('fileList').appendChild(item);
        });
    }

    // ===== Open Image on Canvas =====
    function openImage(f) {
        state.currentFile = { ...f };
        
        const imgUrl = `?page=api/image-tool&ie_action=img&file=${encodeURIComponent(f.rel)}&t=${Date.now()}`;
        
        fabric.Image.fromURL(imgUrl, (img) => {
            if (!img) { toast('Failed to load image', 'err'); return; }
            
            // Reset canvas
            fc.clear();
            fc.setBackgroundColor(null);
            
            // Calculate display size (fit in canvas area)
            const area = $('canvasArea');
            const maxW = area.clientWidth - 60;
            const maxH = area.clientHeight - 60;
            
            let scale = Math.min(maxW / img.width, maxH / img.height, 1);
            fc.setWidth(Math.round(img.width * scale));
            fc.setHeight(Math.round(img.height * scale));
            
            fc.setBackgroundImage(img, fc.renderAll.bind(fc), {
                scaleX: scale,
                scaleY: scale,
                originX: 'left',
                originY: 'top'
            });
            
            state.originalImageSrc = imgUrl;
            state.history = [];
            state.historyIndex = -1;
            saveHistory();
            
            // Show canvas, hide empty
            $('emptyState').style.display = 'none';
            $('canvasWrap').style.display = 'flex';
            
            // Update info
            $('infoName').textContent = f.name;
            $('infoSize').textContent = f.size + ' KB';
            $('infoSize').className = 'value ' + (f.ok ? 'good' : 'over');
            $('infoDim').textContent = img.width + ' x ' + img.height + ' px';
            $('infoStatus').textContent = f.ok ? 'Under 500KB' : 'Over 500KB';
            $('infoStatus').className = 'value ' + (f.ok ? 'good' : 'over');
            
            // Update status bar
            $('statusDim').textContent = img.width + ' x ' + img.height;
            state.zoom = scale;
            $('zoomLevel').textContent = Math.round(scale * 100) + '%';
            
            // Enable buttons
            $('downloadBtn').disabled = false;
            $('saveBtn').disabled = false;
            $('compressBtn').disabled = false;
            $('deleteBtn').disabled = false;
            
            // Reset adjustments
            resetAdjustments();
            
            // Update nav buttons
            state.fileListIndex = state.fileList.findIndex(f => f.rel === f.rel);
            updateNavButtons();

            // Highlight active file
            document.querySelectorAll('.ie-file-item').forEach(el => {
                const name = el.querySelector('.ie-file-name')?.textContent;
                el.classList.toggle('active', name === f.name);
            });
            
            // Switch to select tool
            setTool('select');
        }, { crossOrigin: 'anonymous' });
    }

    // ===== Navigate Files =====
    function navigateFile(direction) {
        if (!state.currentFile || !state.fileList.length) return;
        const idx = state.fileList.findIndex(f => f.rel === state.currentFile.rel);
        if (idx === -1) return;
        const newIdx = idx + direction;
        if (newIdx < 0 || newIdx >= state.fileList.length) return;
        openImage(state.fileList[newIdx]);
    }

    function updateNavButtons() {
        if (!state.currentFile || !state.fileList.length) {
            $('prevBtn').disabled = true;
            $('nextBtn').disabled = true;
            return;
        }
        const idx = state.fileList.findIndex(f => f.rel === state.currentFile.rel);
        $('prevBtn').disabled = idx <= 0;
        $('nextBtn').disabled = idx >= state.fileList.length - 1 || idx === -1;
    }

    // ===== Close Image on Canvas =====
    function closeImage() {
        state.currentFile = null;
        fc.clear();
        fc.setWidth(0);
        fc.setHeight(0);
        $('emptyState').style.display = 'flex';
        $('canvasWrap').style.display = 'none';
        $('downloadBtn').disabled = true;
        $('saveBtn').disabled = true;
        $('compressBtn').disabled = true;
        $('deleteBtn').disabled = true;
        $('prevBtn').disabled = true;
        $('nextBtn').disabled = true;
        state.fileListIndex = -1;
        document.querySelectorAll('.ie-file-item').forEach(el => el.classList.remove('active'));
    }

    // ===== Tool Switching =====
    function setTool(tool) {
        state.tool = tool;
        
        // Update toolbar buttons
        document.querySelectorAll('.ie-tool-btn[data-tool]').forEach(b => {
            b.classList.toggle('active', b.dataset.tool === tool);
        });
        
        // Reset canvas mode
        fc.isDrawingMode = false;
        fc.selection = true;
        fc.defaultCursor = 'default';
        fc.hoverCursor = 'move';
        fc.forEachObject(obj => { obj.selectable = true; obj.evented = true; });
        
        // Hide crop overlay
        $('cropOverlay').style.display = 'none';
        state.isCropping = false;
        
        // Show/hide panels
        $('infoPanel').style.display = tool === 'select' ? 'block' : 'none';
        $('adjustPanel').style.display = 'none';
        $('cropPanel').style.display = 'none';
        $('drawPanel').style.display = 'none';
        $('textPanel').style.display = 'none';
        $('filtersPanel').style.display = 'none';
        
        // Set panel icon/title
        $('panelIcon').className = 'bi bi-sliders';
        $('panelTitle').textContent = 'Properties';
        
        switch (tool) {
            case 'select':
                break;
                
            case 'crop':
                // Disable Fabric interactions so crop overlay captures events
                fc.selection = false;
                fc.defaultCursor = 'crosshair';
                fc.forEachObject(obj => { obj.selectable = false; obj.evented = false; });
                $('cropPanel').style.display = 'block';
                $('panelTitle').textContent = 'Crop';
                $('panelIcon').className = 'bi bi-crop';
                enterCropMode();
                break;
                
            case 'draw':
                fc.isDrawingMode = true;
                fc.freeDrawingBrush.width = parseInt($('brushSize').value);
                fc.freeDrawingBrush.color = document.querySelector('.ie-draw-color.active')?.dataset.color || '#fff';
                $('drawPanel').style.display = 'block';
                $('panelTitle').textContent = 'Draw';
                $('panelIcon').className = 'bi bi-pencil';
                break;
                
            case 'eraser':
                fc.isDrawingMode = true;
                fc.freeDrawingBrush.width = parseInt($('brushSize').value);
                fc.freeDrawingBrush.color = '#ffffff'; // We'll use white on white bg trick - or use destination-out
                // Actually for eraser we need a different approach
                fc.freeDrawingBrush = new fabric.PencilBrush(fc);
                fc.freeDrawingBrush.color = 'rgba(0,0,0,0)'; 
                // Better: set globalCompositeOperation
                $('drawPanel').style.display = 'block';
                $('panelTitle').textContent = 'Eraser';
                $('panelIcon').className = 'bi bi-eraser';
                // Use a simple white brush for now
                fc.freeDrawingBrush.color = '#ffffff';
                break;
                
            case 'text':
                $('textPanel').style.display = 'block';
                $('panelTitle').textContent = 'Text';
                $('panelIcon').className = 'bi bi-type';
                addTextObject();
                break;
                
            case 'rect':
                $('panelTitle').textContent = 'Rectangle';
                $('panelIcon').className = 'bi bi-square';
                addShape('rect');
                break;
                
            case 'circle':
                $('panelTitle').textContent = 'Circle';
                $('panelIcon').className = 'bi bi-circle';
                addShape('circle');
                break;
                
            case 'line':
                $('panelTitle').textContent = 'Line';
                $('panelIcon').className = 'bi bi-slash-lg';
                addShape('line');
                break;
        }
    }

    // ===== Crop Mode =====
    function enterCropMode() {
        state.isCropping = true;
        const cw = fc.getWidth();
        const ch = fc.getHeight();
        
        // Initialize crop rect to 80% of canvas
        state.cropRect = {
            x: Math.round(cw * 0.1),
            y: Math.round(ch * 0.1),
            w: Math.round(cw * 0.8),
            h: Math.round(ch * 0.8)
        };
        
        // Move crop overlay into Fabric's .canvas-container for exact coordinate alignment
        const fabricContainer = document.querySelector('.ie-canvas-container .canvas-container');
        if (fabricContainer && $('cropOverlay').parentNode !== fabricContainer) {
            fabricContainer.appendChild($('cropOverlay'));
        }
        // Make sure overlay is visible and positioned correctly
        const ov = $('cropOverlay');
        ov.style.position = 'absolute';
        ov.style.left = '0px';
        ov.style.top = '0px';
        ov.style.zIndex = '9999';
        renderCropOverlay();
        ov.style.display = 'block';
    }

    function renderCropOverlay() {
        const ov = $('cropOverlay');
        const r = state.cropRect;
        ov.style.left = r.x + 'px';
        ov.style.top = r.y + 'px';
        ov.style.width = r.w + 'px';
        ov.style.height = r.h + 'px';
        
        // Calculate real dimensions
        const bgImg = fc.backgroundImage;
        if (bgImg) {
            const realW = Math.round(r.w / bgImg.scaleX);
            const realH = Math.round(r.h / bgImg.scaleY);
            $('cropDim').textContent = realW + ' x ' + realH;
        }
    }

    function applyCrop() {
        const bgImg = fc.backgroundImage;
        if (!bgImg) return;
        
        const r = state.cropRect;
        const sx = bgImg.scaleX;
        const sy = bgImg.scaleY;
        
        // Calculate source coordinates in original image
        const srcX = Math.round(r.x / sx);
        const srcY = Math.round(r.y / sy);
        const srcW = Math.round(r.w / sx);
        const srcH = Math.round(r.h / sy);
        
        // Create a temp canvas to extract cropped region
        const tmpCanvas = document.createElement('canvas');
        tmpCanvas.width = srcW;
        tmpCanvas.height = srcH;
        const ctx = tmpCanvas.getContext('2d');
        ctx.drawImage(bgImg.getElement(), srcX, srcY, srcW, srcH, 0, 0, srcW, srcH);
        
        // Create new fabric image from cropped data
        const dataUrl = tmpCanvas.toDataURL('image/png');
        fabric.Image.fromURL(dataUrl, (newImg) => {
            if (!newImg) return;
            
            // Calculate new canvas size to fit
            const area = $('canvasArea');
            const maxW = area.clientWidth - 60;
            const maxH = area.clientHeight - 60;
            let scale = Math.min(maxW / srcW, maxH / srcH, 1);
            
            fc.clear();
            fc.setWidth(Math.round(srcW * scale));
            fc.setHeight(Math.round(srcH * scale));
            
            fc.setBackgroundImage(newImg, fc.renderAll.bind(fc), {
                scaleX: scale,
                scaleY: scale,
                originX: 'left',
                originY: 'top'
            });
            
            state.history = [];
            state.historyIndex = -1;
            saveHistory();
            
            // Update info
            $('infoDim').textContent = srcW + ' x ' + srcH + ' px';
            $('statusDim').textContent = srcW + ' x ' + srcH;
            state.zoom = scale;
            $('zoomLevel').textContent = Math.round(scale * 100) + '%';
            
            toast('Image cropped to ' + srcW + 'x' + srcH);
            setTool('select');
        });
    }

    // Crop overlay mouse events
    function initCropEvents() {
        const ov = $('cropOverlay');
        
        ov.addEventListener('mousedown', (e) => {
            if (!state.isCropping) return;
            const handle = e.target.dataset?.dir;
            state.cropDrag = {
                type: handle ? 'resize' : 'move',
                dir: handle,
                startX: e.clientX,
                startY: e.clientY,
                startRect: { ...state.cropRect }
            };
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!state.cropDrag) return;
            const dx = e.clientX - state.cropDrag.startX;
            const dy = e.clientY - state.cropDrag.startY;
            let { x, y, w, h } = state.cropDrag.startRect;
            const cw = fc.getWidth();
            const ch = fc.getHeight();
            const dir = state.cropDrag.dir;
            
            if (state.cropDrag.type === 'move') {
                x = Math.max(0, Math.min(cw - w, x + dx));
                y = Math.max(0, Math.min(ch - h, y + dy));
            } else {
                // Resize
                if (dir.includes('r') || dir === 'mr') w = Math.max(20, Math.min(cw - x, w + dx));
                if (dir.includes('b') || dir === 'bm') h = Math.max(20, Math.min(ch - y, h + dy));
                if (dir.includes('l') || dir === 'ml') { 
                    const nx = Math.min(x + w - 20, x + dx);
                    w -= (nx - x); x = nx; 
                }
                if (dir.includes('t') || dir === 'tm') { 
                    const ny = Math.min(y + h - 20, y + dy);
                    h -= (ny - y); y = ny; 
                }
                
                // Apply aspect ratio constraint
                if (state.cropRatio) {
                    const [rw, rh] = state.cropRatio.split(':').map(Number);
                    const targetRatio = rw / rh;
                    if (dir.includes('r') || dir.includes('l') || dir === 'mr' || dir === 'ml') {
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
            
            state.cropRect = { x, y, w, h };
            renderCropOverlay();
        });
        
        document.addEventListener('mouseup', () => {
            state.cropDrag = null;
        });
    }

    // ===== Add Text =====
    function addTextObject() {
        const text = new fabric.IText('Double-click to edit', {
            left: fc.getWidth() / 2 - 80,
            top: fc.getHeight() / 2 - 12,
            fontSize: parseInt($('textSize').value),
            fill: $('textColor').value,
            fontFamily: $('textFont').value,
            fontWeight: $('textBold').classList.contains('active') ? 'bold' : 'normal',
            fontStyle: $('textItalic').classList.contains('active') ? 'italic' : 'normal',
            underline: $('textUnderline').classList.contains('active'),
            editable: true
        });
        fc.add(text);
        fc.setActiveObject(text);
        text.enterEditing();
        text.selectAll();
    }

    // ===== Add Shape =====
    function addShape(type) {
        let shape;
        const fillColor = 'transparent';
        const strokeColor = document.querySelector('.ie-draw-color.active')?.dataset.color || '#ffffff';
        const strokeWidth = parseInt($('brushSize').value) || 2;
        
        const cw = fc.getWidth();
        const ch = fc.getHeight();
        
        switch (type) {
            case 'rect':
                shape = new fabric.Rect({
                    left: cw / 2 - 60, top: ch / 2 - 40,
                    width: 120, height: 80,
                    fill: fillColor, stroke: strokeColor, strokeWidth: strokeWidth,
                    strokeUniform: true
                });
                break;
            case 'circle':
                shape = new fabric.Circle({
                    left: cw / 2 - 40, top: ch / 2 - 40,
                    radius: Math.max(1, 40),
                    fill: fillColor, stroke: strokeColor, strokeWidth: strokeWidth,
                    strokeUniform: true
                });
                break;
            case 'line':
                shape = new fabric.Line([cw / 2 - 60, ch / 2, cw / 2 + 60, ch / 2], {
                    stroke: strokeColor, strokeWidth: strokeWidth,
                    strokeUniform: true
                });
                break;
        }
        
        if (shape) {
            fc.add(shape);
            fc.setActiveObject(shape);
            setTool('select');
        }
    }

    // ===== Adjustments =====
    function applyFilters() {
        const bgImg = fc.backgroundImage;
        if (!bgImg) return;
        
        const brightness = parseInt($('brightness').value);
        const contrast = parseInt($('contrast').value);
        const saturation = parseInt($('saturation').value);
        const blur = parseInt($('blur').value);
        
        bgImg.filters = [];
        
        if (brightness !== 0) bgImg.filters.push(new fabric.Image.filters.Brightness({ brightness: brightness / 100 }));
        if (contrast !== 0) bgImg.filters.push(new fabric.Image.filters.Contrast({ contrast: contrast / 100 }));
        if (saturation !== 0) bgImg.filters.push(new fabric.Image.filters.Saturation({ saturation: saturation / 100 }));
        if (blur > 0) bgImg.filters.push(new fabric.Image.filters.Blur({ blur: blur / 500 }));
        
        bgImg.applyFilters();
        fc.renderAll();
    }

    function resetAdjustments() {
        $('brightness').value = 0; $('brightnessVal').textContent = '0';
        $('contrast').value = 0; $('contrastVal').textContent = '0';
        $('saturation').value = 0; $('saturationVal').textContent = '0';
        $('blur').value = 0; $('blurVal').textContent = '0';
        applyFilters();
    }

    // ===== Rotate & Flip =====
    function rotateImage(angle) {
        const bgImg = fc.backgroundImage;
        if (!bgImg) return;
        
        // Use fabric's rotate
        const currentAngle = bgImg.angle || 0;
        bgImg.rotate(currentAngle + angle);
        
        // Recalculate canvas size
        const rad = (currentAngle + angle) * Math.PI / 180;
        const absCos = Math.abs(Math.cos(rad));
        const absSin = Math.abs(Math.sin(rad));
        const origW = bgImg.getOriginalSize().width;
        const origH = bgImg.getOriginalSize().height;
        const newW = origW * absCos + origH * absSin;
        const newH = origW * absSin + origH * absCos;
        
        // But we need to account for the image scaling...
        const scale = bgImg.scaleX;
        const rotW = (origW * absCos + origH * absSin) * scale;
        const rotH = (origW * absSin + origH * absCos) * scale;
        
        bgImg.set({
            left: (fc.getWidth() - rotW) / 2 + (origW * absCos * scale) / 2,
            top: (fc.getHeight() - rotH) / 2 + (origW * absSin * scale) / 2,
            originX: 'center',
            originY: 'center'
        });
        
        // Actually, for simplicity, let's use a different approach
        // Export canvas, rotate, reload
        const tmpCanvas = document.createElement('canvas');
        const fullW = origW;
        const fullH = origH;
        tmpCanvas.width = (angle % 180 === 0) ? fullW : fullH;
        tmpCanvas.height = (angle % 180 === 0) ? fullH : fullW;
        const ctx = tmpCanvas.getContext('2d');
        
        ctx.translate(tmpCanvas.width / 2, tmpCanvas.height / 2);
        ctx.rotate(angle * Math.PI / 180);
        ctx.drawImage(bgImg.getElement(), -fullW / 2, -fullH / 2);
        
        const dataUrl = tmpCanvas.toDataURL('image/png');
        fabric.Image.fromURL(dataUrl, (newImg) => {
            if (!newImg) return;
            
            const area = $('canvasArea');
            const maxW = area.clientWidth - 60;
            const maxH = area.clientHeight - 60;
            let newScale = Math.min(maxW / newImg.width, maxH / newImg.height, 1);
            
            fc.clear();
            fc.setWidth(Math.round(newImg.width * newScale));
            fc.setHeight(Math.round(newImg.height * newScale));
            
            fc.setBackgroundImage(newImg, fc.renderAll.bind(fc), {
                scaleX: newScale,
                scaleY: newScale,
                originX: 'left',
                originY: 'top'
            });
            
            state.history = [];
            state.historyIndex = -1;
            saveHistory();
            
            $('infoDim').textContent = newImg.width + ' x ' + newImg.height + ' px';
            $('statusDim').textContent = newImg.width + ' x ' + newImg.height;
            state.zoom = newScale;
            $('zoomLevel').textContent = Math.round(newScale * 100) + '%';
        });
    }

    function flipImage(direction) {
        const bgImg = fc.backgroundImage;
        if (!bgImg) return;
        
        if (direction === 'h') {
            bgImg.set('scaleX', -bgImg.scaleX);
        } else {
            bgImg.set('scaleY', -bgImg.scaleY);
        }
        fc.renderAll();
        saveHistory();
    }

    // ===== Zoom =====
    function setZoom(level) {
        level = Math.max(0.1, Math.min(3, level));
        state.zoom = level;
        
        const bgImg = fc.backgroundImage;
        if (bgImg) {
            const origScale = Math.min(
                ($('canvasArea').clientWidth - 60) / bgImg.getOriginalSize().width,
                ($('canvasArea').clientHeight - 60) / bgImg.getOriginalSize().height,
                1
            );
            const newScale = origScale * level;
            fc.setWidth(Math.round(bgImg.getOriginalSize().width * newScale));
            fc.setHeight(Math.round(bgImg.getOriginalSize().height * newScale));
            bgImg.set({ scaleX: newScale, scaleY: newScale });
            fc.renderAll();
        }
        
        $('zoomLevel').textContent = Math.round(level * 100) + '%';
    }

    // ===== Save to Server =====
    async function saveToServer() {
        if (!state.currentFile) return;
        
        // Export canvas as JPEG
        const dataUrl = fc.toDataURL({
            format: 'jpeg',
            quality: 0.92,
            multiplier: 1 / state.zoom // Save at original resolution
        });
        
        // Fix multiplier - export at original size
        const bgImg = fc.backgroundImage;
        let multiplier = 1;
        if (bgImg) {
            multiplier = bgImg.getOriginalSize().width / fc.getWidth();
        }
        
        const fullDataUrl = fc.toDataURL({
            format: 'jpeg',
            quality: 0.92,
            multiplier: multiplier
        });
        
        const res = await api('save_canvas', {
            file: state.currentFile.rel,
            image_data: fullDataUrl
        });
        
        if (res.ok) {
            toast('Saved: ' + res.file + ' (' + res.size + ' KB)');
            state.currentFile = { ...state.currentFile, rel: res.rel, name: res.file, size: res.size };
            $('infoName').textContent = res.file;
            $('infoSize').textContent = res.size + ' KB';
            $('infoSize').className = 'value ' + (res.size <= 500 ? 'good' : 'over');
            $('infoStatus').textContent = res.size <= 500 ? 'Under 500KB' : 'Over 500KB';
            $('infoStatus').className = 'value ' + (res.size <= 500 ? 'good' : 'over');
            loadFiles();
        } else {
            toast('Save failed: ' + (res.msg || 'unknown error'), 'err');
        }
    }

    // ===== Compress =====
    async function compressCurrent() {
        if (!state.currentFile) return;
        // Save first, then compress
        await saveToServer();
        
        const res = await api('compress', { file: state.currentFile.rel });
        if (res.ok) {
            const pct = ((res.before - res.after) / res.before * 100).toFixed(1);
            toast(`Compressed: ${res.before}KB → ${res.after}KB (${pct}% saved)`);
            openImage({ ...state.currentFile, rel: res.rel, name: res.file, size: res.after });
            loadFiles();
        } else {
            toast('Compress failed', 'err');
        }
    }

    // ===== Download =====
    function downloadImage() {
        const bgImg = fc.backgroundImage;
        let multiplier = 1;
        if (bgImg) {
            multiplier = bgImg.getOriginalSize().width / fc.getWidth();
        }
        
        const dataUrl = fc.toDataURL({
            format: 'jpeg',
            quality: 0.95,
            multiplier: multiplier
        });
        
        const link = document.createElement('a');
        link.download = (state.currentFile?.name || 'edited-image').replace(/\.\w+$/, '') + '_edited.jpg';
        link.href = dataUrl;
        link.click();
        
        toast('Image downloaded');
    }

    // ===== Upload =====
    async function uploadFiles(files) {
        if (!files.length) return;
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('folder', state.activeFolder || 'profile');
        for (const f of files) fd.append('images[]', f);
        
        const res = await fetch('?page=api/image-tool&ie_action=upload', { method: 'POST', body: fd }).then(r => r.json());
        if (res.ok) {
            toast('Uploaded ' + files.length + ' file(s)');
            loadFiles();
            // Auto-open last uploaded
            if (res.files && res.files.length) {
                // Reload files and open the last one
            }
        } else {
            toast('Upload failed', 'err');
        }
    }

    // ===== Filter Presets =====
    function initFilterPresets() {
        const presets = [
            { name: 'Original', filters: [] },
            { name: 'Grayscale', filters: [new fabric.Image.filters.Grayscale()] },
            { name: 'Sepia', filters: [new fabric.Image.filters.Sepia()] },
            { name: 'Invert', filters: [new fabric.Image.filters.Invert()] },
            { name: 'Vintage', filters: [new fabric.Image.filters.Sepia(), new fabric.Image.filters.Brightness({ brightness: -0.05 })] },
            { name: 'Warm', filters: [new fabric.Image.filters.Brightness({ brightness: 0.05 }), new fabric.Image.filters.Saturation({ saturation: 0.15 })] },
            { name: 'Cool', filters: [new fabric.Image.filters.Brightness({ brightness: 0.05 }), new fabric.Image.filters.Saturation({ saturation: -0.1 })] },
            { name: 'Bright', filters: [new fabric.Image.filters.Brightness({ brightness: 0.15 })] },
            { name: 'Contrast', filters: [new fabric.Image.filters.Contrast({ contrast: 0.3 })] },
        ];
        
        const grid = $('filterGrid');
        grid.innerHTML = '';
        
        presets.forEach((preset, idx) => {
            const btn = document.createElement('div');
            btn.className = 'ie-filter-btn' + (idx === 0 ? ' active' : '');
            
            // Create small preview canvas
            const previewCanvas = document.createElement('canvas');
            previewCanvas.width = 70;
            previewCanvas.height = 40;
            grid.appendChild(btn);
            
            btn.appendChild(previewCanvas);
            const span = document.createElement('span');
            span.textContent = preset.name;
            btn.appendChild(span);
            
            btn.addEventListener('click', () => {
                const bgImg = fc.backgroundImage;
                if (!bgImg) return;
                
                bgImg.filters = preset.filters.map(f => {
                    // Clone filter
                    return new f.constructor(f);
                });
                bgImg.applyFilters();
                fc.renderAll();
                
                document.querySelectorAll('.ie-filter-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                // Reset adjustment sliders
                $('brightness').value = 0; $('brightnessVal').textContent = '0';
                $('contrast').value = 0; $('contrastVal').textContent = '0';
                $('saturation').value = 0; $('saturationVal').textContent = '0';
                $('blur').value = 0; $('blurVal').textContent = '0';
                
                saveHistory();
            });
            
            // Render preview when image loads
            if (fc.backgroundImage) {
                setTimeout(() => {
                    try {
                        const previewImg = new Image();
                        previewImg.crossOrigin = 'anonymous';
                        previewImg.onload = () => {
                            const ctx = previewCanvas.getContext('2d');
                            ctx.drawImage(previewImg, 0, 0, 70, 40);
                            
                            if (preset.filters.length > 0 && typeof fabric !== 'undefined') {
                                try {
                                    const fImg = new fabric.Image(previewImg);
                                    fImg.filters = preset.filters.map(f => new f.constructor(f));
                                    fImg.applyFilters();
                                    ctx.clearRect(0, 0, 70, 40);
                                    ctx.drawImage(fImg.getElement(), 0, 0, 70, 40);
                                } catch (e) {}
                            }
                        };
                        previewImg.src = fc.backgroundImage.getSrc();
                    } catch (e) {}
                }, 500);
            }
        });
    }

    // ===== Drag & Drop =====
    function initDragDrop() {
        const area = $('canvasArea');
        
        ['dragenter', 'dragover'].forEach(ev => {
            area.addEventListener(ev, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });
        
        ['drop'].forEach(ev => {
            area.addEventListener(ev, (e) => {
                e.preventDefault();
                e.stopPropagation();
                const files = e.dataTransfer?.files;
                if (files && files.length) uploadFiles(files);
            });
        });
    }

    // ===== Keyboard Shortcuts =====
    function initKeyboard() {
        document.addEventListener('keydown', (e) => {
            // Don't capture when editing text
            if (fc.getActiveObject()?.isEditing) return;
            
            if (e.ctrlKey || e.metaKey) {
                if (e.key === 'z') { e.preventDefault(); undo(); }
                if (e.key === 'y') { e.preventDefault(); redo(); }
                if (e.key === 's') { e.preventDefault(); saveToServer(); }
                return;
            }
            
            switch (e.key.toLowerCase()) {
                case 'v': setTool('select'); break;
                case 'c': setTool('crop'); break;
                case 'd': setTool('draw'); break;
                case 'e': setTool('eraser'); break;
                case 't': setTool('text'); break;
                case 'r': if (!e.ctrlKey) setTool('rect'); break;
                case 'o': if (!e.ctrlKey) setTool('circle'); break;
                case 'l': setTool('line'); break;
                case 'delete':
                case 'backspace':
                    const active = fc.getActiveObject();
                    if (active && !active.isEditing) {
                        fc.remove(active);
                        fc.renderAll();
                    }
                    break;
                case 'ArrowLeft':
                    if (e.altKey) { e.preventDefault(); navigateFile(-1); }
                    break;
                case 'ArrowRight':
                    if (e.altKey) { e.preventDefault(); navigateFile(1); }
                    break;
            }
        });
    }

    // ===== Event Bindings =====
    function initEvents() {
        // Folder select
        $('folderSelect').addEventListener('change', (e) => {
            state.activeFolder = e.target.value;
            loadFiles();
        });
        
        // Upload inputs
        $('uploadInput').addEventListener('change', (e) => {
            uploadFiles(e.target.files);
            e.target.value = '';
        });
        $('dropInput').addEventListener('change', (e) => {
            uploadFiles(e.target.files);
            e.target.value = '';
        });
        
        // Toolbar buttons
        document.querySelectorAll('.ie-tool-btn[data-tool]').forEach(btn => {
            btn.addEventListener('click', () => setTool(btn.dataset.tool));
        });
        
        // Rotate/Flip
        $('rotateLeftBtn').addEventListener('click', () => rotateImage(-90));
        $('rotateRightBtn').addEventListener('click', () => rotateImage(90));
        $('flipHBtn').addEventListener('click', () => flipImage('h'));
        $('flipVBtn').addEventListener('click', () => flipImage('v'));
        $('resetBtn').addEventListener('click', () => {
            if (state.currentFile) openImage(state.currentFile);
        });
        
        // History
        $('undoBtn').addEventListener('click', undo);
        $('redoBtn').addEventListener('click', redo);
        
        // Action buttons
        $('downloadBtn').addEventListener('click', downloadImage);
        $('saveBtn').addEventListener('click', saveToServer);
        $('compressBtn').addEventListener('click', compressCurrent);
        $('prevBtn').addEventListener('click', () => navigateFile(-1));
        $('nextBtn').addEventListener('click', () => navigateFile(1));
        $('deleteBtn').addEventListener('click', async () => {
            if (!state.currentFile || !confirm('Delete ' + state.currentFile.name + '?')) return;
            const currentIdx = state.fileList.findIndex(f => f.rel === state.currentFile.rel);
            const res = await api('delete', { file: state.currentFile.rel });
            if (res.ok) {
                toast('Deleted');
                loadFiles().then(() => {
                    // Navigate to next file after delete
                    if (state.fileList.length > 0) {
                        const nextIdx = Math.min(currentIdx, state.fileList.length - 1);
                        openImage(state.fileList[nextIdx]);
                    } else {
                        closeImage();
                    }
                });
            }
        });
        
        // Adjustment sliders
        ['brightness', 'contrast', 'saturation', 'blur'].forEach(id => {
            $(id).addEventListener('input', () => {
                $(id + 'Val').textContent = $(id).value;
                applyFilters();
            });
            $(id).addEventListener('change', () => saveHistory());
        });
        $('resetAdjustBtn').addEventListener('click', resetAdjustments);
        
        // Crop aspect ratio
        document.querySelectorAll('.ie-aspect-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.ie-aspect-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const ratio = btn.dataset.ratio;
                state.cropRatio = ratio === 'free' ? null : ratio;
                
                if (state.cropRatio && state.isCropping) {
                    const [rw, rh] = ratio.split(':').map(Number);
                    const r = state.cropRect;
                    const targetRatio = rw / rh;
                    r.h = r.w / targetRatio;
                    if (r.y + r.h > fc.getHeight()) {
                        r.h = fc.getHeight() - r.y;
                        r.w = r.h * targetRatio;
                    }
                    renderCropOverlay();
                }
            });
        });
        $('applyCropBtn').addEventListener('click', applyCrop);
        
        // Draw settings
        document.querySelectorAll('.ie-draw-color').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.ie-draw-color').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                if (fc.freeDrawingBrush) fc.freeDrawingBrush.color = btn.dataset.color;
            });
        });
        $('brushSize').addEventListener('input', () => {
            $('brushSizeVal').textContent = $('brushSize').value;
            if (fc.freeDrawingBrush) fc.freeDrawingBrush.width = parseInt($('brushSize').value);
        });
        $('brushOpacity').addEventListener('input', () => {
            $('brushOpacityVal').textContent = $('brushOpacity').value;
            if (fc.freeDrawingBrush) fc.freeDrawingBrush.opacity = parseInt($('brushOpacity').value) / 100;
        });
        
        // Text settings
        $('textSize').addEventListener('input', () => {
            $('textSizeVal').textContent = $('textSize').value;
            const active = fc.getActiveObject();
            if (active && active.type === 'i-text') {
                active.set('fontSize', parseInt($('textSize').value));
                fc.renderAll();
            }
        });
        $('textColor').addEventListener('input', () => {
            const active = fc.getActiveObject();
            if (active && active.type === 'i-text') {
                active.set('fill', $('textColor').value);
                fc.renderAll();
            }
        });
        $('textFont').addEventListener('change', () => {
            const active = fc.getActiveObject();
            if (active && active.type === 'i-text') {
                active.set('fontFamily', $('textFont').value);
                fc.renderAll();
            }
        });
        ['textBold', 'textItalic', 'textUnderline'].forEach(id => {
            $(id).addEventListener('click', () => {
                $(id).classList.toggle('active');
                const active = fc.getActiveObject();
                if (!active || active.type !== 'i-text') return;
                if (id === 'textBold') active.set('fontWeight', $(id).classList.contains('active') ? 'bold' : 'normal');
                if (id === 'textItalic') active.set('fontStyle', $(id).classList.contains('active') ? 'italic' : 'normal');
                if (id === 'textUnderline') active.set('underline', $(id).classList.contains('active'));
                fc.renderAll();
            });
        });
        
        // Filters panel - show on double click info panel area
        $('infoPanel').addEventListener('dblclick', () => {
            $('infoPanel').style.display = 'none';
            $('filtersPanel').style.display = 'block';
            $('panelTitle').textContent = 'Filters';
            $('panelIcon').className = 'bi bi-magic';
            initFilterPresets();
        });
        
        // Click filters panel header to go back to info
        document.querySelectorAll('.ie-panel-header')[1].addEventListener('click', () => {
            if ($('filtersPanel').style.display === 'block') {
                $('filtersPanel').style.display = 'none';
                $('infoPanel').style.display = 'block';
                $('panelTitle').textContent = 'Properties';
                $('panelIcon').className = 'bi bi-sliders';
            }
        });
        
        // Zoom
        $('zoomInBtn').addEventListener('click', () => setZoom(state.zoom + 0.1));
        $('zoomOutBtn').addEventListener('click', () => setZoom(state.zoom - 0.1));
        $('zoomFitBtn').addEventListener('click', () => setZoom(1));
        
        // Mouse wheel zoom
        $('canvasArea').addEventListener('wheel', (e) => {
            if (e.ctrlKey) {
                e.preventDefault();
                setZoom(state.zoom + (e.deltaY > 0 ? -0.05 : 0.05));
            }
        });
    }

    // ===== Window Resize =====
    window.addEventListener('resize', () => {
        if (state.currentFile && fc.backgroundImage) {
            setZoom(state.zoom); // Re-fit
        }
    });

    // ===== Init =====
    function init() {
        initCanvas();
        initCropEvents();
        initDragDrop();
        initKeyboard();
        initEvents();
        loadFolders();
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
