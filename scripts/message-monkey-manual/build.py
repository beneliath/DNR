#!/usr/bin/env python3
"""Build the illustrated MessageMonkey guide offline from shared manual content.

Requires ReportLab, lxml, Pillow, and pypdf. Screenshots remain native lossless PNGs.
"""
from __future__ import annotations
import hashlib, html, json, re
from pathlib import Path
from functools import lru_cache
from lxml import html as LH, etree
from PIL import Image as PILImage
from reportlab import rl_config
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer, PageBreak, Table, TableStyle, Image, Flowable, KeepTogether

ROOT=Path(__file__).resolve().parents[2]
DATA=ROOT/'docs/message-monkey-user-guide'
OUT=ROOT/'output/pdf/message-monkey-comprehensive-user-guide.pdf'
W,H=612,792
M=42; CW=W-2*M
INK=colors.HexColor('#202b2c'); TEAL=colors.HexColor('#007d8a'); PALE=colors.HexColor('#eaf6f5')
MUTED=colors.HexColor('#59676c'); BORDER=colors.HexColor('#d6e1e4'); ORANGE=colors.HexColor('#d96b00')
VERSION=(ROOT/'VERSION').read_text().strip(); EDITION='October 26, 2026'
CHAPTERS=json.loads((DATA/'chapters.json').read_text())
SHOTS=json.loads((DATA/'screenshots.json').read_text())
rl_config.useA85=False
fonts=Path('/System/Library/Fonts/Supplemental')
for name,file in [('Guide','Arial.ttf'),('GuideBold','Arial Bold.ttf'),('GuideItalic','Arial Italic.ttf')]:
    if (fonts/file).exists():pdfmetrics.registerFont(TTFont(name,str(fonts/file)))
    else:raise RuntimeError('Install the documented Arial font family before building.')
pdfmetrics.registerFontFamily('Guide',normal='Guide',bold='GuideBold',italic='GuideItalic',boldItalic='GuideBold')
S={
 'body':ParagraphStyle('body',fontName='Guide',fontSize=10,leading=14.5,textColor=INK,spaceAfter=9),
 'intro':ParagraphStyle('intro',fontName='Guide',fontSize=11.5,leading=16,textColor=MUTED,spaceAfter=12),
 'h1':ParagraphStyle('h1',fontName='GuideBold',fontSize=25,leading=29,textColor=INK,spaceAfter=12,keepWithNext=True),
 'h2':ParagraphStyle('h2',fontName='GuideBold',fontSize=13,leading=17,textColor=TEAL,spaceBefore=11,spaceAfter=7,keepWithNext=True),
 'figure':ParagraphStyle('figure',fontName='GuideBold',fontSize=20,leading=24,textColor=INK,spaceAfter=11,keepWithNext=True),
 'kicker':ParagraphStyle('kicker',fontName='GuideBold',fontSize=8.5,leading=11,textColor=TEAL,spaceAfter=8,keepWithNext=True),
 'small':ParagraphStyle('small',fontName='Guide',fontSize=8.2,leading=11,textColor=MUTED,spaceAfter=8),
 'caption':ParagraphStyle('caption',fontName='Guide',fontSize=8,leading=10.5,textColor=MUTED,spaceBefore=6,spaceAfter=10),
 'step':ParagraphStyle('step',fontName='Guide',fontSize=9.6,leading=13.5,textColor=INK,leftIndent=21,firstLineIndent=-21,spaceAfter=8),
 'cell':ParagraphStyle('cell',fontName='Guide',fontSize=9.2,leading=12.5,textColor=INK,spaceAfter=0),
 'toc':ParagraphStyle('toc',fontName='Guide',fontSize=10.5,leading=15,textColor=INK,spaceAfter=0),
}
for style in S.values():
    style.allowWidows=0;style.allowOrphans=0;style.splitLongWords=0;style.embeddedHyphenation=0;style.hyphenationLang=None

def clean(s):
    for a,b in {'\u2014':' - ','\u2013':'-','\u2011':'-','\u00a0':' ','\u2019':"'",'\u2018':"'",'\u201c':'"','\u201d':'"','→':' > ','←':' < ','↗':'','↑':'','·':' / '}.items():s=s.replace(a,b)
    return s

def esc(s):return html.escape(clean(str(s)),quote=False)
class CheckedParagraph(Paragraph):
    def draw(self):
        if getattr(self,'_splitLongWordCount',0) or getattr(self,'_hyphenations',0):raise RuntimeError('Unexpected word break: '+self.getPlainText())
        super().draw()
