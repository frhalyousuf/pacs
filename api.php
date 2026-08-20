<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'auth.php';
require_once 'reporter_auth.php';

/**
 * DEV only. Set display_errors=0 on production.
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

register_shutdown_function(function () use (&$action): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            if (!in_array($action, ['wado','wadouri'], true)) {
                header('Content-Type: application/json; charset=utf-8');
            }
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error: ' . $err['message'],
            'file'    => $err['file'],
            'line'    => $err['line']
        ], JSON_UNESCAPED_UNICODE);
    }
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$action = $_GET['action'] ?? '';

$doctorOnlyActions = ['tags','save_annotations','load_annotations','delete_study','stats','health'];
if (in_array($action, $doctorOnlyActions, true)) {
    requireDoctor();
}
$doctor = currentDoctor();
$doctorId = (int)($doctor['id'] ?? 0);

try {
    switch ($action) {
        case 'pacs_ingest':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success' => false, 'message' => 'POST required'], 405);
        
            $providedKey = $_SERVER['HTTP_X_PACS_KEY'] ?? '';
            if (!hash_equals(PACS_INGEST_SECRET, (string)$providedKey)) {
                jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            if (!isset($_FILES['file'])) jsonResponse(['success' => false, 'message' => 'No file received'], 400);
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) jsonResponse(['success' => false, 'message' => 'Upload error code: ' . $_FILES['file']['error']], 400);
        
            $doctorId = (int)PACS_DEFAULT_DOCTOR_ID;
            $chk = db()->prepare("SELECT id FROM doctors WHERE id=? LIMIT 1");
            $chk->execute([$doctorId]);
            if ($doctorId <= 0 || !$chk->fetch()) jsonResponse(['success'=>false,'message'=>'PACS default doctor not configured/found'], 500);
        
            $file = $_FILES['file'];
            $tmpPath = (string)$file['tmp_name'];
            $origName = basename((string)$file['name']);
            $fileSize = (int)$file['size'];
            if ($fileSize <= 0) jsonResponse(['success' => false, 'message' => 'Empty file'], 400);
            if ($fileSize > MAX_FILE_SIZE) jsonResponse(['success' => false, 'message' => 'File too large'], 400);
            if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true)) jsonResponse(['success' => false, 'message' => 'Cannot create upload directory'], 500);
            if (!is_writable(UPLOAD_DIR)) jsonResponse(['success' => false, 'message' => 'Upload directory is not writable'], 500);
        
            $tags = parseDicomTags($tmpPath);
            if ($tags === false) jsonResponse(['success' => false, 'message' => 'Invalid DICOM file: ' . $origName], 400);
        
            // ----- Derive the same variables 'upload' expects, but from DICOM tags -----
            $modalityMap = [
                'CT'=>'CT_SCAN','MR'=>'MRI','CR'=>'XRAY','DX'=>'XRAY','US'=>'ULTRASOUND',
                'PT'=>'PET_SCAN','MG'=>'MAMMOGRAPHY','XA'=>'ANGIOGRAPHY','RF'=>'FLUOROSCOPY',
            ];
            $dicomModality = strtoupper(trim((string)($tags['Modality'] ?? '')));
            $imageTypeInput = $modalityMap[$dicomModality] ?? 'CT_SCAN'; // fallback bucket if unmapped
        
            $patientNameInput = trim(str_replace('^', ' ', (string)($tags['PatientName'] ?? ''))) ?: 'Unknown';
        
            $sexFromDicom = strtoupper((string)($tags['PatientSex'] ?? 'U'));
            $patientSexInput = in_array($sexFromDicom, ['M','F'], true) ? $sexFromDicom : 'M'; // 'upload' requires M/F
        
            $birthDate = formatDicomDate((string)($tags['PatientBirthDate'] ?? ''));
            $patientAge = 0;
            if ($birthDate !== '') {
                try { $patientAge = (new DateTime($birthDate))->diff(new DateTime())->y; } catch (Throwable $e) {}
            } elseif (!empty($tags['PatientAge']) && preg_match('/^(\d+)Y/', (string)$tags['PatientAge'], $m)) {
                $patientAge = (int)$m[1];
            }
            if ($patientAge <= 0 || $patientAge > 130) $patientAge = 1; // avoid failing insert on unknown age
        
            $patientWeight = (isset($tags['PatientWeight']) && is_numeric($tags['PatientWeight']))
                ? (float)$tags['PatientWeight'] : 1.0; // avoid failing insert on unknown weight
        
            $clientFileName = $origName;
            $clientRelativePath = '';
            $seriesPrefix = deriveSeriesPrefix($origName);
            // ----- End derived variables -----
        
            $db = db();
            $db->beginTransaction();
        
            try {
                $patientId = $tags['PatientID'] ?? ('PACS_' . md5(strtolower($patientNameInput) . '|' . $patientAge));
                $patientName = $patientNameInput;
                $sex = $patientSexInput;
        
                if ($birthDate === '' && $patientAge >= 0) {
                    $year = (int)date('Y') - $patientAge;
                    $birthDate = sprintf('%04d-01-01', max(1900, $year));
                }
        
                $stmt = $db->prepare("
                    INSERT INTO patients (patient_id, patient_name, birth_date, sex)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        patient_name = VALUES(patient_name),
                        birth_date   = VALUES(birth_date),
                        sex          = VALUES(sex)
                ");
                $stmt->execute([$patientId, $patientName, $birthDate ?: null, $sex]);
        
                $stmt = $db->prepare("SELECT id FROM patients WHERE patient_id = ?");
                $stmt->execute([$patientId]);
                $patientDbId = (int)$stmt->fetchColumn();
                if ($patientDbId <= 0) throw new RuntimeException('Failed to resolve patient ID');
        
                $studyUID = (string)($tags['StudyInstanceUID'] ?? ('PACSSTUDY.' . sha1(strtolower($patientNameInput) . '|' . (string)$patientAge . '|' . strtoupper($imageTypeInput) . '|DOC:' . $doctorId)));
                $studyDate = formatDicomDate((string)($tags['StudyDate'] ?? ''));
                $studyTime = formatDicomTime((string)($tags['StudyTime'] ?? ''));
                $studyDesc = $tags['StudyDescription'] ?? $imageTypeInput;
                $accession = $tags['AccessionNumber'] ?? null;
                $refPhys   = $tags['ReferringPhysician'] ?? null;
                $modality  = $imageTypeInput;
        
                $stmt = $db->prepare("
                    INSERT INTO studies
                        (patient_id, doctor_id, study_instance_uid, study_date, study_time, study_description, accession_number, referring_physician, modality)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        study_date = VALUES(study_date),
                        study_time = VALUES(study_time),
                        study_description = VALUES(study_description),
                        modality = VALUES(modality)
                ");
                $stmt->execute([
                    $patientDbId, $doctorId, $studyUID, $studyDate ?: null, $studyTime ?: null,
                    $studyDesc, $accession, $refPhys, $modality
                ]);
        
                $stmt = $db->prepare("SELECT id FROM studies WHERE study_instance_uid = ?");
                $stmt->execute([$studyUID]);
                $studyDbId = (int)$stmt->fetchColumn();
                if ($studyDbId <= 0) throw new RuntimeException('Failed to resolve study ID');
        
                $seriesUID = (string)($tags['SeriesInstanceUID'] ?? ('PFX.' . sha1((string)$studyDbId . '|' . $seriesPrefix)));
                $stmt = $db->prepare("SELECT id FROM series WHERE study_id = ? AND series_instance_uid = ? LIMIT 1");
                $stmt->execute([$studyDbId, $seriesUID]);
                $seriesDbId = (int)$stmt->fetchColumn();
        
                if ($seriesDbId <= 0) {
                    $stmt = $db->prepare("SELECT COALESCE(MAX(series_number), 0) + 1 FROM series WHERE study_id = ?");
                    $stmt->execute([$studyDbId]);
                    $nextSeriesNumber = (int)$stmt->fetchColumn();
        
                    $seriesDesc = $tags['SeriesDescription'] ?? $seriesPrefix;
                    $bodyPart = $tags['BodyPartExamined'] ?? null;
        
                    $stmt = $db->prepare("
                        INSERT INTO series
                            (study_id, series_instance_uid, series_number, series_description, modality, body_part)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$studyDbId, $seriesUID, $nextSeriesNumber, $seriesDesc, $modality, $bodyPart]);
                    $seriesDbId = (int)$db->lastInsertId();
                }
                if ($seriesDbId <= 0) throw new RuntimeException('Failed to resolve series ID');
        
                $sopUID = (string)($tags['SOPInstanceUID'] ?? ('DB.' . sha1($studyDbId . '|' . $seriesDbId . '|' . $origName . '|' . (@hash_file('sha1', $tmpPath) ?: microtime(true)))));
        
                $stmt = $db->prepare("SELECT id FROM instances WHERE sop_instance_uid = ? LIMIT 1");
                $stmt->execute([$sopUID]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $db->rollBack();
                    jsonResponse(['success' => true, 'duplicate' => true, 'message' => 'Already exists in this series', 'studyId' => $studyDbId]);
                }
        
                $safePatientName = preg_replace('/[^A-Za-z0-9._-]/', '_', $patientNameInput);
                $safeType = preg_replace('/[^A-Za-z0-9._-]/', '_', $imageTypeInput);
                $safeSeries = preg_replace('/[^A-Za-z0-9._-]/', '_', $seriesPrefix);
                $originalNameNoExt = preg_replace('/\.[^.]+$/', '', $origName);
                $safeOriginal = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalNameNoExt) . '.dcm';
        
                $subDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $safePatientName . DIRECTORY_SEPARATOR . $safeType . DIRECTORY_SEPARATOR . $safeSeries . DIRECTORY_SEPARATOR;
                if (!is_dir($subDir) && !mkdir($subDir, 0775, true)) throw new RuntimeException('Cannot create series directory: ' . $subDir);
                if (!is_writable($subDir)) throw new RuntimeException('Series directory not writable: ' . $subDir);
        
                $destPath = $subDir . $safeOriginal;
                if (is_file($destPath)) $destPath = $subDir . $originalNameNoExt . '_' . substr(sha1((string)microtime(true)), 0, 8) . '.dcm';
                if (!move_uploaded_file($tmpPath, $destPath)) throw new RuntimeException('move_uploaded_file failed');
        
                $relPath = ltrim(str_replace(rtrim(UPLOAD_DIR, '/\\'), '', $destPath), '/\\');
        
                $sopClass = $tags['SOPClassUID'] ?? null;
                $instNum  = isset($tags['InstanceNumber']) ? (int)$tags['InstanceNumber'] : null;
                $rowz = isset($tags['Rows']) ? (int)$tags['Rows'] : null;
                $cols = isset($tags['Columns']) ? (int)$tags['Columns'] : null;
                $bits = isset($tags['BitsAllocated']) ? (int)$tags['BitsAllocated'] : null;
        
                [$psRow, $psCol] = parseTwoNumbers($tags['PixelSpacing'] ?? null);
                $sliceThickness  = toFloatOrNull($tags['SliceThickness'] ?? null);
                $sliceLocation   = toFloatOrNull($tags['SliceLocation'] ?? null);
                [$ipX, $ipY, $ipZ] = parseThreeNumbers($tags['ImagePositionPatient'] ?? null);
                $windowCenter = firstNumber($tags['WindowCenter'] ?? null);
                $windowWidth  = firstNumber($tags['WindowWidth'] ?? null);
                $transferSyntax = $tags['TransferSyntaxUID'] ?? null;
        
                $stmt = $db->prepare("
                    INSERT INTO instances
                        (series_id, sop_instance_uid, sop_class_uid, instance_number, file_path, source_file_name, source_relative_path, file_size,
                         rowz, cols, bits_allocated, pixel_spacing_row, pixel_spacing_col, slice_thickness,
                         slice_location, image_position_x, image_position_y, image_position_z,
                         window_center, window_width, transfer_syntax)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $seriesDbId, $sopUID, $sopClass, $instNum, $relPath, $clientFileName, $clientRelativePath, $fileSize,
                    $rowz, $cols, $bits, $psRow, $psCol, $sliceThickness,
                    $sliceLocation, $ipX, $ipY, $ipZ, $windowCenter, $windowWidth, $transferSyntax
                ]);
                $instanceDbId = (int)$db->lastInsertId();
        
                $stmt = $db->prepare("UPDATE series SET num_instances = (SELECT COUNT(*) FROM instances WHERE series_id = ?) WHERE id = ?");
                $stmt->execute([$seriesDbId, $seriesDbId]);
        
                $stmt = $db->prepare("
                    UPDATE studies
                    SET
                      num_series = (SELECT COUNT(*) FROM series WHERE study_id = ?),
                      num_instances = (
                        SELECT COUNT(*) FROM instances i
                        INNER JOIN series s ON s.id = i.series_id
                        WHERE s.study_id = ?
                      )
                    WHERE id = ?
                ");
                $stmt->execute([$studyDbId, $studyDbId, $studyDbId]);
        
                $db->commit();
                jsonResponse([
                    'success'    => true,
                    'studyId'    => $studyDbId,
                    'seriesId'   => $seriesDbId,
                    'instanceId' => $instanceDbId,
                    'patientName'=> $patientNameInput,
                    'imageType'  => $imageTypeInput,
                ]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                jsonResponse(['success' => false, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
            }
            break;
        
        case 'upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success' => false, 'message' => 'POST required'], 405);
            if (!isset($_FILES['file'])) jsonResponse(['success' => false, 'message' => 'No file received'], 400);
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) jsonResponse(['success' => false, 'message' => 'Upload error code: ' . $_FILES['file']['error']], 400);

            $patientNameInput = trim((string)($_POST['patientName'] ?? ''));
            $patientAgeInput  = trim((string)($_POST['patientAge'] ?? ''));
            $patientWeightInput = trim((string)($_POST['patientWeight'] ?? ''));
            $patientSexInput = strtoupper(trim((string)($_POST['patientSex'] ?? '')));
            $imageTypeInput   = trim((string)($_POST['imageType'] ?? ''));
            $clientFileName = trim((string)($_POST['clientFileName'] ?? ''));
            $clientRelativePath = trim((string)($_POST['clientRelativePath'] ?? ''));
            $seriesPrefix = deriveSeriesPrefix($clientRelativePath !== '' ? $clientRelativePath : $clientFileName);
            $doctorId = (int)($_POST['doctorId'] ?? 0);
            if ($doctorId <= 0) jsonResponse(['success'=>false,'message'=>'Doctor is required'], 400);
            
            $chk = db()->prepare("SELECT id FROM doctors WHERE id=? LIMIT 1");
            $chk->execute([$doctorId]);
            if (!$chk->fetch()) jsonResponse(['success'=>false,'message'=>'Invalid doctor'], 400);
            
            $allowedTypes = ['CT_SCAN', 'MRI', 'XRAY', 'ULTRASOUND', 'PET_SCAN', 'MAMMOGRAPHY', 'ANGIOGRAPHY', 'FLUOROSCOPY'];
            if ($patientNameInput === '' || $patientAgeInput === '' || $patientWeightInput === '' || $imageTypeInput === '' || $patientSexInput === '') {
                jsonResponse(['success' => false, 'message' => 'Missing patient metadata (name, age, weight, sex, imageType)'], 400);
            }
            if (!in_array($imageTypeInput, $allowedTypes, true)) jsonResponse(['success' => false, 'message' => 'Invalid imageType'], 400);
            if (!in_array($patientSexInput, ['M','F'], true)) {
                jsonResponse(['success' => false, 'message' => 'Invalid sex'], 400);
            }
            
            $patientAge = (int)$patientAgeInput;
            $patientWeight = (float)$patientWeightInput;
            if ($patientAge < 0 || $patientAge > 130) jsonResponse(['success' => false, 'message' => 'Invalid age'], 400);
            if ($patientWeight <= 0 || $patientWeight > 500) jsonResponse(['success' => false, 'message' => 'Invalid weight'], 400);

            $file = $_FILES['file'];
            $tmpPath = (string)$file['tmp_name'];
            $origName = basename((string)$file['name']);
            $fileSize = (int)$file['size'];

            if ($fileSize <= 0) jsonResponse(['success' => false, 'message' => 'Empty file'], 400);
            if ($fileSize > MAX_FILE_SIZE) jsonResponse(['success' => false, 'message' => 'File too large'], 400);

            if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true)) jsonResponse(['success' => false, 'message' => 'Cannot create upload directory: ' . UPLOAD_DIR], 500);
            if (!is_writable(UPLOAD_DIR)) jsonResponse(['success' => false, 'message' => 'Upload directory is not writable: ' . UPLOAD_DIR], 500);

            $tags = parseDicomTags($tmpPath);
            if ($tags === false) jsonResponse(['success' => false, 'message' => 'Invalid DICOM file: ' . $origName], 400);

            $db = db();
            $db->beginTransaction();

            try {
                $patientId = $tags['PatientID'] ?? ('FORM_' . md5(strtolower($patientNameInput) . '|' . $patientAge));
                $patientName = $patientNameInput;
                // Use explicit form value first; fallback to DICOM only if missing (shouldn't happen because required)
                $sexFromDicom = strtoupper((string)($tags['PatientSex'] ?? 'U'));
                $sex = in_array($patientSexInput, ['M','F'], true)
                    ? $patientSexInput
                    : (in_array($sexFromDicom, ['M','F'], true) ? $sexFromDicom : 'U');

                $birthDate = formatDicomDate((string)($tags['PatientBirthDate'] ?? ''));
                if ($birthDate === '' && $patientAge >= 0) {
                    $year = (int)date('Y') - $patientAge;
                    $birthDate = sprintf('%04d-01-01', max(1900, $year));
                }

                $stmt = $db->prepare("
                    INSERT INTO patients (patient_id, patient_name, birth_date, sex)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        patient_name = VALUES(patient_name),
                        birth_date   = VALUES(birth_date),
                        sex          = VALUES(sex)
                ");
                $stmt->execute([$patientId, $patientName, $birthDate ?: null, $sex]);

                $stmt = $db->prepare("SELECT id FROM patients WHERE patient_id = ?");
                $stmt->execute([$patientId]);
                $patientDbId = (int)$stmt->fetchColumn();
                if ($patientDbId <= 0) throw new RuntimeException('Failed to resolve patient ID');

                $studyUID = 'FORMSTUDY.' . sha1(strtolower($patientNameInput) . '|' . (string)$patientAge . '|' . strtoupper($imageTypeInput) . '|DOC:' . $doctorId);
                $studyDate = formatDicomDate((string)($tags['StudyDate'] ?? ''));
                $studyTime = formatDicomTime((string)($tags['StudyTime'] ?? ''));
                $studyDesc = $tags['StudyDescription'] ?? $imageTypeInput;
                $accession = $tags['AccessionNumber'] ?? null;
                $refPhys   = $tags['ReferringPhysician'] ?? null;
                $modality  = $imageTypeInput;

                $stmt = $db->prepare("
                    INSERT INTO studies
                        (patient_id, doctor_id, study_instance_uid, study_date, study_time, study_description, accession_number, referring_physician, modality)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        study_date = VALUES(study_date),
                        study_time = VALUES(study_time),
                        study_description = VALUES(study_description),
                        modality = VALUES(modality),
                        doctor_id = VALUES(doctor_id)
                ");
                $stmt->execute([
                    $patientDbId, $doctorId, $studyUID, $studyDate ?: null, $studyTime ?: null,
                    $studyDesc, $accession, $refPhys, $modality
                ]);

                $stmt = $db->prepare("SELECT id FROM studies WHERE study_instance_uid = ?");
                $stmt->execute([$studyUID]);
                $studyDbId = (int)$stmt->fetchColumn();
                if ($studyDbId <= 0) throw new RuntimeException('Failed to resolve study ID');

                $seriesUID = 'PFX.' . sha1((string)$studyDbId . '|' . $seriesPrefix);
                $stmt = $db->prepare("SELECT id FROM series WHERE study_id = ? AND series_instance_uid = ? LIMIT 1");
                $stmt->execute([$studyDbId, $seriesUID]);
                $seriesDbId = (int)$stmt->fetchColumn();

                if ($seriesDbId <= 0) {
                    $stmt = $db->prepare("SELECT COALESCE(MAX(series_number), 0) + 1 FROM series WHERE study_id = ?");
                    $stmt->execute([$studyDbId]);
                    $nextSeriesNumber = (int)$stmt->fetchColumn();

                    $seriesDesc = $seriesPrefix;
                    $bodyPart = $tags['BodyPartExamined'] ?? null;

                    $stmt = $db->prepare("
                        INSERT INTO series
                            (study_id, series_instance_uid, series_number, series_description, modality, body_part)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$studyDbId, $seriesUID, $nextSeriesNumber, $seriesDesc, $modality, $bodyPart]);
                    $seriesDbId = (int)$db->lastInsertId();
                }

                if ($seriesDbId <= 0) throw new RuntimeException('Failed to resolve series ID');

                $instNumForKey = isset($tags['InstanceNumber']) ? (string)$tags['InstanceNumber'] : '';
                $slForKey = isset($tags['SliceLocation']) ? (string)$tags['SliceLocation'] : '';
                $posForKey = isset($tags['ImagePositionPatient']) ? (is_array($tags['ImagePositionPatient']) ? implode('\\', $tags['ImagePositionPatient']) : (string)$tags['ImagePositionPatient']) : '';
                $fileHash = @hash_file('sha1', $tmpPath) ?: '';

                $sopUID = 'DB.' . sha1(
                    $studyDbId . '|' . $seriesDbId . '|' . $seriesPrefix . '|' .
                    $origName . '|' . $instNumForKey . '|' . $slForKey . '|' . $posForKey . '|' . $fileHash
                );

                $stmt = $db->prepare("SELECT id FROM instances WHERE sop_instance_uid = ? LIMIT 1");
                $stmt->execute([$sopUID]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $db->rollBack();
                    jsonResponse(['success' => true, 'duplicate' => true, 'message' => 'Already exists in this series', 'studyId' => $studyDbId]);
                }

                $safePatientName = preg_replace('/[^A-Za-z0-9._-]/', '_', $patientNameInput);
                $safeType = preg_replace('/[^A-Za-z0-9._-]/', '_', $imageTypeInput);
                $safeSeries = preg_replace('/[^A-Za-z0-9._-]/', '_', $seriesPrefix);

                $originalName = $clientFileName !== '' ? $clientFileName : $origName;
                $originalNameNoExt = preg_replace('/\.[^.]+$/', '', $originalName);
                $safeOriginal = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalNameNoExt) . '.dcm';

                $subDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $safePatientName . DIRECTORY_SEPARATOR . $safeType . DIRECTORY_SEPARATOR . $safeSeries . DIRECTORY_SEPARATOR;
                if (!is_dir($subDir) && !mkdir($subDir, 0775, true)) throw new RuntimeException('Cannot create series directory: ' . $subDir);
                if (!is_writable($subDir)) throw new RuntimeException('Series directory not writable: ' . $subDir);

                $destPath = $subDir . $safeOriginal;
                if (is_file($destPath)) $destPath = $subDir . $originalNameNoExt . '_' . substr(sha1($fileHash ?: microtime(true)), 0, 8) . '.dcm';
                if (!move_uploaded_file($tmpPath, $destPath)) throw new RuntimeException('move_uploaded_file failed');

                $relPath = ltrim(str_replace(rtrim(UPLOAD_DIR, '/\\'), '', $destPath), '/\\');

                $sopClass = $tags['SOPClassUID'] ?? null;
                $instNum  = isset($tags['InstanceNumber']) ? (int)$tags['InstanceNumber'] : null;
                $rowz = isset($tags['Rows']) ? (int)$tags['Rows'] : null;
                $cols = isset($tags['Columns']) ? (int)$tags['Columns'] : null;
                $bits = isset($tags['BitsAllocated']) ? (int)$tags['BitsAllocated'] : null;

                [$psRow, $psCol] = parseTwoNumbers($tags['PixelSpacing'] ?? null);
                $sliceThickness  = toFloatOrNull($tags['SliceThickness'] ?? null);
                $sliceLocation   = toFloatOrNull($tags['SliceLocation'] ?? null);
                [$ipX, $ipY, $ipZ] = parseThreeNumbers($tags['ImagePositionPatient'] ?? null);
                $windowCenter = firstNumber($tags['WindowCenter'] ?? null);
                $windowWidth  = firstNumber($tags['WindowWidth'] ?? null);
                $transferSyntax = $tags['TransferSyntaxUID'] ?? null;

                $stmt = $db->prepare("
                    INSERT INTO instances
                        (series_id, sop_instance_uid, sop_class_uid, instance_number, file_path, source_file_name, source_relative_path, file_size,
                         rowz, cols, bits_allocated, pixel_spacing_row, pixel_spacing_col, slice_thickness,
                         slice_location, image_position_x, image_position_y, image_position_z,
                         window_center, window_width, transfer_syntax)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $seriesDbId, $sopUID, $sopClass, $instNum, $relPath, $clientFileName, $clientRelativePath, $fileSize,
                    $rowz, $cols, $bits, $psRow, $psCol, $sliceThickness,
                    $sliceLocation, $ipX, $ipY, $ipZ, $windowCenter, $windowWidth, $transferSyntax
                ]);
                $instanceDbId = (int)$db->lastInsertId();

                $stmt = $db->prepare("UPDATE series SET num_instances = (SELECT COUNT(*) FROM instances WHERE series_id = ?) WHERE id = ?");
                $stmt->execute([$seriesDbId, $seriesDbId]);

                $stmt = $db->prepare("
                    UPDATE studies
                    SET
                      num_series = (SELECT COUNT(*) FROM series WHERE study_id = ?),
                      num_instances = (
                        SELECT COUNT(*) FROM instances i
                        INNER JOIN series s ON s.id = i.series_id
                        WHERE s.study_id = ?
                      )
                    WHERE id = ?
                ");
                $stmt->execute([$studyDbId, $studyDbId, $studyDbId]);

                $db->commit();
                jsonResponse([
                    'success'      => true,
                    'studyId'      => $studyDbId,
                    'seriesId'     => $seriesDbId,
                    'instanceId'   => $instanceDbId,
                    'patientName'  => $patientNameInput,
                    'patientAge'   => $patientAge,
                    'patientWeight'=> $patientWeight,
                    'imageType'    => $imageTypeInput
                ]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                jsonResponse(['success' => false, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
            }
            break;

        case 'wado':
        case 'wadouri':
            $instanceId = (int)($_GET['instance'] ?? 0);
            if ($instanceId <= 0) {
                http_response_code(400);
                exit('Invalid instance id');
            }
        
            $stmt = db()->prepare("
                SELECT i.file_path
                FROM instances i
                WHERE i.id = ?
                LIMIT 1
            ");
            $stmt->execute([$instanceId]);
            $row = $stmt->fetch();
        
            if (!$row || empty($row['file_path'])) {
                http_response_code(404);
                exit('Not found');
            }
        
            $stored = (string)$row['file_path'];
        
            // If DB path is absolute, use it; else prepend UPLOAD_DIR
            if (preg_match('/^(\/|[A-Za-z]:\\\\)/', $stored)) {
                $fullPath = $stored;
            } else {
                $fullPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . ltrim($stored, '/\\');
            }
        
            $real = realpath($fullPath);
            if ($real === false || !is_file($real) || !is_readable($real)) {
                http_response_code(404);
                exit('File missing');
            }
        
            if (ob_get_length()) { ob_end_clean(); }
        
            header('Content-Type: application/dicom');
            header('Content-Length: ' . (string)filesize($real));
            header('Content-Disposition: inline; filename="' . basename($real) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Access-Control-Allow-Origin: *');
        
            readfile($real);
            exit;

        case 'tags':
            $instanceId = (int)($_GET['instance'] ?? 0);
            if ($instanceId <= 0) jsonResponse(['success' => false, 'message' => 'Invalid instance id'], 400);

            $stmt = db()->prepare("
                SELECT i.file_path
                FROM instances i
                JOIN series se ON se.id = i.series_id
                JOIN studies st ON st.id = se.study_id
                WHERE i.id = ? AND st.doctor_id = ?
                LIMIT 1
            ");
            $stmt->execute([$instanceId, $doctorId]);
            $inst = $stmt->fetch();

            if (!$inst) jsonResponse(['success' => false, 'message' => 'Not found'], 404);

            $fullPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . ltrim((string)$inst['file_path'], '/\\');
            if (!is_file($fullPath)) jsonResponse(['success' => false, 'message' => 'File missing'], 404);

            $tags = parseDicomTags($fullPath);
            if ($tags === false) jsonResponse(['success' => false, 'message' => 'Failed to parse DICOM'], 500);

            jsonResponse(['success' => true, 'tags' => $tags]);
            break;
        
        case 'save_report':
            // reporter only
            if (!reporterLoggedIn()) {
                jsonResponse(['success' => false, 'message' => 'Unauthorized reporter'], 401);
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                jsonResponse(['success' => false, 'message' => 'POST required'], 405);
            }
        
            $payload = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                jsonResponse(['success' => false, 'message' => 'Invalid JSON'], 400);
            }
        
            $reporter = currentReporter();
            $reporterId = (int)$reporter['id'];
        
            $patientId = (int)($payload['patientId'] ?? 0);
            
            // ★ FIX: Treat empty strings and 0 as null (no study linked)
            $rawStudyId = $payload['studyId'] ?? null;
            $studyId = ($rawStudyId !== null && $rawStudyId !== '' && (int)$rawStudyId > 0) ? (int)$rawStudyId : null;

            $reportText = trim((string)($payload['reportText'] ?? ''));
            $reportHtml = trim((string)($payload['reportHtml'] ?? ''));
        
            if ($patientId <= 0 || ($reportText === '' && $reportHtml === '')) {
                jsonResponse(['success' => false, 'message' => 'Missing patient/report text'], 400);
            }
        
            $db = db();
        
            $chk = $db->prepare("SELECT id FROM patients WHERE id = ? LIMIT 1");
            $chk->execute([$patientId]);
            if (!$chk->fetch()) {
                jsonResponse(['success' => false, 'message' => 'Patient not found'], 404);
            }
        
            if ($studyId !== null) {
                $chk2 = $db->prepare("SELECT id FROM studies WHERE id = ? AND patient_id = ? LIMIT 1");
                $chk2->execute([$studyId, $patientId]);
                if (!$chk2->fetch()) {
                    jsonResponse(['success' => false, 'message' => 'Invalid study for patient'], 400);
                }
            }
            
            $finalReportContent = $reportHtml !== '' ? $reportHtml : $reportText;
        
            $stmt = $db->prepare("
                INSERT INTO patient_reports (patient_id, study_id, reporter_id, report_text)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$patientId, $studyId, $reporterId, $finalReportContent]);
        
            jsonResponse([
                'success' => true,
                'reportId' => (int)$db->lastInsertId()
            ]);
            break;
        
        case 'get_reports':
            $patientId = (int)($_GET['patient'] ?? 0);
            if ($patientId <= 0) {
                jsonResponse(['success' => false, 'message' => 'Invalid patient'], 400);
            }
        
            $stmt = db()->prepare("
                SELECT
                    pr.id, pr.patient_id, pr.study_id, pr.report_text, pr.created_at, pr.updated_at,
                    r.id AS reporter_id, r.full_name AS reporter_name
                FROM patient_reports pr
                JOIN reporters r ON r.id = pr.reporter_id
                WHERE pr.patient_id = ?
                ORDER BY pr.updated_at DESC, pr.id DESC
            ");
            $stmt->execute([$patientId]);
        
            jsonResponse(['success' => true, 'reports' => $stmt->fetchAll()]);
            break;
        
        case 'save_annotations':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success' => false, 'message' => 'POST required'], 405);

            $body = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($body)) jsonResponse(['success' => false, 'message' => 'Invalid JSON body'], 400);

            $instanceId = (int)($body['instanceId'] ?? 0);
            $toolName   = trim((string)($body['toolName'] ?? ''));
            $toolData   = $body['toolData'] ?? null;
            $createdBy  = trim((string)($body['createdBy'] ?? 'Anonymous'));

            if ($instanceId <= 0 || $toolName === '' || $toolData === null) jsonResponse(['success' => false, 'message' => 'Invalid params'], 400);

            $check = db()->prepare("
                SELECT i.id
                FROM instances i
                JOIN series se ON se.id = i.series_id
                JOIN studies st ON st.id = se.study_id
                WHERE i.id = ? AND st.doctor_id = ?
                LIMIT 1
            ");
            $check->execute([$instanceId, $doctorId]);
            if (!$check->fetch()) jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);

            $stmt = db()->prepare("INSERT INTO annotations (instance_id, tool_name, tool_data, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$instanceId, $toolName, json_encode($toolData), $createdBy]);

            jsonResponse(['success' => true, 'id' => (int)db()->lastInsertId()]);
            break;

        case 'load_annotations':
            $instanceId = (int)($_GET['instance'] ?? 0);
            if ($instanceId <= 0) jsonResponse(['success' => false, 'message' => 'Invalid instance id'], 400);

            $check = db()->prepare("
                SELECT i.id
                FROM instances i
                JOIN series se ON se.id = i.series_id
                JOIN studies st ON st.id = se.study_id
                WHERE i.id = ? AND st.doctor_id = ?
                LIMIT 1
            ");
            $check->execute([$instanceId, $doctorId]);
            if (!$check->fetch()) jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);

            $stmt = db()->prepare("
                SELECT id, instance_id, tool_name, tool_data, created_by, created_at
                FROM annotations
                WHERE instance_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$instanceId]);

            jsonResponse(['success' => true, 'annotations' => $stmt->fetchAll()]);
            break;

        case 'delete_study':
            if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);

            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) jsonResponse(['success' => false, 'message' => 'Invalid id'], 400);

            $db = db();
            $db->beginTransaction();

            try {
                $owner = $db->prepare("SELECT id FROM studies WHERE id = ? AND doctor_id = ? LIMIT 1");
                $owner->execute([$id, $doctorId]);
                if (!$owner->fetch()) {
                    $db->rollBack();
                    jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
                }

                $stmt = $db->prepare("
                    SELECT i.file_path
                    FROM instances i
                    INNER JOIN series s ON s.id = i.series_id
                    INNER JOIN studies st ON st.id = s.study_id
                    WHERE st.id = ?
                ");
                $stmt->execute([$id]);
                $files = $stmt->fetchAll();

                $stmt = $db->prepare("DELETE FROM studies WHERE id = ? AND doctor_id = ?");
                $stmt->execute([$id, $doctorId]);

                foreach ($files as $f) {
                    $fp = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . ltrim((string)$f['file_path'], '/\\');
                    if (is_file($fp)) @unlink($fp);
                }

                $db->commit();
                jsonResponse(['success' => true]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                jsonResponse(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
            }
            break;

        case 'stats':
            $stmt = db()->prepare("
                SELECT
                    COUNT(DISTINCT st.patient_id) AS patients,
                    COUNT(DISTINCT st.id) AS studies
                FROM studies st
                WHERE st.doctor_id = ?
            ");
            $stmt->execute([$doctorId]);
            $s = $stmt->fetch();

            $stmt2 = db()->prepare("
                SELECT COUNT(*) AS series
                FROM series se
                JOIN studies st ON st.id = se.study_id
                WHERE st.doctor_id = ?
            ");
            $stmt2->execute([$doctorId]);
            $series = (int)$stmt2->fetchColumn();

            $stmt3 = db()->prepare("
                SELECT COUNT(*) AS instances
                FROM instances i
                JOIN series se ON se.id = i.series_id
                JOIN studies st ON st.id = se.study_id
                WHERE st.doctor_id = ?
            ");
            $stmt3->execute([$doctorId]);
            $instances = (int)$stmt3->fetchColumn();

            jsonResponse(['success' => true, 'stats' => [
                'patients' => (int)($s['patients'] ?? 0),
                'studies' => (int)($s['studies'] ?? 0),
                'series' => $series,
                'instances' => $instances
            ]]);
            break;

        case 'health':
            jsonResponse([
                'success' => true,
                'upload_dir' => UPLOAD_DIR,
                'exists' => is_dir(UPLOAD_DIR),
                'writable' => is_writable(UPLOAD_DIR),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'php_version' => PHP_VERSION
            ]);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Unknown action: ' . $action], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
}

function toFloatOrNull(mixed $v): ?float { return is_numeric($v) ? (float)$v : null; }
function firstNumber(mixed $v): ?float { if (is_array($v)) $v = $v[0] ?? null; return is_numeric($v) ? (float)$v : null; }
function parseTwoNumbers(mixed $v): array {
    $a = $b = null;
    if (is_array($v) && count($v) >= 2) { $a = is_numeric($v[0]) ? (float)$v[0] : null; $b = is_numeric($v[1]) ? (float)$v[1] : null; }
    elseif (is_string($v) && strpos($v, '\\') !== false) { $p = explode('\\', $v); $a = isset($p[0]) && is_numeric($p[0]) ? (float)$p[0] : null; $b = isset($p[1]) && is_numeric($p[1]) ? (float)$p[1] : null; }
    return [$a, $b];
}
function parseThreeNumbers(mixed $v): array {
    $x = $y = $z = null;
    if (is_array($v) && count($v) >= 3) { $x = is_numeric($v[0]) ? (float)$v[0] : null; $y = is_numeric($v[1]) ? (float)$v[1] : null; $z = is_numeric($v[2]) ? (float)$v[2] : null; }
    elseif (is_string($v) && strpos($v, '\\') !== false) { $p = explode('\\', $v); $x = isset($p[0]) && is_numeric($p[0]) ? (float)$p[0] : null; $y = isset($p[1]) && is_numeric($p[1]) ? (float)$p[1] : null; $z = isset($p[2]) && is_numeric($p[2]) ? (float)$p[2] : null; }
    return [$x, $y, $z];
}

function parseDicomTags(string $path): array|false {
    $fp = @fopen($path, 'rb'); if (!$fp) return false;
    fseek($fp, 128); $magic = fread($fp, 4);
    if ($magic !== 'DICM') {
        fseek($fp, 0); $first = fread($fp, 4);
        if (strlen($first) < 1 || !(ord($first[0]) === 0x08 || ord($first[0]) === 0x02)) { fclose($fp); return false; }
        fseek($fp, 0);
    }
    $tags = []; $tagMap = dicomTagMap();
    while (!feof($fp)) {
        $buf = fread($fp, 4); if (strlen($buf) < 4) break;
        $group = unpack('v', substr($buf, 0, 2))[1];
        $element = unpack('v', substr($buf, 2, 2))[1];
        $tag = sprintf('%04X%04X', $group, $element);

        $vrBuf = fread($fp, 2); if (strlen($vrBuf) < 2) break; $vr = $vrBuf;
        $explicitVR = in_array($vr, ['AE','AS','AT','CS','DA','DS','DT','FL','FD','IS','LO','LT','OB','OD','OF','OW','PN','SH','SL','SQ','SS','ST','TM','UC','UI','UL','UN','US','UT','UR'], true);

        if ($explicitVR) {
            if (in_array($vr, ['OB','OD','OF','OW','SQ','UC','UN','UR','UT'], true)) { fread($fp, 2); $lenRaw = fread($fp, 4); if (strlen($lenRaw) < 4) break; $len = unpack('V', $lenRaw)[1]; }
            else { $lenRaw = fread($fp, 2); if (strlen($lenRaw) < 2) break; $len = unpack('v', $lenRaw)[1]; }
        } else {
            $lenBuf = fread($fp, 2); if (strlen($lenBuf) < 2) break; $len = unpack('V', $vrBuf . $lenBuf)[1]; $vr = 'UN';
        }

        if ($len === 0xFFFFFFFF) {
            // Undefined-length sequence: skip forward to its Sequence Delimitation Item
            // (tag FFFE,E0DD with zero length) instead of aborting the whole parse.
            $needle = "\xFE\xFF\xDD\xE0\x00\x00\x00\x00";
            $chunk = '';
            $found = false;
            while (!feof($fp)) {
                $b = fread($fp, 1);
                if ($b === '' || $b === false) break;
                $chunk .= $b;
                if (strlen($chunk) > 8) $chunk = substr($chunk, -8);
                if ($chunk === $needle) { $found = true; break; }
            }
            if (!$found) break; // genuinely truncated/corrupt file — bail out as before
            continue; // resume normal tag parsing right after the sequence
        }
        if ($len > 20 * 1024 * 1024) { fseek($fp, $len, SEEK_CUR); continue; }

        $value = '';
        if ($len > 0) { $value = fread($fp, $len); if (strlen($value) < $len) break; }

        if (!isset($tagMap[$tag])) continue;
        $name = $tagMap[$tag];
        $clean = rtrim($value, " \0");

        if (in_array($vr, ['DS', 'IS'], true)) {
            $parts = array_map('trim', explode('\\', $clean));
            if (count($parts) === 1) {
                $one = $parts[0];
                if ($one === '') $tags[$name] = '';
                else $tags[$name] = (strpos($one, '.') !== false) ? (float)$one : (int)$one;
            } else $tags[$name] = $parts;
        } elseif ($vr === 'US' && strlen($value) === 2) $tags[$name] = unpack('v', $value)[1];
        elseif ($vr === 'UL' && strlen($value) === 4) $tags[$name] = unpack('V', $value)[1];
        elseif ($vr === 'SS' && strlen($value) === 2) $tags[$name] = unpack('s', $value)[1];
        elseif ($vr === 'FL' && strlen($value) === 4) $tags[$name] = unpack('f', $value)[1];
        elseif ($vr === 'FD' && strlen($value) === 8) $tags[$name] = unpack('d', $value)[1];
        else {
            $parts = array_map('trim', explode('\\', $clean));
            $tags[$name] = (count($parts) === 1) ? $parts[0] : $parts;
        }
    }
    fclose($fp);
    return $tags;
}
function dicomTagMap(): array {
    return [
        '00020010'=>'TransferSyntaxUID','00080016'=>'SOPClassUID','00080018'=>'SOPInstanceUID','00080020'=>'StudyDate','00080021'=>'SeriesDate','00080023'=>'ContentDate',
        '00080030'=>'StudyTime','00080050'=>'AccessionNumber','00080060'=>'Modality','00080070'=>'Manufacturer','00080090'=>'ReferringPhysician',
        '00081010'=>'StationName','00081030'=>'StudyDescription','0008103E'=>'SeriesDescription','00100010'=>'PatientName','00100020'=>'PatientID',
        '00100030'=>'PatientBirthDate','00100040'=>'PatientSex','00101010'=>'PatientAge','00101030'=>'PatientWeight','00180050'=>'SliceThickness',
        '00200011'=>'SeriesNumber','00200013'=>'InstanceNumber','00200032'=>'ImagePositionPatient','00201041'=>'SliceLocation','00280010'=>'Rows',
        '00280011'=>'Columns','00280030'=>'PixelSpacing','00280100'=>'BitsAllocated','00281050'=>'WindowCenter','00281051'=>'WindowWidth',
        '0020000D'=>'StudyInstanceUID','0020000E'=>'SeriesInstanceUID',
    ];
}
function formatDicomDate(string $d): string { $d = preg_replace('/[^0-9]/', '', $d); return strlen($d)===8 ? substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2) : ''; }
function formatDicomTime(string $t): string { $t = preg_replace('/[^0-9]/', '', $t); return strlen($t)>=6 ? substr($t,0,2).':'.substr($t,2,2).':'.substr($t,4,2) : ''; }
function deriveSeriesPrefix(string $pathOrName): string {
    $base = basename($pathOrName); $base = preg_replace('/\.[^.]+$/', '', $base); $base = trim($base);
    if ($base === '') return 'SERIES_DEFAULT';
    if (preg_match('/^([A-Za-z]+-\d+)-\d+$/', $base, $m)) return strtoupper($m[1]);
    if (preg_match('/^([A-Za-z]+)\d+$/', $base, $m)) return strtoupper($m[1]);
    if (preg_match('/^(.*?)[\s._-]?\d+$/', $base, $m)) { $p = trim((string)$m[1], " \t\n\r\0\x0B._-"); if ($p !== '') return strtoupper($p); }
    return strtoupper($base);
}
?>