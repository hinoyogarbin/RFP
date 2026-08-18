<?php
/**
 * Admin Activity Monitoring & Audit Trail Dashboard
 * Reforestation Management Platform
 *
 * Access: Administrators ONLY. Managers and Users are denied.
 */

require_once __DIR__ . '/../includes/auth_check.php';
requireLogin();
require_once __DIR__ . '/../includes/role_check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/user_functions.php'; // for h() escaping helper
require_once __DIR__ . '/../includes/log_functions.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();

// ------------------------------------------------------------------
// CSRF token for the destructive "reset records" action
// ------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ------------------------------------------------------------------
// Handle "Reset All Records" (delete) — POST + CSRF only, admin-only
// (requireRole('admin') above already blocks non-admins from this page)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_logs'])) {
    $tokenOk = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);

    if ($tokenOk) {
        $pdo->exec("DELETE FROM activity_logs");

        // Regenerate token after use
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        // Record the reset itself — this is a genuine admin action, not page-view noise.
        if (function_exists('logActivity')) {
            logActivity(
                $_SESSION['user_id'] ?? null,
                $_SESSION['full_name'] ?? 'Unknown Admin',
                $_SESSION['role'] ?? 'admin',
                'Reset Activity Logs',
                'Activity Logs',
                'All activity log records were deleted by an administrator.',
                'warning'
            );
        }

        header('Location: logs.php?reset=1');
        exit;
    }

    header('Location: logs.php?reset=0');
    exit;
}

// ------------------------------------------------------------------
// Read & sanitize filters (all via prepared statement placeholders)
// ------------------------------------------------------------------
$search    = trim($_GET['search'] ?? '');
$roleF     = $_GET['role'] ?? '';
$actionF   = $_GET['action'] ?? '';
$statusF   = $_GET['status'] ?? '';
$dateFrom  = $_GET['date_from'] ?? '';
$dateTo    = $_GET['date_to'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;
$offset    = ($page - 1) * $perPage;

$allowedRoles  = ['admin', 'manager', 'user'];
$allowedStatus = ['success', 'failed', 'warning'];

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = "(full_name LIKE :search OR description LIKE :search2 OR action LIKE :search3)";
    $params[':search']  = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
}
if (in_array($roleF, $allowedRoles, true)) {
    $where[] = "role = :role";
    $params[':role'] = $roleF;
}
if ($actionF !== '') {
    $where[] = "action = :action";
    $params[':action'] = $actionF;
}
if (in_array($statusF, $allowedStatus, true)) {
    $where[] = "status = :status";
    $params[':status'] = $statusF;
}
if ($dateFrom !== '') {
    $where[] = "created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = "created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total count for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Page of results
$sql = "SELECT * FROM activity_logs $whereSql ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Distinct actions for the Action filter dropdown
$actionsList = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

function statusBadgeClass(string $status): string
{
    return match ($status) {
        'success' => 'badge-success',
        'failed'  => 'badge-failed',
        'warning' => 'badge-warning',
        default   => 'badge-secondary',
    };
}

// ------------------------------------------------------------------
// Lightweight AJAX mode for the 5-second auto-refresh: return ONLY the
// table rows as an HTML fragment. This intentionally skips header.php /
// the rest of the page below so the auto-refresh does not re-trigger
// any page-view logging hooks that may live in the shared header/auth
// includes, and does not carry the cost of rendering the full page
// every 5 seconds.
// ------------------------------------------------------------------
if (($_GET['ajax'] ?? '') === '1') {
    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td>' . h(date('M d, Y g:i A', strtotime($log['created_at']))) . '</td>';
        echo '<td>' . h($log['full_name']) . '</td>';
        echo '<td>' . h(ucfirst($log['role'])) . '</td>';
        echo '<td>' . h($log['action']) . '</td>';
        echo '<td>' . h($log['module']) . '</td>';
        echo '<td>' . h($log['description']) . '</td>';
        echo '<td>' . h($log['ip_address'] ?? '') . '</td>';
        echo '<td><span class="badge ' . statusBadgeClass($log['status']) . '">' . h(ucfirst($log['status'])) . '</span></td>';
        echo '</tr>';
    }
    exit;
}

// Summary counters
$totalActivities = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
$todayActivities = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$activeUsers = (int)$pdo->query(
    "SELECT COUNT(DISTINCT user_id) FROM activity_logs WHERE DATE(created_at) = CURDATE() AND user_id IS NOT NULL"
)->fetchColumn();
$failedLogins = (int)$pdo->query(
    "SELECT COUNT(*) FROM activity_logs WHERE action = 'Failed Login Attempt' AND DATE(created_at) = CURDATE()"
)->fetchColumn();

// ------------------------------------------------------------------
// Page setup — use the SAME header/footer as the rest of the admin panel
// ------------------------------------------------------------------
$pageTitle = 'Activity Logs';

