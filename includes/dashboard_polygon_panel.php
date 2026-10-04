<?php
/**
 * Shared, role-scoped polygon analytics and CENRO boundary map.
 *
 * Expects $analytics, $mapPayload and $role to be set by the dashboard.
 */
$isFieldUserDashboard = $role === 'user';
$dashboardPolygonLabel = $isFieldUserDashboard ? 'Assigned polygons' : 'Imported polygons';
$dashboardImportLabel = $isFieldUserDashboard ? 'Source files for your areas' : 'Imported source files';
$dashboardTotal = (int)$analytics['polygon_count'];
$dashboardStatuses = [
    ['label' => 'Active', 'count' => (int)$analytics['active_count'], 'class' => 'active'],
    ['label' => 'Completed', 'count' => (int)$analytics['completed_count'], 'class' => 'completed'],
    ['label' => 'Inactive', 'count' => (int)$analytics['inactive_count'], 'class' => 'inactive'],
];
?>
<section class="dashboard-analytics" aria-labelledby="dashboard-analytics-heading">
    <div class="dashboard-section-heading">
        <div>
            <h2 id="dashboard-analytics-heading">CENRO data overview</h2>
            <p class="dashboard-map-caption">
                <?= $isFieldUserDashboard
                    ? 'Summary of CENRO polygons assigned to your account.'
                    : 'Summary of polygon boundaries and source files imported from CENRO.' ?>
            </p>
        </div>
        <?php if (in_array($role, ['admin', 'manager'], true)): ?>
            <a class="btn btn-primary" href="/RFP/<?= h($role) ?>/polygons/import.php">Import polygon data</a>
        <?php endif; ?>
    </div>

    <div class="dashboard-stat-grid">
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label"><?= h($dashboardPolygonLabel) ?></span>
            <strong class="dashboard-stat-value"><?= number_format($dashboardTotal) ?></strong>
        </article>
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label">Total mapped area</span>
            <strong class="dashboard-stat-value"><?= number_format($analytics['total_hectares'], 2) ?> <small>ha</small></strong>
        </article>
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label"><?= h($dashboardImportLabel) ?></span>
            <strong class="dashboard-stat-value"><?= number_format($analytics['source_file_count']) ?></strong>
        </article>
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label"><?= $isFieldUserDashboard ? 'Areas assigned to you' : 'Areas with a steward' ?></span>
            <strong class="dashboard-stat-value"><?= number_format($analytics['assigned_count']) ?></strong>
        </article>
    </div>

    <div class="dashboard-analytics-grid">
        <section class="dashboard-analytics-card" aria-labelledby="polygon-status-heading">
            <h3 id="polygon-status-heading">Polygon status</h3>
            <?php foreach ($dashboardStatuses as $status): ?>
                <?php $percentage = $dashboardTotal > 0 ? ($status['count'] / $dashboardTotal) * 100 : 0; ?>
                <div class="analytics-bar-row">
                    <div class="analytics-bar-label">
                        <span><?= h($status['label']) ?></span>
                        <strong><?= number_format($status['count']) ?></strong>
                    </div>
                    <div class="analytics-bar-track" role="img"
                         aria-label="<?= h($status['label']) ?>: <?= number_format($status['count']) ?> of <?= number_format($dashboardTotal) ?> polygons">
                        <span class="analytics-bar-fill analytics-bar-<?= h($status['class']) ?>"
                              style="width: <?= h((string)$percentage) ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <h3 class="analytics-subheading">Imported formats</h3>
            <?php if (empty($analytics['formats'])): ?>
                <p class="hint">No imported polygon files to summarize yet.</p>
            <?php else: ?>
                <ul class="analytics-format-list">
                    <?php foreach ($analytics['formats'] as $format): ?>
                        <li>
                            <span><?= h(strtoupper((string)$format['source_format'])) ?></span>
                            <strong><?= number_format((int)$format['polygon_count']) ?> polygon<?= (int)$format['polygon_count'] === 1 ? '' : 's' ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dashboard-analytics-card" aria-labelledby="recent-imports-heading">
            <h3 id="recent-imports-heading">Recent imported files</h3>
            <?php if (empty($analytics['recent_imports'])): ?>
                <p class="hint">No imported files are available in this dashboard scope.</p>
            <?php else: ?>
                <div class="recent-import-list">
                    <?php foreach ($analytics['recent_imports'] as $import): ?>
                        <article class="recent-import-item">
                            <div class="recent-import-details">
                                <strong><?= h($import['source_file'] ?: 'Unknown source file') ?></strong>
                                <span>
                                    <?= h(strtoupper((string)$import['source_format'])) ?>
                                    · <?= number_format((int)$import['polygon_count']) ?> polygon<?= (int)$import['polygon_count'] === 1 ? '' : 's' ?>
                                </span>
                            </div>
                            <time datetime="<?= h(date('c', strtotime($import['date_imported']))) ?>">
                                <?= h(date('M j, Y', strtotime($import['date_imported']))) ?>
                            </time>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>

<section class="dashboard-map-section polygon-map-section" aria-labelledby="dashboard-map-heading">
    <h2 id="dashboard-map-heading"><?= $isFieldUserDashboard ? 'Your assigned reforestation areas' : 'CENRO reforestation areas' ?></h2>
    <p class="dashboard-map-caption">
        <?= $isFieldUserDashboard
            ? 'Only the official CENRO boundaries assigned to your account are shown.'
            : 'Official imported CENRO boundaries. Select a polygon for its details; drag to pan and use map controls to zoom.' ?>
    </p>
    <div id="dashboard-map" class="dashboard-map"></div>
</section>
