<?php
require_once 'config.php';

 $studyId   = (int)($_GET['study'] ?? 0);
 $patientId = (int)($_GET['patient'] ?? 0);
 $imageType = trim((string)($_GET['type'] ?? ''));

 $study = null;
 $seriesList = [];
 $instances = [];
 $viewerMode = 'study';

try {
    if ($studyId > 0) {
        $stmt = db()->prepare("
            SELECT st.*, p.patient_name, p.patient_id AS pid, p.birth_date, p.sex
            FROM studies st
            JOIN patients p ON p.id = st.patient_id
            WHERE st.id = ?
            LIMIT 1
        ");
        $stmt->execute([$studyId]);
        $study = $stmt->fetch();
        if (!$study) { header('Location: study_list.php'); exit; }

        $seriesStmt = db()->prepare("
            SELECT se.*, st.id AS parent_study_id, st.study_date
            FROM series se
            JOIN studies st ON st.id = se.study_id
            WHERE st.id = ?
            ORDER BY COALESCE(se.series_number, 999999), se.id
        ");
        $seriesStmt->execute([$studyId]);
        $seriesList = $seriesStmt->fetchAll();
    } elseif ($patientId > 0 && $imageType !== '') {
        $viewerMode = 'patient_type';

        $stmt = db()->prepare("
            SELECT
                p.id AS patient_db_id,
                p.patient_name,
                p.patient_id AS pid,
                p.birth_date,
                p.sex,
                ? AS modality,
                MIN(st.study_date) AS study_date,
                CONCAT('Folder: ', ?) AS study_description,
                0 AS id
            FROM patients p
            JOIN studies st ON st.patient_id = p.id
            WHERE p.id = ? AND st.modality = ?
            GROUP BY p.id, p.patient_name, p.patient_id, p.birth_date, p.sex
            LIMIT 1
        ");
        $stmt->execute([$imageType, $imageType, $patientId, $imageType]);
        $study = $stmt->fetch();
        if (!$study) { header('Location: study_list.php'); exit; }

        $seriesStmt = db()->prepare("
            SELECT se.*, st.study_date, st.id AS parent_study_id
            FROM series se
            JOIN studies st ON st.id = se.study_id
            WHERE st.patient_id = ? AND st.modality = ?
            ORDER BY COALESCE(st.study_date, '9999-12-31'), COALESCE(se.series_number, 999999), se.id
        ");
        $seriesStmt->execute([$patientId, $imageType]);
        $seriesList = $seriesStmt->fetchAll();
    } else {
        header('Location: study_list.php'); exit;
    }

    foreach ($seriesList as $ser) {
        $iStmt = db()->prepare("
            SELECT *
            FROM instances
            WHERE series_id = ?
            ORDER BY COALESCE(instance_number, 999999), COALESCE(slice_location, 999999), id
        ");
        $iStmt->execute([(int)$ser['id']]);
        $instances[(int)$ser['id']] = $iStmt->fetchAll();
    }
} catch (Throwable $e) {
    header('Location: study_list.php'); exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title><?= APP_NAME ?> — Viewer</title>
<link rel="stylesheet" href="assets/css/style.css?v=viewer-4f-pacs-2">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="icon" type="image/png" href="assets/images/4f-logo.png">

<script src="https://unpkg.com/cornerstone-core@2.6.1/dist/cornerstone.js"></script>
<script src="https://unpkg.com/cornerstone-math@0.1.10/dist/cornerstoneMath.js"></script>
<script src="https://unpkg.com/hammerjs@2.0.8/hammer.min.js"></script>
<script src="https://unpkg.com/dicom-parser@1.8.21/dist/dicomParser.js"></script>
<script src="https://unpkg.com/cornerstone-tools@6.0.10/dist/cornerstoneTools.js"></script>
<script src="https://unpkg.com/cornerstone-wado-image-loader@4.13.2/dist/cornerstoneWADOImageLoader.bundle.min.js"></script>

<style>
html,body{height:100%;margin:0}
body.viewer-page{background:#1A2332;color:#e8edf5;overflow:hidden;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
.viewer-page{height:100dvh;display:flex;flex-direction:column}

/* ===== Splash (3D) ===== */
#pacs-splash-screen{
  position:fixed;inset:0;z-index:99999;overflow:hidden;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  background:radial-gradient(circle at 50% 45%, #0c1730 0%, #050912 45%, #020408 100%);
  perspective:1200px;
  transition:opacity .8s cubic-bezier(.25,1,.5,1),visibility .8s;
}
#pacs-splash-screen.hidden{opacity:0;visibility:hidden;pointer-events:none}

.pacs-3d-scene{position:absolute;inset:0;transform-style:preserve-3d}
.pacs-depth-grid{
  position:absolute;inset:-20%;
  background-image:
    linear-gradient(rgba(89,160,230,.08) 1px, transparent 1px),
    linear-gradient(90deg, rgba(89,160,230,.08) 1px, transparent 1px);
  background-size:34px 34px;
  transform-origin:center;
}
.grid-back{transform:translateZ(-300px) rotateX(70deg) scale(1.5);opacity:.25;filter:blur(1px);animation:gridDrift 16s linear infinite}
.grid-mid{transform:translateZ(-120px) rotateX(70deg) scale(1.2);opacity:.35;animation:gridDrift 9s linear infinite reverse}
.pacs-vignette{position:absolute;inset:0;background:radial-gradient(circle, transparent 35%, rgba(0,0,0,.55) 100%)}
.pacs-particles{
  position:absolute;inset:0;
  background:
    radial-gradient(circle at 20% 30%, rgba(89,160,230,.35) 0 2px, transparent 3px),
    radial-gradient(circle at 80% 60%, rgba(54,111,209,.35) 0 2px, transparent 3px),
    radial-gradient(circle at 60% 20%, rgba(89,160,230,.25) 0 1px, transparent 2px);
  animation:particlesFloat 8s ease-in-out infinite alternate;opacity:.6;
}

.pacs-top-logo-wrap{position:absolute;top:28px;left:50%;transform:translateX(-50%);z-index:20}
#pacs-top-logo{
  width: 132px;
  height: auto;
  opacity: 1 !important;
  mix-blend-mode: normal !important;
  filter:
    brightness(1.65)
    contrast(1.35)
    saturate(1.15)
    drop-shadow(0 0 2px rgba(255,255,255,.95))
    drop-shadow(0 0 10px rgba(120,180,255,.75))
    drop-shadow(0 0 24px rgba(54,111,209,.45));
}
.pacs-top-logo-wrap{
  top: 18px;
  z-index: 30;
  padding: 8px 16px;
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.02));
  box-shadow:
    inset 0 0 0 1px rgba(140,190,255,.18),
    0 8px 24px rgba(0,0,0,.35);
  backdrop-filter: blur(2px);
}
#pacs-splash-corner-title,
.pacs-corner{
  white-space: nowrap;
  line-height: 1;
  letter-spacing: .14em;
  font-size: 9px;
  max-width: 38vw;
  overflow: hidden;
  text-overflow: ellipsis;
}
#pacs-splash-corner-title{ top: 78px; left: 16px; }
.pacs-corner-tr{ top: 78px; right: 16px; text-align:right; }
.pacs-corner-bl{ bottom: 16px; left: 16px; }
.pacs-corner-br{ bottom: 16px; right: 16px; text-align:right; }

@media (max-width: 900px){
  .pacs-top-logo-wrap{
    top: 10px;
    padding: 6px 12px;
    border-radius: 10px;
  }
  #pacs-top-logo{
    width: 110px;
    filter:
      brightness(1.9)
      contrast(1.45)
      drop-shadow(0 0 2px rgba(255,255,255,1))
      drop-shadow(0 0 9px rgba(120,180,255,.8))
      drop-shadow(0 0 20px rgba(54,111,209,.5));
  }
  .pacs-corner-tr{ display:none; }
  #pacs-splash-corner-title{
    top: 62px;
    left: 12px;
    max-width: calc(100vw - 24px);
    font-size: 8px;
    letter-spacing: .12em;
  }
  .pacs-corner-bl, .pacs-corner-br{
    font-size: 8px;
    letter-spacing: .12em;
    bottom: 10px;
    max-width: 46vw;
  }
  .pacs-loader-center{
    margin-top: 34px;
    transform: scale(.83);
  }
  .pacs-loader-text{ margin-top: 6px; }
  .pacs-progress-wrap{ margin-top: 10px; width: 86vw; }
}

#pacs-roi-frame{position:absolute;width:300px;height:300px;z-index:8}
#pacs-roi-frame .roi{position:absolute;width:24px;height:24px}
#pacs-roi-frame .tl{top:0;left:0;border-top:1.5px solid rgba(89,160,230,.6);border-left:1.5px solid rgba(89,160,230,.6)}
#pacs-roi-frame .tr{top:0;right:0;border-top:1.5px solid rgba(89,160,230,.6);border-right:1.5px solid rgba(89,160,230,.6)}
#pacs-roi-frame .bl{bottom:0;left:0;border-bottom:1.5px solid rgba(89,160,230,.6);border-left:1.5px solid rgba(89,160,230,.6)}
#pacs-roi-frame .br{bottom:0;right:0;border-bottom:1.5px solid rgba(89,160,230,.6);border-right:1.5px solid rgba(89,160,230,.6)}

#pacs-splash-corner-title,.pacs-corner{
  position:absolute;font-size:10px;letter-spacing:.18em;color:rgba(133,190,242,.62);
  text-transform:uppercase;font-family:ui-monospace,monospace;z-index:9
}
#pacs-splash-corner-title{top:20px;left:24px}
.pacs-corner-tr{top:20px;right:24px;text-align:right}
.pacs-corner-bl{bottom:20px;left:24px}
.pacs-corner-br{bottom:20px;right:24px;text-align:right}

.pacs-loader-center{
  position:relative;width:240px;height:240px;z-index:12;
  transform-style:preserve-3d;animation:centerFloat 4.5s ease-in-out infinite
}
.ring{position:absolute;border-radius:50%;inset:0;pointer-events:none}
.ring-1{border:1px dashed rgba(89,160,230,.28);animation:spin 16s linear infinite}
.ring-2{inset:14px;border:1px dotted rgba(89,160,230,.5);animation:spinReverse 10s linear infinite}
.ring-3{inset:26px;border:1.5px solid rgba(89,160,230,.65);box-shadow:0 0 20px rgba(54,111,209,.28);animation:pulse 2.1s ease-in-out infinite}
.ring-4{inset:38px;border:1px solid rgba(133,190,242,.35);animation:spin 5s linear infinite}
.sonar{
  position:absolute;inset:16px;border-radius:50%;
  background:conic-gradient(from 0deg, rgba(89,160,230,.22), transparent 120deg, transparent 360deg);
  animation:spin 2.6s linear infinite
}
.scan-beam{
  position:absolute;inset:42px;border-radius:50%;
  background:linear-gradient(90deg, transparent 0%, rgba(133,190,242,.55) 50%, transparent 100%);
  filter:blur(4px);animation:beamSweep 2.2s ease-in-out infinite
}

.logo-wrap{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:3}
#pacs-splash-logo{
  width:98px;height:98px;object-fit:contain;
  filter:drop-shadow(0 0 2px rgba(255,255,255,.9)) drop-shadow(0 0 14px rgba(89,160,230,.7));
  animation:logoPulse 1.9s ease-in-out infinite
}
.logo-shine{display:none!important}