def p(s,style='body'):return CheckedParagraph(s,S[style])
def heading(s,key,level=0,style='h1',chapter=None):
    v=p(esc(s),style);v.dest=key;v.outline_title=clean(s);v.outline_level=level;v.chapter=chapter;return v

def inline(e):
    s=esc(e.text or '')
    for child in e:
        part=inline(child)
        if child.tag in ['strong','b']:s+='<b>'+part+'</b>'
        elif child.tag in ['em','i']:s+='<i>'+part+'</i>'
        elif child.tag=='br':s+='<br/>'
        elif child.tag in ['kbd','code']:s+='<font color="#007d8a">'+part+'</font>'
        else:s+=part
        s+=esc(child.tail or '')
    return s

def blocks(markup):
    out=[]
    root=LH.fragment_fromstring(markup,create_parent='div')
    def visit(e):
        if e.tag=='p':out.append(p(inline(e)))
        elif e.tag in ['ol','ul']:
            for i,item in enumerate(e.findall('li'),1):out.append(p(f'<b>{i if e.tag=="ol" else "-"}.</b>  '+inline(item),'step'))
        elif e.tag=='table':
            rows=[[p(inline(cell),'cell') for cell in tr if cell.tag in ['td','th']] for tr in e.findall('.//tr')]
            widths=[104,CW-104] if len(rows[0])==2 else [CW/len(rows[0])]*len(rows[0])
            t=Table(rows,colWidths=widths,repeatRows=1,hAlign='LEFT')
            t.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('BACKGROUND',(0,0),(-1,0),PALE),('LINEBELOW',(0,0),(-1,-1),.5,BORDER),('LEFTPADDING',(0,0),(-1,-1),10),('RIGHTPADDING',(0,0),(-1,-1),10),('TOPPADDING',(0,0),(-1,-1),9),('BOTTOMPADDING',(0,0),(-1,-1),9)]))
            out.extend([t,Spacer(1,10)])
        else:
            for child in e:visit(child)
    visit(root);return out

@lru_cache
def logo_elements():
    root=etree.parse(str(ROOT/'src/assets/audience-logo-transparent.svg')).getroot()
    return root

def draw_logo(c,x,y,width):
    root=logo_elements();vx,vy,vw,vh=map(float,root.get('viewBox').split());scale=width/vw
    c.saveState();c.translate(x,y+vh*scale);c.scale(scale,-scale);c.translate(-vx,-vy)
    for element in root.iter():
        if etree.QName(element).localname!='path':continue
        inherited=element.getparent().attrib
        c.saveState()
        translate=re.search(r'translate\(([-\d.]+)[, ]+([-\d.]+)\)',element.get('transform',''))
        if translate:c.translate(*map(float,translate.groups()))
        tokens=re.findall(r'[A-Za-z]|[-+]?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?',element.get('d',''));i=0;path=c.beginPath()
        while i<len(tokens):
            command=tokens[i];i+=1;count={'M':2,'C':6,'Z':0}[command];values=list(map(float,tokens[i:i+count]));i+=count
            if command=='M':path.moveTo(*values)
            elif command=='C':path.curveTo(*values)
            else:path.close()
        fill=element.get('fill',inherited.get('fill','#000000'));stroke=element.get('stroke',inherited.get('stroke','none'))
        c.setFillColor(colors.HexColor(fill));c.setLineJoin(1)
        if stroke!='none':c.setStrokeColor(colors.HexColor(stroke));c.setLineWidth(float(element.get('stroke-width',inherited.get('stroke-width','0'))))
        c.drawPath(path,stroke=int(stroke!='none'),fill=1,fillMode=0);c.restoreState()
    c.restoreState()

