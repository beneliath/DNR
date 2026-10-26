#!/usr/bin/env python3
"""Validate final PDF navigation, readable content, and lossless native figures."""
import hashlib,json,re
from pathlib import Path
from PIL import Image
import pdfplumber
from pypdf import PdfReader
ROOT=Path(__file__).resolve().parents[2]
DATA=ROOT/'docs/message-monkey-user-guide'
PDF=ROOT/'output/pdf/message-monkey-comprehensive-user-guide.pdf'
report=json.loads((DATA/'build-report.json').read_text())
shots=json.loads((DATA/'screenshots.json').read_text())
chapters=json.loads((DATA/'chapters.json').read_text())
reader=PdfReader(PDF);pages=reader.pages
assert report['pages']==len(pages)==report['destinations']['__total__']
assert report['pdf_sha256']==hashlib.sha256(PDF.read_bytes()).hexdigest()
assert report['shared_content_sha256']==hashlib.sha256((ROOT/'src/templates/audience/manual-content.php').read_bytes()).hexdigest()
texts=[p.extract_text() or '' for p in pages]
ids={p.indirect_reference.idnum:n for n,p in enumerate(pages)}
links=0
for number,page in enumerate(pages,1):
    if number>1:assert f'{number} / {len(pages)}' in texts[number-1],('Missing page total',number)
    if 1<number<len(pages):assert 'Next >' in texts[number-1],('Missing next-page link',number)
    assert len(texts[number-1].strip())>100,('Nearly empty page',number)
    for ref in page.get('/Annots',[]):
        ann=ref.get_object()
        if ann.get('/Subtype')!='/Link':continue
        links+=1;dest=ann.get('/Dest')
        if dest is None:
            action=ann.get('/A',{});assert action.get('/S')=='/GoTo';dest=action['/D']
        assert isinstance(dest,list) and dest[0].idnum in ids,('Broken destination',number,dest)
        x0,y0,x1,y1=map(float,ann['/Rect']);assert 0<=x0<x1<=612 and 0<=y0<y1<=792
outline=[]
def visit(items):
    for item in items:
        if isinstance(item,list):visit(item)
        else:
            assert 0<=reader.get_destination_page_number(item)<len(pages)
            outline.append(item.title)
visit(reader.outline)
for i,ch in enumerate(chapters,1):
    page=report['destinations']['chapter-'+str(i)]
    assert f'CHAPTER {i:02}' in texts[page-1]
    assert len([s for s in shots if s['chapter']==i])>=1
    for id,title,body in ch['topics']:assert 'topic-'+id in report['destinations']
figure_count=0
for shot in shots:
    number=report['destinations']['shot-'+shot['id']]
    assert number==report['destinations']['end-shot-'+shot['id']],('Walkthrough split across pages',shot['id'])
    assert len(pages[number-1].images)==1,('Missing or extra figure',shot['id'])
    source_path=DATA/shot['file']
    assert shot['capture']['device_scale_factor']==3
    assert hashlib.sha256(source_path.read_bytes()).hexdigest()==shot['capture']['sha256']
    with Image.open(source_path) as source:
        assert source.format=='PNG'
        expected=source.convert('RGB')
        clip=shot['capture']['clip_css'] or shot['capture']['viewport_css']
        assert source.size==(clip['width']*3,clip['height']*3),('Not native 3x',shot['id'])
        if shot.get('crop'):
            x,y,w,h=shot['crop'];assert x>=0 and y>=0 and x+w<=source.width and y+h<=source.height
            expected=expected.crop((x,y,x+w,y+h))
        embedded=pages[number-1].images[0].image.convert('RGB')
        assert embedded.size==expected.size,('Image resampled',shot['id'])
        assert hashlib.sha256(embedded.tobytes()).digest()==hashlib.sha256(expected.tobytes()).digest(),('Image pixels changed',shot['id'])
    details=report['figures_detail'][shot['id']]
    assert details['effective_dpi']>=300
    for ref in pages[number-1]['/Resources']['/XObject'].values():
        obj=ref.get_object()
        if obj.get('/Subtype')=='/Image':
            filters=obj.get('/Filter',[])
            assert '/FlateDecode' in filters and '/DCTDecode' not in filters
    assert 'Figure ' in texts[number-1];figure_count+=1
all_text=' '.join('\n'.join(texts).split())
for phrase in ['Reviewer','Administrator','Admin Unlock','Import connections','Subscription status','Brand Kit','Send or schedule','Pause, edit, and resume','Subscriber preferences','Save draft','Custom reports','Check content','Topic finder']:
    assert phrase.lower() in all_text.lower(),('Missing reference topic',phrase)
for forbidden in ['TODO:','Traceback','Fatal error','Cannot access','could not be updated','Lorem ipsum','/ ...']:
    assert forbidden not in all_text,('Unintended content',forbidden)
# Check actual text coordinates, including long role labels, index entries, headers, and footers.
with pdfplumber.open(PDF) as pdf:
    for number,page in enumerate(pdf.pages,1):
        for char in page.chars:
            if not char['text'].strip():continue
            assert 34<=char['x0']<char['x1']<=578,('Text outside horizontal margins',number,char['text'],char['x0'],char['x1'])
            assert 12<=char['top']<char['bottom']<=780,('Text outside vertical margins',number,char['text'])
        for image in page.images:
            assert 40<=image['x0']<image['x1']<=572 and 45<=image['top']<image['bottom']<=748,('Image outside content area',number)
        if number>1:
            body_chars=[c for c in page.chars if 40<c['top']<748 and c['text'].strip()]
            assert body_chars,('No body text',number)
assert links>350 and len(outline)>100
result={'pages':len(pages),'chapters':len(chapters),'native_3x_figures':figure_count,'minimum_effective_dpi':report['minimum_effective_dpi'],'verified_internal_links':links,'verified_bookmarks':len(outline),'lossless_pixel_matches':figure_count,'page_bounds':'passed','pdf_sha256':report['pdf_sha256']}
(DATA/'verification.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
