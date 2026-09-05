<?php
require_once dirname(__DIR__, 2) . '/backend/db.php';
require_once dirname(__DIR__, 2) . '/backend/notifications.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'Patient';
$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['name'] ?? $role;

$isDoctor = $role === 'Doctor';
$isStaff = $role === 'Staff';
$isGuardian = $role === 'Guardian';

$userNotifications = [];
if ($userId > 0 && isset($pdo)) {
    $rawNotifs = get_user_notifications($pdo, $userId, $role);
    $cleared = $_SESSION['cleared_notifications'][$userId] ?? [];
    if (!empty($cleared)) {
        $userNotifications = array_values(array_filter($rawNotifs, function($n) use ($cleared) {
            return !in_array($n['id'] ?? '', $cleared, true);
        }));
    } else {
        $userNotifications = $rawNotifs;
    }
}
$unreadCount = count($userNotifications);
?>
<header class="site-header">
    <a class="site-brand" href="dashboard.php">
        <span class="brand-mark">+</span>
        <span>PreMed<small>Care portal</small></span>
    </a>
    <nav class="site-nav" aria-label="Main navigation">
        <a class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">Dashboard</a>
        <?php if ($isDoctor): ?>
            <a class="<?= $currentPage === 'doctor_appointments.php' ? 'active' : '' ?>" href="doctor_appointments.php">Appointments</a>
            <a class="<?= $currentPage === 'doctor_analytics.php' ? 'active' : '' ?>" href="doctor_analytics.php">Analytics</a>
            <a class="<?= $currentPage === 'clinical_records.php' ? 'active' : '' ?>" href="clinical_records.php">Records</a>
            <a class="<?= $currentPage === 'billing.php' ? 'active' : '' ?>" href="billing.php">Billing</a>
            <a class="<?= $currentPage === 'doctor_transfer.php' ? 'active' : '' ?>" href="doctor_transfer.php">Transfer</a>
            <a class="<?= $currentPage === 'record_management.php' ? 'active' : '' ?>" href="record_management.php">Audit Log</a>
        <?php elseif ($isStaff): ?>
            <a class="<?= $currentPage === 'staff_overview.php' ? 'active' : '' ?>" href="staff_overview.php">Operations</a>
            <a class="<?= $currentPage === 'staff_appointments.php' ? 'active' : '' ?>" href="staff_appointments.php">Appointments</a>
            <a class="<?= $currentPage === 'inventory.php' ? 'active' : '' ?>" href="inventory.php">Inventory</a>
            <a class="<?= $currentPage === 'billing.php' ? 'active' : '' ?>" href="billing.php">Billing</a>
            <a class="<?= $currentPage === 'reports.php' ? 'active' : '' ?>" href="reports.php">Reports</a>
        <?php elseif ($isGuardian): ?>
            <a class="<?= $currentPage === 'guardian_profile.php' ? 'active' : '' ?>" href="guardian_profile.php">Guardian View</a>
        <?php else: ?>
            <a class="<?= $currentPage === 'symptom_log.php' ? 'active' : '' ?>" href="symptom_log.php">Symptom Log</a>
            <a class="<?= $currentPage === 'book_appointment.php' ? 'active' : '' ?>" href="book_appointment.php">Appointments</a>
            <a class="<?= $currentPage === 'patient_records.php' ? 'active' : '' ?>" href="patient_records.php">Health Records</a>
        <?php endif; ?>
    </nav>

    <div class="header-right-group">
        <!-- Logged-in user name -->
        <div class="header-user-chip" title="Signed in as <?= htmlspecialchars($userName) ?>">
            <span class="header-user-avatar"><?= mb_strtoupper(mb_substr($userName, 0, 1)) ?></span>
            <span class="header-user-name"><?= htmlspecialchars($isDoctor ? format_doctor_name($userName) : $userName) ?></span>
            <?php if ($role !== 'Patient'): ?>
            <span class="header-user-role"><?= htmlspecialchars($role) ?></span>
            <?php endif; ?>
        </div>

        <!-- Notification Popover Trigger -->
        <div class="notif-dropdown-wrapper" id="notif_wrapper">
            <button type="button" class="notif-trigger-btn" id="notif_trigger_btn" aria-label="Notifications" title="Notifications" aria-expanded="false">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <?php if ($unreadCount > 0): ?>
                    <span class="notif-count-badge" id="notif_count_badge"><?= $unreadCount ?></span>
                <?php endif; ?>
            </button>

            <!-- Notifications Dropdown -->
            <div class="notif-popover" id="notif_popover" aria-hidden="true">
                <div class="notif-header">
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-weight:700;color:#f0f7ff;font-size:13px;">Notifications</span>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notif-tag notif-tag-info" id="notif_unread_pill"><?= $unreadCount ?> new</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($unreadCount > 0): ?>
                        <button type="button" class="notif-clear-btn" id="notif_clear_btn">Clear all</button>
                    <?php endif; ?>
                </div>

                <div class="notif-list" id="notif_list">
                    <?php if (!empty($userNotifications)): ?>
                        <?php foreach ($userNotifications as $n): 
                            $iconSymbol = match($n['type']) {
                                'urgent'   => '⚡',
                                'warning'  => '⚠',
                                'reminder' => '📋',
                                'success'  => '✓',
                                default    => '📅',
                            };
                        ?>
                            <div class="notif-item-wrapper" style="position:relative;">
                                <a href="<?= htmlspecialchars($n['link']) ?>" class="notif-item" data-notif-id="<?= htmlspecialchars($n['id']) ?>">
                                    <div class="notif-icon-box notif-icon-<?= htmlspecialchars($n['type']) ?>">
                                        <?= $iconSymbol ?>
                                    </div>
                                    <div class="notif-content" style="padding-right:18px;">
                                        <div class="notif-title-row">
                                            <span class="notif-title"><?= htmlspecialchars($n['title']) ?></span>
                                            <span class="notif-tag notif-tag-<?= htmlspecialchars($n['type']) ?>"><?= htmlspecialchars($n['badge']) ?></span>
                                        </div>
                                        <p class="notif-desc"><?= htmlspecialchars($n['body']) ?></p>
                                        <span class="notif-time"><?= htmlspecialchars($n['time']) ?></span>
                                    </div>
                                </a>
                                <button type="button" class="notif-item-dismiss" data-notif-id="<?= htmlspecialchars($n['id']) ?>" title="Dismiss notification" aria-label="Dismiss">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-empty" id="notif_empty">
                            <span style="font-size:24px;display:block;margin-bottom:6px;">🔔</span>
                            <strong style="color:#e8f4fb;">All caught up!</strong>
                            <p style="margin:2px 0 0;font-size:11px;color:var(--muted);">No unread clinical alerts or appointment updates.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="notif-footer">
                    <span>Clinical Alert &amp; Appointment Stream</span>
                </div>
            </div>
        </div>

        <a class="header-logout" href="../backend/logout.php"
           data-confirm-title="Confirm Sign Out"
           data-confirm-message="Are you sure you want to end your current PreMed session?"
           data-confirm-btn="Sign Out"
           data-confirm-type="warning">Logout</a>
    </div>
