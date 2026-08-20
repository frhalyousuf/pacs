<?php
require_once 'config.php';

 $patientId = (int)($_GET['patient'] ?? 0);
if ($patientId <= 0) die('Invalid patient');

 $stmt = db()->prepare("SELECT * FROM patients WHERE id = ?");
 $stmt->execute([$patientId]);
 $patient = $stmt->fetch();
if (!$patient) die('Patient not found');

 $stmt = db()->prepare("
    SELECT
      st.modality AS image_type,
      MIN(st.id) AS first_study_id,
      COUNT(DISTINCT st.id) AS studies_count,
      COUNT(i.id) AS images_count,
      MAX(st.created_at) AS last_upload_at
    FROM studies st
    LEFT JOIN series se ON se.study_id = st.id
    LEFT JOIN instances i ON i.series_id = se.id
    WHERE st.patient_id = ?
    GROUP BY st.modality
    ORDER BY last_upload_at DESC
");
 $stmt->execute([$patientId]);
 $folders = $stmt->fetchAll();

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

 $patientAge = '—';
if (!empty($patient['birth_date']) && $patient['birth_date'] !== '0000-00-00') {
    $dob = new DateTime($patient['birth_date']);
    $now = new DateTime();
    $patientAge = $dob->diff($now)->y . ' Years';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= APP_NAME ?> — Patient Folders</title>
<link rel="icon" type="image/png" href="assets/images/4f-logo.png">
<link rel="stylesheet" href="assets/css/style.css?v=patientfolders1"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
.report-card{background:#0f172a;border:1px solid #243247;border-radius:12px;padding:14px;margin:14px 0}
.report-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.reporter-name{font-weight:700;color:#bfdbfe}
.report-meta{font-size:12px;color:#94a3b8}
.report-text{white-space:pre-wrap;line-height:1.55;color:#e5e7eb;max-height:120px;overflow:hidden;position:relative}
.report-text.faded::after{content:'';position:absolute;bottom:0;left:0;right:0;height:40px;background:linear-gradient(transparent, #0f172a)}
.report-empty{color:#94a3b8}
.btn-view-report{margin-top:8px}

/* Modal Overlay Styles */
.report-modal-overlay {
  position: fixed; inset: 0; z-index: 99999;
  background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(6px);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease;
}
.report-modal-overlay.active { opacity: 1; visibility: visible; }

.report-modal-box {
  background: #2d3748; padding: 20px; border-radius: 8px;
  width: 95%; max-width: 900px; max-height: 95vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,0.5);
  transform: translateY(20px); transition: transform 0.3s ease;
}
.report-modal-overlay.active .report-modal-box { transform: translateY(0); }

.modal-top-actions {
  display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 10px;
}
.modal-action-btn {
  background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
  color: #fff; padding: 8px 12px; border-radius: 8px; cursor: pointer;
  display: flex; align-items: center; gap: 6px; transition: 0.2s; font-size: 13px;
}
.modal-action-btn:hover { background: rgba(255,255,255,0.2); }

/* The A4 Paper Effect */
.report-paper {
  background: #ffffff; color: #1a202c; flex: 1; overflow-y: auto;
  border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.report-header {
  padding: 20px 30px; border-bottom: 2px solid #e2e8f0;
  display: flex; justify-content: space-between; align-items: center;
}
.clinic-logo-area { font-size: 24px; font-weight: 700; color: #2d3748; display: flex; align-items: center; gap: 10px; }
.report-patient-demographics {
  display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 13px;
  background: #f7fafc; padding: 10px 15px; border-radius: 6px; border: 1px solid #e2e8f0;
}
.demo-label { font-weight: 600; color: #4a5568; }
.demo-value { color: #1a202c; }
.report-body { padding: 25px 30px; line-height: 1.6; font-size: 14px; color: #2d3748; }
.report-body img { max-width: 100%; height: auto; border-radius: 4px; margin: 12px 0; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: block; }
.report-footer { margin-top: 40px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #718096; text-align: center; }
.signatures { display: flex; justify-content: space-around; margin-top: 40px; padding-top: 10px; }
.sig-block { text-align: center; min-width: 150px; }
.sig-line { border-top: 1px solid #1a202c; margin-bottom: 5px; width: 100%; }
.sig-name { font-weight: 600; font-size: 13px; color: #2d3748; }

/* ★ NEW: Print Styles ★ */
@media print {
  /* 1. Hide everything else on the page */
  body.dashboard-page > *:not(.report-modal-overlay) { display: none !important; }
  
  /* 2. Format the body for printing */
  body { background: white !important; margin: 0 !important; padding: 0 !important; }
  
  /* 3. Make overlay static and transparent */
  .report-modal-overlay {
    position: static !important; background: none !important; backdrop-filter: none !important;
    display: block !important; visibility: visible !important; opacity: 1 !important;
    inset: auto !important; z-index: auto !important;
  }
  
  /* 4. Remove box decorations and restrictions from the modal container */
  .report-modal-box {
    background: none !important; padding: 0 !important; box-shadow: none !important;
    max-height: none !important; width: 100% !important; max-width: 100% !important;
    transform: none !important; border-radius: 0 !important;
  }
  
  /* 5. Hide modal buttons (Close / Print) from the printed page */
  .modal-top-actions { display: none !important; }
  
  /* 6. Clean up the paper for print */
  .report-paper {
    box-shadow: none !important; border-radius: 0 !important; border: none !important;
  }
}
</style>
</head>
<body class="dashboard-page">
<header class="app-header">
  <div class="header-brand">
    <i class="fas fa-lungs-virus brand-icon"></i>
    <span class="brand-name"><?= APP_NAME ?></span>
  </div>
  <nav class="header-nav">
    <a href="index.php" class="nav-item"><i class="fas fa-users"></i> Patients</a>
  </nav>
</header>

<main class="main-content">
  <div class="table-toolbar">
    <h2 class="section-title">
      <i class="fas fa-user-injured"></i>
      <?= htmlspecialchars($patient['patient_name']) ?> — Image Type Folders
    </h2>
  </div>

  <div class="table-toolbar" style="margin-top:10px;">
    <h3 class="section-title" style="font-size:18px;">
      <i class="fas fa-file-medical-alt"></i> Reporter Reports
    </h3>
  </div>

  <?php if (empty($reports)): ?>
    <div class="report-card">
      <div class="report-empty">No report available for this patient yet.</div>
    </div>
  <?php else: ?>
    <?php foreach ($reports as $rp): 
      $text = $rp['report_text'] ?? '';
      $isHtml = (stripos($text, '<img ') !== false || stripos($text, '<br') !== false || stripos($text, '<p>') !== false);
    ?>
      <div class="report-card">
        <div class="report-head">
          <div class="reporter-name">
            <i class="fas fa-user-edit"></i>
            <?= htmlspecialchars($rp['reporter_name'] ?? 'Reporter') ?>
          </div>
          <div class="report-meta">
            Updated: <?= htmlspecialchars((string)$rp['updated_at']) ?>
            <?php if (!empty($rp['study_id'])): ?>
              &nbsp;|&nbsp; Study #<?= (int)$rp['study_id'] ?>
            <?php endif; ?>
          </div>
        </div>
        
        <div class="report-text faded">
          <?= $isHtml ? strip_tags($text) : htmlspecialchars($text) ?>
        </div>

        <!-- ★ Buttons Area ★ -->
        <div style="display:flex; gap:8px; margin-top:8px;">
          <button type="button" class="btn btn-view btn-view-report" onclick="openReportModal(<?= (int)$rp['id'] ?>)">
            <i class="fas fa-file-medical"></i> View Full Report
          </button>
          
          <!-- ★ NEW: Quick Print Button ★ -->
          <button type="button" class="btn btn-view btn-view-report" style="border-color:rgba(59, 130, 246, 0.5);" onclick="openAndPrintReport(<?= (int)$rp['id'] ?>)">
            <i class="fas fa-print"></i> Print
          </button>
        </div>
        
        <div id="reportData<?= (int)$rp['id'] ?>" style="display:none;">
          <?php if ($isHtml): ?>
            <?= $text ?>
          <?php else: ?>
            <?= nl2br(htmlspecialchars($text)) ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="table-wrapper">
    <table class="study-table">
      <thead>
        <tr>
          <th>Image Type</th>
          <th>Studies</th>
          <th>Images</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($folders as $f): ?>
        <tr>
          <td><span class="modality-badge mod-<?= strtolower($f['image_type'] ?? 'unk') ?>"><?= htmlspecialchars($f['image_type'] ?? 'UNKNOWN') ?></span></td>
          <td class="num-cell"><?= (int)$f['studies_count'] ?></td>
          <td class="num-cell"><?= (int)$f['images_count'] ?></td>
          <td>
            <a class="btn btn-view" href="viewer.php?patient=<?= $patientId ?>&type=<?= urlencode($f['image_type']) ?>">
              <i class="fas fa-eye"></i> View Sequence
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>

<!-- The Professional Medical Report Modal -->
<div class="report-modal-overlay" id="reportModalOverlay" onclick="closeReportModal(event)">
  <div class="report-modal-box">
    <div class="modal-top-actions">
      <!-- ★ NEW: Print button inside modal ★ -->
      <button class="modal-action-btn" onclick="window.print()">
        <i class="fas fa-print"></i> Print Report
      </button>
      <button class="modal-action-btn" onclick="closeReportModal(event, true)">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
    
    <div class="report-paper">
      <div class="report-header">
        <div class="clinic-logo-area">
          <i class="fas fa-hospital" style="color:#3CD08F;"></i> 
          <span>4F CLINICAL TECHNOLOGY</span>
        </div>
        <div class="report-patient-demographics">
          <span class="demo-label">Patient:</span>
          <span class="demo-value"><?= htmlspecialchars($patient['patient_name']) ?></span>
          <span class="demo-label">Age/Sex:</span>
          <span class="demo-value"><?= htmlspecialchars($patientAge) ?> / <?= htmlspecialchars($patient['sex'] ?? '—') ?></span>
          <span class="demo-label">Patient ID:</span>
          <span class="demo-value"><?= htmlspecialchars($patient['patient_id']) ?></span>
          <span class="demo-label">Date:</span>
          <span class="demo-value" id="reportModalDate">—</span>
        </div>
      </div>

      <div class="report-body" id="reportModalBody"></div>

      <div style="padding: 0 30px 20px;">
        <div class="signatures">
          <div class="sig-block"><div class="sig-line"></div><div class="sig-name">Radiologist Signature</div></div>
          <div class="sig-block"><div class="sig-line"></div><div class="sig-name">Referring Physician</div></div>
        </div>
        <div class="report-footer">4F Clinical Technology | Tel: +XXX-XXX-XXXX | Address Here</div>
      </div>
    </div>
  </div>
</div>

<script>
function openReportModal(reportId) {
  const sourceDiv = document.getElementById('reportData' + reportId);
  const modalBody = document.getElementById('reportModalBody');
  const overlay = document.getElementById('reportModalOverlay');

  if (!sourceDiv || !modalBody || !overlay) return;

  modalBody.innerHTML = sourceDiv.innerHTML;
  document.getElementById('reportModalDate').textContent = new Date().toLocaleDateString();
  
  overlay.classList.add('active');
  document.body.style.overflow = 'hidden';
}

// ★ NEW: Quick Print Function ★
function openAndPrintReport(reportId) {
  openReportModal(reportId);
  // Wait half a second for the modal and images to render, then trigger print
  setTimeout(() => {
    window.print();
  }, 500);
}

function closeReportModal(event, forceClose = false) {
  const overlay = document.getElementById('reportModalOverlay');
  if (!overlay) return;

  if (forceClose || event.target === overlay) {
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }
}
</script>
</body>
</html>