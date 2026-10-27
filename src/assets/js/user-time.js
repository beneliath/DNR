(function(root){
    'use strict';
    const create = selected => {
        let timeZone = typeof selected === 'string' && selected ? selected : 'UTC';
        try { new Intl.DateTimeFormat('en-US', {timeZone}); } catch { timeZone = 'UTC'; }
        const instant = value => {
            if (value && typeof value === 'object') {
                try { const epoch = Date.prototype.getTime.call(value); return Number.isNaN(epoch) ? null : new Date(epoch); } catch { return null; }
            }
            if (typeof value === 'number') { const date = new Date(value); return Number.isNaN(date.getTime()) ? null : date; }
            if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(value)) return null;
            const normalized = value.replace(' ', 'T').replace(/ UTC$/, 'Z');
            const date = new Date(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized) ? normalized : normalized + 'Z');
            return Number.isNaN(date.getTime()) ? null : date;
        };
        const format = (value, options = {}) => {
            const date = instant(value); if (!date) return typeof value === 'string' ? value : '';
            const settings = Object.keys(options).length ? {...options} : {month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'};
            const text = new Intl.DateTimeFormat('en-US', {...settings,timeZone}).format(date);
            if (settings.timeZoneName) return text;
            const zone = new Intl.DateTimeFormat('en-US',{timeZone,hour:'numeric',timeZoneName:'short'}).formatToParts(date).find(part=>part.type==='timeZoneName').value;
            return text + ' ' + zone;
        };
        const dateKey = value => {
            const date = instant(value); if (!date) return '';
            const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US',{timeZone,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(date).map(part=>[part.type,part.value]));
            return `${parts.year}-${parts.month}-${parts.day}`;
        };
        return {timeZone,instant,format,dateKey};
    };
    const api=create(root.document?.querySelector('meta[name="user-timezone"]')?.content);
    api.create=create;
    if(typeof module==='object'&&module.exports)module.exports=api;
    else root.DnrUserTime=api;
})(globalThis);