.pacs-pipeline-wrap{width:280px;height:60px;margin-top:18px;z-index:10;opacity:.95;filter:drop-shadow(0 0 8px rgba(54,111,209,.2))}
.pipeline-beacon{animation:beaconPulse 1.8s ease-in-out infinite}
.data-pulse{will-change:transform}
.pulse-a{animation:dataFlow 1.4s linear infinite}
.pulse-b{animation:dataFlow 1.8s linear infinite .4s}
.pulse-c{animation:dataFlow 1.1s linear infinite .9s}
.pulse-d{animation:dataFlow 1.6s linear infinite 1.2s}

.pacs-loader-text{margin-top:16px;text-align:center;z-index:12}
#pacs-splash-title{display:block;color:#dbe9f8;font-weight:700;letter-spacing:.08em}
#pacs-splash-status{display:block;margin-top:6px;color:#8fb9e4;font-size:12px}

.pacs-progress-wrap{width:min(560px,86vw);margin-top:16px;z-index:12}
.pacs-progress-meta{display:flex;justify-content:space-between;margin-bottom:6px;color:#8db3d9;font-size:11px;letter-spacing:.08em}
.pacs-progress-bar{position:relative;height:12px;border-radius:999px;overflow:hidden;border:1px solid rgba(89,160,230,.45);background:rgba(10,20,36,.9)}
#pacs-splash-progress{height:100%;width:0%;background:linear-gradient(90deg,#2f5fb3,#4e86d8,#82c4ff);box-shadow:0 0 16px rgba(89,160,230,.6);transition:width .08s linear}
.pacs-progress-shine{position:absolute;top:0;left:-30%;width:30%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent);animation:shine 1.5s linear infinite}

