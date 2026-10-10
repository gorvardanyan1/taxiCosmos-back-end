import { describe, expect, it } from 'vitest';
import { polygonToSvgPoints, type GeoJsonPolygon } from '@/lib/geo';

const square: GeoJsonPolygon = { type: 'Polygon', coordinates: [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]] };

describe('polygonToSvgPoints', () => {
    it('returns nothing without a shape', () => {
        expect(polygonToSvgPoints(null, 300, 300)).toEqual([]);
    });

    it('fits the shape inside the canvas with padding and puts north at the top', () => {
        const [ring] = polygonToSvgPoints(square, 300, 300, 20);
        const points = ring.split(' ').map((p) => p.split(',').map(Number));

        // (lng 0, lat 0) is bottom-left, (lng 10, lat 10) top-right; the shape is centred, so x gets a
        // half-pixel offset from the cos(latitude) squeeze.
        expect(points[0][1]).toBe(280);
        expect(points[2][1]).toBe(20);
        expect(Math.abs(points[0][0] - 20)).toBeLessThanOrEqual(1);
        expect(Math.abs(points[2][0] - 280)).toBeLessThanOrEqual(1);
        for (const [x, y] of points) {
            expect(x).toBeGreaterThanOrEqual(20);
            expect(x).toBeLessThanOrEqual(280);
            expect(y).toBeGreaterThanOrEqual(20);
            expect(y).toBeLessThanOrEqual(280);
        }
    });

    it('keeps proportions: a shape twice as wide as tall is twice as wide on screen', () => {
        const wide: GeoJsonPolygon = { type: 'Polygon', coordinates: [[[0, 0], [2, 0], [2, 1], [0, 1], [0, 0]]] };
        const points = polygonToSvgPoints(wide, 400, 400, 0)[0].split(' ').map((p) => p.split(',').map(Number));
        const width = points[1][0] - points[0][0];
        const height = points[0][1] - points[3][1];

        // cos(0.5°) ≈ 1, so the ratio is almost exactly 2.
        expect(width / height).toBeGreaterThan(1.99);
        expect(width / height).toBeLessThan(2.01);
    });

    it('shrinks longitude with latitude so shapes far from the equator are not stretched', () => {
        const at60: GeoJsonPolygon = { type: 'Polygon', coordinates: [[[0, 59.5], [2, 59.5], [2, 60.5], [0, 60.5], [0, 59.5]]] };
        const points = polygonToSvgPoints(at60, 400, 400, 0)[0].split(' ').map((p) => p.split(',').map(Number));

        // 2° of longitude at 60° latitude is as long as 1° of latitude.
        expect(Math.round((points[1][0] - points[0][0]) / (points[0][1] - points[3][1]))).toBe(1);
    });

    it('draws every ring of a MultiPolygon', () => {
        const multi: GeoJsonPolygon = { type: 'MultiPolygon', coordinates: [[[[0, 0], [1, 0], [1, 1], [0, 0]]], [[[5, 5], [6, 5], [6, 6], [5, 5]]]] };

        expect(polygonToSvgPoints(multi, 200, 200)).toHaveLength(2);
    });
});
