<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'reporter_auth.php';
requireReporter();

 $rep = currentReporter();
 $patientId = (int)($_GET['patient'] ?? 0);
if ($patientId <= 0) { header('Location: reporter_index.php'); exit; }

 $selectedStudyId = (int)($_GET['study'] ?? 0);

try {
    $pStmt = db()->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
    $pStmt->execute([$patientId]);
    $patient = $pStmt->fetch();
    if (!$patient) { header('Location: reporter_index.php'); exit; }

    try {
        $authStmt = ravcoDb()->prepare("SELECT 1 FROM pat_radio WHERE dr_report = ? AND pat_id = ? LIMIT 1");
        $authStmt->execute([$rep['username'], $patient['patient_id']]);
        if (!$authStmt->fetch()) {
            header('Location: reporter_index.php');
            exit;
        }
    } catch (Throwable $e) {
        header('Location: reporter_index.php');
        exit;
    }

    $sStmt = db()->prepare("
        SELECT st.*, COUNT(i.id) AS images_count
        FROM studies st
        LEFT JOIN series se ON se.study_id = st.id
        LEFT JOIN instances i ON i.series_id = se.id
        WHERE st.patient_id = ?
        GROUP BY st.id
        ORDER BY COALESCE(st.study_date,'9999-12-31') DESC, st.id DESC
    ");
    $sStmt->execute([$patientId]);
    $studies = $sStmt->fetchAll();

    if ($selectedStudyId > 0) {
        $validStudy = false;
        foreach ($studies as $st) {
            if ((int)$st['id'] === $selectedStudyId) { $validStudy = true; break; }
        }
        if (!$validStudy) $selectedStudyId = 0;
    }

    $rStmt = db()->prepare("
        SELECT pr.*, r.full_name AS reporter_name
        FROM patient_reports pr
        JOIN reporters r ON r.id = pr.reporter_id
        WHERE pr.patient_id = ?
        ORDER BY pr.updated_at DESC, pr.id DESC
    ");
    $rStmt->execute([$patientId]);
    $reports = $rStmt->fetchAll();
} catch (Throwable $e) {
    header('Location: reporter_index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Patient Report — <?= htmlspecialchars($patient['patient_name'] ?? 'Patient') ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=viewer-merged-1"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<!-- Quill CSS -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet"/>

<!-- html2pdf.js for PDF export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<!-- Google Fonts (100 fonts) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&family=Open+Sans:wght@400;700&family=Lato:wght@400;700&family=Montserrat:wght@400;700&family=Poppins:wght@400;700&family=Inter:wght@400;700&family=Nunito:wght@400;700&family=Raleway:wght@400;700&family=Ubuntu:wght@400;700&family=Playfair+Display:wght@400;700&family=Merriweather:wght@400;700&family=PT+Sans:wght@400;700&family=Rubik:wght@400;700&family=Work+Sans:wght@400;700&family=Source+Sans+3:wght@400;700&family=Noto+Sans:wght@400;700&family=Fira+Sans:wght@400;700&family=Cabin:wght@400;700&family=Barlow:wght@400;700&family=Inconsolata:wght@400;700&family=JetBrains+Mono:wght@400;700&family=DM+Sans:wght@400;700&family=Karla:wght@400;700&family=Manrope:wght@400;700&family=Quicksand:wght@400;700&family=Mulish:wght@400;700&family=Titillium+Web:wght@400;700&family=Hind:wght@400;700&family=Mukta:wght@400;700&family=Prompt:wght@400;700&family=Libre+Baskerville:wght@400;700&family=Arimo:wght@400;700&family=Asap:wght@400;700&family=Bitter:wght@400;700&family=Bree+Serif&family=Crimson+Text:wght@400;700&family=EB+Garamond:wght@400;700&family=Josefin+Sans:wght@400;700&family=Libre+Franklin:wght@400;700&family=Lora:wght@400;700&family=Oxygen:wght@400;700&family=Questrial&family=Slabo+27px&family=Varela+Round&family=Yantramanav:wght@400;700&family=Zilla+Slab:wght@400;700&family=Abel&family=Abril+Fatface&family=Acme&family=Anton&family=Audiowide&family=Baloo+2:wght@400;700&family=Be+Vietnam+Pro:wght@400;700&family=Chivo:wght@400;700&family=Cinzel:wght@400;700&family=Comfortaa:wght@400;700&family=Cormorant+Garamond:wght@400;700&family=Dosis:wght@400;700&family=Exo+2:wght@400;700&family=Francois+One&wght@400;700&family=Heebo:wght@400;700&family=IBM+Plex+Sans:wght@400;700&family=IBM+Plex+Serif:wght@400;700&family=Indie+Flower&family=Kanit:wght@400;700&family=Lobster&family=News+Cycle:wght@400;700&family=Nunito+Sans:wght@400;700&family=Overpass:wght@400;700&family=Pacifico&family=Patua+One&family=Philosopher&wght@400;700&family=Plus+Jakarta+Sans:wght@400;700&family=Righteous&wght@400;700&family=Sarabun:wght@400;700&family=Signika:wght@400;700&family=Space+Grotesk:wght@400;700&family=Teko:wght@400;700&family=Vollkorn:wght@400;700&family=Archivo:wght@400;700&family=Assistant:wght@400;700&family=Catamaran:wght@400;700&family=Domine:wght@400;700&family=Encode+Sans:wght@400;700&family=Gudea:wght@400;700&family=Jost:wght@400;700&family=Khand:wght@400;700&family=Lexend:wght@400;700&family=Mada:wght@400;700&family=Nanum+Gothic:wght@400;700&family=Outfit:wght@400;700&family=Public+Sans:wght@400;700&family=Sora:wght@400;700&family=Tajawal:wght@400;700&family=Urbanist:wght@400;700&family=Vazirmatn:wght@400;700&display=swap" rel="stylesheet">

<style>
:root{
  --bg:#0b1220; --panel:#111827; --panel-2:#0f172a; --text:#e5e7eb; --muted:#94a3b8;
  --border:#334155; --accent:#3CD08F; --btn-bg:#1e293b; --btn-text:#fff; --btn-hover:#334155;
}
body.dashboard-page{background:var(--bg);color:var(--text)}
body.light-mode{
  --bg:#f3f6fb; --panel:#fff; --panel-2:#f8fafc; --text:#0f172a; --muted:#475569;
  --border:#d1d5db; --accent:#0ea5e9; --btn-bg:#e2e8f0; --btn-text:#0f172a; --btn-hover:#cbd5e1;
}

.report-wrap{
  max-width:2250px;
  width:70%;
  margin:20px auto;
  padding:0 14px;
}

.card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:14px;color:var(--text)}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
small.muted,.muted{color:var(--muted)}
.study-row{display:flex;justify-content:space-between;align-items:center;gap:10px;border:1px solid var(--border);border-radius:10px;padding:10px;margin-bottom:10px}
.study-actions{display:flex;gap:8px;flex-wrap:wrap}
.editor-card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px}
.editor-card-head h3{margin:0}

#formatToolbar.ql-toolbar.ql-snow{
  border:1px solid var(--border);
  background:var(--panel-2);
  border-radius:10px;
}
.ql-container.ql-snow{
  border:1px solid var(--border);
  border-radius:10px;
  background:var(--panel-2);
  color:var(--text);
  min-height:60vh;
  font-size:16px;
}
.ql-editor{
  min-height:60vh;
  line-height:1.7;
  color:var(--text);
  position:relative;
}
.ql-editor.ql-blank::before{color:var(--muted);font-style:normal}
.ql-snow .ql-stroke{stroke:var(--btn-text)}
.ql-snow .ql-fill{fill:var(--btn-text)}
.ql-snow .ql-picker{color:var(--btn-text)}
.ql-snow .ql-picker-options{
  background:var(--panel);
  border:1px solid var(--border);
  max-height:320px;
  overflow:auto;
}
.ql-snow .ql-picker-item:hover,.ql-snow .ql-picker-label:hover,.ql-snow button:hover{color:var(--accent)!important}
.ql-snow button:hover .ql-stroke{stroke:var(--accent)}
.ql-snow button:hover .ql-fill{fill:var(--accent)}

.ql-snow .ql-picker.ql-size .ql-picker-label::before,
.ql-snow .ql-picker.ql-size .ql-picker-item::before {
  content: attr(data-value);
}
.ql-snow .ql-picker.ql-size .ql-picker-item[data-value="16px"]::before,
.ql-snow .ql-picker.ql-size .ql-picker-label[data-value="16px"]::before {
  content: "16";
}

.paste-hint{display:flex;align-items:center;gap:6px;color:var(--accent);font-size:12px;margin-top:6px;opacity:.9}

body.editor-fullscreen{overflow:hidden}
body.editor-fullscreen #editorCard{
  position:fixed;inset:0;z-index:9998;margin:0;border-radius:0;overflow:auto;padding:18px 22px;
}
body.editor-fullscreen .ql-container.ql-snow{min-height:calc(100vh - 320px)}
body.editor-fullscreen .ql-editor{min-height:calc(100vh - 340px)}

