#!/usr/bin/env python3
"""Check the delivered PDF's actual page targets, outline, text and figures."""
import hashlib, importlib.util, json, re
import pdfplumber
from pathlib import Path
from pypdf import PdfReader

ROOT=Path(__file__).resolve().parents[2]
reader=PdfReader(ROOT/'output/pdf/moed-comprehensive-user-manual.pdf')
report=json.loads((ROOT/'docs/user-manual/build-report.json').read_text())
shots=json.loads((ROOT/'docs/user-manual/screenshots.json').read_text())
pages=reader.pages
page_ids={p.indirect_reference.idnum:n for n,p in enumerate(pages)}
assert len(pages)==report['pages']
texts=[p.extract_text() or '' for p in pages]
for number in range(1,14):
    idx=report['destinations']['chapter-'+str(number)]-1
    assert f'CHAPTER {number:02}' in texts[idx], ('Wrong chapter target',number,idx)
links=0
for n,page in enumerate(pages):
    for ref in page.get('/Annots',[]):
        ann=ref.get_object()
        if ann.get('/Subtype')!='/Link':continue
        links+=1
        dest=ann.get('/Dest')
        if dest is None:
            action=ann.get('/A',{})
            assert action.get('/S')=='/GoTo',('External/unexpected PDF action',n,action)
            dest=action.get('/D')
        assert isinstance(dest,list),('Unresolved destination',n,dest)
        assert dest[0].idnum in page_ids,('Destination outside PDF',n,dest)
        x0,y0,x1,y1=map(float,ann['/Rect'])
        assert -1<=x0<=x1<=613 and -1<=y0<=y1<=793,('Link outside page',n,ann['/Rect'])
outline_count=0
def check_outline(entries):
    global outline_count
    for item in entries:
        if isinstance(item,list):check_outline(item)
        else:
            number=reader.get_destination_page_number(item)
            assert 0<=number<len(pages)
            outline_count+=1
check_outline(reader.outline)
for number,shot in enumerate(shots,1):
    index=report['destinations']['shot-'+shot['id']]-1
    assert len(pages[index].images)>0,('Missing screenshot',shot['id'])
    assert 'Figure ' in texts[index],('Figure caption missing',shot['id'])
    assert report['destinations']['end-shot-'+shot['id']]==index+1,('Split walkthrough',shot['id'])
all_text='\n'.join(texts)
for expected in ['Birthdays','Change Recovery Email','Reset presentation statistics','Closeout','Mattermost','Retry Failed Deliveries','PRUNE','Topic finder','Manage Email Templates','Archive and Restore','Delete and Access','Speaker names','Changing templates keeps your speaker selection']:
    assert expected in all_text,('Missing required topic',expected)
for forbidden in ['Lorem ipsum','TODO:','Traceback','Fatal error','Undefined variable']:
    assert forbidden not in all_text,('Unexpected placeholder/error',forbidden)
# Double braces are now intentional, documented template fields. Reject unknown tokens.
allowed_fields={'event_name','organization_name','event_dates','event_start_date','event_end_date','event_location','speaker_names','presentation_schedule'}
for field in re.findall(r'\{\{(.*?)\}\}',all_text,re.S):
    assert field.strip() in allowed_fields,('Unexpected template field',field)
assert '{{event_name}}' in all_text
assert links>300
assert len(shots)>=50
assert report['destinations']['__total__']==len(pages)
for index,text in enumerate(texts[1:],2):
    assert f'{index} / {len(pages)}' in text,('Missing total-page footer',index)
    if index<len(pages):assert 'Next >' in text,('Missing next-page navigation',index)
