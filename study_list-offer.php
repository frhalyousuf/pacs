<?php
declare(strict_types=1);

require_once 'config.php';

 $appName    = defined('APP_NAME') ? APP_NAME : 'DICOM Viewer';
 $appVersion = defined('APP_VERSION') ? APP_VERSION : '1.0';

 $baseUrl = defined('APP_URL') ? rtrim((string)APP_URL, '/') : 
           ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
           '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'));

 $search  = trim($_GET['q'] ?? '');
 $page    = max(1, (int)($_GET['page'] ?? 1));
 $perPage = 20;
 $offset  = ($page - 1) * $perPage;

 $where  = ['1=1'];
 $params = [];

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
            MAX(st.created_at) AS last_upload_at,
            SUBSTRING(GROUP_CONCAT(DISTINCT st.study_description SEPARATOR ', '), 1, 255) AS study_names,
            SUBSTRING(GROUP_CONCAT(DISTINCT st.modality SEPARATOR ', '), 1, 255) AS study_types
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
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
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
<title><?= htmlspecialchars((string)$appName) ?> — Patients</title>
<link rel="stylesheet" href="assets/css/style.css?v=final3"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="icon" type="image/png" href="assets/images/logoO.png">
<style>
  .share-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .btn-local-wa { color: #25D366; border-color: #25D366; }
  .btn-local-wa:hover { background: #25D366; color: #fff; }
  .brand-logo-img { height: 36px; width: auto; margin-right: 12px; border-radius: 6px; object-fit: contain; }
</style>
</head>
<body class="dashboard-page">

<header class="app-header">
  <div class="header-brand">
    <img src="assets/images/logoO.png" alt="Logo" class="brand-logo-img">
    <span class="brand-name"><?= htmlspecialchars((string)$appName) ?></span>
    <span class="brand-ver">v<?= htmlspecialchars((string)$appVersion) ?></span>
  </div>
  <nav class="header-nav">
    <a href="upload.php" class="nav-item"><i class="fas fa-upload"></i> Upload</a>
  </nav>
  <div class="header-actions" style="display:flex;gap:10px;align-items:center;">
    <span class="hdr-stat"><i class="fas fa-user-injured"></i> <?= number_format($total) ?> patients</span>
  </div>
</header>

<section class="search-bar-section">
  <form method="GET" action="study_list.php" class="search-form">
    <div class="search-group">
      <i class="fas fa-search search-icon"></i>
      <input type="text" name="q" placeholder="Search patient name or patient ID..."
             value="<?= htmlspecialchars($search) ?>" class="search-input"/>
    </div>
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    <a href="study_list.php" class="btn btn-ghost"><i class="fas fa-redo"></i> Reset</a>
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
            <th><i class="fas fa-user"></i> Patient</th>
            <th><i class="fas fa-id-badge"></i> Patient ID</th>
            <th><i class="fas fa-venus-mars"></i> Sex</th>
            <th><i class="fas fa-calendar"></i> Birth Date</th>
            <th><i class="fas fa-folder-tree"></i> Types/Folders</th>
            <th><i class="fas fa-images"></i> Images</th>
            <th><i class="fas fa-arrow-right"></i> Open</th>
            <th><i class="fas fa-share-alt"></i> Share</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($patients as $p): ?>
            <?php
              $shareUrl      = '';
              $shareName     = (string)($p['patient_name'] ?: 'Unknown Patient');
              $shareId       = (string)($p['patient_id'] ?: '');
              $shareAge      = '';
              $shareWeight   = '';
              $shareStudyType = (string)($p['study_types'] ?? '');

              // Secure base variables specifically for database saving
              $dbPatientId   = (string)($p['patient_id'] ?: '');
              $dbPatientName = (string)($p['patient_name'] ?: 'Unknown');

              if (function_exists('makePatientShareToken')) {
                  $token = makePatientShareToken((int)$p['patient_db_id']);
                  $base  = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
                  if ($base !== '') {
                      $longUrl = $base . '/external_viewer-offer.php?share=' . urlencode($token);
                      
                      try {
                          $chk = db()->prepare("SELECT code FROM short_links WHERE target_url = :url LIMIT 1");
                          $chk->execute([':url' => $longUrl]);
                          $code = $chk->fetchColumn();
                          
                          if (!$code) {
                              $ins = db()->prepare("INSERT IGNORE INTO short_links (code, target_url, patient_id, patient_name) VALUES (:code, :url, :pid, :pname)");
                              $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                              
                              for ($i = 0; $i < 5; $i++) {
                                  $code = '';
                                  for ($j = 0; $j < 6; $j++) {
                                      $code .= $chars[random_int(0, 61)];
                                  }
                                  $ins->execute([
                                      ':code' => $code, 
                                      ':url' => $longUrl, 
                                      ':pid' => $dbPatientId, 
                                      ':pname' => $dbPatientName
                                  ]);
                                  if ($ins->rowCount() > 0) break;
                                  $code = null;
                              }
                          }
                          
                          if ($code) {
                              $shareUrl = $baseUrl . '/s/?' . $code;
                          }
                      } catch (Throwable $e) {
                          $shareUrl = ''; 
                      }
                  }
              }

              if (function_exists('fetchRavcoPatientInfo')) {
                  $ravcoInfo   = fetchRavcoPatientInfo($shareId);
                  $shareName   = $ravcoInfo['pat_name']   ?? $shareName;
                  $shareId     = $ravcoInfo['pat_id']     ?? $shareId;
                  $shareAge    = $ravcoInfo['pat_age']    ?? '';
                  $shareWeight = $ravcoInfo['pat_weight'] ?? '';
              }

              $escUrl    = addslashes($shareUrl);
              $escName   = addslashes($shareName);
              $escType   = addslashes($shareStudyType);
              $escAge    = addslashes($shareAge);
              $escWeight = addslashes($shareWeight);

              // COPY BUTTON: Copies ONLY the raw short link
              $copyJs = "(function(btn, e) {
                  e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                  const url = '$escUrl';
                  const orig = btn.innerHTML;
                  try { 
                      navigator.clipboard.writeText(url); 
                      btn.innerHTML = '✓ Copied'; 
                  } catch(err) { 
                      prompt('Copy this link:', url); 
                      btn.innerHTML = orig; 
                  }
                  setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; }, 1400);
              })(this, event)";

              // WHATSAPP BUTTON: Sends message WITHOUT Patient ID
              $whatsappJs = "(function(btn, e) {
                  e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                  const url = '$escUrl';
                  const det = ['Patient Name: $escName', '$escType' ? 'Study Type: $escType' : null, '$escAge' ? 'Age: $escAge' : null, '$escWeight' ? 'Weight: $escWeight kg' : null].filter(Boolean).join('\\n');
                  const text = ['Welcome to Arzheen Hospital', '', det, '', url, '', 'We wish you continued health and wellness.'].join('\\n');
                  window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
              })(this, event)";
            ?>
            <tr>
              <td>
                <div class="patient-cell">
                  <div class="patient-avatar"><?= strtoupper(substr((string)($p['patient_name'] ?: 'NA'), 0, 2)) ?></div>
                  <div class="patient-info">
                    <span class="patient-name"><?= htmlspecialchars((string)($p['patient_name'] ?: '—')) ?></span>
                    <span class="patient-dob"><?= (int)$p['studies_count'] ?> studies</span>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars((string)($p['patient_id'] ?: '—')) ?></td>
              <td><?= htmlspecialchars((string)($p['sex'] ?: '—')) ?></td>
              <td><?= $p['birth_date'] ? date('d/m/Y', strtotime((string)$p['birth_date'])) : '—' ?></td>
              <td class="num-cell"><?= (int)$p['series_count'] ?></td>
              <td class="num-cell"><?= (int)$p['images_count'] ?></td>
              <td>
                <a href="general_patient.php?patient=<?= (int)$p['patient_db_id'] ?>" class="btn btn-view">
                  <i class="fas fa-folder-open"></i> Open Folders
                </a>
              </td>
              <td>
                <?php if ($shareUrl !== ''): ?>
                  <div class="share-actions">
                    <button type="button" class="btn btn-ghost local-copy-btn" onclick="<?= htmlspecialchars($copyJs, ENT_QUOTES) ?>">
                      <i class="fas fa-copy"></i> Copy
                    </button>
                    <button type="button" class="btn btn-ghost btn-local-wa local-wa-btn" onclick="<?= htmlspecialchars($whatsappJs, ENT_QUOTES) ?>">
                      <i class="fab fa-whatsapp"></i> WhatsApp
                    </button>
                  </div>
                <?php else: ?>
                  <span style="color:#94a3b8;font-size:12px;">Share unavailable</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($pp = max(1, $page - 3); $pp <= min($totalPages, $page + 3); $pp++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pp])) ?>" class="page-btn <?= $pp === $page ? 'active' : '' ?>"><?= $pp ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>

</body>
</html>