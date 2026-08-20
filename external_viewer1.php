<?php
declare(strict_types=1);
require_once 'config.php';

 $share = trim((string)($_GET['share'] ?? ''));
if ($share === '') { http_response_code(400); exit('Invalid share link'); }

 $patientId = parsePatientShareToken($share);
if (!$patientId || $patientId <= 0) { http_response_code(403); exit('Invalid or expired share link'); }

 $study = null;
 $seriesList = [];
 $instances = [];
 $viewerMode = 'external_share';
 $reports = [];
 $patientAge = '—';

try {
    $pStmt = db()->prepare("
        SELECT id, patient_name, patient_id AS pid, birth_date, sex
        FROM patients
        WHERE id = ?
        LIMIT 1
    ");
    $pStmt->execute([$patientId]);
    $p = $pStmt->fetch();
    if (!$p) { http_response_code(404); exit('Patient not found'); }

    $study = [
        'patient_name' => $p['patient_name'] ?? '—',
        'pid'          => $p['pid'] ?? '—',
        'birth_date'   => $p['birth_date'] ?? null,
        'sex'          => $p['sex'] ?? null,
        'study_description' => 'Shared Patient Viewer'
    ];

    if (!empty($study['birth_date']) && $study['birth_date'] !== '0000-00-00') {
        $dob = new DateTime((string)$study['birth_date']);
        $now = new DateTime();
        $patientAge = $dob->diff($now)->y . ' Years';
    }

    $seriesStmt = db()->prepare("
        SELECT se.*, st.study_date, st.id AS parent_study_id, st.modality
        FROM series se
        JOIN studies st ON st.id = se.study_id
        WHERE st.patient_id = ?
        ORDER BY COALESCE(st.study_date, '9999-12-31'), COALESCE(se.series_number, 999999), se.id
    ");
    $seriesStmt->execute([$patientId]);
    $seriesList = $seriesStmt->fetchAll();

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

    $rpt = db()->prepare("
        SELECT
          pr.id,
          pr.report_text,
          pr.study_id,
          pr.created_at,
          pr.updated_at,
          r.full_name AS reporter_name
        FROM patient_reports pr
        INNER JOIN reporters r ON r.id = pr.reporter_id
        WHERE pr.patient_id = ?
        ORDER BY pr.updated_at DESC, pr.id DESC
    ");
    $rpt->execute([$patientId]);
    $reports = $rpt->fetchAll();

} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to load shared viewer');
}

// Generate absolute URL for sharing
 $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
 $host = $_SERVER['HTTP_HOST'] ?? '4f-medical.com';
 $currentUrl = $protocol . $host . $_SERVER['REQUEST_URI'];
 $logoUrl = $protocol . $host . dirname($_SERVER['PHP_SELF']) . '/assets/images/logoV.png';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="color-scheme" content="light dark">

<script>
    (function(){
      var m = localStorage.getItem('dicom-mode') || 'dark';
      document.documentElement.setAttribute('data-mode', m);
      document.documentElement.style.colorScheme = (m === 'light' ? 'light' : 'dark');
      document.addEventListener('DOMContentLoaded', function(){
        document.body.setAttribute('data-mode', m);
      });
    })();
</script>

<!-- Updated Title and Open Graph Tags for WhatsApp Preview -->
<title>Ravco Polyclinic</title>
<meta property="og:type" content="website">
<meta property="og:url" content="<?= htmlspecialchars($currentUrl) ?>">
<meta property="og:title" content="Ravco Polyclinic">
<meta property="og:description" content="Patient Name: <?= htmlspecialchars($study['patient_name']) ?> | Patient ID: <?= htmlspecialchars($study['pid']) ?>. Welcome to Ravco Polyclinic. We wish you continued health and wellness.">
<meta property="og:image" content="<?= htmlspecialchars($logoUrl) ?>">
<meta property="og:site_name" content="Ravco Polyclinic">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Ravco Polyclinic">
<meta name="twitter:description" content="Patient Name: <?= htmlspecialchars($study['patient_name']) ?> | Patient ID: <?= htmlspecialchars($study['pid']) ?>. Welcome to Ravco Polyclinic.">
<meta name="twitter:image" content="<?= htmlspecialchars($logoUrl) ?>">

<link rel="stylesheet" href="assets/css/style.css?v=viewer-4f-pacs-38">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="icon" type="image/png" href="assets/images/4f-logo.png">

<script src="https://unpkg.com/cornerstone-core@2.6.1/dist/cornerstone.js"></script>
<script src="https://unpkg.com/cornerstone-math@0.1.10/dist/cornerstoneMath.js"></script>
<script src="https://unpkg.com/hammerjs@2.0.8/hammer.min.js"></script>
<script src="https://unpkg.com/dicom-parser@1.8.21/dist/dicomParser.js"></script>
<script src="https://unpkg.com/cornerstone-tools@6.0.10/dist/cornerstoneTools.js"></script>
<script src="https://unpkg.com/cornerstone-wado-image-loader@4.13.2/dist/cornerstoneWADOImageLoader.bundle.min.js"></script>

<style>
/* ===== Theme Variables & Animations ===== */
:root {
  --c-bg-main: #1A2332;
  --c-bg-header: #121a26;
  --c-bg-panel: #0d1420;
  --c-bg-viewer: #05090f;
  --c-text: #e8edf5;
  --c-accent: #366fd1;
  --c-accent-rgb: 54, 111, 209;
  --c-border: rgba(54, 111, 209, 0.12);
  --c-sync: #3CD08F;
  --c-sync-rgb: 60, 208, 143;
  --t-speed: 0.4s; 
}

body[data-theme="green"] {
  --c-bg-main: #1a2a22; --c-bg-header: #11211a; --c-bg-panel: #0d1814; --c-bg-viewer: #05100b;
  --c-text: #e5f5ee; --c-accent: #3CD08F; --c-accent-rgb: 60, 208, 143; --c-border: rgba(60, 208, 143, 0.15);
}
body[data-theme="purple"] {
  --c-bg-main: #241a32; --c-bg-header: #181024; --c-bg-panel: #120c1d; --c-bg-viewer: #0a0510;
  --c-text: #ece5f5; --c-accent: #9d4edd; --c-accent-rgb: 157, 78, 221; --c-border: rgba(157, 78, 221, 0.18);
}
body[data-theme="crimson"] {
  --c-bg-main: #321a1f; --c-bg-header: #241014; --c-bg-panel: #1d0c10; --c-bg-viewer: #100507;
  --c-text: #f5e5e8; --c-accent: #ff4d6d; --c-accent-rgb: 255, 77, 109; --c-border: rgba(255, 77, 109, 0.18);
}

html,body{height:100%;margin:0}
body.viewer-page{
  background:var(--c-bg-main); color:var(--c-text); overflow:hidden;
  font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  transition: background var(--t-speed) ease, color var(--t-speed) ease;
}
.viewer-page{height:100dvh;display:flex;flex-direction:column}

/* Modal Overlay Styles */
.report-modal-overlay {position: fixed; inset: 0; z-index: 99999;background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(6px);display: none;align-items: center; justify-content: center;opacity: 0; visibility: hidden;pointer-events: none;transition: opacity 0.3s ease, visibility 0.3s ease;}
.report-modal-overlay.active {display: flex;opacity: 1;visibility: visible;pointer-events: auto;}
.report-modal-box {background: var(--c-bg-header); padding: 20px; border-radius: 8px;width: 95%; max-width: 900px; max-height: 95vh;display: flex; flex-direction:column;box-shadow: 0 20px 60px rgba(0,0,0,0.5);transform: translateY(20px) scale(0.98); transition: transform 0.4s cubic-bezier(0.18, 0.89, 0.32, 1.28);}
.report-modal-overlay.active .report-modal-box { transform: translateY(0) scale(1); }
.modal-top-actions {display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 10px;}
.modal-action-btn {background: rgba(255,255,255,0.1); border: 1px solid var(--c-border);color: #fff; padding: 8px 12px; border-radius: 8px; cursor: pointer;display: flex; align-items: center; gap: 6px; transition: 0.2s; font-size: 13px;}
.modal-action-btn:hover { background: var(--c-accent); border-color: var(--c-accent); }
.report-paper {background: #ffffff; color: #1a202c; flex: 1; overflow-y: auto;border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;}
.report-header {padding: 20px 30px; border-bottom: 2px solid #e2e8f0;display: flex; justify-content: space-between; align-items: center;}
.clinic-logo-area { font-size: 24px; font-weight: 700; color: #2d3748; display: flex; align-items: center; gap: 10px; }
.report-patient-demographics {display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 13px;background: #f7fafc; padding: 10px 15px; border-radius: 6px; border: 1px solid #e2e8f0;}
.demo-label { font-weight: 600; color: #4a5568; }
.demo-value { color: #1a202c; }
.report-body { padding: 25px 30px; line-height: 1.6; font-size: 14px; color: #2d3748; }
.report-body img { max-width: 100%; height: auto; border-radius: 4px; margin: 12px 0; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: block; }
.report-footer { margin-top: 40px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #718096; text-align: center; }
.signatures { display: flex; justify-content: space-around; margin-top: 40px; padding-top: 10px; }
.sig-block { text-align: center; min-width: 150px; }
.sig-line { border-top: 1px solid #1a202c; margin-bottom: 5px; width: 100%; }
.sig-name { font-weight: 600; font-size: 13px; color: #2d3748; }

/* ===== Splash (3D) ===== */
#pacs-splash-screen{position:fixed;inset:0;z-index:99999;overflow:hidden;display:flex;flex-direction:column;align-items:center;justify-content:center;background:radial-gradient(circle at 50% 45%, var(--c-bg-panel) 0%, var(--c-bg-viewer) 45%, #020408 100%);perspective:1200px;transition:opacity 0.8s cubic-bezier(.25,1,.5,1), visibility 0.8s, transform 0.8s cubic-bezier(.25,1,.5,1);}
#pacs-splash-screen.hidden{opacity:0;visibility:hidden;pointer-events:none;transform:scale(1.1);}
.pacs-3d-scene{position:absolute;inset:0;transform-style:preserve-3d}
.pacs-depth-grid{position:absolute;inset:-20%;background-image:linear-gradient(rgba(var(--c-accent-rgb),.08) 1px, transparent 1px),linear-gradient(90deg, rgba(var(--c-accent-rgb),.08) 1px, transparent 1px);background-size:34px 34px;transform-origin:center;}
.grid-back{transform:translateZ(-300px) rotateX(70deg) scale(1.5);opacity:.25;filter:blur(1px);animation:gridDrift 16s linear infinite}
.grid-mid{transform:translateZ(-120px) rotateX(70deg) scale(1.2);opacity:.35;animation:gridDrift 9s linear infinite reverse}
.pacs-vignette{position:absolute;inset:0;background:radial-gradient(circle, transparent 35%, rgba(0,0,0,.55) 100%)}
.pacs-particles{position:absolute;inset:0;background:radial-gradient(circle at 20% 30%, rgba(var(--c-accent-rgb),.35) 0 2px, transparent 3px),radial-gradient(circle at 80% 60%, rgba(var(--c-accent-rgb),.35) 0 2px, transparent 3px),radial-gradient(circle at 60% 20%, rgba(var(--c-accent-rgb),.25) 0 1px, transparent 2px);animation:particlesFloat 8s ease-in-out infinite alternate;opacity:.6;}
.pacs-top-logo-wrap{position:absolute;top:28px;left:50%;transform:translateX(-50%);z-index:20}
#pacs-top-logo{width: 132px;height: auto;opacity: 1 !important;mix-blend-mode: normal !important;filter:brightness(1.65) contrast(1.35) saturate(1.15) drop-shadow(0 0 2px rgba(255,255,255,.95)) drop-shadow(0 0 10px rgba(var(--c-accent-rgb),.75)) drop-shadow(0 0 24px rgba(var(--c-accent-rgb),.45));}
.pacs-top-logo-wrap{top: 18px;z-index: 30;padding: 8px 16px;border-radius: 14px;background: linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.02));box-shadow:inset 0 0 0 1px rgba(140,190,255,.18), 0 8px 24px rgba(0,0,0,.35);backdrop-filter: blur(2px);}
#pacs-splash-corner-title,.pacs-corner{white-space: nowrap;line-height: 1;letter-spacing: .14em;font-size: 9px;max-width: 38vw;overflow: hidden;text-overflow: ellipsis;}
#pacs-splash-corner-title{ top: 78px; left: 16px; }.pacs-corner-tr{ top: 78px; right: 16px; text-align:right; }.pacs-corner-bl{ bottom: 16px; left: 16px; }.pacs-corner-br{ bottom: 16px; right: 16px; text-align:right; }

@media (max-width: 900px){
  .pacs-top-logo-wrap{ top: 10px; padding: 6px 12px; border-radius: 10px; }
  #pacs-top-logo{width: 110px;filter:brightness(1.9) contrast(1.45) drop-shadow(0 0 2px rgba(255,255,255,1)) drop-shadow(0 0 9px rgba(var(--c-accent-rgb),.8)) drop-shadow(0 0 20px rgba(var(--c-accent-rgb),.5));}
  .pacs-corner-tr{ display:none; }
  #pacs-splash-corner-title{top: 62px;left: 12px;max-width: calc(100vw - 24px);font-size: 8px;letter-spacing: .12em;}
  .pacs-corner-bl, .pacs-corner-br{font-size: 8px;letter-spacing: .12em;bottom: 10px;max-width: 46vw;}
  .pacs-loader-center{ margin-top: 34px; transform: scale(.83); }.pacs-loader-text{ margin-top: 6px; }.pacs-progress-wrap{ margin-top: 10px; width: 86vw; }
}

#pacs-roi-frame{position:absolute;width:300px;height:300px;z-index:8}
#pacs-roi-frame .roi{position:absolute;width:24px;height:24px}
#pacs-roi-frame .tl{top:0;left:0;border-top:1.5px solid rgba(var(--c-accent-rgb),.6);border-left:1.5px solid rgba(var(--c-accent-rgb),.6)}
#pacs-roi-frame .tr{top:0;right:0;border-top:1.5px solid rgba(var(--c-accent-rgb),.6);border-right:1.5px solid rgba(var(--c-accent-rgb),.6)}
#pacs-roi-frame .bl{bottom:0;left:0;border-bottom:1.5px solid rgba(var(--c-accent-rgb),.6);border-left:1.5px solid rgba(var(--c-accent-rgb),.6)}
#pacs-roi-frame .br{bottom:0;right:0;border-bottom:1.5px solid rgba(var(--c-accent-rgb),.6);border-right:1.5px solid rgba(var(--c-accent-rgb),.6)}
#pacs-splash-corner-title,.pacs-corner{position:absolute;font-size:10px;letter-spacing:.18em;color:rgba(133,190,242,.62);text-transform:uppercase;font-family:ui-monospace,monospace;z-index:9}
#pacs-splash-corner-title{top:20px;left:24px}.pacs-corner-tr{top:20px;right:24px;text-align:right}.pacs-corner-bl{bottom:20px;left:24px}.pacs-corner-br{bottom:20px;right:24px;text-align:right}
.pacs-loader-center{position:relative;width:240px;height:240px;z-index:12;transform-style:preserve-3d;animation:centerFloat 4.5s ease-in-out infinite}

.report-modal-box{width: 100vw !important;max-width: 100vw !important;height: 100dvh !important;max-height: 100dvh !important;border-radius: 0 !important;padding: 8px !important;background: var(--c-bg-viewer) !important;}
.modal-top-actions{position: sticky;top: 0;z-index: 20;background: var(--c-bg-viewer);padding: 4px 0 8px;margin-bottom: 6px;}
.modal-action-btn{flex: 1;justify-content: center;font-size: 14px;padding: 10px 12px;border-radius: 10px;}
.report-paper{border-radius: 10px !important;height: calc(100dvh - 70px) !important;overflow-y: auto !important;-webkit-overflow-scrolling: touch;}
.report-header{padding: 14px 14px !important;display: block !important;}
.clinic-logo-area{font-size: 18px !important;line-height: 1.25;margin-bottom: 10px;}
.report-patient-demographics{display: grid !important;grid-template-columns: 100px 1fr !important;gap: 6px 10px !important;font-size: 13px !important;padding: 10px !important;width: 100% !important;box-sizing: border-box;}
.demo-label{font-size:13px}.demo-value{font-size:13px;word-break: break-word;}
.report-body{padding: 14px !important;font-size: 14px !important;line-height: 1.55 !important;}
.report-body img{width: 100% !important;max-width: 100% !important;height: auto !important;margin: 10px 0 !important;}
.signatures{margin-top: 20px !important;gap: 12px;}
.sig-block{min-width: 0 !important;flex: 1;}
.sig-name{font-size: 12px !important;}
.report-footer{font-size: 11px !important;line-height: 1.4;margin-top: 22px !important;padding-top: 12px !important;}

.ring{position:absolute;border-radius:50%;inset:0;pointer-events:none}
.ring-1{border:1px dashed rgba(var(--c-accent-rgb),.28);animation:spin 16s linear infinite}
.ring-2{inset:14px;border:1px dotted rgba(var(--c-accent-rgb),.5);animation:spinReverse 10s linear infinite}
.ring-3{inset:26px;border:1.5px solid rgba(var(--c-accent-rgb),.65);box-shadow:0 0 20px rgba(var(--c-accent-rgb),.28);animation:pulse 2.1s ease-in-out infinite}
.ring-4{inset:38px;border:1px solid rgba(133,190,242,.35);animation:spin 5s linear infinite}
.sonar{position:absolute;inset:16px;border-radius:50%;background:conic-gradient(from 0deg, rgba(var(--c-accent-rgb),.22), transparent 120deg, transparent 360deg);animation:spin 2.6s linear infinite}
.scan-beam{position:absolute;inset:42px;border-radius:50%;background:linear-gradient(90deg, transparent 0%, rgba(133,190,242,.55) 50%, transparent 100%);filter:blur(4px);animation:beamSweep 2.2s ease-in-out infinite}
.logo-wrap{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:3}
#pacs-splash-logo{width:98px;height:98px;object-fit:contain;filter:drop-shadow(0 0 2px rgba(255,255,255,.9)) drop-shadow(0 0 14px rgba(var(--c-accent-rgb),.7));animation:logoPulse 1.9s ease-in-out infinite, logoGlow 3s ease-in-out infinite;}
.logo-shine{display:none!important}
.pacs-pipeline-wrap{width:280px;height:60px;margin-top:18px;z-index:10;opacity:.95;filter:drop-shadow(0 0 8px rgba(var(--c-accent-rgb),.2))}
.pipeline-beacon{animation:beaconPulse 1.8s ease-in-out infinite}
.data-pulse{will-change:transform}
.pulse-a{animation:dataFlow 1.4s linear infinite}.pulse-b{animation:dataFlow 1.8s linear infinite .4s}.pulse-c{animation:dataFlow 1.1s linear infinite .9s}.pulse-d{animation:dataFlow 1.6s linear infinite 1.2s}
.pacs-loader-text{margin-top:16px;text-align:center;z-index:12}
#pacs-splash-title{display:block;color:#dbe9f8;font-weight:700;letter-spacing:.08em}
#pacs-splash-status{display:block;margin-top:6px;color:#8fb9e4;font-size:12px;animation: textFlicker 3.5s infinite linear;}
.pacs-progress-wrap{width:min(560px,86vw);margin-top:16px;z-index:12}
.pacs-progress-meta{display:flex;justify-content:space-between;margin-bottom:6px;color:#8db3d9;font-size:11px;letter-spacing:.08em}
.pacs-progress-bar{position:relative;height:12px;border-radius:999px;overflow:hidden;border:1px solid rgba(var(--c-accent-rgb),.45);background:rgba(10,20,36,.9)}
#pacs-splash-progress{height:100%;width:0%;background:linear-gradient(90deg,var(--c-accent),#4e86d8,#82c4ff);box-shadow:0 0 16px rgba(var(--c-accent-rgb),.6);transition:width .08s linear}
.pacs-progress-shine{position:absolute;top:0;left:-30%;width:30%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent);animation:shine 1.5s linear infinite}

/* ===== Viewer ===== */
.viewer-header{height:64px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 18px;background:linear-gradient(180deg,var(--c-bg-header),var(--c-bg-panel));border-bottom:1px solid var(--c-border);box-shadow:0 8px 24px rgba(0,0,0,.28);position:relative;z-index:1000;transition: all var(--t-speed) ease;}
.vh-left,.vh-right{display:flex;align-items:center;gap:12px}
.vh-tools{display:flex;align-items:center;gap:6px;flex:1;justify-content:center;min-width:0;white-space:nowrap;overflow:visible}
.tool-group{display:flex;align-items:center;gap:6px}
.tool-btn{width:40px;height:40px;border-radius:12px;border:1px solid var(--c-border);background:rgba(255,255,255,.02);color:var(--c-text);cursor:pointer;transition: all 0.2s cubic-bezier(0.18, 0.89, 0.32, 1.28);position:relative;}
.tool-btn:hover{background:rgba(var(--c-accent-rgb),.08);border-color:var(--c-accent);box-shadow:0 0 0 1px rgba(var(--c-accent-rgb),.08),0 0 16px rgba(var(--c-accent-rgb),.08);transform: translateY(-2px);}
.tool-btn:active{transform: scale(0.95);}
.tool-btn.active{background:linear-gradient(180deg,rgba(var(--c-accent-rgb),.18),rgba(var(--c-accent-rgb),.1));border-color:var(--c-accent);color:var(--c-accent);box-shadow: 0 0 12px rgba(var(--c-accent-rgb), 0.3);}
.tool-sep{width:1px;height:28px;background:var(--c-border);margin:0 6px;transition: background var(--t-speed) ease;}
.tool-slider{accent-color:var(--c-accent)}
.tool-fps{font-size:12px;color:#8eb8df}
.layout-icon{font-size:12px;font-weight:700}
.image-counter{min-width:84px;height:40px;border-radius:12px;display:none;align-items:center;justify-content:center;border:1px solid var(--c-border);background:rgba(255,255,255,.02);color:var(--c-text);font-weight:700;transition: all var(--t-speed) ease;}

/* Viewer Entry Animation */
.viewer-shell{opacity:0;transform:translateY(20px);transition:opacity 0.6s ease, transform 0.6s cubic-bezier(0.18, 0.89, 0.32, 1.28);flex:1;display:flex;flex-direction:column;min-height:0}
.viewer-shell.ready{opacity:1;transform:translateY(0)}
.viewer-body{flex:1;min-height:0;display:flex;position:relative;z-index:10}
.series-panel{width:290px;max-width:80vw;background:linear-gradient(180deg,var(--c-bg-panel),var(--c-bg-viewer));transition: background var(--t-speed) ease;}
.series-panel{border-right:1px solid var(--c-border)}
.info-panel{display: none !important; border-left:1px solid var(--c-border)}
.panel-header{font-size:11px;letter-spacing:.18em;color:var(--c-accent);text-transform:uppercase;padding:14px 16px;background:rgba(255,255,255,.02);border-bottom:1px solid var(--c-border);transition: all var(--t-speed) ease;}
.series-list,.meas-list{padding:8px;overflow:auto;height:calc(100% - 48px)}
.meas-empty{color:#7e93a5;padding:14px}

.series-item{display:flex;gap:12px;padding:12px;margin:10px;border-radius:14px;border:1px solid var(--c-border);background:rgba(255,255,255,.02);cursor:pointer;transition: all 0.3s cubic-bezier(0.18, 0.89, 0.32, 1.28); animation: slideInLeft 0.4s ease backwards;}
.series-item:nth-child(1){animation-delay: 0.1s;}
.series-item:nth-child(2){animation-delay: 0.2s;}
.series-item:nth-child(3){animation-delay: 0.3s;}
.series-item:nth-child(4){animation-delay: 0.4s;}
.series-item:hover{background:rgba(var(--c-accent-rgb),.05);border-color:var(--c-accent);transform: translateX(5px);}
.series-item.active{background:linear-gradient(180deg,rgba(var(--c-accent-rgb),.12),rgba(var(--c-accent-rgb),.05));border-color:var(--c-accent);box-shadow:0 0 20px rgba(var(--c-accent-rgb),.08)}
.series-thumb-wrap{position:relative}
.series-thumb-canvas{width:80px;height:80px;background:#000;border-radius:12px;border:1px solid var(--c-border);box-shadow:inset 0 0 0 1px rgba(255,255,255,.02)}
.series-thumb-count{position:absolute;right:6px;bottom:6px;font-size:10px;padding:2px 6px;border-radius:999px;background:rgba(2,5,6,.7);color:var(--c-accent);border:1px solid var(--c-border)}
.series-info{display:flex;flex-direction:column;gap:4px;min-width:0}
.series-num{color:#fff;font-weight:700;font-size:13px}
.series-desc{color:#c6d4df;font-size:12px;word-break:break-word}
.series-mod{color:var(--c-accent);font-size:11px;letter-spacing:.08em;text-transform:uppercase;}

.viewport-area{flex:1;min-width:0;min-height:0;display:flex;flex-direction:row;background:var(--c-bg-viewer);transition: background var(--t-speed) ease;}
.viewports-grid{flex:1;min-height:0;display:grid;gap:12px;padding:12px;background:linear-gradient(rgba(var(--c-accent-rgb),.02) 1px, transparent 1px),linear-gradient(90deg, rgba(var(--c-accent-rgb),.02) 1px, transparent 1px),var(--c-bg-viewer);background-size:24px 24px;transition: background var(--t-speed) ease; animation: bgBreathe 10s ease-in-out infinite alternate;}
.viewport-cell{position:relative;background:#000;border-radius:18px;overflow:hidden;border:1px solid var(--c-border);box-shadow:0 8px 30px rgba(0,0,0,.35);min-height:180px;z-index:1;transition: all 0.3s ease;}
.viewport-cell.active-cell{border-color:var(--c-accent);box-shadow:0 0 0 1px rgba(var(--c-accent-rgb),.1),0 0 24px rgba(var(--c-accent-rgb),.1);animation: cellPulse 3s ease-in-out infinite;}
.cornerstone-element{width:100%;height:100%;background:#000!important;touch-action:none}
.vp-loading{position:absolute;inset:0;display:none;align-items:center;justify-content:center;background:rgba(2,5,6,.45);color:var(--c-accent);font-size:13px;letter-spacing:.14em;text-transform:uppercase;z-index:15;backdrop-filter:blur(2px)}

/* ===== PET-CT Fusion overlay canvas (per viewport) ===== */
.fusion-overlay-canvas{position:absolute;pointer-events:none;z-index:12;display:none}
.viewport-cell.fusion-on .fusion-overlay-canvas{display:block}
.fusion-viewport-badge{
  position:absolute;top:10px;left:10px;z-index:16;display:none;align-items:center;gap:6px;
  background:rgba(0,0,0,.55);border:1px solid rgba(var(--c-accent-rgb),.5);
  border-radius:6px;padding:4px 9px;font-size:10px;letter-spacing:.08em;text-transform:uppercase;
  color:#e8edf5;pointer-events:none;
}
.fusion-viewport-badge.active{display:flex}
.fusion-viewport-badge i{color:var(--c-accent)}

.scroll-bar-track{padding:0;background:var(--c-bg-header);border-left:1px solid var(--c-border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 40px;width:40px;height:100%}
.image-scroller{-webkit-appearance:slider-vertical!important;appearance:slider-vertical!important;writing-mode:vertical-lr!important;direction:ltr!important;width:6px!important;height:calc(100% - 40px)!important;min-height:200px!important;accent-color:var(--c-accent)}

.screenshot-flash { position: absolute; inset: 0; background: #fff; opacity: 0; pointer-events: none; z-index: 100; }
.screenshot-flash.flash { animation: flashAnim 0.4s ease-out; }
@keyframes flashAnim { 0% { opacity: 0.8; } 100% { opacity: 0; } }

.toast-notification {
  position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(100px);
  background: linear-gradient(135deg, var(--c-accent), #4e86d8); color: #fff; padding: 12px 24px;
  border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 10000; opacity: 0;
  transition: transform 0.4s cubic-bezier(0.18, 0.89, 0.32, 1.28), opacity 0.4s ease;
  display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 500; pointer-events: none;
}
.toast-notification.show { transform: translateX(-50%) translateY(0); opacity: 1; }

#customMagnify {
  position: fixed; width: 160px; height: 160px; border-radius: 50%; border: 3px solid rgba(255,255,255,0.9);
  box-shadow: 0 4px 12px rgba(0,0,0,0.6), inset 0 0 20px rgba(0,0,0,0.2); overflow: hidden; pointer-events: none;
  z-index: 1000; display: none; background: #000;
}
#magnifyCanvas { width: 100%; height: 100%; }

.dicom-overlay{position:absolute;z-index:20;color:#e8edf5;font:12px/1.35 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;text-shadow:0 1px 2px rgba(0,0,0,.9);pointer-events:none;white-space:pre}
.dicom-overlay.top-left{top:10px;left:10px;text-align:left}
.dicom-overlay.top-right{top:10px;right:10px;text-align:right}

.series-edge-toggle{position:absolute;left:290px;top:50%;transform:translate(-50%,-50%);z-index:35;width:80px;height:30px;border-radius:14px;border:1px solid var(--c-border);background:linear-gradient(180deg,var(--c-bg-header),var(--c-bg-panel));color:var(--c-accent);display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 20px rgba(0,0,0,.35);transition:left .22s ease,background .2s ease,color .2s ease,transform .2s ease}
.series-edge-toggle:hover{background:linear-gradient(180deg,#132237,#0f1b2c);color:#a5c5f5}
.series-edge-toggle i{font-size:12px;transition:transform .22s ease}
body.series-collapsed .series-panel{width:0!important;min-width:0!important;max-width:0!important;flex:0 0 0!important;overflow:hidden!important;border:0!important;padding:0!important;margin:0!important}
body.series-collapsed .series-panel .panel-header,body.series-collapsed .series-panel .series-list{display:none!important}
body.series-collapsed .series-edge-toggle{left:8px!important}

.meas-dropdown-wrap {position: relative;display: inline-flex;}
.meas-dropdown-wrap .tool-btn {display: inline-flex;align-items: center;gap: 2px;}
.meas-dropdown-menu {
  position: fixed; z-index: 99999; min-width: 230px;
  background: linear-gradient(180deg, var(--c-bg-header), var(--c-bg-panel));
  border: 1px solid var(--c-accent); border-radius: 12px;
  box-shadow: 0 18px 48px rgba(0,0,0,.55), 0 0 0 1px rgba(var(--c-accent-rgb),.06);
  padding: 6px; display: none; flex-direction: column; gap: 2px; transform-origin: top left;
  transition: background var(--t-speed) ease;
}
.meas-dropdown-menu.open { display: flex; animation: dropDownAnim 0.4s cubic-bezier(0.18, 0.89, 0.32, 1.28); }
@keyframes dropDownAnim { 0% { opacity: 0; transform: translateY(-15px) scale(0.9); } 60% { opacity: 1; transform: translateY(5px) scale(1.02); } 100% { opacity: 1; transform: translateY(0) scale(1); } }
.meas-menu-title {font-size: 10px;letter-spacing: .14em;color: var(--c-accent);padding: 8px 12px 6px;text-transform: uppercase;border-bottom: 1px solid var(--c-border);margin-bottom: 4px;}
.meas-menu-divider {height: 1px;background: var(--c-border);margin: 4px 6px;}
.meas-item {display: flex;align-items: center;gap: 10px;padding: 9px 12px;border: 0;background: transparent;color: var(--c-text);border-radius: 8px;cursor: pointer;font-size: 13px;font-family: inherit;text-align: left;transition: background .15s ease, color .15s ease;width: 100%; box-sizing: border-box;}
.meas-item:hover {background: rgba(var(--c-accent-rgb),.14);color: #fff;}
.meas-item.active {background: linear-gradient(180deg, rgba(var(--c-accent-rgb),.22), rgba(var(--c-accent-rgb),.1));color: var(--c-accent);}
.meas-item.meas-clear {color: #ff8a8a;}
.meas-item.meas-clear:hover {background: rgba(255,90,90,.15);color: #ffb3b3;}
.meas-icon {width: 22px;height: 22px;display: inline-flex;align-items: center;justify-content: center;border-radius: 6px;background: rgba(var(--c-accent-rgb),.12);color: var(--c-accent);font-size: 11px;flex: 0 0 auto;}
.meas-name {flex: 1;}
.meas-key {display: inline-flex;align-items: center;justify-content: center;min-width: 22px;height: 22px;border-radius: 6px;background: rgba(255,255,255,.06);border: 1px solid var(--c-border);font-size: 10px;font-family: ui-monospace, monospace;color: var(--c-text);}

.settings-item {display: flex;align-items: center;gap: 10px;padding: 10px 12px;color: var(--c-text);border-radius: 8px;font-size: 13px;cursor: pointer;}
.settings-item:hover {background: rgba(var(--c-accent-rgb),.14);color: #fff;}
.settings-item input[type="checkbox"] {width: 16px;height: 16px;accent-color: var(--c-accent);cursor: pointer;flex: 0 0 auto;}
.settings-item label {flex: 1;cursor: pointer;}

/* ===== PET-CT Fusion dropdown panel ===== */
.fusion-dropdown-menu{min-width:290px;max-width:min(360px, calc(100vw - 32px));box-sizing:border-box}
.fusion-no-pet-warning{
  display:flex;gap:8px;align-items:flex-start;margin:8px 12px 0;padding:9px 10px;
  background:rgba(255,180,60,.12);border:1px solid rgba(255,180,60,.4);border-radius:8px;
  font-size:11.5px;line-height:1.4;color:#ffd9a0;
}
.fusion-no-pet-warning i{color:#ffb43c;margin-top:1px;flex:0 0 auto}
.fusion-no-pet-warning span{min-width:0;flex:1 1 auto;word-break:break-word;white-space:normal;overflow-wrap:anywhere}
.fusion-select-row{display:flex;flex-direction:column;gap:4px;padding:8px 12px 4px}
.fusion-select-row label{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8eb8df}
.fusion-select-row select{max-width:100%}
.fusion-select-row select{
  background:rgba(255,255,255,.04);border:1px solid var(--c-border);color:var(--c-text);
  padding:7px 8px;border-radius:8px;font-size:12px;font-family:inherit;width:100%;box-sizing:border-box;
}
/* The dropdown popup list is rendered by the browser with its own
   (usually white) background regardless of page theme, so the option
   text needs an explicit dark color here — it can't inherit var(--c-text)
   (a light color meant for the dark page chrome) or it becomes unreadable. */
.fusion-select-row select option{
  background:#ffffff;color:#1a202c;
}
.fusion-mode-row{display:flex;gap:6px;padding:6px 12px}
.fusion-mode-btn{
  flex:1;padding:7px 4px;border-radius:8px;border:1px solid var(--c-border);
  background:rgba(255,255,255,.03);color:var(--c-text);font-size:11px;letter-spacing:.05em;
  text-transform:uppercase;cursor:pointer;transition:.15s;font-family:inherit;
}
.fusion-mode-btn:hover{border-color:var(--c-accent)}
.fusion-mode-btn.active{background:var(--c-accent);border-color:var(--c-accent);color:#fff}
.fusion-slider-row{display:flex;flex-direction:column;gap:5px;padding:8px 12px}
.fusion-slider-row .fusion-slider-label{display:flex;justify-content:space-between;font-size:11px;color:#8eb8df}
.fusion-slider-row input[type="range"]{width:100%;accent-color:var(--c-accent)}
.fusion-cmap-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:6px 12px 10px}
.fusion-cmap{height:24px;border-radius:6px;cursor:pointer;border:1px solid var(--c-border);position:relative;transition:.15s}
.fusion-cmap.selected{outline:2px solid #fff;outline-offset:1px}
.fusion-cmap[data-map="hotiron"]{background:linear-gradient(90deg,#000,#7f0000,#ff4b00,#ffce00,#fff)}
.fusion-cmap[data-map="rainbow"]{background:linear-gradient(90deg,#0000ff,#00ffff,#00ff00,#ffff00,#ff0000)}
.fusion-cmap[data-map="pet"]{background:linear-gradient(90deg,#000030,#3a00b0,#e000a0,#ff8c00,#fff9c4)}
.fusion-cmap[data-map="grayscale"]{background:linear-gradient(90deg,#000,#fff)}

.theme-color-dot { width: 20px; height: 20px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.2); flex: 0 0 auto; transition: transform 0.2s; }
.theme-item:hover .theme-color-dot { transform: scale(1.1); border-color: #fff; }

.meas-row{display:flex;align-items:center;gap:8px;padding:9px 12px;margin:6px 8px;border-radius:10px;background: rgba(255,255,255,.02);border:1px solid var(--c-border);font-size:12px;transition: all 0.3s ease; animation: slideInRight 0.4s ease;}
.meas-row-type{flex:1;color:var(--c-accent);font-weight:600;text-transform:uppercase;letter-spacing:.05em;font-size:11px;}
.meas-row-val{color:var(--c-text);font-family:ui-monospace,monospace;}
.meas-row-vp{font-size:10px;padding:2px 6px;border-radius:6px;background: rgba(var(--c-accent-rgb),.18);color:var(--c-accent);}

/* Small Background Preloader Bar */
#bgLoader {
  position: fixed; bottom: 0; left: 0; width: 100%; height: 3px; 
  background: rgba(54, 111, 209, 0.1); z-index: 9998; display: none; pointer-events: none;
}
#bgLoaderBar {
  height: 100%; width: 0%; background: var(--c-accent); box-shadow: 0 0 8px var(--c-accent); 
  transition: width 0.3s ease, opacity 0.5s ease;
}

/* Animated & Highlighted Report Button */
.tool-btn.report-highlight-btn {
  background: linear-gradient(180deg, rgba(60, 208, 143, 0.2), rgba(60, 208, 143, 0.05));
  border-color: #3CD08F;
  color: #3CD08F;
  animation: reportPulse 2s infinite cubic-bezier(0.4, 0, 0.6, 1);
  position: relative;
  overflow: visible;
}
.tool-btn.report-highlight-btn:hover {
  background: linear-gradient(180deg, rgba(60, 208, 143, 0.35), rgba(60, 208, 143, 0.15));
  border-color: #3CD08F;
  color: #fff;
  transform: translateY(-2px);
  box-shadow: 0 0 0 1px rgba(60, 208, 143, 0.2), 0 0 24px rgba(60, 208, 143, 0.5);
}
.tool-btn.report-highlight-btn i.fa-file-medical {
  animation: reportIconWiggle 2.5s infinite ease-in-out;
}
@keyframes reportPulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(60, 208, 143, 0.4); }
  50% { box-shadow: 0 0 16px 2px rgba(60, 208, 143, 0.6); }
}
@keyframes reportIconWiggle {
  0%, 100% { transform: rotate(-5deg) scale(1); }
  50% { transform: rotate(5deg) scale(1.1); }
}

@media (max-width: 900px){
  html,body{height:100dvh!important;min-height:100dvh!important;overflow:hidden!important;background:var(--c-bg-viewer)}
  body.viewer-page,.viewer-page{height:100dvh!important;min-height:100dvh!important;overflow:hidden!important;background:var(--c-bg-viewer)}
  .viewer-header{height:auto;min-height:74px;padding:8px 10px;background:var(--c-bg-header);border-bottom:1px solid var(--c-border);box-shadow:none;display:flex;flex-wrap:wrap;gap:6px;position:sticky;top:0;z-index:1000}
  .vh-left{width:auto!important;gap:8px;min-width:0}.vh-right{margin-left:auto;order:2}.image-counter{display:none!important}
  .vh-tools{order:3;width:100%;justify-content:flex-start;gap:4px;overflow-x:auto;white-space:nowrap;padding-bottom:2px;scrollbar-width:none}
  .vh-tools::-webkit-scrollbar{display:none}
  .tool-group{display:inline-flex;gap:4px;flex:0 0 auto}.tool-btn{width:34px;height:34px;border-radius:10px}.tool-sep{height:20px;margin:0 3px}#cineSpeed{width:82px}.tool-fps{font-size:10px}.layout-icon{font-size:11px}
  .viewer-body{flex:1 1 auto;min-height:0;height:calc(100dvh - 74px);display:flex;flex-direction:column;overflow:hidden!important}
  .info-panel{display:none!important}
  .viewport-area{order:1;flex:1 1 auto!important;min-height:0!important;display:flex!important;flex-direction:row!important;background:var(--c-bg-viewer);overflow:hidden!important}
  .viewports-grid{flex:1 1 auto!important;min-width:0!important;min-height:0!important;padding:4px;gap:4px;background:var(--c-bg-viewer)}
  .viewport-cell{min-height:0!important;border-radius:10px;border:1px solid var(--c-accent);box-shadow:none}
  .cornerstone-element{touch-action:none!important;-webkit-user-select:none!important;user-select:none!important}
  .viewer-header,.vh-tools,.tool-group,.tool-btn,.tool-btn *{touch-action:manipulation!important;pointer-events:auto!important}
  .dicom-overlay{z-index:50;font:10px/1.2 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace}.dicom-overlay.top-left{top:6px;left:6px}.dicom-overlay.top-right{top:6px;right:6px}.vp-loading{z-index:60}
  .scroll-bar-track{order:2!important;flex:0 0 30px!important;width:30px!important;height:100%!important;padding:0;background:var(--c-bg-header);border-left:1px solid var(--c-border);display:flex;align-items:center;justify-content:center;overflow:hidden}
  .image-scroller{-webkit-appearance:slider-vertical!important;appearance:slider-vertical!important;writing-mode:vertical-lr!important;direction:ltr!important;width:4px!important;height:calc(100% - 20px)!important;min-height:150px!important;accent-color:var(--c-accent)}
  
  /* --- FIXED SERIES PANEL TO SHOW TEXT COMPLETELY --- */
  .series-panel{order:3;display:block!important;width:100%;max-width:100%;flex:0 0 155px!important;height:155px!important;border-right:0;border-top:1px solid var(--c-border);background:var(--c-bg-panel);overflow:hidden;transition: height 0.3s ease, flex-basis 0.3s ease;}
  body.series-collapsed .series-panel{height:0!important;min-height:0!important;flex:0 0 0!important;overflow:hidden!important;border:0!important}
  .panel-header{font-size:11px;letter-spacing:.12em;color:var(--c-accent);padding:5px 10px;border-bottom:1px solid var(--c-border);height:22px;box-sizing:border-box;line-height:12px;}
  .series-list{height:calc(100% - 22px);padding:6px 8px;display:flex!important;flex-direction:row!important;gap:10px;overflow-x:auto!important;overflow-y:hidden!important;align-items:stretch}
  .series-item{margin:0!important;width:165px!important;min-width:165px!important;max-width:165px!important;flex:0 0 165px!important;display:flex;flex-direction:column;gap:4px;padding:6px;border-radius:12px;background:var(--c-bg-header);border:1px solid var(--c-border);transition: transform 0.2s ease, box-shadow 0.2s ease;overflow:hidden;}
  .series-item:active { transform: scale(0.97); }
  .series-item.active{border-color:var(--c-accent);box-shadow:0 0 0 1px rgba(var(--c-accent-rgb),.3);background:linear-gradient(180deg, rgba(var(--c-accent-rgb),.15), rgba(var(--c-accent-rgb),.05));animation: cellPulse 3s ease-in-out infinite;}
  .series-thumb-canvas{width:100%!important;height:70px!important;display:block;background:#000;border-radius:7px;transition: opacity 0.3s ease;}
  .series-thumb-count{right:5px;bottom:5px;font-size:10px;padding:2px 6px}
  .series-info{gap:2px;min-width:0}
  .series-num{font-size:12px;color:#3980d3;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .series-desc{font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2}
  .series-mod{display:none}
  
  .viewports-grid[data-layout="1x1"]{grid-template-columns:1fr!important;grid-template-rows:1fr!important}
  .viewports-grid[data-layout="1x2"]{grid-template-columns:1fr!important;grid-template-rows:repeat(2,minmax(0,1fr))!important}
  .viewports-grid[data-layout="1x3"]{grid-template-columns:1fr!important;grid-template-rows:repeat(3,minmax(0,1fr))!important}
  .viewports-grid[data-layout="2x2"]{grid-template-columns:repeat(2,minmax(0,1fr))!important;grid-template-rows:repeat(2,minmax(0,1fr))!important}
  .series-edge-toggle{left:auto!important;top:auto!important;bottom:155px!important;right:0!important;transform:none!important;width:30px!important;height:48px!important;border-radius:0!important;z-index:70;border-left:1px solid var(--c-border);border-top:1px solid var(--c-border);background:var(--c-bg-header);box-shadow:none;}
  .series-edge-toggle i{transform:rotate(90deg)}
  body.series-collapsed .series-edge-toggle{left:auto!important;bottom:0!important;right:0!important}
  .viewport-cell, .cornerstone-element {touch-action: none !important;}
  .meas-dropdown-menu {min-width: 200px;left: 0;right: auto;}
  .meas-item {font-size: 12px;padding: 8px 10px;}
}

/* table behaves like plain blocks on screen */
.report-print-table{width:100%;border-collapse:collapse}
.report-print-table,
.report-print-table > thead,
.report-print-table > tbody,
.report-print-table > tfoot,
.report-print-table tr,
.report-print-table td{display:block;width:100%;box-sizing:border-box}

@media print {
  @page { size: A4 portrait; margin: 12mm 10mm 14mm; }

  html, body { height:auto !important; min-height:0 !important; overflow:visible !important;
               background:#fff !important; margin:0 !important; padding:0 !important; }
  body.viewer-page > *:not(.report-modal-overlay) { display:none !important; }

  .report-modal-overlay{
    position:static !important; inset:auto !important; z-index:auto !important;
    display:block !important; visibility:visible !important; opacity:1 !important;
    background:none !important; backdrop-filter:none !important; pointer-events:auto !important;
  }
  .report-modal-box{
    position:static !important; width:100% !important; max-width:100% !important;
    height:auto !important; max-height:none !important;
    background:#fff !important; padding:0 !important; margin:0 !important;
    border-radius:0 !important; box-shadow:none !important; transform:none !important;
  }
  .modal-top-actions{ display:none !important; }
  .report-paper{
    height:auto !important; max-height:none !important; overflow:visible !important;
    background:#fff !important; color:#1a202c !important;
    border:none !important; border-radius:0 !important; box-shadow:none !important;
  }

  /* real table display — this is what repeats the header and footer */
  .report-print-table       { display:table !important; width:100% !important; }
  .report-print-table>thead { display:table-header-group !important; }
  .report-print-table>tbody { display:table-row-group !important; }
  .report-print-table>tfoot { display:table-footer-group !important; }
  .report-print-table tr    { display:table-row !important; }
  .report-print-table td    { display:table-cell !important; }

  .report-header{
    padding:8px 6px 10px !important; display:flex !important;
    justify-content:space-between !important; align-items:flex-start !important;
    border-bottom:2px solid #2d3748 !important;
  }
  .clinic-logo-area{ font-size:17px !important; margin:0 !important; }
  .report-patient-demographics{
    grid-template-columns:auto 1fr !important; font-size:11px !important;
    padding:6px 10px !important; width:auto !important;
    background:#f7fafc !important; border:1px solid #e2e8f0 !important;
  }
  .demo-label,.demo-value{ font-size:11px !important; }

  .report-body{ padding:14px 6px !important; font-size:12.5pt !important; line-height:1.55 !important; }
  .report-body img{ max-width:100% !important; height:auto !important; break-inside:avoid; }

  .report-footer{
    margin:0 !important; padding:6px 6px 0 !important;
    border-top:1px solid #cbd5e0 !important; font-size:9pt !important; color:#4a5568 !important;
  }
  .signatures{ margin-top:34px !important; break-inside:avoid; }
  .sig-block{ min-width:150px !important; }

  * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
}

@keyframes spin{to{transform:rotate(360deg)}}
@keyframes spinReverse{to{transform:rotate(-360deg)}}
@keyframes pulse{0%,100%{opacity:.5;transform:scale(1)}50%{opacity:1;transform:scale(1.03)}}
@keyframes beamSweep{0%{transform:rotate(0) scale(.9)}100%{transform:rotate(360deg) scale(1.08)}}
@keyframes logoPulse{0%,100%{transform:scale(.98)}50%{transform:scale(1.04)}}
@keyframes logoGlow{0%,100%{filter:drop-shadow(0 0 2px rgba(255,255,255,.9)) drop-shadow(0 0 14px rgba(var(--c-accent-rgb),.7))}50%{filter:drop-shadow(0 0 5px rgba(255,255,255,1)) drop-shadow(0 0 25px rgba(var(--c-accent-rgb),1))}}
@keyframes textFlicker{0%,19%,21%,23%,25%,54%,56%,100%{opacity:1}20%,22%,24%,55%{opacity:0.4}}
@keyframes centerFloat{0%,100%{transform:translateY(0) rotateX(2deg)}50%{transform:translateY(-8px) rotateX(-2deg)}}
@keyframes particlesFloat{0%{transform:translateY(0)}100%{transform:translateY(-16px)}}
@keyframes gridDrift{0%{background-position:0 0,0 0}100%{background-position:0 220px,220px 0}}
@keyframes shine{0%{left:-30%}100%{left:130%}}
@keyframes beaconPulse{0%,100%{r:2;opacity:.4;fill:var(--c-accent)}50%{r:5;opacity:1;fill:var(--c-accent)}}
@keyframes dataFlow{0%{transform:translateX(0);opacity:0}10%{opacity:.9}85%{opacity:.9}100%{transform:translateX(310px);opacity:0}}
@keyframes slideInUp {0%{opacity:0;transform:translateY(20px)}100%{opacity:1;transform:translateY(0)}}
@keyframes slideInLeft {0%{opacity:0;transform:translateX(-20px)}100%{opacity:1;transform:translateX(0)}}
@keyframes slideInRight {0%{opacity:0;transform:translateX(20px)}100%{opacity:1;transform:translateX(0)}}
@keyframes cellPulse {0%,100%{box-shadow:0 0 0 1px rgba(var(--c-accent-rgb),.1),0 0 15px rgba(var(--c-accent-rgb),.1)}50%{box-shadow:0 0 0 1px rgba(var(--c-accent-rgb),.2),0 0 30px rgba(var(--c-accent-rgb),.2)}}
@keyframes bgBreathe {0%{background-size:24px 24px;opacity:1}50%{background-size:28px 28px;opacity:0.8}100%{background-size:24px 24px;opacity:1}}



/* ===== Light mode ===== */

/* anti-flash: the head script sets data-mode on <html>, so <html> needs a rule too */
:root{color-scheme:dark}
html[data-mode="light"]{color-scheme:light;background:#f3f6fb}

body[data-mode="light"]{
  --c-bg-main:#f3f6fb;
  --c-bg-header:#ffffff;
  --c-bg-panel:#eef2f9;
  --c-bg-viewer:#dfe6f0;
  --c-text:#1b2432;
  --c-border:rgba(var(--c-accent-rgb),.28);
}

/* --- surfaces --- */
body[data-mode="light"] .tool-btn,
body[data-mode="light"] .image-counter,
body[data-mode="light"] .panel-header,
body[data-mode="light"] .meas-row,
body[data-mode="light"] .series-item{background:rgba(0,0,0,.03)}
body[data-mode="light"] .tool-btn:hover{background:rgba(var(--c-accent-rgb),.12)}
body[data-mode="light"] .meas-key{background:rgba(0,0,0,.05)}
body[data-mode="light"] .modal-action-btn{background:rgba(0,0,0,.05);color:var(--c-text)}
body[data-mode="light"] .series-edge-toggle:hover{background:linear-gradient(180deg,#fff,#eef2f9);color:var(--c-accent)}
body[data-mode="light"] .viewer-header{box-shadow:0 6px 18px rgba(20,35,60,.08)}
body[data-mode="light"] .viewport-cell{box-shadow:0 6px 18px rgba(20,35,60,.12)}
body[data-mode="light"] .viewports-grid{
  background:linear-gradient(rgba(var(--c-accent-rgb),.06) 1px, transparent 1px),
             linear-gradient(90deg, rgba(var(--c-accent-rgb),.06) 1px, transparent 1px),
             var(--c-bg-viewer);
}

/* --- text --- */
body[data-mode="light"] .series-num{color:var(--c-text)}
body[data-mode="light"] .series-desc{color:#41506a}
body[data-mode="light"] .tool-fps,
body[data-mode="light"] .meas-empty{color:#5a6a85}
body[data-mode="light"] .meas-item:hover,
body[data-mode="light"] .settings-item:hover{color:var(--c-text)}

/* --- active/highlight states: must outrank the surface rules above --- */
body[data-mode="light"] .tool-btn.active{
  background:linear-gradient(180deg,rgba(var(--c-accent-rgb),.18),rgba(var(--c-accent-rgb),.10));
}
body[data-mode="light"] .tool-btn.report-highlight-btn{
  background:linear-gradient(180deg,rgba(60,208,143,.22),rgba(60,208,143,.06));
}
body[data-mode="light"] .tool-btn.report-highlight-btn:hover{
  background:linear-gradient(180deg,rgba(60,208,143,.35),rgba(60,208,143,.15));
  color:#0b6b46;
}
body[data-mode="light"] .series-item.active{
  background:linear-gradient(180deg,rgba(var(--c-accent-rgb),.12),rgba(var(--c-accent-rgb),.05));
}

/* --- splash stays dark: its glow effects are built for a dark backdrop --- */
body[data-mode="light"] #pacs-splash-screen{
  background:radial-gradient(circle at 50% 45%, #0d1420 0%, #05090f 45%, #020408 100%);
}
body[data-mode="light"] #pacs-splash-title{color:#dbe9f8}

/* ===== Light mode — mobile ===== */
@media (max-width: 900px){
  body[data-mode="light"] .series-panel{background:var(--c-bg-panel)}
  body[data-mode="light"] .series-item{
    background:#fff;
    box-shadow:0 2px 6px rgba(20,35,60,.10);
  }
  body[data-mode="light"] .series-item.active{
    background:linear-gradient(180deg,rgba(var(--c-accent-rgb),.14),rgba(var(--c-accent-rgb),.05));
  }
  body[data-mode="light"] .series-edge-toggle{
    background:#fff;
    border-left:1px solid var(--c-border);
    border-top:1px solid var(--c-border);
    box-shadow:-2px -2px 8px rgba(20,35,60,.10);
  }
  body[data-mode="light"] .scroll-bar-track{
    background:var(--c-bg-header);
    border-left:1px solid var(--c-border);
  }
  body #btnModeToggle{width:36px;height:36px}
  #btnModeToggle{-webkit-tap-highlight-color:transparent}
}

</style>
</head>
<body class="viewer-page" data-theme="blue">

<?php if (!empty($reports)): ?>
  <?php foreach ($reports as $rp):
    $text = $rp['report_text'] ?? '';
    $isHtml = (stripos($text, '<img ') !== false || stripos($text, '<br') !== false || stripos($text, '<p>') !== false);
  ?>
    <div id="reportData<?= (int)$rp['id'] ?>" style="display:none;">
      <?php if ($isHtml): ?>
        <?= $text ?>
      <?php else: ?>
        <?= nl2br(htmlspecialchars($text)) ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div id="pacs-splash-screen">
  <div class="pacs-3d-scene">
    <div class="pacs-depth-grid grid-back"></div>
    <div class="pacs-depth-grid grid-mid"></div>
    <div class="pacs-vignette"></div>
    <div class="pacs-particles"></div>
  </div>
  <div class="pacs-top-logo-wrap">
    <img id="pacs-top-logo" src="assets/images/4f-logo.png" alt="4F Logo">
  </div>
  <div id="pacs-roi-frame">
    <div class="roi tl"></div><div class="roi tr"></div><div class="roi bl"></div><div class="roi br"></div>
  </div>
  <div class="pacs-corner pacs-corner-tr">DICOM 3.0 COMPLIANT // TLS SECURE</div>
  <div class="pacs-corner pacs-corner-bl">ACQ PIPELINE v2.4.1</div>
  <div class="pacs-corner pacs-corner-br">© 2026 PACS IMAGING SYSTEMS</div>
  <div class="pacs-loader-center">
    <div class="ring ring-1"></div><div class="ring ring-2"></div><div class="ring ring-3"></div><div class="ring ring-4"></div><div class="sonar"></div><div class="scan-beam"></div>
    <div class="logo-wrap"><img id="pacs-splash-logo" src="assets/images/logo.png" alt="Logo"></div>
  </div>
  <div class="pacs-pipeline-wrap">
    <svg width="280" height="60" viewBox="0 0 280 60">
      <circle cx="140" cy="30" r="3" fill="var(--c-accent)" class="pipeline-beacon"/>
      <line x1="0" y1="12" x2="280" y2="12" stroke="rgba(var(--c-accent-rgb),0.08)" stroke-width="2"/>
      <rect class="data-pulse pulse-a" x="-30" y="9" width="30" height="6" rx="3" fill="#59a0e6"/>
      <line x1="0" y1="24" x2="280" y2="24" stroke="rgba(var(--c-accent-rgb),0.08)" stroke-width="2"/>
      <rect class="data-pulse pulse-b" x="-20" y="21" width="20" height="6" rx="3" fill="var(--c-accent)"/>
      <line x1="0" y1="36" x2="280" y2="36" stroke="rgba(var(--c-accent-rgb),0.08)" stroke-width="2"/>
      <rect class="data-pulse pulse-c" x="-40" y="33" width="40" height="6" rx="3" fill="#59a0e6"/>
      <line x1="0" y1="48" x2="280" y2="48" stroke="rgba(var(--c-accent-rgb),0.08)" stroke-width="2"/>
      <rect class="data-pulse pulse-d" x="-25" y="45" width="25" height="6" rx="3" fill="var(--c-accent)"/>
    </svg>
  </div>
  <div class="pacs-loader-text">
    <span id="pacs-splash-title">PACS IMAGING SYSTEM</span>
    <span id="pacs-splash-status">[PACS_NET] CONNECTING IMAGE PIPELINE...</span>
  </div>
  <div class="pacs-progress-wrap">
    <div class="pacs-progress-meta"><span>NETWORK IO STATUS:</span><span id="pacs-splash-percentage">0%</span></div>
    <div class="pacs-progress-bar"><div id="pacs-splash-progress"></div><div class="pacs-progress-shine"></div></div>
  </div>
</div>

<div id="viewerShell" class="viewer-shell">
  <header class="viewer-header">
    <div class="vh-left">
      <div class="meas-dropdown-wrap" id="themeDropdownWrap">
        <button class="tool-btn" id="btnTheme" title="Select Theme">
          <i class="fas fa-palette"></i>
          <i class="fas fa-caret-down" style="font-size:10px;margin-left:2px;"></i>
        </button>
        <div class="meas-dropdown-menu" id="themeDropdownMenu" style="min-width: 200px;">
          <div class="meas-menu-title">SELECT THEME</div>
          <button class="meas-item theme-item active" data-theme="blue">
            <span class="theme-color-dot" style="background:#366fd1;"></span>
            <span class="meas-name">Midnight Blue</span>
          </button>
          <button class="meas-item theme-item" data-theme="green">
            <span class="theme-color-dot" style="background:#3CD08F;"></span>
            <span class="meas-name">Carbon Green</span>
          </button>
          <button class="meas-item theme-item" data-theme="purple">
            <span class="theme-color-dot" style="background:#9d4edd;"></span>
            <span class="meas-name">Royal Purple</span>
          </button>
          <button class="meas-item theme-item" data-theme="crimson">
            <span class="theme-color-dot" style="background:#ff4d6d;"></span>
            <span class="meas-name">Crimson Red</span>
          </button>
        </div>
      </div>
    </div>

    <div class="vh-tools" id="toolbarMain">
      <!-- Reports Dropdown List Button (Moved before playback) -->
      <?php if (!empty($reports)): ?>
        <div class="meas-dropdown-wrap" id="reportsDropdownWrap">
          <button class="tool-btn report-highlight-btn" id="btnReports" title="View Reports">
            <i class="fas fa-file-medical"></i>
            <i class="fas fa-caret-down" style="font-size:10px;margin-left:2px;"></i>
          </button>
          <div class="meas-dropdown-menu" id="reportsDropdownMenu" style="min-width: 250px;">
            <div class="meas-menu-title">PATIENT REPORTS</div>
            <?php foreach ($reports as $rp): ?>
              <button class="meas-item" onclick="openReportModal(<?= (int)$rp['id'] ?>); document.getElementById('reportsDropdownMenu').classList.remove('open');">
                <span class="meas-icon"><i class="fas fa-file-lines"></i></span>
                <span class="meas-name">Report (<?= date('d M Y', strtotime($rp['created_at'] ?? 'now')) ?>)</span>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="tool-sep"></div>

      <div class="tool-group">
        <button class="tool-btn" id="btnCinePlay"><i class="fas fa-play"></i></button>
        <input type="range" id="cineSpeed" min="1" max="60" value="15" class="tool-slider">
        <span class="tool-fps" id="cineFpsLabel">15 fps</span>
      </div>
      <div class="tool-sep"></div>

      <div class="meas-dropdown-wrap" id="layoutDropdownWrap">
        <button class="tool-btn active" id="btnLayout" title="Layout Grid">
          <i class="fas fa-th-large"></i>
          <i class="fas fa-caret-down" style="font-size:10px;margin-left:2px;"></i>
        </button>
        <div class="meas-dropdown-menu" id="layoutDropdownMenu" style="min-width: 150px;">
          <div class="meas-menu-title">LAYOUT GRID</div>
          <button class="meas-item active" data-layout="1x1"><span class="meas-icon"><i class="fas fa-square"></i></span><span class="meas-name">1 x 1</span></button>
          <button class="meas-item" data-layout="1x2"><span class="meas-icon"><span class="layout-icon">1×2</span></span><span class="meas-name">1 x 2</span></button>
          <button class="meas-item" data-layout="2x2"><span class="meas-icon"><span class="layout-icon">2×2</span></span><span class="meas-name">2 x 2</span></button>
          <button class="meas-item" data-layout="1x3"><span class="meas-icon"><span class="layout-icon">1×3</span></span><span class="meas-name">1 x 3</span></button>
        </div>
      </div>
      <button class="tool-btn" id="btn1x1" style="display:none"></button>
      <button class="tool-btn" id="btn1x2" style="display:none"></button>
      <button class="tool-btn" id="btn2x2" style="display:none"></button>
      <button class="tool-btn" id="btn1x3" style="display:none"></button>

      <div class="tool-sep"></div>

      <div class="tool-group">
        <button class="tool-btn" data-tool="Pan" title="Pan (Drag to Move)"><i class="fas fa-hand-paper"></i></button>
        <button class="tool-btn" data-tool="Zoom" title="Zoom"><i class="fas fa-search-plus"></i></button>
        <button class="tool-btn" data-tool="Wwwc" title="Window Width/Level"><i class="fas fa-adjust"></i></button>
        <button class="tool-btn" data-tool="StackScroll" title="Stack Scroll"><i class="fas fa-layer-group"></i></button>
      </div>
      <div class="tool-sep"></div>

      <div class="tool-group">
        <div class="meas-dropdown-wrap" id="measDropdownWrap">
          <button class="tool-btn active" id="btnMeasure" title="Measurement Tools">
            <i class="fas fa-ruler-combined"></i>
            <i class="fas fa-caret-down" style="font-size:10px;margin-left:2px;"></i>
          </button>
          <div class="meas-dropdown-menu" id="measDropdownMenu">
            <div class="meas-menu-title">MEASUREMENTS</div>
            <button class="meas-item" data-tool="Length"><span class="meas-icon"><i class="fas fa-ruler"></i></span><span class="meas-name">Line</span><span class="meas-key">L</span></button>
            <button class="meas-item" data-tool="Angle"><span class="meas-icon"><i class="fas fa-drafting-compass"></i></span><span class="meas-name">Angle</span><span class="meas-key">A</span></button>
            <button class="meas-item" data-tool="CobbAngle"><span class="meas-icon"><i class="fas fa-angle-right"></i></span><span class="meas-name">Cobb Angle</span><span class="meas-key">C</span></button>
            <button class="meas-item" data-tool="Polyline"><span class="meas-icon"><i class="fas fa-draw-polygon"></i></span><span class="meas-name">Polyline</span><span class="meas-key">P</span></button>
            <button class="meas-item" data-tool="Bidirectional"><span class="meas-icon"><i class="fas fa-up-right-and-down-left-from-center"></i></span><span class="meas-name">Bidirectional</span><span class="meas-key">B</span></button>
            <div class="meas-menu-divider"></div>
            <button class="meas-item" data-tool="RectangleRoi"><span class="meas-icon"><i class="far fa-square"></i></span><span class="meas-name">Rectangle</span><span class="meas-key">R</span></button>
            <button class="meas-item" data-tool="EllipticalRoi"><span class="meas-icon"><i class="far fa-circle"></i></span><span class="meas-name">Ellipse</span><span class="meas-key">E</span></button>
            <button class="meas-item" data-tool="FreehandRoi"><span class="meas-icon"><i class="fas fa-pencil"></i></span><span class="meas-name">Area (Freehand)</span><span class="meas-key">F</span></button>
            <button class="meas-item" data-tool="Volume"><span class="meas-icon"><i class="fas fa-cube"></i></span><span class="meas-name">Volume</span><span class="meas-key">V</span></button>
            <div class="meas-menu-divider"></div>
            <button class="meas-item" data-tool="Magnify"><span class="meas-icon"><i class="fas fa-search-location"></i></span><span class="meas-name">Magnify Lens</span><span class="meas-key">M</span></button>
            <button class="meas-item" data-tool="Probe"><span class="meas-icon"><i class="fas fa-crosshairs"></i></span><span class="meas-name">Intensity (Probe)</span><span class="meas-key">I</span></button>
            <button class="meas-item" data-tool="TextMarker"><span class="meas-icon"><i class="fas fa-font"></i></span><span class="meas-name">Text</span><span class="meas-key">T</span></button>
            <button class="meas-item" data-tool="ArrowAnnotate"><span class="meas-icon"><i class="fas fa-arrow-right"></i></span><span class="meas-name">Arrow Annotation</span><span class="meas-key">→</span></button>
            <div class="meas-menu-divider"></div>
            <button class="meas-item meas-clear" id="btnClearMeasurements"><span class="meas-icon"><i class="fas fa-trash"></i></span><span class="meas-name">Clear All</span><span class="meas-key">×</span></button>
          </div>
        </div>

        <div class="meas-dropdown-wrap" id="settingsDropdownWrap">
          <button class="tool-btn" id="btnSettings" title="Viewer Settings">
            <i class="fas fa-cog"></i>
            <i class="fas fa-caret-down" style="font-size:10px;margin-left:2px;"></i>
          </button>
          <div class="meas-dropdown-menu" id="settingsDropdownMenu" style="min-width: 300px;">
            <div class="meas-menu-title">REFERENCE LINE SETTINGS</div>
            <div class="settings-item">
              <input type="checkbox" id="optLessStrict">
              <label for="optLessStrict">Less strict reference line mode</label>
            </div>
            <div class="settings-item">
              <input type="checkbox" id="optFirstLast">
              <label for="optFirstLast">Show for the first and last images in the series</label>
            </div>
          </div>
        </div>

        <div class="meas-dropdown-wrap" id="fusionDropdownWrap">
          <button class="tool-btn" id="btnFusion" title="PET-CT Fusion">
            <i class="fas fa-radiation"></i>
            <i class="fas fa-caret-down" style="font-size:10px;margin-left:2px;"></i>
          </button>
          <div class="meas-dropdown-menu fusion-dropdown-menu" id="fusionDropdownMenu">
            <div class="meas-menu-title">PET-CT FUSION PREVIEW</div>

            <div class="fusion-no-pet-warning" id="fusionNoPetWarning" style="display:none;">
              <i class="fas fa-triangle-exclamation"></i>
              <span>No PET series detected in this study — every series below is tagged as CT. Fusion needs a PET series to overlay; picking two CT series won't produce a colored overlay.</span>
            </div>

            <div class="fusion-select-row">
              <label for="fusionCtSelect">CT Series</label>
              <select id="fusionCtSelect"></select>
            </div>
            <div class="fusion-select-row">
              <label for="fusionPetSelect">PET Series</label>
              <select id="fusionPetSelect"></select>
            </div>

            <div class="meas-menu-divider"></div>

            <div class="fusion-mode-row">
              <button type="button" class="fusion-mode-btn" data-mode="ct">CT</button>
              <button type="button" class="fusion-mode-btn" data-mode="pet">PET</button>
              <button type="button" class="fusion-mode-btn active" data-mode="fusion">Fusion</button>
            </div>

            <div class="fusion-slider-row">
              <span class="fusion-slider-label"><span>PET Opacity</span><span id="fusionOpacityVal">50%</span></span>
              <input type="range" id="fusionOpacity" min="0" max="100" value="50">
            </div>

            <div class="fusion-slider-row">
              <span class="fusion-slider-label"><span>PET Window (SUV-like)</span><span id="fusionWindowVal">0–5</span></span>
              <input type="range" id="fusionWindow" min="1" max="20" value="5" step="0.5">
            </div>

            <div class="fusion-cmap-grid">
              <div class="fusion-cmap selected" data-map="hotiron" title="Hot Iron"></div>
              <div class="fusion-cmap" data-map="rainbow" title="Rainbow"></div>
              <div class="fusion-cmap" data-map="pet" title="PET"></div>
              <div class="fusion-cmap" data-map="grayscale" title="Grayscale"></div>
            </div>

            <div class="settings-item" style="padding:6px 12px 10px;">
              <input type="checkbox" id="fusionEnableToggle">
              <label for="fusionEnableToggle">Enable fusion overlay on active viewport</label>
            </div>
          </div>
        </div>
      </div>
      
      <div class="tool-sep"></div>

      <div class="tool-group">
        <button class="tool-btn" id="btnScreenshot" title="Capture Screenshot"><i class="fas fa-camera"></i></button>
        <button class="tool-btn" id="btnInvert"><i class="fas fa-circle-half-stroke"></i></button>
        <button class="tool-btn" id="btnRotateL"><i class="fas fa-rotate-left"></i></button>
        <button class="tool-btn" id="btnRotateR"><i class="fas fa-rotate-right"></i></button>
        <button class="tool-btn" id="btnFlipH"><i class="fas fa-arrows-left-right"></i></button>
        <button class="tool-btn" id="btnFlipV"><i class="fas fa-arrows-up-down"></i></button>
        <button class="tool-btn" id="btnReset" data-tool="Reset"><i class="fas fa-undo"></i></button>
      </div>
    </div>

    <div class="vh-right">
      <button class="tool-btn" id="btnModeToggle" title="Switch to light mode">
        <i class="fas fa-sun"></i>
      </button>
      <div class="image-counter"><span id="imgCurrent">1</span> / <span id="imgTotal">0</span></div>
    </div>
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
          <canvas class="fusion-overlay-canvas" id="fusionCanvas0"></canvas>
          <div class="fusion-viewport-badge" id="fusionBadge0"><i class="fas fa-radiation"></i><span>PET-CT Fusion</span></div>
          <div class="dicom-overlay top-left" id="ovTL0"></div>
          <div class="dicom-overlay top-right" id="ovTR0"></div>
          <div class="vp-loading" id="vpLoading0"><span>Loading…</span></div>
        </div>
        <div class="viewport-cell" id="vp1" style="display:none">
          <div class="cornerstone-element" id="csElement1"></div>
          <canvas class="fusion-overlay-canvas" id="fusionCanvas1"></canvas>
          <div class="fusion-viewport-badge" id="fusionBadge1"><i class="fas fa-radiation"></i><span>PET-CT Fusion</span></div>
          <div class="dicom-overlay top-left" id="ovTL1"></div>
          <div class="dicom-overlay top-right" id="ovTR1"></div>
          <div class="vp-loading" id="vpLoading1"><span>Loading…</span></div>
        </div>
        <div class="viewport-cell" id="vp2" style="display:none">
          <div class="cornerstone-element" id="csElement2"></div>
          <canvas class="fusion-overlay-canvas" id="fusionCanvas2"></canvas>
          <div class="fusion-viewport-badge" id="fusionBadge2"><i class="fas fa-radiation"></i><span>PET-CT Fusion</span></div>
          <div class="dicom-overlay top-left" id="ovTL2"></div>
          <div class="dicom-overlay top-right" id="ovTR2"></div>
          <div class="vp-loading" id="vpLoading2"><span>Loading…</span></div>
        </div>
        <div class="viewport-cell" id="vp3" style="display:none">
          <div class="cornerstone-element" id="csElement3"></div>
          <canvas class="fusion-overlay-canvas" id="fusionCanvas3"></canvas>
          <div class="fusion-viewport-badge" id="fusionBadge3"><i class="fas fa-radiation"></i><span>PET-CT Fusion</span></div>
          <div class="dicom-overlay top-left" id="ovTL3"></div>
          <div class="dicom-overlay top-right" id="ovTR3"></div>
          <div class="vp-loading" id="vpLoading3"><span>Loading…</span></div>
        </div>
      </div>
      
      <div class="scroll-bar-track">
        <input type="range" id="imageScroller" class="image-scroller" min="0" value="0">
      </div>
    </div>
    
    <aside class="info-panel"><div class="panel-header"><span>Measurements</span></div><div class="meas-list" id="measurementList"><div class="meas-empty">No measurements yet.</div></div></aside>
  </div>
</div>

<!-- Small Background Preloader Bar -->
<div id="bgLoader">
  <div id="bgLoaderBar"></div>
</div>

<!-- Toast Notification Element -->
<div id="toastNotification" class="toast-notification">
  <i class="fas fa-check-circle"></i>
  <span id="toastMessage">Screenshot copied! Paste (Ctrl+V) anywhere.</span>
</div>

<div class="report-modal-overlay" id="reportModalOverlay" onclick="closeReportModal(event)">
  <div class="report-modal-box">
    <div class="modal-top-actions">
      <button class="modal-action-btn" onclick="window.print()">
        <i class="fas fa-print"></i> Print
      </button>
      <button class="modal-action-btn" onclick="closeReportModal(event, true)">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
    <div class="report-paper">
      <table class="report-print-table">
        <thead>
          <tr><td>
            <div class="report-header">
              <div class="clinic-logo-area">
                <i class="fas fa-hospital" style="color:#3CD08F;"></i>
                <span>RAVCO POLYCLINIC</span>
              </div>
              <div class="report-patient-demographics">
                <span class="demo-label">Patient:</span>
                <span class="demo-value"><?= htmlspecialchars($study['patient_name'] ?? '—') ?></span>
                <span class="demo-label">Age/Sex:</span>
                <span class="demo-value"><?= htmlspecialchars($patientAge) ?> / <?= htmlspecialchars($study['sex'] ?? '—') ?></span>
                <span class="demo-label">Patient ID:</span>
                <span class="demo-value"><?= htmlspecialchars($study['pid'] ?? '—') ?></span>
                <span class="demo-label">Date:</span>
                <span class="demo-value" id="reportModalDate">—</span>
              </div>
            </div>
          </td></tr>
        </thead>
    
        <tbody>
          <tr><td>
            <div class="report-body" id="reportModalBody"></div>
            <div style="padding: 0 30px 20px;">
              <div class="signatures">
                <div class="sig-block"><div class="sig-line"></div><div class="sig-name">Radiologist Signature</div></div>
                <div class="sig-block"><div class="sig-line"></div><div class="sig-name">Referring Physician</div></div>
              </div>
            </div>
          </td></tr>
        </tbody>
    
        <tfoot>
          <tr><td>
            <div class="report-footer">Ravco Polyclinic | Tel: +XXX-XXX-XXXX | Address Here</div>
          </td></tr>
        </tfoot>
      </table>
    </div>
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
function openReportModal(reportId) {
  const sourceDiv = document.getElementById('reportData' + reportId);
  const modalBody = document.getElementById('reportModalBody');
  const overlay = document.getElementById('reportModalOverlay');
  if (!sourceDiv || !modalBody || !overlay) return;
  modalBody.innerHTML = sourceDiv.innerHTML;
  document.getElementById('reportModalDate').textContent = new Date().toLocaleDateString();
  overlay.style.display = 'flex';
  overlay.style.pointerEvents = 'auto';
  overlay.classList.add('active');
  document.body.style.overflow = 'hidden';
}
function closeReportModal(event, forceClose = false) {
  const overlay = document.getElementById('reportModalOverlay');
  if (!overlay) return;
  if (forceClose || event.target === overlay) {
    overlay.classList.remove('active');
    overlay.style.pointerEvents = 'none';
    overlay.style.display = 'none';
    document.body.style.overflow = '';
  }
}
</script>

<script src="assets/js/viewer.js?v=4f-pacs-38"></script>

<!-- ============================================================
     SCRIPT 1: UI DROPDOWN LOGIC & THEME SWITCHER & VOICE
     ============================================================ -->
<script>
// 1. Welcome Voice Message
function playWelcomeVoice() {
  if ('speechSynthesis' in window) {
    const synth = window.speechSynthesis;
    const utterance = new SpeechSynthesisUtterance("Welcome to Ravco polyclinic center");
    utterance.rate = 0.9;  
    utterance.pitch = 0.8; 
    
    const voices = synth.getVoices();
    const maleVoice = voices.find(v => 
      v.name.toLowerCase().includes('male') || 
      v.name.includes('Daniel') || 
      v.name.includes('Alex') || 
      v.name.includes('Google UK English Male') ||
      v.name.includes('Microsoft David')
    );
    
    if (maleVoice) utterance.voice = maleVoice;
    synth.speak(utterance);
  }
}

playWelcomeVoice();
if ('speechSynthesis' in window) {
  window.speechSynthesis.onvoiceschanged = playWelcomeVoice;
}

// 2. Dropdown Logic
function setupDropdown(btnId, wrapId, menuId) {
  const btn = document.getElementById(btnId);
  const wrap = document.getElementById(wrapId);
  const menu = document.getElementById(menuId);
  if (!btn || !menu) return;

  function positionMenu() {
    const rect = btn.getBoundingClientRect();
    menu.style.top = (rect.bottom + 6) + 'px';
    menu.style.left = rect.left + 'px';
  }

  function toggleDropdown(force) {
    const open = (force === undefined) ? !menu.classList.contains('open') : force;
    if (open) positionMenu();
    menu.classList.toggle('open', open);
  }

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    toggleDropdown();
  });

  document.addEventListener('click', (e) => {
    if (!wrap.contains(e.target) && !menu.contains(e.target)) toggleDropdown(false);
  });

  window.addEventListener('resize', () => { if (menu.classList.contains('open')) positionMenu(); });
  window.addEventListener('scroll', () => { if (menu.classList.contains('open')) positionMenu(); }, true);
}

setupDropdown('btnMeasure', 'measDropdownWrap', 'measDropdownMenu');
setupDropdown('btnSettings', 'settingsDropdownWrap', 'settingsDropdownMenu');
setupDropdown('btnTheme', 'themeDropdownWrap', 'themeDropdownMenu');
setupDropdown('btnLayout', 'layoutDropdownWrap', 'layoutDropdownMenu');
setupDropdown('btnReports', 'reportsDropdownWrap', 'reportsDropdownMenu'); 
setupDropdown('btnFusion', 'fusionDropdownWrap', 'fusionDropdownMenu');

// 3. Theme Switcher Logic
const themeItems = document.querySelectorAll('.theme-item');

const savedTheme = localStorage.getItem('dicom-theme') || 'blue';
document.body.setAttribute('data-theme', savedTheme);
themeItems.forEach(item => {
  if (item.dataset.theme === savedTheme) item.classList.add('active');
  else item.classList.remove('active');
});

themeItems.forEach(item => {
  item.addEventListener('click', () => {
    const theme = item.dataset.theme;
    document.body.setAttribute('data-theme', theme);
    localStorage.setItem('dicom-theme', theme);
    
    themeItems.forEach(i => i.classList.remove('active'));
    item.classList.add('active');
    
    const menu = document.getElementById('themeDropdownMenu');
    if (menu) menu.classList.remove('open');
  });
});

// 4. Layout Dropdown Logic
document.querySelectorAll('.meas-item[data-layout]').forEach(item => {
  item.addEventListener('click', () => {
    const layout = item.dataset.layout;
    const btn = document.getElementById('btn' + layout);
    if (btn) btn.click(); 
    
    const menu = document.getElementById('layoutDropdownMenu');
    if (menu) menu.classList.remove('open');
    
    document.querySelectorAll('.meas-item[data-layout]').forEach(i => i.classList.remove('active'));
    item.classList.add('active');
  });
});

// 5. Background Preloader Logic (Preloads 1st Series, then all remaining)
(function() {
    let preloadingStarted = false;
    const isMobile = window.matchMedia("(max-width: 900px)").matches;
    
    function startBackgroundPreload() {
        if (preloadingStarted) return;
        
        const cs = window.cornerstone;
        const studyData = window.DICOM_STUDY;
        if (!cs || !studyData || !studyData.series || studyData.series.length === 0) {
            setTimeout(startBackgroundPreload, 500);
            return;
        }
        
        const vp0 = document.getElementById('csElement0');
        if (!vp0) {
            setTimeout(startBackgroundPreload, 500);
            return;
        }
        
        let enabledElement;
        try {
            enabledElement = cs.getEnabledElement(vp0);
        } catch(e) {
            setTimeout(startBackgroundPreload, 500);
            return;
        }
        
        if (!enabledElement || !enabledElement.image || !enabledElement.image.imageId) {
            setTimeout(startBackgroundPreload, 500);
            return;
        }
        
        preloadingStarted = true;
        
        const firstImageId = enabledElement.image.imageId;
        const firstInstId = String(studyData.series[0].instances[0].id);
        
        // Robust URL template deduction
        const placeholder = '__INST_ID__';
        const urlTemplate = firstImageId.replace(firstInstId, placeholder);
        const parts = urlTemplate.split(placeholder);
        
        if (parts.length !== 2) {
            console.error('[Preloader] Could not deduce URL pattern. Aborting.', firstImageId, firstInstId);
            return;
        }
        
        const urlPrefix = parts[0];
        const urlSuffix = parts[1];
        
        const idsToPreload = [];
        
        // 1. Add ALL remaining images of the FIRST series
        if (studyData.series[0] && studyData.series[0].instances) {
            studyData.series[0].instances.forEach(inst => {
                if (String(inst.id) !== firstInstId) {
                    idsToPreload.push(urlPrefix + String(inst.id) + urlSuffix);
                }
            });
        }
        
        // 2. Add ALL images for ALL remaining series
        studyData.series.forEach((series, sIndex) => {
            if (sIndex > 0 && series.instances && series.instances.length > 0) {
                series.instances.forEach(inst => {
                    idsToPreload.push(urlPrefix + String(inst.id) + urlSuffix);
                });
            }
        });
        
        if (idsToPreload.length === 0) {
            console.log('[Preloader] No additional images to preload.');
            return;
        }
        
        const loaderEl = document.getElementById('bgLoader');
        const barEl = document.getElementById('bgLoaderBar');
        loaderEl.style.display = 'block';
        loaderEl.style.opacity = '1';
        barEl.style.width = '0%';
        
        let loadedCount = 0;
        const totalCount = idsToPreload.length;
        let currentIndex = 0;
        
        // Mobile loads 1 at a time with a 150ms delay to prevent freezing.
        // Desktop loads 4 at a time with a 50ms delay.
        const batchSize = isMobile ? 1 : 4;
        const batchDelay = isMobile ? 150 : 50;
        
        function loadBatch() {
            if (currentIndex >= totalCount) {
                setTimeout(() => {
                    loaderEl.style.opacity = '0';
                    setTimeout(() => loaderEl.style.display = 'none', 500);
                }, 1000);
                return;
            }
            
            const promises = [];
            for (let i = 0; i < batchSize && currentIndex < totalCount; i++) {
                const imgId = idsToPreload[currentIndex];
                promises.push(
                    cs.loadAndCacheImage(imgId)
                        .then(() => {
                            loadedCount++;
                            const progress = (loadedCount / totalCount) * 100;
                            barEl.style.width = progress + '%';
                        })
                        .catch(err => {
                            loadedCount++;
                            const progress = (loadedCount / totalCount) * 100;
                            barEl.style.width = progress + '%';
                        })
                );
                currentIndex++;
            }
            
            Promise.all(promises).then(() => {
                if (window.requestIdleCallback) {
                    window.requestIdleCallback(() => setTimeout(loadBatch, batchDelay), { timeout: 1000 });
                } else {
                    setTimeout(loadBatch, batchDelay);
                }
            });
        }
        
        loadBatch();
    }
    
    // Start polling immediately. It will wait for the first image to be ready.
    setTimeout(startBackgroundPreload, 100);
})();
</script>

<!-- ============================================================
     SCRIPT 2: CORNERSTONE LOGIC (Measurement Tools, Magnify)
     ============================================================ -->
<script>
(function () {
  'use strict';

  const cs   = window.cornerstone;
  const cst  = window.cornerstoneTools;
  if (!cs || !cst) {
    console.error('[Viewer] cornerstone or cornerstoneTools not loaded');
    return;
  }

  try { if (typeof cst.init === 'function') cst.init(); } catch (e) {}

  const state = {
    activeTool: 'Wwwc',
    settings: {
      lessStrictReferenceLineMode: false,
      showFirstAndLastImages: false
    }
  };

  window.DICOM_VIEWER_EXT = { state };

  function getViewportElements() {
    return [0,1,2,3].map(i => document.getElementById('csElement' + i))
                    .filter(el => el && el.style.display !== 'none');
  }

  const TOOL_MAP = {
    Length: 'LengthTool', Angle: 'AngleTool', CobbAngle: 'CobbAngleTool',
    Polyline: 'FreehandRoiTool', Bidirectional: 'BidirectionalTool',
    RectangleRoi: 'RectangleRoiTool', EllipticalRoi: 'EllipticalRoiTool',
    FreehandRoi: 'FreehandRoiTool', Volume: 'EllipticalRoiTool',
    Probe: 'ProbeTool',
    TextMarker: 'TextMarkerTool', ArrowAnnotate: 'ArrowAnnotateTool',
    Pan: 'PanTool', Zoom: 'ZoomTool', Wwwc: 'WwwcTool', StackScroll: 'StackScrollTool',
    Reset: null
  };

  const REQUIRED_TOOLS = Object.keys(TOOL_MAP).filter(k => TOOL_MAP[k] !== null);

  function ensureToolsRegistered() {
    const elements = getViewportElements();
    if (!elements.length) return;
    REQUIRED_TOOLS.forEach(name => {
      const className = TOOL_MAP[name];
      const ToolClass = cst[className];
      if (!ToolClass) return;
      elements.forEach(el => {
        try {
          if (typeof cst.addToolForElement === 'function') cst.addToolForElement(el, ToolClass, { name });
          else if (typeof cst.addTool === 'function') cst.addTool(ToolClass, { name });
        } catch (err) {}
      });
    });
  }

  function setActiveTool(toolName) {
    if (toolName === 'Reset') { resetAllViewports(); return; }
    
    if (state.activeTool === 'Magnify' && toolName !== 'Magnify') {
      deactivateCustomMagnify();
    }
    
    if (toolName === 'Magnify') {
      const elements = getViewportElements();
      elements.forEach(el => {
        REQUIRED_TOOLS.forEach(t => {
          try { cst.setToolDisabledForElement(el, t); } catch(e){}
        });
      });
      
      state.activeTool = 'Magnify';
      activateCustomMagnify();
      
      document.querySelectorAll('.meas-item').forEach(b => b.classList.toggle('active', b.dataset.tool === toolName));
      document.querySelectorAll('.tool-btn[data-tool]').forEach(b => b.classList.toggle('active', b.dataset.tool === toolName));
      const btnMeasure = document.getElementById('btnMeasure');
      if (btnMeasure) btnMeasure.classList.toggle('active', true);
      const dropdownMenu = document.getElementById('measDropdownMenu');
      if (dropdownMenu) dropdownMenu.classList.remove('open');
      return;
    }

    state.activeTool = toolName;
    ensureToolsRegistered();

    const elements = getViewportElements();
    elements.forEach(el => {
      REQUIRED_TOOLS.forEach(t => {
        try { cst.setToolDisabledForElement(el, t); } catch(e){}
        try { cst.setToolPassiveForElement(el, t); } catch(e){}
      });
    });

    elements.forEach(el => {
      try { cst.setToolActiveForElement(el, toolName, { mouseButtonMask: 1 }); } catch (err) {}
    });

    elements.forEach(el => {
      try { cst.setToolActiveForElement(el, 'Pan', { mouseButtonMask: 4 }); } catch(e){}
      try { cst.setToolActiveForElement(el, 'Zoom', { mouseButtonMask: 2 }); } catch(e){}
    });

    document.querySelectorAll('.meas-item').forEach(b => b.classList.toggle('active', b.dataset.tool === toolName));
    document.querySelectorAll('.tool-btn[data-tool]').forEach(b => b.classList.toggle('active', b.dataset.tool === toolName));
    const btnMeasure = document.getElementById('btnMeasure');
    if (btnMeasure) btnMeasure.classList.toggle('active', !!TOOL_MAP[toolName]);

    const dropdownMenu = document.getElementById('measDropdownMenu');
    if (dropdownMenu) dropdownMenu.classList.remove('open');
  }

  document.querySelectorAll('.meas-item[data-tool]').forEach(item => {
    item.addEventListener('click', () => {
      const tool = item.dataset.tool;
      if (tool === 'Volume') setActiveTool('EllipticalRoi');
      else setActiveTool(tool);
    });
  });

  document.getElementById('btnClearMeasurements').addEventListener('click', () => {
    getViewportElements().forEach(el => {
      REQUIRED_TOOLS.forEach(t => { try { cst.clearToolState(el, t); } catch(e){} });
      cs.updateImage(el);
    });
    const measList = document.getElementById('measurementList');
    if (measList) measList.innerHTML = '<div class="meas-empty">No measurements yet.</div>';
    const dropdownMenu = document.getElementById('measDropdownMenu');
    if (dropdownMenu) dropdownMenu.classList.remove('open');
  });

  const KEY_MAP = { l: 'Length', a: 'Angle', c: 'CobbAngle', p: 'Polyline', b: 'Bidirectional', r: 'RectangleRoi', e: 'EllipticalRoi', f: 'FreehandRoi', v: 'Volume', i: 'Probe', t: 'TextMarker', m: 'Magnify' };
  document.addEventListener('keydown', (e) => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    const k = (e.key || '').toLowerCase();
    if (KEY_MAP[k]) { e.preventDefault(); setActiveTool(KEY_MAP[k]); }
  });

  function resetAllViewports() {
    getViewportElements().forEach(el => {
      try { cs.reset(el); cs.updateImage(el); } catch (e) {}
    });
  }

  /* ============================================================
     2) SETTINGS (Reference Lines)
     ============================================================ */
  const optLessStrict = document.getElementById('optLessStrict');
  const optFirstLast = document.getElementById('optFirstLast');

  if (optLessStrict) {
    optLessStrict.addEventListener('change', (e) => {
      state.settings.lessStrictReferenceLineMode = e.target.checked;
      console.log('[Viewer] Less strict reference line mode:', e.target.checked);
    });
  }

  if (optFirstLast) {
    optFirstLast.addEventListener('change', (e) => {
      state.settings.showFirstAndLastImages = e.target.checked;
      console.log('[Viewer] Show for the first and last images in the series:', e.target.checked);
    });
  }

  /* ============================================================
     3) SCREENSHOT TOOL (Background Clipboard Copy & Flash)
     ============================================================ */
  function showToast(msg) {
    const toast = document.getElementById('toastNotification');
    document.getElementById('toastMessage').textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
  }

  const btnScreenshot = document.getElementById('btnScreenshot');
  if (btnScreenshot) {
    btnScreenshot.addEventListener('click', () => {
      const activeCell = document.querySelector('.viewport-cell.active-cell');
      if (!activeCell) return;
      const canvas = activeCell.querySelector('canvas');
      if (!canvas) return;

      const flash = document.createElement('div');
      flash.className = 'screenshot-flash';
      activeCell.appendChild(flash);
      void flash.offsetWidth; 
      flash.classList.add('flash');
      
      setTimeout(() => {
        if (flash.parentNode) flash.parentNode.removeChild(flash);
      }, 400);

      try {
        canvas.toBlob((blob) => {
          if (!blob) {
            showToast('Error capturing screenshot');
            return;
          }
          
          const item = new ClipboardItem({ 'image/png': blob });
          navigator.clipboard.write([item]).then(() => {
            showToast('Screenshot copied! Paste (Ctrl+V) anywhere.');
          }).catch(err => {
            console.error('Clipboard copy failed:', err);
            showToast('Error copying to clipboard');
          });
        }, 'image/png');
      } catch (e) {
        console.error('Screenshot capture failed:', e);
        showToast('Error capturing screenshot');
      }
    });
  }

  /* ============================================================
     4) CUSTOM MAGNIFY LENS
     ============================================================ */
  const magnifyLens = document.createElement('div');
  magnifyLens.id = 'customMagnify';
  magnifyLens.innerHTML = '<canvas id="magnifyCanvas" width="160" height="160"></canvas>';
  document.body.appendChild(magnifyLens);
  const magnifyCanvas = document.getElementById('magnifyCanvas');
  const magCtx = magnifyCanvas.getContext('2d');

  function customMagnifyMouseMove(e) {
    const activeCell = document.querySelector('.viewport-cell.active-cell');
    if (!activeCell) return;
    const mainCanvas = activeCell.querySelector('canvas');
    if (!mainCanvas) return;

    const rect = mainCanvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    if (x < 0 || y < 0 || x > rect.width || y > rect.height) {
      magnifyLens.style.display = 'none';
      return;
    }

    magnifyLens.style.left = (e.clientX - 80) + 'px';
    magnifyLens.style.top = (e.clientY - 80) + 'px';
    magnifyLens.style.display = 'block';

    const zoom = 2;
    const size = 160;
    const grabSize = size / zoom; 

    const scaleX = mainCanvas.width / rect.width;
    const scaleY = mainCanvas.height / rect.height;

    const sx = (x - grabSize / 2) * scaleX;
    const sy = (y - grabSize / 2) * scaleY;
    const sw = grabSize * scaleX;
    const sh = grabSize * scaleY;

    magCtx.clearRect(0, 0, size, size);
    magCtx.imageSmoothingEnabled = false;
    try {
      magCtx.drawImage(mainCanvas, sx, sy, sw, sh, 0, 0, size, size);
    } catch (err) {}

    magCtx.strokeStyle = 'rgba(255, 0, 0, 0.6)';
    magCtx.lineWidth = 1;
    magCtx.beginPath();
    magCtx.moveTo(size/2, 0);
    magCtx.lineTo(size/2, size);
    magCtx.moveTo(0, size/2);
    magCtx.lineTo(size, size/2);
    magCtx.stroke();
  }

  function activateCustomMagnify() {
    document.addEventListener('mousemove', customMagnifyMouseMove);
  }
  function deactivateCustomMagnify() {
    document.removeEventListener('mousemove', customMagnifyMouseMove);
    magnifyLens.style.display = 'none';
  }

  /* ============================================================
     5) WIRING & MEASUREMENT LIST
     ============================================================ */
  document.querySelectorAll('.tool-btn[data-tool]').forEach(btn => {
    if (btn.id === 'btnReset') return;
    btn.addEventListener('click', () => setActiveTool(btn.dataset.tool));
  });

  document.getElementById('btnReset').addEventListener('click', resetAllViewports);

  ['btn1x1','btn1x2','btn2x2','btn1x3'].forEach(id => {
    const b = document.getElementById(id);
    if (b) b.addEventListener('click', () => {
      setTimeout(() => {
        setActiveTool(state.activeTool);
      }, 120);
    });
  });

  function refreshMeasurementList() {
    const list = document.getElementById('measurementList');
    if (!list) return; 
    const items = [];
    const types = ['Length','Angle','CobbAngle','Bidirectional','RectangleRoi','EllipticalRoi','FreehandRoi','Probe','TextMarker','ArrowAnnotate'];
    getViewportElements().forEach(el => {
      const vpIdx = el.id.replace('csElement','');
      types.forEach(t => {
        let st = null;
        try { st = cst.getToolState(el, t); } catch(e){}
        if (!st || !st.data) return;
        st.data.forEach((d, i) => {
          let value = '';
          if (d.length) value = d.length.toFixed(2) + ' mm';
          else if (d.angle) value = d.angle.toFixed(1) + '°';
          else if (d.mean)  value = 'Mean: ' + (d.mean||0).toFixed(1);
          else if (d.text)  value = '"' + d.text + '"';
          items.push({ vp: vpIdx, type: t, value, idx: i });
        });
      });
    });
    if (!items.length) { list.innerHTML = '<div class="meas-empty">No measurements yet.</div>'; return; }
    list.innerHTML = items.map(it => `<div class="meas-row"><span class="meas-row-type">${it.type}</span><span class="meas-row-val">${it.value}</span><span class="meas-row-vp">VP${it.vp}</span></div>`).join('');
  }

  document.addEventListener('cornerstonetoolsmeasurementcompleted', refreshMeasurementList);
  document.addEventListener('cornerstonetoolsmeasurementadded', refreshMeasurementList);
  document.addEventListener('cornerstonetoolsmeasurementremoved', refreshMeasurementList);

  ensureToolsRegistered();
  console.log('[Viewer] measurement tools + settings + clipboard screenshot + dropdown themes + voice ready');
})();

// 6. Light / Dark Mode Toggle
(function () {
  const btn = document.getElementById('btnModeToggle');
  if (!btn) return;
  const icon = btn.querySelector('i');
  let lastToggle = 0;

  function applyMode(mode) {
    document.documentElement.setAttribute('data-mode', mode);
    document.body.setAttribute('data-mode', mode);
    icon.className = mode === 'light' ? 'fas fa-moon' : 'fas fa-sun';
    btn.title = mode === 'light' ? 'Switch to dark mode' : 'Switch to light mode';
    localStorage.setItem('dicom-mode', mode);

    let meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) {
      meta = document.createElement('meta');
      meta.name = 'theme-color';
      document.head.appendChild(meta);
    }
    meta.content = mode === 'light' ? '#ffffff' : '#121a26';
  }

  function toggleMode(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }

    // swallow the ghost click that follows touchend on mobile
    const now = Date.now();
    if (now - lastToggle < 500) return;
    lastToggle = now;

    const next = document.body.getAttribute('data-mode') === 'light' ? 'dark' : 'light';
    console.log('[Mode] switching to', next);
    applyMode(next);
  }

  applyMode(localStorage.getItem('dicom-mode') || 'dark');

  btn.addEventListener('click', toggleMode);
  btn.addEventListener('touchend', toggleMode, { passive: false });
})();


</script>

<!-- ============================================================
     SCRIPT 3: PET-CT FUSION OVERLAY (preview-grade blending)
     ============================================================
     Renders a colorized PET overlay on top of whichever viewport
     cell is currently active, blended with the underlying CT.
     Slice matching between the two series is done by proportional
     index (CT slice N of M -> PET slice at the same relative
     position) since true geometric registration would require
     slice-location / frame-of-reference metadata this file does
     not expose to the client. Good enough for a quick visual
     preview; not a substitute for a clinical fusion workstation.
     ============================================================ -->
<script>
(function () {
  'use strict';
  const cs = window.cornerstone;
  if (!cs) { console.error('[Fusion] cornerstone not loaded'); return; }

  const LUTS = {
    hotiron:   [[0,0,0],[127,0,0],[255,75,0],[255,206,0],[255,255,255]],
    rainbow:   [[0,0,255],[0,255,255],[0,255,0],[255,255,0],[255,0,0]],
    pet:       [[0,0,48],[58,0,176],[224,0,160],[255,140,0],[255,249,196]],
    grayscale: [[0,0,0],[255,255,255]]
  };
  function lutColor(map, t) {
    const stops = LUTS[map] || LUTS.hotiron;
    const seg = 1 / (stops.length - 1);
    const i = Math.max(0, Math.min(stops.length - 2, Math.floor(t / seg)));
    const localT = (t - i * seg) / seg;
    const [r1,g1,b1] = stops[i], [r2,g2,b2] = stops[i+1];
    return [
      Math.round(r1 + (r2 - r1) * localT),
      Math.round(g1 + (g2 - g1) * localT),
      Math.round(b1 + (b2 - b1) * localT)
    ];
  }

  const fusionState = {
    active: false,
    mode: 'fusion',
    ctSeriesId: null,
    petSeriesId: null,
    opacity: 0.5,
    colormap: 'hotiron',
    windowMax: 5,
    urlPrefix: null,
    urlSuffix: null
  };

  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  }

  function getStudy() { return window.DICOM_STUDY || null; }

  function findSeries(id) {
    const study = getStudy();
    if (!study) return null;
    return (study.series || []).find(s => String(s.id) === String(id)) || null;
  }

  // NOTE: st.modality in the PHP query is study-level, so every series
  // in a study currently reports the same modality string, which means
  // this guess is often wrong or empty for every series. Rather than
  // hiding series the guess doesn't like, both dropdowns below list
  // EVERY series in the study — this function only decides which one
  // to sort to the top as a starting suggestion, never which ones to
  // exclude, so a wrong or missing modality field can never leave a
  // dropdown empty.
  function likelyIsPet(s) {
    const mod = (s.modality || '').toUpperCase();
    const desc = (s.description || '').toUpperCase();
    return mod.indexOf('PT') !== -1 || mod.indexOf('PET') !== -1 || desc.indexOf('PET') !== -1;
  }

  function populateSeriesSelects() {
    const ctSel = document.getElementById('fusionCtSelect');
    const petSel = document.getElementById('fusionPetSelect');
    if (!ctSel || !petSel) return;
    const study = getStudy();
    const all = (study && study.series) ? study.series.slice() : [];
    if (!all.length) {
      ctSel.innerHTML = '<option value="">No series found</option>';
      petSel.innerHTML = '<option value="">No series found</option>';
      return;
    }

    const label = s => escapeHtml(
      (s.description || ('Series ' + (s.number != null ? s.number : s.id))) +
      (s.modality ? ' [' + s.modality + ']' : '')
    );

    // Every series is listed in both dropdowns — sorting only changes
    // which one is picked as the default, never what's selectable.
    const petFirst = all.slice().sort((a, b) => (likelyIsPet(b) ? 1 : 0) - (likelyIsPet(a) ? 1 : 0));
    const ctFirst   = all.slice().sort((a, b) => (likelyIsPet(a) ? 1 : 0) - (likelyIsPet(b) ? 1 : 0));

    const prevCt  = ctSel.value;
    const prevPet = petSel.value;

    ctSel.innerHTML  = ctFirst.map(s => `<option value="${escapeHtml(s.id)}">${label(s)}</option>`).join('');
    petSel.innerHTML = petFirst.map(s => `<option value="${escapeHtml(s.id)}">${label(s)}</option>`).join('');

    // Keep whatever the user had already picked, if it still exists;
    // otherwise fall back to the sorted default (index 0).
    const stillHasCt  = ctFirst.some(s => String(s.id) === String(prevCt));
    const stillHasPet = petFirst.some(s => String(s.id) === String(prevPet));
    ctSel.value  = stillHasCt  ? prevCt  : String(ctFirst[0].id);
    petSel.value = stillHasPet ? prevPet : String(petFirst[0].id);

    fusionState.ctSeriesId  = ctSel.value;
    fusionState.petSeriesId = petSel.value;

    const warning = document.getElementById('fusionNoPetWarning');
    if (warning) warning.style.display = all.some(likelyIsPet) ? 'none' : 'flex';
  }

  // Deduce the image-id URL template by locating a known instance id
  // inside the imageId cornerstone is currently displaying — the same
  // trick the background preloader elsewhere in this file already uses.
  function deduceUrlTemplate() {
    if (fusionState.urlPrefix !== null) return true;
    const el = document.getElementById('csElement0');
    if (!el) return false;
    let enabled;
    try { enabled = cs.getEnabledElement(el); } catch (e) { return false; }
    if (!enabled || !enabled.image || !enabled.image.imageId) return false;

    const imageId = enabled.image.imageId;
    const study = getStudy();
    if (!study) return false;

    let matchId = null;
    outer:
    for (const s of (study.series || [])) {
      for (const inst of (s.instances || [])) {
        if (imageId.indexOf(String(inst.id)) !== -1) { matchId = String(inst.id); break outer; }
      }
    }
    if (!matchId) return false;

    const idx = imageId.indexOf(matchId);
    fusionState.urlPrefix = imageId.slice(0, idx);
    fusionState.urlSuffix = imageId.slice(idx + matchId.length);
    return true;
  }

  function buildImageId(instanceId) {
    return fusionState.urlPrefix + String(instanceId) + fusionState.urlSuffix;
  }

  function getCurrentCtIndex(seriesLength) {
    const counter = document.getElementById('imgCurrent');
    const n = counter ? parseInt(counter.textContent, 10) : NaN;
    if (Number.isFinite(n) && n > 0) return Math.min(n - 1, seriesLength - 1);
    return 0;
  }

  function findActiveCellIndex() {
    const active = document.querySelector('.viewport-cell.active-cell');
    if (!active) return 0;
    const m = active.id.match(/vp(\d+)/);
    return m ? +m[1] : 0;
  }

  function clearOverlay(cellIndex) {
    const canvas = document.getElementById('fusionCanvas' + cellIndex);
    const cell = document.getElementById('vp' + cellIndex);
    const badge = document.getElementById('fusionBadge' + cellIndex);
    if (canvas) {
      const ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
    if (cell) cell.classList.remove('fusion-on');
    if (badge) badge.classList.remove('active');
  }

  function clearAllOverlays() { [0,1,2,3].forEach(clearOverlay); }

  let renderQueued = false;
  function queueRender() {
    if (renderQueued) return;
    renderQueued = true;
    requestAnimationFrame(() => { renderQueued = false; renderFusionOverlay(); });
  }

  function renderFusionOverlay() {
    if (!fusionState.active) return;

    const cellIndex = findActiveCellIndex();
    const mainEl = document.getElementById('csElement' + cellIndex);
    const canvas = document.getElementById('fusionCanvas' + cellIndex);
    const cell = document.getElementById('vp' + cellIndex);
    if (!mainEl || !canvas || !cell) return;

    const mainCanvas = mainEl.querySelector('canvas');
    if (!mainCanvas) return;

    if (!deduceUrlTemplate()) return;

    const ctSeries = findSeries(fusionState.ctSeriesId);
    const petSeries = findSeries(fusionState.petSeriesId);
    if (!ctSeries || !petSeries || !ctSeries.instances.length || !petSeries.instances.length) {
      clearOverlay(cellIndex);
      return;
    }

    const ctIdx = getCurrentCtIndex(ctSeries.instances.length);
    const ratio = ctSeries.instances.length > 1 ? ctIdx / (ctSeries.instances.length - 1) : 0;
    const petIdx = Math.round(ratio * (petSeries.instances.length - 1));
    const petInstance = petSeries.instances[petIdx];
    if (!petInstance) { clearOverlay(cellIndex); return; }

    const petImageId = buildImageId(petInstance.id);

    cs.loadAndCacheImage(petImageId).then(function (petImage) {
      if (!fusionState.active) return;

      const rect = mainCanvas.getBoundingClientRect();
      canvas.width = mainCanvas.width;
      canvas.height = mainCanvas.height;
      canvas.style.width = rect.width + 'px';
      canvas.style.height = rect.height + 'px';
      canvas.style.left = mainCanvas.offsetLeft + 'px';
      canvas.style.top = mainCanvas.offsetTop + 'px';

      if (fusionState.mode === 'ct') {
        clearOverlay(cellIndex);
        return;
      }

      const off = document.createElement('canvas');
      off.width = petImage.width;
      off.height = petImage.height;
      const offCtx = off.getContext('2d');
      const pixelData = petImage.getPixelData();
      const imgData = offCtx.createImageData(petImage.width, petImage.height);

      // Preview-grade normalization: scale by the image's own max pixel
      // value, then apply the "PET window" slider as a soft ceiling.
      // This is NOT a real SUV calculation — it has no access to dose,
      // weight, or decay-corrected activity, so treat it as a visual
      // preview control only, not a quantitative measurement.
      const rawMax = petImage.maxPixelValue || 1;
      const ceiling = rawMax * (fusionState.windowMax / 20);
      const normMax = ceiling > 0 ? ceiling : rawMax;

      for (let i = 0; i < pixelData.length; i++) {
        const t = Math.max(0, Math.min(1, pixelData[i] / normMax));
        const [r, g, b] = lutColor(fusionState.colormap, t);
        const o = i * 4;
        imgData.data[o]     = r;
        imgData.data[o + 1] = g;
        imgData.data[o + 2] = b;
        imgData.data[o + 3] = t < 0.04 ? 0 : 255; // low-activity background stays transparent
      }
      offCtx.putImageData(imgData, 0, 0);

      const ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.imageSmoothingEnabled = true;
      if (fusionState.mode === 'pet') {
        ctx.globalAlpha = 1;
        ctx.globalCompositeOperation = 'source-over';
      } else {
        ctx.globalAlpha = fusionState.opacity;
        ctx.globalCompositeOperation = 'screen';
      }
      ctx.drawImage(off, 0, 0, canvas.width, canvas.height);
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = 'source-over';

      cell.classList.add('fusion-on');
      const badge = document.getElementById('fusionBadge' + cellIndex);
      if (badge) badge.classList.add('active');
    }).catch(function (err) {
      console.error('[Fusion] failed to load/render PET image', err);
    });
  }

  /* ---------------- Wiring ---------------- */

  function initFusionUI() {
    populateSeriesSelects();

    const enableToggle = document.getElementById('fusionEnableToggle');
    const ctSelect = document.getElementById('fusionCtSelect');
    const petSelect = document.getElementById('fusionPetSelect');
    const opacitySlider = document.getElementById('fusionOpacity');
    const opacityVal = document.getElementById('fusionOpacityVal');
    const windowSlider = document.getElementById('fusionWindow');
    const windowVal = document.getElementById('fusionWindowVal');
    const modeBtns = document.querySelectorAll('.fusion-mode-btn');
    const cmapSwatches = document.querySelectorAll('.fusion-cmap');
    const btnFusion = document.getElementById('btnFusion');

    if (enableToggle) {
      enableToggle.addEventListener('change', (e) => {
        fusionState.active = e.target.checked;
        if (btnFusion) btnFusion.classList.toggle('active', fusionState.active);
        if (fusionState.active) queueRender();
        else clearAllOverlays();
      });
    }

    if (ctSelect) ctSelect.addEventListener('change', (e) => {
      fusionState.ctSeriesId = e.target.value;
      if (fusionState.active) queueRender();
    });
    if (petSelect) petSelect.addEventListener('change', (e) => {
      fusionState.petSeriesId = e.target.value;
      if (fusionState.active) queueRender();
    });

    if (opacitySlider) opacitySlider.addEventListener('input', (e) => {
      fusionState.opacity = (+e.target.value) / 100;
      if (opacityVal) opacityVal.textContent = e.target.value + '%';
      if (fusionState.active) queueRender();
    });

    if (windowSlider) windowSlider.addEventListener('input', (e) => {
      fusionState.windowMax = +e.target.value;
      if (windowVal) windowVal.textContent = '0–' + e.target.value;
      if (fusionState.active) queueRender();
    });

    modeBtns.forEach(btn => btn.addEventListener('click', () => {
      modeBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      fusionState.mode = btn.dataset.mode;
      if (fusionState.active) queueRender();
    }));

    cmapSwatches.forEach(sw => sw.addEventListener('click', () => {
      cmapSwatches.forEach(s => s.classList.remove('selected'));
      sw.classList.add('selected');
      fusionState.colormap = sw.dataset.map;
      if (fusionState.active) queueRender();
    }));

    // Re-render whenever a CT viewport redraws or the user scrolls slices.
    [0,1,2,3].forEach(i => {
      const el = document.getElementById('csElement' + i);
      if (el) el.addEventListener('cornerstoneimagerendered', () => {
        if (fusionState.active) queueRender();
      });
    });
    const scroller = document.getElementById('imageScroller');
    if (scroller) scroller.addEventListener('input', () => { if (fusionState.active) queueRender(); });

    window.addEventListener('resize', () => { if (fusionState.active) queueRender(); });

    // Refresh CT/PET dropdowns once study data / series finish loading,
    // in case this script initializes before viewer.js populates them.
    let tries = 0;
    const refreshTimer = setInterval(() => {
      tries++;
      const study = getStudy();
      const hasSeries = !!(study && study.series && study.series.length);
      if (hasSeries || tries > 40) {
        populateSeriesSelects();
        clearInterval(refreshTimer);
      }
    }, 250);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFusionUI);
  } else {
    initFusionUI();
  }

  window.DICOM_FUSION = fusionState;
})();
</script>
</body>
</html>
