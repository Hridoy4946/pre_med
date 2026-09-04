<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Doctor'], true)) {
    header('Location: login.php');
    exit();
}

$role = $_SESSION['role'];
$isStaff = $role === 'Staff';
$isDoctor = $role === 'Doctor';
$userId = (int)$_SESSION['user_id'];

$msg = '';
$error = '';

// Handle POST actions for inventory management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Quick stock restock
    if ($action === 'quick_restock') {
        $medId = filter_input(INPUT_POST, 'medication_id', FILTER_VALIDATE_INT);
        $addQty = filter_input(INPUT_POST, 'add_quantity', FILTER_VALIDATE_INT);

        if ($medId && $addQty && $addQty > 0) {
            try {
                $oldStmt = $pdo->prepare("SELECT MedicationName, StockQuantity, UnitCost, InventoryStatus FROM MEDICATION WHERE MedicationID = ?");
                $oldStmt->execute([$medId]);
                $oldData = $oldStmt->fetch();

                if ($oldData) {
                    $newQty = (int)$oldData['StockQuantity'] + $addQty;
                    $newStatus = ($newQty < 50) ? 'Reorder Needed' : 'Available';

                    $upStmt = $pdo->prepare("UPDATE MEDICATION SET StockQuantity = ?, InventoryStatus = ? WHERE MedicationID = ?");
                    $upStmt->execute([$newQty, $newStatus, $medId]);

                    // Audit log entry
                    $auditStmt = $pdo->prepare("INSERT INTO AUDIT_LOG (ActionType, TableName, RecordID, OldData, NewData, UserID) VALUES ('UPDATE', 'MEDICATION', ?, ?, ?, ?)");
                    $auditStmt->execute([
                        $medId,
                        json_encode($oldData),
                        json_encode(['MedicationName' => $oldData['MedicationName'], 'StockQuantity' => $newQty, 'InventoryStatus' => $newStatus]),
                        $userId
                    ]);

                    $msg = 'Added +' . $addQty . ' units to ' . htmlspecialchars($oldData['MedicationName']) . '. Current stock: ' . $newQty . '.';
                }
            } catch (Throwable $e) {
                $error = 'Failed to update stock: ' . $e->getMessage();
            }
        } else {
            $error = 'Please provide a valid restock quantity.';
        }
    }

    // 2. Full update item (name, stock, cost)
    elseif ($action === 'update_item') {
        $medId = filter_input(INPUT_POST, 'medication_id', FILTER_VALIDATE_INT);
        $name = trim($_POST['medication_name'] ?? '');
        $qty = filter_input(INPUT_POST, 'stock_quantity', FILTER_VALIDATE_INT);
        $unitCost = filter_input(INPUT_POST, 'unit_cost', FILTER_VALIDATE_FLOAT);

        if ($medId && $name !== '' && $qty !== false && $qty >= 0 && $unitCost !== false && $unitCost >= 0) {
            try {
                $oldStmt = $pdo->prepare("SELECT MedicationName, StockQuantity, UnitCost, InventoryStatus FROM MEDICATION WHERE MedicationID = ?");
                $oldStmt->execute([$medId]);
                $oldData = $oldStmt->fetch();

                $status = ($qty < 50) ? 'Reorder Needed' : 'Available';

                $upStmt = $pdo->prepare("UPDATE MEDICATION SET MedicationName = ?, StockQuantity = ?, UnitCost = ?, InventoryStatus = ? WHERE MedicationID = ?");
                $upStmt->execute([$name, $qty, $unitCost, $status, $medId]);

                // Audit log entry
                $auditStmt = $pdo->prepare("INSERT INTO AUDIT_LOG (ActionType, TableName, RecordID, OldData, NewData, UserID) VALUES ('UPDATE', 'MEDICATION', ?, ?, ?, ?)");
                $auditStmt->execute([
                    $medId,
                    json_encode($oldData),
                    json_encode(['MedicationName' => $name, 'StockQuantity' => $qty, 'UnitCost' => $unitCost, 'InventoryStatus' => $status]),
                    $userId
                ]);

                $msg = 'Item "' . htmlspecialchars($name) . '" updated successfully.';
            } catch (Throwable $e) {
                $error = 'Update failed: ' . $e->getMessage();
            }
        } else {
            $error = 'Please enter valid details (positive stock quantity and unit cost).';
        }
    }

    // 3. Add new product/medication
    elseif ($action === 'add_item') {
        $name = trim($_POST['medication_name'] ?? '');
        $qty = filter_input(INPUT_POST, 'stock_quantity', FILTER_VALIDATE_INT);
        $unitCost = filter_input(INPUT_POST, 'unit_cost', FILTER_VALIDATE_FLOAT);

        if ($name !== '' && $qty !== false && $qty >= 0 && $unitCost !== false && $unitCost >= 0) {
            try {
                $status = ($qty < 50) ? 'Reorder Needed' : 'Available';
                $insStmt = $pdo->prepare("INSERT INTO MEDICATION (MedicationName, StockQuantity, UnitCost, InventoryStatus) VALUES (?, ?, ?, ?)");
                $insStmt->execute([$name, $qty, $unitCost, $status]);
                $newId = (int)$pdo->lastInsertId();

                // Audit log entry
                $auditStmt = $pdo->prepare("INSERT INTO AUDIT_LOG (ActionType, TableName, RecordID, OldData, NewData, UserID) VALUES ('INSERT', 'MEDICATION', ?, NULL, ?, ?)");
                $auditStmt->execute([
                    $newId,
                    json_encode(['MedicationName' => $name, 'StockQuantity' => $qty, 'UnitCost' => $unitCost, 'InventoryStatus' => $status]),
                    $userId
                ]);

                $msg = 'New product "' . htmlspecialchars($name) . '" added to inventory.';
            } catch (Throwable $e) {
                $error = 'Product could not be added (it may already exist): ' . $e->getMessage();
            }
        } else {
            $error = 'Please provide product name, initial stock, and unit cost.';
        }
    }
}