WORKFLOWS=[
 [('Connect','Review your audience'),('Create','Prepare useful content'),('Learn','Read the results')],
 [('Identify','Check your role'),('Unlock','Authorize protected work'),('Confirm','Review the consequences')],
 [('Prepare','Check fields and consent'),('Import','Map and review rows'),('Verify','Read results and profiles')],
 [('Label','Tags and group choices'),('Describe','Fields and merge tags'),('Target','Reusable segment rules')],
 [('Plan','Purpose and audience'),('Draft','Sender and content'),('Review','Complete the checklist')],
 [('Choose','Start from a template'),('Edit','Text, blocks, and design'),('Preview','Desktop, mobile, and test')],
 [('Collect','Organize approved assets'),('Style','Set your brand choices'),('Inspect','Preview each destination')],
 [('Ready','Identity and eligibility'),('Confirm','Audience, timing, content'),('Follow','Queue and delivery events')],
 [('Trigger','Define who can enter'),('Sequence','Review every step'),('Monitor','Validate and read results')],
 [('Build','Fields and clear wording'),('Publish','Review the live version'),('Measure','Views and submissions')],
 [('Read','Review the conversation'),('Respond','Draft, reply, or note'),('Organize','Labels and done status')],
 [('Scope','Dates, filters, messages'),('Interpret','Definitions and evidence'),('Share','Save context with results')],
 [('Ask','Give appropriate context'),('Review','Check facts and evidence'),('Apply','Save the intended change')],
 [('Locate','Account, role, and scope'),('Diagnose','Read the exact state'),('Resolve','Preserve work and verify')],
]
class Workflow(Flowable):
    def __init__(self,items):super().__init__();self.items=items;self.width=CW;self.height=74
    def draw(self):
        c=self.canv;gap=15;w=(CW-gap*2)/3
        for i,(title,sub) in enumerate(self.items):
            x=i*(w+gap);c.setFillColor(PALE);c.roundRect(x,9,w,62,8,stroke=0,fill=1)
            c.setFillColor(TEAL);c.circle(x+17,51,9,stroke=0,fill=1);c.setFillColor(colors.white);c.setFont('GuideBold',8);c.drawCentredString(x+17,48,str(i+1))
            c.setFillColor(INK);c.setFont('GuideBold',11);c.drawString(x+33,47,title)
            c.setFillColor(MUTED);c.setFont('Guide',8.2);c.drawString(x+11,25,sub)
            if i<2:
                c.setStrokeColor(TEAL);c.setLineWidth(1.4);ax=x+w+4;c.line(ax,40,ax+7,40);c.line(ax+4,43,ax+7,40);c.line(ax+4,37,ax+7,40)
class ActionLegend(Flowable):
    def __init__(self):super().__init__();self.width=CW;self.height=75
    def draw(self):
        c=self.canv
        for i,(title,colour) in enumerate([('Edit','#007d8a'),('Results','#215fe8'),('Done','#07814e'),('Archive','#7440d8'),('Protected','#d96b00')]):
            x=i*105+18;y=38;c.saveState();c.setStrokeColor(colors.HexColor(colour));c.setLineWidth(1.7);c.setLineCap(1);c.setLineJoin(1)
            if i==0:c.line(x,y,x+13,y+13);c.line(x+13,y+13,x+17,y+9);c.line(x+17,y+9,x+4,y-4);c.line(x+4,y-4,x,y);c.line(x+7,y-4,x+19,y-4)
            elif i==1:
                c.line(x,y-4,x,y+11);c.line(x+7,y-4,x+7,y+17);c.line(x+14,y-4,x+14,y+8)
            elif i==2:c.circle(x+8,y+5,11,stroke=1,fill=0);c.line(x+2,y+5,x+6,y+1);c.line(x+6,y+1,x+14,y+10)
            elif i==3:c.rect(x,y-4,17,15,stroke=1,fill=0);c.rect(x-2,y+11,21,5,stroke=1,fill=0);c.line(x+5,y+6,x+12,y+6)
            else:
                c.roundRect(x-9,y-12,36,36,8,stroke=1,fill=0);c.rect(x,y-2,17,13,stroke=1,fill=0);c.arc(x+4,y+6,x+13,y+19,0,180);c.line(x+8,y+3,x+8,y+6)
            c.setFillColor(INK);c.setFont('Guide',8.5);c.drawCentredString(x+8,10,title);c.restoreState()

