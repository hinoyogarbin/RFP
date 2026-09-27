<?php
/**
 * CENRO Polygon Management - listing page (Admin and Manager).
 */

require_once __DIR__ . '/_bootstrap.php';

$search        = trim($_GET['search'] ?? '');
$statusFilter  = $_GET['status'] ?? '';
$projectFilter = isset($_GET['project']) && $_GET['project'] !== '' ? (int)$_GET['project'] : null;

$polygons = getPolygonsList($search, $statusFilter, $projectFilter);
$projects = getProjectsList();

$mapPayload = buildMapPayload($polygons);
$flash = polygonTakeFlash();

$pageTitle = 'CENRO Polygon Management';
$extraHead = polygonLeafletHead();

require_once __DIR__ . '/../header.php';
?>
<h1>CENRO Polygon Management</h1>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="toolbar">
    <a class="btn btn-primary" href="import.php">Import Polygon</a>

    <form action="index.php" method="get" class="filter-form">
        <input type="text" name="search" placeholder="Search code, name or location"
               value="<?= h($search) ?>">

        <select name="project">
            <option value="">All Projects</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int)$project['project_id'] ?>"
                    <?= $projectFilter === (int)$project['project_id'] ? 'selected' : '' ?>>
                    <?= h($project['project_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (VALID_POLYGON_STATUSES as $status): ?>
                <option value="<?= h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                    <?= h(polygonStatusLabel($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn">Filter</button>
    </form>
</div>

<table class="data-table">
    <thead>
    <tr>
        <th>Polygon ID</th>
        <th>Polygon Name</th>
        <th>Project</th>
        <th>Area</th>
        <th>Steward</th>
        <th>Status</th>
        <th>Date Imported</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($polygons)): ?>
        <tr>
            <td colspan="8" class="empty-row">
                No polygons found. Use "Import Polygon" to upload official CENRO spatial data.
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($polygons as $polygon): ?>
            <tr>
                <td><?= h($polygon['polygon_code']) ?></td>
                <td><?= h($polygon['polygon_name']) ?></td>
                <td><?= h($polygon['project_name'] ?? '-') ?></td>
                <td><?= h(formatHectares($polygon['area_hectares'])) ?></td>
                <td><?= h($polygon['steward_name'] ?? 'Unassigned') ?></td>
                <td>
                    <span class="status-badge status-<?= h($polygon['status']) ?>">
                        <?= h(polygonStatusLabel($polygon['status'])) ?>
                    </span>
                </td>
                <td><?= h(date('F j, Y', strtotime($polygon['date_imported']))) ?></td>
                <td class="actions-cell">
                    <a href="view.php?id=<?= (int)$polygon['polygon_id'] ?>">View</a>
                    <a href="edit.php?id=<?= (int)$polygon['polygon_id'] ?>">Edit</a>
                    <a href="assign.php?id=<?= (int)$polygon['polygon_id'] ?>">Assign</a>
                    <?php if (canDeletePolygons($currentRole)): ?>
                        <form action="delete.php" method="post" class="inline-form"
                              onsubmit="return confirm('Delete this polygon? The imported CENRO boundary will be permanently removed.');">
                            <input type="hidden" name="id" value="<?= (int)$polygon['polygon_id'] ?>">
                            <button type="submit" class="link-button link-danger">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<div class="dashboard-map-section">
    <h2>Polygon Boundaries</h2>
    <p class="dashboard-map-caption">
        Official CENRO boundaries currently matching the filters above. Click a polygon for details.
    </p>
    <div id="polygon-map" class="dashboard-map"></div>
</div>

<?php
$extraScripts = polygonLeafletScripts()
    . '<script>initPolygonMap("polygon-map", ' . jsonForScript($mapPayload) . ');</script>';

require_once __DIR__ . '/../page_end.php';
?>
