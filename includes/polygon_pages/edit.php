<?php
/**
 * CENRO Polygon Management - edit administrative information.
 *
 * Only non-geometric metadata is editable here. The imported CENRO
 * boundary is deliberately not exposed to editing: updatePolygonInfo()
 * does not touch any geometry column.
 */

require_once __DIR__ . '/_bootstrap.php';

$polygonId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
$polygon = $polygonId > 0 ? findPolygonById($polygonId) : null;

if ($polygon === null) {
    polygonSetFlash('error', 'The requested polygon could not be found.');
    polygonRedirect('index.php');
}

$projects = getProjectsList();
$errors = [];

$formData = [
    'polygon_code' => $polygon['polygon_code'],
    'polygon_name' => $polygon['polygon_name'],
    'project_id'   => $polygon['project_id'],
    'location'     => $polygon['location'] ?? '',
    'status'       => $polygon['status'],
    'remarks'      => $polygon['remarks'] ?? '',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formData = [
        'polygon_code' => trim($_POST['polygon_code'] ?? ''),
        'polygon_name' => trim($_POST['polygon_name'] ?? ''),
        'project_id'   => $_POST['project_id'] ?? '',
        'location'     => trim($_POST['location'] ?? ''),
        'status'       => $_POST['status'] ?? '',
        'remarks'      => trim($_POST['remarks'] ?? ''),
    ];

    // The steward is not editable on this form; keep the existing value
    // so validatePolygonInfo() checks it rather than clearing it.
    $validationData = $formData;
    $validationData['assigned_user_id'] = $polygon['assigned_user_id'];

    $errors = validatePolygonInfo($validationData, $polygonId);

    if (empty($errors)) {
        $formData['polygon_code'] = polygonSanitiseCode($formData['polygon_code']);
        $formData['project_id'] = ((int)$formData['project_id'] > 0) ? (int)$formData['project_id'] : null;

        try {
            updatePolygonInfo($polygonId, $formData);

            $changes = [];
            if ($formData['polygon_code'] !== $polygon['polygon_code']) {
                $changes[] = 'code';
            }
            if ($formData['polygon_name'] !== $polygon['polygon_name']) {
                $changes[] = 'name';
            }
            if ((int)$formData['project_id'] !== (int)$polygon['project_id']) {
                $changes[] = 'project';
            }
            if ($formData['status'] !== $polygon['status']) {
                $changes[] = 'status';
            }

            logPolygonActivity(
                'Updated Polygon',
                sprintf(
                    'Updated polygon "%s" (ID %d). Changed: %s. Imported boundary unchanged.',
                    $formData['polygon_name'],
                    $polygonId,
                    $changes ? implode(', ', $changes) : 'details'
                ),
                'success'
            );

            polygonSetFlash('success', 'Polygon information updated. The imported boundary was not changed.');
            polygonRedirect('view.php?id=' . $polygonId);

        } catch (PDOException $e) {
            error_log('Polygon update failed: ' . $e->getMessage());
            $errors[] = 'The polygon could not be updated. Please try again.';
        }
    }
}

$pageTitle = 'Edit Polygon';
require_once __DIR__ . '/../header.php';
?>
<h1>Edit Polygon</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="alert alert-info">
    This form edits administrative information only. The official CENRO boundary
    for this polygon is preserved and cannot be changed here.
</div>

<form class="form" action="edit.php" method="post">
    <input type="hidden" name="id" value="<?= (int)$polygonId ?>">

    <label for="polygon_code">Polygon Code</label>
    <input type="text" id="polygon_code" name="polygon_code"
           value="<?= h((string)$formData['polygon_code']) ?>" maxlength="50" required>

    <label for="polygon_name">Polygon Name</label>
    <input type="text" id="polygon_name" name="polygon_name"
           value="<?= h((string)$formData['polygon_name']) ?>" maxlength="150" required>

    <label for="project_id">Project</label>
    <select id="project_id" name="project_id">
        <option value="0">— No project —</option>
        <?php foreach ($projects as $project): ?>
            <option value="<?= (int)$project['project_id'] ?>"
                <?= (int)$formData['project_id'] === (int)$project['project_id'] ? 'selected' : '' ?>>
                <?= h($project['project_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="location">Location</label>
    <input type="text" id="location" name="location"
           value="<?= h((string)$formData['location']) ?>" maxlength="200">

    <label for="status">Status</label>
    <select id="status" name="status">
        <?php foreach (VALID_POLYGON_STATUSES as $status): ?>
            <option value="<?= h($status) ?>" <?= $formData['status'] === $status ? 'selected' : '' ?>>
                <?= h(polygonStatusLabel($status)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="remarks">Remarks</label>
    <textarea id="remarks" name="remarks" rows="3"><?= h((string)$formData['remarks']) ?></textarea>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a class="btn" href="view.php?id=<?= (int)$polygonId ?>">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../page_end.php'; ?>
