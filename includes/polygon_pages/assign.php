<?php
/**
 * CENRO Polygon Management - assign a steward / field operator.
 *
 * Only accounts with role = 'user' can be assigned. The check runs
 * server-side in validateStewardAssignment(), so a tampered form value
 * naming an Admin or Manager account is rejected rather than saved.
 */

require_once __DIR__ . '/_bootstrap.php';

$polygonId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
$polygon = $polygonId > 0 ? findPolygonById($polygonId) : null;

if ($polygon === null) {
    polygonSetFlash('error', 'The requested polygon could not be found.');
    polygonRedirect('index.php');
}

$fieldUsers = getAssignableFieldUsers();
$errors = [];
$selectedUserId = (int)($polygon['assigned_user_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $selectedUserId = (int)($_POST['assigned_user_id'] ?? 0);

    $stewardError = validateStewardAssignment($selectedUserId > 0 ? $selectedUserId : null);
    if ($stewardError !== null) {
        $errors[] = $stewardError;

        logPolygonActivity(
            'Assigned Steward',
            sprintf(
                'Rejected steward assignment for polygon "%s" (ID %d): %s',
                $polygon['polygon_name'],
                $polygonId,
                $stewardError
            ),
            'failed'
        );
    }

    if (empty($errors)) {
        try {
            $previousName = $polygon['steward_name'] ?? 'Unassigned';
            assignPolygonSteward($polygonId, $selectedUserId > 0 ? $selectedUserId : null);

            $newUser = $selectedUserId > 0 ? findUserById($selectedUserId) : null;
            $newName = $newUser['full_name'] ?? 'Unassigned';

            logPolygonActivity(
                'Assigned Steward',
                sprintf(
                    'Polygon "%s" (%s, ID %d) steward changed from %s to %s.',
                    $polygon['polygon_name'],
                    $polygon['polygon_code'],
                    $polygonId,
                    $previousName,
                    $newName
                ),
                'success'
            );

            polygonSetFlash('success', ($selectedUserId > 0)
                ? 'Steward assigned successfully.'
                : 'Steward removed from this polygon.');
            polygonRedirect('view.php?id=' . $polygonId);

        } catch (PDOException $e) {
            error_log('Steward assignment failed: ' . $e->getMessage());
            $errors[] = 'The steward could not be assigned. Please try again.';
        }
    }
}

$pageTitle = 'Assign Steward';
require_once __DIR__ . '/../header.php';
?>
<h1>Assign Steward</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="details-box">
    <div class="details-row">
        <span class="details-label">Polygon</span>
        <span><?= h($polygon['polygon_code']) ?> — <?= h($polygon['polygon_name']) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Area</span>
        <span><?= h(formatHectares($polygon['area_hectares'])) ?></span>
    </div>
    <div class="details-row">
        <span class="details-label">Current Steward</span>
        <span><?= h($polygon['steward_name'] ?? 'Unassigned') ?></span>
    </div>
</div>

<form class="form" action="assign.php" method="post">
    <input type="hidden" name="id" value="<?= (int)$polygonId ?>">

    <label for="assigned_user_id">Assigned Steward / Field User</label>
    <select id="assigned_user_id" name="assigned_user_id">
        <option value="0">— Unassigned —</option>
        <?php foreach ($fieldUsers as $fieldUser): ?>
            <option value="<?= (int)$fieldUser['user_id'] ?>"
                <?= $selectedUserId === (int)$fieldUser['user_id'] ? 'selected' : '' ?>>
                <?= h($fieldUser['full_name']) ?> (<?= h($fieldUser['username']) ?>)
            </option>
        <?php endforeach; ?>
    </select>

    <?php if (empty($fieldUsers)): ?>
        <p class="hint">
            There are no active Field User accounts available. Create one from
            User Management before assigning a steward.
        </p>
    <?php else: ?>
        <p class="hint">
            Only active accounts with the Field User role appear here. Admin and Manager
            accounts cannot be assigned as stewards.
        </p>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Assignment</button>
        <a class="btn" href="view.php?id=<?= (int)$polygonId ?>">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../page_end.php'; ?>