class Cover(Flowable):
    def __init__(self):super().__init__();self.width=CW;self.height=684
    def draw(self):
        c=self.canv;c.setFillColor(PALE);c.roundRect(0,0,CW,684,20,stroke=0,fill=1)
        c.setFillColor(colors.HexColor('#cfe8e6'));c.circle(CW-76,599,63,stroke=0,fill=1);draw_logo(c,CW-128,548,105)
        c.setFillColor(TEAL);c.setFont('GuideBold',10);c.drawString(30,631,'MOED / MESSAGEMONKEY')
        c.setFillColor(INK);c.setFont('GuideBold',32);c.drawString(30,526,'Comprehensive')
        c.setFont('GuideBold',44);c.drawString(30,473,'User Guide')
        v=p('Build your audience.<br/>Create thoughtful messages.<br/>Understand what happens next.','intro');v.wrap(400,100);v.drawOn(c,30,379)
        c.setFillColor(colors.white);c.roundRect(30,206,CW-60,126,12,stroke=0,fill=1)
        c.setFillColor(TEAL);c.setFont('GuideBold',8.5);c.drawString(47,308,'A PRACTICAL REFERENCE FOR THE WHOLE WORKSPACE')
        c.setFillColor(INK);c.setFont('Guide',11)
        for y,t in [(283,'Connections  /  Campaigns  /  Email design'),(260,'Automations  /  Forms and surveys  /  Inbox'),(237,'Analytics  /  AI assistance  /  Troubleshooting')]:c.drawString(47,y,t)
        c.setFillColor(MUTED);c.setFont('Guide',9);c.drawString(30,165,f'Version {VERSION}  |  {EDITION}')
        c.drawString(30,146,f'14 chapters  |  {len(SHOTS)} illustrated walkthroughs')
        c.drawString(30,127,'For reviewers, editors, and administrators')
        c.setFillColor(TEAL);c.setFont('GuideBold',11);c.drawString(30,74,'Open the contents  >');c.linkRect('','contents',(30,65,180,90),relative=1,thickness=0)
        c.setFillColor(MUTED);c.setFont('Guide',8);c.drawString(30,34,'Searchable text / Clickable chapters / Topic finder / Native high-resolution figures')

class Guide(BaseDocTemplate):
    def __init__(self,path,known):
        super().__init__(str(path),pagesize=(W,H),title='MessageMonkey Comprehensive User Guide',author='MOED',subject=f'Illustrated guide to MessageMonkey {VERSION}, {EDITION}',pageCompression=1)
        self.known=known;self.locations={};self.current_ch=0;self.page_chapters={}
        self.addPageTemplates(PageTemplate(id='normal',frames=[Frame(M,44,CW,H-94,leftPadding=0,rightPadding=0,topPadding=0,bottomPadding=0)],onPage=self.start,onPageEnd=self.end))
    def start(self,c,doc):
        c.bookmarkPage('page-'+str(doc.page))
        if doc.page==1:c.showOutline()
    def afterFlowable(self,f):
        if getattr(f,'chapter',None) is not None:self.current_ch=f.chapter
        if hasattr(f,'dest'):
            self.canv.bookmarkHorizontalAbsolute(f.dest,self.frame._y+getattr(f,'height',0),left=M)
            self.canv.addOutlineEntry(f.outline_title,f.dest,level=f.outline_level,closed=f.outline_level==0)
            self.locations[f.dest]=self.page
        if hasattr(f,'end_tag'):self.locations[f.end_tag]=self.page
    def end(self,c,doc):
        self.page_chapters[doc.page]=self.current_ch
        if doc.page==1:return
        c.saveState();c.setStrokeColor(BORDER);c.setLineWidth(.5);c.line(M,H-34,W-M,H-34);c.line(M,32,W-M,32)
        c.setFillColor(TEAL);c.setFont('GuideBold',8);c.drawString(M,H-24,'MessageMonkey')
        c.setFillColor(MUTED);c.setFont('Guide',7.3)
        label=CHAPTERS[self.current_ch-1]['title'] if self.current_ch else 'Comprehensive User Guide'
        c.drawString(M+76,H-24,label)
        c.setFillColor(TEAL);c.drawRightString(W-M,H-24,'Contents  |  Topic finder')
        c.linkRect('','contents',(W-M-91,H-30,W-M-55,H-16),thickness=0);c.linkRect('','topics',(W-M-50,H-30,W-M,H-16),thickness=0)
        c.drawString(M,20,'< Previous');c.linkRect('','page-'+str(doc.page-1),(M,13,M+48,29),thickness=0)
        if self.current_ch:
            c.drawString(M+67,20,'Chapter start');c.linkRect('','chapter-'+str(self.current_ch),(M+67,13,M+119,29),thickness=0)
        total=self.known.get('__total__',0);c.setFillColor(MUTED);c.drawCentredString(W/2,20,f'{doc.page} / {total or "..."}')
        if doc.page<total:
            c.setFillColor(TEAL);c.drawRightString(W-M,20,'Next >');c.linkRect('','page-'+str(doc.page+1),(W-M-30,13,W-M,29),thickness=0)
        c.restoreState()
    def afterDocument(self):self.locations['__total__']=self.page;self.canv.showOutline()

