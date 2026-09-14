const test = require('node:test');
const assert = require('node:assert/strict');
const { formatMilliseconds, networkAssessment } = require('../../src/assets/js/network-diagnostics.js');

test('assessment refuses to infer IPv6 health from IPv4-only traffic', () => {
    const empty = networkAssessment({ IPv4: { sample_count: 0 }, IPv6: { sample_count: 0 } });
    assert.equal(empty.status, 'Waiting');

    const ipv4Only = networkAssessment({
        IPv4: { sample_count: 3, median_load_ms: 180 },
        IPv6: { sample_count: 0 },
    });
    assert.equal(ipv4Only.status, 'IPv4 only');
    assert.match(ipv4Only.detail, /does not establish whether IPv6 is healthy/);
});

test('assessment flags a material IPv6 delay and accepts comparable paths', () => {
    const slowIpv6 = networkAssessment({
        IPv4: { sample_count: 8, median_load_ms: 400 },
        IPv6: { sample_count: 5, median_load_ms: 1050 },
    });
    assert.equal(slowIpv6.status, 'IPv6 slower');
    assert.equal(slowIpv6.state, 'danger');

    const comparable = networkAssessment({
        IPv4: { sample_count: 8, median_load_ms: 400 },
        IPv6: { sample_count: 5, median_load_ms: 520 },
    });
    assert.equal(comparable.status, 'Comparable');
    assert.equal(formatMilliseconds(7.25), '7.3 ms');
    assert.equal(formatMilliseconds(null), '—');
});
