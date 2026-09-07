// Regenerate the small, offline SVG geometry from Natural Earth's public-domain GeoJSON.
// Usage: node scripts/build-stats-map.mjs /path/to/ne_110m_admin_0_countries.geojson
// Source: https://github.com/nvkelso/natural-earth-vector/blob/master/geojson/ne_110m_admin_0_countries.geojson
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { geoNaturalEarth1, geoPath } from 'd3-geo';

if (!process.argv[2]) throw new Error('Provide the Natural Earth 110m countries GeoJSON path');
const world = JSON.parse(readFileSync(process.argv[2], 'utf8'));
world.features = world.features.filter(feature => feature.properties.ISO_A2_EH !== 'AQ');
const projection = geoNaturalEarth1().fitExtent([[4, 4], [796, 416]], world);
const path = geoPath(projection).digits(1);
const outlines = new Map();
for (const feature of world.features) {
    // Join non-ISO map units to the ISO country used by visit geolocation.
    const code = feature.properties.ISO_A2_EH === '-99'
        ? ({ 'Somaliland': 'SO', 'Northern Cyprus': 'CY' }[feature.properties.ADMIN])
        : feature.properties.ISO_A2_EH;
    if (!/^[A-Z]{2}$/.test(code || '')) throw new Error(`Missing country code: ${feature.properties.ADMIN}`);
    const existing = outlines.get(code);
    if (existing) existing.path += path(feature);
    else outlines.set(code, { code, name: feature.properties.NAME_EN, path: path(feature) });
}
const countries = [...outlines.values()];
mkdirSync('src/assets/data', { recursive: true });
writeFileSync('src/assets/data/stats-world.json', JSON.stringify(countries) + '\n');
console.log(`Generated ${countries.length} country outlines`);
