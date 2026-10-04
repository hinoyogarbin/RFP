<?php
/**
 * Shared CENRO Polygon Management functions.
 *
 * Used by admin/polygons/*, manager/polygons/* and user/polygons/* so the
 * data access, validation and permission rules live in exactly one place.
 *
 * All database access uses prepared statements through the existing
 * getDbConnection() helper.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/user_functions.php';   // h(), findUserById()
require_once __DIR__ . '/log_functions.php';    // logActivity()
require_once __DIR__ . '/geo/geometry.php';

const VALID_POLYGON_STATUSES = ['active', 'inactive', 'completed'];

/** Roles allowed to manage polygons. Field users are read-only. */
const POLYGON_MANAGER_ROLES = ['admin', 'manager'];

/**
 * True when the given role may create/edit/assign/import polygons.
 * Mirrors canManageTargetRole() in role_check.php - this is the
 * server-side authority, not the UI.
 */
function canManagePolygons(?string $role): bool
{
    return in_array((string)$role, POLYGON_MANAGER_ROLES, true);
}

/**
 * True when the given role may delete polygons.
 * Deletion is restricted to Admin, matching the platform's existing
 * treatment of destructive actions.
 */
function canDeletePolygons(?string $role): bool
{
    return $role === 'admin';
}

// ============================================================
// Projects
// ============================================================

/**
 * All projects, for the association dropdown.
 */
function getProjectsList(): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->query('SELECT project_id, project_code, project_name, location, status
                         FROM projects ORDER BY project_name ASC');
    return $stmt->fetchAll();
}

/**
 * A single project, or null.
 */
function findProjectById(?int $projectId): ?array
{
    if ($projectId === null || $projectId <= 0) {
        return null;
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE project_id = :id');
    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch();
    return $project ?: null;
}

// ============================================================
// Field users (stewards)
// ============================================================

/**
 * Active accounts with role = 'user'. Admin and Manager accounts are
 * deliberately excluded - they cannot be assigned as field stewards.
 */
function getAssignableFieldUsers(): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT user_id, full_name, username
                         FROM users
                         WHERE role = 'user' AND status = 'active'
                         ORDER BY full_name ASC");
    return $stmt->fetchAll();
}

/**
 * Validates that a proposed steward really is an active field user.
 * Returns an error string, or null when the assignment is acceptable.
 *
 * This runs server-side on every assignment so a tampered form value
 * cannot attach an Admin or Manager account to a polygon.
 */
function validateStewardAssignment(?int $userId): ?string
{
    if ($userId === null || $userId <= 0) {
        return null; // Unassigned is valid.
    }

    $user = findUserById($userId);
    if ($user === null) {
        return 'The selected field user could not be found.';
    }

    if ($user['role'] !== 'user') {
        return 'Only accounts with the Field User role can be assigned as a steward.';
    }

    if ($user['status'] !== 'active') {
        return 'The selected field user account is inactive.';
    }

    return null;
}

// ============================================================
// Polygon retrieval
// ============================================================

/**
 * The SELECT used by the listing and detail queries, joining the
 * project and steward names so the table needs no extra lookups.
 */
function polygonSelectSql(): string
{
    return 'SELECT p.*,
                   pr.project_name,
                   pr.project_code,
                   u.full_name AS steward_name,
                   u.username  AS steward_username
            FROM polygons p
            LEFT JOIN projects pr ON pr.project_id = p.project_id
            LEFT JOIN users u     ON u.user_id     = p.assigned_user_id';
}

/**
 * Fetches a single polygon with its joined names, or null.
 */
function findPolygonById(int $polygonId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(polygonSelectSql() . ' WHERE p.polygon_id = :id');
    $stmt->execute(['id' => $polygonId]);
    $polygon = $stmt->fetch();
    return $polygon ?: null;
}

/**
 * Filtered polygon listing.
 *
 * @param string   $search        Free text across code, name and location.
 * @param string   $statusFilter  '', 'active', 'inactive' or 'completed'.
 * @param int|null $projectFilter Project to restrict to, or null.
 * @param int|null $assignedUserId When set, ONLY polygons assigned to this
 *                                 user are returned. This is how a Field
 *                                 User is confined to their own area - it
 *                                 is enforced in SQL, not in the view.
 */
