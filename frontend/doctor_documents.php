<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header('Location: login.php');
    exit();
}
$doctorId  = (int) $_SESSION['user_id'];
$patientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);

if (!$patientId) {
    header('Location: doctor_analytics.php');
    exit();
}

// Verify this doctor is the assigned doctor for this patient
$patient = db_fetch_one($conn, "
    SELECT U.Name AS PatientName, P.PatientCode
    FROM PATIENT P
    JOIN `USER` U ON U.UserID = P.UserID
    WHERE P.UserID = ? AND P.AssignedDoctorID = ?
", [$patientId, $doctorId]);

if (!$patient) {
    http_response_code(403);
    exit('You are not the assigned doctor for this patient.');
}

$documents = db_fetch_all($conn, "
    SELECT DocumentID, FileName, MimeType, UploadedAt
    FROM PATIENT_DOCUMENT
    WHERE PatientID = ?
    ORDER BY UploadedAt DESC
", [$patientId]);

$typeIcons = [
    'application/pdf'                                                          => '📄',
    'image/jpeg'                                                               => '🖼',
    'image/png'                                                                => '🖼',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '📝',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patient Documents — PreMed</title>
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
        /* ── Document Preview Pop-up Window ───────────────────────── */
        .doc-preview-modal-overlay {
            display: none !important;
            position: fixed !important;
            inset: 0 !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            z-index: 999999 !important;
            background: rgba(3, 10, 22, 0.88) !important;
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
            align-items: center !important;
            justify-content: center !important;
            padding: clamp(10px, 2.5vw, 24px) !important;
            box-sizing: border-box !important;
        }
        .doc-preview-modal-overlay.active {
            display: flex !important;
        }
        .doc-preview-modal-dialog {
            position: relative !important;
            width: min(96vw, 1000px) !important;
            height: min(90vh, 850px) !important;
            max-height: 90vh !important;
            background: #091a2c !important;
            border: 1px solid rgba(15, 200, 228, 0.3) !important;
            border-radius: 16px !important;
            display: flex !important;
            flex-direction: column !important;
            box-shadow: 0 28px 70px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(255, 255, 255, 0.08) !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
            margin: auto !important;
            animation: premedModalIn 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes premedModalIn {
            from { transform: scale(0.93) translateY(10px); opacity: 0; }
            to   { transform: scale(1) translateY(0); opacity: 1; }
        }
        .doc-preview-modal-header {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            padding: 14px 20px !important;
            background: #0d2238 !important;
            border-bottom: 1px solid var(--line) !important;
            flex-shrink: 0 !important;
            gap: 12px !important;
            box-sizing: border-box !important;
        }
        .doc-preview-header-info {
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            min-width: 0 !important;
        }
        .doc-preview-header-icon {
            font-size: 26px !important;
            line-height: 1 !important;
            flex-shrink: 0 !important;
        }
        .doc-preview-header-text {
            min-width: 0 !important;
        }
        .doc-preview-title {
            margin: 0 !important;
            font-size: 15px !important;
            font-weight: 700 !important;
            color: #f0f7ff !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            max-width: clamp(180px, 45vw, 600px) !important;
        }
        .doc-preview-meta {
            font-size: 11px !important;
            color: var(--teal) !important;
            display: block !important;
            margin-top: 2px !important;
            font-weight: 600 !important;
        }
        .doc-preview-header-actions {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            flex-shrink: 0 !important;
        }
        .doc-preview-modal-header .btn-download {
            width: auto !important;
            margin: 0 !important;
            padding: 8px 14px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            border-radius: 8px !important;
            white-space: nowrap !important;
        }
        button.doc-preview-close-btn,
        .doc-preview-modal-dialog .doc-preview-close-btn {
            width: 34px !important;
            min-width: 34px !important;
            max-width: 34px !important;
            height: 34px !important;
            margin: 0 !important;
            padding: 0 !important;
            border-radius: 8px !important;
            background: rgba(255, 255, 255, 0.08) !important;
            border: 1px solid var(--line) !important;
            color: var(--muted) !important;
            font-size: 22px !important;
            line-height: 1 !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-shadow: none !important;
            transform: none !important;
            transition: all 0.18s ease !important;
        }
        button.doc-preview-close-btn:hover {
            background: rgba(255, 95, 91, 0.25) !important;
            border-color: rgba(255, 95, 91, 0.5) !important;
            color: #ff7e79 !important;
            filter: brightness(1.2) !important;
        }
        .doc-preview-modal-body {
            flex: 1 !important;
            position: relative !important;
            background: #040c16 !important;
            overflow: hidden !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-sizing: border-box !important;
            height: 100% !important;
            min-height: 300px !important;
        }
        .doc-preview-iframe {
            width: 100% !important;
            height: 100% !important;
            border: none !important;
            background: #ffffff !important;
            box-sizing: border-box !important;
        }
        .doc-preview-image {
            max-width: 100% !important;
            max-height: 100% !important;
            object-fit: contain !important;
            padding: 16px !important;
            box-sizing: border-box !important;
        }
        .doc-preview-fallback {
            text-align: center !important;
            padding: 36px 20px !important;
            color: var(--muted) !important;
        }
        .doc-preview-fallback-icon {
            font-size: 44px !important;
            margin-bottom: 12px !important;
            display: block !important;
        }

        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 14px;
            margin-top: 16px;
        }
        .doc-card {
            background: rgba(255,255,255,.035);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: border-color .15s, background .15s;
        }
        .doc-card:hover { border-color: var(--teal); background: rgba(15,200,228,.05); }
        .doc-icon { font-size: 28px; line-height: 1; }
        .doc-name { font-weight: 700; font-size: 14px; color: var(--text); word-break: break-word; }
        .doc-meta { font-size: 12px; color: var(--muted); }
        .doc-download {
            display: inline-flex; align-items: center; gap: 5px;
            margin-top: auto;
            padding: 6px 14px; border-radius: 6px;
            background: rgba(15,200,228,.12); color: var(--teal);
            border: 1px solid rgba(15,200,228,.25);
            font-size: 12px; font-weight: 700;
            text-decoration: none; transition: background .15s;
        }
        .doc-download:hover { background: rgba(15,200,228,.22); }
        .code-badge {
            font-size:11px; font-family:monospace;
            background:rgba(15,200,228,.12); color:var(--teal);
            border:1px solid rgba(15,200,228,.25); border-radius:4px;
            padding:2px 7px; letter-spacing:.04em;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="container" style="max-width:900px;margin:clamp(10px,4vw,36px) auto;">

    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Doctor Panel</p>
            <h2>Patient Documents</h2>
            <p class="page-subtitle">
                Files uploaded by
                <strong><?= htmlspecialchars($patient['PatientName']) ?></strong>
                <span class="code-badge" style="margin-left:6px;"><?= htmlspecialchars($patient['PatientCode']) ?></span>
                for your review.
            </p>
        </div>
        <span class="role-pill role-doctor">Doctor</span>
    </div>

    <?php if ($documents): ?>
    <div class="doc-grid">
        <?php foreach ($documents as $doc):
            $icon = $typeIcons[$doc['MimeType']] ?? '📎';
        ?>
        <div class="doc-card">
            <div class="doc-icon"><?= $icon ?></div>
            <div class="doc-name"><?= htmlspecialchars($doc['FileName']) ?></div>
            <div style="display:flex;gap:8px;margin-top:auto;">
                <?php if (in_array($doc['MimeType'], ['application/pdf','image/jpeg','image/png'])): ?>
                <button type="button" class="btn btn-sm btn-auto btn-view js-preview-doc"
                   data-doc-id="<?= (int)$doc['DocumentID'] ?>"
                   data-file-name="<?= htmlspecialchars($doc['FileName']) ?>"
                   data-mime-type="<?= htmlspecialchars($doc['MimeType']) ?>"
                   data-preview-url="../backend/download_document.php?id=<?= (int)$doc['DocumentID'] ?>&view=1"
                   data-download-url="../backend/download_document.php?id=<?= (int)$doc['DocumentID'] ?>"
                   style="flex:1;justify-content:center;margin:0;">
                   &#x1F441; View
                </button>
                <?php endif; ?>
                <a class="doc-download" href="../backend/download_document.php?id=<?= (int)$doc['DocumentID'] ?>" style="flex:1;justify-content:center;margin:0;">
                    ↓ Download
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="empty-state" style="margin-top:24px;">
            <strong>No documents uploaded yet</strong>
            <span>This patient has not uploaded any medical documents for review.</span>
        </div>
    <?php endif; ?>

    <div style="margin-top:28px;">
        <a class="text-link" href="doctor_analytics.php">← Back to Clinical Analytics</a>
    </div>
</div>

<!-- Document Preview Modal Dialog -->
<div id="doc_preview_modal" class="doc-preview-modal-overlay" aria-hidden="true">
    <div class="doc-preview-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="doc_preview_title">
        <div class="doc-preview-modal-header">
            <div class="doc-preview-header-info">
                <span id="doc_preview_icon" class="doc-preview-header-icon">📄</span>
                <div class="doc-preview-header-text">
                    <h4 id="doc_preview_title" class="doc-preview-title">Document Preview</h4>
                    <span id="doc_preview_meta" class="doc-preview-meta">PDF Document</span>
                </div>
            </div>
            <div class="doc-preview-header-actions">
                <a id="doc_preview_download_btn" href="#" class="btn btn-sm btn-auto btn-download" download>
                    ↓ Download
                </a>
                <button type="button" class="doc-preview-close-btn" id="doc_preview_close_btn" aria-label="Close Preview">&times;</button>
            </div>
        </div>
        <div class="doc-preview-modal-body" id="doc_preview_body">
            <!-- Dynamic Preview Content -->
        </div>
    </div>
</div>

<script>
(function() {
    const previewModal = document.getElementById('doc_preview_modal');
    const previewTitle = document.getElementById('doc_preview_title');
    const previewMeta  = document.getElementById('doc_preview_meta');
    const previewIcon  = document.getElementById('doc_preview_icon');
    const previewBody  = document.getElementById('doc_preview_body');
    const previewDownload = document.getElementById('doc_preview_download_btn');
    const closeBtn     = document.getElementById('doc_preview_close_btn');

    function closePreview() {
        if (!previewModal) return;
        previewModal.classList.remove('active');
        previewModal.setAttribute('aria-hidden', 'true');
        if (previewBody) previewBody.innerHTML = '';
    }

    if (closeBtn) closeBtn.addEventListener('click', closePreview);

    if (previewModal) {
        previewModal.addEventListener('click', function(e) {
            if (e.target === previewModal) closePreview();
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && previewModal && previewModal.classList.contains('active')) {
            closePreview();
        }
    });

    document.querySelectorAll('.js-preview-doc').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const fileName    = this.dataset.fileName || 'Medical Document';
            const mimeType    = this.dataset.mimeType || '';
            const previewUrl  = this.dataset.previewUrl;
            const downloadUrl = this.dataset.downloadUrl;

            previewTitle.textContent = fileName;
            previewDownload.href = downloadUrl;
            previewDownload.setAttribute('download', fileName);

            let icon = '📄';
            let typeLabel = 'Medical Record';

            if (mimeType.includes('pdf')) {
                icon = '📄';
                typeLabel = 'PDF Document';
                previewBody.innerHTML = '<iframe class="doc-preview-iframe" src="' + previewUrl + '" title="' + fileName.replace(/"/g, '&quot;') + '"></iframe>';
            } else if (mimeType.startsWith('image/')) {
                icon = '🖼';
                typeLabel = 'Medical Image / Scan';
                previewBody.innerHTML = '<img class="doc-preview-image" src="' + previewUrl + '" alt="' + fileName.replace(/"/g, '&quot;') + '">';
            } else {
                icon = '📝';
                typeLabel = 'Document File';
                previewBody.innerHTML = '<div class="doc-preview-fallback">' +
                    '<span class="doc-preview-fallback-icon">📄</span>' +
                    '<h4 style="color:#f0f7ff;margin:0 0 8px;">Inline preview unavailable</h4>' +
                    '<p style="margin:0 0 16px;font-size:13px;color:var(--muted);">This file format cannot be rendered directly in the browser.</p>' +
                    '<a href="' + downloadUrl + '" class="btn btn-sm btn-auto btn-download" download="' + fileName.replace(/"/g, '&quot;') + '">↓ Download "' + fileName.replace(/"/g, '&quot;') + '" to open</a>' +
                    '</div>';
            }

            previewIcon.textContent = icon;
            previewMeta.textContent = typeLabel;

            previewModal.classList.add('active');
            previewModal.setAttribute('aria-hidden', 'false');
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer_nav.php'; ?>
</body>
</html>
