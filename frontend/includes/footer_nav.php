<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="bottom-actions">
    <?php if ($currentPage !== 'dashboard.php'): ?>
        <a href="dashboard.php">← Back to Dashboard</a>
    <?php endif; ?>
    <?php if (($_SESSION['role'] ?? '') === 'Doctor'): ?>
        <?php if ($currentPage !== 'doctor_analytics.php'): ?><a href="doctor_analytics.php">Clinical Analytics</a><?php endif; ?>
        <?php if ($currentPage !== 'clinical_records.php'): ?><a href="clinical_records.php">Enter Records</a><?php endif; ?>
        <?php if ($currentPage !== 'billing.php'): ?><a href="billing.php">Visit Billing</a><?php endif; ?>
    <?php elseif (($_SESSION['role'] ?? '') === 'Staff'): ?>
        <?php if ($currentPage !== 'staff_overview.php'): ?><a href="staff_overview.php">Operations</a><?php endif; ?>
        <?php if ($currentPage !== 'staff_appointments.php'): ?><a href="staff_appointments.php">Appointments</a><?php endif; ?>
        <?php if ($currentPage !== 'inventory.php'): ?><a href="inventory.php">Inventory</a><?php endif; ?>
        <?php if ($currentPage !== 'billing.php'): ?><a href="billing.php">Billing</a><?php endif; ?>
        <?php if ($currentPage !== 'reports.php'): ?><a href="reports.php">Reports</a><?php endif; ?>
    <?php elseif (($_SESSION['role'] ?? '') === 'Guardian'): ?>
        <?php if ($currentPage !== 'guardian_profile.php'): ?><a href="guardian_profile.php">Guardian Patient View</a><?php endif; ?>
    <?php else: ?>
        <?php if ($currentPage !== 'symptom_log.php'): ?><a class="primary-action" href="symptom_log.php">Log a Symptom</a><?php endif; ?>
        <?php if ($currentPage !== 'book_appointment.php'): ?><a href="book_appointment.php">Book Appointment</a><?php endif; ?>
        <?php if ($currentPage !== 'patient_records.php'): ?><a href="patient_records.php">Health Records</a><?php endif; ?>
    <?php endif; ?>
</div>