readme=(ROOT/'README.md').read_bytes()
assert reader.attachments['README.md']==[readme], 'Embedded README differs from current source'
assert report['readme_appendix']['sha256']==hashlib.sha256(readme).hexdigest()
spec=importlib.util.spec_from_file_location('manual_build',ROOT/'scripts/manual/build.py')
manual=importlib.util.module_from_spec(spec);spec.loader.exec_module(manual)
appendix_text='\n'.join(t.split('\nMOED\n')[0] for t in texts[report['readme_appendix']['start_page']-1:])
compact=lambda s:re.sub(r'[\s\u0590-\u05ff]+','',manual.clean(s))
searchable=compact(appendix_text)
fragments=0
for element in manual.README_ROOT.xpath('.//p|.//pre|.//li[not(p) and not(ul) and not(ol) and not(pre)]'):
    for fragment in element.itertext():
        if len(fragment.strip())<=3:continue
        fragments+=1
        if re.search('[\u0590-\u05ff]',fragment):
            # pypdf drops the Latin prefix in mixed-direction text; pdfplumber
            # reads the actual glyph positions independently for these lines.
            import pdfplumber
            with pdfplumber.open(ROOT/'output/pdf/moed-comprehensive-user-manual.pdf') as document:
                page=document.pages[report['destinations']['readme-section-2']-1]
                page_text=page.crop((manual.M,44,manual.M+manual.COL,748)).extract_text()
            assert compact(fragment) in compact(page_text),('Missing mixed-direction README text',fragment)
        else:assert compact(fragment) in searchable,('README text omitted',fragment[:120])
for section in manual.README_HEADINGS:
    assert section['key'] in report['destinations'],('README bookmark missing',section['title'])
assert len(pages[0].images)==0, 'The cover logo must remain vector, not a raster image'
assert sum(operator==b'c' for _,operator in pages[0].get_contents().operations)>100, 'SVG logo vector paths missing'
reverse_pages=0
with pdfplumber.open(ROOT/'output/pdf/moed-comprehensive-user-manual.pdf') as document:
    for number,page in enumerate(document.pages,1):
        reverse=number>=report['readme_appendix']['start_page']
        if reverse:
            reverse_pages+=1
            assert any(rect['x0']==0 and rect['y0']==0 and rect['x1']==612 and rect['y1']==792 and rect['non_stroking_color']==(0,0,0) for rect in page.rects),('Missing black page background',number)
        for char in page.chars:
            if not char['text'].strip():continue
            assert 30<=char['x0']<=char['x1']<=584 and 10<=char['top']<=char['bottom']<=782,('Text outside page margins',number,char['text'])
            if reverse:assert char['non_stroking_color']==(1,1,1),('README text must be white',number,char['text'],char['non_stroking_color'])
            region=report.get('column_regions',{}).get(str(number))
            if region and region['top']<=char['top']<region['bottom']:
                assert char['x1']<=manual.W-manual.M+.5,('Text exceeds column margin',number,char['text'])
                assert char['x1']<=manual.M+manual.COL+.5 or char['x0']>=manual.M+manual.COL+manual.GUTTER-.5,('Text crosses column gutter',number,char['text'])
assert report['automatic_word_breaks']==0
assert len(report['full_width_blocks'])>=5
for block in report['full_width_blocks']:
    assert abs(block['width']-manual.CW)<.1,('Reference block not full width',block)
assert 'Administrator' in texts[report['full_width_blocks'][0]['page']-1]
result={'pages':len(pages),'screenshots':len(shots),'internal_links':links,'bookmarks':outline_count,'chapter_targets':'13/13 valid','walkthrough_layout':'all screenshots, captions, and steps remain on one page','external_pdf_actions':0,'readme_appendix':{'sections':len(manual.README_HEADINGS),'text_fragments_verified':fragments,'attachment_bytes':len(readme),'attachment_matches_current_source':True,'sha256':hashlib.sha256(readme).hexdigest()}}
result['format']={'reference_text':'two columns','walkthrough_instructions':'two columns below spanning screenshots','readme_reverse_pages':reverse_pages,'readme_colors':'white text on black','cover_logo':'native SVG paths; no raster image or white backdrop','column_gutters':'clear'}
result['format'].update({'full_width_reference_blocks':len(report['full_width_blocks']),'automatic_word_breaks':0})
(ROOT/'docs/user-manual/verification.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