function getPolygonsList(
    string $search = '',
    string $statusFilter = '',
    ?int $projectFilter = null,
    ?int $assignedUserId = null
): array {
    $pdo = getDbConnection();

    $conditions = [];
    $params = [];

    if ($search !== '') {
        // Native prepared statements (ATTR_EMULATE_PREPARES => false) do not
        // allow the same named placeholder to appear more than once, so each
        // LIKE gets its own parameter.
        $conditions[] = '(p.polygon_code LIKE :search_code
                          OR p.polygon_name LIKE :search_name
                          OR p.location LIKE :search_location)';
        $params['search_code']     = '%' . $search . '%';
        $params['search_name']     = '%' . $search . '%';
        $params['search_location'] = '%' . $search . '%';
    }

    if ($statusFilter !== '' && in_array($statusFilter, VALID_POLYGON_STATUSES, true)) {
        $conditions[] = 'p.status = :status_filter';
        $params['status_filter'] = $statusFilter;
    }

    if ($projectFilter !== null && $projectFilter > 0) {
        $conditions[] = 'p.project_id = :project_filter';
        $params['project_filter'] = $projectFilter;
    }

    if ($assignedUserId !== null) {
        $conditions[] = 'p.assigned_user_id = :assigned_user';
        $params['assigned_user'] = $assignedUserId;
    }

    $sql = polygonSelectSql();
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY p.date_imported DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Polygons assigned to a specific field user.
 */
function getPolygonsForFieldUser(int $userId): array
{
    return getPolygonsList('', '', null, $userId);
}

/**
 * Summarises imported polygon data for a role-aware dashboard.
 *
 * When an assigned user ID is supplied, every metric and file summary is
 * restricted to that user's polygons.
 */