/* ===== Viewer ===== */
.viewer-header{height:64px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 18px;background:linear-gradient(180deg,#121a26,#0d1420);border-bottom:1px solid rgba(54,111,209,.12);box-shadow:0 8px 24px rgba(0,0,0,.28);position:relative;z-index:30}
.vh-left,.vh-right{display:flex;align-items:center;gap:12px}
.vh-back{width:40px;height:40px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#dbe3e8;border:1px solid rgba(54,111,209,.14);background:rgba(255,255,255,.02)}
.vh-patient{display:flex;flex-direction:column;gap:2px}
.vh-name{font-size:15px;font-weight:700;color:#fff}
.vh-meta{color:#72a3cf;font-size:12px}
.vh-tools{display:flex;align-items:center;gap:6px;flex:1;justify-content:center;min-width:0;overflow-x:auto;white-space:nowrap}
.tool-group{display:flex;align-items:center;gap:6px}
.tool-btn{width:40px;height:40px;border-radius:12px;border:1px solid rgba(54,111,209,.14);background:rgba(255,255,255,.02);color:#dbe3e8;cursor:pointer;transition:.2s ease}
.tool-btn:hover{background:rgba(54,111,209,.08);border-color:rgba(54,111,209,.35);box-shadow:0 0 0 1px rgba(54,111,209,.08),0 0 16px rgba(54,111,209,.08)}
.tool-btn.active{background:linear-gradient(180deg,rgba(54,111,209,.18),rgba(33,82,182,.1));border-color:rgba(54,111,209,.45);color:#366fd1}
.tool-sep{width:1px;height:28px;background:rgba(255,255,255,.08);margin:0 6px}
.tool-slider{accent-color:#366fd1}
.tool-fps{font-size:12px;color:#8eb8df}
.layout-icon{font-size:12px;font-weight:700}
.image-counter{min-width:84px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(54,111,209,.14);background:rgba(255,255,255,.02);color:#cfdce9;font-weight:700}

.viewer-shell{opacity:0;transition:opacity .25s ease;flex:1;display:flex;flex-direction:column;min-height:0}
.viewer-shell.ready{opacity:1}
.viewer-body{flex:1;min-height:0;display:flex;position:relative}
.series-panel,.info-panel{width:290px;max-width:80vw;background:linear-gradient(180deg,#0d1420,#0a1119)}
.series-panel{border-right:1px solid rgba(54,111,209,.08)}
.info-panel{border-left:1px solid rgba(54,111,209,.08)}
.panel-header{font-size:11px;letter-spacing:.18em;color:#72a3cf;text-transform:uppercase;padding:14px 16px;background:rgba(255,255,255,.02);border-bottom:1px solid rgba(54,111,209,.08)}
.series-list,.meas-list{padding:8px;overflow:auto;height:calc(100% - 48px)}
.meas-empty{color:#7e93a5;padding:14px}

.series-item{display:flex;gap:12px;padding:12px;margin:10px;border-radius:14px;border:1px solid rgba(54,111,209,.08);background:rgba(255,255,255,.02);cursor:pointer;transition:.2s ease}
.series-item:hover{background:rgba(54,111,209,.05);border-color:rgba(54,111,209,.25)}
.series-item.active{background:linear-gradient(180deg,rgba(54,111,209,.12),rgba(54,111,209,.05));border-color:rgba(54,111,209,.35);box-shadow:0 0 20px rgba(54,111,209,.08)}
.series-thumb-wrap{position:relative}
.series-thumb-canvas{width:80px;height:80px;background:#000;border-radius:12px;border:1px solid rgba(54,111,209,.12);box-shadow:inset 0 0 0 1px rgba(255,255,255,.02)}
.series-thumb-count{position:absolute;right:6px;bottom:6px;font-size:10px;padding:2px 6px;border-radius:999px;background:rgba(2,5,6,.7);color:#7fb8e8;border:1px solid rgba(54,111,209,.18)}
.series-info{display:flex;flex-direction:column;gap:4px;min-width:0}
.series-num{color:#fff;font-weight:700;font-size:13px}
.series-desc{color:#c6d4df;font-size:12px;word-break:break-word}
.series-mod{color:#67a0cd;font-size:11px;letter-spacing:.08em;text-transform:uppercase}

.viewport-area{flex:1;min-width:0;min-height:0;display:flex;flex-direction:column;background:#05090f}
.viewports-grid{flex:1;min-height:0;display:grid;gap:12px;padding:12px;background:linear-gradient(rgba(54,111,209,.02) 1px, transparent 1px),linear-gradient(90deg, rgba(54,111,209,.02) 1px, transparent 1px),#05090f;background-size:24px 24px}
.viewports-grid[data-layout="1x1"]{grid-template-columns:1fr;grid-template-rows:1fr}
.viewports-grid[data-layout="1x2"]{grid-template-columns:1fr 1fr;grid-template-rows:1fr}
.viewports-grid[data-layout="2x2"]{grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr}
.viewports-grid[data-layout="1x3"]{grid-template-columns:1fr 1fr 1fr;grid-template-rows:1fr}
.viewport-cell{position:relative;background:#000;border-radius:18px;overflow:hidden;border:1px solid rgba(54,111,209,.12);box-shadow:0 8px 30px rgba(0,0,0,.35);min-height:180px}
.viewport-cell.active-cell{border-color:rgba(54,111,209,.42);box-shadow:0 0 0 1px rgba(54,111,209,.1),0 0 24px rgba(54,111,209,.1)}
.cornerstone-element{width:100%;height:100%;background:#000!important;touch-action:none}
.vp-loading{position:absolute;inset:0;display:none;align-items:center;justify-content:center;background:rgba(2,5,6,.45);color:#98c5f0;font-size:13px;letter-spacing:.14em;text-transform:uppercase;z-index:15;backdrop-filter:blur(2px)}

.scroll-bar-track{padding:10px 14px 14px;background:#081018}
.image-scroller{width:100%;accent-color:#366fd1}

.dicom-overlay{
  position:absolute;z-index:20;color:#e8edf5;
  font:12px/1.35 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
  text-shadow:0 1px 2px rgba(0,0,0,.9);pointer-events:none;white-space:pre
}
.dicom-overlay.top-left{top:10px;left:10px;text-align:left}
.dicom-overlay.top-right{top:10px;right:10px;text-align:right}

/* Series edge toggle */
.series-edge-toggle{
  position:absolute;left:290px;top:50%;transform:translate(-50%,-50%);
  z-index:35;width:80px;height:30px;border-radius:14px;border:1px solid rgba(54,111,209,.25);
  background:linear-gradient(180deg,#0f1826,#0b1320);color:#7fb8e8;display:flex;align-items:center;justify-content:center;
  cursor:pointer;box-shadow:0 8px 20px rgba(0,0,0,.35);transition:left .22s ease,background .2s ease,color .2s ease,transform .2s ease
}
.series-edge-toggle:hover{background:linear-gradient(180deg,#132237,#0f1b2c);color:#a5c5f5}
.series-edge-toggle i{font-size:12px;transition:transform .22s ease}
body.series-collapsed .series-panel{width:0!important;min-width:0!important;max-width:0!important;flex:0 0 0!important;overflow:hidden!important;border:0!important;padding:0!important;margin:0!important}
body.series-collapsed .series-panel .panel-header,
body.series-collapsed .series-panel .series-list{display:none!important}
body.series-collapsed .series-edge-toggle{left:8px!important}

/* Mobile */
@media (max-width:900px){
  html,body{height:100dvh!important;min-height:100dvh!important;overflow:hidden!important;background:#0b1220}
  body.viewer-page,.viewer-page{height:100dvh!important;min-height:100dvh!important;overflow:hidden!important;background:#0b1220}
  .viewer-header{height:auto;min-height:74px;padding:8px 10px;background:#0f1726;border-bottom:1px solid rgba(255,255,255,.08);box-shadow:none;display:flex;flex-wrap:wrap;gap:6px;position:sticky;top:0;z-index:40}
  .vh-left{width:auto!important;gap:8px;min-width:0}
  .vh-back{width:32px;height:32px;border-radius:10px}
  .vh-right{margin-left:auto;order:2}
  .image-counter{min-width:64px;height:30px;border-radius:9px;font-size:12px}
  .vh-tools{order:3;width:100%;justify-content:flex-start;gap:4px;overflow-x:auto;white-space:nowrap;padding-bottom:2px;scrollbar-width:none}
  .vh-tools::-webkit-scrollbar{display:none}
  .tool-group{display:inline-flex;gap:4px;flex:0 0 auto}
  .tool-btn{width:34px;height:34px;border-radius:10px}
  .tool-sep{height:20px;margin:0 3px}
  #cineSpeed{width:82px}.tool-fps{font-size:10px}.layout-icon{font-size:11px}

  .viewer-body{flex:1 1 auto;min-height:0;height:calc(100dvh - 74px);display:flex;flex-direction:column;overflow:hidden!important}
  .info-panel{display:none!important}
  .viewport-area{order:1;flex:1 1 auto!important;min-height:0!important;display:flex!important;flex-direction:row!important;background:#0b1220;overflow:hidden!important}
  .viewports-grid{flex:1 1 auto!important;min-width:0!important;min-height:0!important;padding:4px;gap:4px;background:#0b1220}
  .viewport-cell{min-height:0!important;border-radius:10px;border:1px solid rgba(54,111,209,.28);box-shadow:none}
  .cornerstone-element{touch-action:none!important;-webkit-user-select:none!important;user-select:none!important}
  .viewer-header,.vh-tools,.tool-group,.tool-btn,.tool-btn *{touch-action:manipulation!important;pointer-events:auto!important}

  .dicom-overlay{z-index:50;font:10px/1.2 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace}
  .dicom-overlay.top-left{top:6px;left:6px}.dicom-overlay.top-right{top:6px;right:6px}
  .vp-loading{z-index:60}

  .scroll-bar-track{order:2!important;flex:0 0 50px!important;width:50px!important;height:100%!important;padding:0;background:#1f2b3d;border-left:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;overflow:hidden}
  .image-scroller{-webkit-appearance:slider-vertical!important;appearance:slider-vertical!important;writing-mode:vertical-lr!important;direction:ltr!important;width:8px!important;height:100%!important;min-height:200px!important;accent-color:#366fd1}

  .series-panel{order:3;display:block!important;width:100%;max-width:100%;flex:0 0 96px!important;height:96px!important;border-right:0;border-top:1px solid rgba(255,255,255,.08);background:#101a2c;overflow:hidden}
  .panel-header{font-size:10px;letter-spacing:.12em;color:#366fd1;padding:5px 10px;border-bottom:1px solid rgba(255,255,255,.08)}
  .series-list{height:calc(100% - 28px);padding:6px 8px;display:flex!important;flex-direction:row!important;gap:8px;overflow-x:auto!important;overflow-y:hidden!important;align-items:stretch}
  .series-item{margin:0!important;width:100px!important;min-width:100px!important;max-width:100px!important;flex:0 0 100px!important;display:flex;flex-direction:column;gap:4px;padding:4px;border-radius:10px;background:#1d2a3b;border:1px solid rgba(255,255,255,.12)}
  .series-item.active{border-color:#366fd1;box-shadow:0 0 0 1px rgba(54,111,209,.22);background:linear-gradient(180deg, rgba(54,111,209,.12), rgba(54,111,209,.04))}
  .series-thumb-canvas{width:100%!important;height:52px!important;display:block;background:#000;border-radius:7px}
  .series-thumb-count{right:3px;bottom:3px;font-size:9px;padding:1px 5px}
  .series-num{font-size:11px;color:#3980d3}.series-desc{font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.series-mod{display:none}

  .viewports-grid[data-layout="1x1"]{grid-template-columns:1fr!important;grid-template-rows:1fr!important}
  .viewports-grid[data-layout="1x2"]{grid-template-columns:1fr!important;grid-template-rows:repeat(2,minmax(0,1fr))!important}
  .viewports-grid[data-layout="1x3"]{grid-template-columns:1fr!important;grid-template-rows:repeat(3,minmax(0,1fr))!important}
  .viewports-grid[data-layout="2x2"]{grid-template-columns:1fr!important;grid-template-rows:repeat(4,minmax(0,1fr))!important}

  .series-edge-toggle{left:50%!important;top:auto!important;bottom:102px!important;transform:translateX(-50%)!important;width:44px;height:28px;border-radius:10px;z-index:70}
  .series-edge-toggle i{transform:rotate(90deg)}
  body.series-collapsed .series-edge-toggle{left:50%!important;bottom:102px!important}

  .pacs-loader-center{transform:scale(.8)}
}

/* Keyframes */
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes spinReverse{to{transform:rotate(-360deg)}}
@keyframes pulse{0%,100%{opacity:.5;transform:scale(1)}50%{opacity:1;transform:scale(1.03)}}
@keyframes beamSweep{0%{transform:rotate(0) scale(.9)}100%{transform:rotate(360deg) scale(1.08)}}
@keyframes logoPulse{0%,100%{transform:scale(.98)}50%{transform:scale(1.04)}}
@keyframes centerFloat{0%,100%{transform:translateY(0) rotateX(2deg)}50%{transform:translateY(-8px) rotateX(-2deg)}}
@keyframes topLogoFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
@keyframes particlesFloat{0%{transform:translateY(0)}100%{transform:translateY(-16px)}}
@keyframes gridDrift{0%{background-position:0 0,0 0}100%{background-position:0 220px,220px 0}}
@keyframes shine{0%{left:-30%}100%{left:130%}}
@keyframes beaconPulse{0%,100%{r:2;opacity:.4;fill:#366fd1}50%{r:5;opacity:1;fill:#59a0e6}}
@keyframes dataFlow{0%{transform:translateX(0);opacity:0}10%{opacity:.9}85%{opacity:.9}100%{transform:translateX(310px);opacity:0}}
</style>
</head>
<body class="viewer-page">

<div id="pacs-splash-screen">
  <!-- 3D scene layers -->
  <div class="pacs-3d-scene">
    <div class="pacs-depth-grid grid-back"></div>
    <div class="pacs-depth-grid grid-mid"></div>
    <div class="pacs-vignette"></div>
    <div class="pacs-particles"></div>
  </div>

  <!-- Top logo -->
  <div class="pacs-top-logo-wrap">
    <img id="pacs-top-logo" src="assets/images/4f-logo.png" alt="4F Logo">
  </div>

  <!-- ROI corners -->
  <div id="pacs-roi-frame">
    <div class="roi tl"></div><div class="roi tr"></div>
    <div class="roi bl"></div><div class="roi br"></div>
  </div>

  <!-- Corner labels -->
  <div id="pacs-splash-corner-title">CORE ENGINE // STATUS: INITIALIZING</div>
  <div class="pacs-corner pacs-corner-tr">DICOM 3.0 COMPLIANT // TLS SECURE</div>
  <div class="pacs-corner pacs-corner-bl">ACQ PIPELINE v2.4.1</div>
  <div class="pacs-corner pacs-corner-br">© 2026 PACS IMAGING SYSTEMS</div>

  <!-- Main center -->
  <div class="pacs-loader-center">
    <div class="ring ring-1"></div>
    <div class="ring ring-2"></div>
    <div class="ring ring-3"></div>
    <div class="ring ring-4"></div>
    <div class="sonar"></div>
    <div class="scan-beam"></div>

    <div class="logo-wrap">
      <img id="pacs-splash-logo" src="assets/images/logo.png" alt="Logo">
    </div>
  </div>

  <!-- Pipeline SVG -->
  <div class="pacs-pipeline-wrap">
    <svg width="280" height="60" viewBox="0 0 280 60">
      <circle cx="140" cy="30" r="3" fill="#366fd1" class="pipeline-beacon"/>
      <line x1="0" y1="12" x2="280" y2="12" stroke="rgba(54,111,209,0.08)" stroke-width="2"/>
      <rect class="data-pulse pulse-a" x="-30" y="9" width="30" height="6" rx="3" fill="#59a0e6"/>
      <line x1="0" y1="24" x2="280" y2="24" stroke="rgba(54,111,209,0.08)" stroke-width="2"/>
      <rect class="data-pulse pulse-b" x="-20" y="21" width="20" height="6" rx="3" fill="#366fd1"/>
      <line x1="0" y1="36" x2="280" y2="36" stroke="rgba(54,111,209,0.08)" stroke-width="2"/>
      <rect class="data-pulse pulse-c" x="-40" y="33" width="40" height="6" rx="3" fill="#59a0e6"/>
      <line x1="0" y1="48" x2="280" y2="48" stroke="rgba(54,111,209,0.08)" stroke-width="2"/>
      <rect class="data-pulse pulse-d" x="-25" y="45" width="25" height="6" rx="3" fill="#366fd1"/>
    </svg>
  </div>

  <div class="pacs-loader-text">
    <span id="pacs-splash-title">PACS IMAGING SYSTEM</span>
    <span id="pacs-splash-status">[SYS_BOOT] ESTABLISHING DATA PIPELINE...</span>
  </div>

  <div class="pacs-progress-wrap">
    <div class="pacs-progress-meta">
      <span>NETWORK IO STATUS:</span>
      <span id="pacs-splash-percentage">0%</span>
    </div>
    <div class="pacs-progress-bar">
      <div id="pacs-splash-progress"></div>
      <div class="pacs-progress-shine"></div>
    </div>
  </div>
</div>

<div id="viewerShell" class="viewer-shell">
  <header class="viewer-header">
    <div class="vh-left">
      <a href="<?= $viewerMode === 'patient_type' ? 'patient.php?patient='.(int)$patientId : 'study_list.php' ?>" class="vh-back"><i class="fas fa-arrow-left"></i></a>
      <div class="vh-patient">
        <span class="vh-name"><?= htmlspecialchars($study['patient_name'] ?? '—') ?></span>
      </div>
    </div>

    <div class="vh-tools" id="toolbarMain">
      <div class="tool-group">
        <button class="tool-btn active" data-tool="Pan"><i class="fas fa-hand-paper"></i></button>
        <button class="tool-btn" data-tool="Zoom"><i class="fas fa-search-plus"></i></button>
        <button class="tool-btn" data-tool="Wwwc"><i class="fas fa-adjust"></i></button>
        <button class="tool-btn" data-tool="StackScroll"><i class="fas fa-layer-group"></i></button>
      </div>
      <div class="tool-sep"></div>
      <div class="tool-group">
        <button class="tool-btn" data-tool="Length"><i class="fas fa-ruler"></i></button>
        <button class="tool-btn" data-tool="Angle"><i class="fas fa-drafting-compass"></i></button>
        <button class="tool-btn" data-tool="EllipticalRoi"><i class="far fa-circle"></i></button>
        <button class="tool-btn" data-tool="RectangleRoi"><i class="far fa-square"></i></button>
        <button class="tool-btn" data-tool="FreehandRoi"><i class="fas fa-draw-polygon"></i></button>
        <button class="tool-btn" data-tool="Probe"><i class="fas fa-crosshairs"></i></button>
      </div>
      <div class="tool-sep"></div>
      <div class="tool-group">
        <button class="tool-btn" id="btnInvert"><i class="fas fa-circle-half-stroke"></i></button>
        <button class="tool-btn" id="btnRotateL"><i class="fas fa-rotate-left"></i></button>
        <button class="tool-btn" id="btnRotateR"><i class="fas fa-rotate-right"></i></button>
        <button class="tool-btn" id="btnFlipH"><i class="fas fa-arrows-left-right"></i></button>
        <button class="tool-btn" id="btnFlipV"><i class="fas fa-arrows-up-down"></i></button>
        <button class="tool-btn" id="btnReset"><i class="fas fa-undo"></i></button>
      </div>
      <div class="tool-sep"></div>
      <div class="tool-group">
        <button class="tool-btn" id="btn1x1"><i class="fas fa-square"></i></button>
        <button class="tool-btn" id="btn1x2"><span class="layout-icon">1×2</span></button>
        <button class="tool-btn" id="btn2x2"><span class="layout-icon">2×2</span></button>
        <button class="tool-btn" id="btn1x3"><span class="layout-icon">1×3</span></button>
      </div>
      <div class="tool-sep"></div>
      <div class="tool-group">
        <button class="tool-btn" id="btnCinePlay"><i class="fas fa-play"></i></button>
        <input type="range" id="cineSpeed" min="1" max="60" value="15" class="tool-slider">
        <span class="tool-fps" id="cineFpsLabel">15 fps</span>
      </div>
    </div>

    <div class="vh-right"><div class="image-counter"><span id="imgCurrent">1</span> / <span id="imgTotal">0</span></div></div>
  </header>

  <div class="viewer-body">
      <button id="seriesEdgeToggle" class="series-edge-toggle" type="button" aria-label="Toggle series panel" title="Toggle series panel">
          <i class="fas fa-chevron-left"></i>
        </button>
    <aside class="series-panel"><div class="panel-header"><span>Series</span></div><div class="series-list" id="seriesList"></div></aside>
    <div class="viewport-area">
      <div class="viewports-grid" id="viewportsGrid" data-layout="1x1">
        <div class="viewport-cell active-cell" id="vp0">
          <div class="cornerstone-element" id="csElement0"></div>
          <div class="dicom-overlay top-left" id="ovTL0"></div>
          <div class="dicom-overlay top-right" id="ovTR0"></div>
          <div class="vp-loading" id="vpLoading0"><span>Loading…</span></div>
        </div>
        <div class="viewport-cell" id="vp1" style="display:none">
            <div class="cornerstone-element" id="csElement1"></div>
            <div class="dicom-overlay top-left" id="ovTL1"></div>
            <div class="dicom-overlay top-right" id="ovTR1"></div>
            <div class="vp-loading" id="vpLoading1"><span>Loading…</span></div>
        </div>
        <div class="viewport-cell" id="vp2" style="display:none">
            <div class="cornerstone-element" id="csElement2"></div>
            <div class="dicom-overlay top-left" id="ovTL2"></div>
            <div class="dicom-overlay top-right" id="ovTR2"></div>
            <div class="vp-loading" id="vpLoading2"><span>Loading…</span></div>
        </div>
        <div class="viewport-cell" id="vp3" style="display:none">
            <div class="cornerstone-element" id="csElement3"></div>
            <div class="dicom-overlay top-left" id="ovTL3"></div>
            <div class="dicom-overlay top-right" id="ovTR3"></div>
            <div class="vp-loading" id="vpLoading3"><span>Loading…</span></div>
        </div>
      </div>
      <div class="scroll-bar-track"><input type="range" id="imageScroller" class="image-scroller" min="0" value="0"></div>
    </div>
    <aside class="info-panel"><div class="panel-header"><span>Measurements</span></div><div class="meas-list" id="measurementList"><div class="meas-empty">No measurements yet.</div></div></aside>
  </div>
</div>

<script>
window.DICOM_STUDY = <?= json_encode([
  'patient_name' => $study['patient_name'] ?? '—',
  'series' => array_values(array_map(function($ser) use ($instances){
      $sid = (int)$ser['id'];
      return [
          'id' => $sid,
          'number' => $ser['series_number'] ?? null,
          'description' => $ser['series_description'] ?? 'Series',
          'modality' => $ser['modality'] ?? '',
          'instances' => array_values(array_map(function($img){ return ['id' => (int)$img['id']]; }, $instances[$sid] ?? []))
      ];
  }, $seriesList))
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
(function () {
  const pctEl = document.getElementById('pacs-splash-percentage');
  const barEl = document.getElementById('pacs-splash-progress');
  const statusEl = document.getElementById('pacs-splash-status');
  const splash = document.getElementById('pacs-splash-screen');
  const viewerShell = document.getElementById('viewerShell');

  const steps = [
    { at: 8,  text: '[SYS_BOOT] INITIALIZING CORE MODULES...' },
    { at: 22, text: '[I/O] CHECKING NETWORK THROUGHPUT...' },
    { at: 40, text: '[DICOM] INDEXING STUDY METADATA...' },
    { at: 62, text: '[GPU] PREPARING VIEWPORT PIPELINE...' },
    { at: 82, text: '[CACHE] OPTIMIZING IMAGE STACK...' },
    { at: 96, text: '[FINALIZE] STARTING VIEWER...' }
  ];

  let p = 0;
  const durationMs = 3200;
  const fps = 60;
  const inc = 100 / (durationMs / (1000 / fps));

  const timer = setInterval(() => {
    p = Math.min(100, p + inc);
    const val = Math.floor(p);

    barEl.style.width = val + '%';
    pctEl.textContent = val + '%';

    for (let i = steps.length - 1; i >= 0; i--) {
      if (val >= steps[i].at) { statusEl.textContent = steps[i].text; break; }
    }

    if (val >= 100) {
      clearInterval(timer);
      statusEl.textContent = '[READY] VIEWER ONLINE';
      setTimeout(() => {
        splash.classList.add('hidden');
        viewerShell.classList.add('ready');
      }, 450);
    }
  }, 1000 / fps);
})();
</script>

<script src="assets/js/viewer.js?v=4f-pacs-2"></script>
</body>
</html>