def page_ref(key,known):return str(known.get(key,'-'))
def link(label,key):return f'<link href="#{key}" color="#007d8a">{esc(label)}</link>'
def toc_row(label,key,known,description=None):
    first=link(label,key)
    if description:first+='<br/><font size="8" color="#59676c">'+esc(description)+'</font>'
    return [p(first,'toc'),p(link(page_ref(key,known),key),'toc')]
def navigation_table(rows,compact=False):
    padding=4 if compact else 8
    t=Table(rows,colWidths=[CW-35,35],hAlign='LEFT');t.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('ALIGN',(-1,0),(-1,-1),'RIGHT'),('LEFTPADDING',(0,0),(-1,-1),0),('RIGHTPADDING',(0,0),(-1,-1),0),('TOPPADDING',(0,0),(-1,-1),padding),('BOTTOMPADDING',(0,0),(-1,-1),padding),('LINEBELOW',(0,0),(-1,-1),.4,BORDER)]));return t

def figure(s,n):
    file=DATA/s['file'];im=PILImage.open(file)
    iw,ih=im.size
    # Cropping for page composition is lossless. Preserve the source capture.
    if s.get('crop'):
        x,y,w,h=s['crop'];im=im.crop((x,y,x+w,y+h));iw,ih=im.size
        import io
        buf=io.BytesIO();im.save(buf,format='PNG');buf.seek(0);source=buf
    else:source=str(file)
    width=min(CW,iw*72/300);height=width*ih/iw
    if height>397:width*=397/height;height=397
    s['embedded']={'width_pixels':iw,'height_pixels':ih,'width_points':round(width,3),'height_points':round(height,3),'effective_dpi':round(iw*72/width,2)}
    img=Image(source,width=width,height=height,hAlign='CENTER')
    start=heading(s['title'],'shot-'+s['id'],1,'figure')
    out=[PageBreak(),p(f'CHAPTER {s["chapter"]:02} / WALKTHROUGH {n:02}','kicker'),start,img,
         p(f'<b>Figure {n:02}.</b> {esc(s["title"])}. '+('Dark mode.' if s.get('theme')=='dark' else 'Small-screen view.' if s['id']=='mobile' else 'Current workspace, shown with fictional example data.'),'caption')]
    for i,step in enumerate(s['steps'],1):out.append(p(f'<b>{i:02}</b>  '+esc(step),'step'))
    end=Spacer(1,1);end.end_tag='end-shot-'+s['id'];out.append(end)
    return out