function getPolygonDashboardAnalytics(?int $assignedUserId = null): array
{
    $pdo = getDbConnection();
    $where = '';
    $params = [];

    if ($assignedUserId !== null) {
        $where = ' WHERE assigned_user_id = :assigned_user';
        $params['assigned_user'] = $assignedUserId;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS polygon_count,
                COALESCE(SUM(area_hectares), 0) AS total_hectares,
                SUM(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN status = \'inactive\' THEN 1 ELSE 0 END) AS inactive_count,
                SUM(CASE WHEN assigned_user_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned_count,
                COUNT(DISTINCT COALESCE(source_hash, source_file)) AS source_file_count
         FROM polygons' . $where
    );
    $stmt->execute($params);
    $summary = $stmt->fetch();

    $stmt = $pdo->prepare(
        'SELECT source_format, COUNT(*) AS polygon_count
         FROM polygons' . $where . '
         GROUP BY source_format
         ORDER BY source_format'
    );
    $stmt->execute($params);
    $formats = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT MAX(source_file) AS source_file,
                MAX(source_format) AS source_format,
                MAX(date_imported) AS date_imported,
                COUNT(*) AS polygon_count
         FROM polygons' . $where . '
         GROUP BY COALESCE(source_hash, source_file, polygon_id)
         ORDER BY date_imported DESC
         LIMIT 5'
    );
    $stmt->execute($params);

    return [
        'polygon_count' => (int)($summary['polygon_count'] ?? 0),
        'total_hectares' => (float)($summary['total_hectares'] ?? 0),
        'active_count' => (int)($summary['active_count'] ?? 0),
        'completed_count' => (int)($summary['completed_count'] ?? 0),
        'inactive_count' => (int)($summary['inactive_count'] ?? 0),
        'assigned_count' => (int)($summary['assigned_count'] ?? 0),
        'source_file_count' => (int)($summary['source_file_count'] ?? 0),
        'formats' => $formats,
        'recent_imports' => $stmt->fetchAll(),
    ];
}

/**
 * True when the polygon is assigned to the given user.
 * Used to gate a Field User's access to a polygon detail page.
 */
function polygonIsAssignedTo(array $polygon, int $userId): bool
{
    return (int)($polygon['assigned_user_id'] ?? 0) === $userId;
}

// ============================================================
// Duplicate detection
// ============================================================

/**
 * Looks for an existing polygon with the same geometry fingerprint.
 */
function findPolygonByGeometryHash(string $hash): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT polygon_id, polygon_code, polygon_name, date_imported
                           FROM polygons WHERE geometry_hash = :hash LIMIT 1');
    $stmt->execute(['hash' => $hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Looks for polygons previously imported from an identical source file.
 * Filename alone is never used - the same name is often reused for
 * different datasets - so this matches on a content hash.
 */
function findPolygonsBySourceHash(string $hash): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT polygon_id, polygon_code, polygon_name, source_file, date_imported
                           FROM polygons WHERE source_hash = :hash');
    $stmt->execute(['hash' => $hash]);
    return $stmt->fetchAll();
}

/**
 * True when a polygon code is already taken.
 */
function polygonCodeExists(string $code, ?int $excludePolygonId = null): bool
{
    $pdo = getDbConnection();

    $sql = 'SELECT polygon_id FROM polygons WHERE polygon_code = :code';
    $params = ['code' => $code];

    if ($excludePolygonId !== null) {
        $sql .= ' AND polygon_id <> :exclude';
        $params['exclude'] = $excludePolygonId;
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetch() !== false;
}

/**
 * Derives a unique polygon code from a preferred value, appending a
 * numeric suffix if needed.
 */
function makeUniquePolygonCode(string $preferred): string
{
    $base = polygonSanitiseCode($preferred);
    if ($base === '') {
        $base = 'POLY';
    }

    if (!polygonCodeExists($base)) {
        return $base;
    }

    for ($i = 2; $i <= 999; $i++) {
        $candidate = substr($base, 0, 46) . '-' . $i;
        if (!polygonCodeExists($candidate)) {
            return $candidate;
        }
    }

    return substr($base, 0, 40) . '-' . bin2hex(random_bytes(3));
}

/**
 * Normalises a polygon code to a predictable, safe form.
 */
function polygonSanitiseCode(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9._\-]+/', '-', $code) ?? '';
    $code = trim($code, '-');
    return substr($code, 0, 50);
}

// ============================================================
// Imported attribute mapping
// ============================================================

/**
 * Candidate source-attribute names for each application field.
 * CENRO datasets do not use consistent column names, so the import
 * suggests a mapping and the Admin/Manager confirms or overrides it.
 */
const POLYGON_ATTRIBUTE_CANDIDATES = [
    'polygon_code' => ['POLYGON_ID', 'POLYGONID', 'POLY_ID', 'POLYID', 'PARCEL_ID',
                       'LOT_NO', 'LOTNO', 'AREA_CODE', 'CODE', 'ID'],
    'polygon_name' => ['POLYGON_NAME', 'AREA_NAME', 'SITE_NAME', 'NAME', 'LABEL',
                       'DESCRIPTION', 'DESC', 'TITLE'],
    'location'     => ['LOCATION', 'BARANGAY', 'BRGY', 'MUNICIPALITY', 'MUNICIPAL',
                       'CITY_MUN', 'PROVINCE', 'SITIO', 'ADDRESS', 'PLACE'],
    'area'         => ['AREA_HA', 'AREAHA', 'HECTARES', 'HAS', 'HA', 'AREA_SQM',
                       'SHAPE_AREA', 'SHAPE_AREA_', 'AREA'],
    'project'      => ['PROJECT', 'PROJ_NAME', 'PROJECT_NA', 'PROGRAM', 'PROJ'],
    'status'       => ['STATUS', 'STATE', 'CONDITION'],
];

/**
 * Suggests which imported attribute should feed each application field.
 * Matching is case-insensitive and ignores separators, because shapefile
 * .dbf column names are truncated to 10 characters and often mangled.
 *
 * @param array $attributeNames Attribute keys present in the file.
 * @return array<string, string|null> field => attribute name (or null)
 */
function suggestAttributeMapping(array $attributeNames): array
{
    $normalised = [];
    foreach ($attributeNames as $name) {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$name) ?? '');
        $normalised[$key] = $name;
    }

    $mapping = [];

    foreach (POLYGON_ATTRIBUTE_CANDIDATES as $field => $candidates) {
        $mapping[$field] = null;

        // Exact (normalised) match first.
        foreach ($candidates as $candidate) {
            $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $candidate) ?? '');
            if (isset($normalised[$key])) {
                $mapping[$field] = $normalised[$key];
                continue 2;
            }
        }

        // Then a prefix match, to catch truncated .dbf names.
        foreach ($candidates as $candidate) {
            $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $candidate) ?? '');
            if (strlen($key) < 3) {
                continue;
            }
            foreach ($normalised as $presentKey => $originalName) {
                if (strpos($presentKey, $key) === 0 || strpos($key, $presentKey) === 0) {
                    $mapping[$field] = $originalName;
                    continue 3;
                }
            }
        }
    }

    return $mapping;
}

