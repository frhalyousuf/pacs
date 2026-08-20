<?php
declare(strict_types=1);
require_once 'config.php';

$doctors = [];
try {
    $stmt = db()->query("SELECT id, full_name, email FROM doctors ORDER BY full_name ASC");
    $doctors = $stmt->fetchAll();
} catch (Throwable $e) {
    $doctors = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= APP_NAME ?> — Upload DICOM</title>
<link rel="stylesheet" href="assets/css/style.css?v=upload-redesign-1"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body class="upload-page">

<header class="app-header">
  <div class="header-brand">
    <i class="fas fa-lungs-virus brand-icon"></i>
    <span class="brand-name"><?= APP_NAME ?></span>
  </div>
  <nav class="header-nav">
    <a href="study_list.php" class="nav-item"><i class="fas fa-list-ul"></i> Study List</a>
    <!--<a href="upload.php" class="nav-item active"><i class="fas fa-upload"></i> Upload</a>-->
  </nav>
</header>

<main class="upload-main">
  <div class="upload-container">
    <div class="upload-header">
      <h1><i class="fas fa-cloud-upload-alt"></i> Upload DICOM Files</h1>
      <p>Select doctor, enter patient info, choose image type, then upload files/folder.</p>
    </div>

    <!-- Patient Meta Form -->
    <section class="upload-meta-card">
      <h3><i class="fas fa-user-injured"></i> Patient Information</h3>
      <div class="upload-meta-grid">

        <div class="meta-field full-width">
          <label for="doctorId">Doctor <span>*</span></label>
          <select id="doctorId" required>
            <option value="">Select doctor...</option>
            <?php foreach ($doctors as $d): ?>
              <option value="<?= (int)$d['id'] ?>">
                <?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['email']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($doctors)): ?>
            <small style="color:#ef4444;display:block;margin-top:6px;">
              No registered doctors found. Please register a doctor first.
            </small>
          <?php endif; ?>
        </div>

        <div class="meta-field">
          <label for="patientName">Patient Name <span>*</span></label>
          <input id="patientName" type="text" placeholder="e.g. John Doe" required />
        </div>
        <div class="meta-field">
          <label for="patientAge">Age <span>*</span></label>
          <input id="patientAge" type="number" min="0" max="130" placeholder="e.g. 45" required />
        </div>
        <div class="meta-field">
          <label for="patientSex">Sex <span>*</span></label>
          <select id="patientSex" required>
            <option value="">Select sex...</option>
            <option value="M">Male (M)</option>
            <option value="F">Female (F)</option>
          </select>
        </div>
        <div class="meta-field">
          <label for="patientWeight">Weight (kg) <span>*</span></label>
          <input id="patientWeight" type="number" min="0" step="0.1" placeholder="e.g. 72.5" required />
        </div>
        <div class="meta-field full-width">
          <label for="imageType">Image Type <span>*</span></label>
          <select id="imageType" required>
            <option value="">Select type...</option>
            <option value="CT_SCAN">CT_SCAN</option>
            <option value="MRI">MRI</option>
            <option value="XRAY">XRAY</option>
            <option value="ULTRASOUND">ULTRASOUND</option>
            <option value="PET_SCAN">PET_SCAN</option>
            <option value="MAMMOGRAPHY">MAMMOGRAPHY</option>
            <option value="ANGIOGRAPHY">ANGIOGRAPHY</option>
            <option value="FLUOROSCOPY">FLUOROSCOPY</option>
          </select>
        </div>
      </div>
    </section>

    <!-- Drop Zone -->
    <div class="drop-zone" id="dropZone">
      <div class="drop-zone-content" id="dropContent">
        <i class="fas fa-cloud-upload-alt drop-icon"></i>
        <h3>Drop DICOM files here</h3>
        <p>or</p>
        <div class="upload-buttons">
          <label for="fileInput" class="btn btn-primary">
            <i class="fas fa-file-medical"></i> Select Files
          </label>
          <label for="folderInput" class="btn btn-secondary">
            <i class="fas fa-folder-open"></i> Select Folder
          </label>
        </div>
        <p class="upload-hint">Supports multiple files • Max 200 MB per file • DICOM format</p>
      </div>
      <input type="file" id="fileInput" multiple accept=".dcm,.dicom,.ima" style="display:none"/>
      <input type="file" id="folderInput" multiple webkitdirectory style="display:none"/>
    </div>

    <!-- Progress Section -->
    <div class="upload-progress-section" id="progressSection" style="display:none">
      <div class="progress-header">
        <h3><i class="fas fa-cog fa-spin"></i> Processing Files</h3>
        <div class="progress-stats">
          <span id="progressCount">0 / 0</span>
          <span id="progressPercent">0%</span>
        </div>
      </div>
      <div class="progress-bar-track">
        <div class="progress-bar-fill" id="progressBar" style="width:0%"></div>
      </div>
      <div class="progress-details">
        <span id="progressCurrent">Preparing…</span>
        <span id="progressSpeed"></span>
      </div>
    </div>

    <!-- File Queue -->
    <div class="file-queue" id="fileQueue" style="display:none">
      <div class="queue-header">
        <h3><i class="fas fa-list"></i> File Queue (<span id="queueCount">0</span> files)</h3>
        <div class="queue-actions">
          <button id="startUpload" class="btn btn-accent"><i class="fas fa-play"></i> Start Upload</button>
          <button id="clearQueue" class="btn btn-ghost"><i class="fas fa-times"></i> Clear</button>
        </div>
      </div>
      <div class="queue-list" id="queueList"></div>
    </div>

    <!-- Upload Results -->
    <div class="upload-results" id="uploadResults" style="display:none">
      <div class="result-summary">
        <div class="result-card success-card">
          <i class="fas fa-check-circle"></i>
          <div><span id="successCount">0</span><small>Uploaded</small></div>
        </div>
        <div class="result-card warning-card">
          <i class="fas fa-exclamation-triangle"></i>
          <div><span id="dupCount">0</span><small>Duplicates</small></div>
        </div>
        <div class="result-card error-card">
          <i class="fas fa-times-circle"></i>
          <div><span id="errorCount">0</span><small>Errors</small></div>
        </div>
      </div>

      <div class="result-actions">
        <button id="uploadAnotherType" class="btn btn-secondary">
          <i class="fas fa-layer-group"></i> Upload Another Image Type
        </button>
        <a href="upload.php" class="btn btn-primary">
          <i class="fas fa-redo"></i> Continue Uploading
        </a>
      </div>
    </div>
  </div>
</main>

<script src="assets/js/upload.js?v=meta3"></script>
</body>
</html>