</header>

<!-- Global Reusable Confirmation Modal Dialog -->
<div id="premed_confirm_modal" class="confirm-modal-overlay" aria-hidden="true">
    <div class="confirm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="confirm_modal_title">
        <div class="confirm-modal-icon type-warning" id="confirm_modal_icon">
            <span id="confirm_icon_symbol">⚠</span>
        </div>
        <div class="confirm-modal-content">
            <h3 id="confirm_modal_title">Confirm Action</h3>
            <p id="confirm_modal_msg">Are you sure you want to proceed with this action?</p>
        </div>
        <div class="confirm-modal-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="confirm_modal_cancel" style="border:1px solid var(--line);background:transparent;color:var(--muted);">Cancel</button>
            <button type="button" class="btn btn-sm btn-danger" id="confirm_modal_proceed">Confirm</button>
        </div>
    </div>
</div>

<script>
(function() {
    // ─── 1. Notifications Dropdown Handler ─────────────────────────────
    const notifBtn     = document.getElementById('notif_trigger_btn');
    const notifPopover = document.getElementById('notif_popover');
    const notifWrapper = document.getElementById('notif_wrapper');
    const notifClear   = document.getElementById('notif_clear_btn');
    const notifBadge   = document.getElementById('notif_count_badge');
    const notifPill    = document.getElementById('notif_unread_pill');
    const notifList    = document.getElementById('notif_list');

    if (notifBtn && notifPopover) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = notifPopover.classList.toggle('show');
            notifBtn.setAttribute('aria-expanded', isOpen);
            notifPopover.setAttribute('aria-hidden', !isOpen);
        });

        // Close when clicking outside
        document.addEventListener('click', (e) => {
            if (notifWrapper && !notifWrapper.contains(e.target)) {
                notifPopover.classList.remove('show');
                notifBtn.setAttribute('aria-expanded', 'false');
                notifPopover.setAttribute('aria-hidden', 'true');
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && notifPopover.classList.contains('show')) {
                notifPopover.classList.remove('show');
                notifBtn.setAttribute('aria-expanded', 'false');
                notifPopover.setAttribute('aria-hidden', 'true');
                notifBtn.focus();
            }
        });

        // Clear All action
        if (notifClear) {
            notifClear.addEventListener('click', (e) => {
                e.stopPropagation();

                // Collect IDs of active notifications
                const notifItems = notifList ? notifList.querySelectorAll('[data-notif-id]') : [];
                const ids = Array.from(notifItems).map(item => item.getAttribute('data-notif-id')).filter(Boolean);

                if (notifBadge) notifBadge.remove();
                if (notifPill) notifPill.remove();
                notifClear.remove();
                if (notifList) {
                    notifList.innerHTML = `
                        <div class="notif-empty">
                            <span style="font-size:24px;display:block;margin-bottom:6px;">✓</span>
                            <strong style="color:#e8f4fb;">All notifications cleared!</strong>
                            <p style="margin:2px 0 0;font-size:11px;color:var(--muted);">You are up to date on all clinical events.</p>
                        </div>
                    `;
                }

                // Persist cleared state to backend session so they stay cleared across page navigation
                fetch('../backend/clear_notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear_all', ids: ids, all: true })
                }).catch(() => {});
            });
        }

        // Single item dismiss action
        if (notifList) {
            notifList.addEventListener('click', (e) => {
                const dismissBtn = e.target.closest('.notif-item-dismiss');
                if (!dismissBtn) return;
                e.stopPropagation();
                e.preventDefault();

                const notifId = dismissBtn.getAttribute('data-notif-id');
                const wrapper = dismissBtn.closest('.notif-item-wrapper');
                if (wrapper) wrapper.remove();

                // Update counts
                const remaining = notifList.querySelectorAll('.notif-item-wrapper').length;
                if (notifBadge) {
                    if (remaining > 0) {
                        notifBadge.textContent = remaining;
                    } else {
                        notifBadge.remove();
                    }
                }
                if (notifPill) {
                    if (remaining > 0) {
                        notifPill.textContent = remaining + ' new';
                    } else {
                        notifPill.remove();
                    }
                }
                if (remaining === 0) {
                    if (notifClear) notifClear.remove();
                    notifList.innerHTML = `
                        <div class="notif-empty">
                            <span style="font-size:24px;display:block;margin-bottom:6px;">✓</span>
                            <strong style="color:#e8f4fb;">All caught up!</strong>
                            <p style="margin:2px 0 0;font-size:11px;color:var(--muted);">No unread clinical alerts or appointment updates.</p>
                        </div>
                    `;
                }

                // Persist single dismissal to backend session
                if (notifId) {
                    fetch('../backend/clear_notifications.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ids: [notifId] })
                    }).catch(() => {});
                }
            });
        }
    }

    // ─── 2. Global Modal Confirmation Controller ───────────────────────
    const modalOverlay = document.getElementById('premed_confirm_modal');
    const modalTitle   = document.getElementById('confirm_modal_title');
    const modalMsg     = document.getElementById('confirm_modal_msg');
    const modalIcon    = document.getElementById('confirm_modal_icon');
    const modalSymbol  = document.getElementById('confirm_icon_symbol');
    const modalCancel  = document.getElementById('confirm_modal_cancel');
    const modalProceed = document.getElementById('confirm_modal_proceed');

    let onConfirmCallback = null;

    window.openPremedConfirm = function({ title, message, confirmText, confirmType = 'danger', onConfirm }) {
        if (!modalOverlay) return;
        
        modalTitle.textContent = title || 'Confirm Action';
        modalMsg.textContent   = message || 'Are you sure you want to proceed?';
        modalProceed.textContent = confirmText || 'Confirm';

        // Styling type (danger, warning, primary)
        modalIcon.className = 'confirm-modal-icon type-' + confirmType;
        modalProceed.className = 'btn btn-sm btn-' + (confirmType === 'danger' ? 'danger' : (confirmType === 'warning' ? 'warning' : 'primary'));
        modalSymbol.textContent = confirmType === 'danger' ? '🗑' : (confirmType === 'warning' ? '⚠' : 'ℹ');

        onConfirmCallback = onConfirm;
        modalOverlay.classList.add('active');
        modalOverlay.setAttribute('aria-hidden', 'false');
        modalProceed.focus();
    };

    function closeModal() {
        if (!modalOverlay) return;
        modalOverlay.classList.remove('active');
        modalOverlay.setAttribute('aria-hidden', 'true');
        onConfirmCallback = null;
    }

    if (modalCancel) {
        modalCancel.addEventListener('click', closeModal);
    }

    if (modalProceed) {
        modalProceed.addEventListener('click', () => {
            const cb = onConfirmCallback;
            closeModal();
            if (typeof cb === 'function') {
                cb();
            }
        });
    }

    if (modalOverlay) {
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modalOverlay.classList.contains('active')) {
                closeModal();
            }
        });
    }

    // Global click listener for any element with data-confirm-title or data-confirm-message
    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('[data-confirm-title], [data-confirm-message], [data-confirm]');
        if (!trigger) return;

        // Prevent immediate action
        e.preventDefault();
        e.stopPropagation();

        const title = trigger.dataset.confirmTitle || 'Confirm Action';
        const message = trigger.dataset.confirmMessage || trigger.dataset.confirm || 'Are you sure you want to perform this action?';
        const confirmText = trigger.dataset.confirmBtn || 'Confirm';
        const confirmType = trigger.dataset.confirmType || 'danger';

        window.openPremedConfirm({
            title,
            message,
            confirmText,
            confirmType,
            onConfirm: () => {
                // If it's an anchor link, navigate
                if (trigger.tagName === 'A' && trigger.href) {
                    window.location.href = trigger.href;
                    return;
                }

                // If it's a submit button, submit its form
                if (trigger.type === 'submit' && trigger.form) {
                    // Temporarily append a hidden input with the button's name & value so POST sees it
                    if (trigger.name) {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = trigger.name;
                        hidden.value = trigger.value;
                        trigger.form.appendChild(hidden);
                    }
                    trigger.form.submit();
                    return;
                }

                // If button inside a form without specific type
                const form = trigger.closest('form');
                if (form) {
                    form.submit();
                }
            }
        });
    }, true);
})();
</script>
