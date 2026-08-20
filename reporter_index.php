<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'reporter_auth.php';
requireReporter();

$rep = currentReporter();

// Determine which patients this reporter is allowed to see, via the ravco DB
$allowedPatientIds = [];
try {
    $raStmt = ravcoDb()->prepare("SELECT DISTINCT pat_id FROM pat_radio WHERE dr_report = ?");
    $raStmt->execute([$rep['username']]);
    $allowedPatientIds = array_map('strval', $raStmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    $allowedPatientIds = [];
}

$search   = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if (empty($allowedPatientIds)) {
    $where[] = '1=0'; // no assigned patients -> show nothing, not an error
} else {
    $placeholders = [];
    foreach ($allowedPatientIds as $idx => $pid) {
        $ph = ":pid$idx";
        $placeholders[] = $ph;
        $params[$ph] = $pid;
    }
    $where[] = 'p.patient_id IN (' . implode(',', $placeholders) . ')';
}

if ($search !== '') {
    $where[] = '(p.patient_name LIKE :s OR p.patient_id LIKE :s2)';
    $params[':s']  = "%$search%";
    $params[':s2'] = "%$search%";
}
$whereSQL = implode(' AND ', $where);

try {
    $countStmt = db()->prepare("
        SELECT COUNT(DISTINCT p.id)
        FROM patients p
        INNER JOIN studies st ON st.patient_id = p.id
        WHERE $whereSQL
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT
            p.id AS patient_db_id,
            p.patient_id,
            p.patient_name,
            p.birth_date,
            p.sex,
            COUNT(DISTINCT st.id) AS studies_count,
            COUNT(DISTINCT se.id) AS series_count,
            COUNT(i.id) AS images_count,
            MAX(st.created_at) AS last_upload_at
        FROM patients p
        INNER JOIN studies st ON st.patient_id = p.id
        LEFT JOIN series se ON se.study_id = st.id
        LEFT JOIN instances i ON i.series_id = se.id
        WHERE $whereSQL
        GROUP BY p.id, p.patient_id, p.patient_name, p.birth_date, p.sex
        ORDER BY last_upload_at DESC, p.id DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $patients = $stmt->fetchAll();
} catch (Throwable $e) {
    $patients = [];
    $total = 0;
}
$totalPages = max(1, (int)ceil($total / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Reporter Panel — Patients</title>
<link rel="stylesheet" href="assets/css/style.css?v=patients1"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="icon" type="image/png" href="assets/images/4f-logo.png">

</head>
<body class="dashboard-page">
<header class="app-header">
  <div class="header-brand">
    <i class="fas fa-file-signature brand-icon"></i>
    <span class="brand-name">Reporter Panel</span>
  </div>
  <nav class="header-nav">
    <a href="reporter_index.php" class="nav-item active"><i class="fas fa-users"></i> Patients</a>
  </nav>
  <div class="header-actions" style="display:flex;gap:10px;align-items:center;">
    <span class="hdr-stat"><i class="fas fa-user-edit"></i> <?= htmlspecialchars($rep['full_name']) ?></span>
    <a href="reporter_logout.php" class="btn btn-ghost">Logout</a>
  </div>
</header>

<section class="search-bar-section">
  <form method="GET" action="reporter_index.php" class="search-form">
    <div class="search-group">
      <i class="fas fa-search search-icon"></i>
      <input type="text" name="q" placeholder="Search patient name or patient ID..."
             value="<?= htmlspecialchars($search) ?>" class="search-input"/>
    </div>
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    <a href="reporter_index.php" class="btn btn-ghost"><i class="fas fa-redo"></i> Reset</a>
  </form>
</section>

<main class="main-content">
  <div class="table-toolbar">
    <h2 class="section-title"><i class="fas fa-users"></i> Patients</h2>
  </div>

  <?php if (empty($patients)): ?>
    <div class="empty-state">
      <i class="fas fa-user-slash empty-icon"></i>
      <p>No patients found.</p>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="study-table">
        <thead>
          <tr>
            <th>Patient</th>
            <th>Patient ID</th>
            <th>Sex</th>
            <th>Birth Date</th>
            <th>Series</th>
            <th>Images</th>
            <th>Report</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($patients as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['patient_name'] ?: '—') ?></td>
              <td><?= htmlspecialchars($p['patient_id'] ?: '—') ?></td>
              <td><?= htmlspecialchars($p['sex'] ?: '—') ?></td>
              <td><?= $p['birth_date'] ? date('d/m/Y', strtotime($p['birth_date'])) : '—' ?></td>
              <td><?= (int)$p['series_count'] ?></td>
              <td><?= (int)$p['images_count'] ?></td>
              <td>
                <a class="btn btn-view" href="reporter_patient.php?patient=<?= (int)$p['patient_db_id'] ?>">
                  <i class="fas fa-pen"></i> Open & Report
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
        <?php for ($pp=max(1,$page-3); $pp<=min($totalPages,$page+3); $pp++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pp])) ?>" class="page-btn <?= $pp === $page ? 'active' : '' ?>"><?= $pp ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>