/**
 * Works out whether an area attribute is expressed in hectares or square
 * metres, based on its column name and magnitude.
 *
 * @return array{value: float|null, unit: string|null}
 */
function interpretAreaAttribute(?string $attributeName, $value): array
{
    if ($attributeName === null || $value === null || !is_numeric($value)) {
        return ['value' => null, 'unit' => null];
    }

    $number = (float)$value;
    if ($number <= 0) {
        return ['value' => null, 'unit' => null];
    }

    $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $attributeName) ?? '');

    if (strpos($key, 'SQM') !== false || strpos($key, 'SQMETER') !== false
        || strpos($key, 'SHAPEAREA') !== false || strpos($key, 'M2') !== false) {
        return ['value' => $number, 'unit' => 'sqm'];
    }

    if (strpos($key, 'HA') !== false || strpos($key, 'HECTARE') !== false) {
        return ['value' => $number, 'unit' => 'ha'];
    }

    // Unlabelled: a reforestation parcel in hectares is rarely above
    // 100000, whereas the same parcel in square metres usually is.
    return ['value' => $number, 'unit' => ($number > 100000) ? 'sqm' : 'ha'];
}

/**
 * Pulls a value out of an attribute row using a chosen mapping.
 */
function attributeValue(array $attributes, ?string $attributeName): ?string
{
    if ($attributeName === null || !array_key_exists($attributeName, $attributes)) {
        return null;
    }

    $value = $attributes[$attributeName];
    if ($value === null || $value === '') {
        return null;
    }

    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    return (string)$value;
}

// ============================================================
// Create / update
// ============================================================

/**
 * Inserts one imported polygon.
 *
 * @param array $feature Converted feature from polygonConvertFeatures().
 * @param array $meta    Import-level metadata (format, source file, hash...).
 * @param array $info    Administrative fields chosen by the operator.
 * @return int New polygon_id.
 */
