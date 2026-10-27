'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const {create}=require('../../src/assets/js/user-time.js');
test('missing or invalid preference uses explicit UTC',()=>{
    for(const zone of [undefined,'','Not/AZone']){
        const userTime=create(zone);assert.equal(userTime.timeZone,'UTC');
        assert.match(userTime.format('2026-10-26 12:00:00'),/12:00 PM UTC$/);
    }
});
test('timezone controls displayed dates and same-day comparisons across midnight',()=>{
    const userTime=create('America/Chicago');
    assert.equal(userTime.dateKey('2026-10-26T02:00:00Z'),'2026-10-25');
    assert.match(userTime.format('2026-10-26T12:00:00Z'),/7:00 AM CDT$/);
    assert.match(userTime.format('2026-11-01T06:30:00Z'),/1:30 AM CDT$/);
    assert.match(userTime.format('2026-11-01T07:30:00Z'),/1:30 AM CST$/);
});
test('half-hour offsets, explicit offsets, Date objects and style options are supported',()=>{
    assert.match(create('Asia/Kolkata').format(new Date('2026-10-26T12:00:00Z')),/5:30 PM GMT\+5:30$/);
    assert.match(create('UTC').format('2026-10-26T07:00:00-05:00',{dateStyle:'full',timeStyle:'long'}),/UTC$/);
    assert.equal(create('Pacific/Kiritimati').dateKey('2026-12-31T12:00:00Z'),'2027-01-01');
});
test('date-only fields and invalid inputs are not interpreted as local timestamps',()=>{
    const userTime=create('America/Chicago');assert.equal(userTime.format('2026-10-26'),'2026-10-26');
    assert.equal(userTime.instant('not a date'),null);assert.equal(userTime.format(null),'');
});