.ql-editor img{
  max-width:100%;
  height:auto;
  border-radius:8px;
  border:1px solid var(--border);
  display:inline-block;
  vertical-align:top;
  margin:6px 8px 6px 0;
  cursor:grab;
}
.ql-editor img.is-selected{border-color:var(--accent)}
.ql-editor img.is-dragging{opacity:.7;cursor:grabbing}
.ql-editor img.is-free{
  position:absolute !important;
  margin:0 !important;
  z-index:3;
  cursor:move;
}

#imgOverlay{
  position:fixed;z-index:10001;display:none;box-sizing:border-box;
  border:1px dashed rgba(60,208,143,.9);border-radius:8px;pointer-events:none;
}
#imgOverlay.visible{display:block}
.img-resize-handle{
  position:absolute;width:12px;height:12px;background:var(--accent);
  border:1px solid var(--panel-2);border-radius:2px;pointer-events:auto;
}
.img-resize-handle[data-pos="se"]{bottom:-6px;right:-6px;cursor:se-resize}
.img-resize-handle[data-pos="sw"]{bottom:-6px;left:-6px;cursor:sw-resize}
.img-resize-handle[data-pos="ne"]{top:-6px;right:-6px;cursor:ne-resize}
.img-resize-handle[data-pos="nw"]{top:-6px;left:-6px;cursor:nw-resize}
.img-mini-toolbar{
  position:absolute;left:0;display:flex;gap:6px;pointer-events:auto;
  background:var(--panel-2);border:1px solid var(--border);border-radius:6px;padding:4px;
  box-shadow:0 6px 18px rgba(0,0,0,.45);
}
.img-mini-toolbar button{
  background:var(--btn-bg);color:var(--btn-text);border:none;padding:5px 9px;border-radius:4px;
  cursor:pointer;font-size:12px;line-height:1;
}
.img-mini-toolbar button:hover{background:var(--btn-hover);color:var(--accent)}
.img-mini-toolbar button.danger:hover{background:#7f1d1d;color:#fecaca}
.img-mini-toolbar .sizer{
  background:var(--btn-bg);color:var(--muted);border-radius:4px;padding:5px 8px;font-size:12px;line-height:1;
}
#dropMarker{
  position:fixed;width:3px;background:var(--accent);pointer-events:none;
  z-index:10002;display:none;border-radius:2px;box-shadow:0 0 8px rgba(60,208,143,.9);
}