function createPolygonFromImport(array $feature, array $meta, array $info, int $importedBy): int
{
    $pdo = getDbConnection();

    $bbox = $feature['bbox'] ?? null;
    $centroid = $feature['centroid'] ?? null;

    $sql = 'INSERT INTO polygons (
                polygon_code, polygon_name, project_id, location, assigned_user_id, status, remarks,
                geometry, geometry_type, geometry_hash,
                original_geometry, original_attributes, original_area_value, original_area_unit,
                area_sqm, area_hectares,
                centroid_lat, centroid_lng,
                bbox_min_lat, bbox_min_lng, bbox_max_lat, bbox_max_lng,
                source_file, source_format, source_crs, source_hash, stored_file, feature_index,
                imported_by
            ) VALUES (
                :polygon_code, :polygon_name, :project_id, :location, :assigned_user_id, :status, :remarks,
                :geometry, :geometry_type, :geometry_hash,
                :original_geometry, :original_attributes, :original_area_value, :original_area_unit,
                :area_sqm, :area_hectares,
                :centroid_lat, :centroid_lng,
                :bbox_min_lat, :bbox_min_lng, :bbox_max_lat, :bbox_max_lng,
                :source_file, :source_format, :source_crs, :source_hash, :stored_file, :feature_index,
                :imported_by
            )';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'polygon_code'     => $info['polygon_code'],
        'polygon_name'     => $info['polygon_name'],
        'project_id'       => $info['project_id'] ?: null,
        'location'         => $info['location'] !== '' ? $info['location'] : null,
        'assigned_user_id' => $info['assigned_user_id'] ?: null,
        'status'           => $info['status'],
        'remarks'          => $info['remarks'] !== '' ? $info['remarks'] : null,

        'geometry'      => json_encode($feature['geometry']),
        'geometry_type' => $feature['geometry_type'],
        'geometry_hash' => $feature['geometry_hash'],

        'original_geometry'   => json_encode($feature['original_geometry']),
        'original_attributes' => json_encode($feature['attributes']),
        'original_area_value' => $info['original_area_value'],
        'original_area_unit'  => $info['original_area_unit'],

        'area_sqm'      => round($feature['area_sqm'], 4),
        'area_hectares' => round($feature['area_hectares'], 4),

        'centroid_lat' => $centroid !== null ? round($centroid['y'], 7) : null,
        'centroid_lng' => $centroid !== null ? round($centroid['x'], 7) : null,

        'bbox_min_lat' => $bbox !== null ? round($bbox['min_y'], 7) : null,
        'bbox_min_lng' => $bbox !== null ? round($bbox['min_x'], 7) : null,
        'bbox_max_lat' => $bbox !== null ? round($bbox['max_y'], 7) : null,
        'bbox_max_lng' => $bbox !== null ? round($bbox['max_x'], 7) : null,

        'source_file'   => $meta['source_file'],
        'source_format' => $meta['format'],
        'source_crs'    => $meta['crs_description'] !== '' ? $meta['crs_description'] : null,
        'source_hash'   => $meta['source_hash'],
        'stored_file'   => $meta['stored_file'],
        'feature_index' => (int)($feature['index'] ?? 0),

        'imported_by' => $importedBy,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Updates only the administrative information of a polygon.
 *
 * The geometry columns are deliberately absent from this statement: an
 * edit to the name, project, steward or status must never disturb the
 * official CENRO boundary.
 */
function updatePolygonInfo(int $polygonId, array $data): void
{
    $pdo = getDbConnection();

    $stmt = $pdo->prepare(
        'UPDATE polygons
         SET polygon_code = :polygon_code,
             polygon_name = :polygon_name,
             project_id   = :project_id,
             location     = :location,
             status       = :status,
             remarks      = :remarks
         WHERE polygon_id = :id'
    );

    $stmt->execute([
        'polygon_code' => $data['polygon_code'],
        'polygon_name' => $data['polygon_name'],
        'project_id'   => $data['project_id'] ?: null,
        'location'     => $data['location'] !== '' ? $data['location'] : null,
        'status'       => $data['status'],
        'remarks'      => $data['remarks'] !== '' ? $data['remarks'] : null,
        'id'           => $polygonId,
    ]);
}

/**
 * Assigns (or clears) the steward for a polygon.
 */
function assignPolygonSteward(int $polygonId, ?int $userId): void
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE polygons SET assigned_user_id = :user_id WHERE polygon_id = :id');
    $stmt->execute([
        'user_id' => ($userId !== null && $userId > 0) ? $userId : null,
        'id'      => $polygonId,
    ]);
}

/**
 * Changes a polygon's status.
 */
