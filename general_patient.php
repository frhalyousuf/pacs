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

/** NEW: load reporter reports for this patient */
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= APP_NAME ?> — Patient Folders</title>
<link rel="stylesheet" href="assets/css/style.css?v=patientfolders1"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
.report-card{background:#0f172a;border:1px solid #243247;border-radius:12px;padding:14px;margin:14px 0}
.report-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.reporter-name{font-weight:700;color:#bfdbfe}
.report-meta{font-size:12px;color:#94a3b8}
.report-text{white-space:pre-wrap;line-height:1.55;color:#e5e7eb}
.report-empty{color:#94a3b8}
</style>
</head>
<body class="dashboard-page">
<header class="app-header">
  <div class="header-brand">
    <i class="fas fa-lungs-virus brand-icon"></i>
    <span class="brand-name"><?= APP_NAME ?></span>
  </div>
  <nav class="header-nav">
    <a href="study_list.php" class="nav-item"><i class="fas fa-users"></i> Patients</a>
  </nav>
</header>

<main class="main-content">
  <div class="table-toolbar">
    <h2 class="section-title">
      <i class="fas fa-user-injured"></i>
      <?= htmlspecialchars($patient['patient_name']) ?> — Image Type Folders
    </h2>
  </div>

  <!-- NEW: Reports section -->
  <!--<div class="table-toolbar" style="margin-top:10px;">-->
  <!--  <h3 class="section-title" style="font-size:18px;">-->
  <!--    <i class="fas fa-file-medical-alt"></i> Reporter Reports-->
  <!--  </h3>-->
  <!--</div>-->

  <!--<?php if (empty($reports)): ?>-->
  <!--  <div class="report-card">-->
  <!--    <div class="report-empty">No report available for this patient yet.</div>-->
  <!--  </div>-->
  <!--<?php else: ?>-->
  <!--  <?php foreach ($reports as $rp): ?>-->
  <!--    <div class="report-card">-->
  <!--      <div class="report-head">-->
  <!--        <div class="reporter-name">-->
  <!--          <i class="fas fa-user-edit"></i>-->
  <!--          <?= htmlspecialchars($rp['reporter_name'] ?? 'Reporter') ?>-->
  <!--        </div>-->
  <!--        <div class="report-meta">-->
  <!--          Updated: <?= htmlspecialchars((string)$rp['updated_at']) ?>-->
  <!--          <?php if (!empty($rp['study_id'])): ?>-->
  <!--            &nbsp;|&nbsp; Study #<?= (int)$rp['study_id'] ?>-->
  <!--          <?php endif; ?>-->
  <!--        </div>-->
  <!--      </div>-->
  <!--      <div class="report-text"><?= nl2br(htmlspecialchars($rp['report_text'] ?? '')) ?></div>-->
  <!--    </div>-->
  <!--  <?php endforeach; ?>-->
  <!--<?php endif; ?>-->

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
            <a class="btn btn-view" href="general_viewer.php?patient=<?= $patientId ?>&type=<?= urlencode($f['image_type']) ?>">
              <i class="fas fa-eye"></i> View Sequence
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>