$extraHead = '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
    .stat-box { border: 1px solid #ddd; border-radius: 8px; padding: .75rem 1rem; text-align: center; }
    .stat-box .stat-value { font-size: 1.5rem; font-weight: 700; }
    .stat-box .stat-label { font-size: .8rem; color: #666; }
    .badge-success { background-color: #2d6a4f; }
    .badge-failed  { background-color: #d62828; }
    .badge-warning { background-color: #e9a400; color: #212529; }

    /* Scrollable logs list: only the table body scrolls, not the whole page */
    .logs-scroll-wrap {
        max-height: 520px;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 6px;
    }
    .logs-scroll-wrap table thead th {
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 1;
    }
</style>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h1 class="mb-0">Activity Logs</h1>
    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#resetLogsModal">
        Reset All Records
    </button>
</div>

<?php if (isset($_GET['reset']) && $_GET['reset'] === '1'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        All activity log records have been deleted.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php elseif (isset($_GET['reset']) && $_GET['reset'] === '0'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        Could not reset records: security check failed. Please try again.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Reset confirmation modal -->
<div class="modal fade" id="resetLogsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Reset All Records?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>This will permanently delete <strong><?= number_format($totalActivities) ?></strong> activity log record(s). This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <button type="submit" name="delete_logs" value="1" class="btn btn-danger">Yes, Delete All</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Summary counters -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-box">
            <div class="stat-value"><?= number_format($totalActivities) ?></div>
            <div class="stat-label">Total Activities</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-box">
            <div class="stat-value"><?= number_format($todayActivities) ?></div>
            <div class="stat-label">Today</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-box">
            <div class="stat-value"><?= number_format($activeUsers) ?></div>
            <div class="stat-label">Active Users Today</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-box">
            <div class="stat-value text-danger"><?= number_format($failedLogins) ?></div>
            <div class="stat-label">Failed Logins Today</div>
        </div>
    </div>
</div>

<!-- Filters -->
<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label small">Search</label>
        <input type="text" name="search" class="form-control" placeholder="Name, action, description..."
               value="<?= h($search) ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label small">Role</label>
        <select name="role" class="form-select">
            <option value="">All</option>
            <?php foreach ($allowedRoles as $r): ?>
                <option value="<?= h($r) ?>" <?= $roleF === $r ? 'selected' : '' ?>><?= h(ucfirst($r)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small">Action</label>
        <select name="action" class="form-select">
            <option value="">All</option>
            <?php foreach ($actionsList as $a): ?>
                <option value="<?= h($a) ?>" <?= $actionF === $a ? 'selected' : '' ?>><?= h($a) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small">Status</label>
        <select name="status" class="form-select">
            <option value="">All</option>
            <?php foreach ($allowedStatus as $s): ?>
                <option value="<?= h($s) ?>" <?= $statusF === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-1">
        <label class="form-label small">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
    </div>
    <div class="col-md-1">
        <label class="form-label small">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
    </div>
    <div class="col-md-1 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
        <a href="logs.php" class="btn btn-outline-secondary btn-sm">Reset</a>
    </div>
</form>

<!-- Logs table: wrapped so only this list scrolls, not the whole page -->
<div class="logs-scroll-wrap">
    <div class="table-responsive">
        <table id="logsTable" class="table table-striped table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Description</th>
                    <th>IP Address</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= h(date('M d, Y g:i A', strtotime($log['created_at']))) ?></td>
                    <td><?= h($log['full_name']) ?></td>
                    <td><?= h(ucfirst($log['role'])) ?></td>
                    <td><?= h($log['action']) ?></td>
                    <td><?= h($log['module']) ?></td>
                    <td><?= h($log['description']) ?></td>
                    <td><?= h($log['ip_address'] ?? '') ?></td>
                    <td><span class="badge <?= statusBadgeClass($log['status']) ?>"><?= h(ucfirst($log['status'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav>
    <ul class="pagination justify-content-center">
        <?php
        $qs = $_GET;
        for ($i = 1; $i <= $totalPages; $i++):
            $qs['page'] = $i;
            $url = '?' . http_build_query($qs);
        ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= h($url) ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php
$extraScripts = '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function () {
    $("#logsTable").DataTable({
        paging: false,
        info: false,
        order: [],
        scrollY: false,
        language: { emptyTable: "No activity records found." }
    });
});

// Auto-refresh the logs list every 5 seconds — fetches ONLY the table rows
// via logs.php?ajax=1 (see PHP above), so it does not reload the whole page,
// does not disturb your scroll position, and does not re-run header/auth
// includes on every cycle. Skips the refresh while the reset modal is open.
function refreshLogsTable() {
    var modalEl = document.getElementById("resetLogsModal");
    var modalOpen = modalEl && modalEl.classList.contains("show");
    if (modalOpen) return;

    var params = new URLSearchParams(window.location.search);
    params.set("ajax", "1");

    fetch("logs.php?" + params.toString())
        .then(function (res) { return res.text(); })
        .then(function (html) {
            var table = $("#logsTable").DataTable();
            table.destroy();
            $("#logsTable tbody").html(html);
            $("#logsTable").DataTable({
                paging: false,
                info: false,
                order: [],
                scrollY: false,
                language: { emptyTable: "No activity records found." }
            });
        })
        .catch(function (err) { console.error("Log refresh failed:", err); });
}

setInterval(refreshLogsTable, 5000);
</script>';
require_once __DIR__ . '/../includes/page_end.php';
?>