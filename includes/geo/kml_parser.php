<?php
/**
 * KML reader.
 *
 * Reads Placemark polygons, including MultiGeometry, and collects the
 * Placemark name/description plus any ExtendedData as attributes.
 *
 * KML coordinates are always WGS 84 geographic, written as
 * "lon,lat[,altitude]" tuples separated by whitespace.
 */

require_once __DIR__ . '/geometry.php';

/**
 * @return array{ok: bool, error: string, features: array}
 */
function kmlParse(string $path): array
{
    $result = ['ok' => false, 'error' => '', 'features' => []];

    if (!is_readable($path)) {
        $result['error'] = 'The KML file could not be read.';
        return $result;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        $result['error'] = 'The KML file is empty.';
        return $result;
    }

    // Parse without network access and without entity expansion, so a
    // malicious KML cannot pull in external resources (XXE).
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
    if (defined('LIBXML_NOENT')) {
        // Deliberately NOT passing LIBXML_NOENT - entities stay unexpanded.
        $flags = $flags;
    }

    $xml = simplexml_load_string($raw, 'SimpleXMLElement', $flags);

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        $result['error'] = 'The KML file is not valid XML.';
        return $result;
    }

    // KML lives in its own namespace; register it so XPath works for both
    // namespaced and bare documents.
    $namespaces = $xml->getDocNamespaces(true);
    $kmlNamespace = null;
    foreach ($namespaces as $prefix => $uri) {
        if (strpos($uri, 'opengis.net/kml') !== false) {
            $kmlNamespace = $uri;
            break;
        }
    }

    if ($kmlNamespace !== null) {
        $xml->registerXPathNamespace('kml', $kmlNamespace);
        $placemarks = $xml->xpath('//kml:Placemark') ?: [];
    } else {
        $placemarks = $xml->xpath('//Placemark') ?: [];
    }

    $features = [];

    foreach ($placemarks as $index => $placemark) {
        if ($kmlNamespace !== null) {
            $placemark->registerXPathNamespace('kml', $kmlNamespace);
            $polygonNodes = $placemark->xpath('.//kml:Polygon') ?: [];
        } else {
            $polygonNodes = $placemark->xpath('.//Polygon') ?: [];
        }

        if (empty($polygonNodes)) {
            continue;
        }

        $attributes = kmlExtractAttributes($placemark, $kmlNamespace);

        $polygons = [];
        foreach ($polygonNodes as $polygonNode) {
            $rings = kmlExtractPolygonRings($polygonNode, $kmlNamespace);
            if ($rings) {
                $polygons[] = $rings;
            }
        }

        if (empty($polygons)) {
            continue;
        }

        $geometry = (count($polygons) === 1)
            ? ['type' => 'Polygon', 'coordinates' => $polygons[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $polygons];

        $features[] = [
            'geometry'   => $geometry,
            'attributes' => $attributes,
            'index'      => $index,
        ];
    }

    if (empty($features)) {
        $result['error'] = 'The KML file does not contain any polygon boundaries. '
            . 'Only polygon areas can be imported.';
        return $result;
    }

    $result['ok'] = true;
    $result['features'] = $features;
    return $result;
}

/**
 * Reads the outer boundary and any inner boundaries (holes) of a
 * KML <Polygon> node.
 */
function kmlExtractPolygonRings(SimpleXMLElement $polygonNode, ?string $namespace): array
{
    if ($namespace !== null) {
        $polygonNode->registerXPathNamespace('kml', $namespace);
        $outerNodes = $polygonNode->xpath('.//kml:outerBoundaryIs//kml:coordinates') ?: [];
        $innerNodes = $polygonNode->xpath('.//kml:innerBoundaryIs//kml:coordinates') ?: [];
    } else {
        $outerNodes = $polygonNode->xpath('.//outerBoundaryIs//coordinates') ?: [];
        $innerNodes = $polygonNode->xpath('.//innerBoundaryIs//coordinates') ?: [];
    }

    $rings = [];

    foreach ($outerNodes as $node) {
        $ring = kmlParseCoordinates((string)$node);
        if (count($ring) >= 3) {
            $rings[] = geoCloseRing($ring);
            break; // One outer boundary per polygon.
        }
    }

    if (empty($rings)) {
        return [];
    }

    foreach ($innerNodes as $node) {
        $ring = kmlParseCoordinates((string)$node);
        if (count($ring) >= 3) {
            $rings[] = geoCloseRing($ring);
        }
    }

    return $rings;
}

/**
 * Converts a KML <coordinates> block into [lon, lat] pairs.
 * Altitude, when present, is discarded.
 */
function kmlParseCoordinates(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $points = [];
    $tuples = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    foreach ($tuples as $tuple) {
        $parts = explode(',', $tuple);
        if (count($parts) < 2) {
            continue;
        }

        $lon = trim($parts[0]);
        $lat = trim($parts[1]);

        if (!is_numeric($lon) || !is_numeric($lat)) {
            continue;
        }

        $points[] = [(float)$lon, (float)$lat];
    }

    return $points;
}

/**
 * Collects a Placemark's name, description, and ExtendedData fields.
 */
function kmlExtractAttributes(SimpleXMLElement $placemark, ?string $namespace): array
{
    $attributes = [];

    $name = trim((string)($placemark->name ?? ''));
    if ($name !== '') {
        $attributes['name'] = $name;
    }

    $description = trim((string)($placemark->description ?? ''));
    if ($description !== '' && strlen($description) <= 1000) {
        $attributes['description'] = strip_tags($description);
    }

    if ($namespace !== null) {
        $placemark->registerXPathNamespace('kml', $namespace);
        $dataNodes = $placemark->xpath('.//kml:ExtendedData//kml:Data') ?: [];
        $simpleNodes = $placemark->xpath('.//kml:ExtendedData//kml:SimpleData') ?: [];
    } else {
        $dataNodes = $placemark->xpath('.//ExtendedData//Data') ?: [];
        $simpleNodes = $placemark->xpath('.//ExtendedData//SimpleData') ?: [];
    }

    foreach ($dataNodes as $node) {
        $key = trim((string)($node['name'] ?? ''));
        if ($key === '') {
            continue;
        }
        $value = trim((string)($node->value ?? ''));
        $attributes[$key] = ($value !== '') ? $value : null;
    }

    foreach ($simpleNodes as $node) {
        $key = trim((string)($node['name'] ?? ''));
        if ($key === '') {
            continue;
        }
        $value = trim((string)$node);
        $attributes[$key] = ($value !== '') ? $value : null;
    }

    return $attributes;
}