function setPolygonStatus(int $polygonId, string $status): void
{
    if (!in_array($status, VALID_POLYGON_STATUSES, true)) {
        return;
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE polygons SET status = :status WHERE polygon_id = :id');
    $stmt->execute(['status' => $status, 'id' => $polygonId]);
}

/**
 * Permanently removes a polygon record and its archived source file.
 */
function deletePolygon(int $polygonId): void
{
    $polygon = findPolygonById($polygonId);

    $pdo = getDbConnection();
    $stmt = $pdo->prepare('DELETE FROM polygons WHERE polygon_id = :id');
    $stmt->execute(['id' => $polygonId]);

    // Clean up the archived CENRO file, but only if no other record still
    // points at it (a multi-feature import shares one source file).
    if ($polygon !== null && !empty($polygon['stored_file'])) {
        $check = $pdo->prepare('SELECT polygon_id FROM polygons WHERE stored_file = :file LIMIT 1');
        $check->execute(['file' => $polygon['stored_file']]);

        if ($check->fetch() === false) {
            $path = __DIR__ . '/../storage/polygons/' . basename($polygon['stored_file']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

// ============================================================
// Validation
// ============================================================

/**
 * Validates the administrative fields of a polygon.
 *
 * @return array List of human-readable errors; empty means valid.
 */
function validatePolygonInfo(array $data, ?int $editingPolygonId = null): array
{
    $errors = [];

    $code = trim($data['polygon_code'] ?? '');
    $name = trim($data['polygon_name'] ?? '');
    $location = trim($data['location'] ?? '');
    $status = $data['status'] ?? '';
    $projectId = $data['project_id'] ?? null;

    if ($code === '') {
        $errors[] = 'Polygon Code is required.';
    } elseif (!preg_match('/^[A-Za-z0-9._\-]{2,50}$/', $code)) {
        $errors[] = 'Polygon Code must be 2-50 characters and may only contain letters, '
            . 'numbers, hyphens, underscores and periods.';
    } elseif (polygonCodeExists(polygonSanitiseCode($code), $editingPolygonId)) {
        $errors[] = 'This Polygon Code is already in use.';
    }

    if ($name === '') {
        $errors[] = 'Polygon Name is required.';
    } elseif (mb_strlen($name) > 150) {
        $errors[] = 'Polygon Name must be 150 characters or fewer.';
    }

    if ($location !== '' && mb_strlen($location) > 200) {
        $errors[] = 'Location must be 200 characters or fewer.';
    }

    if (!in_array($status, VALID_POLYGON_STATUSES, true)) {
        $errors[] = 'Invalid polygon status selected.';
    }

    if ($projectId !== null && $projectId !== '' && (int)$projectId > 0) {
        if (findProjectById((int)$projectId) === null) {
            $errors[] = 'The selected project could not be found.';
        }
    }

    $stewardError = validateStewardAssignment(
        isset($data['assigned_user_id']) && $data['assigned_user_id'] !== ''
            ? (int)$data['assigned_user_id']
            : null
    );
    if ($stewardError !== null) {
        $errors[] = $stewardError;
    }

    return $errors;
}

// ============================================================
// Presentation helpers
// ============================================================

/**
 * Formats an area in hectares for display, e.g. "12.45 ha".
 */
function formatHectares($hectares): string
{
    if ($hectares === null || !is_numeric($hectares)) {
        return '-';
    }

    $value = (float)$hectares;

    if ($value > 0 && $value < 0.01) {
        return number_format($value * 10000, 0) . ' m²';
    }

    return number_format($value, 2) . ' ha';
}

/**
 * Formats the preserved original CENRO area value with its unit.
 */
function formatOriginalArea($value, ?string $unit): string
{
    if ($value === null || !is_numeric($value)) {
        return '-';
    }

    $number = (float)$value;

    if ($unit === 'sqm') {
        return number_format($number, 2) . ' m² (' . number_format($number / 10000, 2) . ' ha)';
    }

    return number_format($number, 2) . ' ha';
}

/**
 * Human label for a polygon status.
 */
function polygonStatusLabel(string $status): string
{
    return ucfirst($status);
}

/**
 * Decodes a stored geometry column into an array, or null when the
 * stored value is unusable.
 */
function decodeStoredGeometry(?string $json): ?array
{
    if ($json === null || $json === '') {
        return null;
    }

    $geometry = json_decode($json, true);
    if (!is_array($geometry) || !isset($geometry['type'])) {
        return null;
    }

    return $geometry;
}

/**
 * Decodes the preserved original attribute set.
 */
function decodeStoredAttributes(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $attributes = json_decode($json, true);
    return is_array($attributes) ? $attributes : [];
}

/**
 * Builds the compact payload the Leaflet map script consumes.
 * Coordinates are emitted in Leaflet's [lat, lng] order.
 *
 * @param array $polygons Rows from getPolygonsList()/findPolygonById().
 */
function buildMapPayload(array $polygons): array
{
    $payload = [];

    foreach ($polygons as $polygon) {
        $geometry = decodeStoredGeometry($polygon['geometry'] ?? null);
        if ($geometry === null) {
            continue;
        }

        $rings = geoToLeafletLatLngs($geometry);
        if (empty($rings)) {
            continue;
        }

        $payload[] = [
            'id'      => (int)$polygon['polygon_id'],
            'code'    => (string)$polygon['polygon_code'],
            'name'    => (string)$polygon['polygon_name'],
            'project' => $polygon['project_name'] ?? null,
            'location' => $polygon['location'] ?? null,
            'area'    => formatHectares($polygon['area_hectares'] ?? null),
            'steward' => $polygon['steward_name'] ?? null,
            'status'  => polygonStatusLabel((string)$polygon['status']),
            'rings'   => $rings,
        ];
    }

    return $payload;
}

/**
 * Safely embeds a PHP value as JSON inside a <script> block.
 */
function jsonForScript($value): string
{
    $encoded = json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );

    return $encoded !== false ? $encoded : '[]';
}

// ============================================================
// Activity logging
// ============================================================

/**
 * Records a polygon action in the existing activity log.
 */
function logPolygonActivity(string $action, string $description, string $status = 'success'): void
{
    logActivity(
        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        (string)($_SESSION['full_name'] ?? 'Unknown'),
        (string)($_SESSION['role'] ?? 'user'),
        $action,
        'CENRO Polygons',
        $description,
        $status
    );
}

// ============================================================
// Pending import handoff (preview -> confirm)
// ============================================================

const POLYGON_PENDING_DIR = __DIR__ . '/../storage/polygons/pending';

/**
 * Stores a parsed-but-unsaved import so the confirm step can pick it up.
 * The payload is written to disk rather than the session, because a
 * detailed boundary can be several megabytes.
 *
 * Returns an opaque token.
 */
function storePendingImport(array $payload): ?string
{
    if (!is_dir(POLYGON_PENDING_DIR) && !@mkdir(POLYGON_PENDING_DIR, 0700, true)
        && !is_dir(POLYGON_PENDING_DIR)) {
        return null;
    }

    polygonPurgeStalePending();

    $token = bin2hex(random_bytes(16));
    $path = POLYGON_PENDING_DIR . '/' . $token . '.json';

    $encoded = json_encode($payload);
    if ($encoded === false || @file_put_contents($path, $encoded) === false) {
        return null;
    }

    @chmod($path, 0600);

    // Tie the token to the session so another account cannot claim it.
    $_SESSION['pending_polygon_import'] = [
        'token'   => $token,
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'created' => time(),
    ];

    return $token;
}

/**
 * Retrieves a pending import, verifying that it belongs to the current
 * session and has not expired.
 */
function loadPendingImport(string $token): ?array
{
    $pending = $_SESSION['pending_polygon_import'] ?? null;

    if (!is_array($pending)
        || !isset($pending['token'], $pending['user_id'])
        || !hash_equals((string)$pending['token'], $token)
        || (int)$pending['user_id'] !== (int)($_SESSION['user_id'] ?? -1)) {
        return null;
    }

    if ((time() - (int)($pending['created'] ?? 0)) > 3600) {
        clearPendingImport($token);
        return null;
    }

    $path = POLYGON_PENDING_DIR . '/' . basename($token) . '.json';
    if (!is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : null;
}

/**
 * Discards a pending import.
 */
function clearPendingImport(?string $token = null): void
{
    if ($token !== null) {
        $path = POLYGON_PENDING_DIR . '/' . basename($token) . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    unset($_SESSION['pending_polygon_import']);
}

/**
 * Removes abandoned preview payloads older than an hour.
 */
function polygonPurgeStalePending(): void
{
    if (!is_dir(POLYGON_PENDING_DIR)) {
        return;
    }

    $files = @glob(POLYGON_PENDING_DIR . '/*.json') ?: [];
    $cutoff = time() - 3600;

    foreach ($files as $file) {
        if (@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
