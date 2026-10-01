(function () {
    'use strict';
    document.querySelectorAll('form[data-duplicate-kind]').forEach(function (form) {
        const kind=form.dataset.duplicateKind;
        const panel=form.querySelector('[data-duplicate-warning]');
        const names=kind==='contact'?['contact_first_name','contact_last_name','contact_email','contact_phone','contact_phone_country_code']:['organization_name','email','phone','phone_country_code','physical_address_line_1','physical_city','physical_state','physical_country'];
        const fields=names.map(name=>form.elements.namedItem(name)).filter(Boolean);
        let timer; let sequence=0;
        function check() {
            const current=++sequence;
            const query=new URLSearchParams({kind:kind});
            fields.forEach(el=>query.set(el.name,el.value));
            const returnTo=form.elements.namedItem('return_to'); if(returnTo) query.set('return_to',returnTo.value);
            fetch('creation_duplicates.php?'+query,{headers:{Accept:'application/json'}}).then(response=>{if(!response.ok) throw new Error('Lookup failed'); return response.json();}).then(function (data) {
                if(current!==sequence) return;
                const list=panel.querySelector('[data-duplicate-matches]'); list.replaceChildren();
                data.matches.forEach(function (match) {
                    const li=document.createElement('li'); const a=document.createElement('a');
                    a.href=match.url; a.textContent='Use Existing: '+match.label;
                    li.append(a,document.createTextNode(' '+[match.email,match.phone,match.location].filter(Boolean).join(' · ')));list.append(li);
                });
                panel.querySelector('[name="duplicate_token"]').value=data.token;
                panel.hidden=!data.matches.length;
            }).catch(function () { /* The server repeats the check before saving. */ });
        }
        fields.forEach(el=>el.addEventListener('input',function () {
            ++sequence; panel.querySelector('[name="duplicate_distinct"]').checked=false;
            clearTimeout(timer);timer=setTimeout(check,350);
        }));
    });
}());
