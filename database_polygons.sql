-- ============================================================
-- Reforestation Management Platform
-- CENRO Polygon Management Module - Database Schema
--
-- Run this AFTER database.sql. It only ADDS tables; it does not
-- modify or drop the existing `users` / `activity_logs` tables.
--
--   mysql -u root reforestation_db < database_polygons.sql
-- ============================================================

USE reforestation_db;

-- ============================================================
-- Projects
--
-- Minimal placeholder owned by the FUTURE Project Management
-- module. It exists now only so `polygons.project_id` has a real
-- foreign key to point at. When Project Management is built, it
-- should take ownership of THIS table and extend it rather than
-- creating a second project table.
-- ============================================================
CREATE TABLE IF NOT EXISTS projects (
    project_id   INT AUTO_INCREMENT PRIMARY KEY,
    project_code VARCHAR(50)  NULL UNIQUE,
    project_name VARCHAR(150) NOT NULL,
    location     VARCHAR(200) NULL,
    status       ENUM('active', 'inactive', 'completed') NOT NULL DEFAULT 'active',
    date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_projects_status (status)
);

-- ============================================================
-- CENRO Polygons
--
-- Geometry storage note:
-- The full polygon boundary is stored as a GeoJSON geometry in a
-- LONGTEXT column (`geometry`), normalised to WGS 84 / EPSG:4326
-- (lon, lat order, as per the GeoJSON spec). This avoids depending
-- on MySQL/MariaDB spatial types, which vary in availability and
-- SRID behaviour across XAMPP builds, while still preserving the
-- COMPLETE boundary - never a single centre point.
--
-- `original_geometry` keeps the geometry exactly as it was read
-- from the CENRO file, in the file's own CRS. It is written once
-- at import and never rewritten by ordinary edits.
--
-- The centroid_* and bbox_* columns are derived conveniences for
-- map fitting and list display only. They are NOT a substitute for
-- the boundary.
-- ============================================================
CREATE TABLE IF NOT EXISTS polygons (
    polygon_id   INT AUTO_INCREMENT PRIMARY KEY,

    -- ---------- Application-managed information ----------
    polygon_code VARCHAR(50)  NOT NULL UNIQUE,
    polygon_name VARCHAR(150) NOT NULL,
    project_id   INT          NULL,
    location     VARCHAR(200) NULL,
    assigned_user_id INT      NULL,
    status       ENUM('active', 'inactive', 'completed') NOT NULL DEFAULT 'active',
    remarks      TEXT         NULL,

    -- ---------- Official imported geometry (EPSG:4326) ----------
    geometry       LONGTEXT   NOT NULL,
    geometry_type  VARCHAR(20) NOT NULL,
    geometry_hash  CHAR(40)   NOT NULL,

    -- ---------- Preserved original import ----------
    original_geometry   LONGTEXT NULL,
    original_attributes LONGTEXT NULL,
    original_area_value DECIMAL(18,4) NULL,
    original_area_unit  VARCHAR(20)   NULL,

    -- ---------- System-calculated area ----------
    area_sqm      DECIMAL(18,4) NOT NULL DEFAULT 0,
    area_hectares DECIMAL(14,4) NOT NULL DEFAULT 0,

    -- ---------- Derived map helpers ----------
    centroid_lat DECIMAL(10,7) NULL,
    centroid_lng DECIMAL(10,7) NULL,
    bbox_min_lat DECIMAL(10,7) NULL,
    bbox_min_lng DECIMAL(10,7) NULL,
    bbox_max_lat DECIMAL(10,7) NULL,
    bbox_max_lng DECIMAL(10,7) NULL,

    -- ---------- Source provenance ----------
    source_file   VARCHAR(255) NULL,
    source_format ENUM('shapefile', 'geojson', 'kml') NOT NULL,
    source_crs    VARCHAR(255) NULL,
    source_hash   CHAR(40)     NULL,
    stored_file   VARCHAR(255) NULL,
    feature_index INT          NOT NULL DEFAULT 0,

    imported_by   INT NULL,
    date_imported TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (project_id)       REFERENCES projects(project_id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_user_id) REFERENCES users(user_id)       ON DELETE SET NULL,
    FOREIGN KEY (imported_by)      REFERENCES users(user_id)       ON DELETE SET NULL,

    INDEX idx_polygons_status   (status),
    INDEX idx_polygons_project  (project_id),
    INDEX idx_polygons_assigned (assigned_user_id),
    INDEX idx_polygons_hash     (geometry_hash),
    INDEX idx_polygons_source   (source_hash)
);

-- ============================================================
-- Sample project, so Admin/Manager have something to associate a
-- polygon with before Project Management exists. Safe to delete.
-- ============================================================
INSERT INTO projects (project_code, project_name, location, status)
SELECT 'PRJ-001', 'Manolo Fortich Reforestation Program', 'Manolo Fortich, Bukidnon', 'active'
WHERE NOT EXISTS (SELECT 1 FROM projects WHERE project_code = 'PRJ-001');