def story(known):
    out=[Cover(),PageBreak(),heading('Contents','contents',chapter=0),p('Choose a chapter. Page numbers, headings, and footer controls are clickable.','intro')]
    out.append(navigation_table([toc_row(f'{i:02}  '+c['title'],'chapter-'+str(i),known) for i,c in enumerate(CHAPTERS,1)]))
    out.extend([Spacer(1,12),p(link('How to use this guide','using')+'  /  '+link('Quick reference','quick-reference')+'  /  '+link('Topic finder','topics'))])
    out.extend([PageBreak(),heading('How to use this guide','using',chapter=0),p('Start with the task you need to complete. Read the chapter reference, then follow its illustrated walkthroughs.','intro')])
    for title,body in [
        ('Navigate without losing your place','Use the clickable contents, PDF bookmarks, or alphabetical Topic finder. Every interior page links to Contents and Topic finder; walkthroughs also link back to their chapter. Use your PDF viewer search to find a label or phrase.'),
        ('Read screenshots as examples','All captured records, campaigns, messages, and report activity are fictional local examples. No external email was sent while producing this guide. Some screens intentionally show draft, empty, unverified, or blocked states. Those states illustrate what to inspect before proceeding.'),
        ('Match the task to your access','Reviewer, Editor, and Administrator controls differ. Demo restrictions, role preview, security requirements, and Admin Unlock may further limit actions. The screenshots use an administrator in an isolated preview; your account may show fewer controls.'),
        ('Distinguish save, publish, and send','Save preserves a draft. Publish makes a supported resource available to visitors. Sending or activating an automation can contact people. Read the final confirmation, recipients, timing, and result for the action you are taking.'),
        ('Use the online guide for quick lookup','Open User Manual beneath Write with AI in the MessageMonkey sidebar. The online guide provides task search and role-aware links to the application. This PDF adds illustrated walkthroughs and offline navigation.'),
        ('About this edition',f'This guide documents the MessageMonkey workspace in version {VERSION} as captured on {EDITION}. Screenshots are native 3x PNG captures embedded without lossy compression or resizing of their pixel data. Each displayed screenshot meets a minimum of 300 effective DPI. The PDF text and diagrams remain searchable or vector-based.')]:
        out.extend([p(esc(title),'h2'),p(esc(body))])
    out.extend([PageBreak(),heading('Quick reference','quick-reference',chapter=0),p('Three distinctions prevent many common mistakes.','intro')])
    for title,body in [
        ('Tags, groups, fields, and segments','Tags are internal labels. Groups collect related choices, which may be exposed to subscribers. Fields store structured profile values and provide merge tags. Segments save matching rules whose membership can change.'),
        ('Consent and delivery status','Subscribed does not guarantee delivery. Unsubscribed records have opted out. Pending, cleaned, archived, or suppressed records may be ineligible. Never change a status merely to bypass a sending block.'),
        ('Observed results and actual outcomes','Queued work has not necessarily reached the provider. Provider acceptance and confirmed delivery are different stages. Opens and clicks are observations affected by privacy tools and automation; they do not prove that a person read the message.')]:out.extend([p(esc(title),'h2'),p(esc(body))])
    out.extend([p('Recognize the action controls','h2'),ActionLegend(),p('Hover or focus an icon to read its actual label. Ordinary action icons gain a background on hover or focus. Protected controls retain an orange outline until the required Admin Unlock is active. The outline is an access cue, not a completed action.','body'),p('Before a send','h2'),Workflow([('Audience','Consent and exclusions'),('Message','Sender, content, links'),('Timing','Date, time, and zone')]),p('Review the final recipient count, run the available tests, and inspect the queued or delivery result before retrying a submission.')])
    number=0
    for i,ch in enumerate(CHAPTERS,1):
        out.extend([PageBreak(),p(f'CHAPTER {i:02}','kicker'),heading(ch['title'],'chapter-'+str(i),chapter=i),p(esc(ch['summary']),'intro'),Workflow(WORKFLOWS[i-1]),Spacer(1,6)])
        for id,title,body in ch['topics']:
            out.append(heading(title,'topic-'+id,1,'h2'));out.extend(blocks(body))
        group=[s for s in SHOTS if s['chapter']==i]
        for shot in group:number+=1;out.extend(figure(shot,number))
    out.extend([PageBreak(),heading('Topic finder','topics',chapter=0),p('Look up a task or screen. Each entry links directly to its reference topic or illustrated walkthrough.','intro')])
    entries=[(title,'topic-'+id) for ch in CHAPTERS for id,title,_ in ch['topics']]
    entries+=[(s['title'],'shot-'+s['id']) for s in SHOTS]
    entries=sorted(entries,key=lambda x:x[0].lower())
    rows=[toc_row(title,key,known) for title,key in entries]
    # Each group fits one page; this keeps page references aligned and avoids orphaned rows.
    for offset in range(0,len(rows),25):
        if offset:out.extend([PageBreak(),p('Topic finder / continued','h1')])
        out.append(navigation_table(rows[offset:offset+25],compact=True))
    return out

OUT.parent.mkdir(parents=True,exist_ok=True)
known={}
for pass_number in range(4):
    doc=Guide(OUT,known);doc.build(story(known))
    doc.locations['__total__']=doc.page
    if doc.locations==known:break
    known=doc.locations
else:raise RuntimeError('PDF page references did not converge')
report={'title':'MessageMonkey Comprehensive User Guide','version':VERSION,'edition':EDITION,'pages':doc.page,'chapters':len(CHAPTERS),'figures':len(SHOTS),'destinations':doc.locations,'page_chapters':doc.page_chapters,'minimum_effective_dpi':min(s['embedded']['effective_dpi'] for s in SHOTS),'figures_detail':{s['id']:s['embedded'] for s in SHOTS},'pdf_sha256':hashlib.sha256(OUT.read_bytes()).hexdigest(),'shared_content_sha256':hashlib.sha256((ROOT/'src/templates/audience/manual-content.php').read_bytes()).hexdigest()}
(DATA/'build-report.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps({k:report[k] for k in ['pages','chapters','figures','minimum_effective_dpi']}))
print(OUT)