// Fetch stats
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) AS TotalItems,
        COALESCE(SUM(StockQuantity), 0) AS TotalUnits,
        COALESCE(SUM(StockQuantity * UnitCost), 0) AS TotalValue,
        COUNT(CASE WHEN InventoryStatus = 'Reorder Needed' OR StockQuantity < 50 THEN 1 END) AS LowStockCount,
        COUNT(CASE WHEN InventoryStatus = 'Available' AND StockQuantity >= 50 THEN 1 END) AS HealthyStockCount
    FROM MEDICATION
");
$stats = $statsStmt->fetch() ?: ['TotalItems' => 0, 'TotalUnits' => 0, 'TotalValue' => 0, 'LowStockCount' => 0, 'HealthyStockCount' => 0];

// Fetch all medications
$itemsStmt = $pdo->query("
    SELECT MedicationID, MedicationName, StockQuantity, UnitCost, InventoryStatus,
           (StockQuantity * UnitCost) AS ItemTotalValue,
           (SELECT COUNT(*) FROM PRESCRIPTION_ITEM WHERE MedicationID = MEDICATION.MedicationID) AS PrescriptionUsageCount
    FROM MEDICATION
    ORDER BY (CASE WHEN StockQuantity < 50 THEN 0 ELSE 1 END), StockQuantity ASC, MedicationName ASC
");
$items = $itemsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pharmacy &amp; Supplies Inventory — PreMed</title>
    <meta name="description" content="Manage clinic medications, pharmaceuticals, stock quantities, and inventory reorders.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
    .inv-container { max-width: 1240px; margin: clamp(10px, 4vw, 36px) auto; }
    .inv-card {
        background: rgba(11,25,41,.97);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: clamp(18px, 2.5vw, 28px);
        margin-bottom: 24px;
        box-shadow: var(--shadow);
    }
    .stock-bar-wrap {
        width: 100%;
        height: 6px;
        background: rgba(255,255,255,.08);
        border-radius: 999px;
        margin-top: 6px;
        overflow: hidden;
    }
    .stock-bar {
        height: 100%;
        border-radius: 999px;
        transition: width .3s ease;
    }
    .stock-good { background: var(--green); }
    .stock-warn { background: var(--amber); }
    .stock-alert { background: var(--coral); }
    
    .quick-btn-group {
        display: inline-flex;
        gap: 4px;
        align-items: center;
    }
    .btn-qty {
        width: auto;
        margin: 0;
        padding: 4px 8px;
        font-size: 11px;
        font-weight: 700;
        border-radius: 5px;
        border: 1px solid rgba(15,200,228,.28);
        background: rgba(15,200,228,.08);
        color: var(--teal);
        cursor: pointer;
        transition: all .15s;
    }
    .btn-qty:hover {
        background: rgba(15,200,228,.22);
        transform: translateY(-1px);
    }

    /* Modal for item edit */
    #editModalOverlay {
        display: none; position: fixed; inset: 0; z-index: 9999;
        background: rgba(0,0,0,.7); backdrop-filter: blur(5px);
        align-items: center; justify-content: center;
    }
    #editModalOverlay.open { display: flex; }
    .edit-modal-box {
        background: #0b1c2e; border: 1px solid var(--line); border-radius: 12px;
        padding: 26px 30px; width: 440px; max-width: 95vw;
        box-shadow: 0 20px 60px rgba(0,0,0,.7);
    }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<div class="inv-container">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Staff Panel</p>
            <h2>Pharmacy &amp; Supplies Inventory</h2>
            <p class="page-subtitle">Track medication stock, restock supplies, update costs, and resolve reorder thresholds.</p>
        </div>
        <span class="role-pill role-staff">Staff</span>
    </div>

    <?php if ($msg):   ?><p class="notice success"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <!-- Overview Metrics -->
    <div class="metric-row cols-4" style="margin:20px 0;">
        <div>
            <span>Total Catalog Items</span>
            <strong><?= (int)$stats['TotalItems'] ?></strong>
        </div>
        <div>
            <span>Total Units In Stock</span>
            <strong style="color:var(--teal)"><?= number_format((int)$stats['TotalUnits']) ?></strong>
        </div>
        <div>
            <span>Reorder Alerts (&lt;50)</span>
            <strong style="color:<?= $stats['LowStockCount'] > 0 ? 'var(--coral)' : 'var(--green)' ?>">
                <?= (int)$stats['LowStockCount'] ?> item<?= $stats['LowStockCount'] != 1 ? 's' : '' ?>
            </strong>
        </div>
        <div>
            <span>Total Stock Valuation</span>
            <strong style="color:var(--green)">$<?= number_format((float)$stats['TotalValue'], 2) ?></strong>
        </div>
    </div>

    <!-- Add New Medication Accordion / Box -->
    <div class="inv-card" style="margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer;" onclick="toggleAddForm()" id="addFormToggle">
            <div>
                <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
                    <span>➕ Add New Medication or Medical Product</span>
                </h3>
                <p style="margin:4px 0 0;font-size:13px;color:var(--muted);">Add newly delivered pharmaceuticals or surgical supplies into system stock.</p>
            </div>
            <button type="button" class="btn btn-sm btn-auto" id="toggleAddBtn" style="margin:0;">+ Expand Form</button>
        </div>

        <form method="POST" id="addMedForm" style="display:none;margin-top:18px;padding-top:18px;border-top:1px solid var(--line);">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_item">

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;align-items:flex-end;">
                <div>
                    <label for="new_name">Product / Medication Name</label>
                    <input type="text" id="new_name" name="medication_name" placeholder="e.g. Paracetamol 500mg, Ceftriaxone" required>
                </div>
                <div>
                    <label for="new_stock">Initial Stock Quantity</label>
                    <input type="number" id="new_stock" name="stock_quantity" min="0" value="100" required>
                </div>
                <div>
                    <label for="new_cost">Unit Cost ($ USD)</label>
                    <input type="number" id="new_cost" name="unit_cost" min="0" step="0.01" value="15.00" required>
                </div>
                <div>
                    <button type="submit" style="width:100%;margin:0;">Save Product</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Inventory Listing -->
    <div class="inv-card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:14px;">
            <div>
                <h3 style="margin:0;">📦 Current Stock Inventory</h3>
                <p style="margin:2px 0 0;font-size:13px;color:var(--muted);">Click "+25", "+50", or "+100" for instant restock, or "Edit" to change pricing.</p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="text" id="invSearch" placeholder="🔍 Search product name..." onkeyup="filterInventory()" style="margin:0;padding:8px 14px;min-width:230px;font-size:13px;">
            </div>
        </div>

        <?php if ($items): ?>
        <div class="table-wrap" style="margin:0;max-height:560px;overflow-y:auto;">
            <table id="invTable">
                <thead>
                    <tr>
                        <th style="min-width:180px;">Product Name</th>
                        <th style="min-width:140px;">Current Units</th>
                        <th style="min-width:90px;">Unit Cost</th>
                        <th style="min-width:110px;">Total Value</th>
                        <th style="min-width:130px;">Inventory Status</th>
                        <th style="min-width:150px;">Quick Restock</th>
                        <th style="min-width:80px;text-align:right;">Edit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): 
                    $qty = (int)$item['StockQuantity'];
                    $isLow = ($qty < 50);
                    $barPercent = min(100, max(5, round(($qty / 200) * 100)));
                    $barClass = ($qty >= 100) ? 'stock-good' : (($qty >= 50) ? 'stock-warn' : 'stock-alert');
                ?>
                <tr class="inv-row" data-name="<?= strtolower(htmlspecialchars($item['MedicationName'])) ?>">
                    <td>
                        <strong><?= htmlspecialchars($item['MedicationName']) ?></strong><br>
                        <small style="color:var(--muted);">Rx usage: <?= (int)$item['PrescriptionUsageCount'] ?> prescriptions</small>
                    </td>
                    <td>
                        <div style="display:flex;align-items:baseline;justify-content:space-between;">
                            <strong style="font-size:15px;color:<?= $isLow ? 'var(--coral)' : 'var(--text)' ?>"><?= number_format($qty) ?> units</strong>
                        </div>
                        <div class="stock-bar-wrap">
                            <div class="stock-bar <?= $barClass ?>" style="width:<?= $barPercent ?>%;"></div>
                        </div>
                    </td>
                    <td style="font-weight:600;">$<?= number_format((float)$item['UnitCost'], 2) ?></td>
                    <td style="color:var(--teal);font-weight:700;">$<?= number_format((float)$item['ItemTotalValue'], 2) ?></td>
                    <td>
                        <?php if ($isLow): ?>
                            <span class="severity-badge severity-high" style="font-size:11px;">⚠ Reorder Needed</span>
                        <?php else: ?>
                            <span class="severity-badge severity-low" style="font-size:11px;">✓ Available</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="quick-btn-group">
                            <form method="POST" style="margin:0;display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="quick_restock">
                                <input type="hidden" name="medication_id" value="<?= (int)$item['MedicationID'] ?>">
                                <input type="hidden" name="add_quantity" value="25">
                                <button type="submit" class="btn-qty" title="Add 25 units">+25</button>
                            </form>
                            <form method="POST" style="margin:0;display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="quick_restock">
                                <input type="hidden" name="medication_id" value="<?= (int)$item['MedicationID'] ?>">
                                <input type="hidden" name="add_quantity" value="50">
                                <button type="submit" class="btn-qty" title="Add 50 units">+50</button>
                            </form>
                            <form method="POST" style="margin:0;display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="quick_restock">
                                <input type="hidden" name="medication_id" value="<?= (int)$item['MedicationID'] ?>">
                                <input type="hidden" name="add_quantity" value="100">
                                <button type="submit" class="btn-qty" title="Add 100 units">+100</button>
                            </form>
                        </div>
                    </td>
                    <td style="text-align:right;">
                        <button type="button" class="btn btn-sm btn-auto btn-ghost" onclick="openEditModal(
                            <?= (int)$item['MedicationID'] ?>,
                            <?= json_encode($item['MedicationName']) ?>,
                            <?= (int)$item['StockQuantity'] ?>,
                            <?= (float)$item['UnitCost'] ?>
                        )">✏ Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state compact">
                <strong>No inventory items catalogued</strong>
                <span>Add medications using the form above.</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editModalOverlay">
    <div class="edit-modal-box">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin:0;">Update Product Details</h3>
            <button type="button" onclick="closeEditModal()" style="background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="medication_id" id="edit_med_id">

            <label for="edit_med_name">Product Name</label>
            <input type="text" id="edit_med_name" name="medication_name" required style="margin-bottom:12px;">

            <label for="edit_stock">Stock Units</label>
            <input type="number" id="edit_stock" name="stock_quantity" min="0" required style="margin-bottom:12px;">

            <label for="edit_cost">Unit Cost ($)</label>
            <input type="number" id="edit_cost" name="unit_cost" min="0" step="0.01" required style="margin-bottom:18px;">

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost btn-auto" onclick="closeEditModal()" style="margin:0;">Cancel</button>
                <button type="submit" class="btn btn-auto" style="margin:0;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer_nav.php'; ?>

<script>
function toggleAddForm() {
    var form = document.getElementById('addMedForm');
    var btn = document.getElementById('toggleAddBtn');
    if (form.style.display === 'none' || form.style.display === '') {
        form.style.display = 'block';
        btn.textContent = '▲ Collapse';
    } else {
        form.style.display = 'none';
        btn.textContent = '+ Expand Form';
    }
}

function filterInventory() {
    var q = document.getElementById('invSearch').value.toLowerCase().trim();
    var rows = document.querySelectorAll('.inv-row');
    rows.forEach(function(row) {
        var name = row.dataset.name || '';
        row.style.display = name.includes(q) ? '' : 'none';
    });
}

function openEditModal(id, name, stock, cost) {
    document.getElementById('edit_med_id').value = id;
    document.getElementById('edit_med_name').value = name;
    document.getElementById('edit_stock').value = stock;
    document.getElementById('edit_cost').value = cost;
    document.getElementById('editModalOverlay').classList.add('open');
}

function closeEditModal() {
    document.getElementById('editModalOverlay').classList.remove('open');
}

document.getElementById('editModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>
</body>
</html>
