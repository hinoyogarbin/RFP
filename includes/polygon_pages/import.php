<?php
/**
 * CENRO Polygon Management - import flow (Admin and Manager).
 *
 *   select file -> validate -> read geometry -> read attributes
 *   -> check CRS -> convert -> PREVIEW ON MAP -> confirm -> save
 *
 * The preview step exists so the operator can verify the boundary
 * plotted correctly before anything is written to the database.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../polygon_import.php';

$action = $_POST['action'] ?? ($_GET['action'] ?? 'form');

$errors = [];
$warnings = [];
$preview = null;
$token = null;
$mapping = [];
$featureInputs = [];
$duplicateNotices = [];
$needsCrs = false;
$suggestedCrs = null;
$crsChoice = (string)($_POST['crs_choice'] ?? '');
$crsPresets = projCrsPresets();

$projects = getProjectsList();
$fieldUsers = getAssignableFieldUsers();

$selectedProjectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$selectedStatus = $_POST['status'] ?? 'active';
$selectedStewardId = isset($_POST['assigned_user_id']) ? (int)$_POST['assigned_user_id'] : 0;

if (!in_array($selectedStatus, VALID_POLYGON_STATUSES, true)) {
    $selectedStatus = 'active';
}

// ============================================================
// Step 1: receive the upload and parse it
// ============================================================
if ($action === 'upload' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $import = polygonProcessUpload('spatial_files', $crsChoice);

    $warnings = $import['warnings'];

    if (!$import['ok']) {
        $errors[] = $import['error'];

        logPolygonActivity(
            'Import Polygon',
            'Import could not be completed: ' . $import['error'],
            'failed'
        );

        // A missing or unsupported CRS is recoverable: keep the parsed
        // geometry so the operator can pick a coordinate system without
        // uploading the file again.
        if (!empty($import['needs_crs']) && !empty($import['raw_features'])) {
            $needsCrs = true;
            $suggestedCrs = $import['suggested_crs'];
            $token = storePendingImport($import);

            if ($token === null) {
                $errors[] = 'The import could not be prepared. Please try again.';
                $needsCrs = false;
            }
        }
    } else {
        $crsChoice = $import['crs_choice'] ?? $crsChoice;
        $token = storePendingImport($import);

        if ($token === null) {
            $errors[] = 'The import could not be prepared for preview. Please try again.';
        } else {
            $preview = $import;
            $action = 'preview';

            logPolygonActivity(
                'Import Polygon',
                sprintf(
                    'Parsed %s file "%s" (%d polygon feature(s), CRS: %s) - awaiting confirmation.',
                    $import['format'],
                    $import['source_file'],
                    count($import['features']),
                    $import['crs_description'] !== '' ? $import['crs_description'] : 'unknown'
                ),
                'success'
            );
        }
    }
}

// ============================================================
// Step 1b: apply a coordinate system chosen by the operator
// ============================================================
if ($action === 'setcrs' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['token'] ?? '');
    $pending = loadPendingImport($token);

    if ($pending === null) {
        $errors[] = 'The import has expired. Please upload the file again.';
        $action = 'form';
    } elseif ($crsChoice === '') {
        $errors[] = 'Please choose a coordinate reference system.';
        $needsCrs = true;
        $suggestedCrs = $pending['suggested_crs'] ?? null;
        $action = 'form';
    } else {
        $reprojected = polygonReprojectPending($pending, $crsChoice);

        if (!$reprojected['ok']) {
            $errors[] = $reprojected['error'];
            $needsCrs = true;
            $suggestedCrs = $pending['suggested_crs'] ?? null;
            $action = 'form';
        } else {
            $pending = $reprojected['pending'];
            clearPendingImport($token);
            $token = storePendingImport($pending);

            if ($token === null) {
                $errors[] = 'The import could not be prepared for preview. Please try again.';
                $action = 'form';
            } else {
                $preview = $pending;
                $warnings = $pending['warnings'] ?? [];
                $action = 'preview';

                logPolygonActivity(
                    'Import Polygon',
                    sprintf(
                        'Applied coordinate system "%s" to "%s" (%d feature(s)) - awaiting confirmation.',
                        (string)projPresetLabel($crsChoice),
                        $pending['source_file'] ?? 'unknown',
                        count($pending['features'] ?? [])
                    ),
                    'success'
                );
            }
        }
    }
}

// ============================================================
// Step 2: re-render the preview after an attribute-mapping change
// ============================================================
if ($action === 'remap' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['token'] ?? '');
    $preview = loadPendingImport($token);

    if ($preview === null) {
        $errors[] = 'The import preview has expired. Please upload the file again.';
        $action = 'form';
    } else {
        $warnings = $preview['warnings'] ?? [];
        $action = 'preview';
    }
}

// ============================================================
// Step 3: confirm and save
// ============================================================
if ($action === 'confirm' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['token'] ?? '');
    $pending = loadPendingImport($token);

    if ($pending === null) {
        $errors[] = 'The import preview has expired. Please upload the file again.';
        $action = 'form';
    } else {
        $features = $pending['features'] ?? [];
        $selected = $_POST['include'] ?? [];
        $codes = $_POST['polygon_code'] ?? [];
        $names = $_POST['polygon_name'] ?? [];
        $locations = $_POST['location'] ?? [];
        $originalAreas = $_POST['original_area'] ?? [];
        $originalUnits = $_POST['original_area_unit'] ?? [];

        if (!is_array($selected) || empty($selected)) {
            $errors[] = 'Please select at least one polygon to import.';
        }

        // Steward and project are validated server-side, never trusted
        // from the form alone.
        $stewardError = validateStewardAssignment($selectedStewardId > 0 ? $selectedStewardId : null);
        if ($stewardError !== null) {
            $errors[] = $stewardError;
        }

        if ($selectedProjectId > 0 && findProjectById($selectedProjectId) === null) {
            $errors[] = 'The selected project could not be found.';
        }

        if (empty($errors)) {
            $pdo = getDbConnection();
            $imported = 0;
            $failed = 0;
            $usedCodes = [];

            foreach ($selected as $rawIndex) {
                $index = (int)$rawIndex;
                if (!isset($features[$index])) {
                    continue;
                }

                $feature = $features[$index];

                $code = polygonSanitiseCode((string)($codes[$index] ?? ''));
                $name = trim((string)($names[$index] ?? ''));
                $location = trim((string)($locations[$index] ?? ''));

                if ($code === '') {
                    $code = 'POLY-' . ($index + 1);
                }
                if ($name === '') {
                    $name = 'Reforestation Area ' . ($index + 1);
                }

                // Guard against two rows claiming the same code in one submit.
                if (in_array($code, $usedCodes, true) || polygonCodeExists($code)) {
                    $code = makeUniquePolygonCode($code);
                }
                $usedCodes[] = $code;

                $areaValue = (isset($originalAreas[$index]) && is_numeric($originalAreas[$index]))
                    ? (float)$originalAreas[$index]
                    : null;
                $areaUnit = in_array($originalUnits[$index] ?? '', ['ha', 'sqm'], true)
                    ? $originalUnits[$index]
                    : null;

                $info = [
                    'polygon_code'        => $code,
                    'polygon_name'        => mb_substr($name, 0, 150),
                    'project_id'          => $selectedProjectId > 0 ? $selectedProjectId : null,
                    'location'            => mb_substr($location, 0, 200),
                    'assigned_user_id'    => $selectedStewardId > 0 ? $selectedStewardId : null,
                    'status'              => $selectedStatus,
                    'remarks'             => '',
                    'original_area_value' => $areaValue,
                    'original_area_unit'  => $areaValue !== null ? $areaUnit : null,
                ];

                try {
                    $polygonId = createPolygonFromImport($feature, $pending, $info, $currentUserId);
                    $imported++;

                    logPolygonActivity(
                        'Imported Polygon',
                        sprintf(
                            'Imported polygon "%s" (%s) from %s file "%s". '
                            . 'Calculated area %s. CRS: %s. Polygon ID %d.',
                            $info['polygon_name'],
                            $code,
                            $pending['format'],
                            $pending['source_file'],
                            formatHectares($feature['area_hectares']),
                            $pending['crs_description'] !== '' ? $pending['crs_description'] : 'unknown',
                            $polygonId
                        ),
                        'success'
                    );
                } catch (PDOException $e) {
                    // Never leak the raw database error to the operator.
                    error_log('Polygon insert failed: ' . $e->getMessage());
                    $failed++;
                }
            }

            clearPendingImport($token);

            if ($imported > 0) {
                $message = $imported . ' polygon' . ($imported === 1 ? '' : 's') . ' imported successfully.';
                if ($failed > 0) {
                    $message .= ' ' . $failed . ' could not be saved.';
                }
                polygonSetFlash($failed > 0 ? 'error' : 'success', $message);
                polygonRedirect('index.php');
            }

            $errors[] = 'The polygon file could not be imported. '
                . 'Please verify that the spatial file is valid.';
            $action = 'form';
        } else {
            // Validation failed - fall back to the preview so nothing is lost.
            $preview = $pending;
            $warnings = $pending['warnings'] ?? [];
            $action = 'preview';
        }
    }
}

// ============================================================
// Prepare preview data
// ============================================================
if ($action === 'preview' && $preview !== null) {
    $features = $preview['features'] ?? [];

    // Collect every attribute name present across the file.
    $attributeNames = [];
    foreach ($features as $feature) {
        foreach (array_keys($feature['attributes'] ?? []) as $name) {
            if (!in_array($name, $attributeNames, true)) {
                $attributeNames[] = $name;
            }
        }
    }

    // Suggested mapping, overridable by the operator.
    $suggested = suggestAttributeMapping($attributeNames);
    foreach (['polygon_code', 'polygon_name', 'location', 'area'] as $field) {
        $posted = $_POST['map'][$field] ?? null;
        if ($posted !== null && $posted !== '' && in_array($posted, $attributeNames, true)) {
            $mapping[$field] = $posted;
        } elseif ($posted === '') {
            $mapping[$field] = null; // Operator explicitly cleared it.
        } else {
            $mapping[$field] = $suggested[$field] ?? null;
        }
    }

    // Resolve each feature's proposed values.
    foreach ($features as $index => $feature) {
        $attributes = $feature['attributes'] ?? [];

        $code = attributeValue($attributes, $mapping['polygon_code']);
        $name = attributeValue($attributes, $mapping['polygon_name']);
        $location = attributeValue($attributes, $mapping['location']);

        $areaRaw = $mapping['area'] !== null ? ($attributes[$mapping['area']] ?? null) : null;
        $areaInfo = interpretAreaAttribute($mapping['area'], $areaRaw);

        // Preserve whatever the operator already typed on a re-render.
        $postedCode = $_POST['polygon_code'][$index] ?? null;
        $postedName = $_POST['polygon_name'][$index] ?? null;
        $postedLocation = $_POST['location'][$index] ?? null;
        $remapped = ($_POST['action'] ?? '') === 'remap';

        $featureInputs[$index] = [
            'code'      => ($postedCode !== null && !$remapped)
                ? $postedCode
                : ($code ?? 'POLY-' . ($index + 1)),
            'name'      => ($postedName !== null && !$remapped)
                ? $postedName
                : ($name ?? $code ?? 'Reforestation Area ' . ($index + 1)),
            'location'  => ($postedLocation !== null && !$remapped)
                ? $postedLocation
                : ($location ?? ''),
            'area_value' => $areaInfo['value'],
            'area_unit'  => $areaInfo['unit'],
        ];

        // Duplicate checks (geometry fingerprint, not filename).
        $existing = findPolygonByGeometryHash($feature['geometry_hash'] ?? '');
        if ($existing !== null) {
            $duplicateNotices[] = sprintf(
                'Feature %d has the same boundary as the existing polygon "%s" (%s), imported on %s.',
                $index + 1,
                $existing['polygon_name'],
                $existing['polygon_code'],
                date('F j, Y', strtotime($existing['date_imported']))
            );
        }
    }

    // Whole-file duplicate check.
    $sameSource = findPolygonsBySourceHash($preview['source_hash'] ?? '');
    if (!empty($sameSource)) {
        $duplicateNotices[] = 'This exact file has been imported before ('
            . count($sameSource) . ' existing polygon record'
            . (count($sameSource) === 1 ? '' : 's') . ' came from it).';
    }
}

$pageTitle = 'Import CENRO Polygon';
$extraHead = polygonLeafletHead();

require_once __DIR__ . '/../header.php';
?>
<h1>Import CENRO Polygon</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php foreach ($warnings as $warning): ?>
    <div class="alert alert-warning"><?= h($warning) ?></div>
<?php endforeach; ?>

<?php if ($action !== 'preview'): ?>

    <?php if ($needsCrs && $token !== null): ?>

        <?php // ---------- CRS recovery: geometry read, coordinate system unknown ---------- ?>
        <form class="form form-wide" action="import.php" method="post">
            <input type="hidden" name="action" value="setcrs">
            <input type="hidden" name="token" value="<?= h($token) ?>">

            <h2>Choose the Coordinate System</h2>

            <div class="info-box">
                <p>
                    The polygon boundary was read successfully, but the file does not say which
                    coordinate system it uses — there is no <strong>.prj</strong> file, and none
                    could be recovered from the metadata.
                </p>
                <p>
                    Select the coordinate system CENRO used, then check the preview map. If the
                    boundary appears in the wrong place, come back and try a different one.
                    Nothing is saved until you confirm.
                </p>
                <p class="hint">
                    For Bukidnon and most of Mindanao, the usual choices are
                    <strong>WGS 84 / UTM zone 51N</strong> or <strong>PRS92 zone IV or V</strong>.
                </p>
            </div>

            <label for="crs_choice">Coordinate Reference System</label>
            <select id="crs_choice" name="crs_choice" required>
                <option value="">— Select a coordinate system —</option>
                <?php foreach ($crsPresets as $presetId => $preset): ?>
                    <option value="<?= h($presetId) ?>"
                        <?= $suggestedCrs === $presetId ? 'selected' : '' ?>>
                        <?= h($preset['label']) ?><?= $suggestedCrs === $presetId ? ' — suggested' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint">
                <?php if ($suggestedCrs !== null): ?>
                    The suggested option is based on the range of the coordinates in the file.
                    Please still confirm it on the preview map.
                <?php else: ?>
                    Pick the option that matches the data supplied by CENRO.
                <?php endif; ?>
            </p>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Apply and Preview</button>
                <a class="btn" href="import.php">Start Over</a>
            </div>
        </form>

        <hr class="divider">

    <?php endif; ?>

    <?php // ---------- Upload form ---------- ?>
    <form class="form form-wide" action="import.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload">

        <label for="spatial_files">Spatial File</label>
        <input type="file" id="spatial_files" name="spatial_files[]" multiple required>

        <p class="hint">
            Supported formats: Shapefile, GeoJSON, and KML.
        </p>

        <label for="upload_crs_choice">Coordinate System (optional)</label>
        <select id="upload_crs_choice" name="crs_choice">
            <option value="">Detect automatically from the file</option>
            <?php foreach ($crsPresets as $presetId => $preset): ?>
                <option value="<?= h($presetId) ?>" <?= $crsChoice === $presetId ? 'selected' : '' ?>>
                    <?= h($preset['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="hint">
            Leave this on automatic unless you already know the file's coordinate system,
            or the automatic detection gets it wrong.
        </p>

        <div class="info-box">
            <h3>Uploading a Shapefile</h3>
            <p>
                A Shapefile is not a single file. Select the
                <strong>.shp</strong>, <strong>.shx</strong>, <strong>.dbf</strong> and
                <strong>.prj</strong> files together, or upload a <strong>.zip</strong>
                archive containing them.
            </p>
            <p>
                You can safely select every file in the folder — companion files that are
                not needed (such as <strong>.sbn</strong>, <strong>.sbx</strong> or
                <strong>.qix</strong>) are simply ignored.
            </p>
            <p>
                If there is no <strong>.prj</strong> file, include the
                <strong>.xml</strong> metadata file if you have one: the coordinate system
                can often be read from it. Otherwise you will be asked to choose the
                coordinate system, and can verify it on the preview map.
            </p>
            <p class="hint">
                Nothing is saved until you have reviewed the boundary on the preview map
                and confirmed the import.
            </p>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Upload and Preview</button>
            <a class="btn" href="index.php">Cancel</a>
        </div>
    </form>

<?php else: ?>

    <?php
    $features = $preview['features'] ?? [];
    $previewPayload = [];

    foreach ($features as $index => $feature) {
        // Preview only: a very detailed boundary is thinned for drawing
        // speed. The stored geometry is never simplified.
        $geometry = geoSimplifyForPreview($feature['geometry'], 4000);

        $previewPayload[] = [
            'id'      => $index,
            'code'    => $featureInputs[$index]['code'] ?? ('Feature ' . ($index + 1)),
            'name'    => $featureInputs[$index]['name'] ?? '',
            'project' => null,
            'area'    => formatHectares($feature['area_hectares']),
            'steward' => null,
            'status'  => 'Preview',
            'rings'   => geoToLeafletLatLngs($geometry),
        ];
    }
    ?>

    <div class="details-box">
        <div class="details-row">
            <span class="details-label">Source File</span>
            <span><?= h($preview['source_file']) ?></span>
        </div>
        <div class="details-row">
            <span class="details-label">Format</span>
            <span><?= h(ucfirst($preview['format'])) ?></span>
        </div>
        <div class="details-row">
            <span class="details-label">Coordinate System</span>
            <span><?= h($preview['crs_description'] !== '' ? $preview['crs_description'] : 'Not specified') ?></span>
        </div>
        <div class="details-row">
            <span class="details-label">Polygons Found</span>
            <span><?= count($features) ?></span>
        </div>
    </div>

    <?php foreach ($duplicateNotices as $notice): ?>
        <div class="alert alert-warning"><?= h($notice) ?></div>
    <?php endforeach; ?>

    <div class="dashboard-map-section">
        <h2>Preview</h2>
        <p class="dashboard-map-caption">
            Check that the boundary sits in the correct location before confirming.
            If it does not, the coordinate reference system of the file may be wrong.
        </p>
        <div id="polygon-map" class="dashboard-map"></div>
    </div>

    <?php // ---------- Correct the coordinate system ---------- ?>
    <form class="form form-wide" action="import.php" method="post">
        <input type="hidden" name="action" value="setcrs">
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <label for="fix_crs_choice">Boundary in the wrong place? Try another coordinate system</label>
        <select id="fix_crs_choice" name="crs_choice">
            <option value="">— Select a coordinate system —</option>
            <?php foreach ($crsPresets as $presetId => $preset): ?>
                <option value="<?= h($presetId) ?>"
                    <?= ($preview['crs_choice'] ?? '') === $presetId ? 'selected' : '' ?>>
                    <?= h($preset['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="hint">
            The boundary is re-projected from the original file coordinates each time, so
            you can try as many options as you need without losing accuracy.
        </p>

        <div class="form-actions">
            <button type="submit" class="btn">Re-project Preview</button>
        </div>
    </form>

    <?php // ---------- Attribute mapping ---------- ?>
    <?php if (!empty($attributeNames)): ?>
        <form class="form form-wide" action="import.php" method="post">
            <input type="hidden" name="action" value="remap">
            <input type="hidden" name="token" value="<?= h($token) ?>">

            <h2>Attribute Mapping</h2>
            <p class="hint">
                These are the attribute columns found in the file. Choose which one should
                fill each polygon field, then select "Apply Mapping" to update the values below.
            </p>

            <?php
            $mapFields = [
                'polygon_code' => 'Polygon Code',
                'polygon_name' => 'Polygon Name',
                'location'     => 'Location',
                'area'         => 'Original Area',
            ];
            ?>

            <?php foreach ($mapFields as $field => $label): ?>
                <label for="map_<?= h($field) ?>"><?= h($label) ?></label>
                <select id="map_<?= h($field) ?>" name="map[<?= h($field) ?>]">
                    <option value="">— Not mapped —</option>
                    <?php foreach ($attributeNames as $attributeName): ?>
                        <option value="<?= h($attributeName) ?>"
                            <?= ($mapping[$field] ?? null) === $attributeName ? 'selected' : '' ?>>
                            <?= h($attributeName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endforeach; ?>

            <div class="form-actions">
                <button type="submit" class="btn">Apply Mapping</button>
            </div>
        </form>
    <?php else: ?>
        <div class="alert alert-warning">
            This file does not contain any attribute columns, so the polygon details below
            were generated automatically. Please review and adjust them.
        </div>
    <?php endif; ?>

    <?php // ---------- Confirm ---------- ?>
    <form class="form form-wide" action="import.php" method="post">
        <input type="hidden" name="action" value="confirm">
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <h2>Polygons to Import</h2>

        <table class="data-table">
            <thead>
            <tr>
                <th>Import</th>
                <th>Polygon Code</th>
                <th>Polygon Name</th>
                <th>Location</th>
                <th>Calculated Area</th>
                <th>CENRO Area</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($features as $index => $feature): ?>
                <?php $input = $featureInputs[$index] ?? []; ?>
                <tr>
                    <td>
                        <input type="checkbox" name="include[]" value="<?= (int)$index ?>" checked>
                    </td>
                    <td>
                        <input type="text" name="polygon_code[<?= (int)$index ?>]"
                               value="<?= h($input['code'] ?? '') ?>" maxlength="50" required>
                    </td>
                    <td>
                        <input type="text" name="polygon_name[<?= (int)$index ?>]"
                               value="<?= h($input['name'] ?? '') ?>" maxlength="150" required>
                    </td>
                    <td>
                        <input type="text" name="location[<?= (int)$index ?>]"
                               value="<?= h($input['location'] ?? '') ?>" maxlength="200">
                    </td>
                    <td><?= h(formatHectares($feature['area_hectares'])) ?></td>
                    <td>
                        <?php if (($input['area_value'] ?? null) !== null): ?>
                            <?= h(formatOriginalArea($input['area_value'], $input['area_unit'])) ?>
                            <input type="hidden" name="original_area[<?= (int)$index ?>]"
                                   value="<?= h((string)$input['area_value']) ?>">
                            <input type="hidden" name="original_area_unit[<?= (int)$index ?>]"
                                   value="<?= h((string)$input['area_unit']) ?>">
                        <?php else: ?>
                            <span class="hint">Not supplied</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="hint">
            "Calculated Area" is measured by the system from the imported boundary.
            "CENRO Area" is the value supplied in the file and is stored separately —
            neither value overwrites the other.
        </p>

        <hr class="divider">

        <h2>Apply to All Imported Polygons</h2>

        <label for="project_id">Project</label>
        <select id="project_id" name="project_id">
            <option value="0">— No project —</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= (int)$project['project_id'] ?>"
                    <?= $selectedProjectId === (int)$project['project_id'] ? 'selected' : '' ?>>
                    <?= h($project['project_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="assigned_user_id">Assigned Steward / Field User</label>
        <select id="assigned_user_id" name="assigned_user_id">
            <option value="0">— Unassigned —</option>
            <?php foreach ($fieldUsers as $fieldUser): ?>
                <option value="<?= (int)$fieldUser['user_id'] ?>"
                    <?= $selectedStewardId === (int)$fieldUser['user_id'] ? 'selected' : '' ?>>
                    <?= h($fieldUser['full_name']) ?> (<?= h($fieldUser['username']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="hint">Only active accounts with the Field User role can be assigned.</p>

        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach (VALID_POLYGON_STATUSES as $status): ?>
                <option value="<?= h($status) ?>" <?= $selectedStatus === $status ? 'selected' : '' ?>>
                    <?= h(polygonStatusLabel($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Confirm Import</button>
            <a class="btn" href="index.php">Cancel</a>
        </div>
    </form>

<?php endif; ?>

<?php
$extraScripts = polygonLeafletScripts();

if ($action === 'preview') {
    $extraScripts .= '<script>initPolygonMap("polygon-map", '
        . jsonForScript($previewPayload ?? []) . ', {preview: true});</script>';
}

require_once __DIR__ . '/../page_end.php';
?>
