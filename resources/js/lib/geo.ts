export interface GeoJsonPolygon {
    type: 'Polygon' | 'MultiPolygon';
    coordinates: number[][][] | number[][][][];
}

/**
 * Projects a zone's GeoJSON onto a width x height canvas (equirectangular, fitted to the shape with
 * padding, y flipped so north is up) and returns one SVG `points` string per ring. This is only the
 * preview until the map editor (P13-T7) replaces it.
 */
export function polygonToSvgPoints(geometry: GeoJsonPolygon | null, width: number, height: number, padding = 24): string[] {
    if (!geometry) return [];
    const polygons = (geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates) as number[][][][];
    const rings = polygons.flatMap((polygon) => polygon);
    const positions = rings.flat();
    if (positions.length === 0) return [];

    const lngs = positions.map((p) => p[0]);
    const lats = positions.map((p) => p[1]);
    const [minLng, maxLng, minLat, maxLat] = [Math.min(...lngs), Math.max(...lngs), Math.min(...lats), Math.max(...lats)];
    // Degrees of longitude are shorter away from the equator; keep the shape's proportions.
    const lngScale = Math.cos(((minLat + maxLat) / 2) * (Math.PI / 180));
    const spanX = Math.max((maxLng - minLng) * lngScale, 1e-9);
    const spanY = Math.max(maxLat - minLat, 1e-9);
    const scale = Math.min((width - 2 * padding) / spanX, (height - 2 * padding) / spanY);
    const offsetX = (width - spanX * scale) / 2;
    const offsetY = (height - spanY * scale) / 2;

    return rings.map((ring) => ring
        .map(([lng, lat]) => `${round(offsetX + (lng - minLng) * lngScale * scale)},${round(offsetY + (maxLat - lat) * scale)}`)
        .join(' '));
}

const round = (value: number) => Math.round(value * 10) / 10;
