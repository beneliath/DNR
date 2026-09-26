const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('timeline row hover and keyboard focus move and clear the date marker', () => {
    const node = () => ({ hidden: true, dataset: {}, events: {},
        addEventListener(name, handler) { this.events[name] = handler; },
        contains(other) { return other === this.child; },
    });
    const rows = [node(), node(), node()];
    rows[0].dataset.statsPeriod = '2026-09-07';
    rows[1].dataset.statsPeriod = '2026-09-08';
    rows[2].dataset.statsPeriod = '2026-09';
    const nodes = new Map();
    const getNode = id => { if (!nodes.has(id)) nodes.set(id, node()); return nodes.get(id); };
    getNode('short-link-stats-data').textContent = JSON.stringify({
        period: 'day', total: 5, timeline: [{label:'2026-09-07', total:2}, {label:'2026-09-08', total:3}],
        referrer: [], browser: [], country: [], os: [],
    });
    const report = { querySelectorAll: () => rows };
    const lines = [];
    let timeline;
    class Chart {
        static register() {}
        constructor(canvas, config) {
            timeline = this; this.config = config;
            this.scales = {x: {getPixelForValue: index => 50 + index * 100}};
            this.chartArea = {top:10, bottom:200};
            this.ctx = {save(){}, restore(){}, beginPath(){}, stroke(){},
                moveTo(x,y){ lines.push(['start',x,y]); }, lineTo(x,y){ lines.push(['end',x,y]); }};
        }
        draw() { this.config.plugins[0].afterDatasetsDraw(this); }
    }
    const source = fs.readFileSync('src/assets/js/short-link-stats.js', 'utf8').replace(/^import .*;\n/gm, '');
    const chartComponents = Object.fromEntries(['LineController','LineElement','PointElement','CategoryScale','LinearScale','Filler','Tooltip','DoughnutController','ArcElement','BarController','BarElement'].map(key => [key, {}]));
    vm.runInNewContext(source, { ...chartComponents, Chart, countries: [], Intl, Date, Map, Set,
        document: {documentElement:{lang:'en'}, getElementById:getNode, querySelector:()=>report, querySelectorAll:()=>[]},
        getComputedStyle:()=>({fontFamily:'sans-serif', getPropertyValue:()=> '#c55c0c'}),
        MutationObserver:class {observe(){}}, console:{error(error){throw error;}},
    });
    rows[0].events.pointerenter();
    assert.deepEqual(lines.splice(0), [['start',50,10],['end',50,200]]);
    rows[1].events.pointerenter();
    assert.deepEqual(lines.splice(0), [['start',150,10],['end',150,200]]);
    rows[1].events.pointerleave();
    assert.equal(lines.length,0);
    rows[0].events.focusin();
    assert.deepEqual(lines.splice(0), [['start',50,10],['end',50,200]]);
    rows[0].child = {};
    rows[0].events.focusout({relatedTarget:rows[0].child});
    timeline.draw();
    assert.equal(lines.splice(0).length,2);
    rows[0].events.focusout({relatedTarget:null});
    assert.equal(lines.length,0);
    // Long ranges use the month bucket key, without parsing it as a local date.
    const payload = JSON.parse(getNode('short-link-stats-data').textContent);
    payload.period = 'month'; payload.timeline = [{label:'2026-09',total:5}];
    getNode('short-link-stats-data').textContent = JSON.stringify(payload);
    vm.runInNewContext(source, { ...chartComponents, Chart, countries: [], Intl, Date, Map, Set,
        document: {documentElement:{lang:'en'}, getElementById:getNode, querySelector:()=>report, querySelectorAll:()=>[]},
        getComputedStyle:()=>({fontFamily:'sans-serif', getPropertyValue:()=> '#fb923c'}),
        MutationObserver:class {observe(){}}, console:{error(error){throw error;}},
    });
    rows[2].events.pointerenter();
    assert.deepEqual(lines.splice(0), [['start',50,10],['end',50,200]]);
    assert.equal(timeline.ctx.strokeStyle,'#fb923c');
});