/* Export button styles */
.export-actions{
  display:flex;gap:8px;flex-wrap:wrap;align-items:center;
  padding:12px 0 0; border-top:1px solid var(--border); margin-top:12px;
}
.export-actions .btn-export{
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 18px;border-radius:8px;border:1px solid var(--border);
  background:var(--btn-bg);color:var(--btn-text);font-size:13px;
  cursor:pointer;transition:all .2s;font-weight:600;
}
.export-actions .btn-export:hover{background:var(--btn-hover);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.3)}
.export-actions .btn-export:active{transform:translateY(0)}
.export-actions .btn-export i{font-size:14px}
.export-actions .btn-export.btn-pdf{border-color:#ef4444;color:#fca5a5}
.export-actions .btn-export.btn-pdf:hover{background:#7f1d1d;border-color:#ef4444;color:#fecaca}
.export-actions .btn-export.btn-word{border-color:#3b82f6;color:#93c5fd}
.export-actions .btn-export.btn-word:hover{background:#1e3a5f;border-color:#3b82f6;color:#bfdbfe}
.export-actions .btn-export.btn-print{border-color:var(--accent);color:var(--accent)}
.export-actions .btn-export.btn-print:hover{background:rgba(60,208,143,.12)}

/* Export loading overlay */
#exportOverlay{
  position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.72);
  display:none;align-items:center;justify-content:center;flex-direction:column;gap:16px;
  backdrop-filter:blur(4px);
}
#exportOverlay.active{display:flex}
#exportOverlay .spinner{
  width:42px;height:42px;border:3px solid rgba(255,255,255,.15);border-top-color:var(--accent);
  border-radius:50%;animation:expSpin .75s linear infinite;
}
#exportOverlay .export-msg{color:#e5e7eb;font-size:15px;font-weight:600;letter-spacing:.3px}
@keyframes expSpin{to{transform:rotate(360deg)}}
</style>
</head>
<body class="dashboard-page">
<header class="app-header">
  <div class="header-brand"><i class="fas fa-file-signature brand-icon"></i><span class="brand-name">Reporter Panel</span></div>
  <nav class="header-nav">
    <a href="reporter_index.php" class="nav-item"><i class="fas fa-users"></i> Patients</a>
    <a href="#" class="nav-item active"><i class="fas fa-pen"></i> Report</a>
  </nav>
  <div class="header-actions">
    <button type="button" class="btn btn-ghost" id="themeToggle"><i class="fas fa-moon"></i> Dark</button>
    <a href="reporter_logout.php" class="btn btn-ghost">Logout</a>
  </div>
</header>

<!-- Export loading overlay -->
<div id="exportOverlay">
  <div class="spinner"></div>
  <div class="export-msg" id="exportOverlayMsg">Preparing export...</div>
</div>

<div class="report-wrap">
  <div class="card">
    <h2 style="margin-top:0;"><?= htmlspecialchars($patient['patient_name'] ?: '—') ?></h2>
    <div class="row">
      <small class="muted">Patient ID: <?= htmlspecialchars($patient['patient_id'] ?: '—') ?></small>
      <small class="muted">Sex: <?= htmlspecialchars($patient['sex'] ?: '—') ?></small>
      <small class="muted">Birth Date: <?= $patient['birth_date'] ? date('d/m/Y', strtotime($patient['birth_date'])) : '—' ?></small>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Patient Studies</h3>
    <?php if (empty($studies)): ?>
      <p class="muted">No studies found for this patient.</p>
    <?php else: foreach ($studies as $st): ?>
      <div class="study-row">
        <div>
          <div><strong>Study #<?= (int)$st['id'] ?></strong></div>
          <small class="muted">
            Modality: <?= htmlspecialchars((string)($st['modality'] ?: '—')) ?> |
            Date: <?= !empty($st['study_date']) ? htmlspecialchars((string)$st['study_date']) : '—' ?> |
            Images: <?= (int)$st['images_count'] ?>
          </small>
        </div>
        <div class="study-actions">
          <a class="btn btn-view" href="reporter_viewer.php?study=<?= (int)$st['id'] ?>&patient=<?= (int)$patientId ?>" target="_blank" rel="noopener">
            <i class="fas fa-image"></i> Open Study
          </a>
          <button type="button" class="btn btn-ghost use-study-btn"
                  onclick="document.getElementById('studyId').value='<?= (int)$st['id'] ?>'; document.querySelectorAll('.use-study-btn').forEach(b=>b.classList.remove('active')); this.classList.add('active');">
            Use for Report
          </button>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card" id="editorCard">
    <div class="editor-card-head">
      <h3>Write Report</h3>
      <button type="button" class="btn btn-ghost" id="btnFullscreen"><i class="fas fa-expand"></i> Fullscreen</button>
    </div>

    <form id="reportForm">
      <input type="hidden" name="patientId" value="<?= (int)$patientId ?>">
      <input type="hidden" id="studyId" name="studyId" value="<?= (int)$selectedStudyId ?>">

      <div id="formatToolbar">
        <span class="ql-formats">
          <button class="ql-undo" type="button" title="Undo"><i class="fas fa-rotate-left"></i></button>
          <button class="ql-redo" type="button" title="Redo"><i class="fas fa-rotate-right"></i></button>
        </span>
        <span class="ql-formats">
          <select class="ql-font" id="fontSelect"></select>
          <select class="ql-size" id="fontSizeSelect"></select>
        </span>
        <span class="ql-formats">
          <button class="ql-bold" type="button"></button>
          <button class="ql-italic" type="button"></button>
          <button class="ql-underline" type="button"></button>
          <select class="ql-color"></select>
        </span>
        <span class="ql-formats">
          <button class="ql-align" value="" type="button"></button>
          <button class="ql-align" value="center" type="button"></button>
          <button class="ql-align" value="right" type="button"></button>
          <button class="ql-list" value="ordered" type="button"></button>
          <button class="ql-list" value="bullet" type="button"></button>
        </span>
      </div>

      <div id="reportEditor"></div>
      <div class="paste-hint">
        <i class="fas fa-paste"></i>
        Ctrl+V to paste text or images. Click image to resize. Use "Free Move" to drag image anywhere.
      </div>

      <div class="row" style="margin-top:10px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Report</button>
        <span id="saveMsg" class="muted"></span>
      </div>

      <!-- Export buttons -->
      <div class="export-actions">
        <button type="button" class="btn-export btn-print" id="btnPrint">
          <i class="fas fa-print"></i> Print
        </button>
        <button type="button" class="btn-export btn-pdf" id="btnPdf">
          <i class="fas fa-file-pdf"></i> Save to PDF
        </button>
        <button type="button" class="btn-export btn-word" id="btnWord">
          <i class="fas fa-file-word"></i> Save to Word
        </button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Previous Reports</h3>
    <?php if (empty($reports)): ?>
      <p class="muted">No reports yet.</p>
    <?php else: foreach ($reports as $rp): ?>
      <div style="border:1px solid var(--border);border-radius:10px;padding:10px;margin-bottom:10px;">
        <div class="row" style="justify-content:space-between">
          <strong><?= htmlspecialchars($rp['reporter_name']) ?></strong>
          <small class="muted"><?= htmlspecialchars((string)$rp['updated_at']) ?></small>
        </div>
        <div style="margin-top:8px;white-space:pre-wrap;"><?= $rp['report_html'] ?? htmlspecialchars($rp['report_text']) ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>

<!-- Pass patient data to JavaScript for export -->
<script>
var patientData = {
  name: <?= json_encode($patient['patient_name'] ?? '—') ?>,
  id: <?= json_encode($patient['patient_id'] ?? '—') ?>,
  sex: <?= json_encode($patient['sex'] ?? '—') ?>,
  birthDate: <?= json_encode($patient['birth_date'] ? date('d/m/Y', strtotime($patient['birth_date'])) : '—') ?>,
  fileName: <?= json_encode(($patient['patient_name'] ?? 'report') . ' - ' . date('Y-m-d')) ?>
};
</script>

<script>
(function () {
  'use strict';

  var FONTS = [
    'roboto','open-sans','lato','montserrat','poppins','inter','nunito','raleway','ubuntu','playfair-display',
    'merriweather','pt-sans','rubik','work-sans','source-sans-3','noto-sans','fira-sans','cabin','barlow','inconsolata',
    'jetbrains-mono','dm-sans','karla','manrope','quicksand','mulish','titillium-web','hind','mukta','prompt',
    'libre-baskerville','arimo','asap','bitter','bree-serif','crimson-text','eb-garamond','josefin-sans','libre-franklin','lora',
    'oxygen','questrial','slabo-27px','varela-round','yantramanav','zilla-slab','abel','abril-fatface','acme','anton',
    'audiowide','baloo-2','be-vietnam-pro','chivo','cinzel','comfortaa','cormorant-garamond','dosis','exo-2','francois-one',
    'heebo','ibm-plex-sans','ibm-plex-serif','indie-flower','kanit','lobster','news-cycle','nunito-sans','overpass','pacifico',
    'patua-one','philosopher','plus-jakarta-sans','righteous','sarabun','signika','space-grotesk','teko','vollkorn','archivo',
    'assistant','catamaran','domine','encode-sans','gudea','jost','khand','lexend','mada','nanum-gothic',
    'outfit','public-sans','sora','tajawal','urbanist','vazirmatn','arial','times-new-roman','courier-new','georgia'
  ];

  var FONT_LABELS = {
    'roboto':'Roboto','open-sans':'Open Sans','lato':'Lato','montserrat':'Montserrat','poppins':'Poppins','inter':'Inter','nunito':'Nunito','raleway':'Raleway','ubuntu':'Ubuntu','playfair-display':'Playfair Display',
    'merriweather':'Merriweather','pt-sans':'PT Sans','rubik':'Rubik','work-sans':'Work Sans','source-sans-3':'Source Sans 3','noto-sans':'Noto Sans','fira-sans':'Fira Sans','cabin':'Cabin','barlow':'Barlow','inconsolata':'Inconsolata',
    'jetbrains-mono':'JetBrains Mono','dm-sans':'DM Sans','karla':'Karla','manrope':'Manrope','quicksand':'Quicksand','mulish':'Mulish','titillium-web':'Titillium Web','hind':'Hind','mukta':'Mukta','prompt':'Prompt',
    'libre-baskerville':'Libre Baskerville','arimo':'Arimo','asap':'Asap','bitter':'Bitter','bree-serif':'Bree Serif','crimson-text':'Crimson Text','eb-garamond':'EB Garamond','josefin-sans':'Josefin Sans','libre-franklin':'Libre Franklin','lora':'Lora',
    'oxygen':'Oxygen','questrial':'Questrial','slabo-27px':'Slabo 27px','varela-round':'Varela Round','yantramanav':'Yantramanav','zilla-slab':'Zilla Slab','abel':'Abel','abril-fatface':'Abril Fatface','acme':'Acme','anton':'Anton',
    'audiowide':'Audiowide','baloo-2':'Baloo 2','be-vietnam-pro':'Be Vietnam Pro','chivo':'Chivo','cinzel':'Cinzel','comfortaa':'Comfortaa','cormorant-garamond':'Cormorant Garamond','dosis':'Dosis','exo-2':'Exo 2','francois-one':'Francois One',
    'heebo':'Heebo','ibm-plex-sans':'IBM Plex Sans','ibm-plex-serif':'IBM Plex Serif','indie-flower':'Indie Flower','kanit':'Kanit','lobster':'Lobster','news-cycle':'News Cycle','nunito-sans':'Nunito Sans','overpass':'Overpass','pacifico':'Pacifico',
    'patua-one':'Patua One','philosopher':'Philosopher','plus-jakarta-sans':'Plus Jakarta Sans','righteous':'Righteous','sarabun':'Sarabun','signika':'Signika','space-grotesk':'Space Grotesk','teko':'Teko','vollkorn':'Vollkorn','archivo':'Archivo',
    'assistant':'Assistant','catamaran':'Catamaran','domine':'Domine','encode-sans':'Encode Sans','gudea':'Gudea','jost':'Jost','khand':'Khand','lexend':'Lexend','mada':'Mada','nanum-gothic':'Nanum Gothic',
    'outfit':'Outfit','public-sans':'Public Sans','sora':'Sora','tajawal':'Tajawal','urbanist':'Urbanist','vazirmatn':'Vazirmatn','arial':'Arial','times-new-roman':'Times New Roman','courier-new':'Courier New','georgia':'Georgia'
  };

  var FONT_STACK = {
    'arial':'Arial, sans-serif',
    'times-new-roman':'"Times New Roman", serif',
    'courier-new':'"Courier New", monospace',
    'georgia':'Georgia, serif'
  };

  function cssFontFamily(key){
    if (FONT_STACK[key]) return FONT_STACK[key];
    return '"' + (FONT_LABELS[key] || key) + '", sans-serif';
  }

  function buildFontCSSString() {
    var css = '';
    FONTS.forEach(function(f) {
      var family = cssFontFamily(f);
      css += '.ql-font-' + f + ' { font-family: ' + family + '; }\n';
    });
    return css;
  }

  function getUsedFontKeys(html) {
    var used = [];
    var seen = {};
    var regex = /ql-font-(\S+)/g;
    var match;
    while ((match = regex.exec(html)) !== null) {
      if (!seen[match[1]]) {
        seen[match[1]] = true;
        used.push(match[1]);
      }
    }
    return used;
  }

  function buildGoogleFontsLink(fontKeys) {
    var families = [];
    fontKeys.forEach(function(key) {
      var label = FONT_LABELS[key];
      if (label && key !== 'arial' && key !== 'times-new-roman' && key !== 'courier-new' && key !== 'georgia') {
        families.push(label.replace(/ /g, '+') + ':wght@400;700');
      }
    });
    if (families.length === 0) return '';
    return 'https://fonts.googleapis.com/css2?family=' + families.join('&family=') + '&display=swap';
  }

  function injectFontCSS() {
    var css = '';
    FONTS.forEach(function(f){
      var label = FONT_LABELS[f] || f;
      var family = cssFontFamily(f);
      css += '.ql-font-' + f + '{font-family:' + family + ';}\n';
      css += '.ql-snow .ql-picker.ql-font .ql-picker-item[data-value="' + f + '"]::before{content:"' + label.replace(/"/g,'\\"') + '";font-family:' + family + ';}\n';
      css += '.ql-snow .ql-picker.ql-font .ql-picker-label[data-value="' + f + '"]::before{content:"' + label.replace(/"/g,'\\"') + '";font-family:' + family + ';}\n';
    });
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);
  }

  function fillFontSelect() {
    var sel = document.getElementById('fontSelect');
    if (!sel) return;
    sel.innerHTML = '';
    FONTS.forEach(function(f, idx){
      var opt = document.createElement('option');
      opt.value = f;
      opt.textContent = FONT_LABELS[f] || f;
      if (idx === 0) opt.selected = true;
      sel.appendChild(opt);
    });
  }

  function fillSizeSelect() {
    var sel = document.getElementById('fontSizeSelect');
    if (!sel) return;
    sel.innerHTML = '';
    for (var i = 4; i <= 150; i++) {
      var opt = document.createElement('option');
      opt.value = i + 'px';
      opt.textContent = i;
      if (i === 16) opt.selected = true;
      sel.appendChild(opt);
    }
  }

  injectFontCSS();
  fillFontSelect();
  fillSizeSelect();

  var Font = Quill.import('formats/font');
  Font.whitelist = FONTS;
  Quill.register(Font, true);

  var SizeStyle = Quill.import('attributors/style/size');
  var SIZE_WHITELIST = [];
  for (var s = 4; s <= 150; s++) SIZE_WHITELIST.push(s + 'px');
  SizeStyle.whitelist = SIZE_WHITELIST;
  Quill.register(SizeStyle, true);

  var quill = new Quill('#reportEditor', {
    theme: 'snow',
    modules: {
      toolbar: '#formatToolbar',
      history: { delay: 1000, maxStack: 200, userOnly: true }
    },
    placeholder: 'Write medical report...'
  });

  document.querySelector('.ql-undo').addEventListener('click', function(){ quill.history.undo(); });
  document.querySelector('.ql-redo').addEventListener('click', function(){ quill.history.redo(); });

  /* Theme toggle */
  (function initTheme(){
    var key = 'reporter-theme';
    var btn = document.getElementById('themeToggle');
    var saved = localStorage.getItem(key) || 'dark';
    apply(saved);
    btn.addEventListener('click', function(){ apply(document.body.classList.contains('light-mode') ? 'dark' : 'light'); });
    function apply(mode){
      if (mode === 'light') {
        document.body.classList.add('light-mode');
        btn.innerHTML = '<i class="fas fa-sun"></i> Light';
      } else {
        document.body.classList.remove('light-mode');
        btn.innerHTML = '<i class="fas fa-moon"></i> Dark';
      }
      localStorage.setItem(key, mode);
    }
  })();

  /* ====================================================================
     EXPORT FUNCTIONALITY — Print, PDF, Word
     ==================================================================== */

  var exportOverlay = document.getElementById('exportOverlay');
  var exportOverlayMsg = document.getElementById('exportOverlayMsg');

  function showExportOverlay(msg) {
    exportOverlayMsg.textContent = msg || 'Preparing export...';
    exportOverlay.classList.add('active');
  }
  function hideExportOverlay() {
    exportOverlay.classList.remove('active');
  }

  function getBaseUrl() {
    var href = window.location.href;
    var idx = href.lastIndexOf('/');
    return idx >= 0 ? href.substring(0, idx + 1) : './';
  }

  function validateContent() {
    var text = quill.getText().trim();
    if (!text) {
      alert('No report content to export. Please write the report first.');
      return false;
    }
    return true;
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
  }

  function sanitizeForExport(html) {
    var tempDiv = document.createElement('div');
    tempDiv.innerHTML = html;
    tempDiv.querySelectorAll('img.is-free').forEach(function(img) {
      img.classList.remove('is-free');
      img.style.position = '';
      img.style.left = '';
      img.style.top = '';
      img.style.display = 'inline-block';
      img.style.margin = '8px 10px 8px 0';
    });
    return tempDiv.innerHTML;
  }

  /*
   * Build export HTML string with flexbox layout:
   *
   *   ┌──────────────────────────┐  ← page top (0 margin)
   *   │   HEADER IMAGE (hr.png)   │  flex-shrink: 0
   *   ├──────────────────────────┤
   *   │   Patient info            │
   *   │   ────────────────        │  flex: 1 (grows, pushes footer down)
   *   │   Report content          │
   *   │                           │
   *   ├──────────────────────────┤
   *   │   FOOTER IMAGE (ft.png)   │  flex-shrink: 0
   *   └──────────────────────────┘  ← page bottom (0 margin)
   *
   * @param {string} minHeight  "100vh" for print/word, "297mm" for PDF
   */
  function buildExportBodyHTML(useAbsoluteUrls, minHeight) {
    var base = useAbsoluteUrls ? getBaseUrl() : '';
    var reportHtml = sanitizeForExport(quill.root.innerHTML);
    var mh = minHeight || '100vh';

    var html = '';

    /* Flex column — fills exactly one full page, footer pinned to bottom edge */
    html += '<div style="display:flex;flex-direction:column;min-height:' + mh + ';background:#fff;">';

    /* HEADER — top of page, no gap */
    html += '<div style="width:100%;line-height:0;flex-shrink:0;">';
    html += '  <img src="' + base + 'assets/images/hr.png" style="width:100%;height:auto;display:block;" />';
    html += '</div>';

    /* MIDDLE — fills space between header and footer */
    html += '<div style="flex:1;display:flex;flex-direction:column;">';
    html += '  <div style="padding:16px 24px 0;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">';
    html += '    <h2 style="margin:0 0 6px;font-size:20px;color:#0f172a;">' + escapeHtml(patientData.name) + '</h2>';
    html += '    <p style="margin:0 0 4px;font-size:13px;color:#475569;">';
    html += '      <strong>Patient ID:</strong> ' + escapeHtml(patientData.id);
    html += '      &nbsp;&nbsp;|&nbsp;&nbsp;<strong>Sex:</strong> ' + escapeHtml(patientData.sex);
    html += '      &nbsp;&nbsp;|&nbsp;&nbsp;<strong>Birth Date:</strong> ' + escapeHtml(patientData.birthDate);
    html += '    </p>';
    html += '    <hr style="border:none;border-top:1px solid #d1d5db;margin:10px 0 0;"/>';
    html += '  </div>';
    html += '  <div style="flex:1;padding:6px 24px 16px;font-family:Arial,Helvetica,sans-serif;color:#1e293b;line-height:1.7;font-size:15px;">';
    html += reportHtml;
    html += '  </div>';
    html += '</div>';

    /* FOOTER — bottom of page, no gap */
    html += '<div style="width:100%;line-height:0;flex-shrink:0;">';
    html += '  <img src="' + base + 'assets/images/ft.png" style="width:100%;height:auto;display:block;" />';
    html += '</div>';

    html += '</div>';

    return html;
  }

  /* ------------------------------------------------------------------
     PRINT
     ------------------------------------------------------------------ */
  function doPrint() {
    if (!validateContent()) return;

    var reportHtml = quill.root.innerHTML;
    var usedFonts = getUsedFontKeys(reportHtml);
    var fontLink = buildGoogleFontsLink(usedFonts);
    var fontCSS = buildFontCSSString();
    var bodyHTML = buildExportBodyHTML(true, '100vh');

    var printDoc = '<!DOCTYPE html>\n'
      + '<html lang="en">\n<head>\n'
      + '<meta charset="UTF-8"/>\n'
      + '<title>Print Report — ' + escapeHtml(patientData.name) + '</title>\n'
      + (fontLink ? '<link rel="stylesheet" href="' + fontLink + '"/>\n' : '')
      + '<style>\n'
      + '  @page { margin: 0; size: A4; }\n'
      + '  * { box-sizing: border-box; margin: 0; padding: 0; }\n'
      + '  html, body { width: 100%; height: 100%; }\n'
      + '  body { font-family: Arial, Helvetica, sans-serif; background: #fff; color: #1e293b; }\n'
      + '  img { max-width: 100%; height: auto; }\n'
      + '  .ql-editor img { border: none; border-radius: 4px; margin: 6px 8px 6px 0; }\n'
      + fontCSS
      + '  .ql-align-center { text-align: center; }\n'
      + '  .ql-align-right { text-align: right; }\n'
      + '  .ql-align-justify { text-align: justify; }\n'
      + '  strong, b { font-weight: 700; }\n'
      + '  em, i { font-style: italic; }\n'
      + '  u { text-decoration: underline; }\n'
      + '  ol, ul { padding-left: 24px; margin: 6px 0; }\n'
      + '  li { margin: 3px 0; }\n'
      + '  p { margin: 4px 0; }\n'
      + '  s, strike { text-decoration: line-through; }\n'
      + '  blockquote { border-left: 3px solid #94a3b8; padding-left: 14px; margin: 8px 0; color: #475569; }\n'
      + '</style>\n'
      + '</head>\n<body>\n'
      + bodyHTML
      + '\n<script>\n'
      + '  document.fonts.ready.then(function() {\n'
      + '    setTimeout(function() { window.print(); }, 500);\n'
      + '  });\n'
      + '<\/script>\n'
      + '</body>\n</html>';

    var printWin = window.open('', '_blank', 'width=900,height=700');
    if (!printWin) {
      alert('Pop-up blocked. Please allow pop-ups for this site to use the Print feature.');
      return;
    }
    printWin.document.write(printDoc);
    printWin.document.close();
  }

  /* ------------------------------------------------------------------
     SAVE TO PDF
     Same layout as Print: header at very top, footer at very bottom,
     zero margins, full A4 page coverage.
     ------------------------------------------------------------------ */
  function doSavePDF() {
    if (!validateContent()) return;
    showExportOverlay('Generating PDF...');

    var base = getBaseUrl();
    var reportHtml = sanitizeForExport(quill.root.innerHTML);

    /*
     * Build the PDF container as a flex column matching the print layout.
     * The container is exactly 210mm × 297mm (A4) with zero padding/margin,
     * so header touches the top edge and footer touches the bottom edge —
     * identical to what the print dialog shows.
     */
    var container = document.createElement('div');
    container.id = 'pdf-export-container';
    container.style.cssText = 'position:fixed;left:-9999px;top:0;width:210mm;height:297mm;background:#fff;color:#1e293b;font-family:Arial,Helvetica,sans-serif;z-index:-1;display:flex;flex-direction:column;overflow:hidden;';

    /* Font CSS */
    var styleEl = document.createElement('style');
    styleEl.textContent =
      buildFontCSSString()
      + '\n#pdf-export-container img { max-width:100%; height:auto; border:none; border-radius:4px; margin:6px 8px 6px 0; }'
      + '\n#pdf-export-container .ql-align-center { text-align:center; }'
      + '\n#pdf-export-container .ql-align-right { text-align:right; }'
      + '\n#pdf-export-container .ql-align-justify { text-align:justify; }'
      + '\n#pdf-export-container strong, #pdf-export-container b { font-weight:700; }'
      + '\n#pdf-export-container em, #pdf-export-container i { font-style:italic; }'
      + '\n#pdf-export-container u { text-decoration:underline; }'
      + '\n#pdf-export-container ol, #pdf-export-container ul { padding-left:24px; margin:6px 0; }'
      + '\n#pdf-export-container li { margin:3px 0; }'
      + '\n#pdf-export-container p { margin:4px 0; }'
      + '\n#pdf-export-container s, #pdf-export-container strike { text-decoration:line-through; }'
      + '\n#pdf-export-container blockquote { border-left:3px solid #94a3b8; padding-left:14px; margin:8px 0; color:#475569; }';
    container.appendChild(styleEl);

    /* HEADER — at the very top edge of the page */
    var headerDiv = document.createElement('div');
    headerDiv.style.cssText = 'width:100%;line-height:0;flex-shrink:0;';
    headerDiv.innerHTML = '<img src="' + base + 'assets/images/hr.png" style="width:100%;height:auto;display:block;" />';
    container.appendChild(headerDiv);

    /* MIDDLE — fills space between header and footer */
    var middleDiv = document.createElement('div');
    middleDiv.style.cssText = 'flex:1;display:flex;flex-direction:column;overflow:hidden;';

    var infoDiv = document.createElement('div');
    infoDiv.style.cssText = 'padding:16px 24px 0;font-family:Arial,Helvetica,sans-serif;color:#1e293b;flex-shrink:0;';
    infoDiv.innerHTML = '<h2 style="margin:0 0 6px;font-size:20px;color:#0f172a;">' + escapeHtml(patientData.name) + '</h2>'
      + '<p style="margin:0 0 4px;font-size:13px;color:#475569;">'
      + '<strong>Patient ID:</strong> ' + escapeHtml(patientData.id)
      + '&nbsp;&nbsp;|&nbsp;&nbsp;<strong>Sex:</strong> ' + escapeHtml(patientData.sex)
      + '&nbsp;&nbsp;|&nbsp;&nbsp;<strong>Birth Date:</strong> ' + escapeHtml(patientData.birthDate)
      + '</p>'
      + '<hr style="border:none;border-top:1px solid #d1d5db;margin:10px 0 0;"/>';
    middleDiv.appendChild(infoDiv);

    var contentDiv = document.createElement('div');
    contentDiv.style.cssText = 'flex:1;padding:6px 24px 16px;font-family:Arial,Helvetica,sans-serif;color:#1e293b;line-height:1.7;font-size:15px;overflow:hidden;';
    contentDiv.innerHTML = reportHtml;
    middleDiv.appendChild(contentDiv);

    container.appendChild(middleDiv);

    /* FOOTER — at the very bottom edge of the page */
    var footerDiv = document.createElement('div');
    footerDiv.style.cssText = 'width:100%;line-height:0;flex-shrink:0;';
    footerDiv.innerHTML = '<img src="' + base + 'assets/images/ft.png" style="width:100%;height:auto;display:block;" />';
    container.appendChild(footerDiv);

    document.body.appendChild(container);

    /* Wait for all images to load */
    var images = container.querySelectorAll('img');
    var loaded = 0;
    var total = images.length;
    var done = false;

    function finishPDF() {
      if (done) return;
      done = true;

      var filename = (patientData.fileName || 'report') + '.pdf';

      /* Zero margin — header/footer already touch page edges via flex */
      html2pdf().from(container).set({
        margin:      [0, 0, 0, 0],
        filename:    filename,
        image:       { type: 'jpeg', quality: 0.95 },
        html2canvas: { scale: 2, useCORS: true, logging: false, letterRendering: true },
        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak:   { mode: ['avoid-all', 'css', 'legacy'] }
      }).save().then(function() {
        cleanup();
      }).catch(function(err) {
        console.error('PDF generation error:', err);
        cleanup();
        alert('PDF generation failed. Try the Print option and choose "Save as PDF".');
      });
    }

    function cleanup() {
      if (container.parentNode) container.parentNode.removeChild(container);
      hideExportOverlay();
    }

    if (total === 0) {
      finishPDF();
    } else {
      images.forEach(function(img) {
        if (img.complete && img.naturalWidth > 0) {
          loaded++;
          if (loaded >= total) finishPDF();
        } else {
          img.onload = function() {
            loaded++;
            if (loaded >= total) finishPDF();
          };
          img.onerror = function() {
            loaded++;
            if (loaded >= total) finishPDF();
          };
        }
      });
      setTimeout(function() { finishPDF(); }, 10000);
    }
  }

  /* ------------------------------------------------------------------
     SAVE TO WORD
     ------------------------------------------------------------------ */
  function doSaveWord() {
    if (!validateContent()) return;
    showExportOverlay('Generating Word document...');

    var reportHtml = quill.root.innerHTML;
    var usedFonts = getUsedFontKeys(reportHtml);

    var fontCSS = '';
    usedFonts.forEach(function(key) {
      var family = cssFontFamily(key);
      fontCSS += '.ql-font-' + key + ' { font-family: ' + family + '; mso-bidi-font-family: ' + family + '; }\n';
    });

    var bodyContent = buildExportBodyHTML(true, '100vh');
    bodyContent = bodyContent.replace(/data-src=/g, 'src=');

    var wordDoc = '<!DOCTYPE html>\n'
      + '<html xmlns:o="urn:schemas-microsoft-com:office:office"\n'
      + '      xmlns:w="urn:schemas-microsoft-com:office:word"\n'
      + '      xmlns="http://www.w3.org/TR/REC-html40">\n'
      + '<head>\n'
      + '<meta charset="UTF-8"/>\n'
      + '<title>Report — ' + escapeHtml(patientData.name) + '</title>\n'
      + '<!--[if gte mso 9]>\n'
      + '<xml>\n'
      + '  <w:WordDocument>\n'
      + '    <w:View>Print</w:View>\n'
      + '    <w:Zoom>100</w:Zoom>\n'
      + '    <w:DoNotOptimizeForBrowser/>\n'
      + '  </w:WordDocument>\n'
      + '</xml>\n'
      + '<![endif]-->\n'
      + '<style>\n'
      + '  @page Section1 { size: A4; margin: 0mm; }\n'
      + '  div.Section1 { page: Section1; }\n'
      + '  html, body { width: 100%; height: 100%; margin: 0; padding: 0; }\n'
      + '  body { font-family: Arial, Helvetica, sans-serif; font-size: 12pt; color: #1e293b; line-height: 1.6; }\n'
      + '  img { max-width: 100%; height: auto; }\n'
      + fontCSS
      + '  .ql-align-center { text-align: center; }\n'
      + '  .ql-align-right { text-align: right; }\n'
      + '  .ql-align-justify { text-align: justify; }\n'
      + '  strong, b { font-weight: bold; }\n'
      + '  em, i { font-style: italic; }\n'
      + '  u { text-decoration: underline; }\n'
      + '  s, strike { text-decoration: line-through; }\n'
      + '  ol, ul { margin: 6px 0 6px 24px; }\n'
      + '  li { margin: 3px 0; }\n'
      + '  p { margin: 4px 0; }\n'
      + '  blockquote { border-left: 3px solid #94a3b8; padding-left: 14px; margin: 8px 0; color: #475569; }\n'
      + '</style>\n'
      + '</head>\n<body>\n'
      + '<div class="Section1">\n'
      + bodyContent
      + '\n</div>\n'
      + '</body>\n</html>';

    var blob = new Blob(['\ufeff' + wordDoc], { type: 'application/msword' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = (patientData.fileName || 'report') + '.doc';
    document.body.appendChild(a);
    a.click();
    setTimeout(function() {
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      hideExportOverlay();
    }, 250);
  }

  /* Bind export buttons */
  document.getElementById('btnPrint').addEventListener('click', doPrint);
  document.getElementById('btnPdf').addEventListener('click', doSavePDF);
  document.getElementById('btnWord').addEventListener('click', doSaveWord);

  /* ====================================================================
     END: Export functionality
     ==================================================================== */

  /* ====================================================================
     IMAGE CONTROLS (original functionality)
     ==================================================================== */

  var editorEl = quill.root;
  var overlay = null, toolbar = null, sizeLabel = null, dropMarker = null;
  var selectedImage = null, draggedImage = null;
  var resizing = false, handlePos = '', startX = 0, startY = 0, startW = 0, startH = 0, rafId = null;
  var freeMoving = false, imgMoveOffsetX = 0, imgMoveOffsetY = 0;

  buildOverlay();
  buildDropMarker();

  editorEl.addEventListener('click', function (e) {
    if (e.target.tagName === 'IMG') selectImage(e.target); else deselect();
  });
  document.addEventListener('mousedown', function (e) {
    if (!selectedImage) return;
    if (overlay.contains(e.target)) return;
    if (e.target.tagName === 'IMG' && editorEl.contains(e.target)) return;
    deselect();
  }, true);

  editorEl.addEventListener('dragstart', onDragStart);
  editorEl.addEventListener('dragover', onDragOver);
  editorEl.addEventListener('drop', onDrop);
  editorEl.addEventListener('dragend', endDrag);
  editorEl.addEventListener('dragleave', function () { dropMarker.style.display = 'none'; });

  window.addEventListener('dragover', function (e) { if (draggedImage) e.preventDefault(); });
  window.addEventListener('drop', function (e) { if (!draggedImage) return; e.preventDefault(); endDrag(); });

  document.addEventListener('mousemove', onResizeMove);
  document.addEventListener('mouseup', endResize);

  editorEl.addEventListener('mousedown', onImageFreeMoveStart);
  document.addEventListener('mousemove', onImageFreeMove);
  document.addEventListener('mouseup', onImageFreeMoveEnd);

  document.getElementById('btnFullscreen').addEventListener('click', function () {
    var on = document.body.classList.toggle('editor-fullscreen');
    this.innerHTML = on ? '<i class="fas fa-compress"></i> Exit Fullscreen' : '<i class="fas fa-expand"></i> Fullscreen';
    if (selectedImage) positionOverlay();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (freeMoving) { freeMoving = false; return; }
      if (selectedImage) { deselect(); return; }
      if (document.body.classList.contains('editor-fullscreen')) document.getElementById('btnFullscreen').click();
    }
    if ((e.key === 'Delete' || e.key === 'Backspace') && selectedImage && document.activeElement !== editorEl) {
      e.preventDefault();
      deleteSelected();
    }
  });

  function buildOverlay() {
    overlay = document.createElement('div');
    overlay.id = 'imgOverlay';

    ['nw', 'ne', 'sw', 'se'].forEach(function (pos) {
      var h = document.createElement('div');
      h.className = 'img-resize-handle';
      h.dataset.pos = pos;
      h.addEventListener('mousedown', startResize);
      overlay.appendChild(h);
    });

    toolbar = document.createElement('div');
    toolbar.className = 'img-mini-toolbar';

    toolbar.appendChild(mkBtn('Inline', function () {
      if (!selectedImage) return;
      selectedImage.classList.remove('is-free');
      selectedImage.style.position = ''; selectedImage.style.left = ''; selectedImage.style.top = '';
      selectedImage.style.float = 'none'; selectedImage.style.display = 'inline-block'; selectedImage.style.margin = '6px 8px 6px 0';
      selectedImage.setAttribute('draggable', 'true');
      positionOverlay();
    }));
    toolbar.appendChild(mkBtn('Left', function () {
      if (!selectedImage) return;
      selectedImage.classList.remove('is-free');
      selectedImage.style.position = ''; selectedImage.style.left = ''; selectedImage.style.top = '';
      selectedImage.style.float = 'left'; selectedImage.style.display = 'block'; selectedImage.style.margin = '6px 14px 6px 0';
      selectedImage.setAttribute('draggable', 'true');
      positionOverlay();
    }));
    toolbar.appendChild(mkBtn('Right', function () {
      if (!selectedImage) return;
      selectedImage.classList.remove('is-free');
      selectedImage.style.position = ''; selectedImage.style.left = ''; selectedImage.style.top = '';
      selectedImage.style.float = 'right'; selectedImage.style.display = 'block'; selectedImage.style.margin = '6px 0 6px 14px';
      selectedImage.setAttribute('draggable', 'true');
      positionOverlay();
    }));
    toolbar.appendChild(mkBtn('Center', function () {
      if (!selectedImage) return;
      selectedImage.classList.remove('is-free');
      selectedImage.style.position = ''; selectedImage.style.left = ''; selectedImage.style.top = '';
      selectedImage.style.float = 'none'; selectedImage.style.display = 'block'; selectedImage.style.margin = '10px auto';
      selectedImage.setAttribute('draggable', 'true');
      positionOverlay();
    }));
    toolbar.appendChild(mkBtn('Free Move', function () {
      if (!selectedImage) return;
      enableFreeMove(selectedImage);
    }));

    sizeLabel = document.createElement('span');
    sizeLabel.className = 'sizer';
    toolbar.appendChild(sizeLabel);

    var del = mkBtn('Remove', deleteSelected);
    del.className = 'danger';
    toolbar.appendChild(del);

    overlay.appendChild(toolbar);
    document.body.appendChild(overlay);
  }

  function mkBtn(label, onClick) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); onClick(); });
    return b;
  }

  function buildDropMarker() {
    dropMarker = document.createElement('div');
    dropMarker.id = 'dropMarker';
    document.body.appendChild(dropMarker);
  }

  function selectImage(img) {
    if (selectedImage === img) { positionOverlay(); return; }
    deselect();
    selectedImage = img;
    img.classList.add('is-selected');
    overlay.classList.add('visible');
    positionOverlay();
    track();
  }

  function deselect() {
    if (selectedImage) selectedImage.classList.remove('is-selected');
    selectedImage = null;
    overlay.classList.remove('visible');
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
  }

  function deleteSelected() {
    if (!selectedImage) return;
    var img = selectedImage;
    deselect();
    if (img.parentNode) img.parentNode.removeChild(img);
  }

  function track() {
    if (!selectedImage) return;
    if (!selectedImage.isConnected) { deselect(); return; }
    positionOverlay();
    rafId = requestAnimationFrame(track);
  }

  function positionOverlay() {
    if (!selectedImage) return;
    var r = selectedImage.getBoundingClientRect();
    overlay.style.left = r.left + 'px';
    overlay.style.top = r.top + 'px';
    overlay.style.width = r.width + 'px';
    overlay.style.height = r.height + 'px';
    toolbar.style.top = (r.top > 52) ? '-40px' : (r.height + 8) + 'px';
    sizeLabel.textContent = Math.round(r.width) + ' \u00d7 ' + Math.round(r.height);
  }

  function onDragStart(e) {
    var img = (e.target && e.target.tagName === 'IMG') ? e.target : null;
    if (!img) return;
    if (img.classList.contains('is-free')) { e.preventDefault(); return; }
    draggedImage = img;
    img.classList.add('is-dragging');
    overlay.classList.remove('visible');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', 'report-image-move'); } catch (err) {}
  }

  function onDragOver(e) {
    if (!draggedImage) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
  }

  function onDrop(e) {
    if (!draggedImage) return;
    e.preventDefault();
    e.stopPropagation();
    var img = draggedImage;
    var range = caretRange(e.clientX, e.clientY);

    if (range && !img.contains(range.startContainer) && range.startContainer !== img) {
      try { range.insertNode(img); range.setStartAfter(img); range.collapse(true); }
      catch (err) { editorEl.appendChild(img); }
    }
    endDrag();
    selectImage(img);
  }

  function endDrag() {
    if (draggedImage) draggedImage.classList.remove('is-dragging');
    draggedImage = null;
    dropMarker.style.display = 'none';
  }

  function caretRange(x, y) {
    if (document.caretRangeFromPoint) return document.caretRangeFromPoint(x, y);
    if (document.caretPositionFromPoint) {
      var pos = document.caretPositionFromPoint(x, y);
      if (!pos) return null;
      var r = document.createRange();
      r.setStart(pos.offsetNode, pos.offset);
      r.collapse(true);
      return r;
    }
    return null;
  }

  function startResize(e) {
    if (!selectedImage) return;
    e.preventDefault();
    e.stopPropagation();
    resizing = true;
    handlePos = e.currentTarget.dataset.pos;

    var r = selectedImage.getBoundingClientRect();
    startX = e.clientX; startY = e.clientY; startW = r.width; startH = r.height;
    document.body.style.userSelect = 'none';
  }

  function onResizeMove(e) {
    if (!resizing || !selectedImage) return;

    var dx = e.clientX - startX;
    var dy = e.clientY - startY;
    var ratio = startW / startH;
    var w;

    if (Math.abs(dy) > Math.abs(dx)) {
      var h = (handlePos.indexOf('s') !== -1) ? startH + dy : startH - dy;
      w = h * ratio;
    } else {
      w = (handlePos.indexOf('e') !== -1) ? startW + dx : startW - dx;
    }

    var max = Math.max(120, editorEl.clientWidth - 56);
    w = Math.max(60, Math.min(w, max));

    selectedImage.style.width = Math.round(w) + 'px';
    selectedImage.style.height = 'auto';

    if (selectedImage.classList.contains('is-free')) clampFreeImage(selectedImage);
    positionOverlay();
  }

  function endResize() {
    if (!resizing) return;
    resizing = false;
    document.body.style.userSelect = '';
  }

  function enableFreeMove(img) {
    var imgRect = img.getBoundingClientRect();
    var edRect = editorEl.getBoundingClientRect();

    var left = (imgRect.left - edRect.left) + editorEl.scrollLeft;
    var top  = (imgRect.top - edRect.top) + editorEl.scrollTop;

    img.style.float = 'none';
    img.style.display = 'block';
    img.style.margin = '0';
    img.style.position = 'absolute';
    img.style.left = Math.max(0, left) + 'px';
    img.style.top = Math.max(0, top) + 'px';
    img.classList.add('is-free');
    img.setAttribute('draggable', 'false');

    clampFreeImage(img);
    selectImage(img);
  }

  function onImageFreeMoveStart(e) {
    var img = e.target && e.target.tagName === 'IMG' ? e.target : null;
    if (!img || !img.classList.contains('is-free')) return;
    if (overlay && overlay.contains(e.target)) return;

    selectImage(img);
    freeMoving = true;

    var imgRect = img.getBoundingClientRect();
    imgMoveOffsetX = e.clientX - imgRect.left;
    imgMoveOffsetY = e.clientY - imgRect.top;

    img.classList.add('is-dragging');
    e.preventDefault();
  }

  function onImageFreeMove(e) {
    if (!freeMoving || !selectedImage || !selectedImage.classList.contains('is-free')) return;

    var edRect = editorEl.getBoundingClientRect();
    var x = e.clientX - edRect.left - imgMoveOffsetX + editorEl.scrollLeft;
    var y = e.clientY - edRect.top - imgMoveOffsetY + editorEl.scrollTop;

    var maxX = Math.max(0, editorEl.scrollWidth - selectedImage.offsetWidth);
    var maxY = Math.max(0, editorEl.scrollHeight - selectedImage.offsetHeight);

    x = Math.max(0, Math.min(x, maxX));
    y = Math.max(0, Math.min(y, maxY));

    selectedImage.style.left = Math.round(x) + 'px';
    selectedImage.style.top = Math.round(y) + 'px';
    positionOverlay();
  }

  function onImageFreeMoveEnd() {
    if (!freeMoving) return;
    freeMoving = false;
    if (selectedImage) selectedImage.classList.remove('is-dragging');
  }

  function clampFreeImage(img) {
    var x = parseFloat(img.style.left || '0');
    var y = parseFloat(img.style.top || '0');

    var maxX = Math.max(0, editorEl.scrollWidth - img.offsetWidth);
    var maxY = Math.max(0, editorEl.scrollHeight - img.offsetHeight);

    x = Math.max(0, Math.min(x, maxX));
    y = Math.max(0, Math.min(y, maxY));

    img.style.left = Math.round(x) + 'px';
    img.style.top = Math.round(y) + 'px';
  }

  /* ====================================================================
     SAVE REPORT (original AJAX save)
     ==================================================================== */
  document.getElementById('reportForm').addEventListener('submit', async function(e){
    e.preventDefault();
    var msg = document.getElementById('saveMsg');
    if (msg) msg.textContent = 'Saving...';

    deselect();
    endDrag();

    var reportHtml = quill.root.innerHTML.trim();
    var reportText = quill.getText().trim();

    if (!reportText) {
      if (msg) msg.textContent = 'Report text is required.';
      return;
    }

    try {
      var resp = await fetch('api.php?action=save_report', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          patientId: document.querySelector('input[name="patientId"]').value,
          studyId: document.getElementById('studyId').value,
          reportText: reportText,
          reportHtml: reportHtml
        })
      });

      var data = await resp.json();
      if (!resp.ok || !data.success) throw new Error(data.message || 'Save failed');

      if (msg) msg.textContent = 'Saved successfully.';
      setTimeout(function(){ location.reload(); }, 700);
    } catch (err) {
      if (msg) msg.textContent = err.message || 'Save failed.';
    }
  });
})();
</script>
</body>
</html>