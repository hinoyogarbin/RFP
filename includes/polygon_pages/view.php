<?php
/**
 * CENRO Polygon Management - polygon details (Admin and Manager).
 */

require_once __DIR__ . '/_bootstrap.php';

$polygonId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$polygon = $polygonId > 0 ? findPolygonById($polygonId) : null;

if ($polygon === null) {
    polygonSetFlash('error', 'The requested polygon could not be found.');
    polygonRedirect('index.php');
}

$attributes = decodeStoredAttributes($polygon['original_attributes'] ?? null);
$mapPayload = buildMapPayload([$polygon]);
$flash = polygonTakeFlash();

$importedBy = !empty($polygon['imported_by']) ? findUserById((int)$polygon['imported_by']) : null;

$pageTitle = 'CENRO Polygon Details';
$extraHead = polygonLeafletHead();

require_once __DIR__ . '/../header.php';
?>
<h1>CENRO Polygon Details</h1>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="details-box">
    <div class="details-row">
        <span class="details-label">Polygon ID</span>
        <span><?= h($polygon['polygon_code']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Polygon Name</span>
        <span><?= h($polygon['polygon_name']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Project</span>
        <span><?= h($polygon['project_name'] ?? 'Not associated') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Location</span>
        <span><?= h($polygon['location'] ?? '-') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Area (calculated)</span>
        <span><?= h(formatHectares($polygon['area_hectares'])) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Area (from CENRO file)</span>
        <span><?= h(formatOriginalArea($polygon['original_area_value'], $polygon['original_area_unit'])) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Steward</span>
        <span>
            <?php if (!empty($polygon['steward_name'])): ?>
                <?= h($polygon['steward_name']) ?> (<?= h($polygon['steward_username']) ?>)
            <?php else: ?>
                Unassigned
            <?php endif; ?>
        </span>
    </div>
    <div class="details-row">
        <span class="details-label">Status</span>
        <span class="status-badge status-<?= h($polygon['status']) ?>">
            <?= h(polygonStatusLabel($polygon['status'])) ?>
        </span>
    </div>
    <div class="details-row">
        <span class="details-label">Remarks</span>
        <span><?= h($polygon['remarks'] ?? '-') ?></span>
    </div>
</div>

<hr class="divider">

<h2>Original Import</h2>

<div class="details-box">
    <div class="details-row">
        <span class="details-label">Source File</span>
        <span><?= h($polygon['source_file'] ?? '-') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Source Format</span>
        <span><?= h(ucfirst($polygon['source_format'])) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Coordinate System</span>
        <span><?= h($polygon['source_crs'] ?? 'Not recorded') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Geometry Type</span>
        <span><?= h($polygon['geometry_type']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Date Imported</span>
        <span><?= h(date('F j, Y', strtotime($polygon['date_imported']))) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Imported By</span>
        <span><?= h($importedBy['full_name'] ?? 'Unknown') ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Date Updated</span>
        <span><?= h(date('F j, Y', strtotime($polygon['date_updated']))) ?></span>
    </div>
</div>

<p class="hint">
    The boundary shown below is the official geometry supplied by CENRO. Editing the
    polygon's administrative information does not change it.
</p>

<div class="dashboard-map-section">
    <h2>Polygon Boundary</h2>
    <p class="dashboard-map-caption">Official CENRO boundary for <?= h($polygon['polygon_code']) ?>.</p>
    <div id="polygon-map" class="dashboard-map"></div>
</div>

<?php if (!empty($attributes)): ?>
    <hr class="divider">

    <h2>Imported Attributes</h2>
    <p class="hint">
        The attribute values exactly as they appeared in the CENRO file. These are preserved
        and are not modified when the polygon information is edited.
    </p>

    <table class="data-table">
        <thead>
        <tr>
            <th>Attribute</th>
            <th>Value</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($attributes as $key => $value): ?>
            <tr>
                <td><?= h((string)$key) ?></td>
                <td>
                    <?php
                    if (is_bool($value)) {
                        echo $value ? 'Yes' : 'No';
                    } elseif ($value === null || $value === '') {
                        echo '-';
                    } else {
                        echo h((string)$value);
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<hr class="divider">

<div class="toolbar">
    <a class="btn btn-primary" href="edit.php?id=<?= (int)$polygon['polygon_id'] ?>">Edit</a>
    <a class="btn" href="assign.php?id=<?= (int)$polygon['polygon_id'] ?>">Assign Steward</a>

    <form action="status.php" method="post" class="filter-form">
        <input type="hidden" name="id" value="<?= (int)$polygon['polygon_id'] ?>">
        <select name="status">
            <?php foreach (VALID_POLYGON_STATUSES as $status): ?>
                <option value="<?= h($status) ?>" <?= $polygon['status'] === $status ? 'selected' : '' ?>>
                    <?= h(polygonStatusLabel($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Change Status</button>
    </form>

    <a class="btn" href="index.php">Back to List</a>
</div>

<?php
$extraScripts = polygonLeafletScripts()
    . '<script>initPolygonMap("polygon-map", ' . jsonForScript($mapPayload) . ');</script>';

require_once __DIR__ . '/../page_end.php';
?>
